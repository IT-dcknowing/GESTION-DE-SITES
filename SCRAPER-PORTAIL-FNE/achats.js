/**
 * Le relevé des factures REÇUES du portail FNE.
 *
 *   node achats.js --reconnaissance 1864699A <motDePasse>   explore et rapporte
 *   node achats.js 1864699A <motDePasse>                    relève et dépose
 *   node achats.js 1864699A                                 mot de passe pris dans le magasin
 *   node achats.js --tous                                   tous les logins configurés
 *
 * ## Pourquoi un second fichier
 *
 * `fne.js` relève la page Paramétrage et il fonctionne. Il n'est pas touché :
 * seule sa liste d'exports a été complétée, pour que la connexion — qui porte
 * l'attente d'hydratation Next.js sans laquelle les identifiants partiraient
 * dans l'URL — ne soit pas recopiée ici. Deux copies, et c'est l'une des deux
 * qui gardera le défaut le jour où le portail bougera.
 *
 * ## Ce qu'il relève, et d'où
 *
 * Une facture émise par un fournisseur porte le NCC de son client. La DGI
 * détient donc, du côté du **client**, les pièces qu'on lui a facturées. C'est
 * donc le compte de l'entreprise Selflow qu'on relève — jamais celui du
 * fournisseur, qui n'a aucune raison de confier ses accès.
 *
 * ## Le contrat de dépôt
 *
 * Un fichier, dans un **sous-dossier** du dossier d'import :
 *
 *     storage/app/portail-fne/achats/<login>_<AAAAMMJJ>.json
 *
 * Le sous-dossier plutôt qu'un suffixe : la découpe du nom se fait au dernier
 * `_`, ce qui permet à un login d'en contenir un. `<login>_<date>_achats.json`
 * casserait cette règle, et un relevé fiscal rangé chez le mauvais client ne se
 * répare pas.
 *
 * **Aucun horodatage de génération n'est écrit dans le fichier.** C'est
 * l'empreinte du contenu qui dit à Selflow si le relevé apporte du neuf ; un
 * champ qui change à chaque passage annulerait cette économie. La leçon vient
 * du tableur du portail, qui embarque `dcterms:created` et ne peut donc jamais
 * être reconnu identique à lui-même.
 *
 * ## Ce qu'il ne fait pas
 *
 * Il ne crée aucun achat, ne touche à aucune table, ne rapproche rien. Il
 * dépose un constat. La règle d'or du projet vaut ici comme ailleurs.
 */

'use strict';

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const {
  nomDeBase,
  dossierDepot,
  seConnecter,
  lireMagasin,
  motDePassePour,
  lireFileDemandes,
  journaliser,
} = require('./fne.js');

/**
 * Le raccourci de ce script-ci : toutes ses lignes portent « achats ».
 *
 * L'outil vit dans `fne.js` et n'est pas recopié : les deux scripts écrivent
 * dans le même fichier que Laravel, et deux formats de ligne y rendraient le
 * `grep` inutile — ce qu'on vient précisément y chercher.
 */
const dire = (niveau, message, details) => journaliser('achats', niveau, message, details);

const DOSSIER_ERREURS = path.join(__dirname, 'erreurs');
const DOSSIER_RECONNAISSANCE = path.join(__dirname, 'reconnaissance');

/**
 * Les libellés sous lesquels le portail peut ranger cette page.
 *
 * Plusieurs orthographes parce qu'on ne les a pas toutes vues : « Reçus et
 * factures réceptionnés » est celle qui a été rapportée, les autres sont des
 * variantes plausibles. Le premier lien qui correspond gagne.
 */
const LIBELLES_PAGE = [
  'Reçus et factures réceptionnés',
  'Reçus et factures réceptionnées',
  'Factures réceptionnées',
  'Factures reçues',
  'Reçus et factures',
  'Achats',
];

/**
 * Au-delà, on arrête de tourner les pages.
 *
 * Une borne, et non une confiance : une pagination qui ne se termine jamais —
 * `total` qui ne décroît pas, page qui rend toujours le même lot — ferait
 * tourner le navigateur jusqu'à la fin des temps sur le portail de la DGI.
 */
const PAGES_MAX = 200;

/** Ce que le portail sert par appel. Relevé à 12 dans l'écran, il en accepte plus. */
const PAR_PAGE = 100;

/**
 * L'API qui nourrit l'écran des factures, relevée le 27/08/2026 :
 *
 *   GET /ws/invoices?page=1&perPage=12&fromDate=…&toDate=…&sortBy=-date
 *                   &listing=received&complete=true
 *   → { data: [...], page, perPage, total }
 *
 * On l'appelle plutôt que de lire le tableau. Le tableau formate ses montants
 * (« 1 234 567 FCFA ») et localise ses dates ; l'API rend des nombres et de
 * l'ISO, et souvent des champs que l'écran n'affiche pas — le NCC de l'émetteur
 * en particulier, qui est la clé du rapprochement avec les fournisseurs.
 */
const CHEMIN_API = '/ws/invoices';

/**
 * Depuis quand relever, au premier passage.
 *
 * La FNE n'a pas d'histoire antérieure à son entrée en service ; remonter plus
 * loin ne rendrait rien de plus et ferait tourner la pagination pour rien.
 */
const DEPUIS_PAR_DEFAUT = process.env.ACHATS_DEPUIS || '2024-01-01';

/* ------------------------------ Le mouchard ------------------------------- */

/**
 * Écoute ce que la page demande au serveur.
 *
 * Le portail est une application Next.js : son tableau n'est pas du HTML servi
 * tel quel, il est dessiné à partir d'un appel JSON. Cet appel vaut bien mieux
 * que le tableau qu'il produit — montants en nombres plutôt qu'en « 1 234 567
 * FCFA », dates en ISO, et souvent des champs que l'écran n'affiche même pas.
 *
 * On écoute donc, plutôt que de lire le DOM.
 */
function ecouterLesReponses(page) {
  const captures = [];

  page.on('response', async reponse => {
    const url = reponse.url();
    const type = (reponse.headers()['content-type'] || '').toLowerCase();

    if (!type.includes('json')) return;
    // Les ressources de l'application elle-même n'ont rien à faire ici.
    if (/\/_next\/|\.js(\?|$)|\.css(\?|$)/.test(url)) return;

    let corps;
    try {
      corps = await reponse.json();
    } catch {
      return; // Corps déjà consommé, ou JSON invalide : sans intérêt.
    }

    captures.push({
      url,
      methode: reponse.request().method(),
      statut: reponse.status(),
      corps,
    });
  });

  return captures;
}

/**
 * Retient l'en-tête d'autorisation que la page envoie à sa propre API.
 *
 * `page.request` partage les cookies du navigateur mais pas les en-têtes posés
 * par le JavaScript de l'application : rejouer un appel sans cela rend 401.
 * Plutôt que d'aller chercher le jeton dans `localStorage` — dont rien ne dit
 * qu'il y restera — on reprend celui que la page vient d'utiliser.
 *
 * **Il ne vit qu'en mémoire, le temps du relevé.** Il n'est ni journalisé, ni
 * écrit dans le fichier déposé, ni dans le rapport de reconnaissance. Le
 * scraper dépose des factures, rien d'autre — la même règle que pour la clé
 * d'API de la page Paramétrage, que `fne.js` n'a jamais relevée.
 */
function capterLAutorisation(page) {
  const etat = { valeur: null };

  page.on('request', requete => {
    if (etat.valeur || !requete.url().includes('/ws/')) return;

    const entetes = requete.headers();
    const auth = entetes.authorization || entetes.Authorization;

    if (auth) etat.valeur = auth;
  });

  return etat;
}

/**
 * Les enregistrements d'un corps JSON, où qu'ils se cachent.
 *
 * Les API rendent rarement un tableau nu : il est presque toujours emballé dans
 * `data`, `items`, `content`, `results`… On cherche donc, en profondeur, le plus
 * grand tableau d'objets — c'est lui qui porte les factures.
 */
function extraireLesEnregistrements(valeur, profondeur = 0) {
  if (profondeur > 6 || valeur === null || typeof valeur !== 'object') return null;

  if (Array.isArray(valeur)) {
    const objets = valeur.filter(e => e && typeof e === 'object' && !Array.isArray(e));
    // Un tableau de trois chaînes n'est pas une liste de factures.
    return objets.length === valeur.length && objets.length > 0 && Object.keys(objets[0]).length >= 3
      ? valeur
      : null;
  }

  let meilleur = null;
  for (const enfant of Object.values(valeur)) {
    const trouve = extraireLesEnregistrements(enfant, profondeur + 1);
    if (trouve && (!meilleur || trouve.length > meilleur.length)) meilleur = trouve;
  }
  return meilleur;
}

/* ------------------------------ La navigation ----------------------------- */

/**
 * L'adresse de la page des factures reçues, déduite de celle de connexion.
 *
 * `http://.../fr/login` donne `http://.../fr/invoice-management?type=received`.
 * Relevée le 27/08/2026 dans le menu du portail, à côté de son symétrique
 * `?type=issued` — les pièces émises.
 */
function urlDesFacturesRecues() {
  const base = String(process.env.FNE_URL || '').replace(/\/login\/?$/, '');
  return `${base}/invoice-management?type=received`;
}

/**
 * Va sur la page des factures reçues.
 *
 * Par l'URL d'abord, par le menu ensuite. L'ordre n'est pas indifférent : un
 * clic sur un menu d'application Next.js rend la main avant que la navigation
 * n'ait eu lieu, et l'on relève alors le tableau de bord en croyant relever les
 * factures. C'est exactement ce qui est arrivé au premier essai.
 *
 * Le menu reste en second recours, pour le jour où l'adresse changera sans que
 * le libellé bouge.
 */
async function allerAuxFacturesRecues(page) {
  const url = urlDesFacturesRecues();

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});

  if (page.url().includes('invoice-management')) return url;

  // L'adresse n'a pas tenu : on retombe sur le menu.
  for (const libelle of LIBELLES_PAGE) {
    const lien = page.getByRole('link', { name: libelle, exact: false }).first();

    if ((await lien.count()) === 0) continue;

    await Promise.all([
      page.waitForURL('**/invoice-management*', { timeout: 30000 }),
      lien.click(),
    ]);
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});

    return libelle;
  }

  throw new Error(
    `Page des factures reçues introuvable : ni à l'adresse ${url}, ni sous les libellés `
    + LIBELLES_PAGE.map(l => `« ${l} »`).join(', ')
    + '. Lancer « node achats.js --reconnaissance <login> <mdp> » pour voir ce que le portail propose.'
  );
}

/**
 * La borne basse du relevé : `--depuis=AAAA-MM-JJ`, sinon la valeur par défaut.
 *
 * La fenêtre est large par défaut, et c'est voulu : le fichier déposé porte
 * alors l'état complet, et Selflow n'enregistre que ce qui change. Un relevé
 * partiel se lirait comme une disparition de factures.
 */
function optionDepuis() {
  const donnee = process.argv.slice(2).find(a => a.startsWith('--depuis='));
  const valeur = donnee ? donnee.slice('--depuis='.length) : DEPUIS_PAR_DEFAUT;

  if (!/^\d{4}-\d{2}-\d{2}$/.test(valeur)) {
    throw new Error(`--depuis attend une date AAAA-MM-JJ, reçu « ${valeur} ».`);
  }
  return valeur;
}

/** La date du jour, en AAAA-MM-JJ — le format qu'attend l'API. */
function aujourdHui() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Interroge l'API des factures, page après page, dans la session ouverte.
 *
 * `page.request` rejoue l'appel avec les cookies et les en-têtes de la session
 * du navigateur : on ne manipule aucun jeton, on n'en écrit aucun nulle part.
 *
 * L'arrêt se fie à `total`, pas à la présence d'un bouton : un « suivant » qui
 * reste cliquable est un classique, un `total` qui ment l'est beaucoup moins.
 * La borne `PAGES_MAX` reste là pour le cas où il mentirait quand même.
 */
async function interrogerLesFactures(page, autorisation, listing, depuis, jusqu_a) {
  const base = String(process.env.FNE_URL || '').replace(/\/fr\/login\/?$/, '').replace(/\/$/, '');
  const factures = [];
  let total = null;

  for (let numero = 1; numero <= PAGES_MAX; numero++) {
    const url = `${base}${CHEMIN_API}?page=${numero}&perPage=${PAR_PAGE}`
      + `&fromDate=${depuis}&toDate=${jusqu_a}&sortBy=-date&listing=${listing}&complete=true`;

    const reponse = await page.request.get(url, {
      timeout: 60000,
      headers: autorisation.valeur ? { Authorization: autorisation.valeur } : {},
    });

    if (!reponse.ok()) {
      // 401 sans en-tête capté : la page n'a pas encore appelé son API, ou elle
      // ne s'autorise plus de cette façon. Le dire, plutôt que de rendre une
      // liste vide qui se lirait comme « aucune facture reçue ».
      const cause = reponse.status() === 401 && !autorisation.valeur
        ? " — aucun en-tête d'autorisation n'a pu être capté sur la page"
        : '';
      throw new Error(`L'API des factures a répondu ${reponse.status()} sur ${url}${cause}`);
    }

    const corps = await reponse.json();

    // L'enveloppe attendue est { data, page, perPage, total }. Si elle change,
    // on le dit plutôt que de rendre une liste vide qui se lirait comme
    // « aucune facture reçue ».
    if (!corps || !Array.isArray(corps.data)) {
      throw new Error(
        "L'API des factures ne rend plus { data: [...] } mais "
        + JSON.stringify(Object.keys(corps ?? {})) + '. Rien n\'est déposé.'
      );
    }

    if (total === null) total = Number(corps.total ?? corps.data.length);

    factures.push(...corps.data);

    if (corps.data.length === 0 || factures.length >= total) break;
  }

  return { factures, total };
}

/* ------------------------------ Le repli DOM ------------------------------ */

/**
 * Lit le tableau à l'écran, quand aucun appel JSON ne le nourrit.
 *
 * Moins bon que l'API — les montants sont formatés, les dates localisées — mais
 * mieux que rien. La lecture suit les **en-têtes**, jamais les positions : le
 * portail peut réordonner ses colonnes, et un relevé qui compte les colonnes
 * rangerait un jour un montant dans une date.
 */
async function lireLeTableau(page) {
  return page.evaluate(() => {
    const tableau = document.querySelector('table');
    if (!tableau) return null;

    const entetes = [...tableau.querySelectorAll('thead th, thead td')]
      .map(c => c.textContent.trim())
      .filter(Boolean);

    if (entetes.length === 0) return null;

    const lignes = [...tableau.querySelectorAll('tbody tr')]
      .map(tr => {
        const cellules = [...tr.querySelectorAll('td, th')].map(c => c.textContent.trim());
        if (cellules.every(v => v === '')) return null;

        const ligne = {};
        entetes.forEach((entete, i) => { ligne[entete] = cellules[i] ?? null; });
        return ligne;
      })
      .filter(Boolean);

    return { entetes, lignes };
  });
}

/* ---------------------------- La reconnaissance --------------------------- */

/**
 * Explore la page sans rien déposer, et écrit ce qu'elle a vu.
 *
 * C'est le mode qui répond à la seule question qui bloque encore : sous quelle
 * forme le portail rend ses factures reçues. Il remplace un aller-retour à la
 * main dans les outils de développement, et il rend un fichier lisible.
 *
 * **Aucun jeton n'est consigné.** Les en-têtes d'autorisation sont écartés :
 * ce qui entre dans un fichier y reste, et le scraper dépose des factures,
 * rien d'autre.
 */
async function reconnaitre(navigateur, login, motDePasse) {
  const contexte = await navigateur.newContext({ acceptDownloads: true });
  const page = await contexte.newPage();
  const captures = ecouterLesReponses(page);
  const autorisation = capterLAutorisation(page);

  try {
    dire('INFO', '   Connexion...');
    await seConnecter(page, login, motDePasse);

    dire('INFO', '   Menus du tableau de bord :');
    const menus = await page.evaluate(() =>
      [...document.querySelectorAll('a')]
        .map(a => ({ texte: a.textContent.trim().replace(/\s+/g, ' '), href: a.getAttribute('href') }))
        .filter(l => l.texte && l.texte.length < 80)
    );
    for (const menu of menus) dire('INFO', `      « ${menu.texte} »  →  ${menu.href}`);

    captures.length = 0;
    const libelle = await allerAuxFacturesRecues(page);
    await page.waitForTimeout(1500);

    const tableau = await lireLeTableau(page);
    const boutons = await page.evaluate(() =>
      [...document.querySelectorAll('button')]
        .map(b => b.textContent.trim().replace(/\s+/g, ' '))
        .filter(t => t && t.length < 60)
    );

    // Sonde des deux listings sur une fenêtre large.
    //
    // `issued` est interrogé aussi, et ce n'est pas de la curiosité : une
    // entreprise qui n'a encore rien reçu rendrait `data: []`, et l'on ne
    // saurait rien de la forme d'un enregistrement. Ses propres pièces émises
    // sortent du même endpoint, donc du même moule.
    const depuis = optionDepuis();
    const jusquA = aujourdHui();
    const sondes = {};

    for (const listing of ['received', 'issued']) {
      try {
        const { factures, total } = await interrogerLesFactures(page, autorisation, listing, depuis, jusquA);
        sondes[listing] = { total, ramenees: factures.length, exemple: factures[0] ?? null };
        dire('INFO', `   ${listing} du ${depuis} au ${jusquA} : ${total} pièce(s)`);
      } catch (erreur) {
        sondes[listing] = { erreur: erreur.message };
        dire('ERREUR', `   ${listing} : ${erreur.message}`);
      }
    }

    const rapport = {
      libelle_du_menu: libelle,
      url: page.url(),
      boutons_de_la_page: boutons,
      tableau_a_l_ecran: tableau,
      periode_sondee: { du: depuis, au: jusquA },
      sondes,
      appels_json: captures.map(c => ({
        url: c.url,
        methode: c.methode,
        statut: c.statut,
        // Le corps entier : c'est lui qu'on vient chercher.
        corps: c.corps,
        enregistrements_detectes: extraireLesEnregistrements(c.corps)?.length ?? 0,
      })),
    };

    if (!fs.existsSync(DOSSIER_RECONNAISSANCE)) fs.mkdirSync(DOSSIER_RECONNAISSANCE, { recursive: true });
    const chemin = path.join(DOSSIER_RECONNAISSANCE, `${nomDeBase(login)}.json`);
    fs.writeFileSync(chemin, JSON.stringify(rapport, null, 2), 'utf-8');

    dire('INFO', `\n   Page : ${page.url()}`);
    dire('INFO', `   Boutons : ${boutons.join(' | ') || '(aucun)'}`);
    dire('INFO', `   Tableau à l'écran : ${tableau ? `${tableau.lignes.length} ligne(s), colonnes ${tableau.entetes.join(' | ')}` : 'aucun'}`);
    dire('INFO', `   Appels JSON captés : ${rapport.appels_json.length}`);
    for (const appel of rapport.appels_json) {
      dire('INFO', `      ${appel.methode} ${appel.url}  →  ${appel.enregistrements_detectes} enregistrement(s)`);
    }
    dire('INFO', `\n   Rapport écrit : ${chemin}`);

    return chemin;
  } finally {
    await contexte.close();
  }
}

/* -------------------------------- Le relevé ------------------------------- */

/**
 * Le relevé des factures reçues, dans une session **déjà ouverte**.
 *
 * Extrait de `releverUnLogin` pour que `fne.js` puisse l'appeler sans rouvrir
 * une session : une connexion au portail de la DGI est ce qui coûte, et il n'y
 * a aucune raison d'en payer deux quand on est déjà dedans. Demandé par le
 * propriétaire du projet le 31/08/2026 — « à chaque fois que la plateforme
 * s'ouvre, il fait un relevé ».
 *
 * `autorisation` est l'état rendu par `capterLAutorisation()`, posé sur la page
 * **avant** toute navigation vers l'application : le jeton se capte au vol sur
 * un appel que la page fait elle-même.
 */
async function releverDansLaSession(page, autorisation, login, dossier) {
  dire('INFO', '   Factures reçues...');
  await allerAuxFacturesRecues(page);

  // La page est ouverte pour deux raisons : établir la session côté
  // application, et faire échouer bruyamment si elle a disparu. Les données,
  // elles, viennent de l'API.
  const tableau = await lireLeTableau(page);

  if (!tableau) {
    throw new Error(
      "Le tableau des factures reçues n'est plus sur la page : le portail a "
      + "changé de structure. Rien n'est déposé."
    );
  }

  const depuis  = optionDepuis();
  const jusquA  = aujourdHui();
  const { factures, total } = await interrogerLesFactures(page, autorisation, 'received', depuis, jusquA);

  if (factures.length !== total) {
    dire('ALERTE', `   /!\\ ${factures.length} facture(s) ramenée(s) pour un total annoncé de ${total}.`);
  }

  // Le dépôt : un fichier, dans le sous-dossier `achats/`.
  //
  // Pas d'horodatage de génération dans le fichier : c'est l'empreinte du
  // contenu qui dit à Selflow s'il y a du neuf, et un champ qui change à
  // chaque passage annulerait cette économie. `periode` en fait partie parce
  // qu'elle décrit ce qui a été demandé — élargir la fenêtre est un vrai
  // changement, et Selflow doit le voir.
  const contenu = {
    login,
    source: `${CHEMIN_API}?listing=received`,
    periode: { du: depuis, au: jusquA },
    colonnes_a_l_ecran: tableau.entetes,
    factures,
  };

  if (!fs.existsSync(dossier)) fs.mkdirSync(dossier, { recursive: true });
  const chemin = path.join(dossier, `${nomDeBase(login)}.json`);
  fs.writeFileSync(chemin, JSON.stringify(contenu, null, 2), 'utf-8');

  dire('INFO', 
    `   ${factures.length} facture(s) reçue(s) du ${depuis} au ${jusquA} -> ${path.basename(chemin)}`
  );

  return { login, ok: true, nombre: factures.length, chemin };
}

/* ------------------------------ Un relevé, un login ----------------------- */

async function releverUnLogin(navigateur, login, motDePasse, dossier) {
  // Un contexte neuf par login : deux entreprises ne partagent jamais une
  // session, sous peine de ranger les factures de l'une chez l'autre.
  const contexte = await navigateur.newContext({ acceptDownloads: true });
  const page = await contexte.newPage();
  const autorisation = capterLAutorisation(page);

  try {
    dire('INFO', '   Connexion...');
    await seConnecter(page, login, motDePasse);

    return await releverDansLaSession(page, autorisation, login, dossier);
  } catch (erreur) {
    let capture = null;
    try {
      if (!fs.existsSync(DOSSIER_ERREURS)) fs.mkdirSync(DOSSIER_ERREURS, { recursive: true });
      capture = path.join(DOSSIER_ERREURS, `achats_${nomDeBase(login)}.png`);
      await page.screenshot({ path: capture, fullPage: true });
    } catch {
      capture = null;
    }

    return { login, ok: false, motif: erreur.message, capture };
  } finally {
    await contexte.close();
  }
}

/* -------------------------------- Le passage ------------------------------ */

function resoudreTaches() {
  const args = process.argv.slice(2);
  const options = args.filter(a => a.startsWith('--'));
  const positions = args.filter(a => !a.startsWith('--'));

  const mode = options.includes('--reconnaissance') ? 'reconnaissance' : 'releve';

  if (positions.length >= 2) return { mode, logins: [positions[0]], motDePasseDirect: positions[1] };
  if (positions.length === 1) return { mode, logins: [positions[0]], motDePasseDirect: null };

  if (options.includes('--tous')) {
    const magasin = lireMagasin();
    return {
      mode,
      logins: Object.keys(magasin).filter(login => motDePassePour(login, magasin)),
      motDePasseDirect: null,
    };
  }

  return { mode, logins: lireFileDemandes(), motDePasseDirect: null };
}

async function passage() {
  if (!process.env.FNE_URL) throw new Error('FNE_URL manquant dans le .env du scraper.');

  const taches = resoudreTaches();
  const magasin = taches.motDePasseDirect ? {} : lireMagasin();
  const dossier = path.join(dossierDepot(), 'achats');

  if (!taches.logins.length) {
    dire('INFO', 'Rien à relever.');
    return;
  }

  if (taches.mode === 'reconnaissance') {
    dire('INFO', 'Mode reconnaissance : rien ne sera déposé dans le dossier d\'import.\n');
  } else {
    dire('INFO', `Dépôt dans : ${dossier}`);
  }

  // Les mots de passe d'abord, le navigateur ensuite : l'ouvrir pour découvrir
  // qu'aucun login n'est utilisable coûte dix secondes pour rien.
  const relevables = [];
  const resultats = [];

  for (const login of taches.logins) {
    const motDePasse = taches.motDePasseDirect || motDePassePour(login, magasin);

    if (motDePasse) {
      relevables.push({ login, motDePasse });
      continue;
    }
    dire('ERREUR', `Aucun mot de passe pour « ${login} » dans identifiants.json.`);
    resultats.push({ login, ok: false, motif: 'mot de passe absent du magasin' });
  }

  if (relevables.length) {
    // `--single-process --no-zygote` : voir fne.js, même correction, même
    // constat le 15/09/2026 sur l'hébergement mutualisé de production.
    const navigateur = await chromium.launch({
      headless: process.env.FNE_HEADLESS !== 'false',
      args: ['--no-sandbox', '--single-process', '--no-zygote'],
    });

    try {
      for (const { login, motDePasse } of relevables) {
        dire('INFO', `\n-- ${login} --`);

        // Un contexte neuf par login : deux entreprises ne partagent jamais une
        // session, sous peine de ranger les factures de l'une chez l'autre.
        if (taches.mode === 'reconnaissance') {
          await reconnaitre(navigateur, login, motDePasse);
          resultats.push({ login, ok: true, nombre: 0 });
        } else {
          resultats.push(await releverUnLogin(navigateur, login, motDePasse, dossier));
        }
      }
    } finally {
      await navigateur.close();
    }
  }

  const reussis = resultats.filter(r => r.ok);
  const echoues = resultats.filter(r => !r.ok);

  dire('INFO', `\n${'-'.repeat(60)}`);
  dire('INFO', `${reussis.length} relevé(s) : ${reussis.map(r => r.login).join(', ') || '(aucun)'}`);

  if (echoues.length) {
    dire('ERREUR', `${echoues.length} en échec :`);
    for (const echec of echoues) {
      dire('ERREUR', `   - ${echec.login} : ${echec.motif}`);
      if (echec.capture) dire('ERREUR', `     capture : ${echec.capture}`);
    }
    process.exitCode = 1;
  }
}

if (require.main === module) {
  passage().catch(erreur => {
    dire('ERREUR', erreur.message);
    process.exitCode = 1;
  });
}

module.exports = {
  LIBELLES_PAGE,
  extraireLesEnregistrements,
  lireLeTableau,
  allerAuxFacturesRecues,

  // Ce que `fne.js` appelle pour relever les factures reçues sans rouvrir une
  // session. L'ordre compte à l'usage : poser `capterLAutorisation` sur la page
  // AVANT toute navigation, puis appeler `releverDansLaSession` une fois
  // connecté.
  capterLAutorisation,
  releverDansLaSession,
};

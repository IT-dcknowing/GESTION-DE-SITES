/**
 * Le scraper du portail FNE — il relève, il dépose, et rien d'autre.
 *
 * Le contrat est écrit dans CONSIGNES-POUR-LE-SCRAPER.md, à côté de ce fichier.
 * En deux gestes :
 *
 *   1. demander à Selflow ce qu'il attend :  php artisan portail-fne:demandes --json
 *   2. déposer, par login, deux fichiers dans le dossier d'import :
 *        <login>_<AAAAMMJJ>.json   la fiche de l'entreprise
 *        <login>_<AAAAMMJJ>.xlsx   les points de facturation
 *
 * Ce script ne ferme aucune demande, ne déplace rien, ne touche à aucune table.
 * Une demande n'est servie que lorsque `portail-fne:importer` range réellement
 * un fichier portant ce login — c'est le seul endroit où l'on verra que le
 * scraper ne fonctionne plus.
 *
 * ---------------------------------------------------------------------------
 * Usage
 *
 *   node fne.js                      relève tous les logins de la file Selflow
 *   node fne.js --tous               relève tous les logins d'identifiants.json
 *   node fne.js <login>              relève ce login (mot de passe du magasin)
 *   node fne.js <login> <motDePasse> relève ce login sans passer par le magasin
 *
 * ---------------------------------------------------------------------------
 * Configuration — SCRAPER-PORTAIL-FNE/.env (voir .env.exemple)
 *
 *   FNE_URL                page de connexion du portail                (requis)
 *   FNE_HEADLESS           "false" pour voir le navigateur (débogage)
 *   UPLOAD_PATH_FOLDER     dossier de dépôt, si l'on veut forcer autre chose que
 *                          PORTAIL_FNE_DOSSIER_IMPORT lu dans le .env de Selflow
 *   URL_SERVER             envoi HTTP en plus du dépôt (scraper distant)
 *   SERVER_UPLOAD_TOKEN    jeton Bearer pour URL_SERVER
 *   SERVER_UPLOAD_FIELD    nom du champ multipart (défaut : "file")
 *   PHP_BINAIRE            chemin de php si absent du PATH (défaut : "php")
 *
 * Les mots de passe des portails vivent dans identifiants.json, ignoré par git.
 * Selflow ne transmet que des logins : il n'a pas à connaître ces accès.
 * Aucun identifiant n'est écrit en dur ici, ni journalisé.
 */

'use strict';

const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const RACINE_SELFLOW = path.join(__dirname, '..');

// Deux .env, dans cet ordre : celui du scraper d'abord — dotenv ne réécrit
// jamais une variable déjà posée, il a donc le dernier mot — puis celui de
// Selflow, pour y lire PORTAIL_FNE_DOSSIER_IMPORT sans avoir à le recopier.
require('dotenv').config({ path: path.join(__dirname, '.env') });
require('dotenv').config({ path: path.join(RACINE_SELFLOW, '.env') });

const FICHIER_IDENTIFIANTS = path.join(__dirname, 'identifiants.json');
const DOSSIER_ERREURS = path.join(__dirname, 'erreurs');

/**
 * Les quatorze clés que `ImportPortailFneService::CHAMPS_FICHE` reconnaît,
 * au caractère près — accents et apostrophes compris.
 *
 * Le portail affiche ses libellés comme il l'entend ; c'est ici qu'ils sont
 * ramenés à la forme attendue. Un libellé qui ne tombe sur aucune de ces clés
 * n'est pas perdu : il part tel quel dans le fichier, Selflow le range dans
 * `champs_inconnus` et le journalise.
 */
const CLES_PORTAIL = [
  'Email',
  'Téléphone',
  'Adresse',
  'Commune',
  'Quartier',
  'Référence Cadastrale',
  'IDU',
  "Propriétaire du local professionnel de l'entreprise",
  "Sticker : solde d'alerte",
  'Références bancaires',
  'Timbre de quittance',
  "Bordereau d'achat de produits agricoles",
  'Pied de page des factures',
  'Factures autres mentions',
];

/**
 * Ce qui ne doit jamais atterrir dans le fichier déposé.
 *
 * La clé d'API en tête : elle est affichée en clair sur la page Paramétrage,
 * et le fichier déposé est lu, archivé en base (`contenu_brut`) et conservé.
 * L'exclure par le type du champ ne suffit pas — le portail la rend dans un
 * champ texte ordinaire.
 */
const LIBELLES_EXCLUS = [
  'api key',
  'cle api',
  'clef api',
  'documentation de l api',
];

/** Champs d'interface qui n'ont rien à voir avec la fiche de l'entreprise. */
const LIBELLES_DE_TABLEAU = [
  'par page',
  'rows per page',
  'recherche',
  'search',
  'filtre',
  'filter',
];

/**
 * En dessous de ce nombre de champs reconnus, la page n'est pas celle qu'on
 * croit. Déposer quand même produirait une fiche entièrement nulle, qui
 * servirait la demande et se lirait comme un relevé réussi : un scraper muet
 * doit échouer bruyamment.
 */
const MINIMUM_CHAMPS_RECONNUS = 4;

/* ------------------------------ Petits outils ----------------------------- */

function requireEnv(names) {
  const manquantes = names.filter(n => !process.env[n]);
  if (manquantes.length) {
    throw new Error(`Variables d'environnement manquantes : ${manquantes.join(', ')}`);
  }
}

/** Rabote accents, casse et ponctuation pour comparer deux libellés. */
function normaliser(texte) {
  return (texte || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[’ʼ`]/g, "'")
    .toLowerCase()
    .replace(/[^a-z0-9']+/g, ' ')
    .trim();
}

/** Date du jour au format AAAAMMJJ (ex. 20260826). */
function horodatage() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}`;
}

/**
 * L'instant à la seconde — pour le journal, jamais pour un nom de fichier.
 *
 * `horodatage()` au-dessus rend une date nue parce qu'elle nomme des fichiers ;
 * celle-ci porte l'heure parce qu'elle date des lignes.
 *
 * **En UTC, et c'est délibéré.** Laravel journalise sous `config('app.timezone')`,
 * qui vaut `UTC` ; le poste, lui, est à l'heure locale. Le premier essai a
 * produit deux journaux du même passage à `12:55` côté Node et `11:56` côté PHP,
 * ce qui interdisait de les fusionner par un `sort` et faisait paraître le
 * ramassage antérieur au relevé qui l'avait nourri. Une seule horloge, donc,
 * celle de l'application.
 */
function instant() {
  // `toISOString` est en UTC par construction : « 2026-09-08T11:56:28.123Z ».
  // On lui retire le T, les millisecondes et le Z pour retrouver exactement la
  // forme des lignes de Laravel.
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Une ligne de journal, horodatée et attribuée.
 *
 * ## Pourquoi elle existe
 *
 * Tout ce que ces scripts impriment est versé par le planificateur dans
 * `storage/logs/portail-fne.log`, où Laravel écrit désormais aussi ses propres
 * lignes. Sans horodatage, un passage toutes les cinq minutes empile 288 blocs
 * par jour qu'aucun repère ne sépare : « Le système ne peut trouver le fichier »
 * ne dit ni quand, ni lequel des deux scripts, ni pour quel login.
 *
 * Le format reprend celui de Laravel — `[date] canal.NIVEAU: message` — pour
 * qu'un `grep ERREUR` rende les deux moitiés de l'histoire dans le même ordre.
 *
 * `console.error` pour ce qui va mal : le planificateur redirige `2>&1` dans le
 * même fichier, mais un lancement à la main garde la distinction.
 */
function journaliser(script, niveau, message, details) {
  const suffixe = details === undefined ? '' : ' ' + JSON.stringify(details);
  const ligne = `[${instant()}] ${script}.${niveau}: ${message}${suffixe}`;

  if (niveau === 'ERREUR' || niveau === 'ALERTE') console.error(ligne);
  else console.log(ligne);
}

/** Le raccourci de ce script-ci : toutes ses lignes portent « fne ». */
const dire = (niveau, message, details) => journaliser('fne', niveau, message, details);

/** Base commune du nom des deux fichiers : <login>_<AAAAMMJJ>. */
function nomDeBase(login) {
  return `${login}_${horodatage()}`;
}

/**
 * Le dossier de dépôt.
 *
 * PORTAIL_FNE_DOSSIER_IMPORT d'abord : c'est le dossier que Selflow relève
 * réellement, et il est lu dans son propre .env. UPLOAD_PATH_FOLDER ne sert
 * qu'à forcer autre chose depuis le .env du scraper.
 */
function dossierDepot() {
  const choisi =
    process.env.PORTAIL_FNE_DOSSIER_IMPORT ||
    process.env.UPLOAD_PATH_FOLDER ||
    path.join(RACINE_SELFLOW, 'storage', 'app', 'portail-fne');

  const resolu = path.resolve(choisi.replace(/^"|"$/g, ''));
  if (!fs.existsSync(resolu)) fs.mkdirSync(resolu, { recursive: true });
  return resolu;
}

/* --------------------------- La file et le magasin ------------------------ */

/**
 * Demande à Selflow ce qu'il attend. Une file vide rend [] : ce n'est pas une
 * erreur, c'est une réponse, et le scraper s'arrête là.
 */
function lireFileDemandes() {
  const php = process.env.PHP_BINAIRE || 'php';
  let sortie;
  try {
    sortie = execFileSync(php, ['artisan', 'portail-fne:demandes', '--json'], {
      cwd: RACINE_SELFLOW,
      encoding: 'utf-8',
      windowsHide: true,
    });
  } catch (erreur) {
    // La cause utile est dans la sortie d'erreur de PHP — « base injoignable »,
    // « commande inconnue ». Sans elle, le message ne dit que « ça a raté ».
    const cause = String(erreur.stderr || erreur.stdout || '')
      .split(/\r?\n/)
      .map(l => l.trim())
      .filter(Boolean)
      .slice(0, 3)
      .join(' | ');

    throw new Error(
      `Impossible de lire la file (${php} artisan portail-fne:demandes --json depuis ` +
      `${RACINE_SELFLOW})${cause ? ' : ' + cause : '.'}`
    );
  }

  // La dernière ligne non vide : un avertissement PHP en amont ne doit pas
  // faire passer une file lisible pour une file illisible.
  const lignes = sortie.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  const derniere = lignes[lignes.length - 1] || '[]';

  let logins;
  try {
    logins = JSON.parse(derniere);
  } catch (_) {
    throw new Error(`Réponse inattendue de portail-fne:demandes : ${derniere.slice(0, 200)}`);
  }
  if (!Array.isArray(logins)) {
    throw new Error("portail-fne:demandes n'a pas rendu un tableau de logins.");
  }
  return logins.map(String);
}

/**
 * Le magasin de mots de passe, propre au scraper.
 * Deux formes acceptées : "1864699A": "motDePasse" ou
 * "1864699A": { "motDePasse": "...", "libelle": "DC-KNOWING CGA" }.
 */
function lireMagasin() {
  if (!fs.existsSync(FICHIER_IDENTIFIANTS)) return {};

  let contenu;
  try {
    contenu = JSON.parse(fs.readFileSync(FICHIER_IDENTIFIANTS, 'utf-8'));
  } catch (erreur) {
    throw new Error(`identifiants.json illisible : ${erreur.message}`);
  }
  if (!contenu || typeof contenu !== 'object') return {};

  // Les clés commençant par `_` sont des notes, pas des logins. Sans ce tri,
  // `--tous` prenait `_lisez-moi` du modèle pour un compte et allait tenter de
  // s'y connecter sur le portail de la DGI. Un login n'est jamais préfixé
  // ainsi : le NCC est alphanumérique.
  const magasin = {};
  for (const [cle, valeur] of Object.entries(contenu)) {
    if (cle.startsWith('_')) continue;
    magasin[cle] = valeur;
  }
  return magasin;
}

function motDePassePour(login, magasin) {
  const entree = magasin[login];
  if (typeof entree === 'string' && entree) return entree;
  if (entree && typeof entree === 'object' && entree.motDePasse) return entree.motDePasse;
  // Repli mono-entreprise, pour un poste qui n'a qu'un seul portail à relever.
  if (process.env.LOGIN === login && process.env.PASSWORD) return process.env.PASSWORD;
  return null;
}

/** Ce que le script doit relever, selon la façon dont il a été appelé. */
function resoudreTaches() {
  const args = process.argv.slice(2);
  const options = args.filter(a => a.startsWith('--'));
  const positions = args.filter(a => !a.startsWith('--'));

  if (positions.length >= 2) {
    return { origine: 'arguments', logins: [positions[0]], motDePasseDirect: positions[1] };
  }
  if (positions.length === 1) {
    return { origine: 'argument', logins: [positions[0]], motDePasseDirect: null };
  }
  if (options.includes('--tous')) {
    // Le passage complet ne relève que les comptes réellement configurés.
    // Un mot de passe laissé vide dit « pas encore rempli », pas « en panne » :
    // le signaler chaque nuit ferait six lignes d'échec pour une situation
    // normale, et un journal qui crie tous les jours cesse d'être lu.
    //
    // Le passage qui sert la file, lui, reste bruyant : là, quelque chose
    // attend vraiment, et le silence coûterait un relevé.
    const magasin = lireMagasin();
    const tous = Object.keys(magasin);
    const configures = tous.filter(login => motDePassePour(login, magasin));

    return {
      origine: 'magasin',
      logins: configures,
      motDePasseDirect: null,
      enAttenteDeConfiguration: tous.length - configures.length,
    };
  }
  return { origine: 'file', logins: lireFileDemandes(), motDePasseDirect: null };
}

/* --------------------------------- Le portail ----------------------------- */

/** Connexion à la plateforme. */
async function seConnecter(page, login, motDePasse) {
  await page.goto(process.env.FNE_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
  // L'application (Next.js) doit terminer son hydratation avant que le clic sur
  // « Connexion » soit intercepté en JS ; sans cette attente, le navigateur
  // retombe sur une soumission HTML classique — identifiants exposés dans l'URL.
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
  await page.waitForSelector('#username', { timeout: 30000 });
  await page.waitForTimeout(800);
  await page.fill('#username', login);
  await page.fill('#password', motDePasse);
  await Promise.all([
    page.waitForURL('**/dashboard', { timeout: 30000 }),
    page.click('button:has-text("Connexion")'),
  ]);
}

/** Navigation vers « Paramétrage » depuis le tableau de bord. */
async function allerAuParametrage(page) {
  await Promise.all([
    page.waitForURL('**/settings', { timeout: 30000 }),
    page.click('a:has-text("Paramétrage")'),
  ]);
  await page
    .getByText('Tableau des points de vente', { exact: true })
    .waitFor({ timeout: 30000 });
}

/**
 * Relève les champs de la page Paramétrage et les ramène aux clés que Selflow
 * reconnaît. Les listes déroulantes et les interrupteurs sont lus au même titre
 * que les champs texte : « Timbre de quittance » et « Bordereau d'achat de
 * produits agricoles » commandent le comportement fiscal, les manquer
 * reviendrait à relever une fiche muette sur l'essentiel.
 */
async function releverLaFiche(page) {
  const config = { exclus: LIBELLES_EXCLUS };

  const champs = await page.$$eval(
    'input, textarea, select, [role="switch"], [role="checkbox"]',
    (elements, cfg) => {
      const normaliserDom = t => (t || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[’ʼ`]/g, "'")
        .toLowerCase()
        .replace(/[^a-z0-9']+/g, ' ')
        .trim();

      const libelleDe = el => {
        if (el.id) {
          const lbl = document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
          if (lbl && lbl.innerText.trim()) return lbl.innerText.trim();
        }
        const englobant = el.closest('label');
        if (englobant && englobant.innerText.trim()) return englobant.innerText.trim();

        const aria = el.getAttribute('aria-label');
        if (aria && aria.trim()) return aria.trim();

        const parId = el.getAttribute('aria-labelledby');
        if (parId) {
          const cible = document.getElementById(parId.split(/\s+/)[0]);
          if (cible && cible.innerText.trim()) return cible.innerText.trim();
        }

        let noeud = el;
        for (let profondeur = 0; profondeur < 4 && noeud; profondeur++) {
          noeud = noeud.parentElement;
          if (!noeud) break;
          const candidat = noeud.querySelector('label, [class*="label"]');
          const texte = candidat && candidat.innerText ? candidat.innerText.trim() : '';
          if (texte) return texte;
        }

        const placeholder = el.getAttribute('placeholder');
        return placeholder && placeholder.trim() ? placeholder.trim() : null;
      };

      const valeurDe = el => {
        const role = (el.getAttribute('role') || '').toLowerCase();
        if (role === 'switch' || role === 'checkbox') {
          return el.getAttribute('aria-checked') === 'true';
        }
        const type = (el.type || '').toLowerCase();
        if (type === 'checkbox') return el.checked;
        if (el.tagName === 'SELECT') {
          const option = el.selectedOptions && el.selectedOptions[0];
          const texte = option ? (option.innerText || option.value || '').trim() : '';
          return texte || null;
        }
        const valeur = (el.value || '').trim();
        return valeur || null;
      };

      const HORS_SUJET = ['file', 'password', 'submit', 'button', 'reset', 'hidden', 'image'];

      const releve = [];
      for (const el of elements) {
        const role = (el.getAttribute('role') || '').toLowerCase();
        const estInterrupteur = role === 'switch' || role === 'checkbox';
        const type = (el.type || '').toLowerCase();

        // Le rôle prime sur le type : un `<button role="switch">` porte
        // `type="submit"` par défaut, et serait écarté comme un bouton
        // ordinaire — or c'est exactement ainsi que le portail rend le timbre
        // de quittance et le bordereau d'achat.
        // Un champ grisé, lui, porte quand même la valeur du portail : gardé.
        if (!estInterrupteur && HORS_SUJET.includes(type)) continue;
        if (type === 'radio' && !el.checked) continue;

        const libelle = libelleDe(el);
        if (!libelle) continue;

        const normalise = normaliserDom(libelle);
        if (!normalise) continue;
        if (cfg.exclus.some(exclu => normalise.includes(exclu))) continue;

        releve.push({ libelle, normalise, valeur: valeurDe(el) });
      }
      return releve;
    },
    config
  );

  const parNormalise = new Map(CLES_PORTAIL.map(cle => [normaliser(cle), cle]));
  const reconnus = {};
  const inconnus = {};

  for (const champ of champs) {
    const cle = parNormalise.get(champ.normalise);
    if (cle) {
      // Un même libellé peut apparaître deux fois ; la première valeur non
      // vide fait foi.
      if (reconnus[cle] === undefined || reconnus[cle] === null) reconnus[cle] = champ.valeur;
      continue;
    }
    if (LIBELLES_DE_TABLEAU.some(motif => champ.normalise.includes(motif))) continue;
    if (inconnus[champ.libelle] === undefined) inconnus[champ.libelle] = champ.valeur;
  }

  const nombreReconnus = Object.keys(reconnus).length;
  if (nombreReconnus < MINIMUM_CHAMPS_RECONNUS) {
    throw new Error(
      `Page Paramétrage méconnaissable : ${nombreReconnus} champ(s) reconnu(s) sur ` +
      `${CLES_PORTAIL.length}. Le portail a changé de libellés ou de structure ; ` +
      "rien n'est déposé, la demande reste ouverte."
    );
  }

  // Les quatorze clés sont toujours écrites, dans l'ordre du référentiel : une
  // valeur absente vaut null, et null se lit « le portail n'a rien ». Un champ
  // manquant reste ainsi visible au lieu de disparaître du fichier.
  const fiche = {};
  for (const cle of CLES_PORTAIL) fiche[cle] = reconnus[cle] === undefined ? null : reconnus[cle];
  Object.assign(fiche, inconnus);

  return { fiche, nombreReconnus, inconnus: Object.keys(inconnus) };
}

/**
 * Export Excel du « Tableau des points de vente » : bouton icône en bas de la
 * section, puis « Excel » dans le menu déroulant.
 */
async function telechargerLesPoints(page, login, dossier) {
  const titre = page.getByText('Tableau des points de vente', { exact: true });
  const boutonExport = titre.locator(
    'xpath=following::button[contains(@class,"bg-secondary") and contains(@class,"rounded-lg")][1]'
  );
  await boutonExport.scrollIntoViewIfNeeded();
  await boutonExport.click();

  await page.waitForSelector('text=Excel', { timeout: 10000 });

  const [telechargement] = await Promise.all([
    page.waitForEvent('download', { timeout: 30000 }),
    page.click('text=Excel'),
  ]);

  const extension = (path.extname(telechargement.suggestedFilename()) || '.xlsx').toLowerCase();
  const chemin = path.join(dossier, `${nomDeBase(login)}${extension}`);
  await telechargement.saveAs(chemin);

  if (extension !== '.xlsx') {
    // Renommer en .xlsx ne convertirait rien : le fichier serait pris en charge
    // puis rejeté à la lecture. Mieux vaut le dire ici.
    dire('ALERTE', 
      `   /!\\ Le portail a servi un ${extension} : Selflow ne range que les .json et .xlsx.`
    );
  }
  return chemin;
}

/* --------------------------------- L'envoi HTTP --------------------------- */

/** Envoi optionnel vers un serveur distant, quand le scraper ne tourne pas sur le poste de Selflow. */
async function envoyerAuServeur(chemin) {
  const champ = process.env.SERVER_UPLOAD_FIELD || 'file';
  const formulaire = new FormData();
  formulaire.append(champ, new Blob([fs.readFileSync(chemin)]), path.basename(chemin));

  const entetes = {};
  if (process.env.SERVER_UPLOAD_TOKEN) {
    entetes.Authorization = `Bearer ${process.env.SERVER_UPLOAD_TOKEN}`;
  }

  const reponse = await fetch(process.env.URL_SERVER, {
    method: 'POST',
    headers: entetes,
    body: formulaire,
  });

  const corps = await reponse.text().catch(() => '');
  if (!reponse.ok) {
    throw new Error(`Échec de l'envoi (HTTP ${reponse.status}) : ${corps.slice(0, 300)}`);
  }
  return reponse.status;
}

/* ------------------------------ Un relevé, un login ----------------------- */

async function releverUnLogin(navigateur, login, motDePasse, dossier) {
  // Un contexte neuf par login : deux entreprises ne doivent jamais partager
  // une session, sous peine de ranger le relevé de l'une chez l'autre.
  const contexte = await navigateur.newContext({ acceptDownloads: true });
  const page = await contexte.newPage();

  // Le jeton des appels `/ws/` se capte au vol, sur un appel que la page fait
  // elle-même : l'écoute doit donc être posée avant toute navigation. Elle ne
  // sert qu'au relevé des factures reçues, plus bas, et le jeton ne vit qu'en
  // mémoire — ni journal, ni fichier déposé.
  const { capterLAutorisation, releverDansLaSession } = require('./achats.js');
  const autorisation = capterLAutorisation(page);

  try {
    dire('INFO', '   Connexion...');
    await seConnecter(page, login, motDePasse);

    dire('INFO', '   Parametrage...');
    await allerAuParametrage(page);

    const { fiche, nombreReconnus, inconnus } = await releverLaFiche(page);
    const cheminJson = path.join(dossier, `${nomDeBase(login)}.json`);
    fs.writeFileSync(cheminJson, JSON.stringify(fiche, null, 2), 'utf-8');
    dire('INFO', 
      `   Fiche : ${nombreReconnus}/${CLES_PORTAIL.length} champs reconnus -> ${path.basename(cheminJson)}`
    );
    if (inconnus.length) {
      dire('INFO', `      champs non referencés, déposés tels quels : ${inconnus.join(', ')}`);
    }

    const cheminExcel = await telechargerLesPoints(page, login, dossier);
    dire('INFO', `   Points de facturation -> ${path.basename(cheminExcel)}`);

    // Une session ouverte est ce qui coûte : le portail de la DGI demande une
    // connexion avec le mot de passe du client, et y retourner souvent est le
    // meilleur moyen de faire bloquer le compte. Tant qu'on y est, on relève
    // tout — les factures reçues comprises, qui partaient jusqu'ici d'un second
    // passage rouvrant une seconde session pour la même entreprise.
    //
    // Leur échec ne perd pas le relevé : la fiche et les points sont déjà
    // déposés, et c'est eux que la certification attend. On le dit, et on rend
    // la main.
    try {
      await releverDansLaSession(page, autorisation, login, path.join(dossier, 'achats'));
    } catch (erreur) {
      dire('ALERTE', `   /!\ Factures reçues non relevées : ${erreur.message}`);
    }

    if (process.env.URL_SERVER) {
      const statutJson = await envoyerAuServeur(cheminJson);
      const statutExcel = await envoyerAuServeur(cheminExcel);
      dire('INFO', `   Envoyés au serveur distant (HTTP ${statutJson} / ${statutExcel}).`);
    }

    return { login, ok: true };
  } catch (erreur) {
    let capture = null;
    try {
      if (!fs.existsSync(DOSSIER_ERREURS)) fs.mkdirSync(DOSSIER_ERREURS, { recursive: true });
      capture = path.join(DOSSIER_ERREURS, `${nomDeBase(login)}.png`);
      await page.screenshot({ path: capture, fullPage: true });
    } catch (_) {
      capture = null; // la page n'est plus disponible
      // Repli sur le HTML brut : constaté le 15/09/2026, `--single-process`
      // (nécessaire pour tenir dans la limite de processus de l'hébergement
      // mutualisé) prive Chromium du compositeur dont `screenshot()` dépend.
      // Le contenu de la page reste lisible, lui, et vaut mieux qu'un
      // diagnostic muet.
      try {
        const secours = path.join(DOSSIER_ERREURS, `${nomDeBase(login)}.html`);
        fs.writeFileSync(secours, await page.content());
        capture = secours;
      } catch (_) {
        capture = null;
      }
    }
    return { login, ok: false, motif: erreur.message, capture };
  } finally {
    await contexte.close();
  }
}

/* ---------------------------------- Le passage ---------------------------- */

async function passage() {
  requireEnv(['FNE_URL']);

  const dossier = dossierDepot();
  const taches = resoudreTaches();
  const magasin = taches.motDePasseDirect ? {} : lireMagasin();

  const provenance = {
    file: 'la file de Selflow',
    magasin: 'identifiants.json',
    argument: 'la ligne de commande',
    arguments: 'la ligne de commande',
  }[taches.origine];

  // Dit une fois, sans dramatiser : c'est une configuration à finir, pas une
  // panne. Le compte concerné se signalera de lui-même le jour où une pièce
  // sera refusée — sa demande restera ouverte et l'écran des rejets le dira.
  if (taches.enAttenteDeConfiguration) {
    dire('INFO', 
      `${taches.enAttenteDeConfiguration} compte(s) sans mot de passe dans `
      + 'identifiants.json : ignorés pour ce passage.'
    );
  }

  if (!taches.logins.length) {
    dire('INFO', `Rien à relever (${provenance} est vide).`);
    return;
  }

  dire('INFO', `${taches.logins.length} relevé(s) à faire, d'après ${provenance}.`);
  dire('INFO', `Dépôt dans : ${dossier}`);

  // Les mots de passe d'abord, le navigateur ensuite : ouvrir Chromium pour
  // découvrir qu'aucun login n'est utilisable coûte dix secondes pour rien.
  const resultats = [];
  const relevables = [];

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
    const headless = process.env.FNE_HEADLESS !== 'false';
    // `--single-process --no-zygote` : constaté le 15/09/2026 sur l'hébergement
    // mutualisé de production (cPanel/CloudLinux). Le zygote de Chromium fork un
    // processus par onglet, et le compte y est plafonné en nombre de processus —
    // invisible depuis `ulimit`, qui répond « illimité » alors que le fork échoue
    // en `pthread_create: Resource temporarily unavailable`. Ces deux options
    // font tenir Chromium dans un seul processus.
    const navigateur = await chromium.launch({
      headless,
      args: ['--no-sandbox', '--single-process', '--no-zygote'],
    });
    try {
      for (const { login, motDePasse } of relevables) {
        dire('INFO', `\n-- ${login} --`);
        // Un login qui échoue n'arrête pas les autres : sa demande reste
        // ouverte, et Selflow la signalera passé le délai d'alerte.
        resultats.push(await releverUnLogin(navigateur, login, motDePasse, dossier));
      }
    } finally {
      await navigateur.close();
    }
  }

  const reussis = resultats.filter(r => r.ok);
  const echoues = resultats.filter(r => !r.ok);

  dire('INFO', `\n${'-'.repeat(60)}`);
  dire('INFO', 
    `${reussis.length} relevé(s) déposé(s) : ${reussis.map(r => r.login).join(', ') || '(aucun)'}`
  );

  if (echoues.length) {
    dire('ERREUR', `${echoues.length} en échec :`);
    for (const echec of echoues) {
      dire('ERREUR', `   - ${echec.login} : ${echec.motif}`);
      if (echec.capture) dire('ERREUR', `     capture : ${echec.capture}`);
    }
    dire('ERREUR', "   Leurs demandes restent ouvertes : c'est voulu.");
    process.exitCode = 1;
  }

  if (reussis.length) {
    dire('INFO', 
      '\nSelflow rangera ces fichiers au prochain passage horaire ' +
      '(ou tout de suite : php artisan portail-fne:importer).'
    );
  }
}

// Lancé en ligne de commande : on relève. Chargé par un autre fichier
// (verifier-extraction.js) : on n'expose que les rouages, sans rien lancer.
if (require.main === module) {
  passage().catch(erreur => {
    dire('ERREUR', erreur.message);
    process.exitCode = 1;
  });
}

// `achats.js` s'appuie sur ces rouages plutôt que de les recopier. La connexion
// surtout : elle porte l'attente d'hydratation Next.js sans laquelle le clic sur
// « Connexion » retombe sur une soumission HTML classique — identifiants exposés
// dans l'URL. Deux copies, et c'est l'une des deux qui gardera le défaut le jour
// où le portail bougera.
//
// Seule cette liste a été complétée : aucune ligne de logique n'a changé, et
// `verifier-extraction.js` continue de le vérifier à chaque exécution.
module.exports = {
  CLES_PORTAIL,
  MINIMUM_CHAMPS_RECONNUS,
  normaliser,
  nomDeBase,
  horodatage,
  instant,
  journaliser,
  dossierDepot,
  seConnecter,
  lireMagasin,
  releverLaFiche,
  motDePassePour,
  lireFileDemandes,
};

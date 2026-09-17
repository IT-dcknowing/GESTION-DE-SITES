# État des lieux du projet — le fil à reprendre

*Tenu à jour à la fin de chaque séance de travail. Dernière mise à jour : **17 septembre 2026**.*

Ce fichier existe pour une seule raison : **qu'une nouvelle séance, sur n'importe quel poste,
reprenne le travail là où il s'est arrêté, sans rien réapprendre et sans rien défaire.** Il dit
où en est chaque module, ce qui est en cours, ce qui a été décidé, ce qui attend une décision,
et les règles qu'on ne transgresse pas.

**À lire en premier, dans cet ordre :** ce fichier → le document du chantier en cours
(aujourd'hui [ETAT-DES-IMPAYES.md](ETAT-DES-IMPAYES.md)) → [ARCHITECTURE.md](ARCHITECTURE.md)
si l'on touche à la structure.

**À faire en dernier, à chaque séance :** mettre à jour les §§ 4, 5 et 6 ci-dessous, et la date.

---

## 1. Le projet en dix lignes

**GESTION-DE-SITES** — application de gestion multi-entreprises pour des garages automobiles.
Premier client réel : **L'Artisan Automobile** (Côte d'Ivoire) — trois villes (Abidjan avec deux
ateliers, Bouaké, San-Pédro).

| | |
|---|---|
| Pile | Laravel 13, Livewire 4, Volt (composants mono-fichier), `nwidart/laravel-modules` |
| Droits | Spatie laravel-permission **par équipe** (`entreprise_id`) ; équipe `0` = plateforme |
| Base | MySQL en ligne ; SQLite en mémoire pour les tests |
| Tests | `php artisan test` — 561 tests, 554 réussis, **0 échec** ; les 7 erreurs WebPush (courbe P-256 absente du poste) sont connues et sans conséquence |
| Dépôts | `IT-dcknowing/GESTION-DE-SITES` et `meledjeabrahamagnimel-lgtm/GESTION-DE-SITES` (deux URL de push sur `origin`) |
| Production | `gestionsites.dc-knowing.com` — `~/public_html/GESTION-DE-SITES` |
| Développement | `gestion-dev.dc-knowing.com` — `~/public_html/gestion-dev/GESTION-DE-SITES`, copie de la base de production, protégé par mot de passe navigateur |
| Local | `C:\laragon\www\GESTION-DE-SITES`, base `gestionsites` |

Déployer : [MISE-A-JOUR-SERVEUR.md](MISE-A-JOUR-SERVEUR.md). Monter le serveur de dev :
[ENVIRONNEMENT-DE-DEV.md](ENVIRONNEMENT-DE-DEV.md).

---

## 2. Les règles qui ne se discutent plus

Posées par le propriétaire du projet. Elles priment sur toute habitude.

1. **La base en ligne porte des données réelles.** Migrations **additives** uniquement (tables
   neuves, colonnes nullables, contraintes relâchées). Jamais de seeder, de purge ni de
   `migrate:fresh` sur un serveur. Une commande qui écrit des données s'exécute **d'abord en
   constat** et ne s'écrit **pas** dans `app:deployer`.
2. **Ne jamais agir sur les données d'une interface qui en contient déjà.**
3. **Sécurité d'abord** : autorisation vérifiée à la route *et* dans l'action, périmètre lu sur
   l'identité du lecteur et jamais sur un paramètre reçu, transactions et verrous sur les
   écritures concurrentes, aucune donnée sensible dans les pages d'erreur.
4. **Une branche git par module.** Le travail d'un module se fait et se commite sur sa branche.
5. **Commits autorisés** sur la branche du module (et fusion sur `main` quand elle est demandée).
   Le **push** est bloqué côté assistant par le classifieur : c'est le propriétaire qui pousse.
6. **Jamais de déploiement par zip.** `git pull` + `php artisan app:deployer`, sur le dev d'abord.
7. **Pas de dialogue navigateur** (`confirm`, `alert`) ; filtres instantanés ; écrans communs
   repliés sous « Paramètres ».
8. **Le chemin critique marche sans JavaScript** : le dépôt d'import est un vrai POST.
9. **Écrire comme le code existant** : commentaires en français, qui expliquent *pourquoi* et
   citent la mesure qui a motivé le choix.

---

## 3. Les modules

```
Noyau            le socle : modèles, migrations, services, écrans communs
SuperAdmin       la plateforme : entreprises, accès, codes, traçabilité, maintenance
Gerant           la direction : tableau de bord, paramètres, réaffectations
Superviseur      le pilotage de la ville : indicateurs, accès, annuaire
ResponsableSite  la saisie du jour
Comptabilite     la caisse : encaissements, décaissements
Commercial       le terrain : performance, prospections, notes
Recouvrement     la poursuite des créances
Import           la reprise des fichiers du logiciel d'atelier
```

| Module | État | Écrans |
|---|---|---|
| **Noyau** | stable | connexion, Google OAuth, inscription, mot de passe, messages, notifications, mon espace, mon profil, loupe ville/exercice, pages de panne |
| **SuperAdmin** | stable | tableau de bord, entreprises, accès (liste/création/modification), administrateurs, codes atelier, journal, traçabilité, maintenance, mode « switch » |
| **Gerant** | stable | tableau de bord, paramètres (référentiels), réaffecter |
| **Superviseur** | **en évolution** | prospects, devis, chiffre d'affaires, commerciaux, charges, trésorerie, caisse, fournisseurs, parc véhicules (+ fiche), clients, entrées/sorties, **état des impayés**, **état initial**, **rapprochement CA/impayés**, accès, annuaire |
| **ResponsableSite** | stable | saisie du jour, fiche de prospection |
| **Comptabilite** | stable | tableau de bord, encaissements, décaissements |
| **Commercial** | stable | ma performance, mes prospections, mes notes |
| **Recouvrement** | stable | tableau de bord, dossier, saisie, synthèse, balance âgée, courtiers, clients & tiers, extrait de compte, relances, encaissements, audit, téléchargements |
| **Import** | stable | dépôt, informations, traitements, lots (+ rejets, corrections, traçabilité), codes |

### Les rôles

`super_admin` (plateforme) · `gerant` · `responsable_ville` (superviseur de ville) ·
`responsable_site` · `responsable_commercial` · `commercial` · `caissier` ·
`superviseur_recouvrement` · `agent_recouvrement`.

Source unique : `ProvisionneurEntreprise::ROLES`. Libellés : `LibellesRoles`. Rangs :
`HierarchieAcces::RANGS`. Rôles qui vendent : `RolesCommerciaux::TOUS`. **Tout nouveau rôle
doit être inscrit dans ces quatre listes et dans `RedirectionController`** — sinon il ne peut pas
se connecter ; `AtterrissageDeChaqueRoleTest` le détecte.

### Les formats d'import (dans l'ordre où il faut les déposer)

`parc` → `devis` → `factures` (CATTC) → `impayes` → `fournisseurs` → `caisse` → `entrees` →
`sorties`. Source : `Registre::DISPONIBLES`.

---

## 4. Chronologie récente

| Date | Commit | Branche | Ce qui a été fait |
|---|---|---|---|
| 14/09 | `5bc97b9` | main | recouvrement, imports, page de panne |
| 14/09 | `91f09b9` | main | un responsable pour plusieurs ateliers ; rôle `responsable_commercial` |
| 14/09 | `d7405b5` | main | protection du serveur de dev |
| 14/09 | `abeada1` | main | documentation de déploiement et du serveur de dev |
| 14/09 | `ff648c2` | main | le responsable commercial pouvait tout sauf se connecter |
| 14/09 | `e43bad0` | main | « Ma performance » pour tous ceux qui vendent |
| 15/09 | `6013a36` | **impayes** | état des impayés, état initial, rapprochement, colonnes du CATTC |
| 15/09 | `517416e` | **impayes** | formulaire en deux rangées, `ETAT-DES-IMPAYES.md` |
| 16/09 | `e1e3782` | **impayes** | FICORE retiré ; définition « facture déposée » ; ce fichier |
| 16/09 | `686810b` | **impayes** | âge depuis le dépôt ; ville des factures ; Détail, Modifier, Porter ; date de réception obligatoire |
| 16/09 | `5064774` | **impayes** | le détail d'une créance a sa page (`/impayes/creance/{id}`) |
| 16/09 | `eaf14b2` | **import** (depuis `main`) | la lecture démarre au dépôt ; barre de progression réelle ; « Traiter maintenant » / « Tout traiter » retirés ; « Annuler l'import » |
| 16/09 | `eaf14b2` | **main** | le propriétaire fusionne `import` seul dans `main` et déploie : le menu perd *État des impayés* et *Rapprochement*, restés sur `impayes` |
| 17/09 | `4c80ffc` | **impayes** | `main` (donc `import`) fusionné dans `impayes` ; seul conflit : la liste des commandes du NoyauServiceProvider |
| 17/09 | voir `git log` | **impayes** | Porter : listes client → facture (numéro de saisie) cherchables, champs préremplis, composant à part ; bouton « Porter à l'état » sur le chiffre d'affaires ; état et chiffre d'affaires calculés en base ; index `encaissements (facture_id, montant)` ; caches reconstruits au déploiement |
| 17/09 | voir `git log` | **impayes** | Porter = le formulaire de saisie prérempli (avance montrée et déduite) ; « + Ajouter une créance » s'ouvre sans le serveur ; menu préchargé au survol ; un dépôt endormi repart seul (`lots_import.controle`) ; rubrique *Vitesse* du diagnostic |

**Incident du 14/09** : la production a été mise en ligne par zip et a reçu le `.env` local ;
site tombé une journée. Réparé, et règle 6 posée. Voir MISE-A-JOUR-SERVEUR.md § 2.

---

## 5. Le chantier en cours : l'état des impayés

**Branche `impayes`**, poussée le 16/09, **toujours pas fusionnée dans `main`** : c'est pourquoi,
après la fusion de `import` seul, le menu *Indicateurs* du serveur n'a ni *État des impayés* ni
*Rapprochement*. Depuis le 17/09 elle contient `main` (donc `import`) : `git merge impayes` sur
`main` est une avance simple. Détail complet : [ETAT-DES-IMPAYES.md](ETAT-DES-IMPAYES.md).

### Ce qui est fait

- Trois écrans dans **Indicateurs** : *État des impayés*, *Tableau état initial*,
  *Rapprochement CA / impayés* — ouverts au gérant, superviseur de ville, responsable de site.
- Saisie d'une créance : seize colonnes du classeur, deux rangées, référence `IMP-JJMM-NNNN`.
- Reconduction d'une année sur l'autre **en lecture** (aucune ligne recopiée).
- L'écran *Chiffre d'affaires* montre les colonnes du CATTC et filtre par origine.
- Commande `impayes:ranger-les-colonnes` (constat par défaut, `--appliquer` pour écrire).
- Migration `2026_09_15_000001` : dix colonnes nullables sur `factures`, type de compteur en
  texte.

### Ce qui est décidé

- **L'état des impayés recense les factures physiquement déposées chez le client.** Tout s'y
  saisit à la main, numéro de facture compris. La date de réception = la date du dépôt.
- **On garde les deux formulaires** : bloc « Facture » du Recouvrement (créance découverte en
  relançant) et saisie de l'état des impayés (facture déposée). Ils ne consignent pas le même
  fait ; l'état garde donc son propre marqueur `exercice_impayes`.
- Le nom de l'écran est **« État des impayés »** (FICORE retiré).
- **16/09 — l'âge d'une créance part du dépôt** (date de réception), de l'édition à défaut,
  pour **tout** le recouvrement (`Recouvrement::dateDeDepart()`). Fait.
- **16/09 — « Porter une facture existante »** construit (CATTC, saisie du jour, bloc Facture
  du recouvrement → état, avec le règlement déjà reçu). Fait.
- **16/09 — date de réception obligatoire** à la saisie et au portage. Fait.
- **16/09 — la ville du recouvrement** : sélecteur sur le tableau de bord ; `factures.ville_id`
  (la colonne SITE des fichiers nomme une ville, Abidjan a deux ateliers). Fait.

### Fait le 16/09 (deuxième séance)

- Tableau de l'état : toutes les colonnes du classeur, dont **Commentaires** ; boutons
  **Détail** — une page propre, `/impayes/creance/{id}` (origine, règlements, historique avant/après) et **Modifier** (clé d'import
  verrouillée côté serveur sur les lignes reprises ; « nouveau règlement » ajoute un
  encaissement ; périmètre relu à chaque action).
- Migration `2026_09_16_000001` (`factures.ville_id`, additive) ; commande
  `factures:poser-la-ville` (constat par défaut) ; l'import écrit la ville **établie** par le
  fichier, jamais la ville présumée du dépôt.
- Recouvrement : filtre ville par ville **ou** atelier ; encaissements filtrés par ville
  (tableau de bord, écran Encaissements) ; « déposée le » sous l'âge dans dossier et extrait.
- Une facture portée à l'état entre au recouvrement (`Facture::scopeAvecHistoriqueDeReglement`).
- Défaut corrigé : `reset()` de Volt remettait les champs à null — la seconde créance d'une
  série se voyait réclamer un mode de règlement.
- En local seulement : les deux fichiers d'origine (`PLAN/MODULE-2`, empreintes vérifiées) ont
  été relus pour écrire ville et date de réception ; le lot local n° 20 est passé un instant en
  « échec » (fichier stocké absent) et a été rétabli à partir de ses compteurs.
- Tests : `DepotEtVilleDesCreancesTest` (18) ; suite complète 561 tests, 554 réussis, 0 échec.

### Fait le 17/09

- **Porter une facture existante** devient deux listes qu'on fouille par l'intérieur : le
  **client** (425 en local, avec le nombre de factures), puis **ses factures**, désignées par
  leur **numéro de saisie** (`F-…`, n° de facture, date, immatriculation, montant). La facture
  choisie remplit atelier, ville, n° de sinistre, banque et date de réception connue, et affiche
  le reste en lecture. Composant à part, `pilotage.impayes-porter` : un choix ne redessine plus
  le tableau de l'état. Arrivée directe par `/impayes?porter={id}`.
- **Chiffre d'affaires** : colonne « État des impayés » — **Porter à l'état** (ouvre le panneau
  sur la facture) ou « À l'état 2026 » (lien vers le détail). Masquée au responsable commercial.
- Date de réception : **déjà obligatoire** depuis le 16/09, à la saisie comme au portage —
  vérifié, testé.
- **Vitesse**, mesurée en local : l'état des impayés ne charge plus ses 8 852 lignes à chaque
  clic (solde, report, totaux et page calculés en base — `EtatDesImpayes::totauxEnBase`,
  `filtrerLeSolde`, confrontés par test à la règle PHP) : ~780 → ~530 ms par interaction. Le
  chiffre d'affaires : 42 requêtes et 1,4 s → 19 requêtes et ~0,5 s (totaux groupés, graphique
  en deux lectures au lieu de deux par point, tableau paginé en base). Migration
  `2026_09_17_000001` (index). `app:deployer` reconstruit les caches de routes, évènements et
  gabarits.
- `x-select-cherchable` : ses ressources passent dans `select-cherchable-ressources`, incluses
  par l'écran ; le script se garde contre une double inclusion et se rebranche après chaque
  échange Livewire (crochet `commit`). Vérifié dans Chrome sans tête (hors Livewire).
- Tests : `DepotEtVilleDesCreancesTest` (24) ; suite complète 576 tests, 569 réussis, 7 erreurs
  WebPush connues (clé P-256 locale), 0 échec.

### Fait le 17/09 (deuxième passe — retours d'usage)

- **Porter est devenu le formulaire de saisie, rempli d'avance** : mêmes deux rangées, mêmes
  mots que « Nouvelle créance ». Quatre cases sont figées — date d'édition, numéro, montant
  (ils appartiennent à l'écran qui a créé la facture) et, sur une ligne reprise d'un fichier,
  l'immatriculation (clé d'import). Deux cases nouvelles : **Avance déjà encaissée** et
  **Reste à payer**, et le règlement saisi ne peut pas dépasser ce reste.
- **« + Ajouter une créance » n'appelle plus le serveur** : le formulaire est rendu replié et le
  clic le déplie (`x-show` sur `$wire`, `$set(..., false)`). Le serveur n'est appelé que pour
  sortir d'une modification en cours. La date d'édition est posée au montage.
- **Menu préchargé au survol** (`wire:navigate.hover`) : la page est là avant le clic.
- **Import — un dépôt endormi repart seul.** Un fichier déposé le 16/09 attendait encore le
  lendemain : déposé par l'ancien chemin, sur un poste sans ouvrier de file, plus rien ne
  pouvait le prendre. `SuiviDuTraitement::reveiller()` le relance quand on regarde son écran,
  une fois par minute au plus. Migration `2026_09_17_000002` : `lots_import.controle`, pour
  qu'une relance tardive n'écrive pas ce qui avait été demandé comme une simulation.
- **`app:diagnostic` a une rubrique *Vitesse*** : OPcache, caches de routes et d'évènements,
  gabarits compilés, magasin de cache.
- Reste à faire côté hébergement, et c'est le premier poste : **OPcache** sur le PHP qui sert
  les pages, et `CACHE_STORE=file`. Voir MISE-A-JOUR-SERVEUR.md.

### Ce qui attend une décision

1. Les arbitrages pris sans confirmation, listés au § 6 d'ETAT-DES-IMPAYES.md (année = année
   de la facture ; état initial en lecture seule ; fermé au responsable commercial ; série
   `IMP-` ; atelier facultatif en modification ; relances non filtrées par ville).
2. **Fusion de `impayes` dans `main`** (`git checkout main && git merge impayes`, avance simple),
   puis déploiement (dev d'abord) et, sur chaque serveur : `impayes:ranger-les-colonnes` puis
   `factures:poser-la-ville` (constat, puis `--appliquer`). Voir MISE-A-JOUR-SERVEUR.md, 17/09.
3. Côté hébergement, facultatif : OPcache, `CACHE_STORE=file`.

### Chiffres de référence (à ne pas remesurer)

Classeur recalculé : 6 329 977 795 F facturés, 5 535 425 213 F réglés, **798 999 354 F** de
reste sur 1 332 créances ouvertes. Reprise en base : 791 268 390 F. CATTC repris : 2026
seulement, 2 375 lignes, 1 384 588 526 F. Pont immatriculation + montant : 71 % (78 % sur la
plaque). Écart 2026 : 95 176 169 F sur 424 factures. 103 factures du Recouvrement
(40 424 806 F) hors de l'état — normal depuis la définition « facture déposée ».
Colonne SITE du classeur (lue par l'import) : ABIDJAN 5 097, SAN PEDRO 4, vide 3 771 → en
local 274 créances ouvertes (134 509 255 F) « ville à préciser », visibles dans toutes les
villes. Âge depuis le dépôt : 8 factures ouvertes sur 1 341 changent de niveau.

---

## 5 bis. Branche `import` — la lecture démarre au dépôt

**Branche `import`, fusionnée dans `main` le 16/09 par le propriétaire et déployée** (la
migration `2026_09_16_000002` est passée sur le serveur). Fusionnée aussi dans `impayes` le
17/09.

- **Défaut signalé** : après « Lancer l'import », il fallait cliquer « Traiter maintenant » ou
  « Tout traiter », et la barre restait figée.
- **Fait** : `LanceurDeTraitement` lance `php artisan import:traiter-lot {id}` détaché au dépôt
  (file d'attente gardée comme filet ; repli après réponse) ; `SuiviDuTraitement` — prise
  atomique du lot, avancée et demande d'arrêt dans le **cache fichier** (la ligne du lot est
  verrouillée par la transaction : 10 clés étrangères y pointent) ; `lignes_estimees` lu dans
  `<dimension>` du `.xlsx` (migration `2026_09_16_000002`) ; composant
  `x-import::progression` sur dépôt, traitements et détail du lot ; bouton **Annuler l'import**
  (geste `arreter`) ; route `POST /import/traitements` supprimée ; les corrections relancent
  par le même chemin.
- **Serveur** : `IMPORT_PHP_CLI` si le PHP ligne de commande n'est pas `/usr/local/bin/php` ;
  le cron `queue:work` reste. Voir MISE-A-JOUR-SERVEUR.md (sur la branche `import`).
- **Vérifié en local** par le vrai chemin détaché (0 → 37 % → 76 % → fin). Tests :
  `LectureImmediateDesImportsTest` (10) ; suite de la branche 535 / 528 / 0 échec.
- **À vérifier sur le serveur de dev** : que `exec` est permis et que la barre avance.

## 6. Ce qui attend, hors du chantier en cours

- **Rotation des secrets** (le `.env` de production a circulé en clair) : mot de passe du
  courriel `infos@dc-knowing.com`, secret Google OAuth, mot de passe MySQL, puis `APP_KEY` et
  clés VAPID. **Priorité la plus haute, côté propriétaire.**
- **Responsable commercial**, deux arbitrages pris sans confirmation : il ne gère pas les accès
  de ses commerciaux ; avec « Toutes les villes », sa fiche de vendeur se rattache à la première
  ville par ordre alphabétique. Il atterrit sur *Saisie du jour* (proposé : *Commerciaux*).
- *Mes prospections* et *Mes notes* restent fermées au responsable de ville et de site
  (ils saisissent par *Saisie du jour*).
- La branche locale `recouvrement` est ancienne (`789a993`) et entièrement fusionnée : peut être
  supprimée.

---

## 7. Reprendre le travail — le mode d'emploi

```bash
cd C:/laragon/www/GESTION-DE-SITES
git status && git branch --show-current && git log --oneline -5
php artisan migrate:status | grep Pending
php artisan test
```

### Pièges d'outillage déjà rencontrés

| Piège | Parade |
|---|---|
| Un heredoc bash contenant « » et apostrophes casse (`unexpected EOF`) | écrire le script Python dans le dossier scratchpad avec l'outil d'écriture, puis `python fichier.py` |
| Python bute sur `\N` dans `Modules\Noyau` | chaînes brutes, ou `B = chr(92)` |
| `php artisan test` renvoie du JSON | le lire avec un petit `python -c "import json,sys…"` |
| Lire un `.xlsx` à la main : les cellules vides sont auto-fermantes `<c r="M49" s="43"/>` | les gérer explicitement, sinon les valeurs glissent d'une colonne (erreur commise le 15/09) |
| Volt garde une classe compilée périmée | `php artisan view:clear` |
| `git commit` avec heredoc refusé | message dans un fichier, `git commit -F fichier` |
| `git push` refusé par le classifieur | ne pas contourner ; demander au propriétaire de pousser |
| `x-champ` attend `[valeur => libellé]` ; `PerimetreSites::optionsVilles/Sites` rendent des modèles **ou null** | `?->pluck('nom', 'id')->all() ?? []` |
| `$this->reset([...])` dans un composant Volt remet à **null**, pas à la valeur de `state()` | vider par affectation (`$this->champ = ''`) — sinon `exclude_if:champ,` ne reconnaît plus le vide |
| Un script qui rejoue un lot d'import le fait passer « en cours » puis « échec » si le fichier stocké manque | appeler le format directement sur le fichier d'origine (empreinte vérifiée), sans passer par `Executeur` |
| Écrire l'avancée d'un import sur `lots_import` depuis une autre connexion | bloque : la transaction de l'import verrouille la ligne (clés étrangères) — passer par le cache fichier |
| Fusionner une branche de module dans `main` sans les autres | le menu « perd » les écrans restés sur l'autre branche — fusionner chaque branche, ou fusionner `main` dans la branche en cours avant |
| MySQL retire l'index d'une clé étrangère quand un nouvel index commence par la même colonne | le `down()` doit reposer l'ancien index avant de retirer le nouveau (erreur 1553 sinon) |
| Livewire n'exécute pas un `<script>` qu'il insère en redessinant (un `@once` dans un panneau ouvert par clic) | inclure les ressources dès le premier affichage de l'écran ; garder le script contre la double inclusion |
| `php artisan optimize:clear` vide aussi le cache applicatif | sur un serveur, préférer `app:deployer` |
| `$this->unCalcul()` défini par `$x = function` dans Volt est une action publique | `protect(...)` pour une aide interne |
| Un `@if` autour d'un formulaire rend son ouverture aussi lente qu'une requête | le rendre replié et le déplier par `x-show` sur `$wire` ; `$wire.$set(prop, valeur, false)` ne va pas au serveur |
| `x-cloak` suppose une règle CSS compilée par Vite | écrire le repli initial côté serveur (`style="display:none"`) plutôt que dépendre d'une reconstruction des fichiers |
| Un lot déposé avant que la lecture immédiate n'existe n'a plus aucun moyen de démarrer | `SuiviDuTraitement::reveiller()` depuis l'écran qui l'affiche ; la prise du lot reste atomique |
| `Handler::render()` passe les callbacks avant `AuthenticationException` | toute page de panne doit exclure explicitement authentification et validation |

### Où regarder

| Sujet | Fichier |
|---|---|
| Menu de chaque rôle | `Modules/Noyau/app/Commun/Services/MenuNavigation.php` |
| Atterrissage après connexion | `app/Http/Controllers/RedirectionController.php` |
| Périmètre visible | `Modules/Noyau/app/Entreprises/Support/PerimetreSites.php` |
| Règles du recouvrement | `Modules/Noyau/app/Exploitation/Services/Recouvrement.php` |
| Règles de l'état des impayés | `Modules/Noyau/app/Exploitation/Services/EtatDesImpayes.php` |
| Numérotation des pièces | `Modules/Noyau/app/Exploitation/Services/GenerateurNumero.php` |
| Code de saisie `A-C-KY-0007` | `Modules/Noyau/app/Commun/Services/CodeAuteur.php` |
| Formats d'import | `Modules/Noyau/app/Imports/Formats/` |
| Fichiers réels du client | `PLAN/MODULE-2/` |

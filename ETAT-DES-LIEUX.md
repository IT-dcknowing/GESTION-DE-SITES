# État des lieux du projet — le fil à reprendre

*Tenu à jour à la fin de chaque séance de travail. Dernière mise à jour : **23 septembre 2026** (7e passe).*

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
| Tests | `php artisan test` — 820 tests, 813 réussis, **0 échec** ; les 7 erreurs WebPush (courbe P-256 absente du poste) sont connues et sans conséquence |
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

`parc` → `devis` → `factures` (CATTC) → `impayes` → `fournisseurs` →
`balance-fournisseurs` → `reglements-fournisseurs` → `caisse` → `journal-caisse` →
`entrees` → `sorties`. Source : `Registre::DISPONIBLES`.

**Un seul format n'est pas un tableur** : `journal-caisse` lit un **PDF**, parce que le
logiciel comptable ne sort pas cet état autrement et que Bouaké et San-Pédro n'ont que lui.
Voir `LecteurPdf`.

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
| 17/09 | `aa6332a` | **main** | le propriétaire fusionne et pousse : `main`, `impayes` et `origin` au même point |
| 18/09 | `63c0b66` | **plan** | relevé des fichiers réels, plan de travail d'une semaine (PDF + classeur de suivi) et courrier à l'éditeur du logiciel (§ 6) ; `DocumentPdf` gagne l'en-tête à deux marques et les cellules qui reviennent à la ligne |
| 21/09 | `8b611d9` | **creances** | jour 1 du plan : `factures.depose_chez` prend la tête du tiers payant ; la créance déposée n'est comptée que chez le dépositaire ; « Porter à l'état » disparaît des factures réglées ; sept tests verrouillent la règle |
| 21/09 | `282538e` | **creances** | jour 2 : le filtre « du … au … » descend au jour (et s'ouvre enfin) ; suppression d'une créance réservée au gérant ; extrait de compte complété ; fournisseurs emportables avec leur déjà payé |
| 21/09 | `ea7a480` | **creances** | jour 3 : écran *Caisse par véhicule* (fiche, caisse, factures, notes) ; « Autres » détaillé et bouton *Détail* en trésorerie ; les cinq écrans d'argent ouverts au comptable |
| 21/09 | `b8ac87c` | **creances** | trois constats de l'écran : l'état des impayés se filtre « du … au … » (sur le dépôt, sinon l'édition), la colonne des boutons se colle au bord droit, une carte en grille peut enfin se rétrécir |
| 21/09 | `a924584` | **creances** | jour 4 : la prospection dit quel véhicule elle vise ; rapprochement prospection / devis par fiche, plaque ou nom, dans une fenêtre réglable ; écran de confirmation, refus mémorisé |
| 21/09 | `4c0d120` | **creances** | jour 5 : le barème de commission devient une donnée (grilles, tranches, date d'effet) ; page du barème réservée au gérant, avec essai ; colonnes *Barème*, *Commission* et *Cumul* sur l'écran Commerciaux |
| 21/09 | `110cee2` | **creances** | la grille dit elle-même quels rôles elle rémunère (plus aucune règle de rémunération dans le code) ; second maillon *devis → facture*, qui porte le commercial jusqu'à l'assiette du barème |
| 21/09 | `16a60e2` | **creances** | correction : l'écran de rapprochement comparait tout à tout et bloquait le serveur (donc toute l'application) ; index par fiche, numéro, plaque et nom |
| 21/09 | `b0f010c` | **creances** | jour 6 : la balance et les règlements fournisseurs exportés du logiciel comptable entrent (deux formats, deux tables, migration `2026_09_21_000006`) |
| 22/09 | `5698290` | **creances** | retours du propriétaire : le n° de devis exigé au passage en devis ; barème refait et cloisonné par exercice ; balance et règlements ont leur page, avec l'écart entre solde annoncé et recalcul |
| 23/09 | `2156dfc` | **creances** | vitesse : les jours ne se comptent plus par Carbon, et les consolidations ne construisent plus d'objets (« Clients & tiers » 4,8 s → 0,7 s) ; « Où vous joindre » demande son numéro à qui n'en a pas ; section **Code-import** sur l'écran des codes — un code se déclare à la main, s'aligne dans un tableau et sert aux imports comme n'importe quel autre ; le suivi fournisseur entre en entier — feuille `DETAIL`, 40 colonnes, deux classeurs fondus sans doublon |
| 23/09 | `5bb665e` | **creances** | seconde passe de vitesse : les créances ouvertes se lisent aussi sans objets — synthèse 1,5 s → 0,66 s, balance âgée 1,15 s → 0,59 s, tableau de bord 2,2 s → 1,64 s ; un test confronte les deux lectures |
| 24/09 | voir `git log` | **creances** | le **journal de caisse imprimé** entre : lecteur de PDF, format `journal-caisse`, 533 mouvements à Bouaké et 571 à San-Pédro, zéro écart sur la chaîne des soldes ; l'écran *Caisse* refait sur les colonnes du fichier (n° de pièce, motif, remettant/bénéficiaire, solde progressif, nom de la caisse, solde avant période) ; le classeur d'Abidjan livre enfin son « SOLDE D'OUVERTURE » |
| 24/09 | voir `git log` | **creances** | le **suivi fournisseur se tient par année**, comme l'état des impayés : report automatique de ce qui n'est pas soldé, colonne *Report*, page de détail par pièce (`/fournisseurs/piece/{id}`), saisie à la main d'une facture reçue entre deux dépôts |
| 24/09 | voir `git log` | **creances** | la feuille **« Liste fournisseurs »** devient un référentiel : terme de règlement, TVA, plafond d'encours, lus au même dépôt que les factures ; l'échéance attendue apparaît là où le fichier n'en donne pas (5 184 pièces sur 7 350), sans jamais s'écrire ; page `/fournisseurs/referentiel`, qui nomme aussi les 45 fournisseurs facturés absents de la liste |
| 24/09 | voir `git log` | **creances** | **les colonnes d'une page listent d'abord celles du fichier** : les entrées et sorties reçoivent les quatre colonnes qui n'avaient nulle part où aller (travaux, propriétaire, déposant, **date de livraison prévue — 147 sur 147**), et l'indicateur « promesse de sortie dépassée » devient calculable — **60 véhicules** ; les pages de détail lisent désormais `colonnes()` du format, ce qui rend sept colonnes fournisseur oubliées, dont « TVA 2 » |
| 24/09 | voir `git log` | **creances** | le **n° de fiche de réception** devient la clé qui relie les états : la fiche du parc montre son devis, sa facture et ses mouvements, et le numéro s'ouvre d'un clic depuis les devis, le chiffre d'affaires et les entrées/sorties. Le rapprochement se fait par égalité — mesuré identique à une forme normalisée, donc aucune colonne de plus |
| 24/09 | voir `git log` | **creances** | un fichier **écarté le dit et dit pourquoi** : troisième catégorie `Registre::ECARTES`, les fiches de réception y figurent avec la raison de la décision du 18/09 ; trois types d'import retrouvent leur compteur et deux commentaires périmés sont corrigés |
| 24/09 | voir `git log` | **creances** | **retours du propriétaire, en sept lots** : pagination en français et sans saut de page, « Caisse par véhicule » hors du menu, journal des modifications lisible, référentiel fournisseur corrigeable avec sa trace, motif obligatoire sur tout règlement, **le barème court jusqu'à ce qu'un autre le remplace**, détail d'une facture depuis le chiffre d'affaires, rapprochement coché et paginé, bornes de date sur les clients / le rapprochement / les impayés, et le classeur du plan retrouve sa mise en forme |
| 23/09 | voir `git diff` | **SuperAdmin / Noyau** | un **commercial peut être rattaché facultativement à un site précis** de sa ville, notamment Abidjan ; le formulaire création/modification propose les sites quand la ville en compte plusieurs, et le serveur vérifie l'appartenance du site à la ville et à l'entreprise |

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
- **23/09 — hors impayés** : le formulaire des accès Super Admin permet désormais de rattacher
  facultativement un commercial à un site précis de sa ville ; le choix vide conserve le
  périmètre de la ville entière. Validation serveur ajoutée dans `ChoixDeVille`.

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

## 6. Le plan de la semaine — onze chantiers pour boucler L'Artisan

**Trois documents produits le 18/09**, hors du dépôt, dans `C:\BUREAU\GESTION-DE-SITES\` :

- `PLAN-DE-TRAVAIL-ARTISAN-2026-09-18.pdf` (7 pages) — ce qui est en service, les onze
  chantiers, les fichiers tenus à la main, l'inventaire du dossier IMPORT, le barème et le
  déroulé de la semaine.
- `ARTISAN-PLAN-RESTANT/plan-a-jour.xlsx` (nommé `PLAN-DE-TRAVAIL-ARTISAN-2026-09-18.xlsx`
  jusqu'au 24/09, renommé à la demande du propriétaire ; sa mise en forme vient du gabarit
  `modele-mise-en-forme.xlsx` posé à côté de lui) — le même plan pour le suivi : un chantier par
  ligne, groupés par module avec une ligne vide entre deux modules, colonne **Statut** à liste
  déroulante (À faire · En cours · Bloqué · À valider · Terminé · Abandonné) et colonnes
  **Début** / **Fin** au format date.
- `COURRIER-M-FOFANA-2026-09-18.pdf` (2 pages) — à l'en-tête du cabinet **DC-KNOWING** et de
  L'Artisan, signé AGNIMEL : les états qui manquent en un tableau (module, état, ce qui manque,
  notre demande) et les API en un second, champ par champ.

Les deux PDF sont fabriqués par `Modules/Noyau/app/Commun/Services/DocumentPdf.php` et le
classeur par la même mécanique OOXML que `Exportateur` — aucune dépendance ajoutée. Les scripts
qui les produisent vivent dans le dossier scratchpad de la séance et ne sont pas versionnés ;
le logo du cabinet est déposé à côté des documents (`logo-dc-knowing.png`).

**Deux ajouts au générateur PDF** (`DocumentPdf`), tous deux facultatifs, rien de changé pour
les documents existants : `enTeteADeuxMarques()` — un logo à chaque extrémité, le titre au
milieu, le destinataire sous le cadre — et l'option `multiligne` de `tableau()`, qui replie une
cellule au lieu de la tronquer. Sans elle, une colonne qui porte une phrase perdait justement
ce qu'on voulait dire.

| # | Chantier | Ce qu'il faut faire |
|---|---|---|
| 1 | **Le payeur** | ✅ **Fait le 21/09.** La règle devient déposant → courtier → assureur → client, écrite une seule fois (`Facture::tiersPayant()`) et confrontée à sa traduction SQL (`Recouvrement::EXPRESSION_TIERS_PAYANT`) par un test |
| 2 | **Facture déposée chez un tiers** | ✅ **Fait le 21/09.** `factures.depose_chez` (migration additive `2026_09_21_000001`), saisie dans l'état, dans « Porter » et dans le bloc facture du recouvrement, lue dans le tableau de l'état, trouvée par la recherche ; balance âgée, relances, extrait et annuaire la comptent chez le seul dépositaire |
| 2 bis | **Une facture réglée n'a rien à faire dans le recouvrement** | ✅ **Fait le 21/09.** « Porter à l'état » laisse la place à « Réglée » quand il ne reste rien ; la mention « Soldée » devient une pastille. Le registre garde ses créances soldées — voir l'encadré ci-dessous |
| 3 | **Extrait de compte** | ✅ **Fait le 21/09.** Date de facturation (qui dit enfin laquelle), date de dépôt, n° de sinistre, et le tiers « pour le compte de » — à l'écran **et** dans le fichier emporté, qui n'en avait aucune |
| 4 | **Prospection → devis → facture** | ✅ **Fait les 21 et 22/09, les deux maillons.** **Tranché autrement le 22/09** : quand le commercial coche « devis après passage », il tient le devis — le **n° du devis devient obligatoire à cet instant** (`prospections.n_devis`, migration `2026_09_22_000001`), et le rapprochement n'a plus rien à deviner. Le numéro du devis plutôt que celui de la fiche, parce qu'il désigne **un** devis quand une fiche peut en porter plusieurs ; le champ accepte néanmoins un n° de fiche, pour ne pas bloquer qui n'a que lui. La piste « devis déclaré » passe en tête du rapprochement, devant la fiche, la plaque et le nom. La prospection porte `immatriculation` et `n_fiche_reception`, tous deux facultatifs (migration additive `2026_09_21_000003`). `RapprochementProspectionDevis` propose trois pistes, de la plus sûre à la plus faible — même fiche, même plaque (lue sur la fiche du devis), même client — dans une fenêtre réglable (15 jours par défaut, jamais vers le passé). Écran `/rapprochement-prospections-devis` : on confirme ou l'on écarte, ligne par ligne ; le refus se garde avec son auteur (`rapprochements_ecartes`). Confirmer porte le devis au commercial de la prospection. Fermé au commercial lui-même. **Second maillon ajouté le soir** : `RapprochementDevisFacture` relie la facture à son devis (par la fiche que `reference_devis` contient réellement, par le numéro du devis, ou par la plaque) et lui porte son commercial — c'est ce geste qui fait exister la commission. Second volet du même écran, table `ecarts_devis_facture` (migration `2026_09_21_000005`) |
| 5 | **Deux gestes** | ✅ **Fait le 21/09.** `SuppressionDUneCreance` : gérant seul, rien de réglé, rien d'importé, confirmation sur la ligne, ligne entière au journal. Filtre « du … au … » au jour sur les douze écrans — **et réparé** : voir l'encadré |
| 6 | **N° de fiche de réception** | ✅ **Fait le 24/09** — voir « Le n° de fiche de réception, clé de rapprochement » plus bas. Relevé corrigé : présent dans le parc, les entrées, les sorties, les fiches, les devis et les factures ; **absent chez les fournisseurs et absent de la caisse** — le relevé du 18/09 l'annonçait « dans le libellé pour la caisse », or aucun des 1 155 libellés ni aucun des motifs n'en porte. Depuis le 21/09 il est aussi **saisissable sur la prospection**, où il manquait ; c'est le seul endroit où il servait de clé et n'existait pas |
| 7 | **Caisse par véhicule** | ✅ **Fait le 21/09.** Page `/caisse/vehicule` : la plaque ramène sa fiche de réception, ses mouvements de caisse, ses factures avec leur reste à payer, et ses notes. Table `notes_vehicule` (migration `2026_09_21_000002`) : les notes s'empilent, chacune avec son auteur |
| 8 | **Trésorerie** | ✅ **Fait le 21/09.** Bouton *Détail* sur chaque encaissement et décaissement (référence, origine — saisie avec son code auteur ou fichier importé —, atelier, facture réglée) ; bloc « Ce que “Autres” recouvre », poste par poste. `PeutVenirDUnImport` gagne la relation `lot()`, qui manquait |
| 9 | **Fournisseurs** | ✅ **Fait le 21/09.** « Déjà payé » et sa part de l'engagé dans le tableau de tête ; export créé (`fournisseurs.telecharger`) — l'écran était le seul tableau sans aucun téléchargement |
| 10 | **Commerciaux** | ✅ **Fait les 21 et 22/09.** **Page refaite le 22/09 sur la maquette du document** : deux sections (commerciaux / responsable commercial et adjoint), chacune son tableau *Tranche CA – Taux – Commission estimée*, son bouton **Enregistrer**, son bouton **+ Ajouter** (les champs s'ouvrent sous le tableau, on valide ou l'on annule) et son bouton **Notes** — les cinq phrases du document sont réparties entre les deux grilles, le seuil n'étant pas le même (25 M pour le responsable, 20 M pour le commercial). **Cloisonné par exercice** et non plus par date d'effet : une grille corrigée vaut aussitôt pour tout son exercice, les mois déjà passés compris. La liste des grilles, la construction manuelle et le bouton « poser la grille du document » ont été retirés ; à défaut d'enregistrement, la grille de référence s'affiche directement, prête à être corrigée. Un bouton **Barème de commission** est posé sur la ligne des filtres de l'écran *Commerciaux*, pour le gérant seul. **Rien n'est écrit en dur** : ni les taux, ni les tranches, ni l'assiette, ni **les rôles que chaque grille rémunère** — la règle « le responsable commercial a sa grille » était en PHP jusqu'au soir du 21/09, elle est désormais cochée à l'écran (`baremes_commission.roles`, migration `2026_09_21_000005`). Un taux modifié agit à l'affichage suivant : aucun cache, aucun déploiement. Le barème est une **donnée** : tables `baremes_commission` et `tranches_bareme` (migration additive `2026_09_21_000004`), avec date d'effet — une grille posée ne réécrit jamais les mois qu'une autre a couverts. Page `/parametres/bareme-commission`, gérant seul : grilles, tranches modifiables, anomalies dites en clair, essai d'un chiffre d'affaires. Sur l'écran *Commerciaux*, colonnes **Barème**, **Commission de la période** et **Cumul de l'année**, visibles du gérant seul, calculées **mois par mois** |
| 11 | **Comptabilité** | ✅ **Fait le 21/09.** Le recouvrement lui était déjà ouvert en consultation ; s'y ajoutent Caisse, Caisse par véhicule, Trésorerie, Charges et Fournisseurs, en lecture et dans son périmètre. Le parc, les clients, le chiffre d'affaires et l'état des impayés restent fermés — deux tests le vérifient |

### La question posée : les factures réglées sont-elles dans l'état des impayés ?

**Oui, elles y sont — et il faut qu'elles y restent.** L'état des impayés est un registre annuel,
comme le classeur dont il est né : il porte le facturé, l'encaissé et le reste, et le reste n'y
est jamais une colonne saisie — il se recalcule depuis les encaissements (`Recouvrement::reste()`,
plancher à zéro). Les retirer ferait perdre les totaux et le rapprochement avec le chiffre
d'affaires.

Ce qui est **déjà en place** : `statutFiltre` vaut `'ouvertes'` par défaut, donc à l'ouverture de
l'écran on ne voit que les créances non soldées ; une soldée porte la mention « Soldée » en vert
et son reste tombe à zéro ; `Recouvrement::niveau()` rend le niveau 0 « Soldée », et la balance
âgée comme l'extrait de compte les écartent (`SEUIL_SOLDE`).

Ce qui **manquait vraiment**, corrigé le 21/09 : sur l'écran du chiffre d'affaires, le bouton
« Porter à l'état » était offert dès que `exercice_impayes` était nul, **même pour une facture
entièrement réglée**. Il n'apparaît plus que s'il subsiste un reste, et affiche « Réglée » sinon —
l'encaissé est additionné en base pour le savoir (`withSum`). La mention « Soldée » est devenue une
pastille verte, aussi visible qu'une pastille de relance quand le filtre est sur « Toutes ».

**Reste à faire sur ce point** : rien. Le registre garde ses soldées, et c'est voulu.

### Le journal de caisse imprimé — fait le 24 septembre

**Le problème, posé le 21/09 et arbitré le même jour** : l'écran *Caisse* n'était alimenté
que par le classeur Excel tenu à la main d'Abidjan. Bouaké et San-Pédro n'ont pas de
classeur : elles n'ont qu'un **journal de caisse en PDF**, que rien ne lisait. Deux villes
sur trois n'avaient donc pas de caisse dans l'application.

**Il a fallu écrire un lecteur de PDF**, et c'est assumé : le logiciel comptable ne sort pas
cet état autrement. `Modules/Noyau/app/Imports/Lecteurs/LecteurPdf.php` n'exécute rien — ni
script, ni police, ni action — il ne lit que des positions et des caractères.

**Ce qu'on y a appris, mesuré fichier en main.**

- **Un PDF ne contient ni lignes ni colonnes**, seulement des morceaux de texte avec leur
  position. On reconstitue les rangées en regroupant ce qui partage une hauteur, et les
  colonnes en cherchant la rangée d'intitulés — la première dont toutes les cellules sont
  des mots en capitales sans un chiffre. Un titre isolé n'en compte qu'une, la ligne de la
  période porte des dates, celle du solde d'ouverture porte un montant : seule la bonne
  satisfait les trois conditions.
- **Les nombres sont alignés à droite.** « 1 » commence trente-trois points plus loin que
  « 270 000 » dans la même colonne. Sans le jeu laissé au bord gauche de chaque colonne, un
  petit montant bascule dans la colonne voisine — une entrée devient une sortie, en silence.
- **Les deux fichiers ne sont pas écrits de la même façon.** San-Pédro pose son texte avec
  `Td` et des chaînes littérales ; Bouaké avec `Tm`, dans une police à index dont il faut la
  table `ToUnicode`, et en renversant d'abord l'axe vertical de la page. Les deux écritures
  sont lues, et un test vérifie qu'elles donnent le même journal.
- **Le défaut qui a failli passer** : on découpait le flux du PDF sur les lettres `BT`…`ET`.
  Or « INTERNET » et « REMETTANT » contiennent `ET`. Toutes les lignes portant ces mots
  étaient coupées et perdues — chez San-Pédro, qui écrit en clair. Le lecteur suit désormais
  les opérateurs un à un, comme il faut.
- **L'ancre d'un mouvement est la rangée qui porte sa date et son montant**, jamais la phrase
  qui le décrit. C'est ce choix qui a rendu le défaut ci-dessus réparable : les mouvements
  sont entrés avec leur montant juste et le solde d'accord, seuls leurs numéros manquaient.
  Accrochés à leur phrase, ils auraient disparu sans laisser de trace.
- **Une annulation reprend le numéro de la pièce qu'elle annule** et porte un montant
  négatif dans sa colonne d'origine. Une entrée de −1 est enregistrée comme une sortie de 1 :
  le montant reste positif, comme partout dans cette table, et les deux lignes cessent de
  s'écraser.
- **Le numéro de pièce ne suffit pas comme clé.** Deux couples de mouvements bien distincts
  partagent un numéro chez San-Pédro (pièces 002410 et 003468) : deux sorties du même
  montant, le même jour, à deux bénéficiaires et pour deux motifs différents. La clé est donc
  la ligne entière — caisse, pièce, date, sens, montant, motif, tiers, libellé — mais **sans**
  le solde ni la page, qui dépendent du tirage et non du mouvement.

**La preuve que la lecture est juste, et elle est dans le document.** Chaque ligne affiche le
solde de la caisse après elle. En partant du solde annoncé avant la période et en appliquant
nos montants un par un, on doit retrouver chacun d'eux. Mesuré : **zéro écart sur 533
mouvements à Bouaké, zéro sur 571 à San-Pédro**. Un second dépôt du même fichier ne recrée
rien : 533 et 571 lignes inchangées.

**L'écran *Caisse* liste désormais les colonnes du fichier**, comme la règle l'exige : n° de
pièce, motif, remettant ou bénéficiaire sortis du libellé, solde progressif, nom de la caisse
et solde avant la période. Une colonne que la source ne porte pas ne s'affiche pas — le
classeur d'Abidjan n'a ni numéro ni motif, et lui réserver deux colonnes de tirets
laisserait croire à une saisie manquante. Le bloc « Où part l'argent » groupe par **motif**
là où il y en a un : grouper le détail libre donnerait quatre cent soixante-huit postes d'une
ligne.

**Le solde avant la période ne se devine pas.** Il part de ce que la source annonce — « SOLDE
AVANT LA PERIODE : 31 260 » sur le journal, « SOLDE D'OUVERTURE » en quatrième ligne de
chaque onglet du classeur — auquel s'ajoutent les mouvements survenus entre cette annonce et
le premier jour regardé. Sans annonce, la page le reconstitue **et le dit** : la caisse vivait
avant le premier fichier déposé.

**Le classeur d'Abidjan livre enfin son solde d'ouverture**, qu'on passait depuis le 8/09 :
décembre ouvre à 662 700, janvier à 293 700, février à **0** et mars à 263 100. Février n'est
donc pas la suite de janvier — c'est une information, pas une erreur de lecture.

Migration additive `2026_09_23_000001` : cinq colonnes nullables sur `mouvements_caisse`
(`numero_piece`, `type_piece`, `motif`, `role_tiers`, `caisse`), la `page` du document,
`libelle` élargi de 255 à 500, et la table neuve `ouvertures_caisse`. Tests :
`LeJournalDeCaisseEntreTest` (19). `Classeur` accepte le PDF, reconnu à sa signature `%PDF-`
et jamais à son extension.

**Reste sur ce chantier** : rien pour la caisse. Le classeur d'Abidjan et le journal des deux
autres villes sont tous deux lus, et l'écran montre ce qu'ils portent.

### Les fichiers tenus à la main, à reprendre comme les impayés

- **Suivi fournisseur, repris comme les impayés** : ✅ **Fait le 24/09.** L'écran listait
  toutes les pièces depuis 2023 dans une seule suite ; on ne peut pas arrêter un exercice sur
  une liste sans fin. Il se tient désormais par année, avec la règle de l'état des impayés :
  l'année choisie montre ce qui y a été facturé **plus ce qui traîne depuis avant**.

  **La reconduction ne recopie rien et ne déplace rien.** Une pièce garde l'année de sa
  facture, pour toujours. Recopier la ligne compterait la dette deux fois dans un total ;
  la déplacer viderait l'état de l'année passée. Le report se défait de lui-même le jour où
  la pièce est réglée — aucune bascule à lancer au 1er janvier, aucune tâche à surveiller.

  **L'année est celle de la facture, et non une colonne de plus** : les impayés ont besoin
  d'un marqueur propre parce qu'une créance peut être *portée* à l'état d'une autre année ;
  une facture fournisseur, non. Mesuré sur les 1 848 pièces reprises en local : aucune n'est
  sans date de facture. Vérifié en base (MySQL) : état 2026 → 51 pièces, toutes reportées,
  61 797 581 F dus ; état 2025 → 1 566 pièces dont 14 reportées de 2024 ; et le reste de
  l'état 2026 retombe **exactement** sur la somme de toutes les pièces encore ouvertes.

  **Trois ajouts à l'écran** : une colonne *Report* (« Reporté 2025 », déduite et jamais
  stockée), un bouton **Détail** par ligne — `/fournisseurs/piece/{id}`, où les quarante
  colonnes du fichier se lisent enfin, la refacturation et les commentaires compris —, et un
  bouton **+ Ajouter une pièce** pour une facture reçue entre deux dépôts.

  **La saisie ouvre une porte, et elle est gardée.** L'écran était en lecture seule, et
  c'est à ce titre qu'il avait été ouvert au comptable ; le responsable d'atelier continue
  de lire mais n'écrit pas (`EtatDesFournisseurs::peutEcrire()`, vérifié à la route **et**
  dans l'action). Le doublon est refusé à la frappe sur la clé de l'import — fournisseur,
  numéro, date, montant. Une ligne venue d'un fichier garde ces quatre champs verrouillés :
  les retoucher ferait qu'un prochain dépôt ne reconnaîtrait plus la ligne et la recréerait,
  et la dette compterait double. Le reste à payer ne se saisit jamais : il se déduit du
  montant et du réglé. Migration additive `2026_09_23_000002` (`factures_fournisseurs.user_id`).
  Tests : `LeSuiviFournisseurSeTientParAnneeTest` (18).

- **Suivi fournisseur, l'import** : ✅ **Fait le 23/09.** Les deux classeurs ont été redéposés sur le
  poste et s'ouvrent. Ce qu'ils ont appris, et qui démentait ce qu'on avait conclu d'un
  échantillon :

  - la feuille exploitable est **`DETAIL`**, pas « contrôle chq ». Son en-tête n'est
    simplement pas en première ligne — ligne 10 à Abidjan, ligne 9 à San Pedro, sous les
    règles d'usage du classeur et une ligne de totaux ;
  - **les deux classeurs n'ont pas les mêmes colonnes** : 39 à Abidjan, 31 à San Pedro.
    Onze n'existent qu'au premier (refacturation, quantités, marge), trois qu'au second
    (montant HT, n° FEB, arrivé à échéance). Un seul format lit l'union des deux ; une
    colonne absente reste **vide**, et non à zéro ;
  - **ils se recouvrent** : 10 147 lignes en tout, 7 397 distinctes — 2 670 lignes
    figurent dans les deux. Les importer l'un après l'autre sans clé compterait la dette
    une fois et demie ;
  - la clé valait « fournisseur + n° de pièce », et **écrasait 317 lignes réelles** : une
    facture ventilée par section, un avoir qui reprend le numéro de la pièce qu'il annule
    (le cas SOCIDA 4138005). Elle vaut désormais fournisseur + pièce + date + montant ;
  - mille lignes de formules traînent après la dernière facture. Elles remontaient au
    journal des rejets et noyaient les vrais : une ligne qui ne dit ni de qui, ni quelle
    pièce, ni combien, ni quand, n'est plus comptée du tout.

  Essai en transaction annulée sur les deux fichiers réels : **7 350 lignes**, dont 2 675
  reconnues comme déjà présentes, 108 rejets nommés, **958 409 076 F** restant dus.
  `date_echeance` passe de **zéro à 1 480 lignes renseignées** — l'écran *Fournisseurs*
  affiche donc enfin l'échu, en disant sur combien de pièces l'échéance est connue.

  Migration `2026_09_22_000003` : 24 colonnes ajoutées, `montant_refacture` et `marge`
  élargis au vide, `mode_reglement` porté à 200 caractères (le classeur y inscrit deux
  chèques), clé unique élargie.

- **Référentiel fournisseurs (feuille « Liste fournisseurs »)** : ✅ **Fait le 24/09.** C'était
  le reste à faire du chantier précédent, et il valait plus cher qu'annoncé. La colonne
  *Échéance* de l'écran *Fournisseurs* était une colonne de tirets : mesuré en local, **aucune**
  des 1 848 pièces ne portait de date d'échéance ni de délai — la colonne « délais de règlement »
  n'existe que dans le classeur de San-Pédro, et ses lignes ne la remplissent pas. Le classeur
  savait pourtant répondre : il le disait un onglet plus loin, fournisseur par fournisseur.

  - **Lue au même dépôt que les factures**, et reconnue à son contenu (l'intitulé « Type de
    règlement », qui n'existe que là) et non à son nom d'onglet. Demander de redéposer le même
    fichier en changeant de format dans une liste aurait garanti qu'on ne le ferait qu'une fois.
  - **287 fiches** pour les deux classeurs réunis — 282 à Abidjan, 216 à San-Pédro, 211 communes.
    Sur ces 211, les deux feuilles ne se contredisent que **huit fois**, dont sept où l'une des
    deux ne dit simplement rien : une valeur vide n'efface donc jamais une valeur déclarée.
  - **Ce qui est lu** : le terme de règlement (195 fiches, quatre formes — « Comptant »,
    « 30 jours », « 45 jours », « 30 jours fin de mois »), la TVA en **trois** états (67 oui,
    186 non, 34 non renseignées — dire « non » à la place du fichier serait une affirmation
    fiscale), et le plafond d'encours de six fournisseurs, recopié à la lettre : l'un l'écrit
    « 10 00 000 », et deviner s'il s'agit d'un million ou de dix effacerait la faute.
  - **L'échéance attendue**, enfin. Sur les 7 350 pièces des deux classeurs, **7 171** ont une
    fiche et **5 184** gagnent une date qu'aucune ligne ne portait. Elle est comptée à
    l'affichage et **ne s'écrit jamais** dans `date_echeance` : rangée là, elle deviendrait
    indiscernable d'une échéance réelle et servirait à relancer un fournisseur sur un délai
    qu'il n'a jamais écrit. Le filtre *Échues* et le KPI de l'échu continuent donc de ne
    regarder que les dates déclarées ; l'écran, lui, l'affiche en gris sous la mention
    « attendue », et l'export la marque « (attendue) » dans la cellule.
  - **Aucun rapprochement approché.** Le nom normalisé est la seule clé — 287 noms donnent
    287 clés distinctes, et 99 des 100 fournisseurs en base sont reconnus. « CFAO BABI » et
    « CFAO BABI MOTORS » se ressemblent ; se ressembler n'autorise pas à attribuer un délai de
    paiement. Les **45 fournisseurs facturés sans fiche (179 pièces)** sont donc *nommés* sur la
    page du référentiel, pour que le classeur soit corrigé là où il est tenu.

  Page `/fournisseurs/referentiel`, ouverte depuis l'écran *Fournisseurs* comme la balance et
  les règlements. Migration additive `2026_09_24_000001` (table `referentiel_fournisseurs`).
  Tests : `LeReferentielFournisseurEntreTest` (25).
- **Caisse** : ✅ **Fait le 24/09.** Les deux sources sont lues — le classeur tenu à la main
  d'Abidjan et le journal imprimé de Bouaké et San-Pédro — et l'écran liste les colonnes des
  fichiers. Règle appliquée : **les colonnes d'une page listent d'abord celles du fichier
  d'origine**. Détail dans la section « Le journal de caisse imprimé » ci-dessus.

  **Question posée le 21/09 — les deux sources sont-elles prises en compte ? Non, une seule.**
  L'écran *Caisse* est alimenté par le **classeur Excel tenu à la main**, un onglet par mois,
  lu par `FormatDeLaCaisse`. Le **journal de caisse en PDF** (sortie du logiciel comptable :
  *PÉRIODE du … au …*, *CAISSE : CAISSE BOUAKÉ*, *SOLDE AVANT LA PÉRIODE*, puis DATE /
  LIBELLÉ DE LA TRANSACTION / ENTRÉE / SORTIE / SOLDE) **n'est lu par rien** — aucun format
  d'import ne lit de PDF, et celui-ci ne porte ni immatriculation ni colonne bénéficiaire :
  le n° de pièce, le motif, le remettant et le bénéficiaire y sont **empilés dans le
  libellé**. Les deux décrivent la même caisse par deux bouts différents.

  **Arbitré par le propriétaire le 21/09, fait le 24/09** : en alignant l'écran sur le
  journal, **le sens (entrée / sortie) reste une colonne à part** et ne redevient pas un
  montant signé, **la ville reste**, **le montant reste**. Les six manques nommés — n° de
  pièce, motif, remettant ou bénéficiaire sortis du libellé, solde progressif, nom de la
  caisse, solde avant période — sont tous comblés, et le journal PDF lui-même est importé.
  Voir la section « Le journal de caisse imprimé » ci-dessus.
- **Fiches de réception** : pas d'import propre à écrire — la situation du parc porte les mêmes
  fiches avec plus de colonnes ; seul le code client lui manque. **Décidé le 18/09 : on n'attend
  pas le code client.** L'obtenir suppose que l'éditeur l'ajoute ou ouvre ses API ; d'ici là le
  rapprochement se fait par le nom ramené à une forme comparable et, pour un véhicule, par
  l'immatriculation. Le code client se posera par-dessus le jour venu, sans rien refaire.
- **Balance et règlements fournisseurs** (exports du logiciel) : ✅ **Faits les 21 et 22/09.**
  Deux pages ajoutées le 22/09, ouvertes depuis l'écran *Fournisseurs* : `/fournisseurs/balance`
  et `/fournisseurs/reglements`.

  **La balance confronte le solde annoncé à son recalcul**, comme demandé — « on garde le
  total estimé du logiciel et on compare après calcul, comme le fait la caisse avec son
  KPI ». Le fichier garde son solde, la page affiche à côté crédit − débit et l'écart, avec
  un KPI « comptes en écart » et un filtre qui les isole. Rien n'est écrasé.

  **Les règlements n'ont pas d'année** : l'export est global, et la page le dit. Les deux
  bornes de date ne servent qu'à celui qui veut réduire lui-même ce qu'il regarde. La
  remarque du 21/09 sur les lignes datées d'octobre tombe donc d'elle-même — il n'y a pas de
  période à respecter.
  `FormatDeLaBalanceFournisseur` (FOURNISSEURS, DEBIT, CREDIT, SOLDE) et
  `FormatDesReglementsFournisseurs` (DATE REGLEMENT, CODE REGLEMENT, FOURNISSEURS, MODE
  REGLEMENT, MONTANT CFA), déclarés au registre, avec leurs deux tables
  (`soldes_fournisseur`, `reglements_fournisseur`, migration additive `2026_09_21_000006`).
  Passés en simulation sur les fichiers réels : **118 lignes de balance** et **293
  règlements**, aucun rejet.

  Trois décisions y sont écrites, et tenues par des tests. Le **solde est recopié, jamais
  recalculé** : débit moins crédit ne redonne pas toujours la colonne du logiciel, et cet
  écart est une information comptable. La balance est **une photographie** : la redéposer met
  à jour le solde d'un fournisseur au lieu d'en ajouter un second. Et le **code de règlement
  est la clé** des paiements — le fichier contient deux virements du même jour, au même
  fournisseur, pour le même montant, que seul leur code distingue.

  Sur le format du fichier : **la balance est déjà fournie en tableur** (`.xlsx`), et c'est
  ce fichier-là qui est lu — 118 lignes, aucun rejet. Il n'y a donc rien à attendre de plus
  de ce côté ; c'est le **journal de caisse** qui reste en PDF, et lui seul.

### La panne qu'on a causée, et corrigée le jour même : l'application figée

**Le symptôme, signalé par le propriétaire le 21/09** : « les pages sont devenues très
lentes, rien ne passe, aucun clic ne passe, la page charge seulement, tous les boutons sont
identiques ». Mesuré : la page de connexion mettait **148 secondes** à répondre.

**La cause** : l'écran de rapprochement confrontait *chaque* facture à *chaque* devis — deux
mille factures contre deux mille six cents devis, soit cinq millions de tours de boucle
portant chacun plusieurs expressions régulières, recalculées à chaque tour. Le premier volet
faisait de même avec les prospections.

**Pourquoi toute l'application semblait morte, et pas seulement cet écran** : le serveur de
développement (`php artisan serve`) ne traite **qu'une requête à la fois**. Pendant qu'il
moulinait, toutes les autres pages, et jusqu'aux feuilles de style et au JavaScript,
attendaient leur tour — d'où les boutons sans style et les clics sans effet.

**La correction** : chaque forme comparable n'est calculée qu'une fois par devis, et rangée
dans un index (par fiche, par numéro, par plaque, par nom). Retrouver les candidats d'une
facture est devenu une lecture de table. Mesuré sur les données locales, après : **190 ms**
pour le premier volet, **884 ms** pour le second, contre plus de deux minutes. L'onglet qu'on
ne regarde pas n'est plus calculé du tout.

**Le garde-fou** : un test vérifie que le nombre de requêtes ne change pas quand la base
passe de deux lignes à cent. Il ne mesure pas un temps — qui varierait d'une machine à
l'autre — mais la propriété qu'on avait perdue : le travail ne doit pas croître avec le
volume.

### La seconde passe de vitesse, du 23 septembre

Le propriétaire signale que les pages restent lentes, « et en particulier le recouvrement ».
Mesuré sur le serveur Apache local, OPcache actif, avec les 11 332 factures de la base :
*Clients & tiers* **4,8 s**, l'état initial des impayés 2,7 s, les devis 1,8 s. Le SQL n'y
comptait que pour trois cents millisecondes ; tout le reste était du PHP.

**Deux causes, toutes deux mesurées.**

1. **Compter des jours par Carbon.** `$depart->copy()->startOfDay()->diffInDays(...)` coûte
   240 µs — deux copies d'objet, deux remises à minuit et une soustraction de calendrier. Les
   quatre écrans qui lisent tout le portefeuille l'appelaient neuf mille fois chacun, soit
   **2 147 ms par affichage** à ne faire que soustraire des dates. Écrit en numéros de jour,
   le même calcul tient en **5 ms**. `Modules/Noyau/app/Commun/Services/NombreDeJours.php`
   porte la règle, et un test confronte chacun de ses résultats à ceux de l'ancien calcul sur
   quatre cents jours de suite.

2. **Construire neuf mille objets pour additionner des entiers.** L'annuaire des tiers et le
   tableau des courtiers parcourent tout le portefeuille sans afficher une seule facture ligne
   à ligne. Chaque lecture d'attribut Eloquent coûte 9 µs, et chaque lecture d'une colonne date
   en coûte **47** — Laravel reconstruit un Carbon **à chaque accès**, sans le retenir. Ces
   écrans lisent donc désormais les lignes telles que la base les rend
   (`Recouvrement::lignesDeCreance()`), huit colonnes au lieu de quarante-cinq.

   **Aucune règle n'a bougé** : le tiers payant reste `Facture::tiersPayantParmi()`, le reste
   à payer `Recouvrement::resteDe()`, le niveau `Recouvrement::niveauPourAge()` — les mêmes
   fonctions que celles qu'appelle le chemin objet, et les mêmes tests. Les noms restent
   comparés **à la casse près**, ce qu'un `GROUP BY` de MySQL aurait perdu : « NSIA
   ASSURANCES » et « NSIA Assurances » doivent continuer de se voir, c'est la raison d'être de
   cet écran.

**Puis la même technique aux créances ouvertes**, dans la foulée : `lignesOuvertes()`
sert désormais la synthèse, la balance âgée, la saisie, l'export et le tableau de bord.
`parTiers()` et `kpis()` lisent des lignes ; le tableau de bord retient la plus vieille
créance de chaque tiers **dans la passe qui les groupe déjà**, au lieu de la rechercher
tiers par tiers ; la page d'un dossier relit les factures du seul tiers demandé au lieu de
filtrer les 1 341.

**Résultat, mesuré au même endroit, avant → après** :

| Écran | Avant | Après |
|---|---|---|
| Recouvrement — Clients & tiers | 4,8 s | **0,65 s** |
| Recouvrement — Synthèse | 1,5 s | **0,66 s** |
| Recouvrement — Balance âgée | 1,15 s | **0,59 s** |
| Recouvrement — Courtiers | 1,5 s | **0,50 s** |
| Recouvrement — Tableau de bord | 2,2 s | **1,64 s** |

Le tableau de bord reste le plus lourd du module, et c'est attendu : il croise les créances,
les relances, les encaissements et les agents. Ce qui lui reste est réparti — 232 ms pour la
lecture des créances ouvertes, 82 ms pour les encaissements de la période (un `whereDate` qui
empêche l'index de servir), le reste en rendu de ses 129 lignes.

**Un test confronte les deux lectures** : `parTiers()` et `kpis()` doivent rendre exactement
la même chose qu'on leur passe des objets ou des lignes, et l'âge d'une ligne doit valoir
celui de sa facture. Sans lui, la balance âgée et l'export du même jour pourraient cesser de
dire le même encours, et l'écart ne se verrait qu'en rapprochant deux totaux à la main.

**Un défaut trouvé en chemin, sans rapport avec la vitesse** : `PortefeuilleDeRecouvrement`
retrouvait le tiers payant d'un règlement en lisant la facture **sans la colonne
`depose_chez`**, pourtant premier candidat de la règle. Le tableau de bord créditait donc
l'encaissement au courtier, ou au client, d'une facture déposée chez un tiers — sans qu'une
seule erreur ne s'affiche.

**Ce qui reste du côté de l'hébergement**, et qui pèse sur toutes les pages sans exception :
`SESSION_DRIVER` et `CACHE_STORE` sont sur `database`. Chaque page paie donc une lecture, une
écriture et un ménage de la table des sessions.

### La panne qu'on a trouvée en chemin : l'onglet « Période » ne s'ouvrait pas

`resources/views/components/filtre-periode.blade.php` lisait `$dateDebut` et `$dateFin` sans
les déclarer dans ses `@props`, et **aucun des douze écrans ne les lui passait**. Cliquer sur
l'onglet « Période » tombait donc sur `Undefined variable $dateDebut`. Aucun test ne l'avait
vu : tous ouvraient les écrans en mode « Calendrier ». C'est ce qui se cachait derrière la
demande d'un filtre par intervalle — il existait dans le calcul (`PeriodeCalculateur`), il ne
s'affichait pas.

Corrigé, et étendu : les bornes sont déclarées, passées par les douze écrans, et lues **au
jour** (`Y-m-d`). Les valeurs écrites au mois (`Y-m`) restent comprises et ouvertes au mois
entier, pour que les liens mis en favori continuent de fonctionner.

### Les colonnes d'une page listent d'abord celles du fichier

✅ **Fait le 24/09.** La règle était écrite depuis le 18/09 et appliquée écran par écran, de
mémoire. Le relevé automatique du 24/09 — comparer `colonnes()` de chaque format à ce que les
pages nomment — dit ce que vaut la mémoire.

**Ce qui tombait au dépôt.** Les fichiers d'entrées et de sorties portent onze colonnes ;
**quatre n'avaient aucune colonne en base**. L'import ne les perdait pas tout à fait : il en
faisait une phrase, recopiée dans `observations` — « REVISION COMPLETE · Propriétaire :
LUSEO CI · Déposant : MR BETAKO · Livraison prévue : 01/09/2026 ». Le commentaire qui
l'écrivait l'avouait : « ce que le fichier porte en plus, et qu'aucune colonne n'accueille ».

Mesuré sur les deux fichiers réels redéposés en transaction annulée : 147 mouvements,
**travaux 147/147, propriétaire 145, déposant 120, date de livraison prévue 147/147**. Une
date rangée dans une phrase ne se trie pas, ne se filtre pas et ne se compare pas à
aujourd'hui — on ne pouvait donc pas poser la question du comptoir. Elle se pose désormais :
**60 véhicules** sont entrés, leur date de livraison promise est passée, et aucune sortie
n'est enregistrée pour leur fiche. L'écran a l'indicateur et le filtre ; le rapprochement se
fait sur l'absence de la ligne de sortie, et non sur le statut du parc — celui-là vient d'un
autre fichier, donc d'un autre dépôt. Migration additive `2026_09_24_000002`.

**L'écran des mouvements** montrait six des onze colonnes ; il les montre toutes (motif,
travaux, propriétaire/déposant, livraison prévue). Les lignes importées avant ce jour gardent
leur phrase, affichée telle quelle : la découper pour en répartir les morceaux supposerait
qu'on sait la relire, or le déposant y porte des retours à la ligne et des numéros de
téléphone. Le prochain dépôt du fichier remplit les colonnes.

**La règle est devenue un mécanisme.** La page de détail d'une pièce fournisseur recopiait la
liste des colonnes à la main et en avait perdu **sept sur quarante et une** — « TVA 2 »,
« DIFFERENCE », les deux montants nets, les deux n° de facture d'achat et de vente, les
observations sur la facturation client. « TVA 2 » est la plus parlante : l'import explique par
écrit qu'il conserve les deux colonnes de TVA exprès, et la page n'en montrait qu'une. Une
liste recopiée à la main diverge de sa source ; c'est sa nature.

Le trait `MontreLesColonnesDuFichier` retire la recopie : intitulés, ordre et valeurs viennent
de `colonnes()` du format. Les blocs (Identité, Dates, Montants…) nomment des **clés**, et tout
ce qu'aucun bloc ne réclame tombe dans « Autres colonnes du fichier ». Une colonne peut être
mal rangée ; elle ne peut plus disparaître — un test l'exige. La fiche du parc, qui avait
inventé ce procédé après avoir perdu deux colonnes, passe au trait partagé. Corrigé au passage :
« RESULTAT INDICATIF » n'est pas un montant malgré son nom — le classeur y écrit « Marge
positive » — et la page l'affichait à travers le formateur de francs, donc « 0 F ».

Tests : `LesColonnesDuFichierSeRetrouventALEcranTest` (16).

### Le n° de fiche de réception, clé de rapprochement

✅ **Fait le 24/09.** Une affaire commence par une fiche de réception, et son numéro se
retrouve ensuite dans la situation du parc, dans les entrées et sorties, sur le devis et sur
la facture. Chacun de ces états était lu de son côté : on pouvait voir qu'un devis existait
sans pouvoir dire si le véhicule était ressorti, ni si la facture avait suivi.

**Ce que le relevé du 24/09 a établi.** Le numéro s'écrit `FR-XX N° nnnnn`, où `XX` désigne
l'atelier : 3 318 des 3 323 fiches du parc ont cette forme, 147 mouvements sur 147, 2 422
devis sur 2 670 et 2 336 factures sur 2 386 — le reste étant le jeu de démonstration local,
qui écrit « FR-ABJ-1-422 ». Surtout : **rapprocher par égalité exacte donne exactement le même
résultat que rapprocher par forme normalisée** — 1 169 devis, 1 914 factures, 124 mouvements
dans les deux cas. Le logiciel écrit le numéro de la même façon partout.

**Aucune colonne normalisée n'est donc stockée, et aucune ligne n'est réécrite.** Une seconde
écriture du même numéro serait une seconde vérité à tenir à jour, pour un gain mesuré à zéro.
La normalisation existe dans `PisteDeLaFiche` mais ne sert qu'à reconnaître qu'une saisie est
un numéro — et à pouvoir constater le jour où les écritures divergeront.

**La fiche du parc porte la piste.** Une section « Ce que cette fiche a produit » y montre les
devis, les factures et les mouvements, avec leurs montants et leurs dates. Et **ce qui manque
est dit en toutes lettres** — « aucune facture ne cite cette fiche », « aucune sortie n'est
enregistrée » — parce qu'une page vide se confond avec une panne : 628 des 3 318 fiches n'ont
encore ni devis, ni facture, ni mouvement.

**Le numéro s'ouvre d'un clic** depuis les écrans *Devis*, *Chiffre d'affaires* et *Entrées &
sorties*, par la route `/parc-vehicules/fiche/{numero}` : la résoudre au clic évite la requête
par ligne affichée qu'aurait coûtée un lien direct. Le responsable commercial, à qui le parc
n'est pas ouvert, lit le numéro sans qu'il soit cliquable — un lien qui répond « interdit » est
une façon désagréable de dire non. Un numéro introuvable au parc dit pourquoi : 1 191 numéros
de devis et 384 de facture désignent une fiche que la situation du parc ne porte pas, cette
situation étant une extraction à une date.

**Deux constats qui corrigent le plan.**

- **La caisse ne porte pas ce numéro.** Le plan l'annonçait « dans le libellé pour la caisse ».
  Vérifié sur les 1 155 mouvements repris : **aucun** libellé et **aucun** motif n'en contient.
  Le rapprochement caisse ↔ fiche n'existe pas, et le chercher aurait coûté une requête par
  page pour ne jamais rien trouver.
- **Le parc interdit déjà deux fiches sous le même numéro** : `dossiers_vehicules` porte une
  clé unique sur (entreprise, n° de fiche). La page avait prévu d'annoncer « l'affaire a été
  rouverte » dans ce cas ; le message ne pouvait jamais s'afficher, et la requête qui le
  nourrissait cherchait ce qu'on tenait déjà. C'est un test qui l'a montré, et les deux ont
  été retirés.

Tests : `LeNumeroDeFicheRelieLesEtatsTest` (13). Aucune migration : ce chantier n'ajoute pas
une colonne.

### Un fichier écarté le dit, et dit pourquoi

✅ **Fait le 24/09.** La décision datait du 18/09 : ne pas écrire d'import pour les fiches de
réception, puisque la situation du parc porte les mêmes fiches avec dix-sept colonnes — dont
les travaux à effectuer, le statut et les sept dates. Elle était juste, et **invisible** :
écrite ici, dans le dépôt de code, et nulle part dans l'application.

Or celui qui tient un export de fiches de réception vient sur l'écran de dépôt. N'y trouvant
rien, il ne peut pas savoir si c'est un oubli ou une décision : il redemande, ou il attend.
**Une décision qui ne se lit nulle part se reprend tous les trois mois.**

Le registre des formats avait deux catégories — ce qu'on sait lire, ce qu'on saura lire — et
il en fallait une troisième : **ce qu'on a regardé puis écarté**. `Registre::ECARTES` la porte,
avec pour chaque entrée sa source et sa raison, et le tableau de bord des imports l'affiche.
Un écarté n'y est **ni un retard ni un manque** : la colonne « villes manquantes » dit « sans
objet » au lieu de peindre trois villes en rouge, et le compteur affiche un tiret au lieu de
« table à créer ».

Le code client reste le seul manque du parc, et **décidé le 18/09 : on ne l'attend pas**.
Mesuré le 24/09, il ne tiendrait de toute façon pas lieu de clé — il n'est renseigné que sur
**2 377 des 11 332 factures**.

**Deux affirmations périmées corrigées au passage**, toutes deux dans le tableau de bord des
imports :

- les entrées et sorties y portaient un commentaire disant qu'elles n'avaient « aucun lecteur,
  les fichiers sortent en PDF ». Les deux lecteurs existent ;
- **trois des onze types n'affichaient aucun compteur** — journal de caisse, balance et
  règlements fournisseurs — faute de destination déclarée, et affichaient donc « table à
  créer » en rouge alors que leurs tables sont pleines. Un test exige désormais que tout
  format importable sache où il écrit.

Tests : `UnFichierEcarteLeDitEtDitPourquoiTest` (8). Aucune migration.

### Les retours du propriétaire du 24/09

Une revue d'écran par écran, faite sur la version en service. Sept lots, tous livrés le
même jour. Ce qui suit dit surtout **pourquoi** chaque point valait d'être corrigé.

**Ce qui se lisait mal.**

- La pagination parlait anglais — « Showing 1 to 25 of 714 results ». `lang/fr.json` la
  traduit partout d'un coup.
- Sur la caisse et les fournisseurs, tourner une page **rechargeait tout et ramenait en
  haut** : le paginateur d'Eloquent rend de vraies ancres. Les deux écrans passent au
  composant maison, piloté par `$set` — on ne perd plus la ligne qu'on lisait.
- Le journal des modifications affichait « updated » et « ville_id : — → 1 ». Un journal
  qu'il faut connaître la base pour lire ne répond qu'à ceux qui n'en ont pas besoin :
  `JournalLisible` traduit le geste, le nom du champ, et remplace un identifiant de ville
  par son nom. Sur la créance et sur la pièce fournisseur.
- Sur les entrées et sorties, deux colonnes débordaient sur leurs voisines — `max-width`
  sur un `<td>` n'est qu'un avis. Et « 0 promesse dépassée » se lisait de travers : il peut
  vouloir dire « tout est tenu » ou « aucune date n'est connue ». Le KPI le dit maintenant,
  et la case de filtre ne s'affiche que lorsqu'elle a quelque chose à filtrer.
- Le délai moyen d'envoi d'un devis s'exprime en jours **et** heures : arrondi au jour, il
  affichait « 0 j » aussi bien pour six heures que pour vingt, alors que le délai visé est
  de vingt-quatre heures.

**Ce qui manquait.**

- **Le référentiel fournisseur se corrige à la main**, et chaque correction laisse sa trace
  — qui, quoi, quand, depuis quelle adresse et quel poste. La page disait « la correction se
  fait dans le classeur » : vrai, et insuffisant, puisque le classeur est tenu ailleurs. Le
  nom d'une fiche venue d'un classeur reste verrouillé — c'est la clé qui la relie à ses
  factures. Les jours et le « fin de mois » ne se saisissent pas : ils sont la lecture du
  libellé, relue ici comme à l'import.
- **Tout encaissement et tout décaissement dit son motif**, obligatoire à la saisie et
  nullable en base : les lignes déjà enregistrées ne peuvent pas en recevoir un, et le leur
  inventer serait écrire à leur place. Migration `2026_09_24_000003`.
- Le chiffre d'affaires reçoit un **filtre d'état** (portées à l'état, réglées) et un
  **bouton Détail** par ligne, qui ouvre la page d'une créance : c'est la même facture des
  deux côtés. Cette page n'acceptait que les factures portées à l'état ; elle les accepte
  toutes et dit, le cas échéant, que la facture n'y est pas encore.
- Le **rapprochement se coche** : une case par ligne, « tout cocher », « cocher les
  certains », « confirmer la sélection ». Entre « tout confirmer » et « ligne à ligne »
  manquait le geste ordinaire. Les deux tableaux sont enfin paginés.
- **Des bornes de date** sur les clients, le rapprochement et les impayés. Sur les impayés,
  un sélecteur de mois qui ne porte pas d'année : elle est déjà choisie dans le sélecteur
  d'exercice, et deux années sur un même écran finissent par diverger.

**Ce qui a été retiré.** Les fiches de réception écartées ne s'annoncent plus sur l'écran de
dépôt : la décision est prise, et cet écran répond à « qu'est-ce que je dépose ? ». Le
paragraphe qui expliquait la traçabilité sous chaque fiche de prospection disparaît aussi —
le tableau se lit seul. « Caisse par véhicule » quitte le menu des indicateurs : elle ne
répond pas à une question qu'on se pose en arrivant, mais devant une plaque.

### Le barème court jusqu'à ce qu'un autre le remplace

✅ **Fait le 24/09**, et cela **revient sur la décision du 22/09**. On avait cloisonné le
barème par exercice : une grille valait pour son année, et la corriger recalculait l'année
entière. L'usage a montré deux conséquences.

Il fallait **reposer une grille chaque 1er janvier**, et le jour où on l'oublie plus aucune
commission ne se calcule — sans que rien ne prévienne. Une grille de 2026 rémunère désormais
2027 tant que personne n'en a posé d'autre.

Et corriger la grille en novembre **recalculait les dix mois déjà annoncés** aux commerciaux,
ce qui est exactement ce qu'on ne veut pas d'une rémunération. Une grille enregistrée
aujourd'hui vaut à partir d'aujourd'hui ; les mois antérieurs gardent celle sous laquelle ils
ont été arrêtés. Remplacer n'est pas écraser : les deux grilles coexistent, et l'écran dit
depuis quand court celle d'aujourd'hui.

La date d'effet redevient la clé ; `exercice` ne cloisonne plus, il dit sous quel exercice la
grille a été posée. Les grilles existantes étant datées du 1er janvier de leur exercice, la
règle s'applique sans qu'aucune ligne soit réécrite. Migration `2026_09_24_000004` (l'index
d'unicité change de colonnes, vérifié avant d'être touché).

### Le classeur du plan garde sa mise en forme

Le script de séance régénérait le classeur par l'exportateur générique de l'application :
bandeau « GESTION-DE-SITES », largeurs standard, plus de liste déroulante sur le statut, plus
de volet figé. Le classeur du 18/09 avait sa propre mise en forme, et c'est elle qu'on veut.

On ne régénère donc plus la mise en forme : on garde le classeur d'origine — styles, colonnes
taillées pour ce tableau, volet figé sous la ligne de colonnes, liste des statuts — et l'on
n'y remplace que les lignes. Le gabarit vit à côté du plan
(`ARTISAN-PLAN-RESTANT/modele-mise-en-forme.xlsx`). Les dates redeviennent des dates : en
texte, elles ne se trieraient pas. La liste déroulante suit jusqu'à la dernière ligne.

### Où en est le plan

Le classeur `plan-a-jour.xlsx` porte le suivi : statut, date de début,
date de fin, une ligne par chantier. Au 24/09 : **72 lignes terminées, 5 à faire, 1 à
valider** (l'envoi du courrier à M. Fofana), **1 abandonnée et 1 sans objet**. Sept sections
se sont ajoutées au plan d'origine — la vitesse des pages, la plateforme (qui saisit, et comment le
joindre), la caisse (le journal imprimé), les fournisseurs — le registre par année, puis le
référentiel et l’échéance attendue —, les colonnes du fichier écran par écran, et le n° de
fiche comme clé de rapprochement, et ce qu'on a écarté.

La ligne « Obtenir les états de caisse en tableur », qui attendait une demande à la
direction, passe à **Abandonné** : elle n'a plus d'objet depuis que le journal est lu dans
son PDF. On ne laisse pas une attente derrière une chose dont on n'a plus besoin.
Le classeur vit dans `C:\BUREAU\GESTION-DE-SITES\ARTISAN-PLAN-RESTANT\`, le PDF un cran
au-dessus.

Il se refabrique par le script de la séance, qui **relit le classeur au lieu de le réécrire**
— les libellés ont été rédigés une fois, et les retaper les ferait dériver. Ce qui suppose
qu'il soit rejouable sans rien empiler : il retire les doublons qu'une exécution précédente
aurait laissés, repère la ligne de colonnes par son intitulé plutôt que par son rang, et
repose ses deux sections entières. Il ne s'écrase pas tant qu'un tableur le tient ouvert,
auquel cas la version à jour attend à côté.

### Pour le jour où les API répondront

Demandé par le propriétaire le 24/09, à garder pour ce moment-là et pas avant.

Quand les données arriveront par une API plutôt que par un fichier, les **codes** qu'elles
portent — code employé, code client — seront reconnus à la lecture. Il faudra alors
**afficher le nom du porteur du code juste en dessous**, lorsqu'il correspond à quelqu'un
qu'on a déjà en base.

Le pourquoi tient en une phrase : un code se vérifie du coin de l'œil quand le nom est à
côté, et ne se vérifie jamais quand il est seul. C'est déjà le parti pris de
`x-numero-ligne`, qui affiche le nom en clair sous le code de saisie — « aucun code ne se
retient ». La même règle vaudra pour les codes venus de l'API, avec une différence : le nom
ne doit s'afficher que si la correspondance existe **réellement en base**. Afficher un nom
deviné serait pire que d'afficher un code nu.

### Hors chantier, toujours en attente

- **Rotation des secrets** (le `.env` de production a circulé en clair) : mot de passe du
  courriel `infos@dc-knowing.com`, secret Google OAuth, mot de passe MySQL, puis `APP_KEY` et
  clés VAPID. **Priorité la plus haute, côté propriétaire.**
- **Hébergement** : activer OPcache sur le PHP qui sert les pages, et `CACHE_STORE=file` — c'est
  le premier poste de lenteur. Voir MISE-A-JOUR-SERVEUR.md.
- **Responsable commercial**, deux arbitrages pris sans confirmation : il ne gère pas les accès
  de ses commerciaux ; avec « Toutes les villes », sa fiche de vendeur se rattache à la première
  ville par ordre alphabétique. Il atterrit sur *Saisie du jour* (proposé : *Commerciaux*).
- *Mes prospections* et *Mes notes* restent fermées au responsable de ville et de site.
- Les branches `import` et `recouvrement` sont entièrement fusionnées dans `main` : supprimables.
- **Le barème de commission** : `Commission_Commerciaux_Artisan (1)_vf6.pdf` (déposé à la racine
  du dépôt, non versionné). **Les quatre points sont levés depuis le 21/09**, et ils ne sont plus
  bloquants : la grille est devenue une **donnée** que le gérant modifie à l'écran.

  - **L'assiette est tranchée par le document lui-même** : sa dernière ligne dit « Commission
    appliquée sur le chiffre d'affaires **global** mensuel HT », et l'arithmétique de sa propre
    colonne le confirme — 200 000 F annoncés à 1 % sur la tranche 20–25 M, soit 1 % de 20 M
    entiers. Un test le vérifie valeur par valeur sur les deux grilles.
  - **Le seuil d'entrée** : la phrase « aucune commission sous 25 M » contredit la grille et sa
    propre commission indicative. La proposition retient **20 M**, celui de la grille.
  - **Les trous entre tranches** : les tranches sont rendues **jointives**.
  - **Les bornes qui se chevauchent** : une convention unique, **plancher atteint, plafond
    exclu**.

  Ces trois derniers points sont des *propositions*, posées d'un clic et modifiables ligne par
  ligne : le gérant tranche lui-même, sans déploiement. **Reste à confirmer par le propriétaire**
  une fois la grille posée et relue à l'écran.
- **Deux règles ajoutées au barème**, absentes du document mais nécessaires : commission sur le
  **facturé** du mois (non l'encaissé), et seulement sur les factures rattachées à une prospection
  ou à un devis du commercial — ce qui fait dépendre le chantier 10 du chantier 4.
- **Ce que les API changeront au chantier 4 — remarque du propriétaire du 21/09.** Elles règlent
  la moitié du problème, et pas l'autre.

  - **Ce qu'elles règlent.** Le flux « Proformas et devis » demandé dans le courrier porte
    l'immatriculation, le n° de fiche et le code client. Aujourd'hui, le devis ne connaît pas sa
    plaque : il faut passer par sa fiche de réception, et **1 172 devis sur 2 673 seulement** s'y
    raccrochent. Avec l'API, chaque devis porte sa propre plaque — le rapprochement par
    immatriculation cesse d'être une piste pour devenir une lecture.
  - **Ce qu'elles ne régleront pas.** Aucune API ne dira **quel commercial a fait la visite** :
    la prospection n'existe que dans cette application, elle n'est dans aucun logiciel d'atelier.
    Le lien prospection → devis restera donc un rapprochement à confirmer, quoi qu'il arrive.
  - **Conséquence pratique** : le travail fait le 21/09 n'est pas à refaire. Le jour où l'API
    arrivera, `RapprochementProspectionDevis` lira la plaque directement sur le devis au lieu de
    la chercher sur sa fiche — un seul endroit à changer, `plaquesParFiche()`.

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

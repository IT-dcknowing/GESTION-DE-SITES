# Reprendre ici

*Écrit pour qui ouvre une séance sur ce projet — une personne ou un assistant — et doit être
utile dans la minute. **Se lit en entier avant de toucher au code.** Mis à jour le 15 octobre
2026.*

`ETAT-DES-LIEUX.md` reste la mémoire longue : 2 800 lignes, l'histoire de chaque décision. Ce
fichier-ci dit seulement **où l'on en est, ce qui reste, et ce qu'il ne faut pas refaire**. On y
va quand on a besoin du détail ; on commence ici.

---

## 1. Le projet en six lignes

Application de gestion pour **L'Artisan Automobile** (Côte d'Ivoire) : ateliers de réparation,
plusieurs villes, un atelier pouvant être multiple dans une même ville (Abidjan en a deux).

Laravel 13, Livewire 4 + Volt, `nwidart/laravel-modules`, Spatie permissions (par équipe =
entreprise) et activitylog, Fortify. MySQL en ligne, SQLite en mémoire pour les tests.

Les données viennent **de classeurs Excel déposés** — chiffre d'affaires, impayés, caisse,
fournisseurs, relevé bancaire — et de saisies dans l'application. **Les fichiers sont tenus à la
main** : ils portent des dates illisibles, des montants négatifs, des feuillets multiples. C'est
la source de la plupart des défauts, et c'est normal.

---

## 2. Les sept règles qui priment sur tout

Posées par le propriétaire. Détail au § 2 d'`ETAT-DES-LIEUX.md`. **Aucune n'est négociable.**

1. **La base en ligne porte des données réelles.** Migrations **additives** seulement : tables
   neuves, colonnes nullables, contraintes **relâchées** jamais resserrées. Jamais de seeder, de
   purge ni de `migrate:fresh` sur un serveur. Une commande qui écrit des données s'exécute
   **d'abord en constat**, et ne s'inscrit pas dans `app:deployer`.
2. **Mesurer avant d'agir.** On ne corrige pas ce qu'on n'a pas compté. Tout chiffre avancé dans
   un commentaire, un commit ou une réponse doit venir d'une mesure, pas d'une estimation.
3. **Sécurité** : autorisation vérifiée **à la route et dans l'action** ; périmètre lu sur
   l'identité du lecteur, **jamais sur un paramètre reçu** ; une valeur qui revient du navigateur
   ne commande rien d'irréversible sans être recontrôlée en base.
4. **Une branche git par module**, commits dessus, fusion sur `main` quand c'est demandé.
   **Depuis le 05/10, le propriétaire demande : en fin de travail, commiter, pousser la
   branche, la fusionner dans `main` et pousser `main`.** Ce qui arrive sur `main` part en
   ligne au prochain `git pull` : la suite de tests passe donc avant la fusion.
5. **Jamais de déploiement par zip.** `git pull` + `php artisan app:deployer`, sur le dev d'abord.
6. **Pas de dialogue navigateur** (`confirm`, `alert`) ; filtres instantanés ; le chemin critique
   marche **sans JavaScript** (le dépôt d'import est un vrai POST).
7. **Écrire comme le code existant** : français, commentaires qui disent **pourquoi** et citent
   la mesure qui a motivé le choix. Pas de commentaire qui paraphrase le code.

---

## 3. Où l'on en est — 20 octobre 2026

**Branche du 14/10 :** `claude/arrondis-doublons-charges-k1upa9`, poussée puis fusionnée dans
`main`. Deux migrations additives (tables neuves) : `git pull` puis `php artisan app:deployer`.

### Fait le 21/10 — la présentation à 360°

**`PRESENTATION-360.md`** : ce qu'est l'application et comment elle marche, à envoyer à un tiers,
l'accent sur **les importations** (§ 6) et **les charges** (§ 7). Aucun identifiant ni adresse de
serveur. À tenir à jour quand la règle des charges ou un format d'import change.

### Fait le 20/10 — les fournisseurs bouclés, les charges = la caisse, la trésorerie lit l'importé

**Le module Fournisseurs est terminé** (« nous allons terminer avec les fournisseurs ») : il ne
figure plus dans ce qui reste. Migration additive **`2026_10_20_000001`** (une colonne nullable).

- **Règle du propriétaire : « toutes les sorties de caisse sont des charges »** ; les fournisseurs
  « ne font que retracer, c'est un suivi ». Écrite une fois dans **`ChargesDeLaPeriode`** :
  charges = chaque sortie du journal de caisse + les charges saisies qui n'en viennent pas (une
  reprise ne compte plus deux fois, elle ne fait que **classer** : libellé, atelier). Lue par la
  page Charges, le CA, l'accueil du gérant et la synthèse par atelier. Le suivi fournisseurs n'entre
  plus dans les charges (il est « à côté ») — la règle du 18/10 est **remplacée**.
- **Page Charges** : cartes Achats pièces / Salaires / Fonctionnement / Autres remplies par la caisse
  (nature lue par `CategorieDeSortie`), graphique par nature, tableau des charges (saisies + sorties)
  filtrable par provenance ; genre « Suivi fournisseurs — à côté ».
- **Règlement fournisseur ↔ sortie de caisse** : « Régler » propose la sortie du journal qui a payé
  (`reglements_fournisseur.mouvement_caisse_id`) ; la page Fournisseurs dit « dont par la caisse ».
- **Fournisseurs** : page « Conditions de règlement » retirée (les fiches restent, pour les
  échéances) ; « Voir » → **Détails** ; **Porter** : ce que dit la facture FNE en premier, en lecture
  seule, relu côté serveur (et verrouillé ensuite sur la pièce) ; formulaire réduit à l'essentiel ;
  **KPI des normalisés** (FNE reçues, au suivi, à porter, suivi normalisé) ; carte « Facturé » ne
  coupe plus les centimes (`(int)` retiré, aussi à l'état des impayés).
- **Balance** : deux onglets — importée (logiciel comptable) et **produite par le système**
  (`BalanceDesFournisseurs` : par fournisseur, facturé / réglé / reste, ancienneté aux tranches de
  la balance âgée, solde importé en regard et écart).
- **Trésorerie** (`TresorerieReelle`) : entrées et sorties = journal de caisse + relevés bancaires +
  saisies d'ici ; les règlements importés de l'état des impayés restent à côté (même argent). Le
  graphique et les listes lisent l'importé (relevés compris).
- **Accueil du gérant** : cartes à 250 px (plus de débordement) ; **objectifs du mois par employé
  concerné** (choix du mois ; réalisé = ses factures s'il est commercial, sinon son atelier, sa ville).

### Fait le 16-18/10 — le relevé FNE en ligne, les fournisseurs dans les charges

- **Le relevé FNE tourne sur gestion-dev** (2 728 pièces, 2 570 en 2026) : Chromium démarre avec les
  bibliothèques déjà posées pour Selflow (`FNE_LIBS=~/local-libs/usr/lib64`). Guide de bout en bout :
  `SCRAPER-PORTAIL-FNE/GUIDE-COMPLET.md`.
- **Relevé automatique allumé d'office** dès que `SCRAPER-PORTAIL-FNE/.env` existe (`config/fne.php`) ;
  `node` cherché dans `/opt/alt/alt-nodejs*/` si `FNE_NODE` est vide. Le bouton Actualiser lance le
  relevé en arrière-plan et les factures se déversent seules ; le dépôt à la main n'est plus qu'un
  secours replié.
- **Bouton Voir** sur chaque facture FNE (`/fournisseurs/fne/{id}`) : en-tête, **lignes de la facture**
  (désignation, quantité, prix, remise, taxes), toutes les données de la pièce — pour passer les
  écritures.
- **Porter ≠ régler** : Porter ouvre le formulaire du suivi fournisseurs **prérempli** (`?porter=id`) ;
  on complète et on enregistre, la pièce est liée (`source_rattachement = 'fne'`).
- **Rapprochement par le n° FNE** écrit dans une ligne importée (n° de pièce, observations…),
  avant celui par date et montant (`AchatsFne::rapprocherParLeNumeroFne`).
- ~~Les fournisseurs sont des charges~~ — **remplacé le 20/10** : les charges sont la caisse et les
  saisies, le suivi fournisseurs se lit à côté (voir ci-dessus).

### Fait le 15/10 — doublons revus, objectifs par accès, rattrapage

- **Doublons, règle précisée par le propriétaire** : au premier import **rien n'est écarté** — la
  ligne répétée est importée et comptée, puis listée « supposée doublon à preuve du contraire »
  avec sa **raison** (même n°, même date, même montant… ou ce qui diffère) ; trois boutons : garder
  la première, garder la seconde, garder les deux. La ligne retirée ne revient pas au dépôt
  suivant ; redéposer le même fichier ne réimporte rien. Migration `2026_10_15_000001`.
- **Accès et objectifs** : à la création d'un accès (gérant/responsables et Super admin), la case
  « concerné par les objectifs » puis tous ou ceux cochés ; **Super admin › Accès › Rattrapage**
  pour les accès d'avant. Ma performance montre les objectifs du mois qui concernent l'accès.
  Migration `2026_10_15_000002`.
- **Page d'erreur** : plus de « config/assistance.php » affiché à l'utilisateur (consigne déplacée
  dans `MISE-A-JOUR-SERVEUR.md`).
- **`node -v` en ligne** : raccourci cPanel cassé (`~/bin/node` → `nodevenv/…/18` absent) ; réparation
  dans `SCRAPER-PORTAIL-FNE/CONSIGNES.md`.
- **Plan à part** : `PLANS-SPECIAUX/PLAN-SPECIAL-14-15-OCTOBRE-2026.xlsx` — demande du propriétaire :
  ne pas mélanger ce plan au plan général ; même modèle (statut à liste, couleurs, dates).

### Fait le 14/10 — doublons, charges selon la caisse, prospections, objectifs

- **Doublons d'import.** Une ligne répétée dans un même fichier **réécrivait** la première (c'est
  l'écart de 10 000 F du CA : FA -8730 deux fois). Désormais une ligne de la base ne sert qu'une
  fois par dépôt : la répétition est gardée dans `doublons_import`, et chaque page concernée (CA,
  impayés, devis, caisse, banques, fournisseurs) montre **Importé / Retenu / Écarté / Intégré**,
  le tableau « Voir les doublons » (ligne écartée ↔ ligne retenue) et le bouton **Intégrer**.
  Plus aucune fusion de deux montants proches (l'ancien ±0,5 F). Détail : ETAT-DES-LIEUX § 6, ligne 42.
- **Charges** : la carte « Selon la caisse » s'ouvre sur les **catégories** (lues dans le libellé) ;
  le tableau a son bouton **« Charges selon la caisse »** ; la carte FNE lit enfin les achats relevés.
- **Affichage général des prospections** (`/affichage-general`) : tout le monde voit tout, avec
  « déjà prospecté ? ».
- **Objectifs mensuels de l'entreprise** par tranches et période (Général › Objectifs mensuels),
  carte au tableau de bord du gérant.

**Tests (14/10, conteneur cloud) :** 1 209, **1 196 au vert** ; les 13 échecs sont ceux d'avant le
changement, à l'identique (9 faute du dossier `PLAN/`, 4 faute de `.env` : locale, `APP_URL`). 16 tests
neufs : doublons (6), charges selon la caisse (2), affichage général (3), objectifs (5).

### Avant — 12 octobre 2026

`claude/friendly-archimedes-nisgxa` a été poussée puis **fusionnée dans `main` le 07/10**, et
`main` poussé. `correspondances-factures` (05/10) et `tresorerie-caisse-et-banques` sont déjà dans `main`.

### Fait le 07/10 — l'accueil du gérant

Le tableau par atelier faisait **six agrégats par atelier** ; il en fait **cinq en tout**
(`SyntheseParAtelier`, `group by site_id`). Mesuré par un rendu Livewire, cinq ateliers, un
changement de filtre : **48 requêtes avant, 23 après**. Les chiffres sont ceux d'avant, et
`LaSyntheseParAtelierCoutCinqRequetesTest` le tient en gardant la boucle d'avant comme
référence (avoirs, lignes sans atelier, bornes, filtres activité et commercial). Aucune
migration : un `git pull` suffit.

### Fait le 05/10 — les correspondances

Chaque commercial coche les factures nées de ses prospections. **11 229 factures sur 11 332
ne sont comptées à personne** en local : avant le 22/09, rien ne reliait une prospection à
son devis. Trois écrans :

| Écran | Pour qui | Ce qu'il fait |
|---|---|---|
| `/correspondances` | ceux qui vendent, et le gérant | factures non affectées, **toutes les colonnes de la facture** ; cocher (la case ou la ligne) → nom et code paraissent → **Valider** ; un responsable coche **pour le compte d'un commercial** choisi dans « Affecter à » ; la ligne disparaît chez les commerciaux, reste chez les responsables, qui seuls **annulent** |
| `/correspondances/miennes` | qui porte une fiche commerciale | bouton « **Mes correspondances** » : ce qu'il a coché, annulations comprises |
| `/correspondances/suivi` | gérant et responsables | bouton « **Les correspondances** » : filtres période / ville / atelier / commercial du tableau de bord, récapitulatif par commercial |

Cocher écrit `factures.commercial_id` (donc le chiffre et la commission) et une ligne dans
`correspondances_factures`. Règles dans `CorrespondancesDeFactures` ; 13 tests dans
`LesCommerciauxCochentLeursFacturesTest`. Détail : § 6 d'`ETAT-DES-LIEUX.md`.

**Tests (07/10, conteneur cloud) :** 1 094, **1 081 au vert**, 13 échecs **tous présents à
l'identique sur `main` sans le changement** : 9 + 2 dans `DepotFormulaireTest` et
`DepotToutesVillesTest` (le dossier `PLAN/`, ignoré par git, n'existe pas hors du poste du
propriétaire), et 2 faute de `.env` (`APP_LOCALE=en`, `APP_URL` sans `http`). Au 05/10 en local :
1 088, 1 081 au vert, 0 échec ; les 7 erreurs restantes y sont `WebPushTest` —
l'OpenSSL local ne sait pas générer une clé P-256. **Environnemental, pas un défaut du code.**

**Les onze chantiers du plan (§ 6) sont tous terminés.** Depuis, le travail porte sur les
retours d'usage du propriétaire.

### Fait dans les trois dernières séances (30/09 → 02/10)

| Sujet | État |
|---|---|
| Trésorerie refaite en tableau de bord par support (caisse / banque / mobile / non précisé) | ✅ |
| Écran **Banques**, import du relevé bancaire, moyen de paiement en trois champs liés | ✅ |
| **Maintenance SuperAdmin** : purge sélective par module, cases à cocher | ✅ |
| Vitesse : Telescope (local), index `(entreprise_id, date)`, pagination en base | ✅ |
| Vitesse : `Exercice::actuel()` mémorisé par requête, courbes en une requête (`SerieParPoint`) | ✅ |
| **Caisse** : solde recalculé par feuillet dans l'ordre du fichier, colonne **D/C** par ligne | ✅ |
| **Avoirs** : la base peut enfin les porter, l'import les accepte, `NatureDeLaCreance` les classe | ✅ |
| **Trésorerie** : les deux listes portent enfin le journal de caisse | ✅ |

---

## 4. Ce qui reste — par qui le fait

### Le plan du 07/10 — en cours

Les demandes du propriétaire du 07/10, **dans l'ordre où elles se traitent**. Le détail de
chacune, avec ses mots : § 6 d'`ETAT-DES-LIEUX.md`, « Le plan du 07/10 ». Chaque section
terminée est fusionnée dans `main` et poussée sur **les deux dépôts** (`origin` porte les deux
URL de push).

| # | Sujet | État |
|---|---|---|
| 0 | Tableau par atelier du gérant en `group by` | ✅ |
| 1 | Habilitations : recouvrement → superviseur de ville ; impayés → comptabilité et recouvrement | ✅ |
| 2 | Tableau de bord du gérant à zéro après import | ✅ |
| 3 | Maintenance : choisir les dépôts à supprimer | ✅ |
| 4 | Extrait de compte : filtre par état de règlement, export compris | ✅ |
| 5 | Pas de doublon entre l'état des impayés et l'import du CA | ✅ — migration `2026_10_07_000001` à passer, puis `factures:doublons` |
| 6 | Caisse centralisée à Abidjan (sites 1 et 2) : vérifier | ✅ |
| 7 | Erreur 503 sur `/super-admin` | 🟡 cause probable nommée — **`app:diagnostic` en ligne** pour la confirmer |
| 8 | Banques AFG et BGFI ; import du classeur de suivi de la caissière | ✅ — `migrate` puis `banques:declarer --appliquer` |
| 9 | Trésorerie : KPI par source, comparaison, rapprochements CA-Banque et CA-Caisse | ✅ |

### Le plan du 07/10, seconde partie — en cours

Détail : § 6 d'`ETAT-DES-LIEUX.md`, « seconde partie ».

| # | Sujet | État |
|---|---|---|
| 10 | Extrait de compte : colonne CLIENT (assuré) à l'export | ✅ |
| 11 | Bouton Retour sur `/recouvrement` | ✅ |
| 12 | La comptabilité ouvre le recouvrement ; et la trésorerie | ✅ |
| 13 | Banques : règlements face au relevé, plus de montant sur les boutons | ✅ |
| 14 | Banques : « Affecter à » et « Modifier » sur les libellés non rangés | ✅ (table `libelles_de_banque`, migration à passer) |
| 15 | Expliquer le rapprochement et le lettrage | ✅ |
| 16 | Charges récupérées de la caisse, colonne Origine | ✅ (colonne `charges.mouvement_caisse_id`, migration à passer) |
| 17 | Synthèse par site : « Lieu non précisé », activité par le devis | ✅ — `factures:situer` **déjà appliqué en ligne** |
| 18 | Factures fournisseurs FNE (stripping), porter et régler | ✅ 12/10 — scraper repris de Selflow (`SCRAPER-PORTAIL-FNE/`), écran Fournisseurs › **Factures FNE**, « Régler » sur la pièce. **Migration à passer, accès FNE attendus** |
| 19 | Accueil du gérant : taux de transformation des devis (0 %) et ventilation de l'encaissé | ✅ 08/10 — validés **ou facturés** ; l'encaissé prend l'activité de sa facture. Charges 0 F = aucune charge saisie, pas un défaut |
| 20 | La comptabilité a **tout** le module recouvrement (plus de « habilitation supérieure ») | ✅ 08/10 |
| 21 | **Panne `/banques` (ERR-XEYTWV)** ; incidents lisibles depuis la Maintenance | ✅ 09/10 — écrans tolérants aux migrations en attente ; Maintenance → Incidents (champ, liste cliquable, migrations en attente) |
| 22 | Tableau de bord de la comptabilité à 0 F | ✅ 09/10 — périmètre par ville, jour = fin de période, encours complet |
| 23 | Les pannes réglées quittent la liste des incidents ; causes réelles corrigées (GROUP BY MySQL, relances longues, date en chaîne) | ✅ 09/10 |
| 24 | Lenteur : configuration en cache à chaque déploiement ; le reste = OPcache et `CACHE_STORE=file` chez l'hébergeur | ✅ code 12/10 — **serveur à faire** |
| 25 | E-mail refusé pour un caractère invisible | ✅ 12/10 |
| 26 | CA = fichier du CA ou saisie ; atelier par la fiche puis le devis | ✅ 12/10 — `factures:situer` déjà appliqué ; le lieu se relit aussi à l'affichage par le code de la fiche |
| 27 | Page Devis : validés ou facturés, délai par la fiche du parc | ✅ 12/10 |
| 28 | Charges par origine (caisse, FNE à venir, non normalisées) | ✅ 12/10 |
| 29 | Trésorerie : listes et libellés sans ambiguïté | ✅ 12/10 |
| 30 | Correspondances : prospections passées en devis | ✅ 12/10 |
| 32 | KPI « CA selon les impayés », à côté du CA | ✅ 12/10 |
| 33 | « Lieu non précisé » : origine dite (état des impayés) | ✅ 12/10 |
| 34 | **Plan fournisseurs** : suivi = base, correspondance dans les deux sens, achats FNE **portés** au suivi, « Régler » et lettrage, avoirs, KPI des différences | ✅ 12/10 — `AchatsFne`, tables `releves_fne` / `factures_fne`, `reglements_fournisseur.facture_fournisseur_id`. Détail : ETAT-DES-LIEUX § 6, ligne 34 |
| 35 | Suivi fournisseurs : aucune facture écartée ; échu par la date de facture sans échéance ni règlement | ✅ 12/10 |
| 36 | Synthèse par site en deux sections (CA / impayés) | ✅ 12/10 |
| 37 | `factures:situer --appliquer` en ligne | ✅ **appliqué par le propriétaire** — ne plus le demander |
| 38 | Synthèse : lieu relu par le code de la fiche (écran des codes, sinon ce que disent les autres lignes), ligne **Total** aux deux tableaux, total = CA de l'entreprise (ligne « Autres lignes du CA » si un lieu manque), lignes **refusées** à l'import dites avec leur montant | ✅ 12/10 — `LieuDesFiches` |
| 39 | Relevé FNE automatique (horaire 7 h–19 h, complet 2 h 30) + bouton Actualiser ; scraper à compte unique | ✅ 13/10 — **à armer sur le serveur** (§ 4, point 15) ; accès `2197588Q` reçus, adresse de production du portail à fournir |
| 40 | Fournisseurs : facture d'une autre ville → rangée à la ville qui a commandé, mention « reçue par » | ✅ 13/10 |
| 41 | **Aucun arrondi de montant** : colonnes décimales, centimes gardés de l'import à l'écran | ✅ 13/10 — **migration à passer, puis redéposer les fichiers** (§ 4, point 16) |
| 42 | **Doublons d'import** gardés, montrés (KPI + tableau), intégrables d'un bouton ; plus de fusion à ±0,5 F | ✅ 14/10 — migration `2026_10_14_000001` (§ 4, point 17) |
| 43 | **Charges selon la caisse** : carte cliquable → catégories ; bouton au tableau ; carte FNE branchée | ✅ 14/10 |
| 44 | **Affichage général des prospections** | ✅ 14/10 |
| 45 | **Objectifs mensuels de l'entreprise** par tranches et période | ✅ 14/10 — migration `2026_10_14_000002` ; **à saisir par le gérant** (§ 4, point 17) |

### Au propriétaire, et qui bloque la suite

0. **Les correspondances** : déjà dans `main` et poussées. Sur le serveur, `git pull` puis
   `php artisan app:deployer`, et `php artisan migrate` en ligne
   (`2026_10_05_000001_un_commercial_reconnait_ses_factures` — une table neuve, rien d'autre).
   **Nommer les codes de deux lettres** sur l'écran des codes : sans nom, la colonne
   « Saisi par » n'affiche que le code (0 des 39 codes nommés en local).
1. **Passer la migration des avoirs en ligne** : `php artisan migrate`
   (`2026_10_02_000001_une_facture_peut_porter_un_avoir`). Élargit `factures.montant` et
   `encaissements.montant` de `unsigned` à signé, ajoute `est_avoir`. Aucune ligne lue ni
   réécrite — vérifié en local : 11 332 factures, 7 699 743 305 F, identiques avant et après.
2. **Redéposer le fichier des impayés.** 43 lignes attendent dans `lignes_rejetees_import` avec
   toutes leurs valeurs. La structure les accepte maintenant ; **rien n'entre tout seul.**
   ⚠️ **Le chiffre d'affaires baissera d'environ 47 millions.** Ce n'est pas une régression :
   c'est la disparition d'un montant qui n'aurait jamais dû y être (voir § 6).
3. **Lancer `php artisan app:diagnostic` sur le serveur**, rubrique *Vitesse*, et rapporter la
   sortie. Sans elle, toute correction de la lenteur en ligne serait une supposition.
   **Depuis le 07/10 elle dit aussi** comment le dernier import a été lancé (processus à part
   ou processus web — cause probable des 503) et les dernières erreurs du journal. La lancer
   **après un dépôt**. Et regarder cPanel → « Resource Usage » au 05/10 vers 13 h 37 GMT.
4. **Regarder le bloc « Les classeurs de cette période »** sur `/caisse` en ligne : il dira si
   deux classeurs se superposent (voir § 5, point 1).
5. **Rotation des secrets** — le `.env` de production a circulé en clair : mot de passe du
   courriel, secret Google OAuth, mot de passe MySQL, puis `APP_KEY` et clés VAPID.
   **Priorité la plus haute, et elle ne dépend que de lui.**
6. **Hébergement** : OPcache sur le PHP qui sert les pages, `CACHE_STORE=file`,
   `SESSION_DRIVER=file`. Voir `MISE-A-JOUR-SERVEUR.md`.

7. **Avant le prochain dépôt du CATTC** : `php artisan migrate`
   (`2026_10_07_000001_une_facture_du_ca_retrouve_sa_creance`, une colonne nullable), puis
   **`php artisan factures:doublons`** — lecture seule — et rapporter sa sortie : elle dit
   combien de factures sont déjà en double en ligne, et pour quel montant.

8. **Les banques** : `php artisan migrate` (`2026_10_07_000002_le_releve_de_la_caissiere_entre`,
   une table neuve), puis `php artisan banques:declarer` (constat) et
   `php artisan banques:declarer --appliquer` — BGFI et AFG. Ensuite, déposer les relevés au
   type « **Relevé bancaire — suivi de la caissière** », compte BGFI, dans l'ordre des
   années (2023 → 2026).

9. ~~`php artisan factures:situer`~~ — **appliqué** (12/10) ; ne plus le demander.

10. **Banques et charges (sections 14 et 16)** : `php artisan migrate` passe aussi
    `2026_10_07_000003_un_libelle_s_affecte_a_sa_banque` (table neuve) et
    `2026_10_07_000004_une_charge_se_reprend_de_la_caisse` (une colonne nullable). Aucune ligne
    lue ni écrite. Ensuite, sur `/banques`, **affecter** les libellés non rangés aux banques
    déclarées (« Affecter à ») ; sur `/charges`, **reprendre** les sorties de caisse qui sont
    des charges — ligne à ligne, rien ne se reprend tout seul.

11. **Index du 08/10** : `php artisan migrate` passe aussi `2026_10_08_000001_une_facture_se_retrouve_par_sa_fiche`
    (un index, rien d'autre) — il sert le taux de transformation des devis.

12. **Le 09/10 — d'abord** : `git pull` puis **`php artisan app:deployer`** (migration
    `2026_10_09_000001_un_incident_se_garde`, table neuve). Ensuite **Super admin → Maintenance
    → Incidents** : taper `ERR-XEYTWV` — la fiche dira la vraie cause de la panne de
    `/banques`, et l'encart rouge listera les migrations encore en attente. **Rapporter ce
    qu'elle dit** : la cause « migration non passée » est la plus probable, pas prouvée.
    → **Fait le 09/10 après-midi** : la liste a donné la vraie cause (GROUP BY refusé par MySQL),
    corrigée. Après le prochain `git pull` + `app:deployer` (migration `incidents_regles`), les
    pannes corrigées quittent la liste d'elles-mêmes ; vérifier qu'aucune ne revient.

13. **Le 12/10** : `git pull`, **`php artisan app:deployer`** (il met maintenant aussi la
    configuration en cache : **le relancer après toute modification du `.env`**). Puis
    ~~`php artisan factures:situer`~~ — **déjà appliqué** par le propriétaire.
    **Vitesse** : cocher OPcache dans cPanel et passer `CACHE_STORE=file` — c'est l'essentiel
    des cinq secondes (voir `MISE-A-JOUR-SERVEUR.md`).

14. **Le 12/10, soir — les factures FNE des fournisseurs** : `git pull` puis
    **`php artisan app:deployer`** (migration `2026_10_12_000001_les_factures_fne_des_fournisseurs_entrent` :
    deux tables neuves et deux colonnes nullables sur `reglements_fournisseur` ; aucune ligne lue
    ni écrite). Vérifier que la fiche de l'entreprise porte son **NCC** (c'est le login du portail).
    Quand les accès FNE arrivent : sur un poste avec Node, suivre `SCRAPER-PORTAIL-FNE/CONSIGNES.md`
    (`npm install`, `.env` avec `FNE_URL`, `node achats.js <NCC> <mdp>`), puis déposer le JSON à
    **Fournisseurs › Factures FNE** — ou `php artisan fne:releve-achats` sur le serveur.

15. **Le 13/10 — le relevé FNE tourne seul** : lire `SCRAPER-PORTAIL-FNE/CONSIGNES.md` (six étapes concrètes).
    En bref : `node -v` sur le serveur ; `npm install` dans le dossier ; `.env` du scraper avec **`FNE_URL`**
    (l'adresse de connexion du portail **de production** — PAS `54.247.95.108`, qui est l'essai), `LOGIN` (NCC
    `2197588Q`) et `PASSWORD` ; `node achats.js` à la main ; puis `FNE_SCRAPER_ACTIF=true` dans le `.env` de
    l'application. Le cron `schedule:run` lance ensuite `fne:actualiser` **toutes les heures de 7 h à 19 h, lundi→samedi**
    (35 derniers jours) et **tout l'historique à 2 h 30** ; le bouton **Actualiser depuis la FNE** le fait tout de suite.
    **Le mot de passe du portail a été écrit dans la conversation : ne jamais le committer, le changer si doute.**

16. **Le 13/10 — migrations** (`app:deployer`) : `2026_10_13_000001` (colonne `recue_par_ville_id`, nullable) et
    **`2026_10_13_000002_les_montants_gardent_leurs_centimes`** : 31 colonnes d'argent passent de `bigint` à
    `decimal(18,4)` — valeurs reprises à l'identique, aucune réécrite. **Les lignes déjà importées restent arrondies
    au franc** (l'ancien import l'avait fait) : pour qu'elles retrouvent leurs centimes, **redéposer les fichiers
    d'origine** (CA, impayés, fournisseurs, caisse, relevés). Un redépôt retrouve les lignes d'avant — par leur numéro, ou
    à ±0,5 F près pour celles qui n'ont pas de numéro — et met leurs montants à jour : rien ne se double. Après le
    redépôt du CA, le total de l'écran doit être celui du classeur (1 867 767 452,25 sur le fichier du 12/10).

17. **Le 14/10** : `git pull` puis **`php artisan app:deployer`** (deux tables neuves :
    `doublons_import`, `objectifs_entreprise` + `tranches_objectif` ; rien d'autre n'est lu ni écrit).
    Ensuite : (a) **redéposer le fichier du CA** — FA -8730 apparaîtra dans « Doublons à l'import »
    sur la page Chiffre d'affaires ; si c'est bien deux factures, **Intégrer** : le total devient celui
    du fichier ; (b) **Général › Objectifs mensuels de l'entreprise** : saisir les tranches établies
    et leur période ; (c) le relevé FNE attend toujours **l'adresse du portail de production et le
    mot de passe** dans `SCRAPER-PORTAIL-FNE/.env` (point 15).

18. **Le 15/10** : `git pull` puis `php artisan app:deployer` (migrations `2026_10_15_000001` et
    `000002`, colonnes nullables et une table neuve). Puis **Super admin › Accès › Rattrapage** :
    dire pour chaque accès d'avant s'il est concerné par les objectifs. Pour le relevé FNE :
    réparer `node` (CONSIGNES.md, section « nodevenv »).

19. **Le 20/10** : `git pull` puis `php artisan app:deployer` (migration `2026_10_20_000001` : une
    colonne nullable sur `reglements_fournisseur`). **À confirmer par le propriétaire** : avec « toutes
    les sorties de caisse sont des charges », les **versements en banque** et transferts sortis de la
    caisse comptent aussi en charges (catégorie « Versements & transferts » sur la page Charges) — dire
    s'il faut les écarter. Corriger les deux tâches Cron de la production (espace après `artisan`).

### En attente d'un accord, pas d'un travail

- **Correspondances — quatre choix faits sans confirmation**, chacun réversible en une ligne :
  0. **le gérant et les responsables cochent pour le compte d'un commercial** choisi dans
     « Affecter à » — sans quoi leur case ne compterait la facture à personne ;
  1. **cocher compte aussitôt** la facture au commercial, commission comprise ; la garde est
     l'annulation par un responsable, non une validation préalable ;
  2. **« Les correspondances » est ouvert à tous les responsables** (ville, site,
     commercial), et pas au seul gérant — ce sont eux qui annulent ;
  3. **les avoirs ne sont pas proposés** à la coche : un avoir suit la facture qu'il corrige,
     mais rien ne le porte encore au commercial de celle-ci.

- **Telescope** : le garder en `require-dev` et ne l'enregistrer qu'en local (réponse donnée le
  02/10). Touche `composer.json`, `composer.lock` et `bootstrap/providers.php`, qui portent déjà
  des modifications non commitées du propriétaire. **Ne pas y toucher sans son accord.**

### Côté code, prêt à prendre

- **Accueil du gérant, « Encaissé » et « Trésorerie nette »** (synthèse par atelier) lisent encore
  les règlements, pas `TresorerieReelle` comme la page Trésorerie depuis le 20/10 : à aligner (par
  lieu : journal par ville, relevés en « lieu non précisé »), tests de la synthèse à adapter.
- **Comptabilité › tableau de bord** : ses charges lisent encore la seule table `charges` — à passer
  sur `ChargesDeLaPeriode` comme les autres écrans.

- ~~Tableau de bord du gérant : six agrégats par atelier~~ — **fait le 07/10** (§ 3). Reste
  à **remesurer sur la base locale** : les 48 → 23 viennent de la base de test, le « 30 » du
  02/10 de la base locale, et les deux ne se comparent pas.
- **Accueil du gérant, le bloc suivant : `topCommerciaux`** fait une somme de factures
  **par commercial actif** de la ville (`Facture::where('commercial_id', $c->id)`) — le
  compte grandit avec l'équipe. Même chemin : test contre la boucle d'avant, puis
  `group by commercial_id`. Non mesuré.
- **Dates mal lues à l'import du classeur de caisse** : une ligne datée `31/12/1899` (le zéro
  d'Excel), une autre `15/10/2026`. Le solde n'en dépend plus, mais ces lignes restent mal
  classées dans un filtre de période. Défaut distinct, non corrigé.
- **Affichage des avoirs dans l'état des impayés.** Volontairement laissé de côté : avec zéro
  avoir en base, rien n'y serait vérifiable, et c'est un écran qui porte de l'argent. **À faire
  après le dépôt du point 2.**

---

## 5. Les questions ouvertes, et ce qu'on sait déjà

### 1. L'écart de 652 917 F sur `/caisse` en ligne — non expliqué

L'écran annonce **2 603 mouvements** là où `ETAT_Caisse-ABIDJAN DU 170326 AU 250926.xlsx` en
porte **2 070**. **533 de trop.** C'est la piste, et elle n'a rien à voir avec l'arithmétique du
solde. Deux hypothèses, aucune vérifiable en local :

- deux classeurs déposés se superposent sur la période ;
- un même dépôt a été lu deux fois.

**Depuis le 14/10**, une ligne répétée **dans un même classeur** n'est plus fusionnée : elle paraît
dans « Doublons à l'import » sur `/caisse`. Deux classeurs qui se recouvrent, eux, se reconnaissent
toujours ligne à ligne (chaque ligne du second retrouve la sienne) : ce bloc ne les montre pas.

⚠️ **Piège dans lequel je suis tombé** : la base locale ne contient **que**
`CAISSE DU 01012026 AU 16032026.xlsx`, le classeur **tenu à la main**. Les écarts par feuillet
mesurés (1 000 F, −271 425 F, −2 000 000 F) décrivent **ce fichier-là**, pas celui du logiciel.
**Pour toute correction de caisse, partir du fichier du logiciel**, qui est celui qu'on importe.

### 2. Les avoirs — tranché, deux points de vérification restent

La règle est décidée et testée (`NatureDeLaCreance`). Reste à vérifier **au prochain dépôt**,
pas en théorie :

1. le logiciel sort-il **aussi** la facture d'origine corrigée ? Si oui, prendre les deux
   compterait la correction deux fois. *Mesuré : sur les 43 lignes négatives refusées, 4
   seulement ont une facture positive de même numéro en base.* Les doublons se verront.
2. les **47 lignes « facture d'avoir à établir »** sont des avoirs *annoncés*, pas écrits. Leur
   reste est déjà à zéro. Elles restent des créances ordinaires, et c'est voulu.

---

## 6. Les trois règles métier qu'il faut connaître avant de toucher à l'argent

### Le solde de caisse

**Un cumul, ligne à ligne, dans l'ordre du fichier, à l'intérieur d'un seul feuillet.**

```
solde(1) = solde annoncé sur la première ligne du feuillet   (il comprend déjà son mouvement)
solde(n) = solde(n-1) + ENTRÉE(n) − SORTIE(n)
```

Jamais trier par date. Jamais faire traverser deux feuillets à une chaîne. Une annulation est un
**montant négatif dans la colonne de la pièce annulée** : on l'applique telle quelle. Un solde
négatif n'est pas une erreur — il vient de l'ordre de saisie.

→ **`LE-SOLDE-DE-CAISSE.md`** : la règle complète et ses cinq pièges, écrite pour qui relit un
classeur. `ChaineDeSolde` la porte,
`tests/Feature/LeSoldeDeCaisseEstUnCumulDansLOrdreDuFichierTest.php` la tient.

### Les trois natures de montant négatif

| Nature | Reconnaissance | Ce qu'on en fait |
|---|---|---|
| **Avoir** | `montantTTC < 0` | il entre en négatif et diminue ce que le client doit |
| **Trop-perçu** | TTC ≥ 0 et `reste ≤ −2` | on le **signale** ; la facture est bien due |
| **Arrondi** | `reste = −1` | on l'ignore |

**Le signe décide, jamais le texte.** 47 lignes portent « facture d'avoir à établir » : ce sont
des avoirs *annoncés*. Les ramasser avec les vrais doublerait la correction.

**Pourquoi « le chiffre d'affaires était surestimé ».** Une facture de 19 819 280 F et son avoir
de −19 819 280 F font **0 F** de chiffre d'affaires. L'import gardait la facture et jetait
l'avoir : il affichait 19 819 280 F pour une affaire qui n'a rien rapporté. Sur l'ensemble,
≈ 47 millions de trop. Les trop-perçus, eux, **ne touchent pas** le chiffre d'affaires.

→ `NatureDeLaCreance`, `tests/Unit/TroisNaturesDeNegatifNeSeConfondentPasTest.php`,
et la section « Les avoirs » d'`ETAT-DES-IMPAYES.md`.

### Le périmètre d'une ligne

L'atelier s'il est connu, **sinon la ville**, sinon la ligne paraît partout — on ne sait pas où
elle est, et la cacher reviendrait à la perdre. Écrit une seule fois dans
`EtatDesImpayes::dansLePerimetre()` et `PerimetreDeTresorerie`.

---

## 7. Les pièges qui ont mordu, et qui mordront encore

Chacun a coûté une séance. Ils ne sont pas théoriques.

| Piège | Ce qui se passe |
|---|---|
| **`whereIn('site_id', …)` ignore les NULL** | **Cinq écrans** touchés — le dernier, l'accueil du gérant, le 07/10 : tout à 0 F après import. 7 627 des 7 714 encaissements n'ont pas d'atelier : `/tresorerie` montrait 35 M au lieu de 5,5 Md. Toujours prévoir le `orWhereNull`, ou passer par le service de périmètre |
| **Une colonne `unsigned` ne peut pas porter de négatif** | A bloqué les avoirs trois semaines, et l'on accusait l'import |
| **Trier un cumul par date** | Une date mal lue déplace sa ligne d'un bout à l'autre du fichier |
| **Un style en ligne bat une feuille de style** | `style="display:flex"` sur le `<nav>` : la règle CSS était écrite, lue, sans effet |
| **`@once` ne déduplique pas** dans un composant Blade anonyme utilisé deux fois | Deux écouteurs sur `document`, chaque clic basculait puis rebasculait |
| **Une directive `@` dans un commentaire JavaScript est compilée par Blade** | Seul `{{-- --}}` met un `@` à l'abri. Trois pages en maintenance |
| **Livewire retire d'une racine de composant les classes que le serveur n'a pas rendues** | Poser les classes d'état sur `document.documentElement` |
| **`display:none` sur un enfant de grille** | Le reste tombe dans la première cellule, ramenée à zéro. `/import` s'affichait sur 160 px |
| **Un `computed` Volt est relu à chaque rendu** | 21 `count(*)` relus 5 à 6 fois = **113 requêtes par clic** sur la maintenance |
| **MySQL compare `''` à `0`** | « non renseigné » sur un booléen ramenait aussi les lignes à faux. D'où `videEstNull` |
| **SQLite est sensible aux accents, MySQL non** | Ne jamais faire de `LIKE` sur du texte accentué ; `whereIn` sur les valeurs du relevé |
| **`@props(['reference-offerte'])` ne crée aucune variable** | Les props sont en camelCase |
| **`.champ { width: 100% }`** casse une rangée de filtres en flex | Mettre `style="width:auto"` |
| **`wire:navigate` remonte toujours en haut** | Il écrase une ancre `#…` ; passer par `scrollIntoView` |
| **`Site::visiblesPour()` ne rend rien au simple commercial** | `PerimetreSites` lui donne un périmètre vide : il ne verrait que les lignes sans ville. Ajouter la ville de son compte et de sa fiche (`CorrespondancesDeFactures::perimetre()`) |
| **Deux suites de tests lancées en même temps** | Elles se marchent dessus sur les fichiers temporaires et donnent de faux échecs |
| **SQLite (tests) accepte ce que MySQL (serveur) refuse** : une sous-requête corrélée dans un `sum()` groupé (ERR-XEYTWV) ; une chaîne plus longue que sa colonne `varchar` | Calculer ligne à ligne dans une sous-requête, regrouper par-dessus (`fromSub`) ; borner la longueur dans le code et la tester explicitement |
| **Le code d'un écran lit une table neuve avant que le serveur ne l'ait** (ERR-XEYTWV, 09/10) | Une nouveauté qui dépend d'une migration récente passe par `App\Support\SchemaDisponible` ; et `app:deployer` après chaque `git pull` |
| **Ne jamais arrondir un montant** (13/10, règle du propriétaire : « tel que donné ») | Les colonnes d'argent sont des `decimal(18,4)`. **Jamais `(int)`, `round()`, `number_format(…, 0)` ni `intdiv` sur un montant ou un total** : `montant_de()` pour lire (entier si entier, flottant sinon — MySQL rend les décimaux et leurs `sum()` **en chaînes**), `ae()` / `montant_nombre()` pour écrire, la cast `App\Casts\Montant` + le trait `CompareLesMontants` sur les modèles. Un type `int` sur un paramètre d'argent est un arrondi caché. Seules exceptions voulues : les **clés de rapprochement** (`PontDesFactures`, banque ↔ règlement, clé du relevé bancaire, clé synthétique des impayés) qui restent calculées au franc pour retrouver les lignes d'avant ; les commissions et objectifs (calculés, jamais importés) ; les pourcentages |
| **La table `factures` mêle les ventes et les créances** (12/10) | Tout KPI de chiffre d'affaires passe par `Facture::scopeDuChiffreDAffaires()` ; une créance de l'état non retrouvée au CATTC n'est pas une vente |
| **Une méthode d'import peut exister sans être appelée** (`siteConnuDe`, 12/10) | Vérifier que la cascade de rattachement la lit (`dossierExistant()`) — un test qui appelle la méthode seule ne le prouve pas |
| **Une ligne de la base ne sert qu'une fois par dépôt** (14/10, revu 15/10) | Un import qui « retrouve » sa ligne doit passer par `Format::premiereLibre()` (et `tenir()` ce qu'il crée) : un `->first()` nu réécrit la ligne précédente quand le fichier se répète, et l'argent disparaît sans trace. `ecrire()` rend `DOUBLON` ; `traiterLeDoublon()` l'écrit quand même (clé unique suffixée `#2` par `variante()`, retrouvée par `avecSesVariantes()`) et la consigne à trancher — sauf décision passée de la retirer |
| **Un composant Livewire posé dans une page** (14/10) | Pas de propriétés `reactive()` (« Cannot mutate reactive prop ») : la page le pose avec un `wire:key` fait de ses filtres, il se remonte. Un test de la page ne rend pas l'enfant : `assertSeeLivewire`, puis tester le composant seul |
| **Un devis importé est toujours « En attente »**, un règlement importé n'a pas d'activité | Ne jamais compter « Validé » seul : passer par `TransformationDesDevis` ; ventiler un règlement par `Encaissement::ACTIVITE_SQL` (celle de sa facture) |

---

## 8. Où chercher le détail

| Question | Fichier |
|---|---|
| L'histoire d'une décision, séance par séance | `ETAT-DES-LIEUX.md` (§ 4 chronologie, § 7 journal) |
| Le chantier des impayés, les avoirs, les chiffres de référence | `ETAT-DES-IMPAYES.md` |
| Le solde de caisse et ses pièges | `LE-SOLDE-DE-CAISSE.md` |
| La structure du code, les modules | `ARCHITECTURE.md` |
| Déployer, les réglages serveur | `MISE-A-JOUR-SERVEUR.md`, `ENVIRONNEMENT-DE-DEV.md` |
| Les comptes de test | `ACCES_COMPTES.md` |
| Présenter l'application à un tiers (imports, charges) | `PRESENTATION-360.md` |

---

## 9. Démarrer une séance

```bash
cd C:/laragon/www/GESTION-DE-SITES
git status && git branch --show-current && git log --oneline -5
php artisan migrate:status | grep -i pending
php artisan test            # ~8 min ; 7 erreurs WebPush attendues
```

**À la fin de chaque séance** : mettre à jour les §§ 4, 5 et 6 d'`ETAT-DES-LIEUX.md` **et ce
fichier**, puis commiter avec le travail, pousser la branche, la fusionner dans `main` et pousser `main` (§ 2, règle 4).

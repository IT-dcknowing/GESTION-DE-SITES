# Plan général — les trois applications (GESTION-DE-SITES, COMPTAFLOW, SELFLOW)

*Établi le 24 novembre 2026. Tient ensemble ce qui a été dit, ce qui est fait et ce qui reste, jusqu'aux
plus petits points. REPRENDRE-ICI.md reste le point d'entrée de GESTION-DE-SITES ; ce fichier-ci est la
vue d'ensemble des trois projets.*

**Ordre de travail arrêté par le propriétaire (24/11)** : 1) le **rapport**, IA comprise, bouclé
entièrement ; 2) les **écritures comptables**, bouclées entièrement ; 3) la suite, sujet par sujet.

**Règles permanentes** : rien de sécurisé seulement côté navigateur — tout se contrôle au serveur ;
ne jamais arrondir un montant ; migrations additives ; aucune écriture de données sans constat ;
**rien n'est appliqué sur les numéros de fiche sans l'accord du propriétaire** (24/11) ; une branche
par sujet, fusion dans `main`, poussée sur les **deux** dépôts de chaque projet ; FNE de SELFLOW gelée.

---

## 1. Ce qui est fait

### Tous projets
- [x] Analyse des trois applications (rôles, modules, liens, routes en double, failles, performance).
- [x] Dépôts jumeaux alignés : GESTION-DE-SITES (IT réaligné sur mele par poussée forcée autorisée,
      le commit au dump de production n'est plus sur `main`), COMPTAFLOW (guysergekouassi mis à jour),
      SELFLOW (identiques).

### COMPTAFLOW
- [x] Liaison RH-FLOW : l'échange du code de liaison n'est plus refusé à tort (reprise 15 min,
      code d'application comparé en slug, code collé nettoyé, raison du refus journalisée).
- [x] La fiche RH-FLOW « pending » créée d'office ne revient plus ; migration qui la retire.

### GESTION-DE-SITES
- [x] Module Comptabilité : plan 6 chiffres ; 411000/401000 + numéro de tiers (410001…, 400001…) et
      tiers divers ; caisse et journal par ville ; compte et journal par banque ; moteur d'écritures
      (charges d'abord sans doublon, ventes du CA, encaissements, règlements) ; TVA lue sur la facture ;
      aucun arrondi ; contre-passation ; IA d'imputation (propose → validation humaine) ; déversement
      COMPTAFLOW ; écran `/ecritures-comptables` ; commande `comptabilite:generer` ; tâche de fond.
- [x] Migration des écritures réparée (nom d'index trop long, MySQL 1059) et rejouable.
- [x] Une source effacée voit son écriture retirée (jamais envoyée) ou contre-passée (envoyée).
- [x] Rapport lisible : onglets fixés, sommaire des sections, deux colonnes, montants sans arrondi.
- [x] Rapport, onglet Commerciaux : prospections saisies / transmises / passées en devis / en attente
      du devis ; devis émis / transformés / facturés / en attente ; factures, CA, taux.
- [x] Ville d'une facture par le numéro de sa fiche de réception, **mêmes lettres exigées** (chaque
      ville a son compteur) ; `factures:situer --details` en constat seulement.
- [x] Avoirs : reste dû = montant + avoirs rattachés − encaissements, une règle pour l'état des
      impayés et le recouvrement ; une facture avoirée sort du recouvrement (client et tiers).
- [x] Fournisseurs : bouton Supprimer pour les pièces **importées** (jamais une FNE normalisée).
- [x] Super admin, écran Intelligence artificielle : « Undefined variable $usages » corrigé.

---

## 2. À faire sur les serveurs (par le propriétaire)

- [ ] **GESTION-DE-SITES** : `git pull` puis `php artisan migrate` (écritures comptables).
- [ ] **GESTION-DE-SITES** : `php artisan queue:work` en permanence (génération et déversement).
- [ ] **GESTION-DE-SITES** : `php artisan factures:situer --details` — **constat seulement** ; ne pas
      lancer `--appliquer` avant accord.
- [ ] **COMPTAFLOW** : déployer `main` puis `php artisan migrate` (fiche RH-FLOW « pending »).
- [ ] **RH-FLOW** : recoller le code de liaison actuel de COMPTAFLOW.
- [ ] Réglages : OPcache, `php artisan optimize` après chaque déploiement, `CACHE_STORE=file`,
      cron `schedule:run` (SELFLOW), Telescope coupé en production (COMPTAFLOW).

## 3. Sécurité urgente (propriétaire)

- [ ] Révoquer la **clé Google API (Gemini)** en clair dans 6 fichiers de COMPTAFLOW.
- [ ] Demander à GitHub la **purge** du commit au dump de production (IT-dcknowing/GESTION-DE-SITES,
      `b61ef72`) et **changer les mots de passe** concernés (47 comptes).
- [ ] Révoquer l'ancien secret de passerelle SELFLOW ↔ COMPTAFLOW présent dans l'historique.
- [ ] Les dépôts sont **publics** : vérifier qu'aucun autre secret n'y reste (ACCES*.txt de
      GESTION-DE-SITES, `create_superadmin.php` et dumps SQL de COMPTAFLOW, jeton dans
      `CONFIGURATION FNE` de SELFLOW).

---

## 4. Étape 1 — Le rapport, IA comprise (en cours de priorité)

Décision du 24/11 : **pas de saisie obligatoire**. Champs de saisie présents mais facultatifs ; la voie
principale est **le dépôt de fichiers, que l'IA lit et analyse**.

1. [ ] **Période** : semaine (du jeudi au mercredi, comme l'Excel), mois, dates libres ; filtres ville
       et atelier ; tous les onglets lisent la même période.
2. [ ] **Situation de trésorerie hebdomadaire** (modèle « RAPPORT TRESORERIE ») : caisses Abidjan,
       San Pédro, Bouaké + synthèse ; banques BGFI, AFG + synthèse ; solde initial, encaissements
       (clients, espèces à la banque, approvisionnements gérant / retrait chèque / entre sites,
       autres), décaissements (carrosserie et peinture, mécanique, gérant, entre sites, versements,
       retraits, autres, impôts et CNPS, échéance ALIOS, salaires, frais bancaires), solde final ;
       chèques déposés / en circulation, provision des salaires, remboursement du gérant → **écart à
       couvrir** ; contrôle solde calculé / solde annoncé. Ce que l'application ignore vient du
       **fichier déposé** de la semaine.
3. [ ] **Synthèse mensuelle** = cumul des semaines.
4. [ ] **Fournisseurs à régler par catégorie** (concessionnaires, peinture, pièces mécaniques, frais
       généraux, carrosserie) : facturé, réglé, reste, ancienneté.
5. [ ] **Engagements fiscaux et sociaux** : avis DGI et appels CNPS **déposés**, lus par l'IA (droits,
       pénalités, frais, échéance — ex. dernier avis de fermeture du 03/11 : 174 861 255 F sous 72 h) ;
       alerte quand le délai court.
6. [ ] **Synthèse dirigeant** d'une page ; **indicateurs** (délai client, délai fournisseur, semaines
       de couverture, charges/CA) ; **alertes par règles** (caisse sous un seuil, banque négative,
       échéance fiscale).
7. [ ] **Exports** Excel (mise en forme du modèle), PDF, PowerPoint — faits par le serveur.
8. [ ] **IA du rapport** :
   - [ ] analyses en **tâche de fond** (aujourd'hui dans la requête : risque d'erreur 504) ;
   - [ ] outils génériques en lecture seule (agrégats, détail, dictionnaire), périmètre recalculé au
         serveur, arguments en liste blanche ;
   - [ ] analyse de chaque **dépôt** (trésorerie, avis DGI, CNPS) et de la période ;
   - [ ] **cloisonnement** de ce qu'elle produit par périmètre (`productions_ia`) ; « revenir à l'état du
         système » limité au périmètre ; `/rapport/document/{id}` filtré ;
   - [ ] pas de noms de clients envoyés sans nécessité ; données des outils signalées comme données
         (injection de consignes) ; écriture (`deposer_piece`) confirmée dans l'interface ;
   - [ ] **journal des appels** (jetons, durée, coût) et **quotas** par entreprise au Super admin ;
         cache de prompt ; lecture de `stop_reason` ; `max_tokens` adaptés ;
   - [ ] montants des pièces déposées sans arrondi (`PieceRapport`, `saisir`, `deposer_piece`) ;
   - [ ] `retirerLesPropositions` revérifie le droit ; `confirmerLesPropositions` ne fait pas confiance
         à l'état du navigateur.
9. [ ] Données bancaires et encaissement des 4 dernières semaines lus au **périmètre** (aujourd'hui
       toute l'entreprise).
10. [ ] Trancher : le CA de l'onglet Commerciaux (et de la page Commerciaux) doit-il passer par
        `duChiffreDAffaires` (piège 18) ?
11. [ ] Réparer les 2 épreuves du rapport en échec (`L_assistant_du_rapport_Test`,
        `LeModuleRapportEtLaFneDirecteTest`).
12. [ ] Option : **connecteur MCP** de GESTION-DE-SITES (lecture, et actions sûres confirmées) pour
        travailler depuis Claude sur l'abonnement ; l'assistant intégré reste sur clé API.

## 5. Étape 2 — Les écritures comptables, bouclées

1. [ ] Validation du plan proposé par le comptable (706100/706200, 443200, caisses, codes banque).
2. [ ] Lecture de la TVA des ventes « à vérifier » ; banque des virements sans banque.
3. [ ] Configurer l'IA « Imputations » au Super admin, puis valider ses propositions.
4. [ ] Écrire les **relevés bancaires** (`mouvements_bancaires`) avec rapprochement, sans doubler les
       encaissements ; recettes de caisse autres que les approvisionnements ; créances douteuses (416) ;
       décaissement du gérant ; virements 585 rapprochés des deux côtés.
5. [ ] Lettrage client / fournisseur ; export TXT ANSI 9 colonnes + plan, tiers, journaux (guide § 8).
6. [ ] **COMPTAFLOW** : `ecritures/deverser` doit refuser une opération déséquilibrée, une date hors
       exercice et un exercice clos ; une ligne ne porte qu'un compte.
7. [ ] **Liaison COMPTA-APK** pour GESTION-DE-SITES (code de liaison → identifiants → jeton Bearer) ;
       corriger côté COMPTAFLOW l'origine `strtoupper($app->code)` qui ne vaut pas `GESTION_DE_SITES`.
8. [ ] Une seule liaison dans COMPTAFLOW (écran SELFLOW historique et console COMPTA-APK fusionnés) ;
       retirer le secret global `EXTERNAL_SYNC_SECRET`.
9. [ ] Pièce justificative PDF envoyée après acceptation (comme SELFLOW).

## 6. Étape 3 — Le reste, par sujet

### Factures et villes (rien d'appliqué sans accord)
- [ ] Étudier les ~33 factures signalées par le propriétaire (numéros de fiche) — vérification seule.
- [ ] FA -7558 et FA -8030 : la base dit Abidjan, lettres et numéro disent Bouaké / San Pédro.
- [ ] Donner une ville aux codes YB, YK, KR, KZ sur l'écran des codes.
- [ ] 3 765 factures de l'état des impayés sans n° de fiche : comment les situer ?
- [ ] Questions : format `FR-XXN° 0nnnnn` constant ? qui fait foi si code et numéro divergent ?

### Recouvrement et impayés
- [ ] 44 avoirs du classeur sans facture désignée : les rattacher ?
- [ ] Trop-perçu (facture payée puis avoirée) : le signaler ?
- [ ] Vue par **client** au recouvrement (l'état des impayés est par client, le recouvrement par
      tiers payant) ?

### Fournisseurs
- [ ] Une pièce importée supprimée revient au redépôt si sa ligne est dans le fichier : garder la
      liste des lignes supprimées ? ; `doublons_import.retenue_id` vers une pièce supprimée.

### Sécurité GESTION-DE-SITES
- [ ] `/inscription` et `/rejoindre` publics : création de comptes actifs dans une entreprise existante.
- [ ] Super admin secondaire : peut désactiver / changer le mot de passe du fondateur (`acces-liste`).
- [ ] Identifiants Livewire non verrouillés (`prospection-voir`, `acces-modifier`, `fournisseur-fne-detail`,
      `import/rejets`, `import/tracabilite`) ; middleware non rejoué sur les appels Livewire.
- [ ] Encaissements / décaissements : rôle revérifié dans l'action.
- [ ] `ACCES*.txt`, `database/exports/*.sql` hors du dépôt.

### Sécurité COMPTAFLOW
- [ ] `POST /api/v1/agent/execute` sans authentification ; 18 scripts PHP exécutables dans `public/` ;
      105 PDF comptables publics ; `X-Company-Id` cru tel quel (API mobile) ;
      `exercices/by-email/{email}` public ; repli `super_secret_123` de `VerifyHubToken` ;
      `list-companies` / `company-info` sous secret global ; `TenantScope` permissif ; `/dashboard`
      hors `auth`.

### Sécurité SELFLOW
- [ ] `X-Point-De-Vente-Id` non vérifié (API) ; `api/admin` sans habilitation ni abonnement ;
      `PersonnelControleur` : élévation de rôle ; repli `HUB_TOKEN` ; créances sans filtre entreprise ;
      `register-enterprise` sous secret partagé ; `caissier.stock` sans `modules:stock` ;
      `configurer_bearer` en 403 ; XSS de `vente.blade.php` (**périmètre FNE gelé : accord requis**).

### Routes en double, code mort, nettoyage
- [ ] COMPTAFLOW : `api.php` recopie `api_hub.php` (`route:cache` échoue) ; `storeMultiple` sur 4 URI ;
      routes de test ; ~8 contrôleurs morts ; vues `.OLD` ; ~400 fichiers parasites à la racine ;
      `match` sans branche dans `AccountingController`.
- [ ] SELFLOW : `admin.ventes.*` / `caissier.ventes.*` ; groupes `fne`, `comptabilite`, `abonnement` en
      double ; deux middlewares Hub ; logique de vente dupliquée dans l'API ; code mort.
- [ ] GESTION-DE-SITES : `Modules/Encadrement` inexistant cité ; annuaire en double ; deux gestions
      des accès ; deux fiches de prospection ; trois espaces personnels.
- [ ] Un seul scraper du portail FNE pour SELFLOW et GESTION-DE-SITES.

### Performance
- [ ] COMPTAFLOW : index composites sur `ecriture_comptables` ; N+1 (`listCompanies`, déversement…).
- [ ] SELFLOW : `entreprise_id` sur `ventes` (84 sous-requêtes) ; `JournaliserVisite` en différé ;
      clés de liaison par empreinte.
- [ ] GESTION-DE-SITES : N+1 du tableau de bord du gérant ; 69 paginations en mémoire ; requêtes dans
      la vue de liaison ; filtre `upper(trim())` ; styles en ligne avant la refonte.

### Qualité
- [ ] Épreuves déjà en échec : 30 (GESTION-DE-SITES), 16 (SELFLOW), 27 (COMPTAFLOW, schéma de test).
- [ ] Journal de bord de SELFLOW à rattraper (~575 commits non consignés).
- [ ] Refonte du design, après stabilisation.

---

*À tenir à jour à chaque lot : cocher, dater, et reporter le détail dans REPRENDRE-ICI.md.*

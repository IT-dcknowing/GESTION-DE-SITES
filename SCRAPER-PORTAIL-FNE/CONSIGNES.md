# Le relevé des achats FNE — ce qu'il fait, et ce que vous avez à faire

## Ce qu'il fait — et seulement cela

Un script Node (`achats.js`, repris de Selflow) ouvre **une session sur le portail FNE avec le compte
de l'entreprise** (son NCC) et lit la liste « Reçus et factures réceptionnés » : **les factures et
avoirs que des tiers ont émis à ce NCC**. Rien d'autre — pas les factures que nous émettons, pas le
paramétrage, pas la file de demandes de Selflow (qui servait plusieurs entreprises ; ici il n'y en a
qu'une). Il dépose un fichier `storage/app/portail-fne/achats/<NCC>_<AAAAMMJJ>.json` et ne touche
à aucune table.

L'application range ensuite ce fichier : chaque pièce **dans son exercice**, les avoirs en négatif,
rapprochée du suivi fournisseurs ou « à porter » — voir Fournisseurs › **Factures FNE**.

## À faire, dans l'ordre

1. **Vérifier que Node existe sur le serveur** (cPanel › Terminal ou SSH) : `node -v` — il en faut
   la version 18 ou plus. S'il n'y en est pas, voir le point « Sans Node sur le serveur » plus bas.
2. `cd SCRAPER-PORTAIL-FNE && npm install` (installe Playwright ; puis `npx playwright install chromium`
   si le navigateur n'est pas déjà présent).
3. `cp .env.exemple .env` puis remplir **quatre lignes** :
   - `FNE_URL` : l'adresse de la page de connexion du portail, **celle que vous ouvrez dans votre
     navigateur avec vos accès** (pas `54.247.95.108` : c'est l'environnement d'essai de la DGI) ;
   - `LOGIN` : le NCC de l'entreprise ; `PASSWORD` : son mot de passe du portail.
   Le fichier `.env` n'est **jamais** versionné. Ne mettez pas ces accès ailleurs que dans ce fichier.
4. **Essayer à la main** : `node achats.js` (sans argument : il prend le compte du `.env`). Il doit
   afficher « N facture(s) reçue(s) … » et écrire le fichier. Si le portail a changé d'écran, il
   s'arrête en le disant, avec une capture dans `erreurs/` — rien n'est déposé.
5. Dans le `.env` **de l'application** : `FNE_SCRAPER_ACTIF=true` (et `FNE_NODE=/chemin/complet/vers/node`
   si le cron ne trouve pas `node`). Puis `php artisan config:clear` (ou `app:deployer`).
6. Sur l'écran **Fournisseurs › Factures FNE**, cliquer **Actualiser depuis la FNE** : le premier
   relevé est complet (depuis 2023) ; la page s'actualise seule à l'arrivée du fichier.

À partir de là le planificateur (le cron `schedule:run` déjà posé sur le serveur) relève **toutes les
heures de 7 h à 19 h, du lundi au samedi** (derniers 35 jours), et **tout l'historique à 2 h 30**.
Le bouton **Actualiser** fait la même chose tout de suite.

## Pourquoi pas « toutes les heures, jour et nuit »

Chaque passage est une **connexion avec le mot de passe de l'entreprise sur le portail de l'État**
(un vrai navigateur, une à deux minutes). Un fournisseur ne certifie pas de facture la nuit ni le
dimanche : 24 connexions par jour n'apporteraient pas une facture de plus, et multiplieraient le
risque d'un blocage du compte. Le rythme horaire aux heures ouvrées donne des données à moins d'une
heure ; le bouton couvre l'urgence. Les heures se règlent (`FNE_HEURE_DEBUT`, `FNE_HEURE_FIN`).

## Sans Node sur le serveur

Faire tourner le relevé sur un poste (Windows ou autre) qui a Node, avec les mêmes étapes 2 à 4, puis
déposer le fichier `…/storage/app/portail-fne/achats/<NCC>_<date>.json` à l'écran Fournisseurs ›
Factures FNE (« Lire le relevé »). Le bouton Actualiser ne peut alors pas lancer le relevé : il le dit.

## Si ça ne marche pas

- le journal du scraper : `storage/logs/fne-scraper.log` ;
- `node achats.js --reconnaissance` : explore le portail et rapporte, sans rien déposer ;
- `php artisan fne:releve-achats` : range à la main les fichiers déjà déposés.

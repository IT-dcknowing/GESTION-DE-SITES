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
   la version 18 ou plus. Si la commande répète « nodevenv/…/activate: No such file or directory »,
   voir le point qui porte ce titre plus bas ; s'il n'y a pas de Node du tout, « Sans Node sur le serveur ».
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

## Si `node -v` répète « nodevenv/SCRAPER-PORTAIL-FNE/18/bin/activate: No such file or directory » (15/10)

C'est ce qu'a affiché le serveur le 15/10. **Ce n'est pas Node qui manque : c'est un raccourci cassé.**
`~/bin/node` est un petit script que cPanel (« Setup Node.js App ») a posé pour une application nommée
`SCRAPER-PORTAIL-FNE` en Node 18. Il commence par charger l'environnement de cette application
(`~/nodevenv/SCRAPER-PORTAIL-FNE/18/bin/activate`) — qui n'existe pas, ou plus — puis rappelle `node`,
c'est-à-dire **lui-même** : d'où la même ligne répétée.

Réparer, sans rien installer (le Node de l'hébergeur est déjà là, sous `/opt/alt`) :

```bash
# 1. Mettre de côté les raccourcis cassés (on ne supprime rien)
mv ~/bin/node ~/bin/node.casse
[ -e ~/bin/npm ] && mv ~/bin/npm ~/bin/npm.casse
[ -e ~/bin/npx ] && mv ~/bin/npx ~/bin/npx.casse

# 2. Voir quelles versions l'hébergeur fournit, et prendre la plus récente (20 ou 18)
ls -d /opt/alt/alt-nodejs*/root/usr/bin/node

# 3. Vérifier — remplacer 20 par le numéro trouvé à l'étape 2
/opt/alt/alt-nodejs20/root/usr/bin/node -v

# 4. Installer le scraper avec ce Node-là
cd ~/<dossier-du-site>/SCRAPER-PORTAIL-FNE
/opt/alt/alt-nodejs20/root/usr/bin/npm install
PATH=/opt/alt/alt-nodejs20/root/usr/bin:$PATH npx playwright install chromium
```

Puis, dans le `.env` **de l'application** : `FNE_NODE=/opt/alt/alt-nodejs20/root/usr/bin/node` (chemin
complet : le cron n'a pas le PATH du terminal), et `php artisan app:deployer`. L'essai à la main de
l'étape 4 se fait avec le même chemin : `/opt/alt/alt-nodejs20/root/usr/bin/node achats.js`.

Si `ls -d /opt/alt/alt-nodejs*` ne rend rien : dans cPanel › **Setup Node.js App**, **créer**
l'application (version 18 ou 20, *Application root* = le dossier `SCRAPER-PORTAIL-FNE`, *startup file*
= `app.js` — un fichier qui n'existe pas suffit, ce n'est pas un site), puis copier la commande
`source …/activate` que cPanel affiche en haut de la page et la lancer avant `node -v`.

**À savoir avant d'insister** : le scraper ouvre un vrai navigateur (Chromium). Sur un hébergement
mutualisé, il arrive qu'il refuse de démarrer faute de bibliothèques système (message « error while
loading shared libraries », ou « Host system is missing dependencies »). Ce n'est pas réparable sans
accès administrateur : passer alors au point suivant — le relevé se lance depuis un PC.

## Si Chromium s'arrête : « error while loading shared libraries » (16/10)

Constaté le 16/10 sur l'hébergement (`libatk-bridge-2.0.so.0`). `npx playwright install-deps` ne
marche pas : il faut être administrateur. À la place, depuis le dossier `SCRAPER-PORTAIL-FNE` :

```bash
bash bibliotheques-chromium.sh
```

Le script télécharge les bibliothèques depuis les dépôts du serveur, les pose dans `~/fne-libs` (rien
n'est installé dans le système) et vérifie. S'il finit par « C'EST BON », copier la ligne `FNE_LIBS=…`
qu'il affiche dans `SCRAPER-PORTAIL-FNE/.env`, puis relancer le relevé. Sinon, envoyer tout le texte.

## Sans Node sur le serveur

Faire tourner le relevé sur un poste (Windows ou autre) qui a Node, avec les mêmes étapes 2 à 4, puis
déposer le fichier `…/storage/app/portail-fne/achats/<NCC>_<date>.json` à l'écran Fournisseurs ›
Factures FNE (« Lire le relevé »). Le bouton Actualiser ne peut alors pas lancer le relevé : il le dit.

## Si ça ne marche pas

- le journal du scraper : `storage/logs/fne-scraper.log` ;
- `node achats.js --reconnaissance` : explore le portail et rapporte, sans rien déposer ;
- `php artisan fne:releve-achats` : range à la main les fichiers déjà déposés.

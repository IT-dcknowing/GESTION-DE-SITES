# Le relevé des factures FNE — guide complet, du début à la mise en production

*Écrit le 16/10/2026, après la première installation réussie sur `gestion-dev` (2 728 factures
relevées). À garder : il sert aussi de modèle pour tout autre scraping.*

---

## 1. Ce que fait le relevé, en une image

```
Portail FNE de la DGI            Serveur (votre compte cPanel)                     Application
(services.fne.dgi.gouv.ci)
        │                                                                          
        │  1. achats.js ouvre un navigateur invisible (Chromium),                  
        │     se connecte avec le NCC et le mot de passe,                          
        │     lit « Factures reçues » (factures ET avoirs émis à votre NCC)        
        ▼                                                                          
                         2. il écrit un fichier :                                 
                            storage/app/portail-fne/achats/2197588Q_20261016.json  
                                         │                                        
                                         │ 3. l'application range ce fichier      
                                         │    (php artisan fne:releve-achats,      
                                         │     ou le bouton « Actualiser »)        
                                         ▼                                        
                                                         tables releves_fne et factures_fne
                                                         → Fournisseurs › Factures FNE
                                                         → carte FNE de la page Charges
```

**Deux étapes distinctes, et c'est voulu :**

1. **Relever** (`achats.js`) : ne touche à aucune table. Il produit un fichier.
2. **Ranger** (`fne:releve-achats` ou le bouton) : lit le fichier et remplit les tables FNE. Un
   fichier déjà rangé n'est jamais rangé deux fois (empreinte).

Ni l'un ni l'autre ne crée de dette ni de règlement : **porter** une facture au suivi
fournisseurs et la **régler** restent des gestes faits à l'écran.

---

## 2. Les fichiers, et à quoi sert chacun

### Dans le dossier `SCRAPER-PORTAIL-FNE/`

| Fichier | Rôle | Versionné (GitHub) ? | Vous y touchez ? |
|---|---|---|---|
| `achats.js` | Le relevé : connexion, lecture des factures reçues, écriture du fichier JSON | oui | non |
| `fne.js` | Les outils communs (connexion au portail, dossier de dépôt, journal) | oui | non |
| `package.json`, `package-lock.json` | La liste des outils Node à installer (Playwright, dotenv) | oui | non |
| `.env.exemple` | **Modèle** du fichier de réglages, sans aucun secret | oui | **jamais** de mot de passe dedans |
| **`.env`** | **Vos réglages réels** : adresse du portail, NCC, mot de passe, `FNE_LIBS` | **non** (ignoré) | **oui** — c'est ici |
| `bibliotheques-chromium.sh` | Diagnostic : dit quelles bibliothèques manquent à Chromium | oui | à lancer en cas de souci |
| `CONSIGNES.md` | Les consignes courtes, et le dépannage | oui | à lire |
| `GUIDE-COMPLET.md` | Ce guide | oui | à lire |
| `node_modules/` | Les outils installés par `npm install` | **non** | non (se recrée) |
| `erreurs/` | Captures d'écran quand le portail a changé ou refusé | non | à regarder en cas d'échec |

### Le contenu exact de `SCRAPER-PORTAIL-FNE/.env`

```
FNE_URL=https://www.services.fne.dgi.gouv.ci/fr/login
LOGIN=2197588Q
PASSWORD=le mot de passe du portail
FNE_HEADLESS=true
ACHATS_DEPUIS=2023-01-01
UPLOAD_PATH_FOLDER=
FNE_LIBS=/home/cp2255957p00/local-libs/usr/lib64:/home/cp2255957p00/local-libs/lib64
```

| Ligne | Ce qu'elle dit |
|---|---|
| `FNE_URL` | la page de connexion du portail, **terminée par `/fr/login`**. Essai DGI : `http://54.247.95.108/fr/login` ; vrai portail : `https://www.services.fne.dgi.gouv.ci/fr/login` |
| `LOGIN` | le **NCC** de l'entreprise (2197588Q — attention à la faute de frappe 21975888Q déjà rencontrée) |
| `PASSWORD` | le mot de passe du portail |
| `FNE_HEADLESS` | `true` = navigateur invisible (toujours sur un serveur) |
| `ACHATS_DEPUIS` | date de début du relevé complet |
| `UPLOAD_PATH_FOLDER` | laisser **vide** : le fichier va alors dans `storage/app/portail-fne/achats` de l'application |
| `FNE_LIBS` | où trouver les bibliothèques qui manquent au serveur (voir § 4, étape 5) |

### Dans l'application (dossier `GESTION-DE-SITES/`)

| Élément | Rôle |
|---|---|
| `.env` de l'application | `FNE_SCRAPER_ACTIF`, `FNE_NODE` (et, au besoin, les horaires) — voir § 6 |
| `config/fne.php` | lit ces réglages ; heures par défaut : 7 h–19 h, complet à 2 h 30, 35 jours |
| `routes/console.php` | programme `fne:actualiser` toutes les heures (lun→sam) et le complet la nuit |
| `php artisan fne:actualiser` | lance `achats.js` **puis** range le fichier (ce que fait le bouton) |
| `php artisan fne:releve-achats` | range seulement les fichiers déjà déposés |
| `storage/app/portail-fne/achats/` | les fichiers JSON déposés par le relevé |
| `storage/logs/fne-scraper.log` | le journal du relevé |
| **Fournisseurs › Factures FNE** | l'écran : les pièces, « Actualiser depuis la FNE », « Lire le relevé », « Porter au suivi » |
| **Charges** | la carte « Achats normalisés — factures fournisseurs FNE » (à côté des charges, jamais additionnée) |
| **Gérant › Paramètres** | le **NCC** de l'entreprise : c'est par lui que le fichier retrouve son entreprise |

---

## 3. Où se rendre, concrètement

| Pour… | Aller dans… |
|---|---|
| taper des commandes | cPanel → **Terminal** |
| modifier un `.env` | cPanel → **Gestionnaire de fichiers** → ⚙ Paramètres → cocher « Afficher les fichiers cachés » → clic droit sur `.env` → **Modifier** |
| vérifier les tâches automatiques | cPanel → **Tâches Cron** |
| voir les factures relevées | l'application → **Fournisseurs › Factures FNE** |
| renseigner le NCC | l'application, compte gérant → **Paramètres** |

Les deux sites sur le serveur :

| Site | Dossier | Adresse |
|---|---|---|
| développement (essais) | `~/public_html/gestion-dev/GESTION-DE-SITES` | gestion-dev.dc-knowing.com |
| production | `~/public_html/GESTION-DE-SITES` | gestionsites.dc-knowing.com |

---

## 4. Installer le relevé sur un site (fait sur `gestion-dev` le 16/10)

Toutes les commandes se tapent dans **cPanel → Terminal**. Remplacer `<SITE>` par le dossier du site
(`~/public_html/gestion-dev/GESTION-DE-SITES` ou `~/public_html/GESTION-DE-SITES`).

**Étape 1 — Récupérer le code**
```bash
cd <SITE>
git pull
```
Le serveur ne fait **que recevoir** le code. On n'y fait jamais `git commit` ni `git push`.

**Étape 2 — Le bon Node** (une fois pour tout le compte, déjà fait)
```bash
/opt/alt/alt-nodejs24/root/usr/bin/node -v
```
Doit afficher `v24.…`. Le raccourci `node` de cPanel était cassé (`nodevenv/…/activate: No such
file`) : il a été mis de côté (`~/bin/node.casse`). On utilise **toujours le chemin complet**
`/opt/alt/alt-nodejs24/root/usr/bin/node`.

**Étape 3 — Installer les outils du scraper** (dans chaque site, car `node_modules` n'est pas sur GitHub)
```bash
cd <SITE>/SCRAPER-PORTAIL-FNE
PATH=/opt/alt/alt-nodejs24/root/usr/bin:$PATH npm install
PATH=/opt/alt/alt-nodejs24/root/usr/bin:$PATH npx playwright install chromium
```
Le **`npm install` d'abord**, puis le navigateur. Dans l'autre ordre, on obtient « Cannot find
module 'playwright' » ou « Executable doesn't exist ».

**Étape 4 — Le fichier de réglages**
```bash
cp .env.exemple .env
```
Puis le remplir (Gestionnaire de fichiers) comme au § 2. **Le `.env`, pas le `.env.exemple`.**

**Étape 5 — Les bibliothèques du navigateur**
Le serveur web41 n'a pas trois bibliothèques dont Chromium a besoin (`libatk-bridge-2.0.so.0`,
`libatspi.so.0`, `libxkbcommon.so.0`), et on ne peut pas les installer sans être administrateur.
Elles existent déjà, posées pour Selflow, dans `~/local-libs/usr/lib64`. La ligne `FNE_LIBS` du
`.env` (§ 2) les donne au navigateur. Sur un autre serveur : `bash bibliotheques-chromium.sh` dit
ce qui manque.

**Étape 6 — Essayer**
```bash
/opt/alt/alt-nodejs24/root/usr/bin/node achats.js
```
Réussi quand on lit : `2728 facture(s) reçue(s) du 2023-01-01 au … -> 2197588Q_AAAAMMJJ.json`.

---

## 5. Faire entrer les factures dans l'application

Le relevé n'a fait qu'écrire un fichier : **rien ne paraît encore dans l'application**. Il faut le
ranger :

```bash
cd <SITE>
php artisan fne:releve-achats
```

Elle doit dire : `2197588Q_….json : N factures (N nouvelles, dont A avoirs), R rapprochées du suivi`.

- Si elle répond **« entreprise introuvable »** : le NCC n'est pas renseigné sur la fiche de
  l'entreprise (ou plusieurs entreprises le portent — c'est le cas sur `gestion-dev`, qui a une
  entreprise de test). Soit renseigner le NCC dans **Paramètres** du gérant de la bonne entreprise,
  soit préciser l'entreprise :
  ```bash
  php artisan tinker --execute="foreach (\Modules\Noyau\Entreprises\Modeles\Entreprise::all() as \$e) echo \$e->id.' — '.\$e->nom.' — NCC '.\$e->ncc.PHP_EOL;"
  php artisan fne:releve-achats --entreprise=<id de L'Artisan Automobile>
  ```

**Où les voir ensuite :**
- **Fournisseurs › Factures FNE** : toutes les pièces, par exercice ; **Détails** ouvre la facture
  (en-tête, lignes, taxes) ; **Porter** ouvre le formulaire du suivi fournisseurs avec ce que dit la
  facture en premier, **non modifiable**.
- **Fournisseurs** (page principale) : les KPI des **normalisés** — factures FNE reçues dans l'année,
  au suivi, à porter, suivi normalisé.
- **Charges** (depuis le 20/10) : les factures FNE et le suivi fournisseurs **ne sont pas des
  charges** — « les fournisseurs ne font que retracer ». La charge est la **sortie de caisse** qui
  paie la facture ; au moment de **Régler** une pièce, on choisit la sortie du journal qui l'a payée,
  et la page Fournisseurs le dit (« dont par la caisse »).

---

## 6. Rendre le relevé automatique

**Depuis le 18/10, rien à écrire dans le `.env` de l'application** : le relevé automatique
s'allume dès que `SCRAPER-PORTAIL-FNE/.env` existe, et `node` est cherché tout seul dans
`/opt/alt/alt-nodejs*/root/usr/bin/node`. Pour forcer l'un ou l'autre, ces lignes restent possibles :
```
FNE_SCRAPER_ACTIF=true      # false pour l'éteindre (ex. gestion-dev, § 7 étape 9)
FNE_NODE=/opt/alt/alt-nodejs24/root/usr/bin/node
```
Après le `git pull` (et après toute modification du `.env`) :
```bash
cd <SITE>
php artisan app:deployer
```
(`app:deployer` remet la configuration en cache : **à relancer après chaque modification du `.env`**.)

**Dans cPanel → Tâches Cron**, la ligne du site doit exister, toutes les minutes :
```
/usr/local/bin/php /home/cp2255957p00/public_html/<dossier>/GESTION-DE-SITES/artisan schedule:run >> /dev/null 2>&1
```
⚠️ Il faut **une espace entre `artisan` et `schedule:run`**. Le 16/10, les deux lignes de la
production étaient écrites `artisanschedule:run` et `artisanqueue:work` : elles ne tournaient pas.

À partir de là : un relevé **toutes les heures de 7 h à 19 h, du lundi au samedi** (35 derniers
jours), et **tout l'historique à 2 h 30**. Le bouton **« Actualiser depuis la FNE »** fait la même
chose tout de suite (relevé + rangement).

Réglages facultatifs du `.env` de l'application : `FNE_HEURE_DEBUT=07:00`, `FNE_HEURE_FIN=19:00`,
`FNE_HEURE_COMPLET=02:30`, `FNE_DEPUIS_JOURS=35`.

---

## 7. Passer en production (`~/public_html/GESTION-DE-SITES`)

À faire **une fois tout validé sur `gestion-dev`**, dans l'ordre :

1. **Corriger les deux tâches Cron** de la production (espace après `artisan`, voir § 6).
2. **Code** : `cd ~/public_html/GESTION-DE-SITES && git pull && php artisan app:deployer`.
3. **Outils du scraper** (§ 4, étape 3) dans `~/public_html/GESTION-DE-SITES/SCRAPER-PORTAIL-FNE`.
4. **Réglages du scraper** : copier le `.env` de `gestion-dev` (il n'est pas sur GitHub) :
   ```bash
   cp ~/public_html/gestion-dev/GESTION-DE-SITES/SCRAPER-PORTAIL-FNE/.env ~/public_html/GESTION-DE-SITES/SCRAPER-PORTAIL-FNE/.env
   ```
   Vérifier que `FNE_URL` est bien le **vrai portail**.
5. **Essai** (§ 4, étape 6) depuis le dossier de production.
6. **NCC** : vérifier dans **Paramètres** (gérant) que la fiche de l'entreprise porte `2197588Q`.
7. **Ranger** : `php artisan fne:releve-achats` (§ 5), puis regarder **Fournisseurs › Factures FNE**.
8. **Automatique** (§ 6) : rien à écrire, le `.env` du scraper suffit ; `php artisan app:deployer`.
9. **Désactiver le relevé automatique sur `gestion-dev`** (`FNE_SCRAPER_ACTIF=false` dans son `.env`,
   puis `app:deployer`) : deux sites qui se connectent chaque heure avec le même mot de passe,
   c'est deux fois plus de connexions sur le portail de l'État pour rien.

---

## 8. Au quotidien

1. **Fournisseurs › Factures FNE** : les pièces arrivées. « Rapprochée » = déjà retrouvée dans le
   suivi fournisseurs (même fournisseur, même n° de pièce, sinon même date et même montant).
2. Une pièce **« à porter »** : **Porter** → le formulaire du suivi fournisseurs s'ouvre ; ce que dit
   la facture FNE est en premier et ne se modifie pas ; on complète (ville, atelier, échéance…) et on
   enregistre. Porter n'est pas régler :
   la pièce devient une dette, reste à payer = TTC. Une ligne importée qui porte le n° FNE (n° de
   pièce, observations…) est rapprochée toute seule.
3. À l'échéance : sur la page de la pièce, **Régler** → le règlement est lettré à la facture ; si la
   caisse a payé, choisir la sortie du journal dans « Payé par la caisse ? ».
4. Les KPI de l'écran disent les différences : FNE absentes du suivi, suivi sans FNE.

---

## 9. Dépannage — tout ce qu'on a déjà rencontré

| Message | Cause | Que faire |
|---|---|---|
| `nodevenv/…/18/bin/activate: No such file`, répété | raccourci `~/bin/node` de cPanel cassé | utiliser `/opt/alt/alt-nodejs24/root/usr/bin/node` (§ 4, étape 2) |
| `"$NODE" npm install` → `Cannot find module …/npm` | `npm` n'est pas un fichier à donner à `node` | `PATH=/opt/alt/alt-nodejs24/root/usr/bin:$PATH npm install` |
| `Cannot find module 'playwright'` | `npm install` pas fait dans ce dossier | § 4, étape 3 |
| `Executable doesn't exist at …chromium_headless_shell-XXXX` | navigateur pas téléchargé pour cette version | `PATH=…:$PATH npx playwright install chromium` |
| `FNE_URL manquant dans le .env du scraper` | réglages mis dans `.env.exemple`, ou `.env` absent | remplir `SCRAPER-PORTAIL-FNE/.env` |
| `error while loading shared libraries: libatk-bridge-2.0.so.0` | bibliothèques système absentes | ligne `FNE_LIBS` (§ 4, étape 5) |
| `npx playwright install-deps` → `spawn su EACCES` | demande les droits administrateur | ne pas l'utiliser ; `FNE_LIBS` à la place |
| `Could not open input file: artisan` | la commande est lancée depuis `SCRAPER-PORTAIL-FNE` | `cd ..` (la racine du site) d'abord |
| Factures relevées mais rien dans l'application | le fichier n'a pas été rangé | `php artisan fne:releve-achats` (§ 5) |
| `entreprise introuvable — précisez --entreprise=` | NCC absent ou porté par deux entreprises | § 5 |
| `git push` refusé, conflit au `git pull` | un commit a été fait **sur le serveur** | `git branch sauvegarde-AAAA-MM-JJ && git fetch origin && git reset --hard origin/main` ; ne jamais commiter sur le serveur |
| Le portail a changé d'écran | le scraper s'arrête et le dit, capture dans `erreurs/` | envoyer la capture ; `node achats.js --reconnaissance` explore sans rien déposer |

---

## 10. Sécurité — les règles qui ne se discutent pas

- Le **mot de passe du portail** ne va que dans `SCRAPER-PORTAIL-FNE/.env` (jamais dans
  `.env.exemple`, jamais dans un message, jamais dans un commit). S'il a circulé : le changer sur le
  portail, puis dans le `.env`.
- Un **jeton GitHub** ne s'écrit jamais dans une adresse de dépôt (`git remote set-url …ghp_…`) ni
  dans une conversation. S'il a circulé : le supprimer sur GitHub (Settings › Developer settings ›
  Personal access tokens). Pour lire un dépôt privé depuis le serveur : un jeton **en lecture seule**,
  limité à ce dépôt, tapé quand `git` le demande.
- Le serveur **reçoit** le code (`git pull`), il ne l'**envoie** jamais.

---

## 11. Refaire un scraping ailleurs (une autre application, un autre portail)

La même recette, dans le même ordre :

1. **Séparer relever et ranger** : un script qui ne fait que déposer un fichier ; l'application qui
   le range, en reconnaissant un fichier déjà lu (empreinte). On peut ainsi rejouer sans rien doubler.
2. **Un dossier à part** avec son `package.json`, un `.env.exemple` sans secret, un `.env` ignoré par
   git, et ses consignes.
3. **Sur le serveur web41** : Node par son chemin complet (`/opt/alt/alt-nodejs24/root/usr/bin/node`),
   `npm install` puis `npx playwright install chromium`, et `LD_LIBRARY_PATH` vers `~/local-libs/usr/lib64`
   (dans notre code : `FNE_LIBS`, lu par le script et passé au navigateur au lancement).
4. **Essayer à la main** avant toute automatisation, puis seulement brancher le planificateur
   (`schedule:run` dans les Tâches Cron, avec l'espace).
5. **Un rythme raisonnable** : aux heures ouvrées, pas jour et nuit — chaque passage est une
   connexion avec vos accès sur le site d'un tiers.
6. **Essayer sur `gestion-dev` d'abord**, puis la production, puis couper l'automatique du site d'essai.

# Mise à jour des serveurs

Deux serveurs, un seul dépôt. Ils reçoivent le même code et ne diffèrent que par leur
fichier `.env` — c'est lui, et lui seul, qui sépare la production du développement.

| | Production | Développement |
|---|---|---|
| Adresse | `gestionsites.dc-knowing.com` | `gestion-dev.dc-knowing.com` |
| Dossier | `~/public_html/GESTION-DE-SITES` | `~/public_html/gestion-dev/GESTION-DE-SITES` |
| Base | `cp2255957p00_gestionsites` | `cp2255957p00_gestiondev` |
| `APP_ENV` | `production` | `local` |
| Courrier | SMTP réel | `log` — rien ne part |
| Accès | connexion applicative | mot de passe navigateur en plus |

Pour créer l'environnement de développement à partir de rien, voir
[ENVIRONNEMENT-DE-DEV.md](ENVIRONNEMENT-DE-DEV.md).

> **La base en ligne porte des données réelles.** C'est la règle qui commande tout le
> reste : aucune purge, aucun seeder, aucun `migrate:fresh` sur un serveur en service. Les
> migrations du projet créent des tables neuves, ajoutent des colonnes facultatives ou
> relâchent une contrainte — jamais l'inverse. Une ligne déjà saisie ne peut donc pas
> devenir invalide du fait d'une mise à jour.
>
> Avant chaque déploiement, vérifier ce qui attend et ce que cela fait :
>
> ```bash
> php artisan migrate:status | grep Pending
> ```
>
> Une migration qui **réécrit** des données existantes — et il en existe — mérite qu'on
> relise sa méthode `up()` avant de la lancer.
>
> Avant de commencer, faire malgré tout une sauvegarde depuis cPanel → *Sauvegardes* →
> *Télécharger une sauvegarde de base de données MySQL*. Une sauvegarde inutile ne coûte
> que deux minutes ; son absence, une journée de saisie.

---

## 1. Le rituel, à chaque mise à jour

Sur **chaque** serveur, dans son propre dossier :

```bash
cd ~/public_html/GESTION-DE-SITES              # ou .../gestion-dev/GESTION-DE-SITES
git pull origin main
php artisan app:deployer
```

`app:deployer` enchaîne `migrate --force` puis vide les caches de configuration, de routes,
d'évènements, de données et de gabarits, **les reconstruit à neuf** (routes, évènements,
gabarits — depuis le 17/09/2026, pour la vitesse), et recrée le lien `public/storage` s'il
manque. La configuration n'est pas mise en cache, délibérément : `bootstrap/app.php` lit
`PROXYS_DE_CONFIANCE` par `env()`, qu'un cache de configuration rendrait muet.
La variante `--sans-migration` fait tout sauf les migrations, quand la base ne doit pas
bouger.

**Le vidage des gabarits n'est pas facultatif.** Volt met en cache la classe compilée de
chaque écran et ne la refabrique que si le fichier source paraît plus récent — une
comparaison de dates. Selon la façon dont les fichiers arrivent, cette comparaison peut se
tromper : le gabarit est neuf, la classe reste l'ancienne, et la page tombe en erreur 500
sur une propriété introuvable.

### Si `composer.json` a changé

```bash
git diff HEAD@{1} --name-only | grep composer.json
```

Si la commande répond quelque chose, l'autochargeur doit être refait **avant**
`app:deployer` :

```bash
composer dump-autoload -o
```

C'est une opération purement locale : aucune dépendance n'est téléchargée. Sans elle,
l'application s'arrête sur `Class "Modules\…\…ServiceProvider" not found` — un module
ajouté au dépôt existe sur le disque mais reste invisible à PHP.

### Un réglage de file d'attente à ne pas oublier

Le traitement d'un fichier importé passe par une file (`QUEUE_CONNECTION=database`) et peut
durer jusqu'à 900 secondes. Le `.env` de chaque serveur doit donc porter :

```
DB_QUEUE_RETRY_AFTER=1200
```

Laravel exige que ce délai dépasse la durée maximale d'une tâche. Avec la valeur par défaut
— 90 secondes —, un import un peu long est cru abandonné, repris par un second ouvrier, et
se retrouve marqué en échec alors qu'il a parfaitement abouti.

### Les tâches planifiées

Une paire par serveur, dans cPanel → *Tâches Cron*, toutes les minutes.

**Depuis la mise à jour de la branche `import` (16/09/2026), la lecture d'un fichier ne
dépend plus de la première.** Elle démarre au dépôt dans un processus à part
(`php artisan import:traiter-lot`), et la barre de progression la montre avancer. La tâche
`queue:work` reste en place comme **filet** : si l'hébergement refuse de lancer ce processus,
c'est elle qui prend le fichier, dans la minute. Un fichier n'est jamais lu deux fois.

Le processus a besoin du PHP « ligne de commande », qui n'est pas celui qui sert les pages. Il
est cherché à `/usr/local/bin/php` — le même que ces tâches — puis `/usr/bin/php`. S'il est
ailleurs, l'indiquer dans le `.env` :

```
IMPORT_PHP_CLI=/usr/local/bin/php
```

Vérifier après la mise à jour : déposer un petit fichier et regarder la barre avancer. Si
l'écran annonce « Le fichier attend depuis … minute(s) », le lancement immédiat a échoué et
deux secours prennent le relais sans qu'on ait rien à faire : le cron, et l'écran lui-même, qui
retente le lancement une fois par minute tant qu'on le regarde (17/09). Si rien ne bouge malgré
tout : régler `IMPORT_PHP_CLI`, ou vérifier que `exec` n'est pas dans `disable_functions`.

La migration `2026_09_16_000002` (colonne `lignes_estimees`, additive) passe avec
`app:deployer`. Aucune commande de données n'est à lancer.

```
/usr/local/bin/php /home/cp2255957p00/public_html/GESTION-DE-SITES/artisan queue:work --stop-when-empty --timeout=3600 --tries=1 >> /dev/null 2>&1
/usr/local/bin/php /home/cp2255957p00/public_html/GESTION-DE-SITES/artisan schedule:run >> /dev/null 2>&1
```

La table des crons est commune à tout le compte d'hébergement : d'autres projets y
cohabitent. Passer par l'interface cPanel plutôt que par `crontab -e` évite d'abîmer leurs
lignes.

### Une seule fois, après la mise à jour du 15 septembre 2026

L'écran **État des impayés** a besoin que chaque créance déjà reprise porte son
année : c'est elle qui la fait apparaître dans l'état d'un exercice, et sans elle la page
reste vide sur une base qui contient pourtant des milliers de créances. La même commande
range au passage le numéro de sinistre, le sticker et le code client, que l'import gardait
jusqu'ici dans une phrase.

Elle ne s'écrit **pas** dans `app:deployer` : elle touche aux données, et cela se regarde
avant de se lancer.

```bash
php artisan impayes:ranger-les-colonnes              # constat : n'écrit rien
php artisan impayes:ranger-les-colonnes --appliquer  # écrit
```

Elle est sans danger et rejouable : elle ne remplit que ce qui est vide, ne crée ni ne
supprime aucune ligne, et ne jette aucun texte qu'elle n'a pas reconnu. Relancée, elle
annonce zéro.

Trois colonnes restent vides ensuite — **date de réception**, **banque** et la tranche
d'ancienneté annoncée par le classeur : elles n'existaient pas en base, donc l'import
précédent ne les avait pas lues. Redéposer le fichier des impayés depuis **Import** les
remplit. Rien ne l'exige, et l'écran fonctionne sans.

### Une seule fois, après la mise à jour du 16 septembre 2026

Les factures ont une colonne **ville** (migration `2026_09_16_000001`, additive). Elle sert
au sélecteur de ville du recouvrement et à l'état des impayés : une créance dont on connaît
la ville mais pas l'atelier — le cas d'Abidjan, qui en a deux — ne s'affiche plus dans les
autres villes. Les factures écrites avant la migration ont une ville vide ; la commande la
pose **d'après leur atelier**, et seulement d'après lui.

```bash
php artisan factures:poser-la-ville              # constat : n'écrit rien
php artisan factures:poser-la-ville --appliquer  # écrit
```

Elle ne remplit que ce qui est vide et ne devine rien : une facture sans atelier garde une
ville vide, et reste visible dans toutes les villes. Pour les factures reprises d'un fichier,
**redéposer le fichier** depuis **Import** écrit la ville que sa colonne SITE désigne. Éprouvé
sur la base locale : 1 937 factures reçoivent la ville de leur atelier ; après relecture des
deux fichiers, 5 077 créances de l'état sont situées à Abidjan et 4 à San Pedro, et 3 771 —
colonne SITE vide dans le classeur — restent « à préciser ».

Deux changements de règle arrivent avec cette mise à jour, sans commande à lancer :

- **l'ancienneté d'une créance se compte depuis son dépôt chez le client** (date de
  réception), et depuis l'édition quand le dépôt n'est pas connu. Tant que le fichier des
  impayés n'a pas été redéposé, les dates de réception sont vides et rien ne bouge ; après
  relecture en local, 8 factures ouvertes sur 1 341 changent de niveau de relance ;
- **la date de réception est obligatoire** à la saisie de l'état des impayés.

### Une seule fois, après la mise à jour du 17 septembre 2026

**D'abord, que `main` contienne bien l'état des impayés.** Le 16/09, seule la branche `import`
a été fusionnée dans `main` : le serveur a reçu la lecture immédiate des imports, mais ni
l'écran *État des impayés* ni *Rapprochement CA / impayés* — d'où leur absence du menu
*Indicateurs*. Rien n'a été perdu : tout est sur la branche `impayes`, qui contient désormais
aussi `import`. La fusion se fait **sur le poste**, puis le poste pousse :

```bash
git checkout main
git pull origin main
git merge impayes          # avance simple, sans conflit
git push origin main
```

**Le serveur ne pousse jamais.** Il tire (`git pull origin main`) et c'est tout ; un
`git push` lancé depuis le serveur est refusé (403), et c'est très bien ainsi.

Sur chaque serveur ensuite :

```bash
git pull origin main
php artisan app:deployer
php artisan impayes:ranger-les-colonnes              # constat, n'écrit rien
php artisan impayes:ranger-les-colonnes --appliquer
php artisan factures:poser-la-ville                  # constat, n'écrit rien
php artisan factures:poser-la-ville --appliquer
```

Les deux commandes de données sont celles des mises à jour du 15 et du 16 septembre, qui
n'avaient pas encore été lancées faute d'écran. Deux migrations passent avec `app:deployer`, et
ni l'une ni l'autre ne lit ou n'écrit une ligne :

- `2026_09_17_000001` pose **un index** sur `encaissements (facture_id, montant)`. Son retour
  arrière repose d'abord l'index de la clé étrangère, que MySQL retire de lui-même quand un
  index commence par la même colonne ;
- `2026_09_17_000002` ajoute `lots_import.controle` — un dépôt se souvient d'avoir été demandé
  « pour vérifier », afin qu'une relance tardive ne se mette pas à écrire.

### Une seule fois, après la mise à jour du 21 septembre 2026

La branche **`creances`** porte le premier jour du plan : le dépôt désigne le payeur, et une
facture réglée ne se porte plus à l'état des impayés. Elle se fusionne dans `main` **sur le
poste**, comme d'habitude, puis le poste pousse et les serveurs tirent.

```bash
git pull origin main
php artisan app:deployer
```

**Rien à lancer de plus, et aucune donnée touchée.** Une seule migration passe avec
`app:deployer` :

- `2026_09_21_000001` ajoute `factures.depose_chez` (texte, facultatif) et son index
  `(entreprise_id, depose_chez)`. La colonne naît vide : les 11 332 factures de la base
  retombent exactement sur la règle d'hier — courtier, puis assureur, puis client — et aucun
  écran ne change de chiffre tant que personne n'a renseigné un dépositaire.

Ce qu'il faut savoir avant de l'annoncer aux utilisateurs : dès qu'une facture porte un
« Déposée chez », **c'est ce tiers-là qui est relancé**, et la créance sort de la balance âgée
du client facturé pour entrer dans la sienne. C'est le but ; ce n'est pas réversible par
inadvertance, mais cela déplace un encours d'un compte à l'autre, et il vaut mieux que le
service recouvrement le sache avant de le découvrir.

**Trois autres changements du même envoi, sans migration ni commande :**

- l'onglet **« Période »** des écrans d'indicateurs s'ouvrait sur une erreur — il ne s'ouvre
  plus sur rien du tout, il s'ouvre. Le filtre y est désormais **au jour** (« du 3 au 17 mars »),
  et les anciens liens mis en favori, qui portaient un mois, continuent de fonctionner ;
- l'état des impayés propose au **gérant seul** un bouton **Supprimer**, refusé sur une créance
  réglée ou importée, avec confirmation sur la ligne et trace complète au journal ;
- l'écran **Fournisseurs** montre le déjà payé et gagne ses trois boutons de téléchargement.

### Les deux réglages qui font le plus pour la vitesse

Ils ne se règlent pas dans le code : ils appartiennent à l'hébergement. `php artisan
app:diagnostic` affiche désormais une rubrique **Vitesse** qui dit où l'on en est.

**1. OPcache — le premier poste, et de loin.**

Sans lui, PHP relit et recompile les milliers de fichiers du cadre à *chaque* page demandée.
Mesuré sur le poste : un démarrage à froid coûte environ deux secondes, un démarrage à chaud un
demi-seconde. C'est exactement cet écart que l'on paie à chaque clic quand OPcache est absent.

Dans cPanel :

1. *Select PHP Version* (ou *MultiPHP INI Editor* selon l'hébergeur) ;
2. onglet **Extensions**, cocher **`opcache`**, enregistrer ;
3. onglet **Options** (ou *MultiPHP INI Editor* → mode éditeur), vérifier ou poser :

```
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
```

`validate_timestamps = 1` avec `revalidate_freq = 2` veut dire : PHP regarde toutes les deux
secondes si un fichier a changé. C'est ce qu'il faut ici — sinon, après un `git pull`, le
serveur continuerait de servir l'ancien code jusqu'au redémarrage de PHP.

Attention : c'est le PHP **qui sert les pages** qu'il faut régler, pas celui de la ligne de
commande. Les deux sont distincts sur un mutualisé, et `app:diagnostic` ne voit que le second —
il le dit.

Pour vérifier depuis le site : la rubrique *Vitesse* du diagnostic, ou, plus simplement, la
sensation au clic après avoir vidé et refait les caches.

**2. `CACHE_STORE=file` dans le `.env`.**

À la place de `CACHE_STORE=database`. Le cache est lu à chaque requête — les droits de chaque
rôle y vivent — et, en base, chaque lecture est un aller-retour MySQL de plus. En fichier, c'est
un `include` local. Rien n'est perdu : un cache se reconstruit tout seul.

```
CACHE_STORE=file
```

Puis `php artisan app:deployer` (ou `php artisan config:clear`). L'avancée des imports, elle,
est déjà écrite en fichier quel que soit ce réglage.

**Ne pas** toucher à `SESSION_DRIVER` en même temps : le passer de `database` à `file`
déconnecterait tout le monde une fois, sans rien accélérer de sensible.

## 2. Ce qu'un envoi ne doit jamais emporter

Le 14 septembre 2026, le projet a été mis en ligne par un zip du dossier local. La
production a reçu, en même temps que le code, le `.env` du poste de développement — et
s'est mise à chercher une base `gestionsites` chez un utilisateur `root` sans mot de passe.
Le site est tombé pour la journée.

**La règle est donc simple : on ne déploie pas par zip.** `git pull` ne transporte ni le
`.env`, ni `vendor/`, ni `storage/` — précisément les trois choses qui appartiennent au
serveur et non au dépôt.

Si un envoi manuel est malgré tout inévitable, il ne doit **jamais** contenir :

| Chemin | Pourquoi |
|---|---|
| `.env` | identifiants de base, clés, mots de passe — propres à chaque serveur |
| `storage/` | fichiers déposés, journaux, sessions, gabarits compilés |
| `vendor/` | dépendances, reconstruites par Composer |
| `bootstrap/cache/` | chemins absolus du poste d'origine |
| `public/storage` | c'est un lien symbolique ; un zip le transforme en copie figée |
| `.git/` | sinon le serveur hérite de la branche du poste, pas de la sienne |

### Remettre un serveur d'aplomb après un envoi malheureux

```bash
git fetch origin
git log --oneline origin/main..HEAD     # doit ne rien afficher
git checkout -f -B main origin/main
git clean -fd
php artisan app:deployer
```

⚠️ **`-fd`, jamais `-fdx`.** Le `-fd` efface les fichiers non suivis ; le `-x` y ajouterait
ceux que `.gitignore` protège, c'est-à-dire `.env`, `vendor/` et les fichiers déposés.

## 3. Faire le ménage parmi les comptes de la plateforme

Une installation qui a vécu accumule des comptes hors entreprise : celui de la
démonstration du premier jour, un administrateur secondaire d'essai, celui créé pour de
bon ensuite. Tous portent `super_admin`, c'est-à-dire **tous les droits sur toutes les
entreprises**. Un compte oublié dont plus personne ne connaît le mot de passe reste une
porte ouverte.

**D'abord l'inventaire**, qui n'écrit rien :

```bash
php artisan superadmin:menage
```

Pour chaque compte, la commande affiche ses rôles, son statut, sa dernière connexion, et
surtout **ce qu'il porte** : accès qu'il a ouverts, entrées au journal, écritures métier
saisies. Un compte à zéro partout est un résidu d'installation ; un compte qui a saisi
des centaines de factures est quelqu'un. On ne supprime pas un administrateur sur la foi
de son adresse.

**Puis le déroulé**, toujours sans écrire — remplacer les adresses par celles que
l'inventaire a réellement affichées :

```bash
php artisan superadmin:menage \
  --garder=superadmin@gmail.com \
  --supprimer=superadmin@plateforme.local \
  --supprimer=support@plateforme.local
```

La commande annonce qui est conservé, qui est supprimé, et qui perd seulement le statut
de fondateur. **Lire cette sortie avant de continuer.**

**Enfin l'exécution**, la même ligne suivie de `--confirmer`.

### Ce qui survit à la suppression

- **Les écritures.** Une facture saisie par un compte effacé reste une facture : son
  `cree_par` retombe à vide, mais le `code_auteur` inscrit à côté garde la trace de qui
  l'a tapée. Le chiffre d'affaires d'un exercice clos ne bouge pas d'un franc.
- **Les accès qu'il avait ouverts.** Supprimer celui qui a créé un gérant ne ferme pas le
  compte de ce gérant.
- **Le journal d'activité.** Supprimer quelqu'un ne doit pas effacer la preuve de ce
  qu'il a fait — et la suppression elle-même y est inscrite avant d'avoir lieu.

### Ce que la commande refuse

- garder un compte inconnu, ou un compte sans rôle — cela fermerait la plateforme à tout
  le monde ;
- supprimer le compte qu'on lui demande de garder ;
- toucher un compte rattaché à une entreprise : celui-là se supprime depuis l'écran
  *Accès*, où la hiérarchie est vérifiée ;
- effacer quoi que ce soit si **une seule** des adresses données est mauvaise. Rien de
  partiel : un ménage à moitié fait laisse un état que personne n'a demandé.

## 4. Mettre à jour les identifiants du super administrateur

**D'abord en simulation**, pour vérifier qu'on vise bien le bon compte :

```bash
php artisan superadmin:identifiants \
  --compte=superadmin@gmail.com \
  --email=it.dcknowing@gmail.com \
  --nom="Super Admin DC-KNOWING" \
  --mot-de-passe='@@@###26dcknowing' \
  --simulation
```

La commande affiche l'état actuel du compte et les changements demandés, **sans rien
écrire**. Lire cette sortie : si l'adresse actuelle affichée n'est pas celle attendue,
s'arrêter là et corriger `--compte`.

Puis, une fois la sortie vérifiée, relancer **la même ligne sans `--simulation`** :

```bash
php artisan superadmin:identifiants \
  --compte=superadmin@gmail.com \
  --email=it.dcknowing@gmail.com \
  --nom="Super Admin DC-KNOWING" \
  --mot-de-passe='@@@###26dcknowing'
```

> **Les apostrophes simples autour du mot de passe sont indispensables.** Sans elles, le
> shell interprète `#` comme le début d'un commentaire et `@@@` comme du texte : le mot de
> passe réellement enregistré ne serait pas celui qu'on croit, et la connexion échouerait
> ensuite sans qu'on comprenne pourquoi.

Si l'adresse actuelle du compte en ligne n'est plus `superadmin@gmail.com`, la commande le
dit et liste les comptes hors entreprise connus. Elle refuse également d'agir si la nouvelle
adresse appartient déjà à quelqu'un d'autre : rien n'est écrasé en silence.

### Ce que la commande fait, et rien d'autre

- remplace `email`, `name`, `password` sur **cette ligne uniquement** ;
- renouvelle le jeton « se souvenir de moi », pour qu'un cookie resté sur un poste
  n'ouvre plus la session avec l'ancien mot de passe ;
- inscrit le changement au journal d'audit — **sans jamais y écrire le mot de passe**.

> **Ne pas passer par le seeder pour cela.** `SuperAdminSeeder` porte bien la nouvelle
> adresse par défaut, mais il travaille en `firstOrCreate` sur l'e-mail : lancé sur un
> serveur dont la ligne porte encore l'ancienne adresse, il ne la corrigerait pas — il
> créerait un **second** super administrateur. La commande ci-dessus modifie la ligne
> existante ; c'est la seule voie en production.

## 5. Vérifier

```bash
php artisan superadmin:reparer it.dcknowing@gmail.com --diagnostic
php artisan app:diagnostic
```

Le premier doit afficher `est_fondateur : oui`, `rôles en base : super_admin (équipe 0)` et
les cinq sections ouvertes. Puis se déconnecter et se reconnecter avec la nouvelle adresse.

## 6. Facultatif — entretien du journal de traçabilité

Le journal des connexions est nominatif et se conserve six mois. L'ordonnanceur qui en
assure l'entretien est déjà déclaré au point 1, parmi les deux tâches planifiées de chaque
serveur.

Sans cron, l'écran de traçabilité reste **juste** : une session silencieuse depuis plus de
quinze minutes n'y est pas comptée comme présente, et les durées sont tenues à jour à chaque
requête. Il suffit alors de lancer l'entretien à la main de temps en temps :

```bash
php artisan tracabilite:entretenir
```

---

## En cas de retour en arrière

Un retour en arrière se compte en **lots**, pas en migrations : `migrate:status` donne le
numéro de lot de chacune, et `--step=1` défait le dernier lot entier — c'est-à-dire tout ce
qu'un `app:deployer` a posé d'un coup.

```bash
php artisan migrate:status | tail -20
php artisan migrate:rollback --step=1 --force
```

Vérifier avant de lancer ce que la méthode `down()` des migrations concernées supprime :
une table neuve et vide se perd sans conséquence, une colonne remplie depuis emporte ce
qu'elle contient.

Le retour en arrière laisse volontairement `factures.commercial_id` facultatif et `moyen`
en `VARCHAR` : les factures de recouvrement saisies entre-temps n'ont pas de commercial, et
rétablir la contrainte imposerait de les supprimer — c'est-à-dire d'effacer du chiffre
d'affaires réel. Un retour en arrière défait ce qu'une migration a ajouté ; il ne détruit
pas ce que les gens ont saisi depuis.

Pour l'adresse du super administrateur, relancer la commande du point 4 avec l'ancienne
adresse en `--email` et `--compte=it.dcknowing@gmail.com`.

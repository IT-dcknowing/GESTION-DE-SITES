# Monter l'environnement de développement

> Ce document décrit le montage complet d'un second serveur, à partir de rien. Pour la
> mise à jour quotidienne des deux serveurs, voir [MISE-A-JOUR-SERVEUR.md](MISE-A-JOUR-SERVEUR.md).

## Ce que vous montez, et pourquoi

Un second serveur qui reçoit le même code que la production, travaille sur une **copie de
ses données**, et ne peut rien envoyer à personne. C'est là qu'on éprouve une mise à jour
avant de la passer en ligne.

| | Production | Développement |
|---|---|---|
| Adresse | `gestionsites.dc-knowing.com` | `gestion-dev.dc-knowing.com` |
| Dossier | `~/public_html/GESTION-DE-SITES` | `~/public_html/gestion-dev/GESTION-DE-SITES` |
| Base | `cp2255957p00_gestionsites` | `cp2255957p00_gestiondev` |
| `APP_ENV` | `production` | `local` |
| Courrier | SMTP réel | `log` — rien ne part |
| Notifications | clés VAPID réelles | clés de test |
| Accès | connexion applicative | mot de passe navigateur en plus |

**Les deux serveurs ne diffèrent que par leur `.env`.** Le code est identique, la structure
de base est identique. C'est ce fichier, et lui seul, qui empêche le dev d'écrire à vos
clients ou de notifier leurs téléphones. Tout le reste de ce document sert à le rendre juste.

---

## 1. Le sous-domaine et la base

Dans cPanel → **Sous-domaines**, créer `gestion-dev` avec pour racine :

```
public_html/gestion-dev/GESTION-DE-SITES/public
```

La racine pointe sur `public`, jamais sur le dossier du projet : tout ce qui est au-dessus
— `.env` compris — doit rester hors de portée du serveur web.

Dans cPanel → **Bases de données MySQL**, créer la base `cp2255957p00_gestiondev` et lui
rattacher l'utilisateur existant avec tous les privilèges.

## 2. Poser le code

### Si le dossier est vide

```bash
cd ~/public_html/gestion-dev
git clone https://github.com/meledjeabrahamagnimel-lgtm/GESTION-DE-SITES.git
cd GESTION-DE-SITES
composer install --no-dev --optimize-autoloader
```

### Si le dossier contient déjà une copie du projet

C'est le cas si un zip y a été déposé un jour. On greffe git sur l'existant plutôt que de
repartir de zéro : `vendor/` n'est pas suivi par git, et le conserver évite d'avoir à
relancer Composer.

```bash
cd ~/public_html/gestion-dev/GESTION-DE-SITES
cp .env ~/env-dev-sauvegarde.txt      # si un .env existe déjà

git init
git remote add origin https://github.com/meledjeabrahamagnimel-lgtm/GESTION-DE-SITES.git
git fetch origin
git checkout -f -B main origin/main
git clean -fd
```

⚠️ **`-fd`, jamais `-fdx`.** Le `-x` effacerait `.env`, `vendor/` et les fichiers déposés.

Puis, parce que le `vendor/` conservé est plus ancien que le code :

```bash
composer dump-autoload -o
```

Sans cela, l'application s'arrête sur `Class "Modules\…\…ServiceProvider" not found` : un
module présent sur le disque reste invisible à PHP tant que la table des correspondances
n'a pas été refaite.

## 3. Le fichier `.env` du dev

Partir du `.env` de production, puis changer **exactement** ces lignes :

```
APP_ENV=local
APP_DEBUG=true
APP_URL=https://gestion-dev.dc-knowing.com

DB_DATABASE=cp2255957p00_gestiondev

# Le courrier ne part pas d'un environnement de test.
MAIL_MAILER=log
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=

GOOGLE_REDIRECT_URI="https://gestion-dev.dc-knowing.com/auth/callback"

# Vidées ici, régénérées à l'étape suivante.
VAPID_CLE_PUBLIQUE=
VAPID_CLE_PRIVEE=

# Plus long que les 900 s que peut durer un import.
DB_QUEUE_RETRY_AFTER=1200

# Le mot de passe du navigateur — voir l'étape 9.
ACCES_TEST_UTILISATEUR=
ACCES_TEST_MOT_DE_PASSE=
```

> **Les guillemets ne sont pas décoratifs.** Dans un `.env`, le caractère `#` ouvre un
> commentaire : un mot de passe contenant `#` sera tronqué sans que rien ne le signale.
> Encadrez de guillemets toute valeur qui contient `#`, `$` ou une espace.

**Ce qui reste identique à la production**, et c'est voulu : `DB_HOST`, `SESSION_DRIVER`,
`QUEUE_CONNECTION`, `CACHE_STORE`. Un environnement de test qui ne tourne pas comme la
production ne prouve rien.

Dans la **console Google**, ajouter les deux entrées du dev à côté de celles de la
production — origine JavaScript `https://gestion-dev.dc-knowing.com` et URI de redirection
`https://gestion-dev.dc-knowing.com/auth/callback` — sinon la connexion Google renverra
l'utilisateur du dev vers la production.

## 4. Les clés propres au dev

```bash
cd ~/public_html/gestion-dev/GESTION-DE-SITES
pwd     # relire : cette commande dans le mauvais dossier déconnecte toute la production

php artisan key:generate --force
php artisan push:cles
php artisan config:clear
```

`push:cles` **affiche** les clés sans écrire le fichier : recopier les deux lignes dans le
`.env`, en gardant votre propre `VAPID_CONTACT`.

Contrôler que le `.env` est lu comme on croit :

```bash
php artisan tinker --execute="echo config('app.acces_test.mot_de_passe'), PHP_EOL;"
```

## 5. Le lien `public/storage`

```bash
ls -ld public/storage
```

La réponse doit commencer par `l` et montrer une flèche. **Le `-d` est indispensable** :
sans lui, `ls` descend dans le dossier visé et l'on ne voit pas si c'est un lien ou une
copie.

Si c'est un vrai dossier — ce qu'un zip produit toujours —, vérifier d'abord que rien n'y
est unique, puis remplacer :

```bash
diff -rq public/storage storage/app/public     # ne doit rien afficher
mv public/storage public/storage.ancien
php artisan storage:link
ls -ld public/storage
rm -rf public/storage.ancien
```

Sans ce lien, tout fichier déposé ensuite est écrit mais jamais servi — et l'on cherche un
bug dans le code là où il n'y a qu'un lien manquant.

## 6. Copier la base de production

```bash
mysqldump --single-transaction --quick cp2255957p00_gestionsites > ~/prod.sql
ls -lh ~/prod.sql
grep -c "CREATE TABLE" ~/prod.sql
```

`--single-transaction` prend un instantané cohérent **sans verrouiller les tables** : la
production continue de fonctionner pendant l'export. Les deux contrôles qui suivent
vérifient qu'on a bien un export et non un message d'erreur.

```bash
mysql cp2255957p00_gestiondev < ~/prod.sql
rm ~/prod.sql
```

⚠️ **Relire la destination avant d'appuyer.** Inversées, ces deux commandes écrasent la
production.

### Couper ce qui était en cours

Une base de production copiée apporte aussi ce qui était **en train de se faire** :

```sql
TRUNCATE TABLE jobs;
TRUNCATE TABLE job_batches;
TRUNCATE TABLE failed_jobs;
TRUNCATE TABLE sessions;
TRUNCATE TABLE abonnements_push;
```

| Table | Ce qu'elle ferait sinon |
|---|---|
| `jobs`, `job_batches` | le dev exécuterait des imports de la production, dont les fichiers n'existent pas chez lui |
| `failed_jobs` | des échecs de la production apparaîtraient comme ceux du dev |
| `sessions` | des sessions ouvertes ailleurs, invalides de toute façon depuis le changement de clé |
| `abonnements_push` | une notification de test partirait vers les téléphones de vrais utilisateurs |

## 7. Déployer

```bash
php artisan app:deployer
php artisan app:diagnostic
```

Le diagnostic doit afficher la même branche et le même commit que la production, `APP_URL`
sur `gestion-dev`, le lien `public/storage` présent, et **0 appareil abonné**.

## 8. Les tâches planifiées

Dans cPanel → **Tâches Cron**, deux lignes, toutes les minutes :

```
/usr/local/bin/php /home/cp2255957p00/public_html/gestion-dev/GESTION-DE-SITES/artisan queue:work --stop-when-empty --timeout=3600 --tries=1 >> /dev/null 2>&1
/usr/local/bin/php /home/cp2255957p00/public_html/gestion-dev/GESTION-DE-SITES/artisan schedule:run >> /dev/null 2>&1
```

Passer par l'interface cPanel plutôt que par `crontab -e` : la table des crons est commune
à tout le compte, d'autres projets y cohabitent. Et vider le champ *Cron Email*, sans quoi
un message part chaque minute.

## 9. Fermer le dev au public

Le serveur porte maintenant une copie de vos données clients réelles, sur une adresse
publique. Deux lignes dans le `.env` suffisent :

```
ACCES_TEST_UTILISATEUR=votre-identifiant
ACCES_TEST_MOT_DE_PASSE="un-mot-de-passe"
```

```bash
php artisan config:clear
```

La protection est portée par `App\Http\Middleware\ProtegerLEnvironnementDeTest`, en tête du
groupe `web`. Elle ne fait rien en production, ni depuis une adresse locale ou privée — le
poste sous Laragon n'est donc pas gêné. Et **elle ferme même si personne ne l'a
configurée** : un oubli doit se voir tout de suite.

> **Pourquoi pas un `.htaccess`.** C'était la réponse évidente. Elle ne tenait pas ici :
> l'hébergeur ne propose pas l'outil de protection par répertoire, et `public/.htaccess` est
> suivi par git — le modifier sur le serveur créerait un conflit à chaque mise à jour,
> jusqu'au jour où quelqu'un l'écraserait pour s'en débarrasser. Une protection qu'un
> déploiement peut effacer n'en est pas une.

## 10. Vérifier

| Contrôle | Attendu |
|---|---|
| `gestion-dev.dc-knowing.com` en navigation privée | fenêtre de mot de passe du navigateur |
| `gestionsites.dc-knowing.com` | **aucune** fenêtre, connexion habituelle |
| `php artisan app:diagnostic` sur le dev | même commit que la production, 0 appareil abonné |
| `ls -ld public/storage` | une ligne commençant par `l` |
| Connexion sur le dev | les comptes de la production, puisque la base en est la copie |

---

## Rafraîchir le dev, ensuite

Deux fichiers rendent l'opération répétable sans risque de se tromper de sens.

**`~/.my.cnf`** — évite de retaper le mot de passe MySQL :

```bash
cat > ~/.my.cnf <<'EOF'
[client]
user=cp2255957p00_dcknowing
password=LE-MOT-DE-PASSE
EOF
chmod 600 ~/.my.cnf
```

Le `chmod 600` n'est pas décoratif : sans lui, le fichier est lisible par les autres
comptes de la machine mutualisée.

**`~/rafraichir-dev.sh`** — reprend les étapes 6 et 7 en une commande, avec un garde-fou
qui refuse toute destination autre que la base de dev :

```bash
#!/bin/bash
set -euo pipefail

DEV=/home/cp2255957p00/public_html/gestion-dev/GESTION-DE-SITES
BASE_PROD=cp2255957p00_gestionsites
BASE_DEV=cp2255957p00_gestiondev

[ "$BASE_DEV" = "cp2255957p00_gestiondev" ] || { echo "Destination suspecte. Arret."; exit 1; }

DUMP=$(mktemp)
mysqldump --single-transaction --quick "$BASE_PROD" > "$DUMP"
mysql "$BASE_DEV" < "$DUMP"
rm -f "$DUMP"

mysql "$BASE_DEV" -e "TRUNCATE TABLE jobs; TRUNCATE TABLE job_batches; TRUNCATE TABLE failed_jobs; TRUNCATE TABLE sessions; TRUNCATE TABLE abonnements_push;"

cd "$DEV"
php artisan app:deployer
php artisan app:diagnostic
```

```bash
chmod 700 ~/rafraichir-dev.sh
bash -n ~/rafraichir-dev.sh && echo "syntaxe correcte"
```

Le `.env` du dev n'est jamais touché par ce script : c'est lui le pare-feu, pas le script.

> **Éviter les heredocs imbriqués.** Un `<<'SQL'` à l'intérieur d'un `<<'EOF'` ne survit pas
> toujours à un copier-coller dans un terminal : le fichier se retrouve tronqué en plein
> milieu, sans erreur visible. D'où le `mysql -e "…"` sur une seule ligne ci-dessus, et le
> `bash -n` qui contrôle la syntaxe sans rien exécuter.

---

## Les pièges rencontrés au montage

Ils sont tous réels, tous rencontrés le 14 septembre 2026.

| Symptôme | Cause | Remède |
|---|---|---|
| `Access denied for user 'root'@'localhost'` | un zip a écrasé le `.env` du serveur | ne jamais déployer par zip ; voir MISE-A-JOUR-SERVEUR.md §2 |
| `Class "Modules\…" not found` | `composer.json` a gagné un module, l'autochargeur est resté en arrière | `composer dump-autoload -o` |
| Erreur 500 sur une propriété introuvable | classe Volt compilée restée en arrière | `php artisan app:deployer`, qui vide les gabarits |
| Un fichier déposé ne s'affiche pas | `public/storage` est un dossier et non un lien | étape 5 |
| Un import n'est jamais traité | pas de cron `queue:work` | étape 8 |
| Un import long marqué en échec alors qu'il a abouti | `DB_QUEUE_RETRY_AFTER` plus court que la durée d'une tâche | `DB_QUEUE_RETRY_AFTER=1200` |
| Un mot de passe du `.env` tronqué | un `#` non protégé ouvre un commentaire | encadrer la valeur de guillemets |
| Le dev renvoie vers la production | `APP_URL` ou `GOOGLE_REDIRECT_URI` restés sur l'adresse de production | étape 3 |

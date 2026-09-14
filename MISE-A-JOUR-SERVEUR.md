# Mise à jour du serveur — `gestionsites.dc-knowing.com`

> **La base en ligne porte des données réelles.** Rien de ce qui suit ne modifie une
> écriture métier : aucune purge, aucun seeder, aucun `migrate:fresh`. Les migrations
> ajoutées ici **créent des tables neuves et vides**, ajoutent des colonnes facultatives
> et **relâchent** deux contraintes — jamais l'inverse. La commande d'identifiants, elle, ne modifie **qu'une seule ligne** de la
> table `users`, et seulement les colonnes nommées.
>
> Avant de commencer, faire malgré tout une sauvegarde depuis cPanel → *Sauvegardes* →
> *Télécharger une sauvegarde de base de données MySQL*. Une sauvegarde inutile ne coûte
> que deux minutes ; son absence, une journée de saisie.

---

## 1. Récupérer le code

```bash
cd ~/gestionsites.dc-knowing.com     # ou le dossier réel de l'application
git pull origin main
```

Aucune dépendance nouvelle n'a été ajoutée : **pas besoin de `composer install`**. Le
générateur de PDF de l'annuaire et le journal de traçabilité sont écrits sans paquet
tiers, précisément pour que `vendor/` — qui n'est pas suivi par git — n'ait pas à bouger.

## 2. Migrer et vider les caches

```bash
php artisan app:deployer
```

Cette commande enchaîne `migrate --force`, puis le vidage des caches de configuration, de
routes, d'évènements et de gabarits. Le vidage des gabarits n'est pas facultatif : Volt met
en cache la classe de chaque écran, et une classe restée en arrière donne une erreur 500 sur
une propriété introuvable.

Ce que les migrations font :

| Objet | Contenu | Effet sur les données existantes |
|---|---|---|
| `sessions_utilisateur` *(table neuve)* | une ligne par connexion | aucun |
| `visites_ecran` *(table neuve)* | une ligne par écran ouvert | aucun |
| `relances_recouvrement` *(table neuve)* | une ligne par relance N1–N5 | aucun |
| `commentaires_ecart_recouvrement` *(table neuve)* | l'explication d'un écart | aucun |
| `factures.vehicule`, `factures.immatriculation` | colonnes **nullables** ajoutées | aucun : les factures existantes restent valides sans reprise |
| `factures.assureur`, `factures.courtier` | colonnes **nullables** ajoutées, indexées | aucun : elles naissent vides, le tiers payant retombe sur `client` |
| `entreprises.objectif_recouvrement_hebdomadaire` | colonne avec valeur par défaut | aucun |
| `factures.commercial_id` | devient **facultatif** | aucun : toutes les factures existantes gardent leur commercial |
| `encaissements.moyen`, `charges.moyen` | passent d'`ENUM` à `VARCHAR(60)` | aucun : les libellés sont conservés à l'identique |
| rôles `agent_recouvrement`, `superviseur_recouvrement` | créés dans chaque entreprise | aucun : personne ne les porte tant qu'on ne les attribue pas |

> Les deux dernières lignes **relâchent** une contrainte au lieu d'en ajouter une : une
> ligne déjà saisie ne peut donc pas devenir invalide. La conversion `ENUM → VARCHAR`
> corrige au passage un défaut qui existait déjà — un moyen de paiement ajouté depuis les
> Paramètres était accepté par le formulaire puis refusé par la base, et l'écran tombait
> en erreur au moment d'enregistrer.

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

Le journal des connexions est nominatif et se conserve six mois. Si l'hébergement dispose
d'une tâche planifiée (cPanel → *Tâches Cron*), y ajouter l'ordonnanceur Laravel :

```
* * * * * cd ~/gestionsites.dc-knowing.com && php artisan schedule:run >> /dev/null 2>&1
```

Sans cron, l'écran de traçabilité reste **juste** : une session silencieuse depuis plus de
quinze minutes n'y est pas comptée comme présente, et les durées sont tenues à jour à chaque
requête. Il suffit alors de lancer l'entretien à la main de temps en temps :

```bash
php artisan tracabilite:entretenir
```

---

## En cas de retour en arrière

Les deux tables ajoutées sont indépendantes du métier : les supprimer ne fait perdre que
l'historique de navigation.

```bash
php artisan migrate:rollback --step=2 --force
```

Le retour en arrière laisse volontairement `factures.commercial_id` facultatif et `moyen`
en `VARCHAR` : les factures de recouvrement saisies entre-temps n'ont pas de commercial, et
rétablir la contrainte imposerait de les supprimer — c'est-à-dire d'effacer du chiffre
d'affaires réel. Un retour en arrière défait ce qu'une migration a ajouté ; il ne détruit
pas ce que les gens ont saisi depuis.

Pour l'adresse du super administrateur, relancer la commande du point 4 avec l'ancienne
adresse en `--email` et `--compte=it.dcknowing@gmail.com`.

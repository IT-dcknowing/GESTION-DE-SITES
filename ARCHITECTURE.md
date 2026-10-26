# Architecture du projet

L'application est découpée en **modules** (`nwidart/laravel-modules`). Chaque module
regroupe tout ce qui concerne un rôle : ses écrans, ses routes, et le middleware qui
en garde l'accès. Ouvrir le dossier d'un module, c'est voir d'un coup d'œil ce que ce
rôle peut faire — et rien d'autre.

```
Modules/
├── Noyau/           le socle : modèles, migrations, services, écrans communs
├── SuperAdmin/      la plateforme
├── Gerant/          la direction
├── Superviseur/     la ville : le pilotage
├── ResponsableSite/ le lieu : la saisie du jour
├── Comptabilite/    la caisse d'une ville
├── Commercial/      le terrain
└── Recouvrement/    la poursuite des créances
```

## Pourquoi le Noyau existe

Une `Prospection` est créée par le commercial, validée par le responsable de site,
consultée par le superviseur et le gérant, encaissée par la comptabilité. Si le modèle
`Prospection` vivait dans le module `Commercial`, les cinq autres modules en
dépendraient — et le jour où l'on toucherait au module Commercial, on risquerait de
casser la comptabilité.

Le **Noyau** rassemble donc tout ce qui est partagé : les modèles, les migrations, les
services métier. Chaque module de rôle en dépend, et **le Noyau ne dépend d'aucun
d'eux**. Les modules de rôle, eux, ne se connaissent pas entre eux. C'est cette règle —
et elle seule — qui empêche les dépendances circulaires.

```
 SuperAdmin  Gerant  Superviseur  ResponsableSite  Comptabilite  Commercial
      \        |          |              |              |           /
       \       |          |              |              |          /
        ──────────────────► Noyau ◄─────────────────────────────────
```

**La règle à respecter :** un module de rôle peut appeler le Noyau. Il ne doit jamais
appeler un autre module de rôle. Si deux rôles ont besoin de la même chose, cette chose
appartient au Noyau.

## Contenu du Noyau

```
Modules/Noyau/
├── app/
│   ├── Entreprises/       entreprises, villes, sites, exercices, création d'accès
│   │   ├── Modeles/       Entreprise, Ville, Site, Exercice
│   │   ├── Actions/       CreerAcces, CreerEntreprise, PurgerDonneesEntreprise
│   │   ├── Services/      ProvisionneurEntreprise, EnregistreurLogo
│   │   └── Support/       PerimetreSites — qui voit quoi
│   ├── Exploitation/      le métier quotidien
│   │   ├── Modeles/       Prospection, Devis, Facture, Encaissement, Charge,
│   │   │                  Commercial, SaisieJournaliere, CompteurDocument
│   │   └── Services/      GenerateurNumero
│   ├── Messagerie/        conversations, messages, pièces jointes
│   └── Commun/            notifications, référentiels, notes, notifications poussées
├── database/migrations/   TOUTES les migrations de l'application
├── resources/views/
│   └── commun/            écrans ouverts à tous : messagerie, notifications,
│                          mot de passe, espace personnel, inscription
└── routes/web.php
```

Les migrations sont toutes dans le Noyau, et non réparties par module : une table comme
`factures` n'appartient à aucun rôle en particulier, et les éparpiller rendrait leur
ordre d'exécution difficile à suivre.

## Contenu des modules de rôle

| Module | Écrans | Rôle Spatie |
|---|---|---|
| `SuperAdmin` | tableau de bord, entreprises, accès, administrateurs, journal, maintenance (dont **rôles et pages**, 22/10) | `super_admin` |
| `Gerant` | tableau de bord, paramètres | `gerant` |
| `Superviseur` | `pilotage/` : prospects, devis, CA, charges, trésorerie, commerciaux, création d'accès | `responsable_ville` (lus aussi par `gerant` et `responsable_site`) |
| `ResponsableSite` | `saisie/` : saisie du jour, fiche prospection | `responsable_site` (partagé avec `responsable_ville`) |
| `Comptabilite` | tableau de bord, encaissements, décaissements | `caissier` |
| `Commercial` | mes prospections, ma performance, mes notes | `commercial` |
| `Recouvrement` | `recouvrement/` : saisie, synthèse, balance âgée, courtiers, clients & tiers, extrait de compte, journal des relances, journal des encaissements, piste d'audit | `agent_recouvrement`, `superviseur_recouvrement` (et `gerant`, qui a les neuf) |

### Comment le Recouvrement partage ses neuf pages

Le module ne crée pas les factures qu'il poursuit : il lit celles du Noyau, et un
encaissement qu'il enregistre entre aussitôt en trésorerie — pas de reprise, pas de
rapprochement.

Le partage traduit une séparation des fonctions, et non une hiérarchie de confort :
l'agent ne voit ni la **synthèse** (les objectifs qui pilotent son propre travail) ni la
**piste d'audit** (le registre de ses propres gestes) ; le superviseur voit la synthèse
mais pas l'audit ; le gérant voit tout, et lui seul engage le contentieux (N5).

Deux middlewares se superposent sur chaque route, et ce n'est pas une redondance :
`role:` dit qui entre dans le module, `VerifiePageRecouvrement` dit jusqu'où. Sans le
second, un agent qui tape `/recouvrement/piste-audit` lirait le journal de ses propres
actions — le grisé de la barre latérale n'a jamais fermé une adresse.

### Qui doit l'argent : le tiers payant

Une facture de sinistre a jusqu'à trois noms dessus, et un seul débiteur. Le **tiers
payant** se dérive dans cet ordre : le **courtier** qui a apporté le dossier, sinon
l'**assurance** représentée, sinon le **client** lui-même. La dérivation vit dans
`Facture::tiersPayant()` et nulle part ailleurs — la balance âgée, l'extrait de compte,
la page Courtiers et les listes de la saisie s'y réfèrent toutes, faute de quoi deux
écrans finiraient par désigner deux débiteurs différents pour la même facture.

C'est la réalité du paiement : un courtier encaisse le règlement de la compagnie et le
reverse ; relancer la compagnie pour une créance portée par son courtier revient à écrire
à quelqu'un qui n'a rien à payer. La page **Courtiers** en tire les deux tableaux qui
rendent une relance utilisable : ce que chaque courtier porte en tout, puis pour le compte
de quelle compagnie — on n'écrit pas « vous devez trente-huit millions », on écrit « dont
trente-six pour cent au titre d'AXA ».

Les deux colonnes sont nullables et naissent vides : sur une base déjà en service, le
tiers payant retombe sur `client` et aucun écran ne change de comportement.

Le niveau de relance maximal (N3 agent, N4 superviseur, N5 gérant) est revérifié côté
serveur à chaque enregistrement : une option désactivée dans une liste déroulante se
rouvre d'un clic, et une mise en demeure envoyée sans mandat engage l'entreprise.

### Où passe la frontière entre Superviseur et ResponsableSite

Les deux rôles atteignent les mêmes écrans : ce qui les distingue n'est pas ce qu'ils
voient, mais jusqu'où — le superviseur couvre tous les lieux de sa ville, le responsable
de site le sien seulement. Ce périmètre est résolu une fois pour toutes par
`Site::visiblesPour()`, jamais dans les écrans.

Les deux modules ne sont donc pas séparés par *qui regarde*, mais par **le métier à qui
l'écran appartient** :

- la **saisie du jour** est ancrée sur un lieu — c'est là que la journée d'atelier se
  déroule. Elle appartient à `ResponsableSite`. Le superviseur y accède aussi : quand sa
  ville n'a qu'un seul lieu, c'est lui qui la tient.
- le **pilotage** couvre une ville entière — lire les indicateurs et nommer les accès
  sous soi est le métier du superviseur. Il appartient à `Superviseur`. Le gérant et le
  responsable de site les lisent, chacun dans son périmètre.

Ce découpage évite la seule chose qu'il fallait éviter : **deux copies du même fichier**.
Un écran n'existe qu'à un seul endroit ; ouvrir `Modules/ResponsableSite/`, c'est voir
la saisie et rien d'autre.

### Pourquoi une route n'est jamais déclarée deux fois

Une URL ne peut porter qu'une seule route : si `Gerant` et `Superviseur` déclaraient tous
deux `/prospects`, seule la dernière chargée survivrait, en silence. Chaque route est
donc déclarée **une fois, dans le module dont c'est le métier**, et son middleware nomme
les rôles admis — `role:gerant|responsable_ville|responsable_site` pour le pilotage,
`role:responsable_ville|responsable_site` pour la saisie.

C'est le middleware, et non l'emplacement du fichier, qui décide de l'accès.

### Une page sans onglet : Charges fournisseurs (23/10)

`/fournisseurs` n'est plus au menu (`MenuNavigation`) : elle s'ouvre depuis la page Charges, et ses
pages filles (grand livre, décaissements fournisseurs de la caisse, balance, factures FNE…) depuis
elle. La route garde son middleware : retirer un onglet ne ferme pas une page.

### Les rôles configurés : une page ouverte sans toucher aux routes (22/10)

Le Super admin peut créer un rôle dans **Maintenance › Rôles et pages** en cochant des pages
(`CatalogueDesPages::SECTIONS`, Noyau) ; le rôle **Trésorerie** y est défini d'office
(`CatalogueDesPages::ROLES_PREDEFINIS`). Un tel rôle ne figure dans aucun `role:` des routes, et
ne doit pas avoir à y figurer : l'alias `role` passe par `App\Http\Middleware\RoleOuPagesOuvertes`,
qui laisse entrer le compte quand la route est l'une de ses pages cochées, et sinon délègue à
Spatie, inchangé. Le menu (`MenuNavigation`), l'atterrissage (`RedirectionController`) et le
périmètre (`Site::visiblesPour` : la ville du compte) lisent le même catalogue. La même page de la
Maintenance montre aussi les rôles du métier, ce qu'ils ouvrent étant **lu dans les routes**
(`CatalogueDesPages::rolesDuMetier`, middleware `role:`) plutôt que recopié. Leurs pages du catalogue
se **modifient** depuis cette page : la modification est une ligne de `roles_configures` au nom du rôle,
et `RoleOuPagesOuvertes` comme `MenuNavigation` la consultent (`decisionDuMetier`) avant la règle des
routes. Ce qu'un rôle peut écrire dans un écran reste la règle de cet écran.

Le catalogue couvre toutes les sections d'une entreprise (23/10). Le recouvrement et l'import
distribuent leurs pages rôle par rôle : ils **déclarent leur règle au catalogue** au démarrage
(`CatalogueDesPages::declarerUnModuleAPages`, dans leur fournisseur de services) et lisent les pages
cochées (`pagesDuModule`) — le Noyau ne les appelle jamais, la règle de dépendance tient. Un rôle configuré
**lit** : aucune action d'écriture ne le connaît. Les définitions vivent dans la table
`roles_configures` ; le rôle Spatie de chaque entreprise se crée au premier accès qui le porte.

### Le portefeuille clients des commerciaux (26/10)

`PortefeuilleCommercial` (Noyau) : `clients_commerciaux` (un client = un commercial, unique sur le nom
normalisé `Tiers::normaliser`) et `relances_commerciales` (sur le modèle de `relances_recouvrement`,
avec une *prochaine relance* au lieu des niveaux). Les chiffres d'un client (factures du CA, devis,
prospections) se lisent sur son nom, filtrés en SQL et regroupés en PHP — `upper()` ne traite pas les
accents pareil sous SQLite et MySQL. Deux écrans : `commercial.mes-clients` (module Commercial, tous ceux
qui vendent) et `pilotage.portefeuilles-clients` (Superviseur : gérant, superviseur de ville, responsable
commercial).

### Les habilitations : qui voit Modifier et Supprimer (26/10)

`HabilitationsDActions` (Noyau, `Entreprises/Support`) dit si un compte a un geste — `modifier`,
`supprimer` — sur un domaine (`creances`, `pieces_fournisseurs`). Le code fixe les rôles du départ
(`DOMAINES[...]['defaut']`, ce que chaque écran permettait avant) ; la table `habilitations_actions`
porte les écarts décidés en **Maintenance › Habilitations** : un rôle (pour toutes les entreprises)
ou un compte, accordé ou retiré. Ordre : le compte, puis un rôle accordé, puis un rôle du départ non
retiré ; le gérant n'est jamais retiré. Les lignes se lisent une fois par requête (instance du
conteneur, `oublier()` après écriture). Chaque écran lit la même fonction pour **afficher** le bouton
et pour **autoriser** l'action ; les verrous propres à l'écran (rien de réglé, rien d'importé) restent
dans `SuppressionDUneCreance` / `SuppressionDUnePieceFournisseur`.

## Les routes

`routes/web.php` à la racine est **vide**. Chaque module déclare les siennes :

```
Modules/Noyau/routes/web.php             écrans communs à tous les rôles
Modules/SuperAdmin/routes/web.php        plateforme
Modules/Gerant/routes/web.php            direction
Modules/Superviseur/routes/web.php       pilotage d'une ville
Modules/ResponsableSite/routes/web.php   saisie du jour d'un lieu
Modules/Comptabilite/routes/web.php      caisse
Modules/Commercial/routes/web.php        terrain
```

Chaque fichier est chargé par le fournisseur de son module, **dans le groupe de
middleware `web`** — sans quoi les pages authentifiées n'auraient ni session ni
protection CSRF.

## Les écrans (Volt)

Chaque module monte son propre dossier de vues. Un écran est désigné par son chemin
relatif à ce dossier :

| Fichier | Nom Volt |
|---|---|
| `Modules/ResponsableSite/resources/views/saisie/saisie-du-jour.blade.php` | `saisie.saisie-du-jour` |
| `Modules/Gerant/resources/views/gerant/tableau-de-bord.blade.php` | `gerant.tableau-de-bord` |
| `Modules/Noyau/resources/views/commun/messages.blade.php` | `commun.messages` |

Le sous-dossier fait partie du nom : c'est ce qui garantit qu'aucun écran d'un module
n'entre en collision avec celui d'un autre — deux « tableau-de-bord » peuvent coexister
sans ambiguïté.

## Ce qui reste hors des modules, et pourquoi

| Emplacement | Contenu | Raison |
|---|---|---|
| `app/Models/User.php` | le compte utilisateur | Imposé par Laravel (authentification, Fortify) |
| `app/Http/` | middlewares, contrôleurs d'authentification | Chemin imposé par le framework |
| `app/Providers/` | fournisseurs de l'application | Chemin imposé par le framework |
| `resources/views/components/` | briques d'interface (`<x-champ>`, `<x-kpi-card>`…) | Utilisées par tous les modules ; les déplacer imposerait d'écrire `<x-noyau::champ>` partout, sans rien y gagner |
| `resources/views/layouts/`, `auth/` | gabarits et écrans Fortify | Attendus à cet emplacement par Fortify |
| `database/seeders/` | jeux de données | Chargés par `php artisan db:seed`, qui les cherche ici |

Les tables `users`, `sessions`, `jobs`, `cache`, `roles`, `permissions`,
`model_has_roles` et `activity_log` gardent leur nom anglais : elles proviennent de
Laravel, Fortify et Spatie. Les renommer reviendrait à se battre contre ces outils à
chaque mise à jour. Toutes les tables métier, elles, sont en français.

## Ajouter un écran

1. Choisir le module : à quel rôle appartient cet écran ? S'il en concerne plusieurs, il
   va dans le Noyau ou dans le module qui les réunit.
2. Créer le fichier dans `Modules/<Module>/resources/views/<dossier>/mon-ecran.blade.php`.
3. Déclarer la route dans `Modules/<Module>/routes/web.php`, avec son middleware de rôle.
4. Ajouter l'entrée de menu dans `Modules/Noyau/app/Commun/Services/MenuNavigation.php`
   si l'écran doit apparaître dans le bandeau.

## Vérifier que tout tient debout

```bash
php artisan module:list      # les 7 modules doivent être « Enabled »
php artisan route:list       # toutes les routes doivent apparaître
php artisan test             # la suite complète
```

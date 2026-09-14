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
| `SuperAdmin` | tableau de bord, entreprises, accès, administrateurs, journal, maintenance | `super_admin` |
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

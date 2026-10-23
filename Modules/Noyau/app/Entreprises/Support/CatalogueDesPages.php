<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;
use App\Support\SchemaDisponible;
use Illuminate\Support\Str;
use Modules\Noyau\Entreprises\Modeles\RoleConfigure;

/**
 * Les rôles qui se définissent par leurs pages — 22/10.
 *
 * **La demande du propriétaire.** « Crée un rôle TRÉSORERIE : il doit donner accès à tout ce qui
 * concerne la trésorerie, uniquement. » Puis : « au Super admin, une section où je configure les
 * rôles : je mets le nom du rôle, je coche les sections qui doivent apparaître avec les pages que
 * je veux faire apparaître ; et un bouton Modifier pour modifier le rôle. Dans la page Maintenance. »
 *
 * **Deux sortes de rôles, donc.** Les rôles du métier (gérant, superviseur, comptabilité…) restent
 * écrits dans le code : leurs écrans écrivent, et ce qu'ils ont le droit d'y faire se vérifie dans
 * chaque action. Un rôle configuré, lui, n'est qu'une liste de pages à **lire** : il ouvre la route
 * (`RoleOuPagesOuvertes`), construit son menu (`MenuNavigation`) et voit sa ville
 * (`Site::visiblesPour`) — aucune action d'écriture ne le connaît, et c'est voulu : ouvrir une page
 * en lecture se décide d'une case à cocher, engager l'entreprise ne doit pas.
 *
 * **Le catalogue ne liste que des écrans qui se lisent sans rôle du métier** : les indicateurs, la
 * trésorerie, les charges, les fournisseurs. Les écrans communs (messages, notifications, espace
 * personnel) sont ouverts à tout compte connecté et n'ont pas à y être.
 *
 * Le rôle « Trésorerie » est le premier : défini ici, il se modifie dans la Maintenance comme les
 * autres — la version enregistrée l'emporte alors sur celle du code.
 */
final class CatalogueDesPages
{
    /**
     * Section => libellé et pages. Une page : la route du menu, et toutes celles qu'elle ouvre
     * (le détail d'une ligne, l'export…) — `*` vaut « toute route qui commence ainsi ».
     *
     * @var array<string, array{libelle: string, pages: array<string, array{libelle: string, routes: list<string>}>}>
     */
    public const SECTIONS = [
        'tableau' => ['libelle' => 'Tableau de bord', 'pages' => [
            'tableau-de-bord' => ['libelle' => 'Tableau de bord de la direction', 'routes' => ['tableau-de-bord']],
        ]],
        'indicateurs' => ['libelle' => 'Indicateurs', 'pages' => [
            'prospects' => ['libelle' => 'Prospects', 'routes' => ['prospects']],
            'affichage-general' => ['libelle' => 'Affichage général des prospections', 'routes' => ['affichage-general']],
            'devis' => ['libelle' => 'Devis', 'routes' => ['devis', 'devis.detail']],
            'commerciaux' => ['libelle' => 'Commerciaux', 'routes' => ['commerciaux']],
            'chiffre-affaires' => ['libelle' => "Chiffre d'affaires", 'routes' => ['chiffre-affaires']],
            'parc-vehicules' => ['libelle' => 'Parc véhicules', 'routes' => ['parc-vehicules', 'parc-fiche', 'parc-fiche.numero']],
            'mouvements-vehicules' => ['libelle' => 'Entrées / sorties', 'routes' => ['mouvements-vehicules']],
            'clients' => ['libelle' => 'Clients', 'routes' => ['clients']],
            'impayes' => ['libelle' => 'État des impayés', 'routes' => ['impayes', 'impayes.*']],
            'rapprochement-ca-impayes' => ['libelle' => 'Rapprochement CA / impayés', 'routes' => ['rapprochement-ca-impayes']],
        ]],
        'tresorerie' => ['libelle' => 'Trésorerie', 'pages' => [
            'tresorerie' => ['libelle' => 'Trésorerie', 'routes' => ['tresorerie', 'tresorerie.*']],
            'caisse' => ['libelle' => 'Caisse (et caisse par véhicule)', 'routes' => ['caisse', 'caisse.*']],
            'banques' => ['libelle' => 'Banques', 'routes' => ['banques']],
        ]],
        'charges' => ['libelle' => 'Charges', 'pages' => [
            'charges' => ['libelle' => 'Charges & décaissements', 'routes' => ['charges']],
        ]],
        'fournisseurs' => ['libelle' => 'Fournisseurs', 'pages' => [
            'fournisseurs' => ['libelle' => 'Suivi fournisseurs (tableau initial, liste, pièces)', 'routes' => ['fournisseurs', 'fournisseurs.tableau-initial', 'fournisseurs.liste', 'fournisseurs.piece', 'fournisseurs.telecharger']],
            'fournisseurs.fne' => ['libelle' => 'Factures FNE', 'routes' => ['fournisseurs.fne', 'fournisseurs.fne.*']],
            'balance-fournisseurs' => ['libelle' => 'Balance et grand livre fournisseurs', 'routes' => ['balance-fournisseurs', 'fournisseurs.grand-livre']],
            'fournisseurs.decaissements' => ['libelle' => 'Décaissements fournisseurs de la caisse', 'routes' => ['fournisseurs.decaissements']],
            'reglements-fournisseurs' => ['libelle' => 'Règlements fournisseurs', 'routes' => ['reglements-fournisseurs']],
        ]],
    ];

    /**
     * Les rôles définis dans le code. « Tout ce qui concerne la trésorerie, uniquement » : la page
     * Trésorerie et ses deux moitiés, la caisse et les banques (01/10 : « on passe par la tréso »).
     *
     * @var array<string, array{libelle: string, pages: list<string>}>
     */
    public const ROLES_PREDEFINIS = [
        'tresorerie' => ['libelle' => 'Trésorerie', 'pages' => ['tresorerie', 'caisse', 'banques']],
    ];

    /**
     * Tous les rôles configurés : ceux du code, puis ceux de la Maintenance, qui l'emportent.
     * Lus une fois par requête.
     *
     * @return array<string, array{libelle: string, pages: list<string>, enregistre: bool}>
     */
    public static function roles(): array
    {
        if (app()->bound('catalogue-des-pages.roles')) {
            return app('catalogue-des-pages.roles');
        }

        $roles = array_map(fn ($r) => $r + ['enregistre' => false], self::ROLES_PREDEFINIS);

        if (SchemaDisponible::table('roles_configures')) {
            foreach (RoleConfigure::query()->orderBy('libelle')->get() as $role) {
                $roles[$role->nom] = [
                    'libelle' => $role->libelle,
                    'pages' => self::pagesConnues((array) $role->pages),
                    'enregistre' => true,
                ];
            }
        }

        app()->instance('catalogue-des-pages.roles', $roles);

        return $roles;
    }

    /** À appeler après une écriture : la requête en cours relira les rôles. */
    public static function oublier(): void
    {
        app()->forgetInstance('catalogue-des-pages.roles');
    }

    public static function estConfigure(?string $role): bool
    {
        return $role !== null && $role !== '' && ! array_key_exists($role, LibellesRoles::TOUS) && isset(self::roles()[$role]);
    }

    public static function libelleDe(string $role): ?string
    {
        return self::estConfigure($role) ? self::roles()[$role]['libelle'] : null;
    }

    /** Le rôle configuré que porte ce compte, ou null s'il n'a qu'un rôle du métier. */
    public static function roleDe(User $utilisateur): ?string
    {
        foreach ($utilisateur->getRoleNames() as $role) {
            if (self::estConfigure($role)) {
                return $role;
            }
        }

        return null;
    }

    /** @return list<string> les pages ouvertes à ce compte par son rôle configuré */
    public static function pagesDe(User $utilisateur): array
    {
        $role = self::roleDe($utilisateur);

        return $role === null ? [] : self::roles()[$role]['pages'];
    }

    /** Ce compte entre-t-il sur cette route par son rôle configuré ? */
    public static function ouvre(User $utilisateur, ?string $route): bool
    {
        if ($route === null || $route === '') {
            return false;
        }

        foreach (self::pagesDe($utilisateur) as $page) {
            foreach (self::page($page)['routes'] ?? [] as $motif) {
                if (Str::is($motif, $route)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Le menu d'un rôle configuré : une entrée par section cochée, ses pages dessous. Une section
     * d'une seule page devient un onglet simple — un groupe d'un élément est un clic de trop.
     *
     * @return list<array<string, mixed>>
     */
    public static function onglets(User $utilisateur): array
    {
        $pages = self::pagesDe($utilisateur);
        $onglets = [];

        foreach (self::SECTIONS as $section) {
            $retenues = array_values(array_filter(array_keys($section['pages']), fn ($p) => in_array($p, $pages, true)));

            if ($retenues === []) {
                continue;
            }

            $items = array_map(fn ($p) => ['label' => $section['pages'][$p]['libelle'], 'route' => $p], $retenues);

            $onglets[] = count($items) === 1
                ? $items[0]
                : ['label' => $section['libelle'], 'groupe' => $items];
        }

        return $onglets;
    }

    /** La première page du rôle : là où il atterrit après la connexion. */
    public static function accueilDe(User $utilisateur): ?string
    {
        $premier = self::onglets($utilisateur)[0] ?? null;

        return $premier === null ? null : ($premier['route'] ?? $premier['groupe'][0]['route'] ?? null);
    }

    /** @return array{libelle: string, routes: list<string>}|null */
    public static function page(string $cle): ?array
    {
        foreach (self::SECTIONS as $section) {
            if (isset($section['pages'][$cle])) {
                return $section['pages'][$cle];
            }
        }

        return null;
    }

    /**
     * Garde les pages que le catalogue connaît, dans son ordre : une case renvoyée par le navigateur
     * ou une page retirée du catalogue depuis l'enregistrement n'ouvre rien.
     *
     * @param  array<int, mixed>  $pages
     * @return list<string>
     */
    public static function pagesConnues(array $pages): array
    {
        $demandees = array_map('strval', $pages);
        $connues = [];

        foreach (self::SECTIONS as $section) {
            foreach (array_keys($section['pages']) as $cle) {
                if (in_array($cle, $demandees, true)) {
                    $connues[] = $cle;
                }
            }
        }

        return $connues;
    }

    /**
     * Les écrans hors catalogue, regroupés pour la lecture : préfixe de route => libellé.
     *
     * @var array<string, string>
     */
    private const AUTRES_ECRANS = [
        'super-admin.' => 'Plateforme (Super admin)',
        'saisie' => 'Saisie du jour',
        'caissier.' => 'Comptabilité : tableau de bord, encaissements, décaissements',
        'recouvrement.' => 'Recouvrement (pages selon le rôle)',
        'import.' => 'Import (pages selon le rôle)',
        'correspondances' => 'Correspondances',
        'rapprochement.prospections-devis' => 'Rapprochement prospections / devis',
        'mes-prospections' => 'Mes prospections',
        'mes-notes' => 'Mes notes',
        'ma-performance' => 'Ma performance',
        'acces.' => 'Gestion des accès',
        'annuaire' => 'Annuaire',
        'parametres' => 'Paramètres de la direction (barème, objectifs, réaffectation)',
        'bareme-commission' => 'Paramètres de la direction (barème, objectifs, réaffectation)',
        'objectifs-mensuels' => 'Paramètres de la direction (barème, objectifs, réaffectation)',
        'reaffecter' => 'Paramètres de la direction (barème, objectifs, réaffectation)',
    ];

    /**
     * Les rôles du métier et ce qu'ils ouvrent — 23/10 : « même les rôles déjà créés doivent figurer,
     * avec ce qu'ils contiennent ».
     *
     * Lu dans les routes elles-mêmes (leur middleware `role:`), et non recopié : une route ouverte
     * demain à un rôle paraît ici sans qu'on y pense. Les pages du catalogue se rangent dans leurs
     * sections ; les autres écrans (saisie, recouvrement, import…) se disent par module. Le
     * recouvrement et l'import affinent ensuite page par page (`AccesRecouvrement`, `AccesImport`).
     *
     * @return array<string, array{libelle: string, pages: list<string>, autres: list<string>}>
     */
    public static function rolesDuMetier(): array
    {
        $ouvertes = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $nom = $route->getName();

            if ($nom === null) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                    foreach (explode('|', substr($middleware, 5)) as $role) {
                        $ouvertes[trim($role)][] = $nom;
                    }
                }
            }
        }

        $roles = [];

        foreach (LibellesRoles::TOUS as $role => $libelle) {
            $routes = array_unique($ouvertes[$role] ?? []);
            $pages = [];

            foreach (self::SECTIONS as $section) {
                foreach ($section['pages'] as $cle => $page) {
                    // La page s'ouvre au rôle si sa route principale l'admet.
                    if (in_array($page['routes'][0], $routes, true)) {
                        $pages[] = $cle;
                    }
                }
            }

            $autres = [];

            foreach ($routes as $nom) {
                foreach (self::AUTRES_ECRANS as $prefixe => $intitule) {
                    if (str_starts_with($nom, $prefixe)) {
                        $autres[$intitule] = true;

                        break;
                    }
                }
            }

            $roles[$role] = ['libelle' => $libelle, 'pages' => $pages, 'autres' => array_keys($autres)];
        }

        return $roles;
    }

    /** Le nom technique d'un rôle, tiré de son libellé : « Trésorerie Abidjan » → tresorerie_abidjan. */
    public static function nomTechnique(string $libelle): string
    {
        return Str::limit(Str::slug($libelle, '_'), 60, '');
    }
}

<?php

namespace Modules\Noyau\Commun\Services;

use App\Models\User;

/**
 * Construit le menu du bandeau supérieur selon le rôle de l'utilisateur connecté.
 */
class MenuNavigation
{
    public static function pour(User $utilisateur): array
    {
        if ($utilisateur->hasRole('super_admin')) {
            // Un Super Admin secondaire ne voit que les sections qui lui ont été ouvertes :
            // afficher un onglet menant à un 403 n'aurait aucun intérêt.
            $sections = [
                ['label' => 'Tableau de bord', 'route' => 'super-admin.dashboard', 'section' => 'dashboard'],
                ['label' => 'Entreprises', 'route' => 'super-admin.entreprises.index', 'section' => 'entreprises'],
                ['label' => 'Accès', 'route' => 'super-admin.acces.index', 'actifPattern' => 'super-admin.acces.*', 'section' => 'acces'],
                ['label' => 'Administrateurs', 'route' => 'super-admin.administrateurs', 'section' => 'acces'],
                // Les deux lettres du logiciel d'atelier, données personne par personne :
                // c'est ce qui décide de quel atelier reçoit quel travail.
                ['label' => 'Codes', 'route' => 'super-admin.codes', 'section' => 'acces'],
                ['label' => 'Traçabilité', 'route' => 'super-admin.tracabilite', 'section' => 'journal'],
                ['label' => 'Journal', 'route' => 'super-admin.journal.index', 'section' => 'journal'],
                ['label' => 'Messages', 'route' => 'messages', 'section' => null],
                ['label' => 'Notifications', 'route' => 'mes-notifications', 'section' => null],
                ['label' => 'Maintenance', 'route' => 'super-admin.maintenance', 'section' => 'maintenance'],
            ];

            return self::construire(array_filter(
                $sections,
                fn ($onglet) => $onglet['section'] === null || $utilisateur->peutAccederA($onglet['section']),
            ));
        }

        /*
         * Le commercial. Son bandeau portait six onglets de même poids, dont quatre qui ne
         * relèvent pas de son métier : ses notes, sa messagerie, ses notifications, ses
         * réglages. Un bandeau où tout se vaut ne dit plus où aller — les deux écrans qu'il
         * ouvre vingt fois par jour y avaient le même relief que celui qu'il ouvre une fois
         * par mois. Les quatre passent donc sous « Paramètres », comme chez le gérant, et
         * ne restent en tête que sa performance et ses prospections.
         */
        if ($utilisateur->hasRole('commercial')) {
            return self::construire([
                ['label' => 'Ma performance individuelle', 'route' => 'ma-performance'],
                ['label' => 'Mes prospections', 'route' => 'mes-prospections'],
                ['label' => 'Paramètres', 'groupe' => [
                    ['label' => 'Mes notes', 'route' => 'mes-notes'],
                    ['label' => 'Messages', 'route' => 'messages'],
                    ['label' => 'Notifications', 'route' => 'mes-notifications'],
                    ['label' => 'Mon espace', 'route' => 'mon-espace'],
                ]],
            ]);
        }

        if ($utilisateur->hasRole('caissier')) {
            return self::construire([
                ['label' => 'Tableau de bord', 'route' => 'caissier.tableau-de-bord'],
                ['label' => 'Encaissements', 'route' => 'caissier.encaissements'],
                ['label' => 'Décaissements', 'route' => 'caissier.decaissements'],
                // La comptabilité encaisse ce que le recouvrement poursuit : elle
                // décrochait le téléphone sans pouvoir lire ce qu'un client devait.
                // Consultation seulement — voir AccesRecouvrement.
                ['label' => 'Recouvrement', 'route' => 'recouvrement.tableau-de-bord', 'actifPattern' => 'recouvrement.'],
                ['label' => 'Messages', 'route' => 'messages'],
                ['label' => 'Notifications', 'route' => 'mes-notifications'],
                ['label' => 'Paramètres', 'route' => 'mon-espace'],
            ]);
        }

        /*
         * Recouvrement. Les deux rôles du module n'ont que lui : leur bandeau se réduit
         * donc à cette section et aux écrans communs. Les neuf pages sont derrière, dans
         * la barre latérale de la section — un rôle n'y voit que celles qui lui sont
         * ouvertes, et le premier onglet auquel il a droit est sa page d'arrivée.
         */
        if ($utilisateur->hasRole('superviseur_recouvrement') || $utilisateur->hasRole('agent_recouvrement')) {
            $pages = \Modules\Recouvrement\Support\AccesRecouvrement::pagesDe($utilisateur);

            /*
             * Le tableau de bord se détache du reste : c'est la page d'arrivée, celle qui
             * dit d'un coup d'œil ce qui est dû, par qui, depuis quand et à qui c'est
             * confié. Les écrans de travail restent derrière l'onglet « Recouvrement ».
             */
            $onglets = [
                ['label' => 'Tableau de bord', 'route' => 'recouvrement.tableau-de-bord'],
                [
                    'label' => 'Recouvrement',
                    'route' => 'recouvrement.'.(collect($pages)->first(fn ($page) => $page !== 'tableau-de-bord') ?? 'saisie'),
                    'actifPattern' => 'recouvrement.',
                ],
            ];

            /*
             * Le reste se replie sous « Paramètres », comme chez le commercial et le gérant.
             *
             * « Ajouter un accès » n'y figure que pour le superviseur : c'est lui qui nomme
             * ses agents. L'onglet était offert aux deux, et l'agent tombait sur un refus —
             * la route ne lui est pas ouverte, et elle n'a pas à l'être.
             */
            $general = [];

            if ($utilisateur->hasRole('superviseur_recouvrement')) {
                $general[] = ['label' => 'Ajouter un accès', 'route' => 'acces.creer'];
            }

            $general[] = ['label' => 'Messages', 'route' => 'messages'];
            $general[] = ['label' => 'Notifications', 'route' => 'mes-notifications'];
            $general[] = ['label' => 'Mon espace', 'route' => 'mon-espace'];

            $onglets[] = ['label' => 'Paramètres', 'groupe' => $general];

            return self::construire($onglets);
        }

        $onglets = [];

        $parametres = 'mon-espace';

        if ($utilisateur->hasRole('gerant')) {
            $onglets[] = ['label' => 'Tableau de bord', 'route' => 'tableau-de-bord'];
            $parametres = 'parametres';
        }

        if ($utilisateur->hasRole('responsable_ville') || $utilisateur->hasRole('responsable_site')) {
            $onglets[] = ['label' => 'Saisie du jour', 'route' => 'saisie-du-jour'];
        }

        /*
         * Recouvrement. Le gérant a les dix écrans, contentieux et piste d'audit compris.
         *
         * Le superviseur de ville et la comptabilité y entrent en consultation : elle
         * encaisse ce que le recouvrement poursuit, il répond d'un chiffre d'affaires dont
         * l'encours est la moitié qu'on ne lui montrait pas. AccesRecouvrement décide de ce
         * que chacun y trouve ; ici on se contente d'ouvrir la porte à qui en a une.
         */
        if (\Modules\Recouvrement\Support\AccesRecouvrement::ouvertA($utilisateur)) {
            $onglets[] = ['label' => 'Recouvrement', 'route' => 'recouvrement.tableau-de-bord', 'actifPattern' => 'recouvrement.'];
        }

        /*
         * Import. L'onglet mène à la première page ouverte au rôle : le gérant et le
         * superviseur de ville arrivent sur le dépôt, la comptabilité sur le journal —
         * elle consulte les imports sans en faire.
         */
        $pagesImport = \Modules\Import\Support\AccesImport::pagesDe($utilisateur);

        if ($pagesImport !== []) {
            $onglets[] = [
                'label' => 'Import',
                'route' => 'import.'.$pagesImport[0],
                'actifPattern' => 'import.',
            ];
        }

        $onglets[] = [
            'label' => 'Indicateurs',
            'groupe' => [
                ['label' => 'Prospects', 'route' => 'prospects'],
                ['label' => 'Devis', 'route' => 'devis'],
                ['label' => 'Parc véhicules', 'route' => 'parc-vehicules'],
                ['label' => 'Entrées / sorties', 'route' => 'mouvements-vehicules'],
                ['label' => 'Clients', 'route' => 'clients'],
                ['label' => "Chiffre d'affaires", 'route' => 'chiffre-affaires'],
                ['label' => 'Charges', 'route' => 'charges'],
                ['label' => 'Trésorerie', 'route' => 'tresorerie'],
                // La caisse et les fournisseurs suivent la trésorerie : ce sont les deux
                // faces de la même question — ce qui sort en espèces, et ce qu'on doit
                // encore. Toutes deux viennent d'un fichier du logiciel d'atelier.
                ['label' => 'Caisse', 'route' => 'caisse'],
                ['label' => 'Fournisseurs', 'route' => 'fournisseurs'],
                ['label' => 'Commerciaux', 'route' => 'commerciaux'],
            ],
        ];

        $onglets[] = [
            'label' => 'Général',
            'groupe' => [
                ['label' => 'Ajouter un accès', 'route' => 'acces.creer'],
                ['label' => 'Messages', 'route' => 'messages'],
                ['label' => 'Notifications', 'route' => 'mes-notifications'],
                ['label' => 'Paramètres', 'route' => $parametres],
            ],
        ];

        return self::construire($onglets);
    }

    private static function construire(array $onglets): array
    {
        return array_map(function ($onglet) {
            if (isset($onglet['groupe'])) {
                $items = array_map(fn ($item) => [
                    'label' => $item['label'],
                    'route' => route($item['route']),
                    'actif' => request()->routeIs(($item['actifPattern'] ?? $item['route']).'*'),
                ], $onglet['groupe']);

                return [
                    'label' => $onglet['label'],
                    'groupe' => $items,
                    'actif' => collect($items)->contains('actif', true),
                ];
            }

            return [
                'label' => $onglet['label'],
                'route' => route($onglet['route']),
                'actif' => request()->routeIs(($onglet['actifPattern'] ?? $onglet['route']).'*'),
            ];
        }, $onglets);
    }
}

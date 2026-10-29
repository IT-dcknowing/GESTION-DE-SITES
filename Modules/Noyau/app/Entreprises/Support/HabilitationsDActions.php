<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;

/**
 * Qui peut modifier, qui peut supprimer — 26/10.
 *
 * « Une section me permettant de donner des habilitations de suppression et de modification à
 * certains rôles déjà créés ou à certains utilisateurs précisément ; les boutons apparaissent chez
 * ceux qui ont ces habilitations. »
 *
 * **Le code dit qui a le geste au départ** (`defaut`) : ce que chaque écran permettait avant le
 * 26/10, pour qu'une table vide ne change rien. La Maintenance y ajoute un rôle ou un compte, ou
 * retire le geste à un rôle du départ — sauf au gérant, qui répond de l'entreprise.
 *
 * L'ordre de lecture : ce qui est dit du compte lui-même l'emporte ; sinon, un de ses rôles qui a
 * reçu le geste le lui donne ; sinon, un rôle du départ qui ne l'a pas perdu. Le bouton se lit ici
 * **et** l'action se revérifie ici : un bouton caché n'a jamais autorisé personne.
 *
 * Ce qui ne se décide pas ici : les verrous de chaque écran (une créance réglée ou importée ne
 * s'efface pas, une ligne venue d'un fichier garde ses colonnes). L'habilitation ouvre le geste,
 * elle ne lève pas ces verrous.
 */
final class HabilitationsDActions
{
    public const DOMAINES = [
        'creances' => [
            'libelle' => 'Créances — état des impayés',
            'actions' => [
                'modifier' => [
                    'libelle' => 'Modifier une créance',
                    'defaut' => ['gerant', 'responsable_ville', 'responsable_site', 'caissier', 'superviseur_recouvrement', 'agent_recouvrement'],
                ],
                'supprimer' => [
                    'libelle' => 'Supprimer une créance',
                    'defaut' => ['gerant'],
                ],
            ],
        ],
        // La page « Fusion des clients » de l'état des impayés — 27/10 : « une page que je pourrai
        // attribuer à partir du Super admin ». Elle ne fait plus que la fusion depuis le 28/10 ; la clé
        // reste la même, et avec elle les habilitations déjà accordées en ligne.
        'clients_impayes' => [
            'libelle' => "Clients de l'état des impayés",
            'actions' => [
                'fusionner' => [
                    'libelle' => 'Fusion des clients (état des impayés)',
                    'defaut' => ['gerant'],
                ],
            ],
        ],
        // La correction des clients, sur les données du recouvrement — 28/10 : « la correction part dans
        // le recouvrement, car elle doit puiser dans les données du recouvrement ». Le gérant seul par
        // défaut (le propriétaire, 28/10 : pas le superviseur du recouvrement d'office).
        'tiers_recouvrement' => [
            'libelle' => 'Clients du recouvrement',
            'actions' => [
                'corriger' => [
                    'libelle' => 'Correction des clients (recouvrement)',
                    'defaut' => ['gerant'],
                ],
            ],
        ],
        'pieces_fournisseurs' => [
            'libelle' => 'Pièces fournisseurs — charges fournisseurs',
            'actions' => [
                'modifier' => [
                    'libelle' => 'Modifier une pièce',
                    'defaut' => ['gerant', 'responsable_ville', 'caissier'],
                ],
                // Aucun écran n'effaçait une pièce avant le 26/10 : le gérant le peut désormais.
                'supprimer' => [
                    'libelle' => 'Supprimer une pièce',
                    'defaut' => ['gerant'],
                ],
            ],
        ],
    ];

    /** Le rôle à qui le geste ne se retire pas. */
    public const INAMOVIBLE = 'gerant';

    public static function accorde(?User $compte, string $domaine, string $action): bool
    {
        $definition = self::DOMAINES[$domaine]['actions'][$action] ?? null;

        if ($compte === null || $definition === null) {
            return false;
        }

        $lignes = self::lignes()->where('domaine', $domaine)->where('action', $action);

        $duCompte = $lignes->firstWhere('user_id', $compte->id);

        if ($duCompte !== null) {
            return (bool) $duCompte->accorde;
        }

        $roles = $compte->getRoleNames()->all();

        if ($lignes->whereNull('user_id')->where('accorde', true)->whereIn('role', $roles)->isNotEmpty()) {
            return true;
        }

        $retires = $lignes->whereNull('user_id')->where('accorde', false)->pluck('role')
            ->reject(fn ($role) => $role === self::INAMOVIBLE)->all();

        return array_intersect($roles, array_diff($definition['defaut'], $retires)) !== [];
    }

    /**
     * Les rôles qui ont le geste, tel que la Maintenance le lit : ceux du départ moins les retirés,
     * plus les accordés.
     *
     * @return array{defaut: list<string>, retires: list<string>, accordes: list<string>, comptes: Collection<int, HabilitationAction>}
     */
    public static function etat(string $domaine, string $action): array
    {
        $lignes = self::lignes()->where('domaine', $domaine)->where('action', $action);
        $parRole = $lignes->whereNull('user_id');

        return [
            'defaut' => self::DOMAINES[$domaine]['actions'][$action]['defaut'] ?? [],
            'retires' => $parRole->where('accorde', false)->pluck('role')->values()->all(),
            'accordes' => $parRole->where('accorde', true)->pluck('role')->values()->all(),
            'comptes' => $lignes->whereNotNull('user_id')->values(),
        ];
    }

    public static function existe(string $domaine, string $action): bool
    {
        return isset(self::DOMAINES[$domaine]['actions'][$action]);
    }

    /**
     * Toutes les lignes, lues une fois par requête : un tableau de cent créances demande cent fois
     * son bouton.
     *
     * @return Collection<int, HabilitationAction>
     */
    public static function lignes(): Collection
    {
        if (app()->bound('habilitations-actions')) {
            return app('habilitations-actions');
        }

        // Avant la migration du 26/10, le code seul décide.
        $lignes = Schema::hasTable('habilitations_actions')
            ? HabilitationAction::query()->with('utilisateur:id,name,email,entreprise_id')->get()
            : collect();

        app()->instance('habilitations-actions', $lignes);

        return $lignes;
    }

    /** À appeler après une écriture : la requête en cours relira les habilitations. */
    public static function oublier(): void
    {
        app()->forgetInstance('habilitations-actions');
    }
}

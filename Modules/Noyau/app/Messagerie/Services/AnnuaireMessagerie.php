<?php

namespace Modules\Noyau\Messagerie\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Décide qui peut écrire à qui.
 *
 * Règles retenues :
 *  - Super Admin  : écrit à tout le monde, toutes entreprises confondues.
 *  - Gérant       : tout son personnel (responsables + commerciaux) et les Super Admins.
 *  - Responsable  : le gérant, les autres responsables et les commerciaux de SON entreprise.
 *  - Commercial   : les responsables et les autres commerciaux de SON entreprise.
 *
 * Aucune communication entre deux entreprises différentes, hors Super Admin.
 */
class AnnuaireMessagerie
{
    /**
     * Destinataires autorisés, groupés par rôle pour l'affichage.
     *
     * @return Collection<string, Collection<int, User>>
     */
    public static function destinatairesGroupes(User $expediteur): Collection
    {
        $utilisateurs = self::destinataires($expediteur);
        $roles = User::nomsRolesParUtilisateur($utilisateurs->pluck('id'));

        return $utilisateurs
            ->groupBy(fn (User $u) => self::libelleRole($roles[$u->id] ?? ''))
            ->sortKeys();
    }

    /**
     * Liste plate des destinataires autorisés pour cet expéditeur.
     *
     * @return Collection<int, User>
     */
    public static function destinataires(User $expediteur): Collection
    {
        $requete = User::query()
            ->where('id', '!=', $expediteur->id)
            ->where('est_actif', true)
            ->orderBy('name');

        if ($expediteur->estSuperAdmin()) {
            return $requete->get();
        }

        if (! $expediteur->entreprise_id) {
            return collect();
        }

        // Le responsable commercial est un encadrant : il figure donc dans cette liste,
        // ce qui le rend joignable par tous ceux qui joignent déjà l'encadrement — et
        // d'abord par les vendeurs qu'il anime.
        $encadrement = ['responsable_ville', 'responsable_site', 'responsable_commercial'];

        $rolesVises = match (true) {
            $expediteur->hasRole('gerant') => [...$encadrement, 'commercial'],
            $expediteur->hasRole('responsable_ville') => ['gerant', ...$encadrement, 'commercial'],
            $expediteur->hasRole('responsable_site') => ['gerant', ...$encadrement, 'commercial'],
            $expediteur->hasRole('responsable_commercial') => ['gerant', ...$encadrement, 'commercial'],
            $expediteur->hasRole('commercial') => [...$encadrement, 'commercial'],
            default => [],
        };

        if ($rolesVises === []) {
            return collect();
        }

        // Le cloisonnement inter-entreprises tient à ce seul where : jamais de dérogation ici.
        $membres = $requete->clone()
            ->where('entreprise_id', $expediteur->entreprise_id)
            ->whereIn('id', self::idsAyantUnRole($rolesVises))
            ->get();

        // Le gérant est le seul de l'entreprise à pouvoir joindre la plateforme.
        if ($expediteur->hasRole('gerant')) {
            $membres = $membres->merge(
                $requete->clone()->whereIn('id', self::idsAyantUnRole(['super_admin']))->get()
            );
        }

        return $membres->unique('id')->sortBy('name')->values();
    }

    /**
     * Identifiants des utilisateurs portant l'un de ces rôles, toutes équipes confondues.
     *
     * La relation roles() de Spatie est filtrée sur l'équipe courante : depuis une entreprise
     * elle ne verrait jamais les Super Admins, qui n'appartiennent à aucune équipe. On lit
     * donc la table de liaison directement.
     *
     * @param  array<int, string>  $roles
     * @return array<int, int>
     */
    private static function idsAyantUnRole(array $roles): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', $roles)
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Vrai si l'expéditeur a le droit d'écrire à ce destinataire. */
    public static function peutEcrireA(User $expediteur, int $destinataireId): bool
    {
        return self::destinataires($expediteur)->contains('id', $destinataireId);
    }

    /**
     * Filtre une liste d'identifiants pour ne garder que les destinataires autorisés.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    public static function filtrer(User $expediteur, array $ids): array
    {
        $autorises = self::destinataires($expediteur)->pluck('id')->all();

        return array_values(array_intersect(array_map('intval', $ids), $autorises));
    }

    private static function libelleRole(string $roles): string
    {
        return match (true) {
            str_contains($roles, 'super_admin') => 'Plateforme',
            str_contains($roles, 'gerant') => 'Direction',
            str_contains($roles, 'responsable_ville') => 'Superviseurs de ville',
            str_contains($roles, 'responsable_site') => 'Responsables de site',
            str_contains($roles, 'commercial') => 'Commerciaux',
            default => 'Autres',
        };
    }
}

<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Qui a le droit d'agir sur l'accès de qui.
 *
 * Un écran qui affiche un bouton n'est pas une autorisation : la même méthode publique
 * est appelable depuis le navigateur, avec l'identifiant que l'on veut. La question se
 * tranche donc ici, à partir des deux comptes, et l'écran ne fait que refléter la
 * réponse.
 *
 * La règle est celle du terrain : on n'agit que sur quelqu'un placé strictement sous
 * soi. Deux personnes de même rang ne se révoquent pas l'une l'autre — deux
 * superviseurs qui se coupent mutuellement l'accès laissent une ville sans personne.
 *
 * Les responsables de site ne gèrent pas d'accès. Leurs commerciaux sont rattachés à
 * une ville, pas à un lieu : « ceux de mon site » n'aurait pas de définition sûre, et
 * une définition floue en matière de droits finit toujours par ouvrir trop.
 */
class HierarchieAcces
{
    /** Du plus large au plus étroit. Un rang plus grand est placé plus bas. */
    private const RANGS = [
        'super_admin' => -1,
        'gerant' => 0,
        'responsable_ville' => 1,
        'responsable_site' => 2,
        // Même rang que le responsable de site : il encadre des vendeurs, pas des
        // lieux, mais ni l'un ni l'autre ne gère d'accès et aucun ne commande l'autre.
        'responsable_commercial' => 2,
        // Le superviseur recouvrement relève directement de la direction : sa filière est
        // parallèle à celle des villes, et non subordonnée à elle. Il est placé au même
        // rang qu'un superviseur de ville, ce qui a une conséquence voulue — aucun des
        // deux ne peut agir sur l'accès de l'autre.
        'superviseur_recouvrement' => 1,
        'caissier' => 3,
        'commercial' => 3,
        'agent_recouvrement' => 3,
    ];

    /** Rôles autorisés à gérer les accès d'autrui au sein d'une entreprise. */
    private const ENCADRANTS = ['gerant', 'responsable_ville', 'superviseur_recouvrement'];

    /**
     * Le motif du refus, ou null si l'acte est permis.
     *
     * Vaut pour toute action portant sur le compte d'un tiers : activer, révoquer,
     * reprendre, supprimer.
     */
    public static function motifDuRefus(User $acteur, User $cible): ?string
    {
        if ($acteur->id === $cible->id) {
            return "Vous ne pouvez pas agir sur votre propre accès depuis cet écran.";
        }

        if ($cible->est_fondateur) {
            return "Le compte fondateur de la plateforme n'est pas modifiable.";
        }

        if ($acteur->hasRole('super_admin')) {
            // Le Super Admin est hors entreprise : sa limite est celle des autres
            // administrateurs, qu'il ne gère que s'il les a lui-même ouverts.
            return $cible->estSuperAdmin() && ! $acteur->peutGerer($cible)
                ? 'Cet administrateur ne relève pas de votre périmètre.'
                : null;
        }

        if (! $acteur->entreprise_id || $cible->entreprise_id !== $acteur->entreprise_id) {
            return "Cet accès n'appartient pas à votre entreprise.";
        }

        $roleActeur = self::roleDe($acteur);

        if (! in_array($roleActeur, self::ENCADRANTS, true)) {
            return "Votre rôle ne permet pas de gérer les accès.";
        }

        if (self::rang(self::roleDe($cible)) <= self::rang($roleActeur)) {
            return "Vous ne pouvez agir que sur un accès placé sous le vôtre.";
        }

        if ($roleActeur === 'responsable_ville' && ! in_array($cible->ville_id, self::villesDe($acteur), true)) {
            return "Cet accès ne relève pas de votre ville.";
        }

        // Le rang seul ouvrirait trop : un superviseur recouvrement encadre des agents de
        // recouvrement, pas les commerciaux ni la comptabilité, qui sont au même rang mais
        // dans une autre filière. La hiérarchie n'est pas qu'une hauteur, c'est aussi une
        // branche.
        if ($roleActeur === 'superviseur_recouvrement' && self::roleDe($cible) !== 'agent_recouvrement') {
            return "Vous ne gérez que les accès des agents de recouvrement.";
        }

        return null;
    }

    public static function autorise(User $acteur, User $cible): bool
    {
        return self::motifDuRefus($acteur, $cible) === null;
    }

    /**
     * Le rôle inscrit en base, sans dépendre de l'équipe posée dans la requête.
     *
     * getRoleNames() filtre sur l'équipe courante : depuis un écran Super Admin, qui
     * n'est rattaché à aucune entreprise, il ne renverrait rien — et le contrôle
     * porterait alors sur un rôle vide, c'est-à-dire sur rien.
     */
    public static function roleDe(User $compte): string
    {
        return (string) DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.model_id', $compte->id)
            ->value('roles.name');
    }

    private static function rang(string $role): int
    {
        // Un rôle inconnu est traité comme le plus bas : il ne commande personne.
        return self::RANGS[$role] ?? PHP_INT_MAX;
    }

    /** @return array<int, int> */
    private static function villesDe(User $superviseur): array
    {
        $villes = Ville::withoutGlobalScopes()
            ->where('entreprise_id', $superviseur->entreprise_id)
            ->where('responsable_id', $superviseur->id)
            ->pluck('id')->all();

        if ($superviseur->ville_id) {
            $villes[] = $superviseur->ville_id;
        }

        return array_map('intval', array_unique($villes));
    }
}

<?php

namespace Modules\Noyau\Entreprises\Support;

/**
 * L'intitulé lisible d'un rôle, en un seul endroit.
 *
 * Le nom technique et l'intitulé ne coïncident pas, et ne le peuvent pas : « caissier »
 * est inscrit dans la table `roles`, dans les middlewares, dans les codes auteur et dans
 * des URL déjà en circulation. Le renommer en base reviendrait à réécrire tout cela pour
 * un mot affiché — et à casser au passage les liens que les gens ont en favori.
 *
 * On sépare donc les deux : la base garde son vocabulaire, l'écran affiche le métier.
 * Le métier, ici, s'appelle la Comptabilité.
 *
 * Cette table était recopiée dans quatre fichiers. Une cinquième copie aurait fini par
 * diverger — c'est toujours celle qu'on oublie qui reste sous les yeux de l'utilisateur.
 */
class LibellesRoles
{
    /** Nom technique → intitulé affiché. */
    public const TOUS = [
        'super_admin' => 'Super administrateur',
        'gerant' => 'Gérant',
        'responsable_ville' => 'Superviseur de ville',
        'responsable_site' => 'Responsable de site',
        'responsable_commercial' => 'Responsable commercial',
        'caissier' => 'Comptabilité',
        'commercial' => 'Commercial',
        'superviseur_recouvrement' => 'Superviseur recouvrement',
        'agent_recouvrement' => 'Agent de recouvrement',
    ];

    /**
     * L'intitulé d'un rôle. Un rôle inconnu se rend tel quel plutôt que de disparaître :
     * une cellule vide dans une liste d'accès se lit « aucun rôle », ce qui est faux.
     */
    public static function de(?string $role): string
    {
        $role = trim((string) $role);

        if ($role === '') {
            return '—';
        }

        return self::TOUS[$role] ?? $role;
    }

    /**
     * Traduit une énumération « role_a, role_b » telle que la renvoie
     * User::nomsRolesParUtilisateur(), qui agrège les rôles d'un compte en une chaîne.
     */
    public static function liste(?string $roles): string
    {
        $roles = trim((string) $roles);

        if ($roles === '') {
            return '—';
        }

        return collect(explode(',', $roles))
            ->map(fn (string $role) => self::de($role))
            ->implode(', ');
    }
}

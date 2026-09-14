<?php

namespace Modules\Noyau\Entreprises\Services;

use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Prépare une entreprise fraîchement créée : ses rôles (scopés à son équipe).
 * Rôles disponibles pour toute entreprise : gerant, responsable_ville, responsable_site,
 * commercial, caissier. Le rôle super_admin, lui, vit hors entreprise sur l'équipe
 * plateforme (id 0).
 */
class ProvisionneurEntreprise
{
    public const ROLES = [
        'gerant', 'responsable_ville', 'responsable_site', 'commercial', 'caissier',
        // Recouvrement : le superviseur pilote et arbitre jusqu'à la mise en demeure,
        // l'agent relance et encaisse. Ni l'un ni l'autre ne crée la créance qu'il
        // poursuit — c'est la séparation des fonctions qui rend le journal crédible.
        'superviseur_recouvrement', 'agent_recouvrement',
        // Le responsable commercial anime les vendeurs d'une ville — et vend lui-même.
        // Il ne touche ni au recouvrement ni aux imports : encadrer des vendeurs n'a
        // aucun rapport avec la poursuite d'une créance.
        'responsable_commercial',
    ];

    public static function creerRoles(Entreprise $entreprise): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($entreprise->id);

        foreach (self::ROLES as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
                'entreprise_id' => $entreprise->id,
            ]);
        }
    }
}

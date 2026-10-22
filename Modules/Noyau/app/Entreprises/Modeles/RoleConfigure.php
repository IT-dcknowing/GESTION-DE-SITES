<?php

namespace Modules\Noyau\Entreprises\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un rôle défini par ses pages, depuis la Maintenance du Super admin — 22/10.
 *
 * Il vaut pour toute la plateforme, et n'appartient donc à aucune entreprise : c'est la définition
 * qui est commune, le rôle Spatie de chaque entreprise se crée quand un accès le porte. Voir
 * `CatalogueDesPages`.
 */
#[Fillable(['nom', 'libelle', 'pages', 'cree_par', 'modifie_par'])]
class RoleConfigure extends Model
{
    protected $table = 'roles_configures';

    protected function casts(): array
    {
        return ['pages' => 'array'];
    }
}

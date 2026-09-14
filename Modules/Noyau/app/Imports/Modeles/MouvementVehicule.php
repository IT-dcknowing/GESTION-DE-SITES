<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * L'entrée ou la sortie d'un véhicule de l'atelier.
 *
 * La table existe, le lecteur n'existe pas encore — et c'est exactement ce qui avait été
 * demandé pour les états qui ne sortent qu'en PDF : préparer les colonnes et la
 * destination, sans prétendre lire un format qu'on n'a pas.
 *
 * Le jour où l'export sort en Excel, ou le jour où l'API répond, il n'y aura qu'un lecteur
 * à écrire : la forme d'arrivée est déjà fixée, et le reste de la chaîne — rattachement,
 * dédoublonnage, journal — fonctionne sans rien savoir de la provenance.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'sens', 'date', 'numero_fiche', 'immatriculation', 'marque', 'modele',
    'client', 'motif', 'observations', 'code_agent',
])]
class MouvementVehicule extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'mouvements_vehicules';

    public const ENTREE = 'entree';

    public const SORTIE = 'sortie';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }
}

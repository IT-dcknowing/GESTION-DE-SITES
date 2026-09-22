<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Commun\Concerns\PeutVenirDUnImport;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Un paiement fournisseur, tel que la comptabilité l'a enregistré.
 *
 * Le code de règlement — « F-REG N°000300 » — est ce qui identifie le paiement : c'est lui
 * qui permet de redéposer le fichier sans doubler ses lignes, et c'est lui qu'on cite quand
 * un fournisseur conteste avoir été payé.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'date_reglement', 'code_reglement', 'fournisseur', 'mode_reglement', 'montant',
    'code_agent', 'source_rattachement', 'rattachement_presume',
])]
class ReglementFournisseur extends Model
{
    use AppartientAUneEntreprise, PeutVenirDUnImport;

    protected $table = 'reglements_fournisseur';

    protected function casts(): array
    {
        return [
            'date_reglement' => 'date',
            'montant' => 'integer',
            'rattachement_presume' => 'boolean',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}

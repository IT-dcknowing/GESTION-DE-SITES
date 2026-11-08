<?php

namespace Modules\Noyau\Rapport\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * Une ligne du dépôt de rapport : un impôt dû ou payé, une cotisation CNPS, un salaire, une pièce de
 * récapitulatif. `compte` dit si elle entre dans les chiffres — un récap n'y entre jamais.
 */
#[Fillable([
    'entreprise_id', 'depot_rapport_id', 'nature', 'statut', 'exercice', 'date_piece', 'libelle',
    'tiers', 'reference', 'montant', 'compte', 'saisie_manuelle', 'empreinte',
])]
class PieceRapport extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'pieces_rapport';

    protected function casts(): array
    {
        return [
            'date_piece' => 'date',
            'montant' => 'integer',
            'compte' => 'boolean',
            'saisie_manuelle' => 'boolean',
            'exercice' => 'integer',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(DepotRapport::class, 'depot_rapport_id');
    }
}

<?php

namespace Modules\Noyau\Rapport\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/** Un fichier déposé dans le module Rapport — voir `DepotDeRapport`. */
#[Fillable([
    'entreprise_id', 'user_id', 'nom_original', 'chemin', 'empreinte_fichier', 'nature_declaree',
    'statut', 'nb_lignes', 'nb_nouvelles', 'nb_doublons', 'message',
])]
class DepotRapport extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'depots_rapport';

    public function pieces(): HasMany
    {
        return $this->hasMany(PieceRapport::class, 'depot_rapport_id');
    }

    public function deposant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

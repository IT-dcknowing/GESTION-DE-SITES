<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * Une facture d'achat normalisée, telle que le portail FNE la rend — voir
 * `Modules\Noyau\Imports\Services\AchatsFne`.
 *
 * Elle est un **constat** : elle ne crée ni dette ni charge par elle-même. Elle confirme une
 * ligne du suivi fournisseurs (`facture_fournisseur_id`) — retrouvée par le rapprochement, ou
 * créée par « Porter au suivi ». Un avoir porte des montants négatifs, et son exercice est
 * l'année de sa date.
 */
#[Fillable([
    'entreprise_id', 'releve_fne_id', 'facture_fournisseur_id',
    'reference', 'fne_id', 'type', 'sous_type', 'est_avoir', 'statut_portail',
    'date_facture', 'exercice', 'emetteur_ncc', 'emetteur_nom',
    'montant_ht', 'montant_tva', 'timbre', 'montant_ttc', 'moyen_paiement', 'contenu',
])]
class FactureFne extends Model
{
    use \App\Casts\CompareLesMontants;

    use AppartientAUneEntreprise;

    protected $table = 'factures_fne';

    protected function casts(): array
    {
        return [
            'date_facture' => 'date',
            'est_avoir' => 'boolean',
            'exercice' => 'integer',
            'montant_ht' => \App\Casts\Montant::class,
            'montant_tva' => \App\Casts\Montant::class,
            'timbre' => \App\Casts\Montant::class,
            'montant_ttc' => \App\Casts\Montant::class,
            'contenu' => 'array',
        ];
    }

    public function pieceDuSuivi(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }
}

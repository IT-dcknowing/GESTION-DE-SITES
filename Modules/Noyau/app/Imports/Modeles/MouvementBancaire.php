<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Commun\Concerns\PeutVenirDUnImport;
use Modules\Noyau\Exploitation\Modeles\Banque;

/**
 * Une opération du relevé bancaire, tel que la caissière le tient — voir la migration du
 * 07/10 et `FormatDuReleveBancaire`.
 *
 * À ne pas confondre avec `PieceBancaire`, qui est une écriture du logiciel comptable : ici
 * c'est la banque qui parle, avec le solde qu'elle annonce après chaque opération.
 */
#[Fillable([
    'entreprise_id', 'banque_id', 'lot_import_id', 'date_operation', 'libelle', 'motif',
    'debit', 'credit', 'sens', 'solde_annonce', 'contrepartie', 'nature', 'rang', 'cle',
])]
class MouvementBancaire extends Model
{
    use AppartientAUneEntreprise;
    use PeutVenirDUnImport;

    public const ENTREE = 'entree';

    public const SORTIE = 'sortie';

    protected $table = 'mouvements_bancaires';

    protected function casts(): array
    {
        return [
            'date_operation' => 'date',
            'debit' => 'integer',
            'credit' => 'integer',
            'solde_annonce' => 'integer',
            'rang' => 'integer',
        ];
    }

    public function banque(): BelongsTo
    {
        return $this->belongsTo(Banque::class);
    }

    /** Le montant, sans son signe : le sens dit de quel côté il va. */
    public function montant(): int
    {
        return $this->sens === self::ENTREE ? (int) $this->credit : (int) $this->debit;
    }
}

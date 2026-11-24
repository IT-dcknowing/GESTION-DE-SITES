<?php

namespace Modules\Noyau\Comptabilite\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * Une opération comptable — une pièce, ou un règlement — 24/11.
 *
 * Toutes ses lignes partagent son numéro de saisie et sa référence. Une facture et son règlement
 * sont deux opérations : c'est la règle de SELFLOW, reprise telle quelle (§ 0 des règles
 * d'imputation). Une opération n'est jamais réécrite : si sa source change, elle est
 * contre-passée et une nouvelle la remplace (`GenerateurDEcritures`).
 */
class OperationComptable extends Model
{
    use AppartientAUneEntreprise;

    public const A_VERIFIER = 'a_verifier';

    public const PRETE = 'prete';

    public const CONTREPASSEE = 'contrepassee';

    protected $table = 'operations_comptables';

    protected $guarded = ['id'];

    protected $casts = [
        'date_operation' => 'date',
        'deversee_le' => 'datetime',
        'est_contrepassation' => 'boolean',
    ];

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneComptable::class, 'operation_id')->orderBy('rang');
    }
}

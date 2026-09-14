<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Une facture fournisseur : ce que l'entreprise doit, et pour quand.
 *
 * C'est la table qui manquait au KPI « dettes ». Elle porte aussi la marge, parce que le
 * fichier qui l'alimente la porte : le suivi fournisseurs rapproche, pièce par pièce, le
 * montant acheté et le montant refacturé au client. C'est la seule source de marge
 * existante dans toute la chaîne.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'numero_piece', 'nature_piece', 'numero_bc', 'fournisseur',
    'date_facture', 'date_reception', 'date_echeance', 'date_reglement',
    'montant', 'montant_regle', 'reste_a_payer', 'montant_refacture', 'marge',
    'mode_reglement', 'imputation', 'immatriculation', 'numero_fiche',
    'numero_facture_client', 'observations',
    'code_agent', 'source_rattachement', 'rattachement_presume',
])]
class FactureFournisseur extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'factures_fournisseurs';

    protected function casts(): array
    {
        return [
            'date_facture' => 'date',
            'date_reception' => 'date',
            'date_echeance' => 'date',
            'date_reglement' => 'date',
            'rattachement_presume' => 'boolean',
            'montant' => 'integer',
            'montant_regle' => 'integer',
            'reste_a_payer' => 'integer',
            'montant_refacture' => 'integer',
            'marge' => 'integer',
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

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }

    /** Vrai quand la dette est éteinte. */
    public function estReglee(): bool
    {
        return $this->reste_a_payer <= 0;
    }

    /**
     * Vrai quand l'échéance est passée et qu'il reste à payer.
     *
     * Une facture sans date d'échéance n'est jamais en retard : on ne peut pas être en
     * retard sur une date qu'on ignore, et l'annoncer comme telle ferait paniquer pour rien.
     */
    public function estEnRetard(): bool
    {
        return $this->date_echeance !== null
            && ! $this->estReglee()
            && $this->date_echeance->isPast();
    }
}

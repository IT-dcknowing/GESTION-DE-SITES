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
    // Le lettrage, depuis le 12/10 : la facture que ce règlement solde — posé par « Régler ».
    'facture_fournisseur_id', 'saisi_par',
    'date_reglement', 'code_reglement', 'fournisseur', 'mode_reglement', 'montant',
    'code_agent', 'source_rattachement', 'rattachement_presume',
    // La sortie de caisse qui paie ce règlement — 20/10.
    'mouvement_caisse_id',
])]
class ReglementFournisseur extends Model
{
    use \App\Casts\CompareLesMontants;

    use AppartientAUneEntreprise, PeutVenirDUnImport;

    protected $table = 'reglements_fournisseur';

    protected function casts(): array
    {
        return [
            'date_reglement' => 'date',
            'montant' => \App\Casts\Montant::class,
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

    /** La pièce du suivi que ce règlement solde — le lettrage, posé par « Régler » ou « Suivre ». */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }
}

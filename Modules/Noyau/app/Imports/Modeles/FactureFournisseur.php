<?php

namespace Modules\Noyau\Imports\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Concerns\MontreLesColonnesDuFichier;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;

/**
 * Une facture fournisseur : ce que l'entreprise doit, et pour quand.
 *
 * C'est la table qui manquait au KPI « dettes ». Elle porte aussi la marge, parce que le
 * fichier qui l'alimente la porte : le suivi fournisseurs rapproche, pièce par pièce, le
 * montant acheté et le montant refacturé au client. C'est la seule source de marge
 * existante dans toute la chaîne.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id', 'user_id',
    'numero_piece', 'nature_piece', 'numero_bc', 'fournisseur',
    'date_facture', 'date_reception', 'date_echeance', 'date_reglement',
    'montant', 'montant_regle', 'reste_a_payer', 'montant_refacture', 'marge',
    'mode_reglement', 'imputation', 'immatriculation', 'numero_fiche',
    'numero_facture_client', 'observations',
    // Les colonnes du classeur tenu à la main, entrées le 22/09 : l'import n'en lisait
    // que dix-huit sur quarante. Voir FormatDesFournisseurs.
    'mois', 'section', 'type_transaction', 'vehicule', 'numero_cheque', 'numero_feb',
    'code_piece', 'numero_facture_achat', 'numero_facture_vente',
    'montant_ht', 'tva', 'tva_2', 'difference', 'montant_net_achat', 'montant_net_vente',
    'quantite_totale', 'quantite_refacturee', 'taux', 'resultat_indicatif',
    'delai_reglement', 'arrive_a_echeance',
    'observations_facturation', 'commentaires', 'actions_a_mener',
    'code_agent', 'source_rattachement', 'rattachement_presume',
])]
class FactureFournisseur extends Model
{
    use AppartientAUneEntreprise;
    use MontreLesColonnesDuFichier;

    protected $table = 'factures_fournisseurs';

    /**
     * Les quarante et une colonnes de la pièce viennent de là, et la page les lit de là.
     *
     * La page de détail recopiait la liste à la main et en avait perdu sept — dont
     * « TVA 2 », que l'import explique pourtant par écrit conserver exprès. Une liste
     * recopiée diverge de sa source ; celle-ci ne peut plus.
     */
    public static function formatDOrigine(): string
    {
        return FormatDesFournisseurs::class;
    }

    /**
     * Les colonnes en francs, pour qu'un montant s'affiche comme un montant.
     *
     * « RESULTAT INDICATIF » n'en est pas, malgré son nom : le classeur y écrit une
     * appréciation — « Marge positive ». La page de détail la passait jusqu'ici dans le
     * formateur de montants, et affichait donc « 0 F » pour toutes les pièces qui en
     * portent une.
     */
    protected function colonnesEnFrancs(): array
    {
        return ['montant', 'montant_ht', 'tva', 'tva_2', 'montant_refacture', 'difference',
            'montant_regle', 'reste_a_payer', 'montant_net_achat', 'montant_net_vente', 'marge'];
    }

    /**
     * La colonne SITE ne devient pas une colonne : elle devient un rattachement.
     *
     * Elle se relit donc par la relation, et l'atelier passe avant la ville — c'est le
     * plus précis des deux quand les deux sont connus.
     */
    protected function lecturesParticulieres(): array
    {
        return ['site' => fn (self $piece) => $piece->site?->nom ?? $piece->ville?->nom];
    }

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
            'mois' => 'integer',
            'montant_ht' => 'integer',
            'tva' => 'integer',
            'tva_2' => 'integer',
            'difference' => 'integer',
            'montant_net_achat' => 'integer',
            'montant_net_vente' => 'integer',
            // Les deux quantités et le taux gardent leurs décimales : ce ne sont pas des
            // francs, et arrondir une quantité de 2,5 litres à 3 fausserait la marge.
            'quantite_totale' => 'decimal:3',
            'quantite_refacturee' => 'decimal:3',
            'taux' => 'decimal:4',
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

    /**
     * Qui a saisi cette pièce à la main — vide pour une ligne venue d'un fichier.
     *
     * Les deux relations se lisent ensemble : un lot et pas d'auteur, c'est un import ;
     * un auteur et pas de lot, c'est une saisie. Aucune ligne n'a les deux.
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

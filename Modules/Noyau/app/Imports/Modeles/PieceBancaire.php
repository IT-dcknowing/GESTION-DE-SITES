<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Commun\Concerns\PeutVenirDUnImport;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Banque;

/**
 * Une pièce passée sur un compte bancaire, telle que le logiciel comptable l'exporte.
 *
 * **Le compte ne vient pas du fichier.** Sur l'écran « Liste de toutes les pièces
 * comptables », le compte bancaire se choisit **au-dessus de la grille** : l'export ne le
 * porte pas. Il est donc déclaré au dépôt, une fois pour tout le fichier, et c'est
 * `banque_id` — repris du lot.
 *
 * **Ne pas confondre avec `banque_emettrice`**, qui est dans le fichier : celle-là est la
 * banque du **chèque reçu**, pas le compte où il est déposé. Les confondre rangerait sous la
 * BGFI tout règlement reçu par chèque BGFI, quel que soit le compte crédité.
 *
 * **Le sens reste nul tant qu'on ne sait pas.** Une pièce est une entrée ou une sortie selon
 * son type, et les libellés réels ne seront connus qu'au premier fichier. Poser une valeur
 * par défaut ferait des sorties des entrées, et le total serait faux du double de leur
 * montant sans que rien ne le signale.
 */
#[Fillable([
    'entreprise_id', 'banque_id', 'ville_id', 'site_id', 'lot_import_id',
    'date_piece', 'code_piece', 'reference_piece', 'banque_emettrice',
    'type_piece', 'modele_reglement', 'beneficiaire', 'montant', 'sens',
    'code_agent', 'source_rattachement', 'rattachement_presume',
])]
class PieceBancaire extends Model
{
    use AppartientAUneEntreprise;
    use PeutVenirDUnImport;

    public const ENTREE = 'entree';

    public const SORTIE = 'sortie';

    protected $table = 'pieces_bancaires';

    protected function casts(): array
    {
        return [
            'date_piece' => 'date',
            'montant' => 'integer',
            'rattachement_presume' => 'boolean',
        ];
    }

    public function banque(): BelongsTo
    {
        return $this->belongsTo(Banque::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Le sens que le type de pièce désigne, ou null quand on ne sait pas.
     *
     * **Les mots sont ceux qu'un logiciel comptable emploie d'ordinaire**, et non ceux du
     * fichier : il n'est pas encore arrivé. Ils seront confrontés au premier dépôt, et c'est
     * pour cela que le doute rend `null` plutôt qu'un sens par défaut — une pièce non orientée
     * se verra à l'écran au lieu de fausser un total.
     */
    public static function sensDuType(?string $type): ?string
    {
        $mot = mb_strtolower(trim((string) $type));

        if ($mot === '') {
            return null;
        }

        foreach (['encaissement', 'recette', 'versement', 'remise', 'credit', 'crédit'] as $entree) {
            if (str_contains($mot, $entree)) {
                return self::ENTREE;
            }
        }

        foreach (['decaissement', 'décaissement', 'paiement', 'reglement', 'règlement',
            'retrait', 'debit', 'débit', 'cheque emis', 'chèque émis'] as $sortie) {
            if (str_contains($mot, $sortie)) {
                return self::SORTIE;
            }
        }

        return null;
    }
}

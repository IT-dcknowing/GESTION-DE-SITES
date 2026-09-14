<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Un mouvement de caisse : ce qui entre, ce qui sort, et le solde qui suit.
 *
 * Les deux sens dans une seule table, avec un montant toujours positif et le sens dans sa
 * propre colonne. Un tableau où certaines lignes sont négatives se lit mal, et le total se
 * calcule aussi bien avec une condition qu'avec un signe.
 *
 * `solde_annonce` mérite un mot : c'est le solde tel que le fichier l'affiche, recopié sans
 * être vérifié. S'il s'écarte de notre propre cumul, ce n'est pas une erreur de notre côté
 * — c'est le signe que le fichier a été retouché à la main entre deux lignes, et c'est
 * précisément ce qu'on veut pouvoir montrer.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'date', 'sens', 'libelle', 'montant', 'solde_annonce',
    'beneficiaire', 'immatriculation', 'mois', 'feuille',
])]
class MouvementCaisse extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'mouvements_caisse';

    public const ENTREE = 'entree';

    public const SORTIE = 'sortie';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant' => 'integer',
            'solde_annonce' => 'integer',
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

    /** Le montant signé, pour les additions. */
    public function signe(): int
    {
        return $this->sens === self::ENTREE ? $this->montant : -$this->montant;
    }
}

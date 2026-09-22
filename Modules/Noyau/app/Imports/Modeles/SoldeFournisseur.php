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
 * Le solde d'un fournisseur tel que la comptabilité le tient.
 *
 * C'est la photographie du compte : débit, crédit, solde, à la date où le logiciel l'a
 * exporté. Un solde positif est ce que l'entreprise doit encore ; un solde négatif, ce
 * qu'elle a payé d'avance.
 *
 * **À ne pas confondre avec `FactureFournisseur`**, qui vient du classeur tenu à la main :
 * celle-là dit ce que l'atelier croit devoir, pièce par pièce. C'est l'écart entre les deux
 * qui se cherche quand un fournisseur réclame, et les confondre l'effacerait.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'fournisseur', 'debit', 'credit', 'solde',
    'code_agent', 'source_rattachement', 'rattachement_presume',
])]
class SoldeFournisseur extends Model
{
    use AppartientAUneEntreprise, PeutVenirDUnImport;

    protected $table = 'soldes_fournisseur';

    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'solde' => 'integer',
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
}

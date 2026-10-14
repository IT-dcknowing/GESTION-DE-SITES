<?php

namespace Modules\Noyau\Imports\Modeles;

use App\Casts\Montant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Une ligne de fichier qu'un import a reconnue comme la répétition d'une autre — 14/10.
 *
 * Elle n'est plus jetée : on la garde avec ses valeurs, on la montre à côté de celle qui a été
 * retenue, et quelqu'un décide. Si ce n'était pas un doublon — deux dépenses de 5 000 F le même
 * jour pour le même motif, deux factures sous le même numéro —, « Intégrer » l'écrit à part
 * entière (`DoublonsDImport::integrer`). Voir la migration `2026_10_14_000001`.
 */
class DoublonImport extends Model
{
    public const ECARTE = 'ecarte';

    public const INTEGRE = 'integre';

    protected $table = 'doublons_import';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ligne' => 'array',
            'rattachement' => 'array',
            'date' => 'date',
            'montant' => Montant::class,
            'numero_ligne' => 'integer',
            'integre_le' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeEcartes(Builder $requete): Builder
    {
        return $requete->where('statut', self::ECARTE);
    }

    public function estEcarte(): bool
    {
        return $this->statut === self::ECARTE;
    }
}

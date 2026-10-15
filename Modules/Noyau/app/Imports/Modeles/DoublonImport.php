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
 * Depuis le 15/10, elle est importée comme les autres — rien n'est écarté au premier import — et
 * consignée ici comme **doublon supposé à preuve du contraire**, avec la raison. Quelqu'un tranche :
 * garder l'une et retirer l'autre, ou garder les deux (`DoublonsDImport::trancher`). Voir les
 * migrations `2026_10_14_000001` et `2026_10_15_000001`.
 */
class DoublonImport extends Model
{
    /** D'avant le 15/10 : mise de côté sans être écrite. « Intégrer » l'écrit. */
    public const ECARTE = 'ecarte';

    /** D'avant le 15/10 : intégrée d'un bouton. */
    public const INTEGRE = 'integre';

    /** Depuis le 15/10 : écrite comme les autres, et supposée doublon tant que personne n'a tranché. */
    public const A_TRANCHER = 'a_trancher';

    /** Tranché : ce n'étaient pas des doublons, les deux restent. */
    public const GARDE = 'garde';

    /** Tranché : l'une des deux a été retirée (`decision` dit laquelle) ; elle ne revient pas au dépôt suivant. */
    public const RETIRE = 'retire';

    /** Les statuts qui attendent une décision. */
    public const EN_ATTENTE = [self::A_TRANCHER, self::ECARTE];

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
            'decide_le' => 'datetime',
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

    public function scopeEnAttente(Builder $requete): Builder
    {
        return $requete->whereIn('statut', self::EN_ATTENTE);
    }

    public function estEcarte(): bool
    {
        return $this->statut === self::ECARTE;
    }

    public function estATrancher(): bool
    {
        return $this->statut === self::A_TRANCHER;
    }
}

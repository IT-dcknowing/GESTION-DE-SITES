<?php

namespace Modules\Noyau\Entreprises\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Le déplacement d'une personne d'un lieu à un autre, gardé comme un fait daté.
 *
 * On aurait pu se contenter de changer `site_id` sur le compte. C'eût été perdre trois
 * choses d'un coup : d'où venait la personne, quand elle est partie, et pourquoi. Or c'est
 * exactement ce qu'on cherche six mois plus tard, quand le chiffre d'affaires d'un atelier
 * baisse et qu'on se demande qui l'a quitté.
 *
 * **Les données ne suivent pas la personne.** Une facture faite au Site 1 reste au Site 1 :
 * c'est là que le travail a eu lieu. Ce qui suit la personne, c'est son écran — et, s'il
 * existe, son code du logiciel d'atelier, sans quoi ses prochaines fiches continueraient
 * d'être rattachées à son ancien poste.
 */
#[Fillable([
    'entreprise_id', 'user_id', 'code_agent_id',
    'ville_avant_id', 'site_avant_id', 'role_avant',
    'ville_apres_id', 'site_apres_id', 'role_apres',
    'motif', 'decidee_par',
])]
class Reaffectation extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'reaffectations';

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeAgent::class, 'code_agent_id');
    }

    public function villeAvant(): BelongsTo
    {
        return $this->belongsTo(Ville::class, 'ville_avant_id');
    }

    public function villeApres(): BelongsTo
    {
        return $this->belongsTo(Ville::class, 'ville_apres_id');
    }

    public function siteAvant(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_avant_id');
    }

    public function siteApres(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_apres_id');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidee_par');
    }

    /**
     * Les ateliers où cette personne a travaillé avant aujourd'hui.
     *
     * Sert au **périmètre de consultation**, et à lui seul : quelqu'un qui a passé huit
     * mois au Site 1 doit pouvoir relire son travail après son départ. Il ne doit pas
     * pouvoir y saisir — c'est pourquoi cette liste ne rejoint jamais `Site::visiblesPour`,
     * qui est le périmètre d'écriture.
     *
     * @return list<int>
     */
    public static function sitesPassesDe(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNotNull('site_avant_id')
            ->pluck('site_avant_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Les villes où cette personne a travaillé avant aujourd'hui. @return list<int> */
    public static function villesPassesDe(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNotNull('ville_avant_id')
            ->pluck('ville_avant_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

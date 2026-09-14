<?php

namespace Modules\Noyau\Entreprises\Modeles;

use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un site est un lieu : l'endroit physique où l'entreprise opère, et où l'on pratique
 * indifféremment les deux activités (Mécanique et Sinistre). Une ville peut compter
 * plusieurs lieux — Abidjan en a deux — ou n'en avoir qu'un, alors confondu avec elle.
 */
#[Fillable(['entreprise_id', 'ville_id', 'code', 'nom', 'responsable_id', 'est_actif'])]
class Site extends Model
{
    use AppartientAUneEntreprise, HasFactory;

    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    /** Responsable propre à ce site. S'il est vide, le site est couvert par le responsable de sa ville. */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** Vrai si $user est responsable de ce site, directement ou via sa ville. */
    public function estResponsablePar(User $user): bool
    {
        return $this->responsable_id === $user->id
            || $this->ville?->responsable_id === $user->id;
    }

    /**
     * Sites que $user peut voir, selon son rôle :
     * - Gérant : tous les sites de son entreprise.
     * - Superviseur de ville : tous les lieux des villes dont il répond.
     * - Responsable de site : le ou les lieux qui lui sont nommément confiés.
     * - Comptabilité : son site de rattachement, ou tous les sites de sa ville s'il est rattaché à une ville entière.
     */
    public static function visiblesPour(User $user): Collection
    {
        if ($user->hasRole('gerant')) {
            return static::where('entreprise_id', $user->entreprise_id)->orderBy('nom')->get();
        }

        if ($user->hasRole('responsable_ville')) {
            return static::where('entreprise_id', $user->entreprise_id)
                ->whereHas('ville', fn ($q) => $q->where('responsable_id', $user->id))
                ->orderBy('nom')->get();
        }

        if ($user->hasRole('responsable_site')) {
            return static::where('entreprise_id', $user->entreprise_id)
                ->where('responsable_id', $user->id)
                ->orderBy('nom')->get();
        }

        /*
         * Le responsable commercial n'est rattaché à aucun lieu : il anime des vendeurs,
         * et un vendeur appartient à une ville entière. Son périmètre est donc celui de sa
         * ville — ou de toutes, quand un seul animateur couvre le groupe.
         */
        if ($user->hasRole('responsable_commercial')) {
            $sites = static::where('entreprise_id', $user->entreprise_id);

            if (! $user->couvre_toutes_les_villes) {
                $sites->where('ville_id', $user->ville_id);
            }

            return $sites->orderBy('nom')->get();
        }

        if ($user->hasRole('caissier')) {
            if ($user->site_id) {
                return static::where('id', $user->site_id)->get();
            }

            if ($user->ville_id) {
                return static::where('ville_id', $user->ville_id)->orderBy('nom')->get();
            }
        }

        return new Collection();
    }
}

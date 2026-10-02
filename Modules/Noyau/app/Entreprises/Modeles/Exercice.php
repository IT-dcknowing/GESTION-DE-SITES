<?php

namespace Modules\Noyau\Entreprises\Modeles;

use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Une année civile d'activité. Se clôture ville par ville ; la clôture globale de
 * l'exercice reste une décision manuelle du gérant (jamais automatique), même une
 * fois toutes les villes closes.
 */
#[Fillable(['entreprise_id', 'annee', 'statut', 'cloture_le', 'est_defaut'])]
class Exercice extends Model
{
    use AppartientAUneEntreprise;

    /**
     * Toute écriture sur un exercice efface ce que la requête en cours avait retenu.
     *
     * `actuel()` est mémorisé pour la durée d'une requête depuis le 02/10, parce qu'il était
     * relancé trois à six fois par clic. La mémoire doit donc tomber dès que la réponse
     * change — création d'une année, bascule du 1er janvier, choix d'un autre défaut.
     *
     * **Ici et pas chez les appelants.** Trois chemins modifient `est_defaut`, dont un qui
     * passe par `forceFill` sans toucher `definirParDefaut()` : un oubli, et un écran
     * afficherait l'année d'avant jusqu'au rechargement suivant.
     */
    protected static function booted(): void
    {
        $oublier = fn (self $exercice) => static::oublierLActuel((int) $exercice->entreprise_id);

        static::saved($oublier);
        static::deleted($oublier);
    }

    protected function casts(): array
    {
        return ['cloture_le' => 'datetime', 'est_defaut' => 'boolean'];
    }

    public function villes(): BelongsToMany
    {
        return $this->belongsToMany(Ville::class, 'exercice_villes')
            ->withPivot(['statut', 'cloture_le', 'cloture_par'])
            ->withTimestamps();
    }

    public function estClos(): bool
    {
        return $this->statut === 'Clos';
    }

    /** Vrai si toutes les villes actives de l'entreprise sont closes pour cet exercice. */
    public function toutesLesVillesSontClosesPour(int $entrepriseId): bool
    {
        $villesActives = Ville::where('entreprise_id', $entrepriseId)->where('est_actif', true)->pluck('id');

        if ($villesActives->isEmpty()) {
            return false;
        }

        $villesClosesIds = $this->villes()->wherePivot('statut', 'Clos')->pluck('villes.id');

        return $villesActives->diff($villesClosesIds)->isEmpty();
    }

    public function clorePourVille(Ville $ville, User $utilisateur): void
    {
        $this->villes()->syncWithoutDetaching([
            $ville->id => ['statut' => 'Clos', 'cloture_le' => now(), 'cloture_par' => $utilisateur->id],
        ]);
    }

    public function reouvrirPourVille(Ville $ville): void
    {
        $this->villes()->syncWithoutDetaching([
            $ville->id => ['statut' => 'Ouvert', 'cloture_le' => null, 'cloture_par' => null],
        ]);
    }

    public function statutPourVille(int $villeId): string
    {
        $pivot = $this->villes->firstWhere('id', $villeId)?->pivot;

        return $pivot?->statut ?? 'Ouvert';
    }

    /**
     * Exercice à afficher dans le badge d'en-tête, pour toute l'équipe : celui marqué
     * par défaut, ou à défaut l'année en cours, ou le plus récent. Une interface qui
     * consulte ou rouvre un autre exercice ne change jamais ce choix.
     */
    public static function actuel(int $entrepriseId): ?self
    {
        /*
         * **Résolu une fois par requête, et non une fois par appel.**
         *
         * Mesuré le 02/10 : un simple changement de filtre relançait ces trois requêtes
         * **trois fois sur `/tresorerie` et six fois sur `/banques`**. La méthode est le
         * passage obligé de l'en-tête, du sélecteur d'exercice et de chaque état — chacun
         * la rappelle, et rien ne change entre deux appels d'une même requête.
         *
         * **La mémoire est dans le conteneur, pas dans une variable statique.** Une statique
         * survivrait d'un test au suivant alors que la base est recréée entre les deux ;
         * le conteneur, lui, est reconstruit avec l'application.
         */
        $cle = 'exercice.actuel.'.$entrepriseId;

        // Rangé dans un tableau, et non seul : `bound()` repose sur `isset()`, qui dit
        // « non » d'une valeur nulle. Une entreprise sans exercice aurait donc été
        // recherchée à chaque appel, c'est-à-dire précisément le cas qu'on vient de corriger.
        if (app()->bound($cle)) {
            return app($cle)[0];
        }

        $exercice = static::where('entreprise_id', $entrepriseId)->where('est_defaut', true)->first()
            ?? static::where('entreprise_id', $entrepriseId)->where('annee', now()->year)->first()
            ?? static::where('entreprise_id', $entrepriseId)->orderByDesc('annee')->first();

        // Le 1er janvier, l'année suivante s'ouvre d'elle-même.
        //
        // C'est ici que la bascule se déclenche, et l'endroit est choisi : cette méthode est
        // le passage obligé de tous les écrans qui ont besoin de savoir en quelle année on
        // travaille. Pas de tâche planifiée à surveiller, pas de clic à ne pas oublier — la
        // première personne qui se connecte ouvre l'année pour toute l'équipe.
        //
        // L'année précédente **n'est pas close** : elle reste ouverte, consultable et
        // corrigeable. Une facture de décembre qui arrive le 8 janvier se saisit à sa date.
        if ($exercice !== null && (int) $exercice->annee !== (int) now()->year) {
            $exercice = (new \Modules\Noyau\Entreprises\Services\BasculeDExercice)
                ->assurer($entrepriseId)['exercice'];
        }

        app()->instance($cle, [$exercice]);

        return $exercice;
    }

    /** Oublie l'exercice retenu pour la requête en cours. Tout geste qui le change passe ici. */
    public static function oublierLActuel(int $entrepriseId): void
    {
        app()->forgetInstance('exercice.actuel.'.$entrepriseId);
    }

    /** Marque cet exercice comme celui par défaut de l'entreprise, et retire ce statut à tout autre. */
    public function definirParDefaut(): void
    {
        static::where('entreprise_id', $this->entreprise_id)->where('id', '!=', $this->id)->update(['est_defaut' => false]);
        $this->update(['est_defaut' => true]);

        // Ce que la requête avait retenu n'est plus vrai.
        static::oublierLActuel((int) $this->entreprise_id);
    }

    /**
     * Vrai si la saisie doit être bloquée pour cette ville à cette date : soit
     * l'exercice entier est clos, soit spécifiquement cette ville l'est pour son année.
     */
    public static function estFerme(int $entrepriseId, int $villeId, Carbon|string $date): bool
    {
        $annee = Carbon::parse($date)->year;

        $exercice = static::where('entreprise_id', $entrepriseId)->where('annee', $annee)->first();

        if (! $exercice) {
            return false;
        }

        if ($exercice->estClos()) {
            return true;
        }

        return $exercice->statutPourVille($villeId) === 'Clos';
    }
}

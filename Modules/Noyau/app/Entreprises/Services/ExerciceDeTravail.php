<?php

namespace Modules\Noyau\Entreprises\Services;

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Exercice;

/**
 * L'année sur laquelle on travaille — qui n'est pas forcément l'année où l'on est.
 *
 * **Deux notions se confondaient, et il fallait les séparer.** L'exercice *courant* est
 * celui que le calendrier impose : au 1ᵉʳ janvier, le système en ouvre un nouveau tout seul
 * et bascule dessus. L'exercice *de travail* est celui qu'on regarde en ce moment — et il
 * arrive souvent qu'on veuille regarder l'année d'avant : pour établir un rapport, pour
 * vérifier un chiffre, pour retrouver une facture de décembre.
 *
 * Tant qu'une seule notion existait, revenir sur l'année passée obligeait à rouvrir
 * l'exercice, c'est-à-dire à modifier l'état de l'entreprise pour lire une donnée. C'est le
 * genre de manœuvre qu'on finit par oublier de défaire.
 *
 * **Le choix vit dans la session, pas en base.** Il est propre à celui qui regarde et à sa
 * session : deux personnes peuvent consulter deux années en même temps sans se gêner, et
 * rien n'est écrit nulle part. Fermer la session ramène naturellement à l'année en cours.
 *
 * **Aucun exercice ne se clôture pour autant.** Basculer sur 2025 ne ferme pas 2026 et ne
 * rouvre pas 2025 : on ne fait que déplacer le regard. La saisie, elle, reste gouvernée par
 * l'état réel de l'exercice — ce que {@see Exercice::estFerme()} continue de dire.
 */
class ExerciceDeTravail
{
    /** La clé de session, préfixée par l'entreprise : un compte peut en changer. */
    private const CLE = 'exercice_de_travail';

    /**
     * L'année regardée en ce moment.
     *
     * À défaut de choix, celle de l'exercice courant — donc l'année civile, puisque la
     * bascule est automatique. Un choix devenu invalide (l'exercice a été supprimé) est
     * ignoré plutôt que de faire échouer la page.
     */
    public static function annee(?User $utilisateur = null): ?int
    {
        $utilisateur ??= auth()->user();
        $entrepriseId = $utilisateur?->entreprise_id;

        if ($entrepriseId === null) {
            return null;
        }

        $choisie = session(self::cle($entrepriseId));

        if ($choisie !== null && self::annees($entrepriseId)->contains((int) $choisie)) {
            return (int) $choisie;
        }

        return Exercice::actuel($entrepriseId)?->annee;
    }

    /** Retient l'année regardée. `null` revient à l'exercice courant. */
    public static function choisir(?int $annee, ?User $utilisateur = null): void
    {
        $utilisateur ??= auth()->user();
        $entrepriseId = $utilisateur?->entreprise_id;

        if ($entrepriseId === null) {
            return;
        }

        if ($annee === null || ! self::annees($entrepriseId)->contains($annee)) {
            session()->forget(self::cle($entrepriseId));

            return;
        }

        session([self::cle($entrepriseId) => $annee]);
    }

    /**
     * Les exercices ouvrables au regard, du plus récent au plus ancien.
     *
     * @return \Illuminate\Support\Collection<int, Exercice>
     */
    public static function disponibles(int $entrepriseId)
    {
        return Exercice::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->orderByDesc('annee')
            ->get();
    }

    /** Vrai quand l'année regardée n'est pas l'année courante — l'écran doit le dire. */
    public static function estUnRetourEnArriere(?User $utilisateur = null): bool
    {
        $utilisateur ??= auth()->user();
        $entrepriseId = $utilisateur?->entreprise_id;

        if ($entrepriseId === null) {
            return false;
        }

        return self::annee($utilisateur) !== Exercice::actuel($entrepriseId)?->annee;
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private static function annees(int $entrepriseId)
    {
        return self::disponibles($entrepriseId)->pluck('annee')->map(fn ($a) => (int) $a);
    }

    private static function cle(int $entrepriseId): string
    {
        return self::CLE.'.'.$entrepriseId;
    }
}

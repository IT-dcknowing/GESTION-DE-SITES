<?php

namespace Modules\SuperAdmin\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Modules\Noyau\Tracabilite\Services\JournalDeNavigation;
use RuntimeException;

/**
 * Prendre la place de quelqu'un pour l'assister, et pouvoir revenir.
 *
 * **Le besoin.** Une personne au téléphone décrit un écran qu'on ne voit pas. Les
 * habilitations, le périmètre, la ville de travail, les rôles : tout cela change ce qui
 * s'affiche, et aucune capture d'écran ne remplace le fait d'y être. Le mode switch ouvre
 * l'application telle que cette personne la voit.
 *
 * **Ce n'est pas une connexion, et le système ne doit pas le raconter comme telle.** C'est
 * le point le plus important de cette classe. Si l'on ouvrait une session au nom de la
 * personne assistée, son compte afficherait une connexion qu'elle n'a pas faite — et
 * l'écran de traçabilité, qui sert précisément à savoir qui est entré, se mettrait à
 * mentir. Le drapeau de session est donc posé **avant** l'authentification, et le journal
 * de navigation le lit pour ne rien ouvrir. Le passage, lui, est consigné dans le journal
 * d'audit au nom de l'administrateur, qui est bien celui qui agit.
 *
 * **On revient toujours.** L'identité d'origine est gardée en session : tant qu'elle y est,
 * un bandeau barre l'écran et un bouton ramène. Sans ce retour, un administrateur entré
 * dans un compte sans mot de passe y resterait enfermé.
 *
 * **Ce que le mode switch ne fait pas.** Il ne contourne aucune habilitation : une fois
 * dans le compte, on voit ce que ce compte voit, ni plus ni moins. Et il ne s'ouvre que
 * vers un compte que l'administrateur a déjà le droit d'administrer.
 */
class ModeSwitch
{
    /**
     * L'identité de départ, gardée le temps du détour.
     *
     * C'est la même clef que celle que le journal de présence surveille : tant qu'elle
     * est posée, aucune connexion n'est inscrite au nom de la personne assistée.
     */
    public const CLE_ORIGINE = JournalDeNavigation::CLEF_ASSISTANCE;

    /** L'heure d'entrée, pour que le bandeau dise depuis quand. */
    public const CLE_DEPUIS = 'switch.depuis';

    /** Vrai pendant qu'un passage est en cours : le journal de navigation s'en sert. */
    public static function enCours(): bool
    {
        return Session::has(self::CLE_ORIGINE);
    }

    /** Le compte d'origine, ou null quand personne n'est en détour. */
    public static function origine(): ?User
    {
        $id = Session::get(self::CLE_ORIGINE);

        return $id === null ? null : User::withoutGlobalScopes()->find($id);
    }

    /**
     * Qui peut prendre la place de qui.
     *
     * Trois refus, et chacun couvre un dégât réel :
     *
     * - **on ne prend pas sa propre place** : l'écran serait le même, et le bandeau ne
     *   servirait qu'à embrouiller ;
     * - **on n'entre pas dans un compte fermé** : un accès révoqué l'est pour tout le
     *   monde, y compris pour celui qui vient l'inspecter — sans quoi la révocation ne
     *   voudrait plus rien dire ;
     * - **on n'entre pas dans le compte d'un autre administrateur** qu'on n'administre pas
     *   soi-même. Entre pairs, l'assistance se demande ; elle ne se prend pas.
     */
    public static function motifDuRefus(User $acteur, User $cible): ?string
    {
        if (! $acteur->hasRole('super_admin')) {
            return "Le mode switch est réservé aux administrateurs de la plateforme.";
        }

        if ($acteur->id === $cible->id) {
            return "Vous êtes déjà dans ce compte.";
        }

        if (! $cible->est_actif) {
            return "Cet accès est révoqué : il ne s'ouvre pour personne, pas même pour une assistance. "
                ."Réactivez-le d'abord si l'assistance est nécessaire.";
        }

        if ($cible->estSuperAdmin() && ! $acteur->peutGerer($cible)) {
            return "Cet administrateur ne relève pas de votre périmètre.";
        }

        return null;
    }

    /**
     * Entre dans le compte visé.
     *
     * L'ordre des trois gestes est le sujet de la méthode :
     *
     * 1. **le drapeau d'abord**, pour que l'authentification qui suit ne soit pas comptée
     *    comme une connexion de la personne assistée ;
     * 2. **l'identifiant de session est renouvelé** — on change d'identité, et une session
     *    qui change de titulaire sans changer d'identifiant est une fixation de session ;
     * 3. **l'authentification ensuite**, une fois le terrain préparé.
     */
    public static function entrer(User $acteur, User $cible): void
    {
        $motif = self::motifDuRefus($acteur, $cible);

        if ($motif !== null) {
            throw new RuntimeException($motif);
        }

        // regenerate() conserve le contenu de la session et n'en change que l'identifiant :
        // le drapeau posé juste après survivra donc au renouvellement.
        Session::regenerate();
        Session::put(self::CLE_ORIGINE, $acteur->id);
        Session::put(self::CLE_DEPUIS, now()->toIso8601String());

        activity()
            ->causedBy($acteur)
            ->performedOn($cible)
            ->withProperties([
                'entreprise_id' => $cible->entreprise_id,
                'adresse_ip' => request()->ip(),
            ])
            ->event('switch.entree')
            ->log('Entrée en mode switch dans le compte de '.$cible->name);

        Auth::login($cible);
    }

    /**
     * Revient à l'identité de départ.
     *
     * Le drapeau est retiré **après** l'authentification de retour : l'administrateur est
     * déjà connu du système, sa propre session de traçabilité est ouverte depuis le début,
     * et il n'y a pas lieu d'en ouvrir une seconde.
     */
    public static function sortir(): ?User
    {
        $origine = self::origine();

        if ($origine === null) {
            return null;
        }

        $quitte = Auth::user();

        Session::regenerate();
        Auth::login($origine);

        Session::forget([self::CLE_ORIGINE, self::CLE_DEPUIS]);

        activity()
            ->causedBy($origine)
            ->performedOn($quitte)
            ->event('switch.sortie')
            ->log('Sortie du mode switch');

        return $origine;
    }
}

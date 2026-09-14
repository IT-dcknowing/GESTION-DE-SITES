<?php

namespace Modules\Noyau\Commun\Services;

use App\Models\User;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Qui a écrit cette ligne — en clair, et pas seulement en code.
 *
 * Les tableaux affichaient le code de saisie, `A-C-KY-0007`, et rien d'autre. C'est
 * rigoureux et illisible : il faut connaître la grille pour savoir que « KY » désigne Koffi
 * Yao, et personne ne la connaît par cœur. Le code sert à retrouver et à classer ; le nom
 * sert à comprendre. Les deux ont leur place, et ils tiennent dans la même cellule.
 *
 * S'y ajoute, quand il existe, **l'identifiant de liaison** — les deux lettres du logiciel
 * d'atelier. Il ne dit pas la même chose que le code de saisie : celui-ci désigne qui a
 * saisi *ici*, celui-là qui a rédigé la fiche *là-bas*. Sur une ligne importée, c'est même
 * le seul des deux à exister.
 *
 * **Tout est chargé d'un bloc.** Les tableaux affichent vingt-cinq lignes ; résoudre le nom
 * ligne par ligne ferait vingt-cinq requêtes pour lire deux colonnes. Les deux annuaires —
 * comptes et codes — tiennent en quelques dizaines d'entrées et sont mémorisés pour la durée
 * de la requête.
 */
class SignatureDeSaisie
{
    /** @var array<int, array{nom: string, liaison: string|null}>|null */
    private static ?array $annuaire = null;

    private static ?int $entrepriseChargee = null;

    /**
     * Le nom de l'auteur d'une ligne, ou null quand on ne peut pas le dire.
     *
     * Null n'est pas un échec : une ligne importée n'a pas d'auteur sur la plateforme, et
     * afficher un nom au hasard serait pire que de n'en afficher aucun.
     */
    public static function nom(?int $userId, ?int $entrepriseId = null): ?string
    {
        if ($userId === null) {
            return null;
        }

        return self::annuaire($entrepriseId)[$userId]['nom'] ?? null;
    }

    /** L'identifiant de liaison de l'auteur, s'il en a un. */
    public static function liaison(?int $userId, ?int $entrepriseId = null): ?string
    {
        if ($userId === null) {
            return null;
        }

        return self::annuaire($entrepriseId)[$userId]['liaison'] ?? null;
    }

    /**
     * Remet l'annuaire à zéro.
     *
     * Utile aux tests, et à toute commande longue qui modifierait les rattachements en
     * cours de route : un annuaire mémorisé y deviendrait faux sans prévenir.
     */
    public static function oublier(): void
    {
        self::$annuaire = null;
        self::$entrepriseChargee = null;
    }

    /** @return array<int, array{nom: string, liaison: string|null}> */
    private static function annuaire(?int $entrepriseId): array
    {
        $entrepriseId ??= auth()->user()?->entreprise_id;

        if ($entrepriseId === null) {
            return [];
        }

        if (self::$annuaire !== null && self::$entrepriseChargee === $entrepriseId) {
            return self::$annuaire;
        }

        $liaisons = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->whereNotNull('user_id')
            ->pluck('code', 'user_id');

        self::$annuaire = User::where('entreprise_id', $entrepriseId)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $u) => [(int) $u->id => [
                'nom' => (string) $u->name,
                'liaison' => $liaisons[$u->id] ?? null,
            ]])
            ->all();

        self::$entrepriseChargee = $entrepriseId;

        return self::$annuaire;
    }
}

<?php

namespace Modules\Noyau\Entreprises\Services;

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * La ville qu'on regarde en ce moment, dans les modules qui travaillent sur toute l'entreprise.
 *
 * **Ce n'est pas un contrôle d'accès, c'est une loupe.** Le recouvrement est confié à des
 * rôles qui portent sur l'entreprise entière — l'agent de recouvrement relance aussi bien
 * une créance d'Abidjan que de San Pédro. Leur périmètre est donc, à dessein, sans
 * restriction de ville : rien ici ne l'élargit, et rien ne le rétrécit non plus.
 *
 * Ce qui manquait est autre chose : **pouvoir n'en regarder qu'une**. Une balance âgée qui
 * mélange trois villes se lit mal quand on prépare une visite à Bouaké, et les totaux qu'on
 * en tire ne sont attribuables à personne.
 *
 * Le choix vit dans la session, comme celui de l'exercice, et pour les mêmes raisons : il
 * appartient à celui qui regarde, il n'écrit rien, et fermer la session le rend.
 *
 * @see ExerciceDeTravail la même idée, appliquée à l'année
 */
class VilleDeTravail
{
    private const CLE = 'ville_de_travail';

    /** La ville regardée, ou null pour « toutes ». */
    public static function villeId(?User $utilisateur = null): ?int
    {
        $utilisateur ??= auth()->user();
        $entrepriseId = $utilisateur?->entreprise_id;

        if ($entrepriseId === null) {
            return null;
        }

        $choisie = session(self::cle($entrepriseId));

        return $choisie !== null && self::villes($entrepriseId)->has((int) $choisie)
            ? (int) $choisie
            : null;
    }

    public static function choisir(?int $villeId, ?User $utilisateur = null): void
    {
        $utilisateur ??= auth()->user();
        $entrepriseId = $utilisateur?->entreprise_id;

        if ($entrepriseId === null) {
            return;
        }

        if ($villeId === null || ! self::villes($entrepriseId)->has($villeId)) {
            session()->forget(self::cle($entrepriseId));

            return;
        }

        session([self::cle($entrepriseId) => $villeId]);
    }

    /**
     * Les identifiants de sites correspondant à la ville regardée — null pour « toutes ».
     *
     * Rendre `null` plutôt qu'un tableau de tous les sites n'est pas un détail : cela permet
     * à l'appelant de **ne poser aucune condition**, et donc de conserver les lignes qui
     * n'ont pas encore d'atelier. Un tableau exhaustif les aurait exclues sans qu'on le
     * veuille — et ce sont précisément celles qu'il faut voir.
     *
     * @return list<int>|null
     */
    public static function sites(?User $utilisateur = null): ?array
    {
        $villeId = self::villeId($utilisateur);

        if ($villeId === null) {
            return null;
        }

        $utilisateur ??= auth()->user();

        return \Modules\Noyau\Entreprises\Modeles\Site::withoutGlobalScopes()
            ->where('entreprise_id', $utilisateur->entreprise_id)
            ->where('ville_id', $villeId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    public static function villes(?int $entrepriseId = null)
    {
        $entrepriseId ??= auth()->user()?->entreprise_id;

        if ($entrepriseId === null) {
            return collect();
        }

        return Ville::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('est_actif', true)
            ->orderBy('nom')
            ->pluck('nom', 'id');
    }

    public static function libelle(?User $utilisateur = null): string
    {
        $villeId = self::villeId($utilisateur);

        return $villeId === null
            ? 'Toutes les villes'
            : (string) self::villes()->get($villeId, 'Toutes les villes');
    }

    private static function cle(int $entrepriseId): string
    {
        return self::CLE.'.'.$entrepriseId;
    }
}

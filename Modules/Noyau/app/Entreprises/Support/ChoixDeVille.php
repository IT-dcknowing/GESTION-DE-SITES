<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Le champ « Ville » d'un rattachement — une ville, ou toutes.
 *
 * **Pourquoi « toutes les villes » existe.** Un groupe n'a parfois qu'un seul animateur
 * commercial pour Abidjan, Bouaké et San Pédro. Jusqu'ici la seule façon de l'exprimer
 * était de le nommer gérant, ce qui lui ouvrait la trésorerie, les charges et la gestion
 * des accès dont il n'a pas la charge — un excès de droits pour combler un manque de
 * vocabulaire.
 *
 * **Pourquoi une colonne plutôt qu'un `ville_id` laissé à nul.** Parce que nul veut déjà
 * dire « pas encore rattaché ». Deux sens pour une même absence de valeur, c'est la
 * garantie qu'un jour l'un sera lu pour l'autre — et qu'un compte en attente de
 * rattachement se retrouvera à voir toute l'entreprise.
 *
 * **Et sa ville d'attache, alors ?** Un responsable commercial vend lui-même : sa fiche
 * commercial doit vivre quelque part. On retient la première ville de l'entreprise, par
 * ordre alphabétique. C'est arbitraire, et c'est assumé : l'alternative était un second
 * champ « ville d'attache » que personne n'aurait compris à côté de « toutes les villes ».
 * Ce qui compte — ce qu'il supervise — est porté par la colonne, pas par ce rattachement.
 */
class ChoixDeVille
{
    /** La valeur qui désigne l'entreprise entière plutôt qu'une ville. */
    public const TOUTES = 'toutes';

    /**
     * Les valeurs proposées, valeur => libellé.
     *
     * « Toutes les villes » n'apparaît que pour les rôles qui peuvent réellement couvrir
     * l'entreprise, et seulement si elle compte plus d'une ville : ailleurs, l'entrée
     * désignerait la même chose que la ville unique, sous un autre nom.
     *
     * @return array<string, string>
     */
    public static function options(int $entrepriseId, bool $avecToutes = false): array
    {
        $villes = Ville::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('est_actif', true)
            ->orderBy('nom')
            ->get();

        $options = [];

        foreach ($villes as $ville) {
            $options[(string) $ville->id] = $ville->nom;
        }

        if ($avecToutes && $villes->count() > 1) {
            $options[self::TOUTES] = 'Toutes les villes';
        }

        return $options;
    }

    /** Les rôles à qui l'entrée « toutes les villes » est proposée. */
    public static function peutCouvrirToutesLesVilles(?string $role): bool
    {
        return $role === 'responsable_commercial';
    }

    public static function estToutes(mixed $choix): bool
    {
        return $choix === self::TOUTES;
    }

    /** La valeur à présélectionner pour un compte déjà rattaché. */
    public static function choixActuel(?User $compte): string
    {
        if (! $compte) {
            return '';
        }

        return $compte->couvre_toutes_les_villes
            ? self::TOUTES
            : (string) ($compte->ville_id ?: '');
    }

    /**
     * Pose le rattachement et renvoie la ville qui portera la fiche commercial.
     *
     * Renvoyer une ville même pour « toutes » n'est pas une contradiction : c'est là que
     * vit la fiche du vendeur qu'il est aussi, pas la limite de ce qu'il supervise — cette
     * limite-là est dans `couvre_toutes_les_villes`.
     */
    public static function poser(int $entrepriseId, User $compte, mixed $choix, mixed $siteId = null): ?Ville
    {
        if (self::estToutes($choix)) {
            $attache = Ville::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)
                ->where('est_actif', true)
                ->orderBy('nom')
                ->first();

            if (! $attache) {
                return null;
            }

            $compte->forceFill([
                'ville_id' => $attache->id,
                'site_id' => null,
                'couvre_toutes_les_villes' => true,
            ])->save();

            return $attache;
        }

        $ville = Ville::withoutGlobalScopes()
            ->where('id', is_numeric($choix) ? (int) $choix : 0)
            ->where('entreprise_id', $entrepriseId)
            ->first();

        if (! $ville) {
            return null;
        }

        $site = null;

        if ($siteId !== null && $siteId !== '') {
            $site = Site::withoutGlobalScopes()
                ->whereKey((int) $siteId)
                ->where('entreprise_id', $entrepriseId)
                ->where('ville_id', $ville->id)
                ->where('est_actif', true)
                ->first();

            if (! $site) {
                return null;
            }
        }

        // Le drapeau retombe : quelqu'un ramené sur une seule ville ne doit pas continuer
        // de voir les autres parce qu'il les voyait hier.
        $compte->forceFill([
            'ville_id' => $ville->id,
            'site_id' => $site?->id,
            'couvre_toutes_les_villes' => false,
        ])->save();

        return $ville;
    }

    /** Le périmètre d'un compte, en une ligne lisible. */
    public static function libelle(?User $compte): ?string
    {
        if (! $compte) {
            return null;
        }

        if ($compte->couvre_toutes_les_villes) {
            return 'Toutes les villes';
        }

        return $compte->ville_id ? Ville::find($compte->ville_id)?->nom : null;
    }
}

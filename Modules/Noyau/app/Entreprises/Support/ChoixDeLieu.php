<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Le champ « Site » d'un responsable de lieu — un atelier, ou tous ceux d'une ville.
 *
 * **Ce qui manquait.** Abidjan compte deux ateliers, et la même personne en dirige
 * parfois les deux. La base le permettait depuis toujours : la désignation est portée par
 * `sites.responsable_id`, donc rien n'empêche un compte d'apparaître sur deux lignes. Mais
 * l'écran ne proposait qu'une liste de lieux, un seul choisissable — et la seule façon
 * d'exprimer « les deux » était de ne pas l'exprimer.
 *
 * On ajoute donc une entrée par ville qui compte plus d'un atelier :
 *
 *      Abidjan — Site 1
 *      Abidjan — Site 2
 *      Abidjan — tous les sites      ← « ville:7 »
 *      Bouaké
 *
 * **Pourquoi pas une case à cocher à côté de la liste.** Parce que deux champs qui se
 * contredisent — un site choisi *et* « toute la ville » cochée — obligent à trancher, et
 * l'arbitrage se serait perdu dans trois écrans. Une seule liste, une seule valeur : il
 * n'y a rien à arbitrer.
 *
 * **Pourquoi l'entrée n'apparaît pas partout.** Une ville d'un seul atelier la proposerait
 * deux fois sous deux noms. « Bouaké » et « Bouaké — tous les sites » désigneraient la même
 * chose, et le lecteur chercherait la différence.
 */
class ChoixDeLieu
{
    /** Préfixe des valeurs qui désignent une ville entière plutôt qu'un lieu. */
    public const TOUTE_LA_VILLE = 'ville:';

    /**
     * Les valeurs proposées par le champ, valeur => libellé.
     *
     * @return array<string, string>
     */
    public static function options(int $entrepriseId): array
    {
        return self::depuis(
            Site::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)
                ->where('est_actif', true)
                ->with('ville')
                ->orderBy('nom')
                ->get()
        );
    }

    /**
     * Les mêmes options, mais construites sur une liste de lieux déjà bornée.
     *
     * L'écran du superviseur ne propose que les ateliers de son propre périmètre : il ne
     * nomme pas un responsable sur une ville qu'il ne couvre pas. C'est la même règle
     * d'affichage appliquée à une liste plus courte — et « tous les sites » n'y apparaît
     * que si le périmètre en contient réellement plusieurs.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<string, string>
     */
    public static function depuis(Collection $sites): array
    {
        $options = [];

        foreach ($sites as $site) {
            $options[(string) $site->id] = $site->nom;
        }

        // Les villes à plusieurs ateliers, ajoutées après leurs lieux pour que la liste se
        // lise du plus précis au plus large.
        foreach ($sites->groupBy('ville_id') as $villeId => $deLaVille) {
            if ($deLaVille->count() < 2) {
                continue;
            }

            $nomVille = $deLaVille->first()->ville?->nom ?: 'Cette ville';

            $options[self::TOUTE_LA_VILLE.$villeId] = $nomVille.' — tous les sites';
        }

        return $options;
    }

    /** Ce choix désigne-t-il une ville entière ? */
    public static function estTouteLaVille(mixed $choix): bool
    {
        return is_string($choix) && str_starts_with($choix, self::TOUTE_LA_VILLE);
    }

    /** L'identifiant de la ville visée, ou null si le choix désigne un lieu précis. */
    public static function villeDe(mixed $choix): ?int
    {
        if (! self::estTouteLaVille($choix)) {
            return null;
        }

        $id = (int) substr((string) $choix, strlen(self::TOUTE_LA_VILLE));

        return $id > 0 ? $id : null;
    }

    /**
     * La valeur à présélectionner pour un compte déjà rattaché.
     *
     * On ne se fie pas à `users.site_id` : il est nul quand la personne répond de
     * plusieurs ateliers, et le champ reviendrait vide à chaque reprise d'accès, comme si
     * rien n'avait jamais été saisi. La vérité est du côté des désignations.
     */
    public static function choixActuel(?User $compte): string
    {
        if (! $compte) {
            return '';
        }

        $tenus = Site::withoutGlobalScopes()
            ->where('responsable_id', $compte->id)
            ->get(['id', 'ville_id']);

        if ($tenus->isEmpty()) {
            return (string) ($compte->site_id ?: '');
        }

        if ($tenus->count() === 1) {
            return (string) $tenus->first()->id;
        }

        // Plusieurs ateliers dans la même ville : c'est « toute la ville ». Répartis sur
        // plusieurs villes, aucune entrée de la liste ne le dit — on retombe sur le
        // premier lieu plutôt que de présélectionner quelque chose de faux.
        $villes = $tenus->pluck('ville_id')->unique();

        return $villes->count() === 1
            ? self::TOUTE_LA_VILLE.$villes->first()
            : (string) $tenus->first()->id;
    }

    /**
     * Pose la désignation et renvoie la ville qui en découle.
     *
     * L'appelant a détaché au préalable les désignations que ce compte portait : sans
     * cela, quelqu'un qui passe de « tous les sites » à un seul resterait inscrit sur
     * l'autre, et son ancien atelier continuerait de remonter dans son périmètre sans
     * que rien ne l'indique nulle part.
     */
    public static function poser(int $entrepriseId, User $compte, mixed $choix): ?Ville
    {
        if (self::estTouteLaVille($choix)) {
            $ville = Ville::withoutGlobalScopes()
                ->where('id', self::villeDe($choix))
                ->where('entreprise_id', $entrepriseId)
                ->first();

            if (! $ville) {
                return null;
            }

            Site::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)
                ->where('ville_id', $ville->id)
                ->where('est_actif', true)
                ->update(['responsable_id' => $compte->id]);

            // `site_id` reste nul : aucun lieu ne résume le périmètre, et en désigner un
            // au hasard ferait mentir tous les écrans qui le lisent.
            $compte->forceFill(['ville_id' => $ville->id, 'site_id' => null])->save();

            return $ville;
        }

        $site = Site::withoutGlobalScopes()
            ->where('id', is_numeric($choix) ? (int) $choix : 0)
            ->where('entreprise_id', $entrepriseId)
            ->first();

        if (! $site) {
            return null;
        }

        $site->forceFill(['responsable_id' => $compte->id])->save();
        $compte->forceFill(['ville_id' => $site->ville_id, 'site_id' => $site->id])->save();

        return $site->ville;
    }

    /**
     * Le périmètre d'un compte, en une ligne lisible : « Abidjan — Site 2 », ou
     * « Abidjan — tous les sites » quand il en tient plusieurs.
     */
    public static function libelle(?User $compte): ?string
    {
        if (! $compte) {
            return null;
        }

        $choix = self::choixActuel($compte);

        if ($choix === '') {
            return $compte->ville_id ? Ville::find($compte->ville_id)?->nom : null;
        }

        if (self::estTouteLaVille($choix)) {
            return (Ville::find(self::villeDe($choix))?->nom ?: 'Cette ville').' — tous les sites';
        }

        return Site::find($choix)?->nom;
    }
}

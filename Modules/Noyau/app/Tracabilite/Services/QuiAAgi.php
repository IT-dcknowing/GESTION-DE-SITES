<?php

namespace Modules\Noyau\Tracabilite\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Noyau\Commun\Services\CodeAuteur;
use Spatie\Activitylog\Models\Activity;

/**
 * Qui a touché une ligne, et quand.
 *
 * **La demande, du 25/09, et elle est générale.** « Dans le tableau, ajoute une colonne qui
 * va nous dire qui s'est chargé du ou des traitements sur une ligne concernée, et ils
 * doivent être dans la page détail aussi. NB : cela se fera pour tout traitement sur
 * l'application. » — avec une précision qui compte : le **nom** se lit dans le tableau,
 * les **dates** ne paraissent que dans le détail.
 *
 * C'est la bonne répartition. Un tableau répond à « qui suit ce dossier ? », et une
 * colonne de dates y serait illisible ; le détail répond à « que s'est-il passé, et
 * quand ? », et c'est là que les dates ont un sens.
 *
 * **Rien n'est inventé ni stocké.** Tout est déjà écrit : le journal d'audit consigne
 * chaque écriture avec son auteur et son horodatage, et la ligne porte `cree_par`. Ce
 * service ne fait que réunir les deux et les rendre lisibles. Une colonne « traité par »
 * tenue à part aurait fini par diverger du journal — et c'est le journal qui fait foi.
 *
 * **Une seule requête pour toute une page.** Une par ligne aurait signifié quarante
 * requêtes sur un tableau de quarante lignes, et l'état des impayés en affiche cinquante.
 * On interroge donc par lot, comme partout ailleurs dans la maison.
 */
class QuiAAgi
{
    /**
     * Les personnes qui ont touché chacune de ces lignes.
     *
     * @param  Collection<int, Model>  $lignes  les lignes d'une page, pas toute la table
     * @return array<int, array<int, array{nom: string, code: ?string, fonction: ?string}>>
     *                                                                                      identifiant de la ligne => les personnes, la plus récente d'abord
     */
    public static function pour(Collection $lignes): array
    {
        if ($lignes->isEmpty()) {
            return [];
        }

        $type = $lignes->first()->getMorphClass();
        $ids = $lignes->pluck('id')->all();

        // Le journal d'abord : c'est lui qui porte les gestes, avec leur auteur.
        $traces = Activity::query()
            ->where('subject_type', $type)
            ->whereIn('subject_id', $ids)
            ->whereNotNull('causer_id')
            ->orderByDesc('id')
            ->get(['subject_id', 'causer_id', 'created_at']);

        // Puis celui qui a posé la ligne, quand la colonne existe : une ligne créée et
        // jamais retouchée n'a pas de trace de modification, et son auteur compte autant.
        $createurs = [];

        foreach ($lignes as $ligne) {
            $auteur = $ligne->cree_par ?? $ligne->user_id ?? null;

            if ($auteur !== null) {
                $createurs[$ligne->id] = (int) $auteur;
            }
        }

        $comptes = self::comptes(
            $traces->pluck('causer_id')->merge(array_values($createurs))->filter()->unique()->all(),
        );

        $parLigne = [];

        foreach ($traces as $trace) {
            $compte = $comptes[$trace->causer_id] ?? null;

            if ($compte === null) {
                continue;
            }

            // Une personne qui a agi dix fois n'apparaît qu'une, à sa dernière intervention.
            $parLigne[$trace->subject_id][$trace->causer_id] ??= $compte;
        }

        foreach ($createurs as $ligneId => $auteurId) {
            if (isset($comptes[$auteurId])) {
                $parLigne[$ligneId][$auteurId] ??= $comptes[$auteurId];
            }
        }

        return array_map(fn (array $personnes) => array_values($personnes), $parLigne);
    }

    /**
     * Le détail d'une ligne : chaque personne, et les dates auxquelles elle est intervenue.
     *
     * Les dates sont rendues ici et nulle part ailleurs : c'est la consigne, et c'est aussi
     * ce qui garde le tableau lisible.
     *
     * @return Collection<int, array{nom: string, code: ?string, fonction: ?string, dates: array<int, Carbon>, gestes: int}>
     */
    public static function detailDe(Model $ligne): Collection
    {
        $traces = Activity::query()
            ->where('subject_type', $ligne->getMorphClass())
            ->where('subject_id', $ligne->id)
            ->whereNotNull('causer_id')
            ->orderBy('id')
            ->get(['causer_id', 'created_at']);

        $auteurCreation = $ligne->cree_par ?? $ligne->user_id ?? null;

        $comptes = self::comptes(
            $traces->pluck('causer_id')->push($auteurCreation)->filter()->unique()->all(),
        );

        $parPersonne = [];

        foreach ($traces as $trace) {
            $compte = $comptes[$trace->causer_id] ?? null;

            if ($compte === null) {
                continue;
            }

            $parPersonne[$trace->causer_id] ??= $compte + ['dates' => [], 'gestes' => 0];
            $parPersonne[$trace->causer_id]['dates'][] = $trace->created_at;
            $parPersonne[$trace->causer_id]['gestes']++;
        }

        // Celui qui a posé la ligne, s'il n'a rien fait d'autre : sa date est celle de la
        // ligne, pas une trace du journal — on ne la présente donc pas comme un geste.
        if ($auteurCreation !== null && ! isset($parPersonne[$auteurCreation]) && isset($comptes[$auteurCreation])) {
            $parPersonne[$auteurCreation] = $comptes[$auteurCreation] + [
                'dates' => array_filter([$ligne->created_at]),
                'gestes' => 0,
            ];
        }

        return collect(array_values($parPersonne))
            ->sortByDesc(fn (array $personne) => end($personne['dates']) ?: null)
            ->values();
    }

    /**
     * Les comptes nommés, avec ce qui permet de les désigner sans ambiguïté.
     *
     * Le code de saisie plutôt que le seul nom : deux homonymes existent, et c'est
     * précisément quand on demande des comptes qu'il ne faut pas se tromper de personne.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{nom: string, code: ?string, fonction: ?string}>
     */
    private static function comptes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->with('roles')
            ->get()
            ->mapWithKeys(fn (User $compte) => [$compte->id => [
                'nom' => (string) $compte->name,
                'code' => CodeAuteur::pour($compte),
                'fonction' => self::fonction($compte),
            ]])
            ->all();
    }

    /** Le rôle, écrit comme on le dit — « Responsable de site », pas « responsable_site ». */
    private static function fonction(User $compte): ?string
    {
        $role = $compte->roles->first()?->name;

        if ($role === null) {
            return null;
        }

        return match ($role) {
            'gerant' => 'Gérant',
            'responsable_ville' => 'Responsable de ville',
            'responsable_site' => 'Responsable de site',
            'commercial' => 'Commercial',
            'caissier' => 'Comptable',
            'superviseur_recouvrement' => 'Superviseur recouvrement',
            'agent_recouvrement' => 'Agent de recouvrement',
            'super_admin' => 'Plateforme',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }
}

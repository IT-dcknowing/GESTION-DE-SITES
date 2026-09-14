<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;

/**
 * Rapproche un code de deux lettres d'un nom d'employé — et n'en fait qu'une proposition.
 *
 * **Ce que ce service ne peut pas faire, mesuré plutôt que supposé.** On a confronté les
 * 17 codes relevés dans les exports à la liste des 28 employés du logiciel et à l'annuaire
 * des 17 accès de la plateforme. Résultat : 10 codes trouvent un candidat, **7 n'en
 * trouvent aucun** — dont KZ, qui porte 924 fiches, et YK, qui en porte 167. Et quatre des
 * dix candidats sont ambigus, deux personnes se disputant le même code.
 *
 * La raison est simple : les deux listes ne décrivent pas la même population. L'annuaire
 * recense qui se connecte à la plateforme ; les codes désignent qui rédige des fiches dans
 * le logiciel de l'atelier. Un chef d'atelier peut saisir mille fiches sans avoir jamais
 * ouvert la plateforme.
 *
 * Ce service ne conclut donc rien. Il pré-remplit un formulaire et classe les candidats,
 * ce qui transforme une page blanche en deux clics — mais la décision reste humaine, parce
 * qu'un code mal attribué déplace le chiffre d'affaires d'un atelier vers l'autre sans que
 * rien ne le signale.
 *
 * **Les formes reconnues** sont celles qu'on observe dans les vrais codes :
 *
 *   - initiale du prénom + initiale du nom — « TT » pour TOU Tahirou ;
 *   - initiale du nom + initiale du prénom — « SK » pour Sandrine Kouadio ;
 *   - les deux premières lettres du nom de famille — « AB » pour ABE Géraud.
 */
class RapprochementDesCodes
{
    /** Mots d'un état civil qui ne sont pas des noms et ne donnent donc pas d'initiale. */
    private const PARTICULES = ['MADAME', 'MONSIEUR', 'MME', 'MLLE', 'EPSE', 'EP', 'NEE', 'DIT', 'DE', 'DU', 'LA', 'LE'];

    public function __construct(private int $entrepriseId) {}

    /**
     * Tous les codes qu'un nom pourrait raisonnablement produire.
     *
     * @return list<string>
     */
    public static function formesDe(string $nom): array
    {
        $mots = self::mots($nom);

        if ($mots === []) {
            return [];
        }

        $formes = [];
        $initiales = array_map(fn (string $mot) => mb_substr($mot, 0, 1), $mots);

        foreach ($initiales as $a => $premiere) {
            foreach ($initiales as $b => $seconde) {
                if ($a !== $b) {
                    $formes[] = $premiere.$seconde;
                }
            }
        }

        // Les deux premières lettres du nom de famille, qui vient en tête dans les états
        // civils du logiciel : c'est ainsi que « ABE Géraud » donne « AB ».
        if (mb_strlen($mots[0]) >= 2) {
            $formes[] = mb_substr($mots[0], 0, 2);
        }

        return array_values(array_unique(array_filter(
            $formes,
            fn (string $forme) => (bool) preg_match('/^[A-Z]{2}$/', $forme),
        )));
    }

    /**
     * Les codes libres qui pourraient être ceux de cette personne, les plus utilisés d'abord.
     *
     * Sert au formulaire de création d'un accès : on tape le nom, et les codes plausibles
     * apparaissent avec leur volume, ce qui permet de trancher d'un coup d'œil — « KK, 101
     * fiches » se confirme ou s'écarte tout de suite.
     *
     * @return Collection<int, CodeAgent>
     */
    public function codesPossiblesPour(string $nom): Collection
    {
        $formes = self::formesDe($nom);

        if ($formes === []) {
            return collect();
        }

        return CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNull('user_id')
            ->whereIn('code', $formes)
            ->orderByDesc('occurrences')
            ->get();
    }

    /**
     * Les comptes dont le nom pourrait produire ce code.
     *
     * Sert à l'écran des codes, dans l'autre sens : en face de « KZ — 924 fiches », on
     * propose les personnes dont le nom colle. Souvent aucune, et c'est une information en
     * soi — elle dit que le code appartient à quelqu'un qui n'a pas de compte ici.
     *
     * @return Collection<int, User>
     */
    public function comptesPossiblesPour(string $code): Collection
    {
        $code = mb_strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return collect();
        }

        $pris = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNotNull('user_id')
            ->where('code', '!=', $code)
            ->pluck('user_id');

        return User::where('entreprise_id', $this->entrepriseId)
            ->whereNotIn('id', $pris)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $compte) => in_array($code, self::formesDe($compte->name), true))
            ->values();
    }

    /**
     * Les mots signifiants d'un nom, en majuscules sans accents.
     *
     * La normalisation du module fait le gros du travail ; on retire ensuite les mentions
     * d'état civil, qui donneraient des initiales absurdes — « MADAME ADOU VANESSA »
     * produirait « MA », qui est justement un code réel appartenant à quelqu'un d'autre.
     *
     * @return list<string>
     */
    private static function mots(string $nom): array
    {
        $mots = preg_split('/\s+/', CorrespondanceImport::normaliser($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $mots,
            fn (string $mot) => mb_strlen($mot) > 1 && ! in_array($mot, self::PARTICULES, true),
        ));
    }
}

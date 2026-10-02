<?php

namespace Modules\Noyau\Commun\Services;

use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

/**
 * La série d'un graphique, en une requête au lieu d'une par point.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi ce service existe — mesuré le 02/10.** « Les clics sur certaines choses dans
 * l'application ne sont toujours pas instantanés. » Un simple changement de filtre sur
 * `/tresorerie` coûtait **cinquante-trois requêtes**, dont **vingt-six pour le seul
 * graphique** : chacun des treize points demandait sa somme, deux fois, une fois pour les
 * entrées et une fois pour les sorties.
 *
 * Six écrans portaient la même boucle. Un point par jour sur un mois, et c'est soixante
 * allers-retours pour une courbe.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Le calcul est le même, pas seulement proche.** Chaque point devient une colonne
 * `sum(case when date between … then … else 0 end)` de la **même** requête, avec le même
 * périmètre, les mêmes filtres et les mêmes bornes. C'est une réécriture de la forme, pas
 * de l'arithmétique — et `tests/Feature/UneSerieDeGraphiqueCoutUneRequeteTest.php` le tient
 * en comparant, chiffre par chiffre, à la boucle d'avant.
 *
 * Ce point compte plus qu'ailleurs : ces courbes portent des montants, sur une base qui
 * porte des écritures réelles. Un graphique faux est pire qu'un graphique lent.
 *
 * **Le nombre de colonnes est borné**, et c'est ce qui rend le procédé sûr : les points
 * viennent de `PeriodeCalculateur::points()`, qui en rend au plus trente et un (un par jour
 * sur un mois), puis passe à la semaine, puis au mois. Il n'y a pas de cas où cette requête
 * deviendrait démesurée.
 */
class SerieParPoint
{
    /**
     * Les sommes d'une colonne, point par point, dans l'ordre des points reçus.
     *
     * @param  Builder  $requete  la requête déjà filtrée — elle n'est pas modifiée
     * @param  array<int, array{debut: mixed, fin: mixed}>  $points
     * @return array<int, int>
     */
    public static function sommes(Builder $requete, array $points, string $colonneDate, string $colonneValeur): array
    {
        // La colonne sommée entre elle aussi dans du SQL brut.
        self::exigerUnNomDeColonne($colonneValeur);

        return self::agreger($requete, $points, $colonneDate, fn (string $i) => "coalesce(sum(case when %s then {$colonneValeur} else 0 end), 0) as {$i}");
    }

    /**
     * Les nombres de lignes, point par point.
     *
     * @param  array<int, array{debut: mixed, fin: mixed}>  $points
     * @return array<int, int>
     */
    public static function nombres(Builder $requete, array $points, string $colonneDate): array
    {
        return self::agreger($requete, $points, $colonneDate, fn (string $i) => "sum(case when %s then 1 else 0 end) as {$i}");
    }

    /**
     * @param  array<int, array{debut: mixed, fin: mixed}>  $points
     * @param  callable(string): string  $gabarit
     * @return array<int, int>
     */
    private static function agreger(Builder $requete, array $points, string $colonneDate, callable $gabarit): array
    {
        self::exigerUnNomDeColonne($colonneDate);

        if ($points === []) {
            return [];
        }

        $colonnes = [];
        $liaisons = [];

        /*
         * **Bornée à l'union exacte des points, et à rien de plus serré.**
         *
         * Sans borne du tout, la requête lit la table entière et l'index sur la date ne sert
         * à rien. Mais borner à la plage demandée serait faux : `pointsHebdomadaires` fait
         * commencer le premier point au lundi, donc parfois **avant** le début de la plage,
         * et ce premier point perdrait des jours que la boucle d'avant comptait.
         *
         * L'union des fenêtres des points est le seul encadrement qui ne puisse écarter
         * aucune ligne qu'un `case` aurait comptée.
         */
        $bornes = [];

        foreach ($points as $point) {
            $bornes[] = $point['debut'];
            $bornes[] = $point['fin'];
        }

        $requete = (clone $requete)->whereBetween($colonneDate, [min($bornes), max($bornes)]);

        foreach ($points as $rang => $point) {
            // `p0`, `p1`… : un alias à nous, qui ne peut pas entrer en conflit avec une
            // colonne de la table, et qui ne vient jamais de ce qu'on a reçu.
            $alias = 'p'.$rang;
            $colonnes[] = sprintf($gabarit($alias), "{$colonneDate} between ? and ?");
            $liaisons[] = $point['debut'];
            $liaisons[] = $point['fin'];
        }

        /*
         * On travaille sur la copie faite plus haut : la requête reçue sert souvent à autre
         * chose ensuite — le total, la liste, un autre découpage —, et lui ajouter un
         * `select` la laisserait mutilée pour son appelant.
         *
         * Les liaisons du `select` passent avant celles du `where`, et c'est bien l'ordre
         * dans lequel Laravel les assemble : les colonnes calculées sont écrites avant le
         * `from`.
         */
        $ligne = $requete->selectRaw(implode(', ', $colonnes), $liaisons)->first();

        $series = [];

        foreach (array_keys($points) as $rang) {
            $alias = 'p'.$rang;

            // `null` est le cas normal, pas une anomalie : une somme sur zéro ligne vaut
            // `null` en SQL, et un graphique veut y lire un zéro.
            $valeur = match (true) {
                $ligne === null => 0,
                is_array($ligne) => $ligne[$alias] ?? 0,
                default => $ligne->{$alias} ?? 0,
            };

            $series[] = (int) $valeur;
        }

        return $series;
    }

    /**
     * Un nom de colonne, et rien qui puisse être du SQL.
     *
     * **Pourquoi un contrôle et non une convention.** Les noms de colonnes entrent ici dans
     * du SQL brut, parce qu'un `case when` ne se construit pas autrement. Aujourd'hui ils
     * sont écrits en dur dans les six écrans appelants — `date`, `date_emission`, `montant` —
     * et rien ne vient du navigateur.
     *
     * C'est exactement le genre de vérité qui cesse d'être vraie sans qu'on s'en aperçoive :
     * il suffira d'un écran qui laisse choisir la colonne à tracer. Le contrôle coûte une
     * expression régulière et ferme la porte par construction, plutôt que par la vigilance
     * des appelants à venir.
     */
    private static function exigerUnNomDeColonne(string $colonne): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $colonne) !== 1) {
            throw new InvalidArgumentException(
                "« {$colonne} » n'est pas un nom de colonne : cette valeur entrerait dans du SQL brut.",
            );
        }
    }
}

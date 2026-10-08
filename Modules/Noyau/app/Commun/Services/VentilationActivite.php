<?php

namespace Modules\Noyau\Commun\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Répartit un montant entre les deux activités de l'entreprise — Mécanique et Sinistre.
 *
 * Toutes les écritures ne portent pas leur activité : une facture et un devis l'ont
 * toujours, une charge et un encaissement seulement quand celui qui saisit la connaît.
 * Répartir ce reliquat au prorata donnerait des chiffres inventés ; il est donc isolé
 * sous « non ventilé », de sorte que les trois lignes refassent toujours le total exact.
 */
final class VentilationActivite
{
    public const ACTIVITES = ['Mécanique', 'Sinistre'];

    /**
     * @param  Builder  $requete  la requête déjà filtrée (période, sites, commercial…)
     * @return array{mecanique:int, sinistre:int, nonVentile:int}
     */
    public static function repartir(Builder $requete, string $colonne = 'montant', ?string $activiteSql = null): array
    {
        // L'activité se lit d'ordinaire dans la colonne ; un appelant peut la lire autrement
        // — un règlement prend celle de sa facture, voir `Encaissement::ACTIVITE_SQL`. Le SQL
        // vient d'une constante du code, jamais d'une saisie.
        $activite = $activiteSql ?? 'activite';

        // La colonne agrégée ne vient jamais de l'utilisateur, mais elle entre dans du SQL
        // brut : on n'accepte donc que celles qu'on a nommées ici.
        if (! in_array($colonne, ['montant', 'montant_valide'], true)) {
            throw new \InvalidArgumentException("Colonne non agrégeable : $colonne");
        }

        // La requête reste Eloquent — et non ->getQuery() — pour que le périmètre
        // entreprise posé par le scope global s'applique encore à l'agrégat.
        // reorder() retire le tri éventuel : MySQL refuse un ORDER BY sur une colonne
        // absente du GROUP BY, et l'ordre n'a de toute façon aucun sens sur un total.
        $lignes = (clone $requete)->reorder()
            ->selectRaw("$activite as activite")
            ->selectRaw("SUM($colonne) as total")
            ->groupByRaw($activite)
            ->get();

        $repartition = ['mecanique' => 0, 'sinistre' => 0, 'nonVentile' => 0];

        foreach ($lignes as $ligne) {
            $cle = match ($ligne->activite) {
                'Mécanique' => 'mecanique',
                'Sinistre' => 'sinistre',
                default => 'nonVentile',
            };

            $repartition[$cle] += (int) $ligne->total;
        }

        return $repartition;
    }

    /**
     * Même répartition, sur des lignes déjà chargées en mémoire. À préférer dès que la
     * collection sert aussi à autre chose : la relire en base coûterait un aller-retour
     * de plus pour un total qu'on a déjà sous la main.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $lignes
     * @return array{mecanique:int, sinistre:int, nonVentile:int}
     */
    public static function repartirCollection($lignes, string $colonne = 'montant'): array
    {
        return [
            'mecanique' => (int) $lignes->where('activite', 'Mécanique')->sum($colonne),
            'sinistre' => (int) $lignes->where('activite', 'Sinistre')->sum($colonne),
            'nonVentile' => (int) $lignes->whereNotIn('activite', self::ACTIVITES)->sum($colonne),
        ];
    }

    /** Soustrait deux répartitions, ligne à ligne : utile pour un résultat ou une trésorerie. */
    public static function difference(array $entrees, array $sorties): array
    {
        return [
            'mecanique' => $entrees['mecanique'] - $sorties['mecanique'],
            'sinistre' => $entrees['sinistre'] - $sorties['sinistre'],
            'nonVentile' => $entrees['nonVentile'] - $sorties['nonVentile'],
        ];
    }
}

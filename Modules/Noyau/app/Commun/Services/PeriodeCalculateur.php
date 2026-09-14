<?php

namespace Modules\Noyau\Commun\Services;

use Carbon\Carbon;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;

/**
 * Calcule la plage de dates [début, fin] pour les filtres partagés par le tableau de
 * bord et les onglets de consultation. Deux modes : « calendrier » (Mois → Semaine →
 * Jour, en cascade, année de l'exercice en cours) et « période » (un intervalle de mois
 * choisi librement, année en cours).
 */
class PeriodeCalculateur
{
    /**
     * @param  string  $periode  'calendrier' ou 'periode'.
     * @param  string|null  $debutPersonnalise  Mois de début au format "Y-m" (mode période).
     * @param  string|null  $finPersonnalisee  Mois de fin au format "Y-m" (mode période).
     * @param  int|null  $moisFiltre  Mois choisi (1-12) en mode calendrier, ou null pour « tous les mois ».
     * @param  int|null  $semaineFiltre  Numéro de semaine dans le mois choisi, ou null pour « toutes les semaines ».
     * @param  int|null  $jourFiltre  Jour du mois (ou de la semaine si elle est précisée), ou null pour « tous les jours ».
     */
    public static function plage(
        string $periode,
        ?string $debutPersonnalise = null,
        ?string $finPersonnalisee = null,
        ?int $moisFiltre = null,
        ?int $semaineFiltre = null,
        ?int $jourFiltre = null,
    ): array {
        $aujourdhui = Carbon::today();

        // L'année regardée gouverne aussi les valeurs par défaut du mode « période » :
        // sans bornes saisies, on prend l'exercice consulté en entier plutôt que l'année
        // civile, sans quoi passer sur 2025 laisserait des bornes restées en 2026.
        $anneeRegardee = ExerciceDeTravail::annee() ?? $aujourdhui->year;
        $courante = $anneeRegardee === $aujourdhui->year;

        if ($periode === 'periode') {
            $debut = $debutPersonnalise
                ? Carbon::createFromFormat('Y-m', $debutPersonnalise)->startOfMonth()
                : Carbon::create($anneeRegardee, 1, 1)->startOfMonth();
            $fin = $finPersonnalisee
                ? Carbon::createFromFormat('Y-m', $finPersonnalisee)->endOfMonth()
                // Sur l'année en cours on s'arrête au mois courant ; sur une année passée,
                // s'y arrêter amputerait l'exercice de ses derniers mois.
                : ($courante ? $aujourdhui->copy()->endOfMonth() : Carbon::create($anneeRegardee, 12, 31));

            // Un intervalle de mois inversé (fin avant début) n'a pas de sens à interroger.
            if ($fin->lessThan($debut)) {
                $fin = $debut->copy()->endOfMonth();
            }

            return [$debut->startOfDay(), $fin->startOfDay()];
        }

        // Mode « calendrier » : Mois → Semaine → Jour, en cascade, sur **l'année regardée**
        // — pas nécessairement celle où l'on est.
        //
        // La version précédente lisait l'année du jour. Choisir « Mars » en consultant
        // l'exercice 2025 ramenait donc mars 2026, c'est-à-dire un mois vide, sans que rien
        // ne le signale : l'écran affichait zéro, et on en concluait qu'il ne s'était rien
        // passé cette année-là.
        $annee = ExerciceDeTravail::annee() ?? $aujourdhui->year;

        if (! $moisFiltre) {
            // « Tous les mois » : l'exercice complet (1er janvier au 31 décembre), pas
            // seulement jusqu'à aujourd'hui — pour que l'objectif de la période reflète la
            // cible annuelle complète (objectif mensuel × 12) et non un prorata figé sur la
            // date du jour. Les requêtes sur des écritures réelles ne portent de toute façon
            // jamais au-delà d'aujourd'hui : aucune ligne future n'existe pour les gonfler.
            return [Carbon::create($annee, 1, 1)->startOfDay(), Carbon::create($annee, 12, 31)->startOfDay()];
        }

        $moisDebut = Carbon::create($annee, $moisFiltre, 1)->startOfMonth();
        $moisFin = $moisDebut->copy()->endOfMonth();

        if (! $semaineFiltre) {
            if (! $jourFiltre) {
                return [$moisDebut->startOfDay(), $moisFin->startOfDay()];
            }

            $jour = $moisDebut->copy()->day(min($jourFiltre, $moisFin->day));

            return [$jour->startOfDay(), $jour->copy()->startOfDay()];
        }

        $semaines = self::semainesDuMois($moisDebut);
        $semaine = $semaines[$semaineFiltre - 1] ?? $semaines[0];

        if (! $jourFiltre) {
            return [$semaine['debut']->copy()->startOfDay(), $semaine['fin']->copy()->startOfDay()];
        }

        $jour = $semaine['debut']->copy()->addDays(min($jourFiltre, 7) - 1)->min($semaine['fin']);

        return [$jour->startOfDay(), $jour->copy()->startOfDay()];
    }

    /** Les douze mois de l'année en cours, pour peupler le sélecteur « Mois ». */
    public static function moisDeLAnnee(): array
    {
        $annee = Carbon::today()->year;

        return collect(range(1, 12))
            ->mapWithKeys(fn ($m) => [$m => ucfirst(Carbon::create($annee, $m, 1)->translatedFormat('F'))])
            ->all();
    }

    /**
     * Découpe un mois en semaines calendaires (1 à 6 selon l'alignement des jours),
     * chacune bornée aux limites du mois. Sert à la fois au sélecteur « Semaine » et
     * à la résolution de la plage quand une semaine précise est choisie.
     */
    public static function semainesDuMois(Carbon $moisDebut): array
    {
        $moisDebut = $moisDebut->copy()->startOfMonth();
        $moisFin = $moisDebut->copy()->endOfMonth();
        $semaines = [];
        $curseur = $moisDebut->copy()->startOfWeek();
        $i = 1;

        while ($curseur->lessThanOrEqualTo($moisFin)) {
            $finSemaine = $curseur->copy()->endOfWeek();
            $semaines[] = [
                'numero' => $i,
                'debut' => $curseur->copy()->max($moisDebut)->startOfDay(),
                'fin' => $finSemaine->copy()->min($moisFin)->startOfDay(),
            ];
            $curseur->addWeek();
            $i++;
        }

        return $semaines;
    }

    /** Nombre calendaires inclus dans la plage (1 pour une journée). */
    public static function nombreDeJours(Carbon $debut, Carbon $fin): int
    {
        return (int) $debut->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay()) + 1;
    }

    /**
     * Prorata exact d'un objectif mensuel sur une plage de dates, mois par mois : un
     * mois plein rend l'objectif exact (jamais 103 % pour un mois de 31 jours comme le
     * ferait un prorata naïf sur 30 jours), une plage partielle ou à cheval sur
     * plusieurs mois additionne le prorata réel de chacun.
     */
    public static function objectifProrata(float $objectifMensuel, Carbon $debut, Carbon $fin): float
    {
        if ($objectifMensuel <= 0) {
            return 0;
        }

        $total = 0.0;
        $curseur = $debut->copy()->startOfMonth();

        while ($curseur->lessThanOrEqualTo($fin)) {
            $finMois = $curseur->copy()->endOfMonth();
            $borneDebut = $curseur->copy()->max($debut);
            $borneFin = $finMois->copy()->min($fin);
            $joursDansCeMois = self::nombreDeJours($borneDebut, $borneFin);
            $total += $objectifMensuel * ($joursDansCeMois / $curseur->daysInMonth);
            $curseur->addMonthNoOverflow()->startOfMonth();
        }

        return $total;
    }

    /**
     * Découpe la plage en points de graphique, avec une granularité adaptée à sa durée :
     * une semaine se lit jour par jour, un mois semaine par semaine, une période plus
     * longue mois par mois. Sans cela une période courte ne produit qu'une seule barre
     * et le graphique perd tout intérêt — et une longue période plafonnée aux 12
     * premières semaines depuis son début manquerait toutes les données récentes.
     */
    public static function points(Carbon $debut, Carbon $fin): array
    {
        $jours = self::nombreDeJours($debut, $fin);

        if ($jours <= 1) {
            return [['label' => $debut->translatedFormat('d/m'), 'debut' => $debut->copy(), 'fin' => $debut->copy()]];
        }

        if ($jours <= 31) {
            return self::pointsJournaliers($debut, $fin);
        }

        if ($jours <= 120) {
            return self::pointsHebdomadaires($debut, $fin);
        }

        return self::pointsMensuels($debut, $fin);
    }

    /** Un point par jour, en sautant un libellé sur deux au-delà de deux semaines. */
    private static function pointsJournaliers(Carbon $debut, Carbon $fin): array
    {
        $jours = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $points = [];
        $curseur = $debut->copy();
        $court = self::nombreDeJours($debut, $fin) <= 8;

        while ($curseur->lessThanOrEqualTo($fin)) {
            $points[] = [
                'label' => $court ? $jours[$curseur->dayOfWeek].' '.$curseur->format('d') : $curseur->format('d/m'),
                'debut' => $curseur->copy(),
                'fin' => $curseur->copy(),
            ];
            $curseur->addDay();
        }

        return $points;
    }

    /** Découpe la plage en points hebdomadaires pour les graphiques, sur toute la période demandée. */
    public static function pointsHebdomadaires(Carbon $debut, Carbon $fin): array
    {
        $points = [];
        $curseur = $debut->copy()->startOfWeek();
        $i = 1;

        while ($curseur->lessThanOrEqualTo($fin)) {
            $finSemaine = $curseur->copy()->endOfWeek();
            $points[] = [
                'label' => 'S'.$i,
                'debut' => $curseur->copy(),
                'fin' => $finSemaine->copy(),
            ];
            $curseur->addWeek();
            $i++;
        }

        return $points;
    }

    /** Découpe la plage en points mensuels pour les graphiques, sur toute la période demandée. */
    private static function pointsMensuels(Carbon $debut, Carbon $fin): array
    {
        $points = [];
        $curseur = $debut->copy()->startOfMonth();

        while ($curseur->lessThanOrEqualTo($fin)) {
            $finMois = $curseur->copy()->endOfMonth();
            $points[] = [
                'label' => ucfirst($curseur->translatedFormat('M')).' '.$curseur->format('y'),
                'debut' => $curseur->copy()->max($debut),
                'fin' => $finMois->copy()->min($fin),
            ];
            $curseur->addMonthNoOverflow();
        }

        return $points;
    }
}

<?php

namespace Modules\Recouvrement\Support;

use Illuminate\Support\Carbon;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;

/**
 * La période qu'on regarde, et l'instant auquel l'encours est arrêté.
 *
 * **Le défaut auquel cette classe répond, et il valait la peine d'être relevé.** Chaque écran
 * du recouvrement portait un champ « Date de l'écriture » — une date libre, tapée à la main.
 * Elle faisait deux métiers à la fois, et c'est de là que venait la gêne :
 *
 * - sur l'écran de saisie, c'était **la date de l'écriture qu'on s'apprêtait à enregistrer** ;
 * - partout ailleurs, c'était **l'arrêté**, l'instant auquel on regarde le stock.
 *
 * Un même champ pour deux métiers finit toujours par nuire aux deux. Reculer la date pour
 * consulter un encours passé changeait aussi la date du prochain encaissement saisi ; et
 * choisir une date au hasard donnait un arrêté qui ne correspondait à aucune période
 * comptable, donc à aucun chiffre comparable.
 *
 * **Ce qui remplace.** La même commande que partout ailleurs dans l'application : exercice,
 * mois, semaine, jour. On choisit une période, et **l'arrêté s'en déduit** — sa dernière
 * journée. L'année entière s'arrête au 31 décembre de l'exercice, ou à aujourd'hui quand
 * l'exercice est celui en cours : arrêter l'encours au 31 décembre d'une année qui n'est pas
 * finie ferait entrer des factures qui n'existent pas encore.
 *
 * La date d'écriture, elle, est retournée à sa place : dans le formulaire qui l'enregistre.
 */
class PeriodeDeTravail
{
    private function __construct(
        public readonly int $annee,
        public readonly ?int $mois,
        public readonly ?int $semaine,
        public readonly ?int $jour,
        public readonly Carbon $debut,
        public readonly Carbon $arrete,
    ) {}

    /**
     * Lit la période dans les paramètres d'une requête.
     *
     * Tout est borné : un mois hors des douze, une semaine qui n'existe pas dans le mois,
     * un jour au-delà de la fin — tout cela retombe sur la période la plus large. Une
     * adresse forgée à la main ne doit pas produire un arrêté fantaisiste, et surtout pas
     * un arrêté silencieusement faux.
     */
    public static function depuis(?string $mois, ?string $semaine, ?string $jour): self
    {
        $annee = ExerciceDeTravail::annee() ?? (int) date('Y');
        $aujourdHui = Carbon::today();

        $moisRetenu = self::borne($mois, 1, 12);

        if ($moisRetenu === null) {
            // L'année entière. On ne dépasse jamais aujourd'hui : arrêter au 31 décembre
            // d'un exercice en cours ferait entrer des factures qui n'existent pas.
            $debut = Carbon::create($annee, 1, 1)->startOfDay();
            $fin = Carbon::create($annee, 12, 31)->endOfDay();

            return new self($annee, null, null, null, $debut, self::sansDepasser($fin, $aujourdHui));
        }

        $premierDuMois = Carbon::create($annee, $moisRetenu, 1);
        $debut = $premierDuMois->copy()->startOfDay();
        $fin = $premierDuMois->copy()->endOfMonth()->endOfDay();

        $semaines = PeriodeCalculateur::semainesDuMois($premierDuMois);
        $semaineRetenue = self::borne($semaine, 1, count($semaines));

        if ($semaineRetenue !== null) {
            $bornes = $semaines[$semaineRetenue - 1];
            $debut = $bornes['debut']->copy()->startOfDay();
            $fin = $bornes['fin']->copy()->endOfDay();
        }

        $jourRetenu = self::borne($jour, 1, $premierDuMois->daysInMonth);

        if ($jourRetenu !== null) {
            $leJour = Carbon::create($annee, $moisRetenu, $jourRetenu);

            // Un jour choisi hors de la semaine retenue n'est pas une erreur de l'utilisateur
            // mais un reste du choix précédent : la semaine reprend la main.
            if ($semaineRetenue === null || $leJour->betweenIncluded($debut, $fin)) {
                $debut = $leJour->copy()->startOfDay();
                $fin = $leJour->copy()->endOfDay();
            } else {
                $jourRetenu = null;
            }
        }

        return new self(
            $annee, $moisRetenu, $semaineRetenue, $jourRetenu,
            $debut, self::sansDepasser($fin, $aujourdHui),
        );
    }

    /** L'arrêté au format ISO — c'est ce que les services du module attendent. */
    public function arreteIso(): string
    {
        return $this->arrete->toDateString();
    }

    /** Ce que la période dit d'elle-même, en une ligne lisible. */
    public function enClair(): string
    {
        $mois = PeriodeCalculateur::moisDeLAnnee();

        return match (true) {
            $this->jour !== null => 'Le '.$this->debut->format('d/m/Y'),
            $this->semaine !== null => 'Semaine '.$this->semaine.' — du '.$this->debut->format('d/m')
                .' au '.$this->arrete->format('d/m/Y'),
            $this->mois !== null => ($mois[$this->mois] ?? '').' '.$this->annee,
            default => 'Exercice '.$this->annee.' — année entière',
        };
    }

    /**
     * Les paramètres à recopier dans un lien pour que la période le suive.
     *
     * Les valeurs vides sont retirées : une barre d'adresse encombrée de paramètres nuls
     * ne se relit pas, et ne se transmet pas non plus.
     *
     * @return array<string, int>
     */
    public function parametres(): array
    {
        return array_filter([
            'moisFiltre' => $this->mois,
            'semaineFiltre' => $this->semaine,
            'jourFiltre' => $this->jour,
        ], fn ($valeur) => $valeur !== null);
    }

    private static function borne(?string $valeur, int $minimum, int $maximum): ?int
    {
        if ($valeur === null || trim($valeur) === '' || ! is_numeric($valeur)) {
            return null;
        }

        $entier = (int) $valeur;

        return ($entier >= $minimum && $entier <= $maximum) ? $entier : null;
    }

    private static function sansDepasser(Carbon $fin, Carbon $aujourdHui): Carbon
    {
        return $fin->greaterThan($aujourdHui->copy()->endOfDay())
            ? $aujourdHui->copy()->endOfDay()
            : $fin;
    }
}

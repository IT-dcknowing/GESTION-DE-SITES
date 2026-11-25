<?php

namespace Modules\Noyau\Rapport\Support;

use Illuminate\Support\Carbon;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;

/**
 * La période que lit le rapport : l'exercice, une semaine, un mois ou des dates libres — 25/11.
 *
 * Le rapport ne connaissait que l'exercice, du 1er janvier à aujourd'hui. Le propriétaire veut lire
 * la semaine « du jeudi au mercredi, comme l'Excel de trésorerie », le mois, ou deux dates qu'il
 * choisit ; et que **tous** les onglets chiffrés lisent la même période.
 *
 * **La semaine se choisit par n'importe lequel de ses jours.** On ne demande pas au gérant de
 * retrouver le jeudi : il donne une date, le rapport remonte au jeudi qui ouvre sa semaine et va
 * jusqu'au mercredi suivant. Un lien partagé (`?periode=semaine&jour=2026-11-25`) désigne donc
 * toujours la même semaine, quel que soit le jour choisi en la partageant.
 *
 * **Les dates se lisent par `PeriodeCalculateur::borne()`**, comme sur les autres écrans : une date
 * forgée dans l'adresse retombe sur la valeur par défaut au lieu de faire tomber la page, et une
 * fin avant le début est ramenée sur le début, par la même règle.
 */
final class PeriodeDuRapport
{
    /** Les quatre manières de lire le rapport, dans l'ordre du sélecteur. */
    public const MODES = [
        'exercice' => 'Exercice entier',
        'semaine' => 'Semaine (jeudi au mercredi)',
        'mois' => 'Mois',
        'dates' => 'Dates libres',
    ];

    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    private function __construct(
        public readonly string $mode,
        public readonly Carbon $debut,
        public readonly Carbon $fin,
        public readonly int $exercice,
    ) {}

    /**
     * L'exercice : du 1er janvier à aujourd'hui pour l'année en cours, jusqu'au 31 décembre pour
     * une année passée. C'est la lecture d'avant le 25/11, inchangée.
     */
    public static function exercice(int $annee): self
    {
        $fin = $annee >= (int) now()->year ? Carbon::today()->endOfDay() : Carbon::create($annee, 12, 31)->endOfDay();

        return new self('exercice', Carbon::create($annee, 1, 1)->startOfDay(), $fin, $annee);
    }

    /**
     * La période demandée par l'adresse de la page. Un mode inconnu vaut l'exercice : la valeur
     * vient du navigateur, elle ne choisit qu'entre les quatre lectures prévues.
     */
    public static function lire(string $mode, int $exercice, ?string $jour = null, ?string $mois = null, ?string $du = null, ?string $au = null): self
    {
        $defaut = self::jourParDefaut($exercice);

        return match ($mode) {
            'semaine' => self::semaineDe(PeriodeCalculateur::borne($jour, false) ?? $defaut),
            'mois' => self::moisDe(PeriodeCalculateur::borne($mois, false) ?? $defaut),
            'dates' => self::entre(
                PeriodeCalculateur::borne($du, false) ?? Carbon::create($exercice, 1, 1),
                PeriodeCalculateur::borne($au, true) ?? $defaut,
            ),
            default => self::exercice($exercice),
        };
    }

    /** La semaine, du jeudi au mercredi, qui contient ce jour. */
    public static function semaineDe(\DateTimeInterface $jour): self
    {
        $jeudi = self::jeudiDe(Carbon::instance($jour));

        return new self('semaine', $jeudi, $jeudi->copy()->addDays(6)->endOfDay(), (int) $jeudi->copy()->addDays(6)->year);
    }

    public static function moisDe(\DateTimeInterface $jour): self
    {
        $debut = Carbon::instance($jour)->startOfMonth()->startOfDay();

        return new self('mois', $debut, $debut->copy()->endOfMonth()->endOfDay(), (int) $debut->year);
    }

    public static function entre(\DateTimeInterface $debut, \DateTimeInterface $fin): self
    {
        // `PeriodeCalculateur` rend des `Carbon\Carbon` : on les ramène au Carbon de Laravel.
        $debut = Carbon::instance($debut)->startOfDay();
        $fin = Carbon::instance($fin)->endOfDay();

        if ($fin->lt($debut)) {
            $fin = $debut->copy()->endOfDay();
        }

        return new self('dates', $debut, $fin, (int) $fin->year);
    }

    /** Le jeudi qui ouvre la semaine de ce jour (le jeudi lui-même s'il en est un). */
    public static function jeudiDe(Carbon $jour): Carbon
    {
        // ISO : lundi 1 … jeudi 4 … dimanche 7. Un mercredi (3) recule de six jours, un vendredi d'un.
        return $jour->copy()->startOfDay()->subDays(($jour->dayOfWeekIso - 4 + 7) % 7);
    }

    /** Sans date choisie : aujourd'hui pour l'exercice en cours, le 31 décembre pour un exercice passé. */
    public static function jourParDefaut(int $exercice): Carbon
    {
        return $exercice >= (int) now()->year ? Carbon::today() : Carbon::create($exercice, 12, 31);
    }

    public function estLExercice(): bool
    {
        return $this->mode === 'exercice';
    }

    /** Ce que le titre de chaque onglet écrit après son nom. */
    public function libelle(): string
    {
        return match ($this->mode) {
            'semaine' => 'semaine du jeudi '.$this->debut->format('d/m/Y').' au mercredi '.$this->fin->format('d/m/Y'),
            'mois' => self::MOIS[$this->debut->month - 1].' '.$this->debut->year,
            'dates' => 'du '.$this->debut->format('d/m/Y').' au '.$this->fin->format('d/m/Y'),
            default => 'exercice '.$this->exercice.', du '.$this->debut->format('d/m/Y').' au '.$this->fin->format('d/m/Y'),
        };
    }

    /**
     * Les semaines (jeudi au mercredi) qui couvrent la période, chacune bornée à la période : la
     * première et la dernière peuvent être partielles, et le disent.
     *
     * @return list<array{libelle: string, debut: Carbon, fin: Carbon, partielle: bool}>
     */
    public function semaines(): array
    {
        $tranches = [];
        $jeudi = self::jeudiDe($this->debut);

        while ($jeudi->lte($this->fin)) {
            $mercredi = $jeudi->copy()->addDays(6)->endOfDay();
            $debut = $jeudi->copy()->max($this->debut);
            $fin = $mercredi->copy()->min($this->fin);
            $tranches[] = [
                'libelle' => self::JOURS[$debut->dayOfWeekIso].' '.$debut->format('d/m/Y').' au '.self::JOURS[$fin->dayOfWeekIso].' '.$fin->format('d/m/Y'),
                'debut' => $debut,
                'fin' => $fin,
                'partielle' => ! $debut->eq($jeudi) || ! $fin->eq($mercredi),
            ];
            $jeudi->addDays(7);
        }

        return $tranches;
    }

    /**
     * Les mois qui couvrent la période. Un mois est « complet » s'il est entier dans la période et
     * déjà fini : seul un mois complet entre dans la moyenne mensuelle, comme avant le 25/11.
     *
     * @return list<array{libelle: string, debut: Carbon, fin: Carbon, complet: bool}>
     */
    public function mois(): array
    {
        $tranches = [];
        $curseur = $this->debut->copy()->startOfMonth();
        $plusieursAnnees = $this->debut->year !== $this->fin->year;

        while ($curseur->lte($this->fin)) {
            $finDuMois = $curseur->copy()->endOfMonth()->endOfDay();
            $tranches[] = [
                'libelle' => ucfirst(self::MOIS[$curseur->month - 1]).($plusieursAnnees ? ' '.$curseur->year : ''),
                'debut' => $curseur->copy()->max($this->debut),
                'fin' => $finDuMois->copy()->min($this->fin),
                'complet' => $curseur->gte($this->debut->copy()->startOfDay()) && $finDuMois->lte($this->fin) && $finDuMois->lt(Carbon::today()),
            ];
            $curseur->addMonthNoOverflow()->startOfMonth();
        }

        return $tranches;
    }

    /** La même période, un an plus tôt : ce que l'onglet « Année précédente » compare. */
    public function unAnPlusTot(): self
    {
        if ($this->estLExercice()) {
            return self::exercice($this->exercice - 1);
        }

        return new self($this->mode, $this->debut->copy()->subYearNoOverflow(), $this->fin->copy()->subYearNoOverflow(), $this->exercice - 1);
    }
}

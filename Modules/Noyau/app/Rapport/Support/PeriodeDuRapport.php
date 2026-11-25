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
 *
 * **Le sélecteur se simplifie (25/11, suite).** « Un champ qui liste les semaines de l'année, un
 * champ mois, et une plage de dates qu'on choisit sur un calendrier. » D'où `semainesDeLExercice()`,
 * `moisDeLExercice()` et les `RACCOURCIS` de la plage. Une plage est **bornée**
 * (`premierJourPermis()` à `dernierJourPermis()`) : `semaines()` et `mois()` découpent la période
 * tranche par tranche, et une adresse forgée « du 0001-01-01 au 9999-12-31 » leur ferait construire
 * un demi-million de semaines.
 */
final class PeriodeDuRapport
{
    /** Les quatre manières de lire le rapport, dans l'ordre du sélecteur. */
    public const MODES = [
        'exercice' => 'Exercice entier',
        'semaine' => 'Semaine (jeudi au mercredi)',
        'mois' => 'Mois',
        'dates' => 'Plage de dates',
    ];

    /** Les raccourcis de la plage de dates, dans l'ordre des boutons. */
    public const RACCOURCIS = [
        'semaine' => 'Cette semaine',
        'mois' => 'Ce mois',
        '30jours' => '30 derniers jours',
    ];

    /** Combien d'années en arrière une plage de dates peut remonter. */
    private const ANNEES_PERMISES = 10;

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
        // `PeriodeCalculateur` rend des `Carbon\Carbon` : on les ramène au Carbon de Laravel, et
        // dans les bornes permises (voir l'en-tête : une plage forgée ne découpe pas des siècles).
        $debut = Carbon::instance($debut)->startOfDay()->max(self::premierJourPermis())->min(self::dernierJourPermis()->startOfDay());
        $fin = Carbon::instance($fin)->endOfDay()->max(self::premierJourPermis()->endOfDay())->min(self::dernierJourPermis());

        if ($fin->lt($debut)) {
            $fin = $debut->copy()->endOfDay();
        }

        return new self('dates', $debut, $fin, (int) $fin->year);
    }

    /** Le premier jour qu'une plage de dates peut lire : le 1er janvier d'il y a dix ans. */
    public static function premierJourPermis(): Carbon
    {
        return Carbon::create((int) now()->year - self::ANNEES_PERMISES, 1, 1)->startOfDay();
    }

    /** Le dernier : le 31 décembre de l'année en cours (« Ce mois » va jusqu'à la fin du mois). */
    public static function dernierJourPermis(): Carbon
    {
        return Carbon::create((int) now()->year, 12, 31)->endOfDay();
    }

    /**
     * Une plage toute faite, lue sur aujourd'hui : la semaine en cours (du jeudi au mercredi), le
     * mois en cours, ou les trente derniers jours, aujourd'hui compris. Une clé inconnue ne rend rien.
     */
    public static function raccourci(string $cle): ?self
    {
        $aujourdhui = Carbon::today();

        return match ($cle) {
            'semaine' => self::entre(self::jeudiDe($aujourdhui), self::jeudiDe($aujourdhui)->addDays(6)),
            'mois' => self::entre($aujourdhui->copy()->startOfMonth(), $aujourdhui->copy()->endOfMonth()),
            '30jours' => self::entre($aujourdhui->copy()->subDays(29), $aujourdhui),
            default => null,
        };
    }

    /**
     * Les semaines, du jeudi au mercredi, qui couvrent l'exercice : la première est celle qui
     * contient le 1er janvier (elle peut ouvrir en décembre), la dernière celle qui contient le
     * 31 décembre (elle peut finir en janvier). Chacune est entière : le sélecteur choisit une
     * semaine de l'Excel de trésorerie, pas un morceau d'année.
     *
     * Elles se numérotent dans l'ordre à partir de 1. Quand l'année commence un jeudi (2026), ce
     * numéro est celui de la semaine ISO : le jeudi 19/11/2026 ouvre la semaine 47 dans les deux.
     *
     * La valeur de chaque option est la date du jeudi : c'est ce que porte `?jour=` dans l'adresse.
     *
     * @return list<array{numero: int, valeur: string, libelle: string, debut: Carbon, fin: Carbon}>
     */
    public static function semainesDeLExercice(int $exercice): array
    {
        $semaines = [];
        $jeudi = self::jeudiDe(Carbon::create($exercice, 1, 1));
        $dernier = Carbon::create($exercice, 12, 31)->endOfDay();

        for ($numero = 1; $jeudi->lte($dernier); $numero++, $jeudi->addDays(7)) {
            $mercredi = $jeudi->copy()->addDays(6)->endOfDay();
            // L'année ne s'écrit au début que si la semaine en change : « du jeudi 31/12/2026 au … ».
            $debut = $jeudi->format($jeudi->year === $mercredi->year ? 'd/m' : 'd/m/Y');
            $semaines[] = [
                'numero' => $numero,
                'valeur' => $jeudi->toDateString(),
                'libelle' => 'Semaine '.$numero.' : du jeudi '.$debut.' au mercredi '.$mercredi->format('d/m/Y'),
                'debut' => $jeudi->copy(),
                'fin' => $mercredi,
            ];
        }

        return $semaines;
    }

    /**
     * Les douze mois de l'exercice, pour le champ « Mois ». La valeur est `AAAA-MM`, comme `?mois=`.
     *
     * @return list<array{valeur: string, libelle: string}>
     */
    public static function moisDeLExercice(int $exercice): array
    {
        return array_map(fn (int $m) => [
            'valeur' => sprintf('%04d-%02d', $exercice, $m),
            'libelle' => ucfirst(self::MOIS[$m - 1]).' '.$exercice,
        ], range(1, 12));
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

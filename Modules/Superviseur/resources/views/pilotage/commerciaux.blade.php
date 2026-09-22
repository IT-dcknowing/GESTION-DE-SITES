<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Exploitation\Services\CommissionCommerciale;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use function Livewire\Volt\{state, computed, mount};

state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'villeFiltre' => '',
    'siteFiltre' => '',
    'activiteFiltre' => '',
    'commercialFiltre' => '',
    'pageClassement' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; };
/** Changer de ville rend caduc le lieu choisi dans la précédente. */
$updatedVilleFiltre = function () { $this->siteFiltre = ''; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

$optionsCommerciaux = computed(fn () => Commercial::actifs()->where('est_spontane', false)
    ->whereIn('ville_id', $this->idsVilles)->orderBy('nom')->get());

/**
 * Le gérant seul voit la commission.
 *
 * Elle dit ce que quelqu'un touchera à la fin du mois. Un responsable de site qui lit le
 * classement de ses commerciaux n'a pas à y lire leur rémunération, et un commercial encore
 * moins celle de son voisin.
 */
$voitLesCommissions = computed(fn () => auth()->user()->hasRole('gerant'));

/**
 * Le chiffre d'affaires facturé, mois par mois, pour chaque commercial.
 *
 * **Une seule requête, et le regroupement en PHP.** Extraire l'année et le mois d'une date
 * ne s'écrit pas de la même façon sur MySQL et sur SQLite ; le faire ici évite d'avoir deux
 * versions de la même règle, et le volume reste celui d'une année de facturation.
 *
 * L'intervalle va du 1er janvier de l'année de fin jusqu'à la fin de la période : il couvre
 * à la fois la période affichée et le cumul annuel, qui n'a pas de raison d'être demandé
 * deux fois.
 */
$caMensuel = computed(function () {
    [$debut, $fin] = $this->plage;
    $depuis = $fin->copy()->startOfYear()->min($debut);

    $lignes = Facture::query()
        ->whereNotNull('commercial_id')
        ->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$depuis, $fin])
        ->get(['commercial_id', 'date', 'montant']);

    $parCommercial = [];

    foreach ($lignes as $ligne) {
        $mois = $ligne->date->format('Y-m');
        $parCommercial[$ligne->commercial_id][$mois] ??= 0;
        $parCommercial[$ligne->commercial_id][$mois] += (int) $ligne->montant;
    }

    return $parCommercial;
});

/**
 * Combien de factures de la période portent un commercial — et combien n'en portent pas.
 *
 * Sans ce compte, une commission à zéro se lit comme « il n'a rien vendu » alors qu'elle dit
 * « on ne sait pas qui a vendu ». Les deux méritaient d'être distinguées à l'écran.
 */
$couvertureCommerciale = computed(function () {
    [$debut, $fin] = $this->plage;

    $base = Facture::query()
        ->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);

    $total = (clone $base)->count();

    return ['total' => $total, 'attribuees' => $total === 0 ? 0 : (clone $base)->whereNotNull('commercial_id')->count()];
});

$classement = computed(function () {
    [$debut, $fin] = $this->plage;

    $commerciaux = Commercial::actifs()->where('est_spontane', false)->with(['ville', 'utilisateur'])
        ->whereIn('ville_id', $this->idsVilles)
        ->when($this->commercialFiltre, fn ($q) => $q->where('id', $this->commercialFiltre))
        ->get();

    // CA de la ville sur la période, pour exprimer la contribution de chaque commercial —
    // celui-ci travaillant pour la ville entière, jamais pour un lieu précis. Le CA est
    // néanmoins restreint aux lieux et à l'activité retenus, pour que la contribution se
    // rapporte bien à ce que le filtre affiche.
    $caParVille = Facture::query()
        ->join('sites', 'sites.id', '=', 'factures.site_id')
        ->whereIn('factures.site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('factures.activite', $this->activiteFiltre))
        ->whereBetween('factures.date', [$debut, $fin])
        ->selectRaw('sites.ville_id, SUM(factures.montant) as total')
        ->groupBy('sites.ville_id')
        ->pluck('total', 'ville_id');

    $caMensuel = $this->caMensuel;
    $moisDeLaPeriode = [];
    for ($curseur = $debut->copy()->startOfMonth(); $curseur <= $fin; $curseur->addMonth()) {
        $moisDeLaPeriode[] = $curseur->format('Y-m');
    }
    $anneeDeFin = $fin->format('Y');
    $entrepriseId = auth()->user()->entreprise_id;

    return $commerciaux->map(function ($commercial) use ($debut, $fin, $caParVille, $caMensuel, $moisDeLaPeriode, $anneeDeFin, $entrepriseId) {
        $lignesFactures = Facture::where('commercial_id', $commercial->id)
            ->whereIn('site_id', $this->idsSites)
            ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
            ->whereBetween('date', [$debut, $fin])->get(['montant', 'activite']);
        $realisation = (int) $lignesFactures->sum('montant');
        $objectifProrata = (int) round(PeriodeCalculateur::objectifProrata((float) $commercial->objectif_mensuel, $debut, $fin));
        $ecart = $realisation - $objectifProrata;
        $caVille = (int) ($caParVille[$commercial->ville_id] ?? 0);

        /*
         * La commission, mois par mois — jamais sur la période entière.
         *
         * Le barème est mensuel : appliquer son taux au chiffre d'un trimestre le ferait
         * entrer dans une tranche qu'il n'a jamais atteinte en un mois. Chaque mois est donc
         * calculé pour lui-même, avec la grille en vigueur ce mois-là.
         */
        // La grille n'est pas choisie ici : c'est elle qui dit quels rôles elle rémunère,
        // et le gérant coche cette liste à l'écran.
        $moisDuCommercial = $caMensuel[$commercial->id] ?? [];

        $periode = CommissionCommerciale::surLesMois(
            $entrepriseId, $commercial->utilisateur,
            array_intersect_key($moisDuCommercial, array_flip($moisDeLaPeriode)),
        );

        // Le cumul court sur l'année civile de la fin de période : c'est la façon dont on
        // suit une rémunération, et non sur les douze derniers mois glissants.
        $cumul = CommissionCommerciale::surLesMois(
            $entrepriseId, $commercial->utilisateur,
            array_filter($moisDuCommercial, fn ($mois) => str_starts_with($mois, $anneeDeFin), ARRAY_FILTER_USE_KEY),
        );

        // Le taux affiché est celui du dernier mois de la période : un taux moyen sur
        // plusieurs mois ne correspondrait à aucune ligne du barème.
        $dernierMois = end($moisDeLaPeriode) ?: null;
        $dernier = $dernierMois !== null ? ($periode['mois'][$dernierMois] ?? null) : null;

        return [
            'commercial' => $commercial,
            'commission' => $periode['commission'],
            'commissionCumul' => $cumul['commission'],
            'tauxBareme' => $dernier['taux'] ?? null,
            'caDuDernierMois' => $dernier['ca'] ?? 0,
            'moisSansGrille' => $periode['sansGrille'],
            'moisSansTranche' => $periode['sansTranche'],
            'objectif' => $objectifProrata,
            'objectifMecanique' => (int) round(PeriodeCalculateur::objectifProrata((float) $commercial->objectif_mecanique, $debut, $fin)),
            'objectifSinistre' => (int) round(PeriodeCalculateur::objectifProrata((float) $commercial->objectif_sinistre, $debut, $fin)),
            'objectifJournalier' => (int) round($commercial->objectif_mensuel / 30),
            'realisation' => $realisation,
            'realisationMecanique' => (int) $lignesFactures->where('activite', 'Mécanique')->sum('montant'),
            'realisationSinistre' => (int) $lignesFactures->where('activite', 'Sinistre')->sum('montant'),
            'ecart' => $ecart,
            'taux' => $objectifProrata > 0 ? $realisation / $objectifProrata : null,
            'contribution' => $caVille > 0 ? $realisation / $caVille : null,
        ];
    })->sortByDesc(fn ($l) => $l['taux'] ?? -1)->values();
});

$kpis = computed(function () {
    $objectifTotal = $this->classement->sum('objectif');
    $realisationTotal = $this->classement->sum('realisation');
    $objectifMecanique = $this->classement->sum('objectifMecanique');
    $objectifSinistre = $this->classement->sum('objectifSinistre');
    $realisationMecanique = $this->classement->sum('realisationMecanique');
    $realisationSinistre = $this->classement->sum('realisationSinistre');

    return [
        'nombre' => $this->classement->count(),
        'objectif' => $objectifTotal,
        'objectifMecanique' => $objectifMecanique,
        'objectifSinistre' => $objectifSinistre,
        'realisation' => $realisationTotal,
        'realisationMecanique' => $realisationMecanique,
        'realisationSinistre' => $realisationSinistre,
        'ecart' => $realisationTotal - $objectifTotal,
        'ecartMecanique' => $realisationMecanique - $objectifMecanique,
        'ecartSinistre' => $realisationSinistre - $objectifSinistre,
        'taux' => $objectifTotal > 0 ? $realisationTotal / $objectifTotal : null,
        'tauxMecanique' => $objectifMecanique > 0 ? $realisationMecanique / $objectifMecanique : null,
        'tauxSinistre' => $objectifSinistre > 0 ? $realisationSinistre / $objectifSinistre : null,
    ];
});

$graphique = computed(fn () => [
    'labels' => $this->classement->pluck('commercial.nom')->all(),
    'datasets' => [
        ['label' => 'Objectif (prorata)', 'data' => $this->classement->pluck('objectif')->all(), 'color' => '#191B20'],
        ['label' => 'Réalisation', 'data' => $this->classement->pluck('realisation')->all(), 'color' => '#C8102E'],
    ],
]);

?>

<div>
    <x-titre-ecran titre="Commerciaux"
        sous-titre="L'activité de chacun, de la prospection à la facture." />

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        :commerciaux="$this->optionsCommerciaux" :commercial-filtre="$commercialFiltre">
        {{-- Sur la même ligne que les filtres, et pour le seul gérant : le barème dit ce
             que quelqu'un touchera à la fin du mois, et se règle depuis cet écran-là
             puisque c'est là qu'on lit les commissions. --}}
        @if ($this->voitLesCommissions)
            <a href="{{ route('bareme-commission') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; white-space:nowrap;">Barème de commission</a>
        @endif
    </x-filtre-periode>

    <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Commerciaux — {{ $this->libellePerimetre }}" :value="$this->kpis['nombre']" />
        <x-kpi-card label="Objectif de la période" :value="ae($this->kpis['objectif'])"
            :mecanique="$activiteFiltre ? null : ae($this->kpis['objectifMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['objectifSinistre'])" />
        <x-kpi-card label="Réalisation totale" :value="ae($this->kpis['realisation'])"
            :mecanique="$activiteFiltre ? null : ae($this->kpis['realisationMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['realisationSinistre'])" />
        <x-kpi-card label="Écart global — {{ $this->libellePerimetre }}" :value="ae($this->kpis['ecart'])" :couleur="$this->kpis['ecart'] >= 0 ? '#0E9F6E' : '#C8102E'"
            :mecanique="$activiteFiltre ? null : ae($this->kpis['ecartMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['ecartSinistre'])" />
        <x-kpi-card label="Taux de Réalisation — {{ $this->libellePerimetre }}" :value="an($this->kpis['taux'])"
            :mecanique="$activiteFiltre ? null : an($this->kpis['tauxMecanique'])" :sinistre="$activiteFiltre ? null : an($this->kpis['tauxSinistre'])" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="Objectif vs réalisation par commercial" id="commerciaux-objectif"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Classement des commerciaux par performance</h3>

        @if ($this->voitLesCommissions)
            @php $couverture = $this->couvertureCommerciale; @endphp
            <p style="margin:-6px 0 14px; font-size:12.5px; color:#6B6E76;">
                La commission est calculée <b>mois par mois</b> sur le chiffre d'affaires
                <b>facturé</b> du commercial, avec la grille en vigueur ce mois-là — voir
                <a href="{{ route('bareme-commission') }}" wire:navigate style="color:#C8102E; font-weight:600;">Barème de commission</a>.
                @if ($couverture['total'] > 0 && $couverture['attribuees'] < $couverture['total'])
                    {{-- Une commission à zéro se lit « il n'a rien vendu » ; il faut pouvoir
                         lire « on ne sait pas qui a vendu ». Le chiffre le dit. --}}
                    <b style="color:#B45309;">
                        Sur {{ number_format($couverture['total'], 0, ',', ' ') }} facture(s) de la période,
                        {{ number_format($couverture['attribuees'], 0, ',', ' ') }} seulement portent un commercial :
                        les autres ne sont comptées à personne.
                    </b>
                    L'écran <a href="{{ route('rapprochement.prospections-devis') }}" wire:navigate style="color:#C8102E; font-weight:600;">Rapprochement prospections / devis</a>
                    sert à combler cet écart.
                @endif
            </p>
        @endif
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Rang</th>
                        <th>N°</th>
                        <th>Commercial</th>
                        @if (count($this->idsVilles) > 1)
                            <th>Ville</th>
                        @endif
                        <th>Objectif mensuel</th>
                        <th>Objectif journalier (mensuel/30)</th>
                        <th>Objectif de la période</th>
                        <th>Réalisation</th>
                        <th>Écart</th>
                        <th>Taux de Réalisation</th>
                        <th>Contribution au CA de la ville</th>
                        @if ($this->voitLesCommissions)
                            <th>Barème</th>
                            <th>Commission de la période</th>
                            <th>Cumul {{ $this->plage[1]->format('Y') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->classement->forPage($pageClassement, 10) as $i => $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); {{ $i === 0 ? 'background:#FFFBEA;' : '' }}">
                            <td>
                                @php
                                    // Or, argent, bronze pour le podium, comme dans la maquette.
                                    $medaille = ['#D4AF37', '#9CA3AF', '#B87333'][$i] ?? null;
                                @endphp
                                @if ($medaille)
                                    <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px;
                                                 border-radius:99px; background:{{ $medaille }}; color:#fff; font-weight:700;
                                                 font-family:'Barlow Condensed',sans-serif; font-size:14px;">{{ $i + 1 }}</span>
                                @else
                                    <span style="font-weight:800;">{{ $i + 1 }}</span>
                                @endif
                            </td>
                            <td style="font-weight:700;">{{ $ligne['commercial']->numero }}</td>
                            <td style="font-weight:700;">{{ $ligne['commercial']->nom }}</td>
                            @if (count($this->idsVilles) > 1)
                                <td>{{ $ligne['commercial']->ville->nom }}</td>
                            @endif
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['commercial']->objectif_mensuel) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['objectifJournalier']) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['objectif']) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['realisation']) }}</td>
                            <td style="font-variant-numeric:tabular-nums; color:{{ $ligne['ecart'] >= 0 ? '#0E9F6E' : '#C8102E' }};">{{ ae($ligne['ecart']) }}</td>
                            <td style="font-weight:700; color:{{ ($ligne['taux'] ?? 0) >= 1 ? '#0E9F6E' : '#D97706' }};">{{ an($ligne['taux']) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ an($ligne['contribution']) }}</td>
                            @if ($this->voitLesCommissions)
                                <td style="white-space:nowrap;">
                                    @if ($ligne['moisSansGrille'] > 0)
                                        {{-- Pas de grille n'est pas zéro pour cent : c'est qu'on
                                             n'a rien à quoi se référer. --}}
                                        <span style="color:#B45309; font-size:12px;">aucune grille</span>
                                    @elseif ($ligne['tauxBareme'] === null)
                                        <span style="color:#C8102E; font-size:12px;">hors tranche</span>
                                    @else
                                        <b>{{ rtrim(rtrim(number_format($ligne['tauxBareme'], 2, ',', ' '), '0'), ',') }} %</b>
                                        <span style="color:#6B6E76; font-size:11px;">sur {{ ae($ligne['caDuDernierMois']) }}</span>
                                    @endif
                                </td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; color:#1E7B34;">
                                    {{ ae($ligne['commission']) }}
                                </td>
                                <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['commissionCumul']) }}</td>
                            @endif
                        </tr>
                    @empty
                        <x-table-vide :colspan="(count($this->idsVilles) > 1 ? 10 : 9) + ($this->voitLesCommissions ? 3 : 0)"
                            texte="Aucun commercial actif pour ce filtre." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageClassement" :total="$this->classement->count()" prop="pageClassement" />
    </div>
</div>

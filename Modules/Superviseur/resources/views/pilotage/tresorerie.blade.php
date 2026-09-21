<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\VentilationActivite;
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
    'pageEncaissements' => 1,
    'pageDecaissements' => 1,
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
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

$encaissementsQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return Encaissement::whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$chargesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return Charge::whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$facturesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return Facture::whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

/**
 * Encaissements et charges n'ont pas d'étiquette Mécanique/Sinistre en base — ils ne
 * sont rattachés qu'au site où ils sont enregistrés, et un même site accueille souvent
 * un commercial qui vend sur les deux activités. Une ventilation par activité du site
 * afficherait donc « Sinistre : 0 F » même quand des mouvements liés à cette activité
 * existent bel et bien — trompeur plutôt qu'informatif. Ces KPI restent consolidés.
 */
$kpis = computed(function () {
    $encaisse = (int) (clone $this->encaissementsQ)->sum('montant');
    $decaisse = (int) (clone $this->chargesQ)->sum('montant');
    $facture = (int) (clone $this->facturesQ)->sum('montant');

    // Un encaissement ou un décaissement ne porte son activité que si celui qui l'a
    // saisi la connaissait : la part restante s'affiche « non ventilée » plutôt que
    // d'être répartie au jugé.
    $encaisseVentile = VentilationActivite::repartir($this->encaissementsQ);
    $decaisseVentile = VentilationActivite::repartir($this->chargesQ);
    $factureVentile = VentilationActivite::repartir($this->facturesQ);

    return [
        'encaisse' => $encaisse,
        'encaisseVentile' => $encaisseVentile,
        'decaisse' => $decaisse,
        'decaisseVentile' => $decaisseVentile,
        'net' => $encaisse - $decaisse,
        'netVentile' => VentilationActivite::difference($encaisseVentile, $decaisseVentile),
        'nonEncaisse' => max(0, $facture - $encaisse),
        'nonEncaisseVentile' => array_map(
            fn ($v) => max(0, $v),
            VentilationActivite::difference($factureVentile, $encaisseVentile),
        ),
    ];
});

$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);

    $labels = [];
    $entrees = [];
    $sorties = [];
    $cumul = [];
    $total = 0;

    foreach ($points as $point) {
        $e = (int) (clone $this->encaissementsQ)->whereBetween('date', [$point['debut'], $point['fin']])->sum('montant');
        $so = (int) (clone $this->chargesQ)->whereBetween('date', [$point['debut'], $point['fin']])->sum('montant');
        $total += $e - $so;

        $labels[] = $point['label'];
        $entrees[] = $e;
        $sorties[] = $so;
        $cumul[] = $total;
    }

    return [
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Entrées', 'data' => $entrees, 'color' => '#0E9F6E'],
            ['label' => 'Sorties', 'data' => $sorties, 'color' => '#C8102E'],
            ['label' => 'Trésorerie nette cumulée', 'data' => $cumul, 'color' => '#2563EB', 'type' => 'line'],
        ],
    ];
});

$detailEncaissements = computed(fn () => (clone $this->encaissementsQ)->with('site')->latest('date')->latest('id')->get());
$detailDecaissements = computed(fn () => (clone $this->chargesQ)->with('site')->latest('date')->latest('id')->get());

?>

<div>
    <x-titre-ecran titre="Trésorerie"
        sous-titre="Ce qui est entré, ce qui est sorti, et ce qu'il reste en caisse." />

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="Encaissements — {{ $this->libellePerimetre }}" :value="ae($this->kpis['encaisse'])" couleur="#0E9F6E"
            :mecanique="$ventile ? ae($this->kpis['encaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['encaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['encaisseVentile']['nonVentile'] ? ae($this->kpis['encaisseVentile']['nonVentile']) : null" />
        <x-kpi-card label="Décaissements — {{ $this->libellePerimetre }}" :value="ae($this->kpis['decaisse'])" couleur="#C8102E"
            :mecanique="$ventile ? ae($this->kpis['decaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['decaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['decaisseVentile']['nonVentile'] ? ae($this->kpis['decaisseVentile']['nonVentile']) : null" />
        <x-kpi-card label="Trésorerie nette — {{ $this->libellePerimetre }}" :value="ae($this->kpis['net'])" :accent="$this->kpis['net'] < 0" :couleur="$this->kpis['net'] >= 0 ? '#0E9F6E' : '#C8102E'"
            :mecanique="$ventile ? ae($this->kpis['netVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['netVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['netVentile']['nonVentile'] ? ae($this->kpis['netVentile']['nonVentile']) : null" />
        <x-kpi-card label="Facturé non encaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['nonEncaisse'])" sub="Créances clients"
            :mecanique="$ventile ? ae($this->kpis['nonEncaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['nonEncaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['nonEncaisseVentile']['nonVentile'] ? ae($this->kpis['nonEncaisseVentile']['nonVentile']) : null" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="Entrées et sorties" id="treso-hebdo"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Encaissements ({{ $this->detailEncaissements->count() }})</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type d'encaissement</th>
                            <th>Moyens</th>
                            <th>Montant</th>
                            <th>Clients</th>
                            <th>Autres tiers</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->detailEncaissements->forPage($pageEncaissements, 10) as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $ligne->date->format('d/m/Y') }}</td>
                                <td>{{ $ligne->type }}</td>
                                <td>{{ $ligne->moyen }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; color:#0E9F6E;">{{ ae($ligne->montant) }}</td>
                                <td>{{ $ligne->client ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $ligne->autres_tiers ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucun encaissement sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageEncaissements" :total="$this->detailEncaissements->count()" prop="pageEncaissements" />
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Décaissements ({{ $this->detailDecaissements->count() }})</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type d'opération</th>
                            <th>Libellé d'opération</th>
                            <th>Moyens</th>
                            <th>Montant</th>
                            <th>Tiers</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->detailDecaissements->forPage($pageDecaissements, 10) as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $ligne->date->format('d/m/Y') }}</td>
                                <td>{{ $ligne->type_operation }}</td>
                                <td>{{ $ligne->libelle }}</td>
                                <td>{{ $ligne->moyen }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; color:#C8102E;">{{ ae($ligne->montant) }}</td>
                                <td style="color:#6B6E76;">{{ $ligne->tiers ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucun décaissement sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageDecaissements" :total="$this->detailDecaissements->count()" prop="pageDecaissements" />
        </div>
    </div>
</div>

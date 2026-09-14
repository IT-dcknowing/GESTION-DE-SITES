<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
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
    'pageDetail' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m');
    $this->dateFin ??= now()->format('Y-m');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; };
/** Changer de ville rend caduc le lieu choisi dans la précédente. */
$updatedVilleFiltre = function () { $this->siteFiltre = ''; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

/**
 * Une charge n'est ventilée par activité que si celui qui l'a saisie l'a précisée :
 * beaucoup d'entre elles (loyer, salaires, fonctionnement) concernent réellement le
 * lieu entier. Isoler une activité les écarte donc volontairement du total — ce sont
 * les seules dont on puisse affirmer qu'elles lui appartiennent.
 */
$requeteBase = computed(function () {
    [$debut, $fin] = $this->plage;

    return Charge::query()->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$requeteCa = computed(function () {
    [$debut, $fin] = $this->plage;

    return Facture::whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$caPeriode = computed(fn () => (int) (clone $this->requeteCa)->sum('montant'));

$kpis = computed(function () {
    $lignes = (clone $this->requeteBase)->where('type_operation', 'Charges')->get();
    $total = (int) $lignes->sum('montant');

    // Les lignes sont déjà en mémoire : on ventile ici plutôt que de relancer des
    // requêtes d'agrégat pour des chiffres qu'on a sous la main.
    $ventileTotal = VentilationActivite::repartirCollection($lignes);

    return [
        'total' => $total,
        'totalVentile' => $ventileTotal,
        'pieces' => (int) $lignes->where('libelle', 'Achats pièces')->sum('montant'),
        'piecesVentile' => VentilationActivite::repartirCollection($lignes->where('libelle', 'Achats pièces')),
        'salaires' => (int) $lignes->where('libelle', 'Salaires & personnel')->sum('montant'),
        'salairesVentile' => VentilationActivite::repartirCollection($lignes->where('libelle', 'Salaires & personnel')),
        'resultat' => $this->caPeriode - $total,
        'resultatVentile' => VentilationActivite::difference(
            VentilationActivite::repartir($this->requeteCa),
            $ventileTotal,
        ),
    ];
});

$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);
    $natures = ['Achats pièces' => '#191B20', 'Salaires & personnel' => '#2563EB', 'Fonctionnement' => '#D97706', 'Autres décaissements' => '#9A9DA5'];

    $labels = [];
    $series = array_fill_keys(array_keys($natures), []);

    foreach ($points as $point) {
        $lignes = (clone $this->requeteBase)->where('type_operation', 'Charges')->whereBetween('date', [$point['debut'], $point['fin']])->get();
        $labels[] = $point['label'];
        foreach ($natures as $nature => $couleur) {
            $series[$nature][] = (int) $lignes->where('libelle', $nature)->sum('montant');
        }
    }

    return [
        'labels' => $labels,
        'datasets' => collect($natures)->map(fn ($couleur, $nature) => ['label' => $nature, 'data' => $series[$nature], 'color' => $couleur])->values()->all(),
    ];
});

$detail = computed(fn () => (clone $this->requeteBase)->with('site')->latest('date')->latest('id')->get());

?>

<div>
    <x-titre-ecran titre="Charges & décaissements"
        sous-titre="Ce que l'exploitation a coûté sur la période." />

    <x-filtre-periode :periode="$periode" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="Total charges — {{ $this->libellePerimetre }}" :value="ae($this->kpis['total'])" sub="Hors transferts et décaissements DG"
            :mecanique="$ventile ? ae($this->kpis['totalVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['totalVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['totalVentile']['nonVentile'] ? ae($this->kpis['totalVentile']['nonVentile']) : null" />
        <x-kpi-card label="Achats pièces" :value="ae($this->kpis['pieces'])"
            :mecanique="$ventile ? ae($this->kpis['piecesVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['piecesVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['piecesVentile']['nonVentile'] ? ae($this->kpis['piecesVentile']['nonVentile']) : null" />
        <x-kpi-card label="Salaires & personnel" :value="ae($this->kpis['salaires'])"
            :mecanique="$ventile ? ae($this->kpis['salairesVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['salairesVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['salairesVentile']['nonVentile'] ? ae($this->kpis['salairesVentile']['nonVentile']) : null" />
        <x-kpi-card label="Résultat net — {{ $this->libellePerimetre }}" :value="ae($this->kpis['resultat'])" :couleur="$this->kpis['resultat'] >= 0 ? '#0E9F6E' : '#C8102E'" sub="CA facturé − charges"
            :mecanique="$ventile ? ae($this->kpis['resultatVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['resultatVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['resultatVentile']['nonVentile'] ? ae($this->kpis['resultatVentile']['nonVentile']) : null" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="Charges par nature" id="charges-hebdo"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Détail des opérations ({{ $this->detail->count() }})</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type d'opération</th>
                        <th>Libellé d'opération</th>
                        <th>Moyens</th>
                        <th>Tiers</th>
                        @if (count($this->idsSites) > 1)
                            <th>Site</th>
                        @endif
                        <th>Montant</th>
                        <th>Observations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail->forPage($pageDetail, 10) as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>{{ $ligne->date->format('d/m/Y') }}</td>
                            <td>{{ $ligne->type_operation }}</td>
                            <td>{{ $ligne->libelle }}</td>
                            <td>{{ $ligne->moyen }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->tiers ?? '—' }}</td>
                            @if (count($this->idsSites) > 1)
                                <td>{{ $ligne->site->nom }}</td>
                            @endif
                            <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne->montant) }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->observations ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="count($this->idsSites) > 1 ? 8 : 7" texte="Aucune charge enregistrée sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageDetail" :total="$this->detail->count()" prop="pageDetail" />
    </div>
</div>

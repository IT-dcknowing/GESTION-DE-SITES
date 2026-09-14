<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Commercial;
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
    'recherche' => '',
    'commercialFiltre' => '',
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

$requeteBase = computed(function () {
    [$debut, $fin] = $this->plage;
    $q = Facture::query()->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($r) => $r->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);

    /*
     * La recherche porte aussi sur la référence, et c'est ce qui la rend utile.
     *
     * Le numéro porte désormais le jour et le mois de l'opération : taper « 1409 »
     * ramène la journée entière, « F-1409-0104 » la pièce exacte. Sans la référence dans la
     * recherche, il fallait connaître le nom du client pour retrouver un document dont
     * on n'avait que le numéro sur un papier.
     */
    if ($this->recherche) {
        $terme = '%'.trim($this->recherche).'%';

        $q->where(function ($sous) use ($terme) {
            $sous->where('client', 'like', $terme);
            $sous->orWhere('numero', 'like', $terme);
            $sous->orWhere('n_facture', 'like', $terme);
        });
    }

    if ($this->commercialFiltre) {
        $q->where('commercial_id', $this->commercialFiltre);
    }

    return $q;
});

$commerciaux = computed(fn () => Commercial::where('est_spontane', false)
    ->whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre))->orderBy('nom')->get());

$requeteCharges = computed(function () {
    [$debut, $fin] = $this->plage;

    return Charge::where('type_operation', 'Charges')
        ->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$chargesPeriode = computed(fn () => (int) (clone $this->requeteCharges)->sum('montant'));

$kpis = computed(function () {
    $lignes = (clone $this->requeteBase)->get();
    $ca = (int) $lignes->sum('montant');

    // Une facture porte toujours son activité ; une charge, seulement si celui qui l'a
    // saisie la connaissait. Le résultat net hérite donc du « non ventilé » des charges.
    $caVentile = VentilationActivite::repartirCollection($lignes);
    $chargesVentilees = VentilationActivite::repartir($this->requeteCharges);

    return [
        'total' => $ca,
        'mecanique' => $caVentile['mecanique'],
        'sinistre' => $caVentile['sinistre'],
        'chargesVentilees' => $chargesVentilees,
        'resultat' => $ca - $this->chargesPeriode,
        'resultatVentile' => VentilationActivite::difference($caVentile, $chargesVentilees),
    ];
});

$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);

    $labels = [];
    $mecanique = [];
    $sinistre = [];
    $resultat = [];

    foreach ($points as $point) {
        $lignes = (clone $this->requeteBase)->whereBetween('date', [$point['debut'], $point['fin']])->get();
        $ca = (int) $lignes->sum('montant');
        $charges = (int) \Modules\Noyau\Exploitation\Modeles\Charge::query()
            ->where('type_operation', 'Charges')
            ->whereIn('site_id', $this->idsSites)
            ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
            ->whereBetween('date', [$point['debut'], $point['fin']])
            ->sum('montant');

        $labels[] = $point['label'];
        $mecanique[] = (int) $lignes->where('activite', 'Mécanique')->sum('montant');
        $sinistre[] = (int) $lignes->where('activite', 'Sinistre')->sum('montant');
        $resultat[] = $ca - $charges;
    }

    return [
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Mécanique', 'data' => $mecanique, 'color' => '#191B20'],
            ['label' => 'Sinistre', 'data' => $sinistre, 'color' => '#C8102E'],
            ['label' => 'Résultat net', 'data' => $resultat, 'color' => '#0E9F6E', 'type' => 'line'],
        ],
    ];
});

$detail = computed(fn () => (clone $this->requeteBase)->with(['commercial', 'site'])->latest('date')->latest('id')->get());

?>

<div>
    <x-titre-ecran titre="Chiffre d'affaires"
        sous-titre="Ce qui a été facturé sur la période, par activité et par lieu." />

    <x-filtre-periode :periode="$periode" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="CA — {{ $this->libellePerimetre }}" :value="ae($this->kpis['total'])" :sub="$this->detail->count().' facture(s)'"
            :mecanique="$ventile ? ae($this->kpis['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['sinistre']) : null" />
        <x-kpi-card label="Charges — {{ $this->libellePerimetre }}" :value="ae($this->chargesPeriode)"
            :mecanique="$ventile ? ae($this->kpis['chargesVentilees']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['chargesVentilees']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['chargesVentilees']['nonVentile'] ? ae($this->kpis['chargesVentilees']['nonVentile']) : null" />
        <x-kpi-card label="Résultat net (CA − charges)" :value="ae($this->kpis['resultat'])"
            :bon="$this->kpis['resultat'] >= 0" :accent="$this->kpis['resultat'] < 0"
            :mecanique="$ventile ? ae($this->kpis['resultatVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['resultatVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['resultatVentile']['nonVentile'] ? ae($this->kpis['resultatVentile']['nonVentile']) : null" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="CA par activité" id="ca-hebdo"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Détail des factures ({{ $this->detail->count() }})</h3>
        <div style="display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap;">
            <input type="text" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                placeholder="Client, ou référence — 1409 pour la journée…"
                style="flex:1; min-width:200px; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
            <select wire:model.live="commercialFiltre" style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
                <option value="" @selected($commercialFiltre === '')>Commercial : tous</option>
                @foreach ($this->commerciaux as $commercial)
                    <option value="{{ $commercial->id }}" @selected((string) $commercialFiltre === (string) $commercial->id)>{{ $commercial->nom }}</option>
                @endforeach
            </select>
        </div>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Date</th>
                        <th>Commercial</th>
                        <th>Clients</th>
                        <th>Type</th>
                        <th>N° de facture</th>
                        <th>Activité</th>
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
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->date->format('d/m/Y') }}</td>
                            <td>{{ $ligne->commercial?->nom ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td>{{ $ligne->type }}</td>
                            <td>{{ $ligne->n_facture }}</td>
                            <td>{{ $ligne->activite }}</td>
                            @if (count($this->idsSites) > 1)
                                <td>{{ $ligne->site->nom }}</td>
                            @endif
                            <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne->montant) }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->observations ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="count($this->idsSites) > 1 ? 10 : 9" texte="Aucune facture enregistrée sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageDetail" :total="$this->detail->count()" prop="pageDetail" />
    </div>
</div>

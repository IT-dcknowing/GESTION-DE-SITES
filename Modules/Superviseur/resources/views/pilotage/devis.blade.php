<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Exploitation\Services\StatistiquesDevis;
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
    'statutFiltre' => '',
    'dateFiltre' => '',
    'nFactureFiltre' => '',
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
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

$requeteBase = computed(function () {
    [$debut, $fin] = $this->plage;
    $q = Devis::query()->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($r) => $r->where('activite', $this->activiteFiltre))
        ->whereBetween('date_emission', [$debut, $fin]);

    /*
     * La recherche porte aussi sur la référence, et c'est ce qui la rend utile.
     *
     * Le numéro porte désormais le jour et le mois de l'opération : taper « 1409 »
     * ramène la journée entière, « D-1409-0242 » la pièce exacte. Sans la référence dans la
     * recherche, il fallait connaître le nom du client pour retrouver un document dont
     * on n'avait que le numéro sur un papier.
     */
    if ($this->recherche) {
        $terme = '%'.trim($this->recherche).'%';

        $q->where(function ($sous) use ($terme) {
            $sous->where('client', 'like', $terme);
            $sous->orWhere('numero', 'like', $terme);
        });
    }

    if ($this->idsCommercialFiltre !== null) {
        $q->whereIn('commercial_id', $this->idsCommercialFiltre);
    }

    return $q;
});

// Inclut les commerciaux "Client spontané" : un devis pour un client venu de lui-même,
// sans commercial nommé, reste un cas réel qu'on doit pouvoir filtrer.
// Un commercial travaille pour toute une ville, jamais pour un seul de ses sites.
$commerciaux = computed(fn () => Commercial::whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre))->orderBy('est_spontane')->orderBy('nom')->get());

/**
 * Résout le filtre commercial en identifiants concrets : un site a un "Client
 * spontané" distinct par activité (Mécanique et Sinistre), donc choisir "Client
 * spontané" dans la liste doit filtrer sur les deux à la fois, pas sur un seul.
 */
$idsCommercialFiltre = computed(function () {
    if ($this->commercialFiltre === 'spontane') {
        return $this->commerciaux->where('est_spontane', true)->pluck('id')->all();
    }

    return $this->commercialFiltre ? [(int) $this->commercialFiltre] : null;
});

/** Répartition des devis par commercial sur la période retenue : nombre émis, validés et montant validé. */
$parCommercial = computed(function () {
    $lignes = (clone $this->requeteBase)->get();

    return $this->commerciaux->map(function ($commercial) use ($lignes) {
        $siens = $lignes->where('commercial_id', $commercial->id);
        $valides = $siens->where('statut', 'Validé');

        return [
            'commercial' => $commercial,
            'emis' => $siens->count(),
            'valides' => $valides->count(),
            'montantValide' => (int) $valides->sum('montant_valide'),
            'taux' => $siens->count() > 0 ? $valides->count() / $siens->count() : null,
        ];
    })->filter(fn ($l) => $l['emis'] > 0)->sortByDesc('montantValide')->values();
});

$kpis = computed(function () {
    $lignes = (clone $this->requeteBase)->get();
    $emis = $lignes->count();
    $valides = $lignes->where('statut', 'Validé');
    $refuses = $lignes->where('statut', 'Refusé')->count();
    $attente = $lignes->where('statut', 'En attente')->count();

    return [
        'emis' => $emis,
        'montantEmis' => $lignes->sum('montant_devis'),
        'montantEmisMecanique' => (int) $lignes->where('activite', 'Mécanique')->sum('montant_devis'),
        'montantEmisSinistre' => (int) $lignes->where('activite', 'Sinistre')->sum('montant_devis'),
        'valides' => $valides->count(),
        'montantValide' => $valides->sum('montant_valide'),
        'montantValideMecanique' => (int) $valides->where('activite', 'Mécanique')->sum('montant_valide'),
        'montantValideSinistre' => (int) $valides->where('activite', 'Sinistre')->sum('montant_valide'),
        'refuses' => $refuses,
        'refusesMecanique' => $lignes->where('statut', 'Refusé')->where('activite', 'Mécanique')->count(),
        'refusesSinistre' => $lignes->where('statut', 'Refusé')->where('activite', 'Sinistre')->count(),
        'attente' => $attente,
        'attenteMecanique' => $lignes->where('statut', 'En attente')->where('activite', 'Mécanique')->count(),
        'attenteSinistre' => $lignes->where('statut', 'En attente')->where('activite', 'Sinistre')->count(),
        'tauxTransfo' => $emis > 0 ? $valides->count() / $emis : null,
        'tauxTransfoMecanique' => StatistiquesDevis::tauxTransformation($lignes, 'Mécanique'),
        'tauxTransfoSinistre' => StatistiquesDevis::tauxTransformation($lignes, 'Sinistre'),
        // Écart moyen entre le montant proposé et le montant réellement validé.
        'differenciation' => $valides->count() > 0
            ? (int) round($valides->sum('montant_devis') - $valides->sum('montant_valide')) / $valides->count()
            : null,
        'differenciationMecanique' => StatistiquesDevis::differenciationMoyenne($valides, 'Mécanique'),
        'differenciationSinistre' => StatistiquesDevis::differenciationMoyenne($valides, 'Sinistre'),
        // Délai moyen, en jours, entre la réception du véhicule et l'émission du devis.
        'delaiEnvoi' => StatistiquesDevis::delaiMoyenEnvoi($lignes),
        'delaiEnvoiMecanique' => StatistiquesDevis::delaiMoyenEnvoi($lignes->where('activite', 'Mécanique')),
        'delaiEnvoiSinistre' => StatistiquesDevis::delaiMoyenEnvoi($lignes->where('activite', 'Sinistre')),
    ];
});

/** Taux de transformation (validés ÷ émis) restreint à une activité. */
$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);

    $labels = [];
    $emis = [];
    $valides = [];
    $taux = [];

    foreach ($points as $point) {
        $lignes = (clone $this->requeteBase)->whereBetween('date_emission', [$point['debut'], $point['fin']])->get();
        $nbEmis = $lignes->count();
        $nbValides = $lignes->where('statut', 'Validé')->count();

        $labels[] = $point['label'];
        $emis[] = $nbEmis;
        $valides[] = $nbValides;
        $taux[] = $nbEmis > 0 ? round($nbValides / $nbEmis * 100, 1) : 0;
    }

    return [
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Émis', 'data' => $emis, 'color' => '#191B20'],
            ['label' => 'Validés', 'data' => $valides, 'color' => '#0E9F6E'],
            ['label' => 'Taux transfo (%)', 'data' => $taux, 'color' => '#D97706', 'type' => 'line', 'axe' => 'y1'],
        ],
    ];
});

$detail = computed(function () {
    $q = (clone $this->requeteBase)->with(['commercial', 'site', 'facture'])->latest('date_emission')->latest('id');

    if ($this->statutFiltre) {
        $q->where('statut', $this->statutFiltre);
    }

    if ($this->dateFiltre) {
        $q->whereDate('date_emission', $this->dateFiltre);
    }

    if ($this->nFactureFiltre) {
        $q->whereHas('facture', fn ($f) => $f->where('n_facture', 'like', '%'.$this->nFactureFiltre.'%'));
    }

    return $q->get();
});

?>

<div>
    <x-titre-ecran titre="Devis & proformas"
        sous-titre="Ce qui a été chiffré, et ce qui est devenu une facture." />

    <x-filtre-periode :periode="$periode" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        :commerciaux="$this->commerciaux" :commercial-filtre="$commercialFiltre" />

    <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Devis émis — {{ $this->libellePerimetre }}" :value="$this->kpis['emis']" :sub="ae($this->kpis['montantEmis'])"
            :mecanique="$activiteFiltre ? null : ae($this->kpis['montantEmisMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['montantEmisSinistre'])" />
        <x-kpi-card label="Validés — {{ $this->libellePerimetre }}" :value="$this->kpis['valides']" :sub="ae($this->kpis['montantValide'])" couleur="#0E9F6E"
            :mecanique="$activiteFiltre ? null : ae($this->kpis['montantValideMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['montantValideSinistre'])" />
        <x-kpi-card label="Refusés — {{ $this->libellePerimetre }}" :value="$this->kpis['refuses']" couleur="#C8102E"
            :mecanique="$activiteFiltre ? null : $this->kpis['refusesMecanique']" :sinistre="$activiteFiltre ? null : $this->kpis['refusesSinistre']" />
        <x-kpi-card label="En attente — {{ $this->libellePerimetre }}" :value="$this->kpis['attente']" :accent="$this->kpis['attente'] > 0"
            :mecanique="$activiteFiltre ? null : $this->kpis['attenteMecanique']" :sinistre="$activiteFiltre ? null : $this->kpis['attenteSinistre']" />
        <x-kpi-card label="Taux transfo (nb) — {{ $this->libellePerimetre }}" :value="an($this->kpis['tauxTransfo'])"
            :mecanique="$activiteFiltre ? null : an($this->kpis['tauxTransfoMecanique'])" :sinistre="$activiteFiltre ? null : an($this->kpis['tauxTransfoSinistre'])" />
        <x-kpi-card label="Différenciation moyenne — {{ $this->libellePerimetre }}" :value="ae($this->kpis['differenciation'] !== null ? (int) $this->kpis['differenciation'] : null)"
            sub="Écart moyen devis → validé"
            :mecanique="$activiteFiltre ? null : ae($this->kpis['differenciationMecanique'] !== null ? (int) $this->kpis['differenciationMecanique'] : null)"
            :sinistre="$activiteFiltre ? null : ae($this->kpis['differenciationSinistre'] !== null ? (int) $this->kpis['differenciationSinistre'] : null)" />
        <x-kpi-card label="Délai moyen d'envoi — {{ $this->libellePerimetre }}"
            :value="$this->kpis['delaiEnvoi'] !== null ? $this->kpis['delaiEnvoi'].' j' : '—'"
            sub="Réception → émission"
            :mecanique="$activiteFiltre ? null : ($this->kpis['delaiEnvoiMecanique'] !== null ? $this->kpis['delaiEnvoiMecanique'].' j' : '—')"
            :sinistre="$activiteFiltre ? null : ($this->kpis['delaiEnvoiSinistre'] !== null ? $this->kpis['delaiEnvoiSinistre'].' j' : '—')" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="Devis émis vs validés" id="devis-hebdo"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    <div class="carte" style="margin-bottom:20px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Répartition par commercial</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Commercial</th>
                        <th>Devis émis</th>
                        <th>Devis validés</th>
                        <th>Montant validé</th>
                        <th>Taux de transformation</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->parCommercial as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="font-weight:700;">{{ $ligne['commercial']->nom }}</td>
                            <td>{{ $ligne['emis'] }}</td>
                            <td>{{ $ligne['valides'] }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['montantValide']) }}</td>
                            <td style="font-weight:700; color:{{ ($ligne['taux'] ?? 0) >= 0.5 ? '#0E9F6E' : '#D97706' }};">{{ an($ligne['taux']) }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="5" texte="Aucun devis enregistré sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Détail des opérations ({{ $this->detail->count() }})</h3>
        <div style="display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap;">
            <input type="text" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                placeholder="Client, ou référence — 1409 pour la journée…"
                style="flex:1; min-width:200px; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
            <select wire:model.live="statutFiltre" style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
                <option value="" @selected($statutFiltre === '')>Statut : tous</option>
                <option value="En attente" @selected((string) $statutFiltre === 'En attente')>En attente</option>
                <option value="Validé" @selected((string) $statutFiltre === 'Validé')>Validé</option>
                <option value="Refusé" @selected((string) $statutFiltre === 'Refusé')>Refusé</option>
            </select>
            <input type="date" wire:model.live="dateFiltre" value="{{ $dateFiltre }}"
                style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
            <input type="text" wire:model.live.debounce.400ms="nFactureFiltre" value="{{ $nFactureFiltre }}" placeholder="N° de facture…"
                style="width:160px; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
        </div>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Date de réception</th>
                        <th>N° fiche de réception</th>
                        <th>Client</th>
                        <th>Date d'émission</th>
                        <th>Délai moyen</th>
                        <th>Commercial</th>
                        @if (count($this->idsSites) > 1)
                            <th>Site</th>
                        @endif
                        <th>Statut du devis</th>
                        <th>Montant du devis</th>
                        <th>Montant validé</th>
                        <th>Montant restant</th>
                        <th>Activité</th>
                        <th>Observations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail->forPage($pageDetail, 10) as $ligne)
                        @php
                            $montantFacture = $ligne->facture?->montant ?? 0;
                            $montantRestant = $ligne->montant_valide !== null ? $ligne->montant_valide - $montantFacture : null;
                            $couleurRestant = $montantRestant === null ? 'inherit' : ($montantRestant > $montantFacture ? '#C8102E' : '#0E9F6E');
                            $delaiHeures = $ligne->date_reception ? $ligne->date_reception->diffInHours($ligne->date_emission) : null;
                            $delaiDepasse = $delaiHeures !== null && $delaiHeures > 24;
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->date_reception?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_fiche_reception ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td>{{ $ligne->date_emission->format('d/m/Y') }}</td>
                            <td style="font-weight:700; color:{{ $delaiHeures === null ? 'inherit' : ($delaiDepasse ? '#C8102E' : '#0E9F6E') }};">
                                @if ($delaiHeures === null)
                                    —
                                @else
                                    {{ $delaiHeures }} h
                                    @if ($delaiDepasse)
                                        <span title="Délai supérieur à 24h" style="margin-left:3px;">⚠</span>
                                    @endif
                                @endif
                            </td>
                            <td>{{ $ligne->commercial?->nom ?? '—' }}</td>
                            @if (count($this->idsSites) > 1)
                                <td>{{ $ligne->site->nom }}</td>
                            @endif
                            <td>
                                <span style="font-weight:700; color:{{ ['En attente' => '#D97706', 'Validé' => '#0E9F6E', 'Refusé' => '#C8102E'][$ligne->statut] }};">{{ $ligne->statut }}</span>
                            </td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne->montant_devis) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne->montant_valide) }}</td>
                            <td style="font-variant-numeric:tabular-nums; font-weight:700; color:{{ $couleurRestant }};">{{ $montantRestant !== null ? ae($montantRestant) : '—' }}</td>
                            <td>{{ $ligne->activite }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->observations ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="count($this->idsSites) > 1 ? 14 : 13" texte="Aucun devis enregistré sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageDetail" :total="$this->detail->count()" prop="pageDetail" />
    </div>
</div>

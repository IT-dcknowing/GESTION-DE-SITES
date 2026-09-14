<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
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
    'pageAttente' => 1,
    'pageOperations' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m');
    $this->dateFin ??= now()->format('Y-m');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
// La comptabilité du caissier est toujours consolidée à l'échelle de la ville (ou du
// site s'il n'en a qu'un) : contrairement aux autres interfaces, aucune précision
// Mécanique/Sinistre n'est proposée ici.
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

// Les factures reprises du logiciel d'atelier sont écartées : leur historique de
// règlement n'a pas été importé, elles paraîtraient donc toutes intégralement dues.
$facturesEnAttente = computed(fn () => Facture::whereIn('site_id', $this->idsSites)
    ->saisieManuelle()
    ->avecResteAEncaisser()
    ->withSum('encaissements', 'montant')
    ->latest('date')->latest('id')->get());

$kpis = computed(function () {
    [$debut, $fin] = $this->plage;
    $jour = now()->toDateString();

    $encaisseJour = (int) Encaissement::whereIn('site_id', $this->idsSites)->whereDate('date', $jour)->sum('montant');
    $encaissePeriode = (int) Encaissement::whereIn('site_id', $this->idsSites)->whereBetween('date', [$debut, $fin])->sum('montant');
    $decaisseJour = (int) Charge::whereIn('site_id', $this->idsSites)->whereDate('date', $jour)->sum('montant');
    $decaissePeriode = (int) Charge::whereIn('site_id', $this->idsSites)->whereBetween('date', [$debut, $fin])->sum('montant');

    return [
        'encaisseJour' => $encaisseJour,
        'decaisseJour' => $decaisseJour,
        'soldeJour' => $encaisseJour - $decaisseJour,
        'encaissePeriode' => $encaissePeriode,
        'decaissePeriode' => $decaissePeriode,
        'soldePeriode' => $encaissePeriode - $decaissePeriode,
        'resteAEncaisser' => $this->facturesEnAttente->sum(fn ($f) => $f->resteAEncaisser()),
    ];
});

$dernieresOperations = computed(function () {
    $encaissements = Encaissement::whereIn('site_id', $this->idsSites)->where('cree_par', auth()->id())
        ->latest('date')->latest('id')->get()
        ->map(fn ($e) => ['date' => $e->date, 'type' => $e->type, 'libelle' => $e->client ?? $e->autres_tiers ?? '—', 'montant' => $e->montant, 'sens' => 1]);

    $charges = Charge::whereIn('site_id', $this->idsSites)->where('cree_par', auth()->id())
        ->latest('date')->latest('id')->get()
        ->map(fn ($c) => ['date' => $c->date, 'type' => $c->type_operation, 'libelle' => $c->libelle, 'montant' => $c->montant, 'sens' => -1]);

    return $encaissements->concat($charges)->sortByDesc(fn ($l) => $l['date'])->values();
});

?>

<div>
    <x-titre-ecran titre="Comptabilité — tableau de bord"
        sous-titre="Ce qui reste à encaisser, et ce qui est sorti de la caisse." />

    <x-filtre-periode :periode="$periode" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :masquer-activite="true"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(165px, 1fr)); gap:10px; margin-bottom:20px;">
        <x-kpi-card label="Encaissé aujourd'hui" :value="ae($this->kpis['encaisseJour'])" couleur="#0E9F6E" />
        <x-kpi-card label="Décaissé aujourd'hui" :value="ae($this->kpis['decaisseJour'])" couleur="#C8102E" />
        <x-kpi-card label="Solde de caisse — jour" :value="ae($this->kpis['soldeJour'])"
            :couleur="$this->kpis['soldeJour'] >= 0 ? '#0E9F6E' : '#C8102E'" />
        <x-kpi-card label="Encaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['encaissePeriode'])" />
        <x-kpi-card label="Décaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['decaissePeriode'])" />
        <x-kpi-card label="Solde de caisse — période" :value="ae($this->kpis['soldePeriode'])"
            :couleur="$this->kpis['soldePeriode'] >= 0 ? '#0E9F6E' : '#C8102E'" />
        <x-kpi-card label="Reste à encaisser" :value="ae($this->kpis['resteAEncaisser'])" sub="Toutes factures en attente" couleur="#D97706" />
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Top factures en attente d'encaissement</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>N° facture</th><th>Client</th><th>Montant</th><th>Reste</th></tr></thead>
                    <tbody>
                        @forelse ($this->facturesEnAttente->forPage($pageAttente, 10) as $f)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $f->n_facture }}</td>
                                <td>{{ $f->client }}</td>
                                <td style="font-variant-numeric:tabular-nums;">{{ ae($f->montant) }}</td>
                                <td style="font-weight:700; color:#D97706; font-variant-numeric:tabular-nums;">{{ ae($f->resteAEncaisser()) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune facture en attente d'encaissement." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageAttente" :total="$this->facturesEnAttente->count()" prop="pageAttente" />
            <div style="margin-top:14px;">
                <a href="{{ route('caissier.encaissements') }}" wire:navigate class="bouton bouton-sombre">Encaisser une facture →</a>
            </div>
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Mes dernières opérations</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Date</th><th>Nature</th><th>Libellé</th><th>Montant</th></tr></thead>
                    <tbody>
                        @forelse ($this->dernieresOperations->forPage($pageOperations, 10) as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ \Illuminate\Support\Carbon::parse($ligne['date'])->format('d/m/Y') }}</td>
                                <td>{{ $ligne['type'] }}</td>
                                <td>{{ $ligne['libelle'] }}</td>
                                <td style="font-weight:700; font-variant-numeric:tabular-nums; color:{{ $ligne['sens'] > 0 ? '#0E9F6E' : '#C8102E' }};">
                                    {{ $ligne['sens'] > 0 ? '+' : '−' }}{{ ae($ligne['montant']) }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune opération récente." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageOperations" :total="$this->dernieresOperations->count()" prop="pageOperations" />
        </div>
    </div>
</div>

<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Site;
use function Livewire\Volt\{state, computed, mount};

state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'activiteFiltre' => '',
    'pageFactures' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; };

$commercial = computed(fn () => Commercial::where('user_id', auth()->id())->with('ville')->first());

$villeUnique = computed(fn () => $this->commercial?->ville);

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));

$factures = computed(function () {
    if (! $this->commercial) {
        return collect();
    }
    [$debut, $fin] = $this->plage;

    $q = Facture::with('site')->withSum('encaissements', 'montant')
        ->where('commercial_id', $this->commercial->id)->whereBetween('date', [$debut, $fin]);

    if ($this->activiteFiltre) {
        $q->where('activite', $this->activiteFiltre);
    }

    return $q->latest('date')->latest('id')->get();
});

$kpis = computed(function () {
    if (! $this->commercial) {
        return null;
    }
    [$debut, $fin] = $this->plage;
    $realisation = montant_de($this->factures->sum('montant'));
    $realisationMecanique = montant_de($this->factures->where('activite', 'Mécanique')->sum('montant'));
    $realisationSinistre = montant_de($this->factures->where('activite', 'Sinistre')->sum('montant'));
    $encaisse = montant_de($this->factures->sum('encaissements_sum_montant'));
    $objectifMecanique = montant_de(PeriodeCalculateur::objectifProrata((float) $this->commercial->objectif_mecanique, $debut, $fin));
    $objectifSinistre = montant_de(PeriodeCalculateur::objectifProrata((float) $this->commercial->objectif_sinistre, $debut, $fin));
    $objectif = match ($this->activiteFiltre) {
        'Mécanique' => $objectifMecanique,
        'Sinistre' => $objectifSinistre,
        default => montant_de(PeriodeCalculateur::objectifProrata((float) $this->commercial->objectif_mensuel, $debut, $fin)),
    };

    // Le commercial couvrant toute la ville, sa contribution se mesure au CA de la ville
    // entière (ses deux sites), pas d'un seul site.
    $idsSitesVille = Site::where('ville_id', $this->commercial->ville_id)->pluck('id');
    $caSite = montant_de(Facture::whereIn('site_id', $idsSitesVille)->whereBetween('date', [$debut, $fin])->sum('montant'));
    $caSiteMecanique = montant_de(Facture::whereIn('site_id', $idsSitesVille)->where('activite', 'Mécanique')->whereBetween('date', [$debut, $fin])->sum('montant'));
    $caSiteSinistre = montant_de(Facture::whereIn('site_id', $idsSitesVille)->where('activite', 'Sinistre')->whereBetween('date', [$debut, $fin])->sum('montant'));
    $encaisseMecanique = montant_de($this->factures->where('activite', 'Mécanique')->sum('encaissements_sum_montant'));
    $encaisseSinistre = montant_de($this->factures->where('activite', 'Sinistre')->sum('encaissements_sum_montant'));

    return [
        'objectif' => $objectif,
        'objectifMecanique' => $objectifMecanique,
        'objectifSinistre' => $objectifSinistre,
        'realisation' => $realisation,
        'realisationMecanique' => $realisationMecanique,
        'realisationSinistre' => $realisationSinistre,
        'ecart' => $realisation - $objectif,
        'ecartMecanique' => $realisationMecanique - $objectifMecanique,
        'ecartSinistre' => $realisationSinistre - $objectifSinistre,
        'taux' => $objectif > 0 ? $realisation / $objectif : null,
        'tauxMecanique' => $objectifMecanique > 0 ? $realisationMecanique / $objectifMecanique : null,
        'tauxSinistre' => $objectifSinistre > 0 ? $realisationSinistre / $objectifSinistre : null,
        'contribution' => $caSite > 0 ? $realisation / $caSite : null,
        'contributionMecanique' => $caSiteMecanique > 0 ? $realisationMecanique / $caSiteMecanique : null,
        'contributionSinistre' => $caSiteSinistre > 0 ? $realisationSinistre / $caSiteSinistre : null,
        'encaisse' => $encaisse,
        'encaisseMecanique' => $encaisseMecanique,
        'encaisseSinistre' => $encaisseSinistre,
        'tauxRecouvrement' => $realisation > 0 ? $encaisse / $realisation : null,
        'tauxRecouvrementMecanique' => $realisationMecanique > 0 ? $encaisseMecanique / $realisationMecanique : null,
        'tauxRecouvrementSinistre' => $realisationSinistre > 0 ? $encaisseSinistre / $realisationSinistre : null,
    ];
});

/**
 * Les objectifs de l'entreprise qui concernent cet accès — 15/10 : coché à la création de l'accès,
 * ou au rattrapage du Super admin. Le mois en cours, la portée de chaque objectif.
 */
$objectifsDeLEntreprise = computed(fn () => \Modules\Noyau\Exploitation\Services\AccesAuxObjectifs::disponible()
    ? \Modules\Noyau\Exploitation\Services\AccesAuxObjectifs::suiviDuMois(auth()->user()) : []);

?>

<div>
    @if ($this->objectifsDeLEntreprise !== [])
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 8px;">Les objectifs de l'entreprise qui vous concernent — {{ ucfirst(now()->locale('fr')->translatedFormat('F Y')) }}</h3>
            @foreach ($this->objectifsDeLEntreprise as $suivi)
                <div style="margin-bottom:10px;">
                    <div style="font-size:13px; color:#4B4E55; margin-bottom:6px;"><b>{{ $suivi['objectif']->libelle }}</b> — {{ $suivi['objectif']->portee() }} · réalisé depuis le 1er : <b>{{ ae($suivi['realise']) }}</b></div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:8px;">
                        @foreach ($suivi['tranches'] as $t)
                            @php $atteinte = $suivi['realise'] >= $t['montant']; $taux = $t['montant'] > 0 ? $suivi['realise'] / $t['montant'] : null; @endphp
                            <div style="border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:8px 10px; {{ $atteinte ? 'background:#ECFDF5;' : '' }}">
                                <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; color:#4B4E55;">{{ $t['libelle'] }}</div>
                                <div style="font-weight:800; font-variant-numeric:tabular-nums;">{{ ae($t['montant']) }}</div>
                                <div style="font-size:12px; color:{{ $atteinte ? '#0E9F6E' : '#6B6E76' }};">{{ an($taux) }} — {{ $atteinte ? 'atteinte ✔' : 'reste '.ae(montant_de($t['montant'] - $suivi['realise'])) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! $this->commercial)
        <x-a-venir titre="Aucune fiche commerciale associée" description="Contactez votre responsable de site." />
    @else
        <div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:22px; margin-bottom:20px;">
            <h1 style="font-size:22px; font-weight:800; margin:0 0 4px;">{{ $this->commercial->nom }}</h1>
            <p style="color:#6B6E76; font-size:14.5px; margin:0;">{{ $this->commercial->ville->nom }}</p>
        </div>

        <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :ville-unique="$this->villeUnique" :activite-filtre="$activiteFiltre"
            :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(165px, 1fr)); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Objectif mensuel" :value="ae($this->commercial->objectif_mensuel)"
                :mecanique="$activiteFiltre ? null : ae($this->commercial->objectif_mecanique)" :sinistre="$activiteFiltre ? null : ae($this->commercial->objectif_sinistre)" />
            <x-kpi-card label="Objectif de la période" :value="ae($this->kpis['objectif'])"
                :mecanique="$activiteFiltre ? null : ae($this->kpis['objectifMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['objectifSinistre'])" />
            <x-kpi-card label="Réalisation" :value="ae($this->kpis['realisation'])"
                :mecanique="$activiteFiltre ? null : ae($this->kpis['realisationMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['realisationSinistre'])" />
            <x-kpi-card label="Écart" :value="ae($this->kpis['ecart'])"
                :bon="$this->kpis['ecart'] >= 0" :accent="$this->kpis['ecart'] < 0"
                :mecanique="$activiteFiltre ? null : ae($this->kpis['ecartMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['ecartSinistre'])" />
            <x-kpi-card label="Taux de Réalisation" :value="an($this->kpis['taux'])"
                :bon="($this->kpis['taux'] ?? 0) >= 1" :accent="($this->kpis['taux'] ?? 0) < 1"
                :mecanique="$activiteFiltre ? null : an($this->kpis['tauxMecanique'])" :sinistre="$activiteFiltre ? null : an($this->kpis['tauxSinistre'])" />
            <x-kpi-card label="Montant encaissé" :value="ae($this->kpis['encaisse'])" couleur="#0E9F6E"
                :mecanique="$activiteFiltre ? null : ae($this->kpis['encaisseMecanique'])" :sinistre="$activiteFiltre ? null : ae($this->kpis['encaisseSinistre'])" />
            <x-kpi-card label="Taux de recouvrement" :value="an($this->kpis['tauxRecouvrement'])"
                sub="Encaissé ÷ facturé" :bon="($this->kpis['tauxRecouvrement'] ?? 0) >= 1"
                :mecanique="$activiteFiltre ? null : an($this->kpis['tauxRecouvrementMecanique'])" :sinistre="$activiteFiltre ? null : an($this->kpis['tauxRecouvrementSinistre'])" />
            <x-kpi-card label="Contribution au CA de la ville" :value="an($this->kpis['contribution'])"
                :mecanique="$activiteFiltre ? null : an($this->kpis['contributionMecanique'])" :sinistre="$activiteFiltre ? null : an($this->kpis['contributionSinistre'])" />
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Détail des facturations ({{ $this->factures->count() }})</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Date</th>
                            <th>Activité</th>
                            <th>Site</th>
                            <th>Clients</th>
                            <th>Type</th>
                            <th>N° de facture</th>
                            <th>Montant facturé</th>
                            <th>Montant encaissé</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures->forPage($pageFactures, 10) as $facture)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><x-numero-ligne :ligne="$facture" /></td>
                                <td>{{ $facture->date->format('d/m/Y') }}</td>
                                <td>{{ $facture->activite }}</td>
                                <td>{{ $facture->site->nom }}</td>
                                <td>{{ $facture->client }}</td>
                                <td>{{ $facture->type }}</td>
                                <td>{{ $facture->n_facture }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($facture->montant) }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; color:#0E9F6E;">{{ ae($facture->encaissements_sum_montant ?? 0) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="9" texte="Aucune facture sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageFactures" :total="$this->factures->count()" prop="pageFactures" />
        </div>
    @endif
</div>

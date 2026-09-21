<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\VentilationActivite;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\LotImport;
use function Livewire\Volt\{state, computed, mount, protect};

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

    /*
     * La ligne dont on a demandé le détail, de chaque côté. Une seule à la fois : le
     * détail s'ouvre sous la ligne, et deux volets ouverts feraient perdre celle qu'on
     * regardait. L'identifiant vient du navigateur, il n'est donc jamais cru sur parole —
     * la ligne est relue dans la liste déjà filtrée par le périmètre du compte.
     */
    'detailEncaissement' => null,
    'detailDecaissement' => null,
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

/*
 * Les deux listes portent de quoi montrer la pièce d'origine : son atelier, sa facture
 * (pour un encaissement) et le lot d'import dont elle vient. Préchargés ici, sans quoi
 * ouvrir un détail déclencherait trois requêtes de plus par ligne affichée.
 */
$detailEncaissements = computed(fn () => (clone $this->encaissementsQ)
    ->with(['site', 'facture:id,numero,n_facture,client,immatriculation', 'lot:id,nom_fichier,format,created_at'])
    ->latest('date')->latest('id')->get());

$detailDecaissements = computed(fn () => (clone $this->chargesQ)
    ->with(['site', 'lot:id,nom_fichier,format,created_at'])
    ->latest('date')->latest('id')->get());

/** Ouvre le détail d'une ligne, et referme l'autre : on ne lit pas deux pièces à la fois. */
$voirEncaissement = function (int $id) {
    $this->detailEncaissement = $this->detailEncaissement === $id ? null : $id;
};

$voirDecaissement = function (int $id) {
    $this->detailDecaissement = $this->detailDecaissement === $id ? null : $id;
};

/**
 * Ce que « Autres » recouvre, poste par poste.
 *
 * **Pourquoi ce bloc existe.** « Autres » est une valeur du référentiel, côté type
 * d'encaissement comme côté libellé de charge. C'est commode à la saisie et muet à la
 * lecture : on voit une somme, on ne sait pas ce qu'elle contient, et c'est justement
 * celle dont on voudrait le détail. La réponse était déjà en base — chaque ligne porte son
 * tiers et sa référence —, elle n'était simplement affichée nulle part.
 *
 * Les postes sont rangés du plus lourd au plus léger : c'est l'ordre dans lequel on les
 * regarde, et les trois premiers suffisent presque toujours à comprendre.
 *
 * @return array{encaissements: array<int, array{poste: string, montant: int, lignes: int}>,
 *               decaissements: array<int, array{poste: string, montant: int, lignes: int}>,
 *               totalEncaisse: int, totalDecaisse: int}
 */
$autres = computed(function () {
    $ranger = function ($lignes, callable $poste) {
        $postes = [];

        foreach ($lignes as $ligne) {
            $cle = trim((string) $poste($ligne)) ?: 'Sans précision';
            $postes[$cle] ??= ['poste' => $cle, 'montant' => 0, 'lignes' => 0];
            $postes[$cle]['montant'] += (int) $ligne->montant;
            $postes[$cle]['lignes']++;
        }

        usort($postes, fn ($a, $b) => $b['montant'] <=> $a['montant']);

        return $postes;
    };

    // Côté recettes, « Autres » est un type d'encaissement ; le poste réel est le tiers
    // qui a versé, à défaut le moyen employé.
    $encaissements = $this->detailEncaissements->where('type', 'Autres');

    // Côté dépenses, le libellé est déjà le poste : on ne retient que celui qui ne dit
    // rien — « Autres décaissements » — et l'on regarde alors le tiers payé.
    $decaissements = $this->detailDecaissements->filter(
        fn ($c) => str_contains(mb_strtolower((string) $c->libelle), 'autre')
    );

    return [
        'encaissements' => $ranger($encaissements, fn ($e) => $e->autres_tiers ?: ($e->client ?: $e->moyen)),
        'decaissements' => $ranger($decaissements, fn ($c) => $c->tiers ?: ($c->observations ?: $c->moyen)),
        'totalEncaisse' => (int) $encaissements->sum('montant'),
        'totalDecaisse' => (int) $decaissements->sum('montant'),
    ];
});

/**
 * La pièce d'origine d'une ligne, en une phrase : saisie ici, ou reprise d'un fichier.
 *
 * `protect()` et non une simple fonction : dans un composant Volt, une closure posée en
 * tête devient une action appelable depuis le navigateur. Celle-ci n'a rien à y faire.
 */
$origineDe = protect(function ($ligne) {
    if ($ligne->lot_import_id === null) {
        return 'Saisie dans l\'application'
            .($ligne->code_auteur ? ' · code '.$ligne->code_auteur : '')
            .($ligne->created_at ? ' le '.$ligne->created_at->format('d/m/Y à H:i') : '');
    }

    $lot = $ligne->lot;

    return 'Reprise du fichier '.($lot?->nom_fichier ?: 'importé')
        .($lot?->format ? ' ('.$lot->format.')' : '')
        .($lot?->created_at ? ' déposé le '.$lot->created_at->format('d/m/Y') : '');
});

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

    @if ($this->autres['totalEncaisse'] > 0 || $this->autres['totalDecaisse'] > 0)
        {{-- « Autres » ne dit rien par construction. Ce bloc dit ce qu'il contient, du plus
             lourd au plus léger — et ne s'affiche pas quand il n'y a rien dedans : un
             indicateur qui répète « zéro » cesse d'être lu. --}}
        <div class="carte" style="margin-bottom:20px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Ce que « Autres » recouvre</h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                Le détail des lignes rangées sous « Autres », par tiers et par poste, sur la période
                et le périmètre regardés.
            </p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <div>
                    <h4 style="font-size:13px; font-weight:700; margin:0 0 8px; color:#0E9F6E;">
                        Encaissements — {{ ae($this->autres['totalEncaisse']) }}
                    </h4>
                    @forelse ($this->autres['encaissements'] as $poste)
                        <div style="display:flex; justify-content:space-between; gap:12px; padding:5px 0;
                                    border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:13px;">
                            <span>{{ $poste['poste'] }}
                                <span style="color:#6B6E76; font-size:11px;">· {{ $poste['lignes'] }} ligne(s)</span>
                            </span>
                            <span style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($poste['montant']) }}</span>
                        </div>
                    @empty
                        <p style="margin:0; font-size:13px; color:#6B6E76;">Rien sous « Autres » sur cette période.</p>
                    @endforelse
                </div>

                <div>
                    <h4 style="font-size:13px; font-weight:700; margin:0 0 8px; color:#C8102E;">
                        Décaissements — {{ ae($this->autres['totalDecaisse']) }}
                    </h4>
                    @forelse ($this->autres['decaissements'] as $poste)
                        <div style="display:flex; justify-content:space-between; gap:12px; padding:5px 0;
                                    border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:13px;">
                            <span>{{ $poste['poste'] }}
                                <span style="color:#6B6E76; font-size:11px;">· {{ $poste['lignes'] }} ligne(s)</span>
                            </span>
                            <span style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($poste['montant']) }}</span>
                        </div>
                    @empty
                        <p style="margin:0; font-size:13px; color:#6B6E76;">Rien sous « Autres » sur cette période.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- Les deux tableaux se rangent l'un sous l'autre quand l'écran ne peut plus les tenir
         côte à côte : à moins de 430 px chacun, ils ne montraient plus rien d'utile. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(430px, 1fr)); gap:20px;">
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
                            <th class="colonne-collee"></th>
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
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" wire:click="voirEncaissement({{ $ligne->id }})"
                                        class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">
                                        {{ (int) $detailEncaissement === (int) $ligne->id ? 'Fermer' : 'Détail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ((int) $detailEncaissement === (int) $ligne->id)
                                {{-- La pièce d'origine, sous sa ligne : d'où elle vient, qui l'a
                                     posée, et la facture qu'elle solde. --}}
                                <tr style="background:#F7F5EF;">
                                    <td colspan="7" style="font-size:12.5px; padding:10px 12px;">
                                        <div><b>Référence</b> : {{ $ligne->numero ?: '—' }}</div>
                                        <div><b>Origine</b> : {{ $this->origineDe($ligne) }}</div>
                                        <div><b>Atelier</b> : {{ $ligne->site?->nom ?: '—' }}</div>
                                        <div><b>Activité</b> : {{ $ligne->activite ?: 'non ventilée' }}</div>
                                        @if ($ligne->facture)
                                            <div><b>Facture réglée</b> :
                                                {{ $ligne->facture->n_facture ?: $ligne->facture->numero }}
                                                — {{ $ligne->facture->client }}
                                                {{ $ligne->facture->immatriculation ? '· '.$ligne->facture->immatriculation : '' }}
                                            </div>
                                        @elseif ($ligne->reference_origine)
                                            <div><b>Référence d'origine</b> : {{ $ligne->reference_origine }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <x-table-vide :colspan="7" texte="Aucun encaissement sur cette période." />
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
                            <th class="colonne-collee"></th>
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
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" wire:click="voirDecaissement({{ $ligne->id }})"
                                        class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">
                                        {{ (int) $detailDecaissement === (int) $ligne->id ? 'Fermer' : 'Détail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ((int) $detailDecaissement === (int) $ligne->id)
                                <tr style="background:#F7F5EF;">
                                    <td colspan="7" style="font-size:12.5px; padding:10px 12px;">
                                        <div><b>Référence</b> : {{ $ligne->numero ?: '—' }}</div>
                                        <div><b>Origine</b> : {{ $this->origineDe($ligne) }}</div>
                                        <div><b>Atelier</b> : {{ $ligne->site?->nom ?: '—' }}</div>
                                        <div><b>Activité</b> : {{ $ligne->activite ?: 'non ventilée' }}</div>
                                        @if ($ligne->observations)
                                            <div><b>Observations</b> : {{ $ligne->observations }}</div>
                                        @endif
                                        @if ($ligne->reference_origine)
                                            <div><b>Référence d'origine</b> : {{ $ligne->reference_origine }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <x-table-vide :colspan="7" texte="Aucun décaissement sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageDecaissements" :total="$this->detailDecaissements->count()" prop="pageDecaissements" />
        </div>
    </div>
</div>

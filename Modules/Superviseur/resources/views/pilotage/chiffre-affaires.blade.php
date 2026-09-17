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
    /*
     * L'origine de la ligne : reprise du logiciel d'atelier, ou saisie ici.
     *
     * La distinction existait en base depuis longtemps — `lot_import_id` — sans qu'aucun écran
     * ne permette de s'en servir. Or elle change ce qu'on peut attendre d'une ligne : une
     * facture reprise n'a pas de commercial, parce que le logiciel d'atelier ne connaît pas
     * cette notion, et son atelier peut manquer tant que le code de liaison n'est pas
     * rattaché. Voir PeutVenirDUnImport.
     */
    'origineFiltre' => '',
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
$updatedOrigineFiltre = function () { $this->pageDetail = 1; };
$updatedRecherche = function () { $this->pageDetail = 1; };
$updatedCommercialFiltre = function () { $this->pageDetail = 1; };

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

/*
 * Les totaux et le graphique sont additionnés par la base, ou sur trois colonnes seulement.
 *
 * Mesuré en local : l'écran chargeait toutes les factures de la période quatorze fois par
 * affichage — une pour les totaux, une par point du graphique, une pour le tableau — soit
 * 42 requêtes et 1,4 s. Une somme n'a pas besoin des trente colonnes de chaque facture.
 */
$kpis = computed(function () {
    // Une facture porte toujours son activité ; une charge, seulement si celui qui l'a
    // saisie la connaissait. Le résultat net hérite donc du « non ventilé » des charges.
    $caVentile = VentilationActivite::repartir($this->requeteBase);
    $ca = $caVentile['mecanique'] + $caVentile['sinistre'] + $caVentile['nonVentile'];
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

    // Deux lectures pour tout le graphique, au lieu de deux par point : les montants du jour,
    // regroupés par la base, puis rangés dans leurs points ici.
    $factures = (clone $this->requeteBase)->reorder()->toBase()
        ->selectRaw('date as jour, activite, sum(montant) as total')
        ->groupBy('date', 'activite')->get();
    $charges = (clone $this->requeteCharges)->reorder()->toBase()
        ->selectRaw('date as jour, sum(montant) as total')
        ->groupBy('date')->get();

    foreach ($points as $point) {
        $debut = $point['debut']->toDateString();
        $fin = $point['fin']->toDateString();
        $dansLePoint = fn ($l) => substr((string) $l->jour, 0, 10) >= $debut && substr((string) $l->jour, 0, 10) <= $fin;

        $lignes = $factures->filter($dansLePoint);
        $ca = (int) $lignes->sum('total');

        $labels[] = $point['label'];
        $mecanique[] = (int) $lignes->where('activite', 'Mécanique')->sum('total');
        $sinistre[] = (int) $lignes->where('activite', 'Sinistre')->sum('total');
        $resultat[] = $ca - (int) $charges->filter($dansLePoint)->sum('total');
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

/**
 * Le détail, avec les colonnes du fichier CATTC et le filtre d'origine.
 *
 * **Le filtre d'origine ne porte que sur ce tableau**, jamais sur les totaux ni sur le
 * graphique, et c'est délibéré : un chiffre d'affaires amputé de ce qui a été importé ne
 * serait plus celui de l'entreprise, ce serait celui de ce qu'on a tapé. On regarde les lignes
 * d'une origine ; on ne réduit pas le chiffre à cette origine.
 *
 * Il s'appuie sur les portées du modèle — `importee()`, `saisieManuelle()` — plutôt que sur un
 * `whereNull('lot_import_id')` écrit ici : la même question se pose sur cinq écrans, et une
 * condition recopiée finit par diverger de celle qui décide ailleurs si une ligne est reprise.
 */
$requeteDetail = computed(function () {
    $q = clone $this->requeteBase;

    if ($this->origineFiltre === 'import') {
        $q->importee();
    } elseif ($this->origineFiltre === 'local') {
        $q->saisieManuelle();
    }

    return $q;
});

/*
 * « Porter à l'état » n'est offert qu'à qui ouvre l'état des impayés : sa route est fermée au
 * responsable commercial, et un bouton qui mène à un refus n'est pas un bouton.
 */
$peutPorter = computed(fn () => auth()->user()->hasAnyRole(['gerant', 'responsable_ville', 'responsable_site']));

$nombreDetail = computed(fn () => (clone $this->requeteDetail)->count());

/** Seule la page affichée se charge : dix factures, et non toutes celles de la période. */
$detail = computed(fn () => (clone $this->requeteDetail)
    ->with(['commercial', 'site'])
    ->latest('date')->latest('id')
    ->forPage(max(1, (int) $this->pageDetail), 10)
    ->get());

/** Combien de lignes de chaque origine, pour que le filtre annonce ce qu'il va trouver. */
$comptesParOrigine = computed(fn () => [
    'import' => (clone $this->requeteBase)->importee()->count(),
    'local' => (clone $this->requeteBase)->saisieManuelle()->count(),
]);

?>

<div>
    <x-titre-ecran titre="Chiffre d'affaires"
        sous-titre="Ce qui a été facturé sur la période, par activité et par lieu." />

    <x-filtre-periode :periode="$periode" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="CA — {{ $this->libellePerimetre }}" :value="ae($this->kpis['total'])" :sub="$this->nombreDetail.' facture(s)'"
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

    {{-- Le détail porte désormais les colonnes du fichier CATTC, sous ses propres intitulés.
         Cinq d'entre elles manquaient : le sticker, le n° de sinistre et le code client étaient
         concaténés dans une phrase rangée en observation, la marque et le modèle fondus en une
         seule valeur. Une donnée dans une phrase ne se trie pas, ne se filtre pas et ne
         s'affiche pas en colonne : elle était conservée sans être consultable. --}}
    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Détail des factures ({{ $this->nombreDetail }})</h3>
        <p style="font-size:12.5px; color:#6B6E76; margin:0 0 14px;">
            Les colonnes sont celles du fichier CATTC. Le filtre d'origine ne touche que ce tableau :
            les totaux et le graphique ci-dessus comptent tout, sans quoi ce ne serait plus le chiffre
            d'affaires de l'entreprise mais celui de ce qu'on a tapé.
        </p>
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
            {{-- Le décompte est dans l'intitulé de chaque choix : un filtre qui annonce
                 « 0 » avant qu'on le choisisse évite le clic qui ne trouve rien. --}}
            <select wire:model.live="origineFiltre" style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
                <option value="" @selected($origineFiltre === '')>Origine : toutes</option>
                <option value="import" @selected($origineFiltre === 'import')>
                    Reprises du logiciel d'atelier ({{ $this->comptesParOrigine['import'] }})
                </option>
                <option value="local" @selected($origineFiltre === 'local')>
                    Saisies sur la plateforme ({{ $this->comptesParOrigine['local'] }})
                </option>
            </select>
        </div>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Origine</th>
                        <th>DATE DE LA FACTURE</th>
                        <th>N° FACTURE</th>
                        <th>N° STICKER</th>
                        <th>FICHE DE RECEPTION</th>
                        <th>N° SINISTRE</th>
                        <th>IMMAT. VEHICULE</th>
                        <th>MARQUE</th>
                        <th>MODELE</th>
                        <th>CODE CLIENT</th>
                        <th>CLIENTS</th>
                        <th style="text-align:right;">MONTANT FACTURE</th>
                        @if (count($this->idsSites) > 1)
                            <th>SITE</th>
                        @endif
                        <th>Activité</th>
                        <th>Commercial</th>
                        @if ($this->peutPorter)
                            <th>État des impayés</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>
                                @if ($ligne->estImportee())
                                    <span style="font-size:11.5px; color:#B9791C; font-weight:600;">Reprise</span>
                                @else
                                    <span style="font-size:11.5px; color:#0E9F6E; font-weight:600;">Saisie ici</span>
                                @endif
                            </td>
                            <td>{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_facture ?? '—' }}</td>
                            <td>{{ $ligne->n_sticker ?? '—' }}</td>
                            <td>{{ $ligne->reference_devis ?? '—' }}</td>
                            <td>{{ $ligne->n_sinistre ?? '—' }}</td>
                            <td>{{ $ligne->immatriculation ?? '—' }}</td>
                            {{-- Marque et modèle sont deux colonnes du fichier que l'import
                                 fusionnait en une seule valeur. Les lignes déjà reprises
                                 portent donc le véhicule entier : il s'affiche ici plutôt
                                 que d'être découpé au premier espace — « LAND ROVER
                                 DEFENDER » donnerait la marque « LAND ». Le prochain dépôt
                                 du fichier remplit les deux colonnes. --}}
                            <td @if (! $ligne->marque && $ligne->vehicule) title="Véhicule non encore séparé en marque et modèle — le prochain dépôt du CATTC le fera." style="color:#6B6E76;" @endif>
                                {{ $ligne->marque ?: ($ligne->vehicule ?: '—') }}
                            </td>
                            <td>{{ $ligne->modele ?: '—' }}</td>
                            <td>{{ $ligne->code_client ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne->montant) }}</td>
                            @if (count($this->idsSites) > 1)
                                <td>{{ $ligne->site?->nom ?? '— à rattacher —' }}</td>
                            @endif
                            <td>{{ $ligne->activite }}</td>
                            <td>{{ $ligne->commercial?->nom ?? '—' }}</td>
                            @if ($this->peutPorter)
                                <td style="white-space:nowrap;">
                                    {{-- Envoyer la facture à l'état : le panneau « Porter » s'y ouvre dessus,
                                         les champs connus déjà remplis. --}}
                                    @if ($ligne->exercice_impayes === null)
                                        <a href="{{ route('impayes', ['porter' => $ligne->id]) }}" wire:navigate class="bouton bouton-secondaire"
                                            style="padding:4px 10px; font-size:12px; text-decoration:none;">Porter à l'état</a>
                                    @else
                                        <a href="{{ route('impayes.detail', $ligne->id) }}" wire:navigate
                                            style="font-size:12px; color:#0E9F6E; font-weight:600;">À l'état {{ $ligne->exercice_impayes }}</a>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <x-table-vide :colspan="(count($this->idsSites) > 1 ? 16 : 15) + ($this->peutPorter ? 1 : 0)" texte="Aucune facture enregistrée sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageDetail" :total="$this->nombreDetail" prop="pageDetail" />
    </div>
</div>

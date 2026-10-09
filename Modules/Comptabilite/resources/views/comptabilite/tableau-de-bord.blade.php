<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Illuminate\Support\Carbon;
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
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->pageAttente = 1; $this->pageOperations = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->pageAttente = 1; $this->pageOperations = 1; };
$updatedJourFiltre = function () { $this->pageAttente = 1; $this->pageOperations = 1; };
$updatedVilleFiltre = function () { $this->pageAttente = 1; $this->pageOperations = 1; };

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

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * **Les KPI revus le 09/10** — « revois les KPI, vérifie comment on peut avoir zéro, et s'ils
 * suivent le filtre ». Trois défauts, tous mesurables :
 *
 * 1. **Zéro partout, par le piège n° 1.** Encaissements et factures étaient cherchés par
 *    `whereIn('site_id', …)`. Un règlement importé de l'état des impayés n'a presque jamais
 *    d'atelier — le fichier dit la ville — : il ne tombait dans aucun. Le périmètre est
 *    désormais celui de la trésorerie : l'atelier, sinon la ville, sinon la ligne paraît.
 * 2. **« Aujourd'hui » ne suivait pas le filtre.** Choisir septembre laissait les trois
 *    premières cartes sur la date du jour, donc à zéro. Elles portent maintenant sur **le
 *    dernier jour de la période choisie** (aujourd'hui si la période court encore), et le
 *    disent.
 * 3. **Le reste à encaisser n'était ni filtré ni complet.** Il ne comptait que les factures
 *    tapées à la main, toutes dates confondues. Il compte désormais toutes celles dont le
 *    reste se calcule (saisies, et portées à l'état des impayés — voir
 *    `Facture::scopeAvecHistoriqueDeReglement`), **émises jusqu'à la fin de la période** :
 *    c'est l'encours à cette date. Les factures du seul CATTC restent dehors : elles
 *    arrivent sans règlement et paraîtraient dues en entier.
 *
 * Le journal de caisse importé a sa carte à part, sans s'additionner aux règlements : un
 * règlement en espèces est écrit à l'état des impayés **et** au journal (règle du 07/10).
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));

/** Le jour des trois premières cartes : la fin de la période, sans dépasser aujourd'hui. */
$jourRegarde = computed(function () {
    [, $fin] = $this->plage;
    $fin = Carbon::parse($fin)->startOfDay();

    return $fin->greaterThan(today()) ? today() : $fin;
});

$facturesEnAttenteQ = computed(function () {
    [, $fin] = $this->plage;

    return EtatDesImpayes::dansLePerimetre(Facture::query(), $this->idsSites, $this->idsVilles)
        ->avecHistoriqueDeReglement()
        ->avecResteAEncaisser()
        ->where('factures.date', '<=', Carbon::parse($fin)->toDateString());
});

// Le haut du tableau : les plus gros restes d'abord — c'est ce que « Top » veut dire. Paginé
// en base : l'encours compte des milliers de factures.
$facturesEnAttente = computed(fn () => (clone $this->facturesEnAttenteQ)
    ->select('factures.*')
    ->selectRaw('factures.montant - '.Facture::ENCAISSE_SQL.' as reste_calcule')
    ->orderByDesc('reste_calcule')->orderByDesc('factures.id')
    ->forPage($this->pageAttente, 10)
    ->get());

$encours = computed(function () {
    // En deux temps : MySQL refuse la sous-requête du reste dans un `sum()` (ERR-XEYTWV).
    $lignes = (clone $this->facturesEnAttenteQ)
        ->selectRaw('factures.montant - '.Facture::ENCAISSE_SQL.' as reste');

    $ligne = \Illuminate\Support\Facades\DB::query()->fromSub($lignes->toBase(), 'l')
        ->selectRaw('count(*) as nombre, coalesce(sum(l.reste), 0) as reste')
        ->first();

    return ['nombre' => (int) ($ligne->nombre ?? 0), 'reste' => (int) ($ligne->reste ?? 0)];
});

$kpis = computed(function () {
    [$debut, $fin] = $this->plage;
    $jour = $this->jourRegarde->toDateString();

    $encaissements = fn () => PerimetreDeTresorerie::encaissements(Encaissement::query(), $this->idsSites, $this->idsVilles);
    // `charges.site_id` est NOT NULL : le périmètre par atelier suffit, et c'est celui de la trésorerie.
    $charges = fn () => PerimetreDeTresorerie::charges(Charge::query(), $this->idsSites);

    $encaisseJour = (int) $encaissements()->whereDate('encaissements.date', $jour)->sum('encaissements.montant');
    $encaissePeriode = (int) $encaissements()->whereBetween('encaissements.date', [$debut, $fin])->sum('encaissements.montant');
    $decaisseJour = (int) $charges()->whereDate('charges.date', $jour)->sum('charges.montant');
    $decaissePeriode = (int) $charges()->whereBetween('charges.date', [$debut, $fin])->sum('charges.montant');

    $journal = PerimetreDeTresorerie::mouvementsDeCaisse(MouvementCaisse::query(), $this->idsSites, $this->idsVilles)
        ->whereBetween('mouvements_caisse.date', [$debut, $fin])
        ->selectRaw('count(*) as nombre, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as entrees, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as sorties',
            [MouvementCaisse::ENTREE, MouvementCaisse::SORTIE])
        ->toBase()->first();

    return [
        'encaisseJour' => $encaisseJour,
        'decaisseJour' => $decaisseJour,
        'soldeJour' => $encaisseJour - $decaisseJour,
        'encaissePeriode' => $encaissePeriode,
        'decaissePeriode' => $decaissePeriode,
        'soldePeriode' => $encaissePeriode - $decaissePeriode,
        'resteAEncaisser' => $this->encours['reste'],
        'facturesEnAttente' => $this->encours['nombre'],
        'journalEntrees' => (int) ($journal->entrees ?? 0),
        'journalSorties' => (int) ($journal->sorties ?? 0),
        'journalNombre' => (int) ($journal->nombre ?? 0),
    ];
});

/** Mes opérations de la période — celles que j'ai saisies, et elles seules. */
$dernieresOperations = computed(function () {
    [$debut, $fin] = $this->plage;

    $encaissements = Encaissement::whereIn('site_id', $this->idsSites)->where('cree_par', auth()->id())
        ->whereBetween('date', [$debut, $fin])
        ->latest('date')->latest('id')->get()
        ->map(fn ($e) => ['date' => $e->date, 'type' => $e->type, 'libelle' => $e->client ?? $e->autres_tiers ?? '—', 'montant' => $e->montant, 'sens' => 1]);

    $charges = Charge::whereIn('site_id', $this->idsSites)->where('cree_par', auth()->id())
        ->whereBetween('date', [$debut, $fin])
        ->latest('date')->latest('id')->get()
        ->map(fn ($c) => ['date' => $c->date, 'type' => $c->type_operation, 'libelle' => $c->libelle, 'montant' => $c->montant, 'sens' => -1]);

    return $encaissements->concat($charges)->sortByDesc(fn ($l) => $l['date'])->values();
});

?>

<div>
    <x-titre-ecran titre="Comptabilité — tableau de bord"
        sous-titre="Ce qui reste à encaisser, et ce qui est sorti de la caisse." />

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :masquer-activite="true"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(165px, 1fr)); gap:10px; margin-bottom:20px;">
        @php
            $jourLibelle = $this->jourRegarde->isToday() ? "aujourd'hui" : 'le '.$this->jourRegarde->format('d/m/Y');
        @endphp
        <x-kpi-card label="Encaissé {{ $jourLibelle }}" :value="ae($this->kpis['encaisseJour'])" couleur="#0E9F6E"
            sub="Dernier jour de la période" />
        <x-kpi-card label="Décaissé {{ $jourLibelle }}" :value="ae($this->kpis['decaisseJour'])" couleur="#C8102E" />
        <x-kpi-card label="Solde {{ $jourLibelle }}" :value="ae($this->kpis['soldeJour'])"
            :couleur="$this->kpis['soldeJour'] >= 0 ? '#0E9F6E' : '#C8102E'" sub="Encaissé − décaissé" />
        <x-kpi-card label="Encaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['encaissePeriode'])"
            sub="Règlements de la période, tous moyens" />
        <x-kpi-card label="Décaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['decaissePeriode'])"
            sub="Charges de la période" />
        <x-kpi-card label="Solde — période" :value="ae($this->kpis['soldePeriode'])"
            :couleur="$this->kpis['soldePeriode'] >= 0 ? '#0E9F6E' : '#C8102E'" sub="Encaissé − décaissé" />
        <x-kpi-card label="Caisse — journal importé" :value="$this->kpis['journalNombre'] > 0 ? ae($this->kpis['journalEntrees'] - $this->kpis['journalSorties']) : '—'"
            sub="{{ $this->kpis['journalNombre'] > 0 ? 'Entrées '.ae($this->kpis['journalEntrees']).' · sorties '.ae($this->kpis['journalSorties']) : 'Aucun journal de caisse sur la période' }}" />
        <x-kpi-card label="Reste à encaisser" :value="ae($this->kpis['resteAEncaisser'])" couleur="#D97706"
            sub="{{ number_format($this->kpis['facturesEnAttente'], 0, ',', ' ') }} facture(s) émises jusqu'au {{ \Illuminate\Support\Carbon::parse($this->plage[1])->format('d/m/Y') }}" />
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Top factures en attente d'encaissement <span style="font-weight:400; color:#6B6E76; font-size:12.5px;">— les plus gros restes</span></h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>N° facture</th><th>Client</th><th>Montant</th><th>Reste</th></tr></thead>
                    <tbody>
                        @forelse ($this->facturesEnAttente as $f)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $f->n_facture }}</td>
                                <td>{{ $f->client }}</td>
                                <td style="font-variant-numeric:tabular-nums;">{{ ae($f->montant) }}</td>
                                <td style="font-weight:700; color:#D97706; font-variant-numeric:tabular-nums;">{{ ae((int) $f->reste_calcule) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune facture en attente d'encaissement." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageAttente" :total="$this->encours['nombre']" prop="pageAttente" />
            <div style="margin-top:14px;">
                <a href="{{ route('caissier.encaissements') }}" wire:navigate class="bouton bouton-sombre">Encaisser une facture →</a>
            </div>
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Mes opérations de la période</h3>
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

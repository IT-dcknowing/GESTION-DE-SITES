<?php

use Illuminate\Support\Carbon;
use Modules\Noyau\Exploitation\Modeles\CommentaireEcartRecouvrement;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use Modules\Recouvrement\Support\AccesRecouvrement;
use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Synthèse & pilotage — l'écart, et son explication
|--------------------------------------------------------------------------
| Un chiffre en dessous de l'objectif ne dit pas pourquoi. Le tableau des écarts
| existe pour qu'on l'écrive : « trois assureurs en règlement groupé fin de mois »
| n'appelle pas la même décision que « aucune relance N4 partie cette semaine ».
|
| Sans le commentaire, la revue hebdomadaire se résume à constater un retard, et le
| même retard se reconstate la semaine suivante.
|
| L'objectif est une décision de direction : le gérant le fixe, le superviseur le lit.
*/

/*
 * La période commande l'arrêté, comme sur les autres écrans du module. Trois appels
 * séparés : la liaison à l'adresse attend une chaîne, pas un tableau.
 */
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

state([
    'commentaires' => [],
    'objectifHebdo' => 0,
]);

mount(function () {
    $this->objectifHebdo = (int) auth()->user()->entreprise->objectif_recouvrement_hebdomadaire;

    foreach ($this->periodes as $periode) {
        $this->commentaires[$periode['clef']] = CommentaireEcartRecouvrement::query()
            ->where('periode', $periode['clef'])
            ->where('reference', $periode['reference'])
            ->value('texte') ?? '';
    }
});

/** La période regardée, et l'arrêté qu'elle commande — voir PeriodeDeTravail. */
$periodeDeTravail = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periodeDeTravail->arreteIso()));

/*
 * Lues telles que la base les rend, sans en faire des objets : cet écran additionne les
 * créances ouvertes, il n'en affiche aucune ligne à ligne. Voir lignesOuvertes().
 */
$ouvertes = computed(fn () => Recouvrement::lignesOuvertes($this->arrete));

$kpis = computed(fn () => Recouvrement::kpis($this->ouvertes, $this->arrete));

$parTiers = computed(fn () => Recouvrement::parTiers($this->ouvertes, $this->arrete));

/**
 * Les dix tiers qui portent le plus d'encours.
 *
 * Le recouvrement se fait par tiers, jamais par tranche d'ancienneté : on n'appelle pas
 * « les créances de 61 à 90 jours », on appelle NSIA. Ces dix-là étaient sur un autre
 * écran, alors que ce sont eux qu'on va relancer en sortant de la réunion.
 */
$premiers = computed(fn () => $this->parTiers->take(10));

/** La part de l'encours que portent ces dix tiers — souvent l'essentiel. */
$partDesPremiers = computed(function () {
    $total = max(1, $this->kpis['reste']);

    return $this->premiers->sum('reste') / $total;
});

$peutCommenter = computed(fn () => AccesRecouvrement::peutCommenter(auth()->user()));

$peutFixerLObjectif = computed(fn () => AccesRecouvrement::peutFixerLObjectif(auth()->user()));

/** Encours total par tranche d'ancienneté, pour la barre et sa légende. */
$tranches = computed(function () {
    $totaux = array_fill(0, count(Recouvrement::TRANCHES), 0);

    $jourArrete = NombreDeJours::jour($this->arrete);

    foreach ($this->ouvertes as $ligne) {
        $index = Recouvrement::tranchePourAge(Recouvrement::ageDeLaLigne($ligne, $jourArrete));

        if ($index !== null) {
            $totaux[$index] += Recouvrement::resteDe($ligne->montant, $ligne->encaissements_sum_montant);
        }
    }

    return $totaux;
});

/**
 * Les trois périodes de la revue, avec leur objectif proratisé.
 *
 * L'objectif est fixé à la semaine ; le jour vaut la semaine divisée par le nombre de
 * jours ouvrés, le mois vaut la semaine multipliée par 52 et divisée par 12. En cours de
 * période, l'écart se lit comme un reste à réaliser, pas comme un échec — la nuance est
 * écrite sous le tableau, car sans elle un lundi matin affiche toujours un retard.
 */
$periodes = computed(function () {
    $jour = $this->arrete;
    $hebdo = max(0, (int) $this->objectifHebdo);
    $joursOuvres = 6;

    return [
        [
            'clef' => 'jour',
            'reference' => $jour->toDateString(),
            'libelle' => 'Jour — '.$jour->format('d/m/Y'),
            'realise' => $this->encaisseEntre($jour, $jour),
            'objectif' => (int) round($hebdo / $joursOuvres),
        ],
        [
            'clef' => 'semaine',
            'reference' => $jour->format('o-\SW'),
            'libelle' => 'Semaine en cours (du '.$jour->copy()->startOfWeek()->format('d/m').')',
            'realise' => $this->encaisseEntre($jour->copy()->startOfWeek(), $jour),
            'objectif' => $hebdo,
        ],
        [
            'clef' => 'mois',
            'reference' => $jour->format('Y-m'),
            'libelle' => 'Mois en cours ('.$jour->format('m/Y').')',
            'realise' => $this->encaisseEntre($jour->copy()->startOfMonth(), $jour),
            'objectif' => (int) round($hebdo * 52 / 12),
        ],
    ];
});

/*
 * Volt rend publique toute fonction déclarée ici, donc appelable depuis le navigateur.
 * Celle-ci n'est qu'un calcul interne : protect() la garde hors de portée.
 */
$encaisseEntre = protect(function (Carbon $debut, Carbon $fin): int {
    return (int) Encaissement::whereBetween('date', [$debut->copy()->startOfDay(), $fin->copy()->endOfDay()])
        ->sum('montant');
});

$enregistrerCommentaire = function (string $clef) {
    if (! $this->peutCommenter) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: "Le commentaire d'écart relève du superviseur ou du gérant.");

        return;
    }

    $periode = collect($this->periodes)->firstWhere('clef', $clef);

    if (! $periode) {
        return;
    }

    $texte = trim((string) ($this->commentaires[$clef] ?? ''));

    // Un commentaire vidé s'efface plutôt que de laisser une ligne blanche : une revue
    // qui affiche un commentaire vide se lit comme une revue qui n'a rien à dire.
    if ($texte === '') {
        CommentaireEcartRecouvrement::where('periode', $clef)->where('reference', $periode['reference'])->delete();

        return;
    }

    CommentaireEcartRecouvrement::updateOrCreate(
        [
            'entreprise_id' => auth()->user()->entreprise_id,
            'periode' => $clef,
            'reference' => $periode['reference'],
        ],
        ['texte' => $texte, 'user_id' => auth()->id()],
    );

    $this->dispatch('annonce', texte: 'Commentaire enregistré pour la période « '.$clef.' ».');
};

$enregistrerObjectif = function () {
    if (! $this->peutFixerLObjectif) {
        $this->dispatch('annonce', ton: 'alerte', texte: "L'objectif est fixé par la direction.");

        return;
    }

    $this->validate(['objectifHebdo' => ['required', 'numeric', 'min:0', 'max:100000000000']], [], [
        'objectifHebdo' => 'objectif hebdomadaire',
    ]);

    $entreprise = auth()->user()->entreprise;
    $entreprise->update(['objectif_recouvrement_hebdomadaire' => (int) $this->objectifHebdo]);

    activity()->causedBy(auth()->user())
        ->withProperties(['objectif_hebdomadaire' => (int) $this->objectifHebdo])
        ->log("Recouvrement — objectif hebdomadaire fixé");

    $this->dispatch('annonce', texte: 'Objectif porté à '.Recouvrement::fr((int) $this->objectifHebdo).' par semaine.');
};

?>

<x-recouvrement::coquille page="synthese">
    <x-slot:actions>
        <x-recouvrement::periode route="recouvrement.synthese" :periode="$this->periodeDeTravail" />
    </x-slot:actions>

    {{-- La phrase qu'on lit debout, avant la réunion. Elle ne remplace pas les chiffres :
         elle dit lequel regarder en premier, ce qu'aucun tableau ne fait tout seul. --}}
    @php
        $totalEncours = max(1, array_sum($this->tranches));
        $vieux = $this->tranches[3] + $this->tranches[4];
        $partVieux = $vieux / $totalEncours;
        $recent = $this->tranches[0] / $totalEncours;
    @endphp

    <div class="rec-carte" style="border-left:5px solid {{ $partVieux >= 0.5 ? '#C8102E' : ($partVieux >= 0.3 ? '#D9541E' : '#1E7B34') }}; margin-bottom:15px;">
        <div style="font-family:'Barlow Condensed',sans-serif; font-size:22px; font-weight:700; line-height:1.3;">
            @if ($this->kpis['ouvertes'] === 0)
                Aucune facture ouverte à cette date. Rien à recouvrer.
            @else
                <span style="color:#C8102E;">{{ Recouvrement::fr($this->kpis['reste']) }}</span>
                restent dus par {{ $this->kpis['tiers'] }} tiers,
                dont <span style="color:{{ $partVieux >= 0.5 ? '#C8102E' : '#D9541E' }};">{{ round($partVieux * 100) }} %</span>
                à plus de 90 jours.
            @endif
        </div>

        @if ($this->kpis['ouvertes'] > 0)
            <div style="font-size:14px; line-height:1.6; color:#4B4E55; margin-top:9px;">
                @if ($partVieux >= 0.5)
                    <strong>Plus de la moitié de l'encours est ancien.</strong> Le téléphone ne suffira pas :
                    la période appelle des mises en demeure (N4) et la préparation du contentieux (N5).
                    {{ Recouvrement::fr($this->kpis['contentieux']) }} sont déjà en zone N5.
                @elseif ($partVieux >= 0.3)
                    <strong>Le vieillissement s'installe.</strong> {{ Recouvrement::fr($vieux) }} ont dépassé
                    90 jours : les traiter maintenant coûte un appel, dans trois mois cela coûtera un huissier.
                @else
                    <strong>L'encours est jeune</strong> — {{ round($recent * 100) }} % a moins de 30 jours.
                    La relance courante (N1–N2) suffit à le tenir.
                @endif

                Les dix premiers tiers en portent <strong>{{ round($this->partDesPremiers * 100) }} %</strong> :
                c'est par eux qu'il faut commencer.
            </div>
        @endif
    </div>

    <div class="rec-kpis">
        <div class="rec-kpi rouge">
            <div class="lab">Reste à payer</div>
            <div class="val">{{ number_format($this->kpis['reste'], 0, ',', ' ') }}</div>
            <div class="sub">F CFA · {{ $this->kpis['ouvertes'] }} factures · {{ $this->kpis['tiers'] }} tiers</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Encaissé sur le mois</div>
            <div class="val">{{ number_format($this->kpis['encaisse_mois'], 0, ',', ' ') }}</div>
            <div class="sub">F CFA</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Zone contentieuse N5</div>
            <div class="val">{{ number_format($this->kpis['contentieux'], 0, ',', ' ') }}</div>
            <div class="sub">+90 jours · AUPSRVE</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Objectif hebdomadaire</div>
            <div class="val">{{ number_format((int) $this->objectifHebdo, 0, ',', ' ') }}</div>
            <div class="sub">F CFA · fixé par la direction</div>
        </div>
    </div>

    <div class="rec-g2" style="align-items:start;">

        {{-- ─────────────── Balance âgée globale ─────────────── --}}
        <div class="rec-carte">
            <h2>Balance âgée globale</h2>

            @php $total = max(1, array_sum($this->tranches)); @endphp

            <div class="rec-bar">
                @foreach ($this->tranches as $index => $montant)
                    <div style="width:{{ max(1, $montant / $total * 100) }}%; background:{{ Recouvrement::TRANCHES[$index]['couleur'] }};"
                        title="{{ Recouvrement::TRANCHES[$index]['libelle'] }} : {{ Recouvrement::fr($montant) }}"></div>
                @endforeach
            </div>

            {{-- La légende de cinq carrés colorés est remplacée par un tableau : une part se
                 compare d'un coup d'œil, une couleur demande d'aller la retrouver dans la
                 barre. La colonne « part » est celle qu'on lit ; le montant vient après. --}}
            <div class="rec-tbl-wrap" style="margin-top:12px;">
                <table class="rec-tbl">
                    <thead>
                        <tr><th>Ancienneté</th><th class="num">Part</th><th class="num">Montant</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($this->tranches as $index => $montant)
                            <tr>
                                <td>
                                    <span class="rec-sw" style="background:{{ Recouvrement::TRANCHES[$index]['couleur'] }};"></span>
                                    {{ Recouvrement::TRANCHES[$index]['libelle'] }}
                                </td>
                                <td class="num"><b>{{ round($montant / $total * 100) }} %</b></td>
                                <td class="num">{{ number_format($montant, 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                        <tr class="tot">
                            <td>TOTAL</td>
                            <td class="num">100 %</td>
                            <td class="num">{{ number_format(array_sum($this->tranches), 0, ',', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rec-hint" style="margin-top:11px;">
                Une créance se recouvre d'autant plus mal qu'elle vieillit&nbsp;: c'est la seule raison
                pour laquelle cette répartition compte davantage que le total.
            </div>
        </div>

        {{-- ─────────────── Objectif vs réalisé ─────────────── --}}
        <div class="rec-carte">
            <h2>
                Objectif contre réalisé — écarts à commenter
                <span class="chip">{{ $this->peutCommenter ? 'Commentaires : superviseur · gérant' : 'Lecture' }}</span>
            </h2>

            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Période</th>
                        <th class="num">Réalisé</th>
                        <th class="num">Objectif</th>
                        <th class="num">Écart</th>
                        <th class="num">%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->periodes as $periode)
                        @php
                            $ecart = $periode['realise'] - $periode['objectif'];
                            $part = $periode['objectif'] > 0 ? round($periode['realise'] / $periode['objectif'] * 100) : 0;
                        @endphp
                        <tr>
                            <td>{{ $periode['libelle'] }}</td>
                            <td class="num">{{ number_format($periode['realise'], 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format($periode['objectif'], 0, ',', ' ') }}</td>
                            <td class="num {{ $ecart < 0 ? 'rec-neg' : 'rec-pos' }}">
                                {{ $ecart < 0 ? '−' : '+' }}{{ number_format(abs($ecart), 0, ',', ' ') }}
                            </td>
                            <td class="num">{{ $part }} %</td>
                        </tr>
                        <tr>
                            <td colspan="5">
                                @if ($this->peutCommenter)
                                    <textarea class="rec-cmt"
                                        wire:model="commentaires.{{ $periode['clef'] }}"
                                        wire:change="enregistrerCommentaire('{{ $periode['clef'] }}')"
                                        placeholder="Commentaire de l'écart ({{ $periode['clef'] }}) — revue hebdomadaire…"></textarea>
                                @else
                                    <div class="rec-hint" style="margin:0;">
                                        {{ $this->commentaires[$periode['clef']] ?: 'Commentaire réservé au superviseur / gérant.' }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="rec-hint">
                Objectif : {{ Recouvrement::fr((int) $this->objectifHebdo) }} par semaine · jour = hebdomadaire ÷ 6
                jours ouvrés · mois = hebdomadaire × 52 ÷ 12. <b>En cours de période, l'écart se lit comme un
                reste à réaliser</b>, non comme un manquement.
            </div>

            @if ($this->peutFixerLObjectif)
                <div class="rec-actions">
                    <div class="rec-fld" style="max-width:220px;">
                        <label>Objectif hebdomadaire (F)</label>
                        <input type="number" min="0" step="100000" wire:model="objectifHebdo" value="{{ $objectifHebdo }}">
                    </div>
                    <button type="button" class="rec-btn o" wire:click="enregistrerObjectif" style="align-self:flex-end;">
                        Enregistrer l'objectif
                    </button>
                </div>
                @error('objectifHebdo') <div class="rec-hint warn">⚠ {{ $message }}</div> @enderror
            @endif
        </div>

        {{-- ─────────────── Top débiteurs ─────────────── --}}
        <div class="rec-carte" style="grid-column:1/-1;">
            <h2>Principaux débiteurs <span class="chip">{{ $this->parTiers->count() }} tiers</span></h2>

            <div class="rec-tbl-wrap">
                <table class="rec-tbl">
                    <thead>
                        <tr>
                            <th>Tiers</th>
                            <th class="num">Reste à payer</th>
                            <th class="num">Factures</th>
                            <th>Niveau maximal requis</th>
                            <th class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->parTiers->take(15) as $ligne)
                            <tr>
                                <td><b>{{ $ligne['tiers'] }}</b></td>
                                <td class="num">{{ number_format($ligne['reste'], 0, ',', ' ') }}</td>
                                <td class="num">{{ $ligne['nombre'] }}</td>
                                <td><span class="pill {{ $ligne['niveau']['classe'] }}">{{ $ligne['niveau']['libelle'] }}</span></td>
                                <td class="no-print">
                                    <a href="{{ route('recouvrement.extrait', ['tiers' => $ligne['tiers']]) }}" wire:navigate
                                        class="rec-btn o" style="text-decoration:none; padding:4px 10px; font-size:11px;">
                                        Extrait
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align:center; color:#5A6472; padding:26px;">
                                    Aucune facture ouverte à cette date : rien à recouvrer.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-recouvrement::coquille>

<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Tracabilite\Services\QuiAAgi;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Un versement, et tout ce qu'il a soldé
|--------------------------------------------------------------------------
| **Demandé le 28/09** : « au niveau des détails, on ne doit pas afficher uniquement le
| détail de cette ligne ; dans la partie règlement global, on doit avoir le montant total
| donné, les lignes qui ont été touchées par le montant et combien par ligne, et non juste
| la ligne seule ».
|
| C'est la bonne remarque, et elle dit ce qui manquait : la référence `RG-280926-0001`
| n'était qu'une étiquette. Depuis le détail d'une créance, on voyait sa part du chèque
| sans jamais voir le chèque. Or la question qu'on pose au client six mois plus tard est
| celle du **versement** — « votre chèque de 5 000 000, qu'a-t-il soldé ? » — et non celle
| d'une de ses parts.
|
| **Le périmètre se lit sur l'identité du lecteur**, jamais sur la référence reçue : une
| référence tapée dans l'adresse ne doit pas ouvrir le versement d'une ville qu'on ne voit
| pas.
*/

state(['reference']);

$ecritures = computed(function () {
    $sites = PerimetreSites::idsRetenus(auth()->user(), null, null);

    return Encaissement::query()
        ->where('reglement_global', (string) $this->reference)
        /*
         * `withSum` et non seulement `with` : `Recouvrement::reste()` lit
         * `encaissements_sum_montant`, et sans cette somme il rendrait le montant entier —
         * toutes les factures paraîtraient « allégées » alors qu'elles sont soldées.
         */
        ->with(['facture' => fn ($q) => $q->withSum('encaissements', 'montant')->with('site.ville')])
        ->orderBy('id')
        ->get()
        // Le versement se lit par ses factures : un encaissement dont la créance est hors
        // périmètre n'a pas à paraître, même si le reste du versement est visible.
        ->filter(fn (Encaissement $e) => $e->facture !== null
            && in_array((int) $e->facture->site_id, $sites, true) === false
                ? in_array((int) $e->site_id, $sites, true)
                : true)
        ->values();
});

$total = computed(fn () => (int) $this->ecritures->sum('montant'));

/** Le geste qui a produit ce versement : une seule date, un seul mode, un seul auteur. */
$entete = computed(function () {
    $premier = $this->ecritures->first();

    return [
        'date' => $premier?->date,
        'moyen' => $premier?->moyen,
        'reference' => $premier?->reference_origine,
        'tiers' => $premier?->client,
    ];
});

$traitants = computed(fn () => $this->ecritures->isEmpty()
    ? collect()
    : QuiAAgi::detailDe($this->ecritures->first()));

?>

<div>
    @php
        $intitule = 'padding:7px 10px; border-bottom:1px solid var(--th-ligne,#E2E0D8); color:#6B6E76; font-size:12px; text-transform:uppercase; letter-spacing:.4px; width:42%; vertical-align:top;';
        $cellule = 'padding:7px 10px; border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;';
    @endphp

    @if ($this->ecritures->isEmpty())
        <x-carte-section titre="Règlement introuvable" icone="atelier" couleur="#C8102E">
            <p style="margin:0 0 14px; color:#4B4E55;">
                Aucun versement ne porte la référence « {{ $this->reference }} », ou il relève d'un
                lieu qui n'est pas dans votre périmètre.
            </p>
            <a href="{{ route('impayes') }}" wire:navigate class="bouton bouton-secondaire">← Retour à l'état des impayés</a>
        </x-carte-section>
    @else
        <x-titre-ecran :titre="'Règlement global '.$this->reference"
            :sous-titre="($this->entete['tiers'] ?? 'Tiers inconnu').' — un seul versement, '.$this->ecritures->count().' facture(s) soldée(s) ou allégée(s).'">
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('impayes') }}" wire:navigate class="bouton bouton-secondaire">← État des impayés</a>
                @if ($this->entete['tiers'])
                    <a href="{{ route('recouvrement.extrait', ['tiers' => $this->entete['tiers']]) }}"
                        wire:navigate class="bouton bouton-secondaire">Extrait de compte du tiers</a>
                @endif
            </div>
        </x-titre-ecran>

        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Montant versé" :value="ae($this->total)"
                :sub="$this->ecritures->count().' imputation(s)'" />
            <x-kpi-card label="Date du versement"
                :value="$this->entete['date']?->format('d/m/Y') ?? '—'" />
            <x-kpi-card label="Mode" :value="$this->entete['moyen'] ?? '—'"
                :sub="$this->entete['reference'] ?: null" />
            {{-- Ce qui reste dû sur les factures touchées : le versement les a allégées,
                 il ne les a pas forcément soldées. C'est le chiffre qu'on cherche quand on
                 rappelle le client. --}}
            <x-kpi-card label="Reste dû sur ces factures"
                :value="ae($this->ecritures->sum(fn ($e) => $e->facture ? Recouvrement::reste($e->facture) : 0))"
                :accent="$this->ecritures->sum(fn ($e) => $e->facture ? Recouvrement::reste($e->facture) : 0) > 0" />
        </div>

        {{-- --------------------------------------------------------- la ventilation --}}
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">
                Ce que le versement a payé, facture par facture
            </h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                La répartition suit une règle unique&nbsp;: <b>la plus ancienne d'abord, jusqu'à
                épuisement</b>. C'est la convention comptable, et c'est la seule qui serve le
                recouvrement — elle fait tomber les créances qui déclenchent les niveaux de relance.
                Au prorata, aucune ne sortirait de la balance âgée.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N° facture</th>
                            <th>Date</th>
                            <th>Atelier</th>
                            <th style="text-align:right;">Montant de la facture</th>
                            <th style="text-align:right;">Imputé par ce versement</th>
                            <th style="text-align:right;">Reste dû</th>
                            <th>État</th>
                            <th class="colonne-collee"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->ecritures as $ecriture)
                            @php
                                $facture = $ecriture->facture;
                                $reste = $facture ? Recouvrement::reste($facture) : 0;
                            @endphp
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:600;">
                                    {{ $facture?->n_facture ?: ($facture?->numero ?? '—') }}
                                </td>
                                <td style="white-space:nowrap;">{{ $facture?->date?->format('d/m/Y') ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $facture?->site?->nom ?? '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">
                                    {{ ae((int) ($facture->montant ?? 0)) }}
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; color:#2563EB;">
                                    {{ ae((int) $ecriture->montant) }}
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $reste >= Recouvrement::SEUIL_SOLDE ? '#C8102E' : '#6B6E76' }};">
                                    {{ ae($reste) }}
                                </td>
                                <td>
                                    @if ($reste < Recouvrement::SEUIL_SOLDE)
                                        <span style="color:#0E9F6E; font-weight:700;">Soldée</span>
                                    @else
                                        <span style="color:#B87A00;">Allégée</span>
                                    @endif
                                </td>
                                <td class="colonne-collee" style="text-align:right;">
                                    @if ($facture)
                                        <a href="{{ route('impayes.detail', $facture->id) }}" wire:navigate
                                            class="bouton bouton-secondaire"
                                            style="padding:4px 10px; font-size:12px; text-decoration:none;">Détail</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--th-ink,#191B20); font-weight:700;">
                            <td colspan="4" style="text-align:right;">Total versé</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#2563EB;">
                                {{ ae($this->total) }}
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- --------------------------------------------------------- qui l'a saisi --}}
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Qui l'a enregistré</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Personne</th><th>Code de saisie</th><th>Fonction</th><th>Dates d'intervention</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->traitants as $personne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:600;">{{ $personne['nom'] }}</td>
                                <td style="font-family:ui-monospace,Consolas,monospace; font-size:12.5px;">{{ $personne['code'] ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $personne['fonction'] ?? '—' }}</td>
                                <td style="font-size:12.5px;">
                                    @foreach (collect($personne['dates'])->sortDesc()->take(6) as $date)
                                        <span style="display:inline-block; background:#F4F2EC; border-radius:4px;
                                                     padding:2px 7px; margin:0 4px 4px 0; white-space:nowrap;">
                                            {{ $date?->format('d/m/Y H:i') }}
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune trace nominative sur ce versement." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

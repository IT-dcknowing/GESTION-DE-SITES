<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\GrandLivreFournisseur;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Le grand livre fournisseur — 23/10
|--------------------------------------------------------------------------
| « Comme l'extrait de compte du recouvrement, pour les fournisseurs : on recherche un tiers, et
| son extrait de compte, à travers son nom. » Les lignes et leur solde viennent de
| `GrandLivreFournisseur`, qui lit le suivi : facture au crédit, règlements au débit.
*/

state(['fournisseur' => ''])->url(except: '');
state(['du' => '', 'au' => '']);
state(['page' => 1]);

mount(function () {
    $this->du = $this->du ?: now()->startOfYear()->format('Y-m-d');
    $this->au = $this->au ?: now()->format('Y-m-d');
});

$updatedFournisseur = function () { $this->page = 1; };
$updatedDu = function () { $this->page = 1; };
$updatedAu = function () { $this->page = 1; };

$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), ''));

$fournisseurs = computed(fn () => GrandLivreFournisseur::fournisseurs($this->idsVilles));

$compte = computed(function () {
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->du) ? $this->du : '1900-01-01';
    $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->au) ? $this->au : '2999-12-31';

    return trim($this->fournisseur) === ''
        ? null
        : GrandLivreFournisseur::pour($this->fournisseur, $this->idsVilles, $du, $au);
});

?>

<div>
    <x-titre-ecran titre="Grand livre fournisseur"
        sous-titre="L'extrait de compte d'un fournisseur : ses factures au crédit, ses règlements au débit, et le solde ligne à ligne.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Charges fournisseurs</a>
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance fournisseurs</a>
        </div>
    </x-titre-ecran>

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:1 1 320px; max-width:460px;">
                <x-select-cherchable id="gl-fournisseur" label="Fournisseur" model="fournisseur" :valeur="$fournisseur"
                    :options="$this->fournisseurs" vide="— choisir le fournisseur —" placeholder="Rechercher par le nom…" :seuil="0" />
            </div>
            <x-champ label="Du" model="du" type="date" :live="true" width="155" />
            <x-champ label="Au" model="au" type="date" :live="true" width="155" />
        </div>
    </div>

    @if ($this->compte === null)
        <div class="carte"><p style="margin:0; font-size:13px; color:#6B6E76;">Choisissez un fournisseur pour lire son compte.</p></div>
    @else
        @php $c = $this->compte; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Report au {{ \Illuminate\Support\Carbon::parse($du)->format('d/m/Y') }}" :value="ae($c['report'])" sub="Solde des opérations antérieures" />
            <x-kpi-card label="Facturé sur la période" :value="ae($c['credit'])" sub="Au crédit" />
            <x-kpi-card label="Réglé sur la période" :value="ae($c['debit'])" couleur="#0E9F6E" sub="Au débit" />
            <x-kpi-card label="Solde dû" :value="ae($c['solde'])" :accent="$c['solde'] > 0" sub="Ce que nous devons encore à ce fournisseur" />
        </div>

        <div class="carte">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Date</th><th>N° pièce</th><th>Libellé</th>
                            <th style="text-align:right;">Débit (réglé)</th><th style="text-align:right;">Crédit (facturé)</th><th style="text-align:right;">Solde</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr style="color:#6B6E76;"><td colspan="5"><i>Report</i></td><td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($c['report']) }}</td><td></td></tr>
                        @forelse ($c['lignes']->forPage($page, 50) as $l)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ $l['date'] ? \Illuminate\Support\Carbon::parse($l['date'])->format('d/m/Y') : '—' }}</td>
                                <td>{{ $l['numero'] ?: '—' }}</td>
                                <td>
                                    {{ $l['libelle'] }}
                                    @if ($l['caisse'])
                                        <div style="font-size:11px;"><a href="{{ route('fournisseurs.decaissements', ['sortie' => $l['caisse']]) }}" wire:navigate>payé par la caisse ›</a></div>
                                    @endif
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ $l['debit'] != 0 ? ae($l['debit']) : '' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $l['credit'] != 0 ? ae($l['credit']) : '' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($l['solde']) }}</td>
                                <td><a href="{{ route('fournisseurs.piece', $l['piece']) }}" wire:navigate style="font-size:12px;">Détails</a></td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune opération sur cette période pour ce fournisseur." />
                        @endforelse
                    </tbody>
                    @if ($c['lignes']->isNotEmpty())
                        <tfoot>
                            <tr style="font-weight:800; border-top:2px solid #191B20;">
                                <td colspan="3">Total de la période</td>
                                <td style="text-align:right;">{{ ae($c['debit']) }}</td>
                                <td style="text-align:right;">{{ ae($c['credit']) }}</td>
                                <td style="text-align:right;">{{ ae($c['solde']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <x-pagination :page="$page" :total="$c['lignes']->count()" prop="page" :par-page="50" />
        </div>
    @endif
</div>

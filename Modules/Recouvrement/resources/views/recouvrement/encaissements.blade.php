<?php

use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Journal des encaissements — ce qui est rentré, et par quel canal
|--------------------------------------------------------------------------
| La synthèse par mode n'est pas une curiosité : c'est elle qui alimente le
| rapprochement bancaire (BGFI, BDA), le contrôle de caisse pour les espèces, et la
| réconciliation des relevés Orange Money et Wave. Un total global ne se rapproche
| de rien — un relevé se pointe banque par banque.
|
| Ne sont listés que les règlements adossés à une facture : ce sont les seuls qui
| soldent une créance. Les encaissements hors facture relèvent de la caisse, et
| l'écran de comptabilité les montre déjà.
*/

state([
    'du' => fn () => now()->startOfMonth()->toDateString(),
    'au' => fn () => now()->toDateString(),
    'recherche' => '',
    'page' => 1,
]);

$periode = computed(fn () => [
    Recouvrement::arrete($this->du),
    Recouvrement::arrete($this->au)->endOfDay(),
]);

$encaissements = computed(function () {
    $recherche = trim($this->recherche);
    [$du, $au] = $this->periode;

    return Encaissement::query()
        ->whereNotNull('facture_id')
        ->whereBetween('date', [$du, $au])
        ->when($recherche !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('client', 'like', "%$recherche%")
            ->orWhere('reference_origine', 'like', "%$recherche%")))
        ->with(['facture:id,n_facture', 'site:id,nom'])
        ->orderByDesc('date')->orderByDesc('id')
        ->get();
});

$auteurs = computed(fn () => \App\Models\User::whereIn('id', $this->encaissements->pluck('cree_par')->filter()->unique())
    ->pluck('name', 'id'));

/**
 * Ventilation par mode. Les modes du référentiel sont listés même à zéro : une ligne
 * absente se lit comme un oubli, une ligne à zéro se lit comme une information.
 */
$parMode = computed(function () {
    $reels = $this->encaissements->groupBy('moyen');

    $modes = collect(array_keys(Referentiel::options(Referentiel::MODE_RECOUVREMENT)))
        ->merge($reels->keys())
        ->unique()->values();

    return $modes->map(fn (string $mode) => [
        'mode' => $mode,
        'montant' => (int) ($reels[$mode] ?? collect())->sum('montant'),
        'nombre' => ($reels[$mode] ?? collect())->count(),
    ])->sortByDesc('montant')->values();
});

$updatedRecherche = fn () => $this->page = 1;
$updatedDu = fn () => $this->page = 1;
$updatedAu = fn () => $this->page = 1;

?>

<x-recouvrement::coquille page="encaissements">
    <x-slot:actions>
        <div style="background:#fff; border:1px solid #E3E0D8; border-radius:10px; padding:8px 12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <label style="font-size:10.5px; text-transform:uppercase; letter-spacing:.7px; color:#5A6472; font-weight:700;">Du</label>
            <input type="date" wire:model.live="du" value="{{ $du }}"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 8px; font-size:13px;">
            <label style="font-size:10.5px; text-transform:uppercase; letter-spacing:.7px; color:#5A6472; font-weight:700;">au</label>
            <input type="date" wire:model.live="au" value="{{ $au }}"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 8px; font-size:13px;">
            <input type="search" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}" placeholder="Tiers ou référence…"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px; min-width:180px;">
        </div>
    
        {{-- Le fichier emporté contient exactement ce que l'écran montre :
             mêmes filtres, même arrêté, même ligne de totaux. --}}
        <x-telecharger route="recouvrement.telecharger"
            :parametres="['document' => 'encaissements', 'du' => $du, 'au' => $au]" />
    </x-slot:actions>

    <div class="rec-g2" style="align-items:start;">
        <div class="rec-carte">
            <h2>
                Journal des encaissements
                <span class="chip">{{ $this->encaissements->count() }} opération(s)</span>
            </h2>

            <div class="rec-tbl-wrap" style="max-height:520px;">
                <table class="rec-tbl">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tiers</th>
                            <th>Facture</th>
                            <th>Mode</th>
                            <th class="num">Montant</th>
                            <th>Référence</th>
                            <th>Par</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->encaissements->forPage($page, 20) as $encaissement)
                            <tr wire:key="enc-{{ $encaissement->id }}">
                                <td>{{ $encaissement->date->format('d/m/Y') }}</td>
                                <td><b>{{ $encaissement->client }}</b></td>
                                <td>N° {{ $encaissement->facture?->n_facture ?? '—' }}</td>
                                <td>{{ $encaissement->moyen }}</td>
                                <td class="num"><b>{{ number_format((int) $encaissement->montant, 0, ',', ' ') }}</b></td>
                                <td>{{ $encaissement->reference_origine }}</td>
                                <td>{{ $this->auteurs[$encaissement->cree_par] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" style="text-align:center; color:#5A6472; padding:26px;">
                                    Aucun encaissement sur cette période.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$page" :total="$this->encaissements->count()" prop="page" :par-page="20" />
        </div>

        <div class="rec-carte">
            <h2>Synthèse par mode</h2>

            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Mode</th>
                        <th class="num">Montant</th>
                        <th class="num">Nb</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->parMode as $ligne)
                        <tr>
                            <td>{{ $ligne['mode'] }}</td>
                            <td class="num">{{ $ligne['montant'] ? number_format($ligne['montant'], 0, ',', ' ') : '·' }}</td>
                            <td class="num">{{ $ligne['nombre'] ?: '·' }}</td>
                        </tr>
                    @endforeach
                    <tr class="tot">
                        <td>TOTAL</td>
                        <td class="num">{{ number_format((int) $this->encaissements->sum('montant'), 0, ',', ' ') }}</td>
                        <td class="num">{{ $this->encaissements->count() }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="rec-hint">
                Cette ventilation alimente le rapprochement bancaire (BGFI, BDA), le contrôle de caisse
                pour les espèces, et la réconciliation des relevés Orange Money / Wave. Un total global
                ne se rapproche de rien.
            </div>
        </div>
    </div>
</x-recouvrement::coquille>

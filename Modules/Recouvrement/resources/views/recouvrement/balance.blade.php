<?php

use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Balance âgée — l'encours vu par son âge
|--------------------------------------------------------------------------
| Le total dû ne dit presque rien. Ce qui commande l'action, c'est la répartition :
| deux cents millions à trente jours se recouvrent par téléphone, la même somme à
| deux cents jours se prépare avec un huissier.
|
| Rien ne se saisit ici. L'écran ne fait que lire les factures et leurs encaissements,
| avec le même calcul que partout ailleurs dans le module — c'est ce qui garantit que
| la balance et l'extrait de compte ne se contredisent jamais.
*/

/*
 * La période remplace la date libre. Trois appels séparés à `state()` et non un seul :
 * `->url(except: ...)` attend une chaîne, pas un tableau — un seul appel pour trois
 * propriétés ne compile pas.
 */
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

// Lié à l'adresse : un lien ordinaire filtre, la vue se transmet, et le téléchargement
// reprend les mêmes paramètres. `except` garde l'adresse propre tant qu'aucun filtre
// n'est posé — une barre d'adresse encombrée de paramètres vides ne se relit pas.
state(['recherche' => ''])->url(except: '');

/** La période regardée, et l'arrêté qu'elle commande — voir PeriodeDeTravail. */
$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

/*
 * Lues telles que la base les rend, sans en faire des objets : cet écran additionne les
 * créances ouvertes, il n'en affiche aucune ligne à ligne. Voir lignesOuvertes().
 */
$ouvertes = computed(fn () => Recouvrement::lignesOuvertes($this->arrete));

$lignes = computed(function () {
    $recherche = trim(mb_strtolower($this->recherche));

    return Recouvrement::parTiers($this->ouvertes, $this->arrete)
        ->when($recherche !== '', fn ($lignes) => $lignes
            ->filter(fn (array $l) => str_contains(mb_strtolower($l['tiers']), $recherche))
            ->values());
});

/** Totaux par tranche, calculés sur les lignes affichées : le pied doit refaire le corps. */
$totaux = computed(function () {
    $totaux = array_fill(0, count(Recouvrement::TRANCHES), 0);

    foreach ($this->lignes as $ligne) {
        foreach ($ligne['tranches'] as $index => $montant) {
            $totaux[$index] += $montant;
        }
    }

    return $totaux;
});

?>

<x-recouvrement::coquille page="balance">
    <x-slot:actions>
        <x-recouvrement::periode route="recouvrement.balance" :periode="$this->periode"
            :recherche="$recherche" placeholder="Filtrer un tiers…" />

        <x-telecharger route="recouvrement.telecharger"
            :parametres="['document' => 'balance', 'arrete' => $this->periode->arreteIso()]" />
    </x-slot:actions>

    <div class="rec-carte">
        <h2>
            Balance âgée par tiers
            <span class="chip">{{ $this->lignes->count() }} tiers · arrêtée au {{ $this->arrete->format('d/m/Y') }}</span>
        </h2>

        <div class="rec-tbl-wrap" style="max-height:560px;">
            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Tiers</th>
                        @foreach (Recouvrement::TRANCHES as $tranche)
                            <th class="num">{{ $tranche['libelle'] }}</th>
                        @endforeach
                        <th class="num">Total</th>
                        <th>Niveau maximal</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $ligne)
                        <tr>
                            <td><b>{{ $ligne['tiers'] }}</b></td>
                            @foreach ($ligne['tranches'] as $montant)
                                {{-- Un point plutôt qu'un zéro : dans une grille de cinq colonnes,
                                     les zéros font du bruit et cachent les cases qui portent
                                     réellement quelque chose. --}}
                                <td class="num">{{ $montant ? number_format($montant, 0, ',', ' ') : '·' }}</td>
                            @endforeach
                            <td class="num"><b>{{ number_format($ligne['reste'], 0, ',', ' ') }}</b></td>
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
                            <td colspan="{{ count(Recouvrement::TRANCHES) + 4 }}"
                                style="text-align:center; color:#5A6472; padding:26px;">
                                Aucun encours à cette date.
                            </td>
                        </tr>
                    @endforelse

                    @if ($this->lignes->isNotEmpty())
                        <tr class="tot">
                            <td>TOTAL</td>
                            @foreach ($this->totaux as $montant)
                                <td class="num">{{ number_format($montant, 0, ',', ' ') }}</td>
                            @endforeach
                            <td class="num">{{ number_format(array_sum($this->totaux), 0, ',', ' ') }}</td>
                            <td></td>
                            <td class="no-print"></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <div class="rec-hint">
            L'ancienneté se compte depuis la date de la facture jusqu'à la date d'arrêté. Le niveau
            retenu pour un tiers est celui de sa facture la plus ancienne : on ne relance pas un assureur
            au niveau moyen de son compte, mais au niveau de la créance qui commande la procédure.
        </div>
    </div>
</x-recouvrement::coquille>

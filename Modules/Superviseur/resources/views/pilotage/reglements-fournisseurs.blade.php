<?php

use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Règlements fournisseurs — ce que la comptabilité a payé
|--------------------------------------------------------------------------
| Chaque paiement enregistré par le logiciel comptable, avec son code, son mode et son
| montant. C'est ce qu'on cite quand un fournisseur conteste avoir été payé.
|
| **Le fichier n'a pas d'exercice, et cet écran non plus par défaut.** Le propriétaire l'a
| précisé le 22/09 : « l'import n'a pas de date, pour lui c'est de façon unique, toutes
| années ». L'export est global ; on affiche donc tout, et les deux bornes ne sont là que
| pour celui qui veut réduire lui-même ce qu'il regarde.
|
| Écran en **lecture seule** : rien ne s'y saisit, tout vient du fichier déposé.
*/

state(['recherche' => ''])->url(except: '');
state(['dateDebut' => ''])->url(except: '');
state(['dateFin' => ''])->url(except: '');
state(['page' => 1]);

$updatedRecherche = function () { $this->page = 1; };
$updatedDateDebut = function () { $this->page = 1; };
$updatedDateFin = function () { $this->page = 1; };

$lignes = computed(function () {
    $requete = ReglementFournisseur::query()->where('entreprise_id', auth()->user()->entreprise_id);

    if (trim($this->recherche) !== '') {
        $terme = '%'.trim($this->recherche).'%';
        $requete->where(fn ($sous) => $sous
            ->where('fournisseur', 'like', $terme)
            ->orWhere('code_reglement', 'like', $terme)
            ->orWhere('mode_reglement', 'like', $terme));
    }

    // Les bornes viennent de l'adresse : relues par le même lecteur que partout ailleurs,
    // et sans effet quand elles ne sont pas des dates.
    if ($depuis = PeriodeCalculateur::borne($this->dateDebut, false)) {
        $requete->whereDate('date_reglement', '>=', $depuis->toDateString());
    }

    if ($jusqua = PeriodeCalculateur::borne($this->dateFin, true)) {
        $requete->whereDate('date_reglement', '<=', $jusqua->toDateString());
    }

    return $requete->orderByDesc('date_reglement')->orderByDesc('id')->get();
});

/** Ce que chaque mode de règlement pèse — la question qu'on pose à cette liste. */
$parMode = computed(fn () => $this->lignes
    ->groupBy(fn ($l) => $l->mode_reglement ?: 'Non précisé')
    ->map(fn ($groupe) => ['lignes' => $groupe->count(), 'montant' => (int) $groupe->sum('montant')])
    ->sortByDesc('montant'));

$totaux = computed(fn () => [
    'lignes' => $this->lignes->count(),
    'montant' => (int) $this->lignes->sum('montant'),
    'fournisseurs' => $this->lignes->pluck('fournisseur')->unique()->count(),
]);

?>

<div>
    <x-titre-ecran titre="Règlements fournisseurs"
        sous-titre="Les paiements enregistrés par la comptabilité. Le fichier est global : toutes les années y sont, et les deux bornes ne servent qu'à réduire ce qu'on regarde.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Suivi fournisseur</a>
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance</a>
        </div>
    </x-titre-ecran>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:12px; margin-bottom:18px;">
        <x-kpi-card label="Règlements" :value="$this->totaux['lignes']" />
        <x-kpi-card label="Montant total" :value="ae($this->totaux['montant'])" />
        <x-kpi-card label="Fournisseurs payés" :value="$this->totaux['fournisseurs']" />
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <x-champ label="Rechercher" model="recherche" :live="true"
                placeholder="Fournisseur, code de règlement, mode…" />
            <x-champ label="Du" model="dateDebut" type="date" :live="true" width="150" />
            <x-champ label="au" model="dateFin" type="date" :live="true" width="150" />
        </div>
    </div>

    @if ($this->parMode->isNotEmpty())
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Par mode de règlement</h3>
            @foreach ($this->parMode as $mode => $poste)
                <div style="display:flex; justify-content:space-between; gap:12px; padding:5px 0;
                            border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:13px;">
                    <span>{{ $mode }}
                        <span style="color:#6B6E76; font-size:11px;">· {{ $poste['lignes'] }} règlement(s)</span>
                    </span>
                    <span style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($poste['montant']) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
            Règlements ({{ $this->totaux['lignes'] }})
        </h3>

        @if ($this->lignes->isEmpty() && $recherche === '' && $dateDebut === '' && $dateFin === '')
            <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                Aucun règlement déposé. Le fichier se dépose depuis le module <b>Import</b>,
                sous le format « Règlements fournisseurs ».
            </p>
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Code de règlement</th>
                            <th>Fournisseur</th>
                            <th>Mode</th>
                            <th class="colonne-collee" style="text-align:right;">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes->forPage($page, 25) as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ $ligne->date_reglement?->format('d/m/Y') ?? '—' }}</td>
                                <td style="font-weight:700;">{{ $ligne->code_reglement }}</td>
                                <td>{{ $ligne->fournisseur }}</td>
                                <td style="color:#6B6E76;">{{ $ligne->mode_reglement ?: '—' }}</td>
                                <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">
                                    {{ ae($ligne->montant) }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Aucun règlement ne correspond à ce filtre." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="25" />
        @endif
    </div>
</div>

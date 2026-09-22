<?php

use Modules\Noyau\Imports\Modeles\SoldeFournisseur;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Balance fournisseurs — ce que la comptabilité tient
|--------------------------------------------------------------------------
| **Deux sources, et l'écart entre elles.** L'écran *Fournisseurs* montre le suivi tenu à
| la main : ce que l'atelier croit devoir, pièce par pièce. Celui-ci montre la balance
| exportée du logiciel comptable : ce qui est enregistré. C'est leur écart qu'on cherche
| quand un fournisseur réclame.
|
| **Le solde est gardé tel que le logiciel l'annonce, et recalculé à côté.** Demandé par le
| propriétaire le 22/09 : « on garde le total estimé du logiciel et on compare après calcul,
| comme le fait la caisse avec son KPI ». Débit moins crédit ne redonne pas toujours la
| colonne SOLDE — lettrage, reports, écritures d'à-nouveau. Écraser l'un par l'autre
| effacerait l'écart ; les afficher côte à côte le montre.
|
| Cette page est en **lecture seule** : rien ne s'y saisit, tout vient du fichier déposé.
*/

state(['recherche' => ''])->url(except: '');
state(['ecartsSeulement' => false])->url(except: false);
state(['page' => 1]);

$updatedRecherche = function () { $this->page = 1; };
$updatedEcartsSeulement = function () { $this->page = 1; };

/**
 * Les soldes, chacun avec son recalcul et son écart.
 *
 * L'écart se calcule à la lecture et n'est jamais une colonne stockée — même règle que le
 * reste à payer d'une créance : une colonne peut se tromper, une soustraction faite à la
 * lecture ne peut pas.
 */
$lignes = computed(function () {
    $requete = SoldeFournisseur::query()->where('entreprise_id', auth()->user()->entreprise_id);

    if (trim($this->recherche) !== '') {
        $requete->where('fournisseur', 'like', '%'.trim($this->recherche).'%');
    }

    $lignes = $requete->orderByDesc('solde')->get()->map(function ($ligne) {
        $recalcule = (int) $ligne->credit - (int) $ligne->debit;

        return [
            'ligne' => $ligne,
            'recalcule' => $recalcule,
            'ecart' => (int) $ligne->solde - $recalcule,
        ];
    });

    if ($this->ecartsSeulement) {
        $lignes = $lignes->filter(fn ($l) => $l['ecart'] !== 0)->values();
    }

    return $lignes;
});

$totaux = computed(fn () => [
    'fournisseurs' => $this->lignes->count(),
    'debit' => (int) $this->lignes->sum(fn ($l) => $l['ligne']->debit),
    'credit' => (int) $this->lignes->sum(fn ($l) => $l['ligne']->credit),
    'solde' => (int) $this->lignes->sum(fn ($l) => $l['ligne']->solde),
    'recalcule' => (int) $this->lignes->sum(fn ($l) => $l['recalcule']),
    'enEcart' => $this->lignes->filter(fn ($l) => $l['ecart'] !== 0)->count(),
]);

?>

<div>
    <x-titre-ecran titre="Balance fournisseurs"
        sous-titre="Les comptes fournisseurs tels que le logiciel comptable les exporte. Le solde annoncé est conservé, et confronté au recalcul crédit − débit.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Suivi fournisseur</a>
            <a href="{{ route('referentiel-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Référentiel</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements</a>
        </div>
    </x-titre-ecran>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:12px; margin-bottom:18px;">
        <x-kpi-card label="Fournisseurs" :value="$this->totaux['fournisseurs']" />
        <x-kpi-card label="Total débit" :value="ae($this->totaux['debit'])" />
        <x-kpi-card label="Total crédit" :value="ae($this->totaux['credit'])" />
        <x-kpi-card label="Solde annoncé" :value="ae($this->totaux['solde'])" sub="Tel que le logiciel le donne" />
        <x-kpi-card label="Solde recalculé" :value="ae($this->totaux['recalcule'])" sub="Crédit − débit"
            :couleur="$this->totaux['recalcule'] === $this->totaux['solde'] ? '#0E9F6E' : '#D97706'" />
        <x-kpi-card label="Comptes en écart" :value="$this->totaux['enEcart']"
            sub="Annoncé ≠ recalculé" :couleur="$this->totaux['enEcart'] > 0 ? '#C8102E' : '#0E9F6E'" />
    </div>

    <div class="carte">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
            <x-champ label="Rechercher un fournisseur" model="recherche" :live="true" placeholder="Nom du fournisseur…" />
            <x-champ label="Comptes en écart seulement" model="ecartsSeulement" type="checkbox" live="true" />
        </div>

        @if ($this->lignes->isEmpty() && $recherche === '' && ! $ecartsSeulement)
            <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                Aucune balance déposée. Le fichier se dépose depuis le module <b>Import</b>,
                sous le format « Balance fournisseurs ».
            </p>
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th style="text-align:right;">Débit</th>
                            <th style="text-align:right;">Crédit</th>
                            <th style="text-align:right;">Solde annoncé</th>
                            <th style="text-align:right;">Recalculé</th>
                            <th class="colonne-collee" style="text-align:right;">Écart</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes->forPage($page, 25) as $l)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $l['ligne']->fournisseur }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($l['ligne']->debit) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($l['ligne']->credit) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($l['ligne']->solde) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">{{ ae($l['recalcule']) }}</td>
                                <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $l['ecart'] === 0 ? '#0E9F6E' : '#C8102E' }};">
                                    {{ $l['ecart'] === 0 ? '—' : ae($l['ecart']) }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucun compte ne correspond à ce filtre." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="25" />

            <p style="margin:14px 0 0; font-size:12.5px; color:#6B6E76;">
                Un écart n'est pas une erreur en soi : un lettrage, un report ou une écriture
                d'à-nouveau suffit à l'expliquer. C'est pour qu'il se voie plutôt qu'il ne
                disparaisse que le solde annoncé n'est jamais écrasé par le recalcul.
            </p>
        @endif
    </div>
</div>

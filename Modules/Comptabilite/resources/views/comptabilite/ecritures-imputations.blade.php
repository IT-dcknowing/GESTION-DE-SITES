<?php

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Comptabilite\Services\AccesALaComptabilite;
use Modules\Noyau\Comptabilite\Services\PropositionsDImputation;

use function Livewire\Volt\{computed, mount, protect};

/*
|--------------------------------------------------------------------------
| Écritures comptables — les propositions de l'IA — 24/11, page à part le 25/11
|--------------------------------------------------------------------------
| Les sorties et charges que la règle range en « Autres » (658000). L'IA propose une nature du plan
| de l'entreprise, le serveur rejette toute autre réponse (`PropositionsDImputation`), et seule une
| proposition validée par une personne change l'écriture ; les montants ne bougent jamais.
|
| Habilitation `comptabilite.imputer_ia` (l'écran IA du Super admin cite ce nom), revérifiée dans
| chaque action : un bouton caché n'a jamais autorisé personne.
*/

mount(function () {
    $this->garder();
});

$garder = protect(function () {
    AccesALaComptabilite::exiger('imputer_ia');
});

$entreprise = protect(fn () => (int) auth()->user()->entreprise_id);

/** Les propositions en attente de décision, avec l'opération qu'elles touchent. */
$propositions = computed(fn () => PropositionsDImputation::disponible()
    ? DB::table('imputations_comptables as i')
        ->join('operations_comptables as o', fn ($j) => $j->on('o.source', '=', 'i.source')->on('o.source_id', '=', 'i.source_id')->on('o.entreprise_id', '=', 'i.entreprise_id'))
        ->where('i.entreprise_id', $this->entreprise())->where('i.statut', 'proposee')->where('o.statut', '!=', 'contrepassee')
        ->orderBy('o.date_operation')->limit(50)
        ->get(['i.id', 'i.cle_compte', 'i.confiance', 'i.raison', 'o.date_operation', 'o.libelle', 'o.total_debit'])
    : collect());

$natures = computed(fn () => PropositionsDImputation::disponible() ? (new PropositionsDImputation($this->entreprise()))->natures() : []);

/** L'IA propose une nature pour les charges restées « Autres » — elle n'écrit rien. */
$proposerParIA = function () {
    $this->garder();
    try {
        $n = (new PropositionsDImputation($this->entreprise()))->proposer();
        $this->dispatch('annonce', ton: 'succes', texte: $n.' proposition(s) de l’IA, à valider ci-dessous.');
    } catch (\Throwable $e) {
        $this->dispatch('annonce', texte: $e->getMessage());
    }
    unset($this->propositions);
};

/** Une personne décide ; seule une imputation validée change l'écriture. */
$decider = function (int $id, bool $valider, ?string $cle = null) {
    $this->garder();
    try {
        (new PropositionsDImputation($this->entreprise()))->decider($id, $valider, $cle ?: null, (int) auth()->id());
    } catch (\RuntimeException $e) {
        $this->dispatch('annonce', texte: $e->getMessage());
    }
    unset($this->propositions);
};

?>

<div>
    <x-titre-ecran titre="Écritures comptables — propositions de l'IA"
        sous-titre="Les sorties et charges que la règle range en « Autres ». L'IA choisit une nature du plan ; rien ne change tant que vous n'avez pas validé, et les montants ne bougent jamais." />

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
        <a href="{{ route('ecritures.index') }}" wire:navigate class="bouton bouton-secondaire">← Écritures</a>
        <a href="{{ route('ecritures.parametrage') }}" wire:navigate class="bouton bouton-secondaire">Paramétrage des comptes</a>
    </div>

    @if (! PropositionsDImputation::disponible())
        <div class="carte"><p style="margin:0; color:#B45309;">La mise à jour du serveur n'est pas encore passée (migration du 24/11).</p></div>
    @else
        <x-carte-section titre="Charges non classées — l'IA propose, vous décidez">
            <button type="button" wire:click="proposerParIA" class="bouton bouton-secondaire" style="margin-bottom:8px;">Demander des propositions à l'IA</button>
            @if ($this->propositions->isNotEmpty())
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead><tr><th>Date</th><th>Libellé</th><th style="text-align:right;">Montant</th><th>Nature proposée</th><th>Confiance</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($this->propositions as $p)
                                <tr wire:key="prop-{{ $p->id }}" x-data="{ cle: @js($p->cle_compte) }">
                                    <td>{{ \Illuminate\Support\Carbon::parse($p->date_operation)->format('d/m/Y') }}</td>
                                    <td>{{ $p->libelle }}<div style="font-size:11.5px; color:#6B6E76;">{{ $p->raison }}</div></td>
                                    <td style="text-align:right;">{{ montant_nombre($p->total_debit) }}</td>
                                    <td><select x-model="cle" class="champ" style="width:240px;">
                                        @foreach ($this->natures as $cle => $n)<option value="{{ $cle }}">{{ $n['numero'] }} — {{ $n['libelle'] }}</option>@endforeach
                                    </select></td>
                                    <td>{{ $p->confiance }} %</td>
                                    <td style="white-space:nowrap;">
                                        <button type="button" class="bouton" style="padding:2px 8px; font-size:12px;" x-on:click="$wire.decider({{ $p->id }}, true, cle)">Valider</button>
                                        <button type="button" class="bouton bouton-secondaire" style="padding:2px 8px; font-size:12px;" wire:click="decider({{ $p->id }}, false)">Rejeter</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p style="font-size:12.5px; color:#6B6E76; margin:0;">Aucune proposition en attente.</p>
            @endif
        </x-carte-section>
    @endif
</div>

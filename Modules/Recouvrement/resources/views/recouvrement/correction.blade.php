<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Services\CorrectionDesTiers;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Correction des clients, sur les données du recouvrement — 28/10
|--------------------------------------------------------------------------
| « C'est l'inverse : la fusion reste dans l'état des impayés ; la correction part dans le
| recouvrement, car elle doit puiser dans les données du recouvrement et non dans celles des
| impayés — toujours avec la logique initiale de correction. »
|
| Les clients sont justes, mais des factures ne sont pas sous le bon : on choisit le client de
| départ parmi les tiers du recouvrement, on coche ses factures — ou toutes d'un bouton —, on
| choisit le client d'arrivée ; elles passent, rien n'est supprimé. Le nom est remplacé dans la
| colonne où il se lit (client, assureur, courtier, déposé chez). Voir `CorrectionDesTiers`.
|
| Ouverte à qui a le geste (Maintenance › Habilitations, « Correction des clients (recouvrement) »)
| — le gérant par défaut. Revérifié à chaque action : un bouton caché n'a jamais autorisé personne.
|
| Les cases ne sont jamais remises par `reset()` : un état Volt y retombe à null (panne du 28/10).
*/

state([
    'depart' => '',
    'arrivee' => '',
    'cochees' => [],
]);

mount(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'tiers_recouvrement', 'corriger'), 403);
});

$garder = protect(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'tiers_recouvrement', 'corriger'), 403);
});

$tiers = computed(fn () => CorrectionDesTiers::tiers());

/** Les noms pour la liste : « NOM — n facture(s) · reste ». */
$options = computed(fn () => $this->tiers->mapWithKeys(fn ($t) => [
    $t->nom => $t->nom.' — '.$t->factures.' facture(s) · reste '.ae($t->reste),
])->all());

/** L'arrivée ne propose jamais le départ. */
$optionsArrivee = computed(fn () => array_diff_key($this->options, [(string) $this->depart => true]));

$factures = computed(fn () => trim((string) $this->depart) === '' ? collect() : CorrectionDesTiers::facturesDe((string) $this->depart));

/** Les cases cochées, toujours en liste de chaînes — jamais null. */
$lesCochees = protect(fn (): array => array_values(array_map('strval', (array) ($this->cochees ?? []))));

$totalCoche = computed(fn () => montant_de($this->factures->whereIn('id', array_map('intval', $this->lesCochees()))->sum('montant')));

$updatedDepart = function () {
    $this->depart = (string) ($this->depart ?? '');
    $this->cochees = [];

    if ($this->arrivee === $this->depart) {
        $this->arrivee = '';
    }

    unset($this->factures, $this->optionsArrivee);
};

$updatedCochees = function () {
    $this->cochees = $this->lesCochees();
};

/** « Sélectionner toutes les factures du client » — ou les décocher toutes si elles le sont. */
$toutCocher = function () {
    $ids = $this->factures->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->cochees = count(array_intersect($ids, $this->lesCochees())) === count($ids) ? [] : $ids;
};

$corriger = function () {
    $this->garder();
    $this->resetValidation();

    try {
        $bilan = CorrectionDesTiers::corriger(auth()->user(), (string) $this->depart, (string) $this->arrivee, $this->lesCochees());
    } catch (\RuntimeException $e) {
        $this->addError('arrivee', $e->getMessage());

        return;
    }

    $texte = $bilan['deplacees'].' facture(s) passée(s) de « '.$this->depart.' » à « '.$this->arrivee.' ».';

    $this->depart = $bilan['restantes'] > 0 ? $this->depart : '';
    $this->arrivee = '';
    $this->cochees = [];
    unset($this->tiers, $this->options, $this->optionsArrivee, $this->factures);
    $this->dispatch('annonce', ton: 'succes', texte: $texte);
};

?>

<x-recouvrement::coquille page="correction" titre="Correction des clients"
    sous-titre="Des factures du recouvrement rangées sous le mauvais client : elles passent au bon, rien n'est supprimé.">
    @include('components.select-cherchable-ressources')

    <div class="rec-carte" style="margin-bottom:14px; border-left:3px solid #2563EB;">
        <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.55;">
            <b>Correction</b> : les deux clients sont justes, mais des factures ne sont pas sous le bon. Elles passent au client
            d'arrivée ; rien n'est supprimé. Le nom est remplacé là où il se lit sur la facture — client, assureur, courtier,
            déposé chez. Montants, dates et règlements restent ; chaque facture déplacée est tracée.
        </p>
        <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
            Un client écrit sous un nom faux, à faire disparaître ? C'est une <b>fusion</b>, depuis l'état des impayés.
        </p>
    </div>

    <div class="rec-carte" style="margin-bottom:14px;">
        <div class="rec-frm">
            <div class="rec-fld" style="grid-column:span 2; max-width:560px;">
                <x-select-cherchable id="correction-depart" label="Client de départ"
                    model="depart" :valeur="$depart" :options="$this->options" vide="— choisir le client de départ —"
                    placeholder="Rechercher un client…" :seuil="0" />
            </div>
        </div>
    </div>

    @if (trim((string) $depart) !== '')
        @php $cochees = $this->lesCochees(); @endphp
        <div class="rec-carte" style="margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                <h2 style="margin:0;">Factures de « {{ $depart }} » ({{ $this->factures->count() }})</h2>
                @if ($this->factures->isNotEmpty())
                    <button type="button" wire:click="toutCocher" class="rec-btn">
                        {{ count($cochees) === $this->factures->count() ? 'Tout décocher' : 'Sélectionner toutes les factures du client' }}
                    </button>
                @endif
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th style="width:34px;"></th><th>N° facture</th><th>Date</th><th>Le nom y est</th><th>Client</th>
                            <th style="text-align:right;">Montant</th><th style="text-align:right;">Encaissé</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures as $f)
                            <tr wire:key="ct-{{ $f->id }}">
                                <td><input type="checkbox" wire:model.live="cochees" value="{{ $f->id }}" aria-label="Choisir la facture {{ $f->n_facture }}"></td>
                                <td>{{ $f->n_facture ?: $f->numero }}@if ($f->est_avoir) <span style="font-size:11px; color:#B45309;">avoir</span>@endif</td>
                                <td>{{ $f->date?->format('d/m/Y') }}</td>
                                <td style="font-size:12px;">{{ collect($f->colonnes_du_tiers)->map(fn ($c) => CorrectionDesTiers::COLONNES[$c])->implode(', ') }}</td>
                                <td style="font-size:12px;">{{ $f->client ?: '—' }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->montant)) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->encaissements_sum_montant ?? 0)) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune facture du recouvrement ne porte ce nom." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
                {{ count($cochees) }} facture(s) cochée(s) · {{ ae($this->totalCoche) }}
            </p>
        </div>

        <div class="rec-carte">
            <div class="rec-frm">
                <div class="rec-fld" style="grid-column:span 2; max-width:560px;">
                    <x-select-cherchable id="correction-arrivee" label="Client d'arrivée"
                        model="arrivee" :valeur="$arrivee" :options="$this->optionsArrivee" vide="— choisir le client d'arrivée —"
                        placeholder="Rechercher un client…" :seuil="0" />
                    @error('arrivee') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="rec-actions">
                <button type="button" wire:click="corriger" class="rec-btn r" @disabled($cochees === [] || $arrivee === '')
                    wire:confirm="Déplacer les factures cochées vers le client d'arrivée ?">Corriger : déplacer les factures cochées</button>
            </div>
        </div>
    @endif
</x-recouvrement::coquille>

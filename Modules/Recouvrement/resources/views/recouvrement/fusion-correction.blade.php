<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Services\FusionCorrectionDesTiers;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Fusion / correction des clients, sur les données du recouvrement — 28/10, complétée le 02/11
|--------------------------------------------------------------------------
| « Ajoute les deux des deux côtés : fusion et correction, au niveau des impayés et du
| recouvrement » ; et « c'est la correction qui doit supprimer le nom de départ ».
|
| **Fusion** : des factures du client de départ passent au client d'arrivée ; rien n'est supprimé.
| **Correction** : le client de départ est un nom en erreur ; ses factures passent au bon nom, puis le
| nom faux disparaît dès qu'aucune facture ne le porte plus (relances, référentiel, code tiers et
| portefeuille suivent).
|
| Les clients sont ceux du recouvrement : les noms de ses factures dans les quatre colonnes où il lit
| un tiers (client, assureur, courtier, déposé chez). Le nom est remplacé dans la colonne où il se lit.
| Voir `FusionCorrectionDesTiers`.
|
| Ouverte à qui a le geste (Maintenance › Habilitations, « Fusion / correction des clients
| (recouvrement) ») — le gérant seul par défaut. Revérifié à chaque action.
|
| Les cases ne sont jamais remises par `reset()` : un état Volt y retombe à null (panne du 28/10).
*/

state([
    'section' => 'fusion',
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

$tiers = computed(fn () => FusionCorrectionDesTiers::tiers());

/** Les noms pour la liste : « NOM — n facture(s) · reste ». */
$options = computed(fn () => $this->tiers->mapWithKeys(fn ($t) => [
    $t->nom => $t->nom.' — '.$t->factures.' facture(s) · reste '.ae($t->reste)
        .($t->relances > 0 ? ' · '.$t->relances.' relance(s)' : ''),
])->all());

/** L'arrivée ne propose jamais le départ. */
$optionsArrivee = computed(fn () => array_diff_key($this->options, [(string) $this->depart => true]));

$factures = computed(fn () => trim((string) $this->depart) === '' ? collect() : FusionCorrectionDesTiers::facturesDe((string) $this->depart));

$relances = computed(fn () => trim((string) $this->depart) === '' ? collect() : FusionCorrectionDesTiers::relancesDe((string) $this->depart));

/** Les cases cochées, toujours en liste de chaînes — jamais null. */
$lesCochees = protect(fn (): array => array_values(array_map('strval', (array) ($this->cochees ?? []))));

$totalCoche = computed(fn () => montant_de($this->factures->whereIn('id', array_map('intval', $this->lesCochees()))->sum('montant')));

$vider = protect(function (bool $garderLeDepart = false) {
    $this->depart = $garderLeDepart ? (string) $this->depart : '';
    $this->arrivee = '';
    $this->cochees = [];
    unset($this->tiers, $this->options, $this->optionsArrivee, $this->factures, $this->relances);
});

$choisirSection = function (string $section) {
    $this->section = $section === 'correction' ? 'correction' : 'fusion';
    $this->vider();
    $this->resetValidation();
};

$updatedDepart = function () {
    $this->depart = (string) ($this->depart ?? '');
    $this->cochees = [];

    if ($this->arrivee === $this->depart) {
        $this->arrivee = '';
    }

    unset($this->factures, $this->relances, $this->optionsArrivee);
};

$updatedCochees = function () {
    $this->cochees = $this->lesCochees();
};

/** « Sélectionner toutes les factures du client » — ou les décocher toutes si elles le sont. */
$toutCocher = function () {
    $ids = $this->factures->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->cochees = count(array_intersect($ids, $this->lesCochees())) === count($ids) ? [] : $ids;
};

$executer = function () {
    $this->garder();
    $this->resetValidation();

    // La suppression du nom de départ n'appartient qu'à la correction (02/11).
    $corriger = $this->section === 'correction';

    try {
        $bilan = FusionCorrectionDesTiers::deplacer(auth()->user(), (string) $this->depart, (string) $this->arrivee,
            $this->lesCochees(), $corriger);
    } catch (\RuntimeException $e) {
        $this->addError('arrivee', $e->getMessage());

        return;
    }

    $texte = $bilan['deplacees'].' facture(s) passée(s) de « '.$this->depart.' » à « '.$this->arrivee.' ».';

    if ($corriger) {
        $texte .= $bilan['supprime']
            ? ' Le nom « '.$this->depart.' » est supprimé'.($bilan['relances'] > 0 ? ', ses '.$bilan['relances'].' relance(s) passent au bon client.' : '.')
            : ' Le nom « '.$this->depart.' » reste : des factures le portent encore. Corrigez-les aussi pour qu\'il disparaisse.';
    }

    $this->vider($bilan['restantes'] > 0);
    $this->dispatch('annonce', ton: 'succes', texte: $texte);
};

?>

<x-recouvrement::coquille page="fusion-correction" titre="Fusion / correction des clients"
    sous-titre="Sur les données du recouvrement : regrouper des factures sous un autre client, ou corriger un nom faux.">
    @include('components.select-cherchable-ressources')

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
        <button type="button" wire:click="choisirSection('fusion')" class="rec-btn {{ $section === 'fusion' ? 'r' : '' }}">Fusion</button>
        <button type="button" wire:click="choisirSection('correction')" class="rec-btn {{ $section === 'correction' ? 'r' : '' }}">Correction</button>
    </div>

    <div class="rec-carte" style="margin-bottom:14px; border-left:3px solid {{ $section === 'correction' ? '#C8102E' : '#2563EB' }};">
        <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.55;">
            @if ($section === 'fusion')
                <b>Fusion</b> : des factures du client de départ passent au client d'arrivée. Les deux clients restent ; rien n'est supprimé.
            @else
                <b>Correction</b> : le client de départ est un nom en erreur. Ses factures passent au bon client, puis le nom faux
                disparaît dès qu'aucune facture ne le porte plus — ses relances, son code et le portefeuille du commercial passent au bon client.
            @endif
            Le nom est remplacé là où il se lit sur la facture — client, assureur, courtier, déposé chez. Montants, dates et
            règlements restent ; chaque facture déplacée est tracée.
        </p>
        <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
            Les mêmes gestes existent sur les créances de l'état des impayés.
        </p>
    </div>

    <div class="rec-carte" style="margin-bottom:14px;">
        <div class="rec-frm">
            <div class="rec-fld" style="grid-column:span 2; max-width:560px;">
                <x-select-cherchable id="recouvrement-depart" :label="$section === 'correction' ? 'Client à corriger (le nom faux)' : 'Client de départ'"
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
                            <tr wire:key="fr-{{ $f->id }}">
                                <td><input type="checkbox" wire:model.live="cochees" value="{{ $f->id }}" aria-label="Choisir la facture {{ $f->n_facture }}"></td>
                                <td>{{ $f->n_facture ?: $f->numero }}@if ($f->est_avoir) <span style="font-size:11px; color:#B45309;">avoir</span>@endif</td>
                                <td>{{ $f->date?->format('d/m/Y') }}</td>
                                <td style="font-size:12px;">{{ collect($f->colonnes_du_tiers)->map(fn ($c) => FusionCorrectionDesTiers::COLONNES[$c])->implode(', ') }}</td>
                                <td style="font-size:12px;">{{ $f->client ?: '—' }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->montant)) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->encaissements_sum_montant ?? 0)) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" :texte="$section === 'correction'
                                ? 'Aucune facture du recouvrement ne porte ce nom : « Corriger » efface le nom et reporte ses relances au bon client.'
                                : 'Aucune facture du recouvrement ne porte ce nom.'" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
                {{ count($cochees) }} facture(s) cochée(s) · {{ ae($this->totalCoche) }}
                @if ($section === 'correction' && $this->relances->isNotEmpty())
                    · {{ $this->relances->count() }} relance(s) sous ce nom, qui passeront au bon client si le nom est supprimé
                @endif
            </p>
        </div>

        <div class="rec-carte">
            <div class="rec-frm">
                <div class="rec-fld" style="grid-column:span 2; max-width:560px;">
                    <x-select-cherchable id="recouvrement-arrivee" :label="$section === 'correction' ? 'Le bon client' : 'Client d\'arrivée'"
                        model="arrivee" :valeur="$arrivee" :options="$this->optionsArrivee" vide="— choisir le client d'arrivée —"
                        placeholder="Rechercher un client…" :seuil="0" />
                    @error('arrivee') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="rec-actions">
                @if ($section === 'fusion')
                    <button type="button" wire:click="executer" class="rec-btn r" @disabled($cochees === [] || $arrivee === '')
                        wire:confirm="Passer les factures cochées au client d'arrivée ? Rien n'est supprimé.">Fusionner les factures cochées</button>
                @else
                    <button type="button" wire:click="executer" class="rec-btn r" style="background:#7A0A1C;" @disabled($arrivee === '' || ($cochees === [] && $this->factures->isNotEmpty()))
                        wire:confirm="Passer les factures cochées au bon client, puis supprimer le nom faux s'il ne porte plus aucune facture ?">
                        Corriger et supprimer le nom faux
                    </button>
                @endif
            </div>
        </div>
    @endif
</x-recouvrement::coquille>

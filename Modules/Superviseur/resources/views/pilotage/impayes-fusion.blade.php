<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\FusionDesClients;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Fusion / correction des clients de l'état des impayés — 27/10, rôles inversés le 02/11
|--------------------------------------------------------------------------
| Deux sections, comme au 27/10 ; le 02/11, le propriétaire a inversé ce qu'elles font et les a mises
| des deux côtés : « c'est la correction qui doit supprimer le nom de départ ; ajoute les deux des
| deux côtés, au niveau des impayés et du recouvrement ».
|
| **Fusion** : des factures du client de départ passent au client d'arrivée ; rien n'est supprimé.
| **Correction** : le client de départ est un nom en erreur ; ses factures passent au bon nom, puis le
| nom faux disparaît dès qu'aucune facture ne le porte plus (relances, référentiel, code tiers et
| portefeuille suivent). Le même écran existe au recouvrement, sur ses données.
|
| Ouverte à qui a le geste (Maintenance › Habilitations, « Fusion / correction des clients (état des
| impayés) ») — le gérant par défaut. Le périmètre est celui du compte. Voir `FusionDesClients`.
|
| **La panne du 28/10** (« count(): null given ») : `reset()` remet un état Volt à null. Les états sont
| remis par affectation, et les cases relues par `lesCochees()`, qui rend toujours une liste.
*/

state([
    'section' => 'fusion',
    'colonne' => 'client',
    'depart' => '',
    'arrivee' => '',
    'cochees' => [],
]);

mount(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'clients_impayes', 'fusionner'), 403);
});

$garder = protect(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'clients_impayes', 'fusionner'), 403);
});

$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), '', ''));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));

$noms = computed(fn () => FusionDesClients::noms((string) $this->colonne, $this->idsSites, $this->idsVilles));

/** Les noms pour la liste : « NOM — n facture(s) · total ». */
$options = computed(fn () => $this->noms->mapWithKeys(fn ($n) => [
    $n->nom => $n->nom.' — '.$n->n.' facture(s) · '.ae($n->total),
])->all());

/** L'arrivée ne propose jamais le départ. */
$optionsArrivee = computed(fn () => array_diff_key($this->options, [(string) $this->depart => true]));

$factures = computed(fn () => trim((string) $this->depart) === '' ? collect()
    : FusionDesClients::facturesDe((string) $this->colonne, (string) $this->depart, $this->idsSites, $this->idsVilles));

/** Les cases cochées, toujours en liste de chaînes — jamais null (voir l'en-tête). */
$lesCochees = protect(fn (): array => array_values(array_map('strval', (array) ($this->cochees ?? []))));

$totalCoche = computed(fn () => montant_de($this->factures->whereIn('id', array_map('intval', $this->lesCochees()))->sum('montant')));

$vider = protect(function (bool $garderLeDepart = false) {
    $this->depart = $garderLeDepart ? (string) $this->depart : '';
    $this->arrivee = '';
    $this->cochees = [];
    unset($this->noms, $this->options, $this->optionsArrivee, $this->factures);
});

$choisirSection = function (string $section) {
    $this->section = $section === 'correction' ? 'correction' : 'fusion';
    $this->vider();
    $this->resetValidation();
};

$updatedColonne = function () {
    $this->vider();
};

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

$executer = function () {
    $this->garder();
    $this->resetValidation();

    // La suppression du nom de départ n'appartient qu'à la correction (02/11).
    $corriger = $this->section === 'correction';

    try {
        $bilan = FusionDesClients::deplacer(
            auth()->user(), (string) $this->colonne, (string) $this->depart, (string) $this->arrivee,
            $this->lesCochees(), $this->idsSites, $this->idsVilles, $corriger,
        );
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

<div>
    @include('components.select-cherchable-ressources')

    <x-titre-ecran titre="Fusion / correction des clients"
        sous-titre="État des impayés : regrouper des factures sous un autre client, ou corriger un nom faux.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('impayes') }}" wire:navigate class="bouton bouton-secondaire">← État des impayés</a>
            <button type="button" wire:click="choisirSection('fusion')" class="bouton {{ $section === 'fusion' ? '' : 'bouton-secondaire' }}">Fusion</button>
            <button type="button" wire:click="choisirSection('correction')" class="bouton {{ $section === 'correction' ? '' : 'bouton-secondaire' }}">Correction</button>
        </div>
    </x-titre-ecran>

    <div class="carte" style="margin-bottom:16px; border-left:3px solid {{ $section === 'correction' ? '#C8102E' : '#2563EB' }};">
        <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.5;">
            @if ($section === 'fusion')
                <b>Fusion</b> : des factures du client de départ passent au client d'arrivée. Les deux clients restent ; rien n'est supprimé.
            @else
                <b>Correction</b> : le client de départ est un nom en erreur. Ses factures passent au bon client, puis le nom faux
                disparaît dès qu'aucune facture ne le porte plus — ses relances, son code et le portefeuille du commercial passent au bon client.
            @endif
            Seul le nom change : montants, dates et règlements restent ; chaque facture déplacée est tracée.
        </p>
        @if (HabilitationsDActions::accorde(auth()->user(), 'tiers_recouvrement', 'corriger'))
            <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
                Les mêmes gestes sur les données du recouvrement :
                <a href="{{ route('recouvrement.fusion-correction') }}" wire:navigate>Recouvrement › Fusion / correction des clients</a>.
            </p>
        @endif
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div class="bloc-saisie" style="background:#fff; border-style:solid;">
            <x-champ label="Nom porté par" model="colonne" type="select" :live="true" width="170" :options="FusionDesClients::COLONNES" />
            <div style="flex:1 1 320px; max-width:460px;">
                <x-select-cherchable id="impayes-depart" :label="$section === 'correction' ? 'Client à corriger (le nom faux)' : 'Client de départ'"
                    model="depart" :valeur="$depart" :options="$this->options" vide="— choisir le client de départ —"
                    placeholder="Rechercher un client…" :seuil="0" />
            </div>
        </div>
    </div>

    @if (trim((string) $depart) !== '')
        @php $cochees = $this->lesCochees(); @endphp
        <div class="carte" style="margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                <h3 style="font-size:14.5px; font-weight:700; margin:0;">Factures de « {{ $depart }} » ({{ $this->factures->count() }})</h3>
                <button type="button" wire:click="toutCocher" class="bouton bouton-secondaire" style="padding:6px 12px;">
                    {{ $this->factures->isNotEmpty() && count($cochees) === $this->factures->count() ? 'Tout décocher' : 'Sélectionner toutes les factures du client' }}
                </button>
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th style="width:34px;"></th><th>N° facture</th><th>Référence</th><th>Date</th><th>Assureur</th>
                            <th style="text-align:right;">Montant</th><th style="text-align:right;">Encaissé</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures as $f)
                            <tr wire:key="fi-{{ $f->id }}">
                                <td><input type="checkbox" wire:model.live="cochees" value="{{ $f->id }}" aria-label="Choisir la facture {{ $f->n_facture }}"></td>
                                <td><a href="{{ route('impayes.detail', $f->id) }}" wire:navigate>{{ $f->n_facture }}</a>@if ($f->est_avoir) <span style="font-size:11px; color:#B45309;">avoir</span>@endif</td>
                                <td style="font-size:12px;">{{ $f->numero }}</td>
                                <td>{{ $f->date?->format('d/m/Y') }}</td>
                                <td style="font-size:12px;">{{ $f->assureur ?: '—' }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->montant)) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->encaisse ?? 0)) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune créance sous ce nom dans votre périmètre." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
                {{ count($cochees) }} facture(s) cochée(s) · {{ ae($this->totalCoche) }}
            </p>
        </div>

        <div class="carte">
            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <div style="flex:1 1 320px; max-width:460px;">
                    <x-select-cherchable id="impayes-arrivee" :label="$section === 'correction' ? 'Le bon client' : 'Client d\'arrivée'"
                        model="arrivee" :valeur="$arrivee" :options="$this->optionsArrivee" vide="— choisir le client d'arrivée —"
                        placeholder="Rechercher un client…" :seuil="0" />
                    @error('arrivee') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                @if ($section === 'fusion')
                    <button type="button" wire:click="executer" class="bouton" @disabled($cochees === [] || $arrivee === '')
                        wire:confirm="Passer les factures cochées au client d'arrivée ? Rien n'est supprimé.">Fusionner les factures cochées</button>
                @else
                    <button type="button" wire:click="executer" class="bouton" style="background:#C8102E; border-color:#C8102E;" @disabled($cochees === [] || $arrivee === '')
                        wire:confirm="Passer les factures cochées au bon client, puis supprimer le nom faux s'il ne porte plus aucune facture ?">
                        Corriger et supprimer le nom faux
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>

<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\FusionDesClients;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Correction des clients de l'état des impayés — 27/10, réduite à la correction le 28/10
|--------------------------------------------------------------------------
| « Correction : les clients sont justes, mais des factures ne sont pas sous le bon. On choisit le
| client de départ, on voit ses factures, on les coche — ou toutes d'un bouton —, puis le client
| d'arrivée ; rien n'est supprimé. Un client de départ ne doit pas être un client d'arrivée. »
|
| **La fusion a quitté cette page le 28/10** : « je me suis trompé, la fusion concerne le
| recouvrement, donc les données du recouvrement, alors que la correction concerne les impayés ».
| Elle vit dans le module Recouvrement (`recouvrement.fusion`, `FusionDesTiers`).
|
| Ouverte à qui a le geste (Maintenance › Habilitations, « Correction des clients ») — le gérant par
| défaut. Le périmètre est celui du compte. Voir `FusionDesClients`.
|
| **La panne du 28/10** (ERR « count(): Argument #1 must be of type Countable|array, null given »,
| lignes 221 et 270 de la vue compilée). `reset('cochees')` d'un état Volt le remet à **null**, et
| non au `[]` déclaré : la valeur de départ n'est posée qu'au montage. Changer de colonne ou
| enregistrer laissait donc `cochees` à null, et le rendu suivant comptait null. Les états sont
| désormais remis par affectation, et les cases relues par `lesCochees()`, qui rend toujours une liste.
*/

state([
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

$updatedColonne = function () {
    $this->depart = '';
    $this->arrivee = '';
    $this->cochees = [];
    unset($this->noms, $this->options, $this->optionsArrivee, $this->factures);
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

$corriger = function () {
    $this->garder();
    $this->resetValidation();

    try {
        // La correction ne supprime jamais rien : le dernier argument reste à faux.
        $bilan = FusionDesClients::deplacer(
            auth()->user(), (string) $this->colonne, (string) $this->depart, (string) $this->arrivee,
            $this->lesCochees(), $this->idsSites, $this->idsVilles, false,
        );
    } catch (\RuntimeException $e) {
        $this->addError('arrivee', $e->getMessage());

        return;
    }

    $texte = $bilan['deplacees'].' facture(s) passée(s) de « '.$this->depart.' » à « '.$this->arrivee.' ».';

    $this->depart = $bilan['restantes'] > 0 ? $this->depart : '';
    $this->arrivee = '';
    $this->cochees = [];
    unset($this->noms, $this->options, $this->optionsArrivee, $this->factures);
    $this->dispatch('annonce', ton: 'succes', texte: $texte);
};

?>

<div>
    @include('components.select-cherchable-ressources')

    <x-titre-ecran titre="Correction des clients"
        sous-titre="État des impayés : remettre sous le bon client les factures rangées sous un autre.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('impayes') }}" wire:navigate class="bouton bouton-secondaire">← État des impayés</a>
        </div>
    </x-titre-ecran>

    <div class="carte" style="margin-bottom:16px; border-left:3px solid #2563EB;">
        <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.5;">
            <b>Correction</b> : les deux clients sont justes, mais des factures ne sont pas sous le bon. Elles passent au client
            d'arrivée ; rien n'est supprimé. Seul le nom change : montants, dates et règlements restent ; chaque facture déplacée
            est tracée.
        </p>
        @if (HabilitationsDActions::accorde(auth()->user(), 'tiers_recouvrement', 'fusionner'))
            <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
                Un même tiers écrit sous deux noms ? C'est une fusion :
                <a href="{{ route('recouvrement.fusion') }}" wire:navigate>Recouvrement › Fusion des tiers</a>.
            </p>
        @endif
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div class="bloc-saisie" style="background:#fff; border-style:solid;">
            <x-champ label="Nom porté par" model="colonne" type="select" :live="true" width="170" :options="FusionDesClients::COLONNES" />
            <div style="flex:1 1 320px; max-width:460px;">
                <x-select-cherchable id="correction-depart" label="Client de départ"
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
                            <tr wire:key="fc-{{ $f->id }}">
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
                    <x-select-cherchable id="correction-arrivee" label="Client d'arrivée"
                        model="arrivee" :valeur="$arrivee" :options="$this->optionsArrivee" vide="— choisir le client d'arrivée —"
                        placeholder="Rechercher un client…" :seuil="0" />
                    @error('arrivee') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" wire:click="corriger" class="bouton" @disabled($cochees === [] || $arrivee === '')
                    wire:confirm="Déplacer les factures cochées vers le client d'arrivée ?">Corriger : déplacer les factures cochées</button>
            </div>
        </div>
    @endif
</div>

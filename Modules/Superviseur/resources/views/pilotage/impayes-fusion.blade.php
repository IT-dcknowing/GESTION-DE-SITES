<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\FusionDesClients;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Fusion / correction des clients de l'état des impayés — 27/10
|--------------------------------------------------------------------------
| « Une page fusion / correction que je pourrai attribuer à partir du Super admin. Deux sections :
| fusion et correction. Fusion : on choisit le client à fusionner (l'erreur), on voit ses
| factures, on les coche — ou toutes d'un bouton —, puis le client de dépôt ; et un bouton pour
| corriger et supprimer le client de départ. Correction : le même parcours, mais on ne supprime
| rien. Un client de départ ne doit pas être un client d'arrivée. »
|
| Ouverte à qui a le geste (Maintenance › Habilitations, « Fusion / correction des clients ») — le
| gérant par défaut. Le périmètre est celui du compte. Voir `FusionDesClients`.
*/

state([
    // « fusion » ou « correction ».
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

$totalCoche = computed(fn () => montant_de($this->factures->whereIn('id', array_map('intval', (array) $this->cochees))->sum('montant')));

$choisirSection = function (string $section) {
    $this->section = $section === 'correction' ? 'correction' : 'fusion';
    $this->reset('depart', 'arrivee', 'cochees');
    $this->resetValidation();
};

$updatedColonne = function () {
    $this->reset('depart', 'arrivee', 'cochees');
    unset($this->noms, $this->options, $this->optionsArrivee, $this->factures);
};

$updatedDepart = function () {
    $this->cochees = [];

    if ($this->arrivee === $this->depart) {
        $this->arrivee = '';
    }

    unset($this->factures, $this->optionsArrivee);
};

/** « Sélectionner toutes les factures du client » — ou les décocher toutes si elles le sont. */
$toutCocher = function () {
    $ids = $this->factures->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->cochees = count(array_intersect($ids, (array) $this->cochees)) === count($ids) ? [] : $ids;
};

$executer = function (bool $supprimerLeDepart = false) {
    $this->garder();
    $this->resetValidation();

    // La suppression n'appartient qu'à la fusion : la correction ne supprime rien.
    $supprimer = $supprimerLeDepart && $this->section === 'fusion';

    try {
        $bilan = FusionDesClients::deplacer(
            auth()->user(), (string) $this->colonne, (string) $this->depart, (string) $this->arrivee,
            (array) $this->cochees, $this->idsSites, $this->idsVilles, $supprimer,
        );
    } catch (\RuntimeException $e) {
        $this->addError('arrivee', $e->getMessage());

        return;
    }

    $texte = $bilan['deplacees'].' facture(s) passée(s) de « '.$this->depart.' » à « '.$this->arrivee.' ».';

    if ($supprimer) {
        $texte .= $bilan['supprime']
            ? ' Le client « '.$this->depart.' » est supprimé.'
            : ' Le client de départ n\'est pas supprimé : '.$bilan['restantes'].' facture(s) portent encore son nom.';
    }

    $restant = $bilan['restantes'] > 0 ? $this->depart : '';
    $this->reset('arrivee', 'cochees');
    $this->depart = $restant;
    unset($this->noms, $this->options, $this->optionsArrivee, $this->factures);
    $this->dispatch('annonce', ton: 'succes', texte: $texte);
};

?>

<div>
    @include('components.select-cherchable-ressources')

    <x-titre-ecran titre="Fusion / correction des clients"
        sous-titre="État des impayés : remettre sous le bon client les factures rangées sous un nom faux ou sous un autre client.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('impayes') }}" wire:navigate class="bouton bouton-secondaire">← État des impayés</a>
            <button type="button" wire:click="choisirSection('fusion')" class="bouton {{ $section === 'fusion' ? '' : 'bouton-secondaire' }}">Fusion</button>
            <button type="button" wire:click="choisirSection('correction')" class="bouton {{ $section === 'correction' ? '' : 'bouton-secondaire' }}">Correction</button>
        </div>
    </x-titre-ecran>

    <div class="carte" style="margin-bottom:16px; border-left:3px solid {{ $section === 'fusion' ? '#C8102E' : '#2563EB' }};">
        <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.5;">
            @if ($section === 'fusion')
                <b>Fusion</b> : le client de départ est un nom en erreur, dont les factures sont vraies. Elles passent au bon client ;
                « Fusionner et supprimer le client de départ » fait disparaître le nom faux — ses relances passent au bon client.
            @else
                <b>Correction</b> : les deux clients sont justes, mais des factures ne sont pas sous le bon. Elles passent au client
                d'arrivée ; rien n'est supprimé.
            @endif
            Seul le nom change : montants, dates et règlements restent ; chaque facture déplacée est tracée.
        </p>
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div class="bloc-saisie" style="background:#fff; border-style:solid;">
            <x-champ label="Nom porté par" model="colonne" type="select" :live="true" width="170" :options="FusionDesClients::COLONNES" />
            <div style="flex:1 1 320px; max-width:460px;">
                <x-select-cherchable id="fusion-depart" :label="$section === 'fusion' ? 'Client à fusionner (l\'erreur)' : 'Client de départ'"
                    model="depart" :valeur="$depart" :options="$this->options" vide="— choisir le client de départ —"
                    placeholder="Rechercher un client…" :seuil="0" />
            </div>
        </div>
    </div>

    @if (trim($depart) !== '')
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
                            <tr wire:key="ff-{{ $f->id }}">
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
                    <x-select-cherchable id="fusion-arrivee" :label="$section === 'fusion' ? 'Client de dépôt (le bon)' : 'Client d\'arrivée'"
                        model="arrivee" :valeur="$arrivee" :options="$this->optionsArrivee" vide="— choisir le client d'arrivée —"
                        placeholder="Rechercher un client…" :seuil="0" />
                    @error('arrivee') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                @if ($section === 'fusion')
                    <button type="button" wire:click="executer(false)" class="bouton" @disabled($cochees === [] || $arrivee === '')
                        wire:confirm="Passer les factures cochées au client d'arrivée ?">Fusionner les factures cochées</button>
                    <button type="button" wire:click="executer(true)" class="bouton" style="background:#C8102E; border-color:#C8102E;" @disabled($cochees === [] || $arrivee === '')
                        wire:confirm="Passer les factures cochées au client d'arrivée, puis supprimer le client de départ s'il ne porte plus aucune facture ?">
                        Fusionner et supprimer le client de départ
                    </button>
                @else
                    <button type="button" wire:click="executer(false)" class="bouton" @disabled($cochees === [] || $arrivee === '')
                        wire:confirm="Déplacer les factures cochées vers le client d'arrivée ?">Corriger : déplacer les factures cochées</button>
                @endif
            </div>
        </div>
    @endif
</div>

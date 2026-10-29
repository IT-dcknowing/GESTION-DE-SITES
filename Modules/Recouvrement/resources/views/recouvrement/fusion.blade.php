<?php

use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Services\FusionDesTiers;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Fusion des tiers du recouvrement — 28/10
|--------------------------------------------------------------------------
| « Au niveau de la page correction et fusion, je me suis trompé : la fusion concerne le
| recouvrement, donc les données du recouvrement, alors que la correction concerne les impayés.
| Retire cette section de cette page, mets-la au niveau du recouvrement, dans le module
| recouvrement ; fais une page pour ça. »
|
| Le tiers à fusionner est un nom en erreur ; ses factures, ses relances et ses règlements sont
| vrais, et vont au bon tiers. Il est remplacé dans les quatre colonnes où il se lit (client,
| assureur, courtier, déposé chez). « Fusionner et supprimer » fait disparaître le nom faux quand
| plus rien ne le porte : ses relances, sa place au référentiel, son code et le portefeuille du
| commercial qui le suivait passent au bon. Voir `FusionDesTiers`.
|
| Ouverte à qui a le geste (Maintenance › Habilitations, « Fusion des tiers (recouvrement) ») — le
| gérant par défaut. Revérifié à chaque action : un bouton caché n'a jamais autorisé personne.
*/

state([
    'depart' => '',
    'arrivee' => '',
    'cochees' => [],
]);

mount(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'tiers_recouvrement', 'fusionner'), 403);
});

$garder = protect(function () {
    abort_unless(HabilitationsDActions::accorde(auth()->user(), 'tiers_recouvrement', 'fusionner'), 403);
});

$tiers = computed(fn () => FusionDesTiers::tiers());

/** Les noms pour la liste : « NOM — n facture(s) · reste · n relance(s) ». */
$options = computed(fn () => $this->tiers->mapWithKeys(fn ($t) => [
    $t->nom => $t->nom.' — '.$t->factures.' facture(s) · reste '.ae($t->reste)
        .($t->relances > 0 ? ' · '.$t->relances.' relance(s)' : ''),
])->all());

/** L'arrivée ne propose jamais le départ. */
$optionsArrivee = computed(fn () => array_diff_key($this->options, [(string) $this->depart => true]));

$factures = computed(fn () => trim((string) $this->depart) === '' ? collect() : FusionDesTiers::facturesDe((string) $this->depart));

$relances = computed(fn () => trim((string) $this->depart) === '' ? collect() : FusionDesTiers::relancesDe((string) $this->depart));

/*
 * Les cases cochées, toujours en liste. `reset()` d'un état Volt le remet à null, non à sa valeur
 * de départ — c'est ce qui faisait tomber la page de correction (ERR du 28/10, « count(): null
 * given ») : ici, on ne les remet jamais que par affectation, et on les relit par cette méthode.
 */
$lesCochees = protect(fn (): array => array_values(array_map('strval', (array) ($this->cochees ?? []))));

$totalCoche = computed(fn () => montant_de($this->factures->whereIn('id', array_map('intval', $this->lesCochees()))->sum('montant')));

$vider = protect(function (bool $garderLeDepart = false) {
    if (! $garderLeDepart) {
        $this->depart = '';
    }

    $this->arrivee = '';
    $this->cochees = [];
    unset($this->tiers, $this->options, $this->optionsArrivee, $this->factures, $this->relances);
});

$updatedDepart = function () {
    $this->cochees = [];
    $this->depart = (string) ($this->depart ?? '');

    if ($this->arrivee === $this->depart) {
        $this->arrivee = '';
    }

    unset($this->factures, $this->relances, $this->optionsArrivee);
};

$updatedCochees = function () {
    $this->cochees = $this->lesCochees();
};

/** « Sélectionner toutes les factures du tiers » — ou les décocher toutes si elles le sont. */
$toutCocher = function () {
    $ids = $this->factures->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->cochees = count(array_intersect($ids, $this->lesCochees())) === count($ids) ? [] : $ids;
};

$fusionner = function (bool $supprimerLeDepart = false) {
    $this->garder();
    $this->resetValidation();

    try {
        $bilan = FusionDesTiers::fusionner(auth()->user(), (string) $this->depart, (string) $this->arrivee,
            $this->lesCochees(), $supprimerLeDepart);
    } catch (\RuntimeException $e) {
        $this->addError('arrivee', $e->getMessage());

        return;
    }

    $texte = $bilan['deplacees'].' facture(s) passée(s) de « '.$this->depart.' » à « '.$this->arrivee.' ».';

    if ($supprimerLeDepart) {
        $texte .= $bilan['supprime']
            ? ' Le tiers « '.$this->depart.' » est supprimé'.($bilan['relances'] > 0 ? ', ses '.$bilan['relances'].' relance(s) passent au bon tiers.' : '.')
            : ' Le tiers n\'est pas supprimé : '.$bilan['restantes'].' facture(s) portent encore son nom.';
    }

    $this->vider($bilan['restantes'] > 0);
    $this->dispatch('annonce', ton: 'succes', texte: $texte);
};

?>

<x-recouvrement::coquille page="fusion" titre="Fusion des tiers"
    sous-titre="Un tiers en double, sous une autre orthographe : ses factures, ses relances et ses règlements passent au bon tiers.">
    @include('components.select-cherchable-ressources')

    <div class="rec-carte" style="margin-bottom:14px; border-left:3px solid #C8102E;">
        <p style="margin:0; font-size:13px; color:#4B4E55; line-height:1.55;">
            <b>Fusion</b> : le tiers à fusionner est un nom en erreur, dont les factures sont vraies. Il est remplacé par le bon
            tiers partout où il se lit sur la facture — client, assureur, courtier, déposé chez. « Fusionner et supprimer le tiers »
            fait disparaître le nom faux quand plus rien ne le porte : ses relances, son code et le portefeuille du commercial qui
            le suivait passent au bon tiers. Montants, dates et règlements ne changent pas ; chaque facture déplacée est tracée.
        </p>
        <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
            Des factures rangées sous le mauvais client de l'état des impayés, sans doublon de tiers ?
            C'est la <b>correction des clients</b>, depuis l'état des impayés.
        </p>
    </div>

    <div class="rec-carte" style="margin-bottom:14px;">
        <div class="rec-frm">
            <div class="rec-fld" style="grid-column:span 2; max-width:560px;">
                <x-select-cherchable id="fusion-tiers-depart" label="Tiers à fusionner (l'erreur)"
                    model="depart" :valeur="$depart" :options="$this->options" vide="— choisir le tiers à fusionner —"
                    placeholder="Rechercher un tiers…" :seuil="0" />
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
                        {{ count($cochees) === $this->factures->count() ? 'Tout décocher' : 'Sélectionner toutes les factures du tiers' }}
                    </button>
                @endif
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th style="width:34px;"></th><th>N° facture</th><th>Date</th><th>Le tiers y est</th><th>Client</th>
                            <th style="text-align:right;">Montant</th><th style="text-align:right;">Encaissé</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->factures as $f)
                            <tr wire:key="ft-{{ $f->id }}">
                                <td><input type="checkbox" wire:model.live="cochees" value="{{ $f->id }}" aria-label="Choisir la facture {{ $f->n_facture }}"></td>
                                <td>{{ $f->n_facture ?: $f->numero }}@if ($f->est_avoir) <span style="font-size:11px; color:#B45309;">avoir</span>@endif</td>
                                <td>{{ $f->date?->format('d/m/Y') }}</td>
                                <td style="font-size:12px;">{{ collect($f->colonnes_du_tiers)->map(fn ($c) => FusionDesTiers::COLONNES[$c])->implode(', ') }}</td>
                                <td style="font-size:12px;">{{ $f->client ?: '—' }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->montant)) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae(montant_de($f->encaissements_sum_montant ?? 0)) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune facture du recouvrement ne porte ce nom : « Fusionner et supprimer » efface le nom et reporte ses relances." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p style="margin:8px 0 0; font-size:12.5px; color:#6B6E76;">
                {{ count($cochees) }} facture(s) cochée(s) · {{ ae($this->totalCoche) }}
                @if ($this->relances->isNotEmpty())
                    · {{ $this->relances->count() }} relance(s) sous ce nom, qui passeront au bon tiers si le tiers est supprimé
                @endif
            </p>
        </div>

        <div class="rec-carte">
            <div class="rec-frm">
                <div class="rec-fld" style="grid-column:span 2; max-width:560px;">
                    <x-select-cherchable id="fusion-tiers-arrivee" label="Tiers d'arrivée (le bon)"
                        model="arrivee" :valeur="$arrivee" :options="$this->optionsArrivee" vide="— choisir le tiers d'arrivée —"
                        placeholder="Rechercher un tiers…" :seuil="0" />
                    @error('arrivee') <span class="champ-erreur">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="rec-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" wire:click="fusionner(false)" class="rec-btn r" @disabled($cochees === [] || $arrivee === '')
                    wire:confirm="Passer les factures cochées au tiers d'arrivée ?">Fusionner les factures cochées</button>
                <button type="button" wire:click="fusionner(true)" class="rec-btn r" style="background:#7A0A1C;" @disabled($arrivee === '')
                    wire:confirm="Passer les factures cochées au tiers d'arrivée, puis supprimer le tiers à fusionner s'il ne porte plus aucune facture ?">
                    Fusionner et supprimer le tiers
                </button>
            </div>
        </div>
    @endif
</x-recouvrement::coquille>

<?php

use Modules\Noyau\Imports\Modeles\DoublonImport;
use Modules\Noyau\Imports\Services\DoublonsDImport;
use function Livewire\Volt\{state, computed};

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Les doublons supposés d'une page — 14/10, revu le 15/10.
 *
 * Le 15/10, le propriétaire a précisé : « réimporter les mêmes éléments ne doit rien importer ;
 * mais à un premier import, on n'écarte rien. S'il y a des doublons, un tableau dit que ces
 * éléments sont supposés doublons à preuve du contraire, à cause de tel ou tel élément (dans une
 * colonne), et demande de choisir par un bouton et de retirer l'autre. »
 *
 * Donc : les deux lignes sont importées et comptent dans le total de la page ; ce composant les
 * montre côte à côte, dit pourquoi on les suppose identiques, et propose trois boutons — garder la
 * première, garder la seconde, garder les deux. La décision est retenue au dépôt suivant.
 *
 * Posé sur chaque page qui lit un tel import (CA, impayés, devis, caisse, banques, fournisseurs).
 * La page passe ses filtres et le pose avec une clé faite d'eux (`wire:key`) : changer de période
 * remonte le composant. `retenu` est le total que la page affiche déjà — doublons supposés compris.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
state([
    'formats' => [],
    'idsSites' => [],
    'idsVilles' => [],
    'debut' => null,
    'fin' => null,
    'retenu' => null,
    'libelleRetenu' => 'Total importé',
]);

state([
    'ouvert' => false,
    'voirTranches' => false,
    'page' => 1,
]);

$disponible = computed(fn () => DoublonsDImport::disponible());

$requete = computed(fn () => (new DoublonsDImport((int) auth()->user()->entreprise_id))
    ->requete((array) $this->formats, (array) $this->idsSites, (array) $this->idsVilles, $this->debut, $this->fin));

$totaux = computed(fn () => $this->disponible ? DoublonsDImport::totaux($this->requete) : null);

$affichees = computed(fn () => (clone $this->requete)->when(
    $this->voirTranches,
    fn ($q) => $q->whereNotIn('statut', DoublonImport::EN_ATTENTE),
    fn ($q) => $q->whereIn('statut', DoublonImport::EN_ATTENTE),
));

$lignes = computed(fn () => ! $this->ouvert || ! $this->disponible ? collect() : (clone $this->affichees)
    ->with(['lot', 'site', 'ville'])
    ->orderByDesc('date')->orderBy('id')
    ->forPage($this->page, 20)->get());

$nombreAffiche = computed(fn () => ! $this->ouvert || ! $this->disponible ? 0 : (clone $this->affichees)->count());

$retenues = computed(fn () => DoublonsDImport::retenues($this->lignes));

/** Les secondes lignes (celles écrites comme doublons supposés), lues comme les premières. */
$secondes = computed(fn () => DoublonsDImport::retenues($this->lignes->map(fn (DoublonImport $d) => (new DoublonImport)->forceFill([
    'retenue_table' => $d->retenue_table, 'retenue_id' => $d->integre_id,
]))));

$peutTrancher = computed(fn () => DoublonsDImport::peutIntegrer(auth()->user()));

$basculer = function () {
    $this->ouvert = ! $this->ouvert;
    $this->page = 1;
};

$montrer = function (bool $tranches) {
    $this->voirTranches = $tranches;
    $this->page = 1;
};

/**
 * Tranche un doublon supposé. L'identifiant et la décision viennent du navigateur : tout est relu
 * en base sous l'entreprise du lecteur (`DoublonsDImport::trancher`).
 */
$trancher = function (int $id, string $decision) {
    abort_unless($this->peutTrancher, 403, 'Trancher un doublon est réservé au gérant et au responsable de ville.');

    try {
        $doublon = (new DoublonsDImport((int) auth()->user()->entreprise_id))->trancher($id, $decision, auth()->user());
    } catch (\RuntimeException $refus) {
        $this->dispatch('annonce', ton: 'erreur', texte: $refus->getMessage());

        return;
    }

    unset($this->totaux, $this->affichees, $this->lignes, $this->nombreAffiche, $this->retenues, $this->secondes);

    // La page qui porte le composant relit ses totaux : une ligne a pu partir.
    $this->dispatch('doublon-integre');
    $this->dispatch('annonce', ton: 'succes', texte: match ($decision) {
        DoublonsDImport::GARDER_LES_DEUX => 'Les deux lignes restent : ce n\'était pas un doublon.',
        default => 'Doublon retiré'.($doublon->reference ? ' — '.$doublon->reference : '').' : une seule ligne reste.',
    });
};

?>

<div>
    @if ($this->disponible && $this->totaux !== null && ($this->totaux['aTrancher'] + $this->totaux['ecartes'] + $this->totaux['tranches']) > 0)
        @php
            $t = $this->totaux;
            $enAttente = $t['aTrancher'] + $t['ecartes'];
        @endphp
        <div class="carte" style="margin-bottom:16px; border-left:4px solid #B45309;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                <div>
                    <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Doublons supposés à l'import</h3>
                    <p style="margin:0; font-size:12.5px; color:#6B6E76; line-height:1.5; max-width:780px;">
                        À l'import, rien n'est écarté : quand deux lignes d'un même fichier se ressemblent, les
                        deux sont importées et comptées, et elles sont listées ici comme <b>supposées doublons à
                        preuve du contraire</b>, avec la raison. Choisissez celle à garder — l'autre est retirée —,
                        ou gardez les deux. Redéposer le même fichier ne réimporte rien.
                    </p>
                </div>
                <button type="button" wire:click="basculer" class="bouton {{ $ouvert ? '' : 'bouton-secondaire' }}" style="padding:8px 14px;">
                    {{ $ouvert ? 'Masquer' : 'Voir les doublons supposés ('.number_format($enAttente, 0, ',', ' ').')' }}
                </button>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-top:12px;">
                @if ($retenu !== null)
                    <x-kpi-card :label="$libelleRetenu.' — tout le fichier'" :value="ae($retenu + $t['montantEcarte'])"
                        sub="Toutes les lignes importées, doublons supposés compris" />
                    <x-kpi-card label="Montant sans les doublons supposés" :value="ae(montant_de($retenu - $t['montantATrancher']))"
                        sub="Ce que serait le total si ce sont bien des doublons" couleur="#0E9F6E" />
                @endif
                <x-kpi-card label="Doublons supposés à trancher" :value="ae(montant_de($t['montantATrancher'] + $t['montantEcarte']))"
                    sub="{{ number_format($enAttente, 0, ',', ' ') }} ligne(s) en attente de votre choix" couleur="#B45309" />
                <x-kpi-card label="Déjà tranchés" :value="number_format($t['tranches'], 0, ',', ' ')"
                    sub="dont {{ number_format($t['retires'], 0, ',', ' ') }} retiré(s) pour {{ ae($t['montantRetire']) }}" />
            </div>

            @if ($ouvert)
                <div style="display:flex; gap:8px; margin:14px 0 10px;">
                    <button type="button" wire:click="montrer(false)" class="bouton {{ $voirTranches ? 'bouton-secondaire' : '' }}" style="padding:6px 12px;">À trancher</button>
                    <button type="button" wire:click="montrer(true)" class="bouton {{ $voirTranches ? '' : 'bouton-secondaire' }}" style="padding:6px 12px;">Déjà tranchés</button>
                </div>

                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th>Fichier</th>
                                <th>Première ligne</th>
                                <th>Seconde ligne (supposée doublon)</th>
                                <th>Pourquoi</th>
                                <th>Votre choix</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->lignes as $d)
                                @php
                                    $col = DoublonsDImport::COLONNES_RETENUES[$d->retenue_table] ?? null;
                                    $lire = function ($r) use ($col) {
                                        if (! $r || ! $col) {
                                            return null;
                                        }

                                        return [
                                            'ref' => $r->{$col[0]}, 'date' => $r->{$col[1]} ? \Illuminate\Support\Carbon::parse($r->{$col[1]})->format('d/m/Y') : 'sans date',
                                            'lib' => $r->{$col[2]}, 'montant' => montant_de($r->{$col[3]}),
                                        ];
                                    };
                                    $a = $lire($this->retenues[$d->retenue_table.'#'.$d->retenue_id] ?? null);
                                    $b = $d->estEcarte() ? ['ref' => $d->reference, 'date' => $d->date?->format('d/m/Y') ?? 'sans date', 'lib' => $d->libelle, 'montant' => abs((float) $d->montant)]
                                        : $lire($this->secondes[$d->retenue_table.'#'.$d->integre_id] ?? null);
                                @endphp
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;">
                                    <td style="font-size:12px; color:#6B6E76;">
                                        {{ DoublonsDImport::LIBELLES[$d->format] ?? $d->format }}
                                        @if ($d->lot)<div>{{ \Illuminate\Support\Str::limit($d->lot->nom_fichier ?? ('Dépôt n° '.$d->lot->id), 40) }}</div>@endif
                                        @if ($d->numero_ligne)<div>ligne {{ $d->numero_ligne }}{{ $d->feuille ? ' · '.$d->feuille : '' }}</div>@elseif ($d->feuille)<div>{{ $d->feuille }}</div>@endif
                                    </td>
                                    @foreach ([$a, $b] as $cote)
                                        <td>
                                            @if ($cote)
                                                <b>{{ $cote['ref'] ?: '—' }}</b> · {{ $cote['date'] }}
                                                <div style="font-size:12px; color:#6B6E76;">{{ \Illuminate\Support\Str::limit((string) ($cote['lib'] ?: '—'), 60) }}</div>
                                                <div style="font-weight:700; font-variant-numeric:tabular-nums;">{{ ae($cote['montant']) }}</div>
                                            @else
                                                <span style="color:#6B6E76;">— retirée</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td style="font-size:12px; color:#7C2D12; max-width:280px;">
                                        {{ $d->raison ?: 'Même clé de pièce qu\'une ligne déjà lue dans ce fichier.' }}
                                        @if ($d->estEcarte())<div style="color:#6B6E76;">Dépôt d'avant le 15/10 : la seconde n'avait pas été importée.</div>@endif
                                    </td>
                                    <td style="white-space:nowrap;">
                                        @if (in_array($d->statut, DoublonImport::EN_ATTENTE, true))
                                            @if ($this->peutTrancher)
                                                <div style="display:flex; flex-direction:column; gap:5px;">
                                                    <button type="button" wire:click="trancher({{ $d->id }}, '{{ DoublonsDImport::GARDER_LA_PREMIERE }}')" wire:loading.attr="disabled" class="bouton" style="padding:5px 10px;">Garder la première</button>
                                                    @unless ($d->estEcarte())
                                                        <button type="button" wire:click="trancher({{ $d->id }}, '{{ DoublonsDImport::GARDER_LA_SECONDE }}')" wire:loading.attr="disabled" class="bouton bouton-secondaire" style="padding:5px 10px;">Garder la seconde</button>
                                                    @endunless
                                                    <button type="button" wire:click="trancher({{ $d->id }}, '{{ DoublonsDImport::GARDER_LES_DEUX }}')" wire:loading.attr="disabled" class="bouton bouton-secondaire" style="padding:5px 10px;">Pas un doublon : garder les deux</button>
                                                </div>
                                            @else
                                                <span style="font-size:11.5px; color:#6B6E76;">Le gérant ou le responsable de ville décide</span>
                                            @endif
                                        @else
                                            <span style="font-size:11.5px; color:#0E9F6E;">
                                                {{ match ($d->decision) {
                                                    DoublonsDImport::GARDER_LA_PREMIERE => 'Première gardée, seconde retirée',
                                                    DoublonsDImport::GARDER_LA_SECONDE => 'Seconde gardée, première retirée',
                                                    default => 'Les deux gardées',
                                                } }}{{ $d->decide_le ? ' le '.$d->decide_le->format('d/m/Y') : '' }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="5" :texte="$voirTranches ? 'Aucun doublon tranché.' : 'Aucun doublon supposé en attente sur cette période.'" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-pagination :page="$page" :total="$this->nombreAffiche" prop="page" :par-page="20" />
            @endif
        </div>
    @endif
</div>

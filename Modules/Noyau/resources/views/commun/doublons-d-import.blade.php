<?php

use Modules\Noyau\Imports\Services\DoublonsDImport;
use function Livewire\Volt\{state, computed};

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Les doublons d'import d'une page — 14/10.
 *
 * « Ajoute un KPI pour faire la différence : montre ce qui a été importé et montre le montant
 * correct ; dans un tableau, derrière un bouton, les lignes considérées comme doublons — celle
 * écartée — et un bouton qui les intègre si en réalité ce n'en sont pas. »
 *
 * Posé tel quel sur chaque page qui lit un import capable d'écarter une ligne (CA, impayés,
 * devis, caisse, banques, fournisseurs). La page passe ses filtres ; le composant ne décide
 * d'aucun périmètre lui-même, et relit tout dans `DoublonsDImport`.
 *
 * La page le pose avec une clé faite de ses filtres (`wire:key`) : changer de période ou de ville
 * remonte le composant avec les nouveaux, sans propriété « réactive » à surveiller.
 *
 * `retenu` est le total que la page affiche déjà : « ce qui a été importé » = retenu + écarté.
 * Il ne s'additionne nulle part ailleurs — c'est la page qui dit quel total est le sien.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
state([
    'formats' => [],
    'idsSites' => [],
    'idsVilles' => [],
    'debut' => null,
    'fin' => null,
    'retenu' => null,
    'libelleRetenu' => 'Retenu',
]);

state([
    'ouvert' => false,
    'voirIntegres' => false,
    'page' => 1,
]);

$disponible = computed(fn () => DoublonsDImport::disponible());

$requete = computed(fn () => (new DoublonsDImport((int) auth()->user()->entreprise_id))
    ->requete((array) $this->formats, (array) $this->idsSites, (array) $this->idsVilles, $this->debut, $this->fin));

$totaux = computed(fn () => $this->disponible ? DoublonsDImport::totaux($this->requete) : null);

$lignes = computed(function () {
    if (! $this->ouvert || ! $this->disponible) {
        return collect();
    }

    return (clone $this->requete)
        ->where('statut', $this->voirIntegres ? 'integre' : 'ecarte')
        ->with(['lot', 'site', 'ville'])
        ->orderByDesc('date')->orderBy('id')
        ->forPage($this->page, 20)->get();
});

$nombreAffiche = computed(fn () => ! $this->ouvert || ! $this->disponible ? 0
    : (clone $this->requete)->where('statut', $this->voirIntegres ? 'integre' : 'ecarte')->count());

$retenues = computed(fn () => DoublonsDImport::retenues($this->lignes));

$peutIntegrer = computed(fn () => DoublonsDImport::peutIntegrer(auth()->user()));

$basculer = function () {
    $this->ouvert = ! $this->ouvert;
    $this->page = 1;
};

$montrer = function (bool $integres) {
    $this->voirIntegres = $integres;
    $this->page = 1;
};

/**
 * Intègre une ligne mise de côté. L'identifiant vient du navigateur : il est relu en base sous
 * l'entreprise du lecteur, et seulement s'il attend encore une décision (`DoublonsDImport`).
 */
$integrer = function (int $id) {
    abort_unless($this->peutIntegrer, 403, 'Intégrer un doublon est réservé au gérant et au responsable de ville.');

    try {
        $doublon = (new DoublonsDImport((int) auth()->user()->entreprise_id))->integrer($id, auth()->user());
    } catch (\RuntimeException $refus) {
        $this->dispatch('annonce', ton: 'erreur', texte: $refus->getMessage());

        return;
    }

    unset($this->totaux, $this->lignes, $this->nombreAffiche, $this->retenues);

    // La page qui porte le composant relit ses totaux : la ligne compte désormais.
    $this->dispatch('doublon-integre');
    $this->dispatch('annonce', ton: 'succes',
        texte: 'Ligne intégrée'.($doublon->reference ? ' — '.$doublon->reference : '').' : '.ae($doublon->montant).' comptés désormais.');
};

?>

<div>
    @if ($this->disponible && $this->totaux !== null && ($this->totaux['ecartes'] > 0 || $this->totaux['integres'] > 0))
        @php $t = $this->totaux; $importe = $retenu === null ? null : $retenu + $t['montantEcarte']; @endphp
        <div class="carte" style="margin-bottom:16px; border-left:4px solid #B45309;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                <div>
                    <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Doublons à l'import</h3>
                    <p style="margin:0; font-size:12.5px; color:#6B6E76; line-height:1.5; max-width:760px;">
                        Une ligne qui répète, dans le même fichier, une ligne déjà importée n'est plus
                        perdue : elle est gardée ici, <b>hors des totaux</b>, à côté de celle qui a été
                        retenue. Si ce n'est pas un doublon, « Intégrer » la compte.
                    </p>
                </div>
                <button type="button" wire:click="basculer" class="bouton {{ $ouvert ? '' : 'bouton-secondaire' }}" style="padding:8px 14px;">
                    {{ $ouvert ? 'Masquer les doublons' : 'Voir les doublons ('.number_format($t['ecartes'], 0, ',', ' ').')' }}
                </button>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-top:12px;">
                @if ($importe !== null)
                    <x-kpi-card label="Importé — toutes les lignes du fichier" :value="ae($importe)"
                        sub="{{ $libelleRetenu }} + doublons écartés" />
                    <x-kpi-card :label="$libelleRetenu.' — montant compté'" :value="ae($retenu)"
                        sub="Ce que la page additionne" couleur="#0E9F6E" />
                @endif
                <x-kpi-card label="Écartés comme doublons" :value="ae($t['montantEcarte'])"
                    sub="{{ number_format($t['ecartes'], 0, ',', ' ') }} ligne(s) en attente de décision" couleur="#B45309" />
                <x-kpi-card label="Intégrés après vérification" :value="ae($t['montantIntegre'])"
                    sub="{{ number_format($t['integres'], 0, ',', ' ') }} ligne(s) — déjà comprises dans le montant compté" />
            </div>

            @if ($ouvert)
                <div style="display:flex; gap:8px; margin:14px 0 10px;">
                    <button type="button" wire:click="montrer(false)" class="bouton {{ $voirIntegres ? 'bouton-secondaire' : '' }}" style="padding:6px 12px;">Écartés</button>
                    <button type="button" wire:click="montrer(true)" class="bouton {{ $voirIntegres ? '' : 'bouton-secondaire' }}" style="padding:6px 12px;">Intégrés</button>
                </div>

                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th>Fichier</th>
                                <th>Ligne écartée</th>
                                <th style="text-align:right;">Montant écarté</th>
                                <th>Ligne retenue à sa place</th>
                                <th style="text-align:right;">Montant retenu</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->lignes as $d)
                                @php
                                    $r = $this->retenues[$d->retenue_table.'#'.$d->retenue_id] ?? null;
                                    $col = \Modules\Noyau\Imports\Services\DoublonsDImport::COLONNES_RETENUES[$d->retenue_table] ?? null;
                                    $rRef = $r && $col ? $r->{$col[0]} : null;
                                    $rDate = $r && $col ? $r->{$col[1]} : null;
                                    $rLib = $r && $col ? $r->{$col[2]} : null;
                                    $rMontant = $r && $col ? $r->{$col[3]} : null;
                                @endphp
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;">
                                    <td style="font-size:12px; color:#6B6E76;">
                                        {{ \Modules\Noyau\Imports\Services\DoublonsDImport::LIBELLES[$d->format] ?? $d->format }}
                                        @if ($d->lot)<div>{{ \Illuminate\Support\Str::limit($d->lot->nom_fichier ?? ('Dépôt n° '.$d->lot->id), 40) }}</div>@endif
                                        @if ($d->numero_ligne)<div>ligne {{ $d->numero_ligne }}{{ $d->feuille ? ' · '.$d->feuille : '' }}</div>@elseif ($d->feuille)<div>{{ $d->feuille }}</div>@endif
                                    </td>
                                    <td>
                                        <b>{{ $d->reference ?: '—' }}</b> · {{ $d->date?->format('d/m/Y') ?? 'sans date' }}
                                        <div style="font-size:12px; color:#6B6E76;">{{ $d->libelle ?: '—' }}</div>
                                        <div style="font-size:11.5px; color:#6B6E76;">{{ $d->site?->nom ?? ($d->ville?->nom ? $d->ville->nom.' — atelier non précisé' : 'Lieu non précisé') }}</div>
                                    </td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; color:#B45309; white-space:nowrap;">{{ ae($d->montant) }}</td>
                                    <td>
                                        @if ($r)
                                            <b>{{ $rRef ?: '—' }}</b> · {{ $rDate ? \Illuminate\Support\Carbon::parse($rDate)->format('d/m/Y') : 'sans date' }}
                                            <div style="font-size:12px; color:#6B6E76;">{{ $rLib ?: '—' }}</div>
                                        @else
                                            <span style="color:#6B6E76;">— (supprimée depuis)</span>
                                        @endif
                                    </td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $r ? ae(montant_de($rMontant)) : '—' }}</td>
                                    <td style="white-space:nowrap;">
                                        @if ($d->estEcarte())
                                            @if ($this->peutIntegrer)
                                                <button type="button" wire:click="integrer({{ $d->id }})" wire:loading.attr="disabled" class="bouton" style="padding:6px 12px;">
                                                    Intégrer
                                                </button>
                                            @else
                                                <span style="font-size:11.5px; color:#6B6E76;">Le gérant ou le responsable de ville décide</span>
                                            @endif
                                        @else
                                            <span style="font-size:11.5px; color:#0E9F6E;">Intégrée le {{ $d->integre_le?->format('d/m/Y') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="6" :texte="$voirIntegres ? 'Aucune ligne intégrée.' : 'Aucun doublon en attente sur cette période.'" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-pagination :page="$page" :total="$this->nombreAffiche" prop="page" :par-page="20" />
            @endif
        </div>
    @endif
</div>

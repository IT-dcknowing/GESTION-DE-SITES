<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Tracabilite\Services\JournalLisible;
use Modules\Noyau\Tracabilite\Services\PisteDeLaFiche;
use Modules\Noyau\Tracabilite\Services\QuiAAgi;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Le détail d'un devis — toutes ses colonnes, sur une page
|--------------------------------------------------------------------------
| **Demandé le 25/09** : « ajoute une colonne détail, et dois mettre toutes les colonnes.
| NB : détail dans une page. » C'est la même règle que pour la créance et la pièce
| fournisseur, et pour la même raison : un panneau qui se déplie sous une ligne pousse le
| tableau vers le bas et se perd au premier changement de page. Une page se rouvre dans un
| autre onglet, se transmet par son adresse, et se lit au large.
|
| **Le périmètre se lit sur l'identité du lecteur, jamais sur l'identifiant reçu** : un
| devis hors périmètre n'est pas affiché, et la page le dit sans laisser croire à une
| panne.
*/

state(['id']);

$devis = computed(function () {
    $sites = PerimetreSites::idsRetenus(auth()->user(), null, null);

    return Devis::query()
        ->whereIn('site_id', $sites)
        ->with(['site.ville', 'commercial', 'facture', 'prospection'])
        ->find((int) $this->id);
});

/** Qui s'en est chargé, et à quelles dates — les dates ne paraissent que dans le détail. */
$traitants = computed(fn () => $this->devis === null ? collect() : QuiAAgi::detailDe($this->devis));

$historique = computed(fn () => $this->devis === null ? collect() : Activity::query()
    ->with('causer')
    ->where('subject_type', $this->devis->getMorphClass())
    ->where('subject_id', $this->devis->id)
    ->latest('id')
    ->limit(50)
    ->get());

?>

<div>
    @php
        $intitule = 'padding:6px 10px 6px 0; color:#6B6E76; width:44%; vertical-align:top; border-bottom:1px solid var(--th-ligne,#E2E0D8);';
        $cellule = 'padding:6px 0; font-weight:600; border-bottom:1px solid var(--th-ligne,#E2E0D8);';
    @endphp

    @if (! $this->devis)
        <x-carte-section titre="Devis introuvable" icone="atelier" couleur="#C8102E">
            <p style="margin:0 0 14px; color:#4B4E55;">
                Ce devis n'existe pas, ou il relève d'un lieu qui n'est pas dans votre périmètre.
            </p>
            <a href="{{ route('devis') }}" wire:navigate class="bouton bouton-secondaire">← Retour aux devis</a>
        </x-carte-section>
    @else
        @php
            $d = $this->devis;
            $montantFacture = $d->facture?->montant ?? 0;
            $restant = $d->montant_valide !== null ? $d->montant_valide - $montantFacture : null;
            $delaiHeures = $d->date_reception ? $d->date_reception->diffInHours($d->date_emission) : null;
        @endphp

        <x-titre-ecran :titre="'Devis '.$d->numero"
            :sous-titre="$d->client.' — '.($d->site?->nom ?? 'atelier à préciser')">
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('devis') }}" wire:navigate class="bouton bouton-secondaire">← Retour aux devis</a>
                @if ($d->n_fiche_reception && PisteDeLaFiche::peutOuvrir(auth()->user()))
                    <a href="{{ route('parc-fiche.numero', ['numero' => $d->n_fiche_reception]) }}"
                        wire:navigate class="bouton bouton-secondaire">Fiche de réception</a>
                @endif
            </div>
        </x-titre-ecran>

        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Montant du devis" :value="ae($d->montant_devis)" />
            <x-kpi-card label="Montant validé"
                :value="$d->montant_valide !== null ? ae($d->montant_valide) : '—'" :bon="true" />
            <x-kpi-card label="Facturé" :value="ae($montantFacture)" />
            {{-- Ce qui reste à facturer sur ce qui a été validé : c'est le seul chiffre de
                 cette page qui se déduit, et il se déduit des trois autres. --}}
            <x-kpi-card label="Reste à facturer"
                :value="$restant !== null ? ae($restant) : '—'"
                :accent="$restant !== null && $restant > 0" />
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:16px; margin-bottom:16px;">
            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Le devis</h3>
                <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <tr><td style="{{ $intitule }}">N° de proforma</td><td style="{{ $cellule }}">{{ $d->numero }}</td></tr>
                    <tr><td style="{{ $intitule }}">N° de fiche de réception</td><td style="{{ $cellule }}">{{ $d->n_fiche_reception ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Client</td><td style="{{ $cellule }}">{{ $d->client }}</td></tr>
                    <tr><td style="{{ $intitule }}">Activité</td><td style="{{ $cellule }}">{{ $d->activite ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Statut</td>
                        <td style="{{ $cellule }} color:{{ ['En attente' => '#D97706', 'Validé' => '#0E9F6E', 'Refusé' => '#C8102E'][$d->statut] ?? 'inherit' }};">
                            {{ $d->statut }}
                        </td></tr>
                    @if ($d->motif_refus)
                        <tr><td style="{{ $intitule }}">Motif du refus</td><td style="{{ $cellule }}">{{ $d->motif_refus }}</td></tr>
                    @endif
                    <tr><td style="{{ $intitule }}">Date de réception</td><td style="{{ $cellule }}">{{ $d->date_reception?->format('d/m/Y') ?? '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Date d'émission</td><td style="{{ $cellule }}">{{ $d->date_emission?->format('d/m/Y') ?? '—' }}</td></tr>
                    {{-- Le délai que la maison surveille : au-delà de vingt-quatre heures,
                         le client a eu le temps de demander ailleurs. --}}
                    <tr><td style="{{ $intitule }}">Délai réception → émission</td>
                        <td style="{{ $cellule }} color:{{ $delaiHeures !== null && $delaiHeures > 24 ? '#C8102E' : 'inherit' }};">
                            {{ $delaiHeures === null ? '—' : $delaiHeures.' h' }}
                            @if ($delaiHeures !== null && $delaiHeures > 24)
                                <span style="font-weight:400; font-size:12px;"> — au-delà de 24 h</span>
                            @endif
                        </td></tr>
                    <tr><td style="{{ $intitule }}">Observations</td><td style="{{ $cellule }}">{{ $d->observations ?: '—' }}</td></tr>
                </table>
            </div>

            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Son origine, et à qui il compte</h3>
                <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <tr><td style="{{ $intitule }}">Atelier</td><td style="{{ $cellule }}">{{ $d->site?->nom ?? '— à préciser —' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Ville</td><td style="{{ $cellule }}">{{ $d->site?->ville?->nom ?? '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Origine</td>
                        <td style="{{ $cellule }}">
                            {{ $d->lot_import_id === null ? 'Saisi sur la plateforme' : 'Repris du logiciel d’atelier' }}
                        </td></tr>
                    {{-- Le code est un **fait lu dans le numéro** ; le commercial est une
                         **conclusion**, qui suppose que ce code ait été relié à un compte.
                         Les deux lignes sont séparées pour qu'on voie laquelle manque. --}}
                    <tr><td style="{{ $intitule }}">Code du rédacteur</td>
                        <td style="{{ $cellule }} font-family:ui-monospace,Consolas,monospace;">
                            {{ $d->code_auteur ?: '—' }}
                        </td></tr>
                    <tr><td style="{{ $intitule }}">Commercial</td>
                        <td style="{{ $cellule }}">
                            @if ($d->commercial)
                                {{ $d->commercial->nom }}
                                <span style="font-weight:400; color:#6B6E76; font-family:ui-monospace,Consolas,monospace;">
                                    · {{ $d->commercial->codeDeSaisie() }}
                                </span>
                            @elseif ($d->code_auteur)
                                <span style="color:#B0000A;">
                                    aucun — le code « {{ $d->code_auteur }} » n'est relié à personne
                                </span>
                            @else
                                <span style="color:#6B6E76;">—</span>
                            @endif
                        </td></tr>
                    <tr><td style="{{ $intitule }}">Prospection d'origine</td>
                        <td style="{{ $cellule }}">{{ $d->prospection?->numero ?? '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Facture émise</td>
                        <td style="{{ $cellule }}">
                            {{ $d->facture?->n_facture ?? $d->facture?->numero ?? '—' }}
                        </td></tr>
                </table>
            </div>
        </div>

        {{-- ------------------------------------------------------------- qui s'en est chargé --}}
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Qui s'en est chargé</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Personne</th><th>Code de saisie</th><th>Fonction</th><th class="num">Gestes</th><th>Dates d'intervention</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->traitants as $personne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:600;">{{ $personne['nom'] }}</td>
                                <td style="font-family:ui-monospace,Consolas,monospace; font-size:12.5px;">{{ $personne['code'] ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $personne['fonction'] ?? '—' }}</td>
                                <td class="num">{{ $personne['gestes'] ?: '·' }}</td>
                                <td style="font-size:12.5px;">
                                    @foreach (collect($personne['dates'])->sortDesc()->take(8) as $date)
                                        <span style="display:inline-block; background:#F4F2EC; border-radius:4px;
                                                     padding:2px 7px; margin:0 4px 4px 0; white-space:nowrap;">
                                            {{ $date?->format('d/m/Y H:i') }}
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5"
                                texte="Personne n'est encore intervenu sur ce devis — il vient d'un dépôt et n'a pas été retouché." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- l'historique --}}
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Ce qui a changé, geste par geste</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Quand</th><th>Qui</th><th>Geste</th><th>Ce qui a changé</th></tr>
                    </thead>
                    <tbody>
                        @php $noms = JournalLisible::noms($this->historique); @endphp
                        @forelse ($this->historique as $trace)
                            <tr>
                                <td style="white-space:nowrap;">{{ $trace->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $trace->causer?->name ?? 'import' }}</td>
                                <td>{{ JournalLisible::geste($trace) }}</td>
                                <td style="font-size:12.5px;">
                                    <x-journal-changements
                                        :changements="JournalLisible::changements($trace, $noms)"
                                        :creation="JournalLisible::estUneCreation($trace)" />
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune trace enregistrée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

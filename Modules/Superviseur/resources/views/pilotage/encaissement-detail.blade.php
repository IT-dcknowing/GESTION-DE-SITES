<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Tracabilite\Services\JournalLisible;
use Modules\Noyau\Tracabilite\Services\QuiAAgi;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Le détail d'un encaissement — sur une page
|--------------------------------------------------------------------------
| **Demandé le 28/09** : « les Encaissements (83) de la page trésorerie viennent avec
| moins de détails comparé à ceux des impayés, et les détails doivent ouvrir dans une
| page ».
|
| C'était exact. La trésorerie affichait six colonnes et dépliait quatre lignes sous la
| ligne cliquée ; l'écran des impayés, lui, montre la créance entière, ses règlements, qui
| l'a touchée et quand. Un règlement mérite autant : c'est de l'argent entré, et quand on
| le cherche six mois plus tard, c'est qu'il y a un désaccord.
|
| **Et un panneau déplié n'est pas une page.** Il pousse le tableau vers le bas, se perd au
| premier changement de page, ne se transmet pas et ne se rouvre pas dans un autre onglet.
| C'est la même décision que pour la créance, la pièce fournisseur et le devis.
*/

state(['id']);

$encaissement = computed(function () {
    $sites = PerimetreSites::idsRetenus(auth()->user(), null, null);

    return Encaissement::query()
        ->whereIn('site_id', $sites)
        ->with([
            'site.ville',
            'lot',
            'facture' => fn ($q) => $q->withSum('encaissements', 'montant')->with('site.ville'),
        ])
        ->find((int) $this->id);
});

/**
 * Les autres écritures du même versement, quand il en portait plusieurs.
 *
 * Un encaissement issu d'un règlement global n'est qu'une part : le montrer seul laisse
 * croire que le client a payé cette somme-là, alors qu'il a payé le total.
 */
$fratrie = computed(function () {
    $reference = $this->encaissement?->reglement_global;

    if ($reference === null) {
        return collect();
    }

    return Encaissement::query()
        ->where('reglement_global', $reference)
        ->with(['facture' => fn ($q) => $q->withSum('encaissements', 'montant')])
        ->orderBy('id')
        ->get();
});

$traitants = computed(fn () => $this->encaissement === null
    ? collect()
    : QuiAAgi::detailDe($this->encaissement));

$historique = computed(fn () => $this->encaissement === null ? collect() : Activity::query()
    ->with('causer')
    ->where('subject_type', $this->encaissement->getMorphClass())
    ->where('subject_id', $this->encaissement->id)
    ->latest('id')
    ->limit(30)
    ->get());

?>

<div>
    @php
        $intitule = 'padding:7px 10px; border-bottom:1px solid var(--th-ligne,#E2E0D8); color:#6B6E76; font-size:12px; text-transform:uppercase; letter-spacing:.4px; width:44%; vertical-align:top;';
        $cellule = 'padding:7px 10px; border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;';
    @endphp

    @if (! $this->encaissement)
        <x-carte-section titre="Encaissement introuvable" icone="atelier" couleur="#C8102E">
            <p style="margin:0 0 14px; color:#4B4E55;">
                Cet encaissement n'existe pas, ou il relève d'un lieu qui n'est pas dans votre périmètre.
            </p>
            <a href="{{ route('tresorerie') }}" wire:navigate class="bouton bouton-secondaire">← Retour à la trésorerie</a>
        </x-carte-section>
    @else
        @php
            $e = $this->encaissement;
            $importe = $e->lot_import_id !== null;
        @endphp

        <x-titre-ecran :titre="'Encaissement '.($e->numero ?: '#'.$e->id)"
            :sous-titre="ae((int) $e->montant).' — '.($e->client ?: 'tiers non précisé').' — '.($e->date?->format('d/m/Y') ?? '')">
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('tresorerie') }}" wire:navigate class="bouton bouton-secondaire">← Trésorerie</a>
                @if ($e->facture)
                    <a href="{{ route('impayes.detail', $e->facture_id) }}" wire:navigate class="bouton bouton-secondaire">
                        La facture réglée
                    </a>
                @endif
                @if ($e->reglement_global)
                    <a href="{{ route('impayes.reglement', $e->reglement_global) }}" wire:navigate class="bouton bouton-secondaire">
                        Le versement entier
                    </a>
                @endif
            </div>
        </x-titre-ecran>

        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
            <x-kpi-card label="Montant encaissé" :value="ae((int) $e->montant)" :bon="true" />
            <x-kpi-card label="Date de l'écriture" :value="$e->date?->format('d/m/Y') ?? '—'" />
            <x-kpi-card label="Moyen" :value="$e->moyen ?: '—'" :sub="$e->reference_origine ?: null" />
            {{-- D'où vient la ligne : c'est la première chose qu'on cherche quand un
                 chiffre surprend. Demandé comme colonne le 28/09, et il la fallait aussi
                 ici, en grand. --}}
            <x-kpi-card label="Origine" :value="$importe ? 'Importé' : 'Saisi ici'"
                :sub="$importe ? ($e->lot?->nom_fichier ?: 'fichier déposé') : ($e->code_auteur ?: null)" />
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:16px; margin-bottom:16px;">
            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">L'écriture</h3>
                <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <tr><td style="{{ $intitule }}">Référence</td><td style="{{ $cellule }}">{{ $e->numero ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Type d'encaissement</td><td style="{{ $cellule }}">{{ $e->type ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Moyen</td><td style="{{ $cellule }}">{{ $e->moyen ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Montant</td>
                        <td style="{{ $cellule }} font-weight:700; color:#0E9F6E;">{{ ae((int) $e->montant) }}</td></tr>
                    <tr><td style="{{ $intitule }}">Date</td><td style="{{ $cellule }}">{{ $e->date?->format('d/m/Y') ?? '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Référence d'origine</td><td style="{{ $cellule }}">{{ $e->reference_origine ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Motif</td><td style="{{ $cellule }}">{{ $e->motif ?: '—' }}</td></tr>
                    {{-- Non ventilée n'est pas une faute : un encaissement n'a d'activité
                         que s'il solde une facture qui en porte une. --}}
                    <tr><td style="{{ $intitule }}">Activité</td><td style="{{ $cellule }}">{{ $e->activite ?: 'non ventilée' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Atelier</td><td style="{{ $cellule }}">{{ $e->site?->nom ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Ville</td><td style="{{ $cellule }}">{{ $e->site?->ville?->nom ?: '—' }}</td></tr>
                </table>
            </div>

            <div class="carte">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Qui a payé, et pour quoi</h3>
                <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <tr><td style="{{ $intitule }}">Tiers payant</td><td style="{{ $cellule }}">{{ $e->client ?: '—' }}</td></tr>
                    <tr><td style="{{ $intitule }}">Autres tiers</td><td style="{{ $cellule }}">{{ $e->autres_tiers ?: '—' }}</td></tr>
                    @if ($e->facture)
                        @php $reste = Recouvrement::reste($e->facture); @endphp
                        <tr><td style="{{ $intitule }}">Facture réglée</td>
                            <td style="{{ $cellule }}">
                                <a href="{{ route('impayes.detail', $e->facture_id) }}" wire:navigate style="color:#2563EB; font-weight:700;">
                                    {{ $e->facture->n_facture ?: $e->facture->numero }}
                                </a>
                            </td></tr>
                        <tr><td style="{{ $intitule }}">Client de la facture</td><td style="{{ $cellule }}">{{ $e->facture->client }}</td></tr>
                        <tr><td style="{{ $intitule }}">Véhicule</td>
                            <td style="{{ $cellule }}">{{ $e->facture->immatriculation ?: ($e->facture->vehicule ?: '—') }}</td></tr>
                        <tr><td style="{{ $intitule }}">Montant de la facture</td>
                            <td style="{{ $cellule }}">{{ ae((int) $e->facture->montant) }}</td></tr>
                        <tr><td style="{{ $intitule }}">Reste dû après ce règlement</td>
                            <td style="{{ $cellule }} font-weight:700; color:{{ $reste >= Recouvrement::SEUIL_SOLDE ? '#C8102E' : '#0E9F6E' }};">
                                {{ ae($reste) }}
                                @if ($reste < Recouvrement::SEUIL_SOLDE)
                                    <span style="font-weight:400; font-size:12px;"> — soldée</span>
                                @endif
                            </td></tr>
                    @else
                        {{-- Un encaissement sans facture n'est pas une anomalie : un apport,
                             un remboursement, une avance. Le dire vaut mieux qu'un blanc. --}}
                        <tr><td style="{{ $intitule }}">Facture réglée</td>
                            <td style="{{ $cellule }}">aucune — cet encaissement ne solde pas de créance</td></tr>
                    @endif
                    <tr><td style="{{ $intitule }}">Règlement global</td>
                        <td style="{{ $cellule }}">
                            @if ($e->reglement_global)
                                <a href="{{ route('impayes.reglement', $e->reglement_global) }}" wire:navigate
                                   style="font-family:ui-monospace,Consolas,monospace; color:#2563EB; font-weight:700;">
                                    {{ $e->reglement_global }}
                                </a>
                                <div style="font-size:11.5px; color:#6B6E76;">
                                    une part d'un versement de {{ ae((int) $this->fratrie->sum('montant')) }}
                                    sur {{ $this->fratrie->count() }} facture(s)
                                </div>
                            @else
                                —
                            @endif
                        </td></tr>
                    <tr><td style="{{ $intitule }}">Origine</td>
                        <td style="{{ $cellule }}">
                            @if ($importe)
                                Reprise du fichier « {{ $e->lot?->nom_fichier ?: 'importé' }} »
                                @if ($e->lot?->created_at)
                                    <div style="font-size:11.5px; color:#6B6E76;">déposé le {{ $e->lot->created_at->format('d/m/Y') }}</div>
                                @endif
                            @else
                                Saisi dans l'application
                                @if ($e->code_auteur)
                                    <div style="font-size:11.5px; color:#6B6E76; font-family:ui-monospace,Consolas,monospace;">
                                        {{ $e->code_auteur }}
                                    </div>
                                @endif
                            @endif
                        </td></tr>
                </table>
            </div>
        </div>

        @if ($this->fratrie->count() > 1)
            <div class="carte" style="margin-bottom:16px;">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">Les autres parts du même versement</h3>
                <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                    Cette écriture n'est qu'une part : le client a versé
                    <b>{{ ae((int) $this->fratrie->sum('montant')) }}</b> en une fois. La montrer seule
                    laisserait croire qu'il n'a payé que {{ ae((int) $e->montant) }}.
                </p>
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr><th>Facture</th><th style="text-align:right;">Imputé</th><th style="text-align:right;">Reste dû</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->fratrie as $part)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); {{ $part->id === $e->id ? 'background:#FFFBEA;' : '' }}">
                                    <td style="font-weight:600;">
                                        {{ $part->facture?->n_facture ?: ($part->facture?->numero ?? '—') }}
                                        @if ($part->id === $e->id)
                                            <span style="font-weight:400; color:#6B6E76; font-size:11.5px;">— celle-ci</span>
                                        @endif
                                    </td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae((int) $part->montant) }}</td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">
                                        {{ $part->facture ? ae(Recouvrement::reste($part->facture)) : '—' }}
                                    </td>
                                    <td style="text-align:right;">
                                        @if ($part->facture)
                                            <a href="{{ route('impayes.detail', $part->facture_id) }}" wire:navigate
                                                class="bouton bouton-secondaire"
                                                style="padding:3px 9px; font-size:11.5px; text-decoration:none;">Détail</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Qui s'en est chargé</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Personne</th><th>Code de saisie</th><th>Fonction</th><th>Dates d'intervention</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->traitants as $personne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:600;">{{ $personne['nom'] }}</td>
                                <td style="font-family:ui-monospace,Consolas,monospace; font-size:12.5px;">{{ $personne['code'] ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $personne['fonction'] ?? '—' }}</td>
                                <td style="font-size:12.5px;">
                                    @foreach (collect($personne['dates'])->sortDesc()->take(6) as $date)
                                        <span style="display:inline-block; background:#F4F2EC; border-radius:4px;
                                                     padding:2px 7px; margin:0 4px 4px 0; white-space:nowrap;">
                                            {{ $date?->format('d/m/Y H:i') }}
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4"
                                texte="Personne n'est intervenu sur cette écriture — elle vient d'un dépôt." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Ce qui a changé, geste par geste</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Quand</th><th>Qui</th><th>Geste</th><th>Ce qui a changé</th></tr></thead>
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

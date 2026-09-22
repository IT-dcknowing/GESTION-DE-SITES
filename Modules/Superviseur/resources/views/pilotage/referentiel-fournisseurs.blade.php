<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\ConditionsFournisseur;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Référentiel fournisseurs — à quel terme chacun se règle
|--------------------------------------------------------------------------
| **Une feuille qu'on ne lisait pas.** Les deux classeurs de suivi portent, à côté de
| `DETAIL`, une feuille « Liste fournisseurs » qui décrit les fournisseurs eux-mêmes : le
| terme de règlement négocié, l'assujettissement à la TVA, et pour six d'entre eux le
| plafond d'encours. On lisait les factures depuis un mois sans jamais ouvrir la page qui
| dit pour quand elles sont dues.
|
| **Ce que cela débloque.** Sur les deux fichiers réels, 7 171 pièces sur 7 350 ont
| désormais une fiche, et 5 184 gagnent une échéance qu'aucune ligne ne portait. La colonne
| *Échéance* de l'écran principal n'était pas vide par hasard : la réponse était dans le
| classeur, un onglet plus loin.
|
| **Deuxième tableau, et il vaut le premier.** Les fournisseurs facturés qui n'ont pas de
| fiche sont nommés, avec ce qu'on leur doit. Aucun rapprochement approché n'est tenté :
| « CFAO BABI » et « CFAO BABI MOTORS » se ressemblent, et se ressembler n'autorise pas à
| attribuer un délai de paiement. La liste est là pour être corrigée dans le classeur, où
| elle est tenue — pas pour être devinée ici.
|
| Cette page est en **lecture seule** : tout vient du fichier déposé.
*/

state(['recherche' => ''])->url(except: '');
state(['sansTerme' => false])->url(except: false);
state(['page' => 1]);

$updatedRecherche = function () { $this->page = 1; };
$updatedSansTerme = function () { $this->page = 1; };

$fiches = computed(function () {
    $requete = FournisseurReferentiel::query()
        ->where('entreprise_id', auth()->user()->entreprise_id);

    if (trim($this->recherche) !== '') {
        $requete->where('nom', 'like', '%'.trim($this->recherche).'%');
    }

    // « Sans terme » est le filtre utile : une fiche sans délai ne produit aucune
    // échéance, et c'est exactement la ligne du classeur qu'il faut aller compléter.
    if ($this->sansTerme) {
        $requete->whereNull('jours_reglement');
    }

    return $requete->orderBy('nom')->get();
});

$totaux = computed(function () {
    $toutes = FournisseurReferentiel::query()
        ->where('entreprise_id', auth()->user()->entreprise_id)
        ->get();

    return [
        'fiches' => $toutes->count(),
        'avecTerme' => $toutes->whereNotNull('jours_reglement')->count(),
        'tva' => $toutes->where('assujetti_tva', '===', true)->count(),
        'inconnue' => $toutes->whereNull('assujetti_tva')->count(),
    ];
});

/**
 * Les fournisseurs facturés que la liste ne connaît pas, dans le périmètre du lecteur.
 *
 * Le périmètre se lit du compte connecté, jamais d'un paramètre reçu : un responsable de
 * ville ne doit pas apprendre d'ici ce qu'une autre ville doit à qui.
 */
$orphelins = computed(fn () => ConditionsFournisseur::sansFiche(
    (int) auth()->user()->entreprise_id,
    EtatDesFournisseurs::dansLePerimetre(
        EtatDesFournisseurs::requete(EtatDesFournisseurs::exerciceOuvert((int) auth()->user()->entreprise_id)),
        PerimetreSites::idsVillesRetenus(auth()->user(), ''),
    ),
));

?>

<div>
    <x-titre-ecran titre="Référentiel fournisseurs"
        sous-titre="Le terme de règlement et la TVA de chaque fournisseur, tels que la feuille « Liste fournisseurs » du classeur les déclare. C'est de là que vient l'échéance attendue d'une facture.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Suivi fournisseur</a>
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements</a>
        </div>
    </x-titre-ecran>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:12px; margin-bottom:18px;">
        <x-kpi-card label="Fiches" :value="$this->totaux['fiches']" sub="Fournisseurs décrits" />
        <x-kpi-card label="Terme connu" :value="$this->totaux['avecTerme']"
            sub="Produisent une échéance attendue"
            :couleur="$this->totaux['avecTerme'] > 0 ? '#0E9F6E' : '#6B6E76'" />
        <x-kpi-card label="Assujettis à la TVA" :value="$this->totaux['tva']" />
        <x-kpi-card label="TVA non renseignée" :value="$this->totaux['inconnue']"
            sub="Le classeur ne le dit pas"
            :couleur="$this->totaux['inconnue'] > 0 ? '#D97706' : '#6B6E76'" />
    </div>

    <div class="carte">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
            <x-champ label="Rechercher un fournisseur" model="recherche" :live="true" placeholder="Nom du fournisseur…" />
            <x-champ label="Sans terme de règlement seulement" model="sansTerme" type="checkbox" live="true" />
        </div>

        @if ($this->totaux['fiches'] === 0)
            <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                Aucune fiche. Elles arrivent avec le classeur de suivi fournisseur, déposé
                depuis le module <b>Import</b> : la feuille « Liste fournisseurs » est lue
                en même temps que la feuille des factures, en un seul dépôt.
            </p>
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th>Terme de règlement</th>
                            <th>Échéance comptée</th>
                            <th>TVA</th>
                            <th class="colonne-collee">Note du classeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->fiches->forPage($page, 25) as $fiche)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $fiche->nom }}</td>
                                {{-- Le libellé du fichier, à la lettre : personne ne se
                                     reconnaîtrait dans une reformulation. --}}
                                <td>{{ $fiche->delai_reglement ?: '—' }}</td>
                                <td style="color:#6B6E76;">
                                    @if ($fiche->jours_reglement === null)
                                        {{-- Dire qu'on ne sait pas compter vaut mieux que
                                             de compter faux sans le dire. --}}
                                        <span style="color:#D97706;">non déduite</span>
                                    @elseif ($fiche->jours_reglement === 0 && ! $fiche->fin_de_mois)
                                        le jour de la facture
                                    @else
                                        {{ $fiche->jours_reglement }} j
                                        {{ $fiche->fin_de_mois ? 'après la fin du mois de facture' : 'après la facture' }}
                                    @endif
                                </td>
                                <td>
                                    @if ($fiche->assujetti_tva === null)
                                        <span style="color:#9A9DA5;">—</span>
                                    @else
                                        {{ $fiche->assujetti_tva ? 'Oui' : 'Non' }}
                                    @endif
                                </td>
                                <td class="colonne-collee" style="color:#6B6E76; font-size:12.5px;">
                                    {{ $fiche->note ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Aucune fiche ne correspond à ce filtre." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$page" :total="$this->fiches->count()" prop="page" :par-page="25" />

            <p style="margin:14px 0 0; font-size:12.5px; color:#6B6E76;">
                Le plafond d'encours est recopié tel qu'il est écrit, sans être converti en
                nombre : l'un des six porte « 10 00 000 », et deviner s'il s'agit d'un
                million ou de dix effacerait la faute au lieu de la montrer.
            </p>
        @endif
    </div>

    @if ($this->orphelins->isNotEmpty())
        <div class="carte" style="margin-top:18px;">
            <h3 style="margin:0 0 6px; font-size:15px;">Facturés, mais absents de la liste</h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                Ces fournisseurs ont des pièces en base et aucune fiche : leurs factures
                n'auront pas d'échéance attendue. Certains noms sont vraisemblablement une
                autre orthographe d'un fournisseur déjà listé — l'application ne les
                rapproche pas d'elle-même, parce qu'attribuer un délai de paiement sur une
                ressemblance est une décision qui ne lui appartient pas. La correction se
                fait dans la feuille « Liste fournisseurs » du classeur.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur facturé</th>
                            <th style="text-align:right;">Pièces</th>
                            <th style="text-align:right;">Reste dû</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->orphelins as $orphelin)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $orphelin['fournisseur'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $orphelin['pieces'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $orphelin['reste'] > 0 ? '#C8102E' : '#6B6E76' }};">
                                    {{ ae($orphelin['reste']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Tableau initial des fournisseurs — les deux classeurs, tels qu'ils sont entrés
|--------------------------------------------------------------------------
| **Pourquoi cet écran existe.** L'écran « Fournisseurs » se tient par année, comme
| l'état des impayés : il montre ce qui a été facturé dans l'année regardée, plus ce qui
| traîne depuis avant. C'est la bonne vue pour travailler, et la mauvaise pour vérifier
| une reprise — on n'y voit jamais l'ensemble d'un coup, et une pièce déjà soldée d'une
| année passée n'y paraît nulle part.
|
| Demandé par le propriétaire le 24/09 : « je ne vois pas le bouton initial comme celui
| des impayés ». La paire manquait. Celui-ci en est le pendant exact : toutes les pièces,
| toutes années mêlées, dans les colonnes du classeur, en lecture seule.
|
| **En lecture seule, et volontairement.** Une correction se fait dans l'état de l'année,
| où elle est datée et attribuée à quelqu'un — et les quatre champs de la clé d'import y
| restent verrouillés sur une ligne venue d'un fichier. Voir
| `EtatDesFournisseurs::champsVerrouilles()`.
*/

state(['anneeFiltre' => ''])->url(except: '');
state(['villeFiltre' => ''])->url(except: '');
state(['origineFiltre' => ''])->url(except: '');
state(['soldeFiltre' => ''])->url(except: '');
state(['recherche' => ''])->url(except: '');
state(['page' => 1]);

$updatedAnneeFiltre = function () { $this->page = 1; };
$updatedVilleFiltre = function () { $this->page = 1; };
$updatedOrigineFiltre = function () { $this->page = 1; };
$updatedSoldeFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };

/*
 * Le périmètre se lit sur l'identité du lecteur, jamais sur le paramètre reçu.
 *
 * `idsVillesRetenus()` fait exactement cela : il part des villes visibles du compte, puis
 * **restreint** au filtre choisi. Le filtre ne peut donc qu'élaguer, jamais élargir — une
 * ville tapée dans l'adresse ne rend rien de plus. Une première version de cet écran
 * prenait le filtre tel quel quand il était renseigné ; le test
 * `test_le_tableau_initial_ne_montre_pas_une_autre_ville` l'a vu tout de suite.
 */
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user())?->pluck('nom', 'id')->all() ?? []);
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre) ?: [0]);

/** Les années que portent réellement les pièces — la liste ne propose pas du vide. */
$annees = computed(function () {
    $annees = EtatDesFournisseurs::exercices(auth()->user()->entreprise_id);

    return array_combine($annees, $annees) ?: [];
});

/**
 * Toutes les pièces, sans découpage par exercice.
 *
 * C'est la seule différence avec l'écran d'ensemble, et c'est toute la raison d'être de
 * celui-ci : `EtatDesFournisseurs::requete($annee)` retient une année et ses reports ; ici
 * il n'y a pas d'année à retenir.
 */
$requete = computed(function () {
    $requete = EtatDesFournisseurs::dansLePerimetre(
        FactureFournisseur::query()->with(['ville', 'site', 'lot', 'auteur']),
        $this->idsVilles,
    );

    if ($this->anneeFiltre !== '') {
        $requete->whereYear('date_facture', (int) $this->anneeFiltre);
    }

    // « Classeur » et « saisie » se distinguent par la seule chose qui les distingue en
    // base : le lot d'import qui a posé la ligne, ou son absence.
    if ($this->origineFiltre === 'fichier') {
        $requete->whereNotNull('lot_import_id');
    } elseif ($this->origineFiltre === 'saisie') {
        $requete->whereNull('lot_import_id');
    }

    if ($this->soldeFiltre === 'ouvertes') {
        $requete->where('reste_a_payer', '>', 0);
    } elseif ($this->soldeFiltre === 'soldees') {
        $requete->where('reste_a_payer', '<=', 0);
    }

    if (trim($this->recherche) !== '') {
        $terme = '%'.trim($this->recherche).'%';

        $requete->where(fn ($sous) => $sous
            ->where('fournisseur', 'like', $terme)
            ->orWhere('numero_piece', 'like', $terme)
            ->orWhere('numero_bc', 'like', $terme)
            ->orWhere('immatriculation', 'like', $terme)
            ->orWhere('vehicule', 'like', $terme)
            ->orWhere('numero_cheque', 'like', $terme)
            ->orWhere('imputation', 'like', $terme));
    }

    return $requete->orderByDesc('date_facture')->orderByDesc('id');
});

$nombre = computed(fn () => (clone $this->requete)->count());

/**
 * Les totaux, comptés par la base.
 *
 * Le reste ne retient que les restes positifs, exactement comme l'écran d'ensemble : six
 * pièces portent un reste négatif — trop-payés ou avoirs — et les additionner afficherait
 * une dette inférieure à la somme des pièces réellement ouvertes.
 */
$totaux = computed(fn () => [
    'facture' => (int) (clone $this->requete)->sum('montant'),
    'regle' => (int) (clone $this->requete)->sum('montant_regle'),
    'reste' => (int) (clone $this->requete)->where('reste_a_payer', '>', 0)->sum('reste_a_payer'),
    'ouvertes' => (clone $this->requete)->where('reste_a_payer', '>', 0)->count(),
    'saisies' => (clone $this->requete)->whereNull('lot_import_id')->count(),
]);

$lignes = computed(fn () => (clone $this->requete)->forPage($this->page, 20)->get());

?>

<div>
    <x-titre-ecran titre="Tableau initial — fournisseurs"
        sous-titre="Toutes les pièces des deux classeurs, toutes années mêlées, dans leurs colonnes d'origine et en lecture seule.">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Retour à l'état de l'année</a>
        </div>
    </x-titre-ecran>

    <div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
        <p style="margin:0 0 10px; font-size:13px; line-height:1.6;">
            <strong>Ce que ce tableau montre, et que l'autre ne montre pas.</strong> L'état des
            fournisseurs se tient <b>par année</b>, comme celui des impayés : il affiche ce qui a été
            facturé dans l'année choisie, plus ce qui reste dû depuis avant. Une pièce de 2023 déjà
            réglée n'y paraît donc dans aucune année. Ici elles y sont toutes — c'est la reprise
            telle quelle, pour vérifier, pas pour travailler.
        </p>
        <p style="margin:0; font-size:13px; line-height:1.6;">
            <strong>Pourquoi les montants ne comptent pas double.</strong> Les deux classeurs
            — Abidjan et San-Pédro — portent <b>10 147 lignes</b> à eux deux, mais seulement
            <b>7 397 distinctes</b> : <b>2 670 lignes figurent dans les deux</b>. Déposés l'un après
            l'autre sans précaution, ils auraient compté la même dette deux fois. Chaque pièce est
            donc reconnue à quatre champs — <b>fournisseur, n° de pièce, date, montant</b> — et une
            ligne déjà entrée est mise à jour au lieu d'être ajoutée. C'est aussi pourquoi ces quatre
            champs restent verrouillés sur une ligne venue d'un fichier : les retoucher ferait qu'un
            prochain dépôt ne la reconnaîtrait plus, et la recréerait.
        </p>
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="Année de la facture" model="anneeFiltre" type="select" :options="$this->annees"
                vide="Toutes" :live="true" width="170" />

            @if (! $this->villeUnique && $this->mesVilles !== [])
                <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles"
                    vide="Toutes" :live="true" width="170" />
            @endif

            <x-champ label="Origine" model="origineFiltre" type="select" :live="true" width="190"
                :options="['fichier' => 'Reprise du classeur', 'saisie' => 'Saisie à la main']" vide="Peu importe" />

            <x-champ label="Solde" model="soldeFiltre" type="select" :live="true" width="150"
                :options="['ouvertes' => 'Non soldées', 'soldees' => 'Soldées']" vide="Toutes" />

            <x-champ label="Recherche" model="recherche" :live="true"
                placeholder="Fournisseur, n° de pièce, BC, chèque, immatriculation…" />
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Facturé — reprise" :value="ae($this->totaux['facture'])"
            :sub="number_format($this->nombre, 0, ',', ' ').' pièce(s)'" />
        <x-kpi-card label="Déjà réglé" :value="ae($this->totaux['regle'])" :bon="true" />
        <x-kpi-card label="Reste à payer" :value="ae($this->totaux['reste'])"
            :accent="$this->totaux['reste'] > 0" :sub="$this->totaux['ouvertes'].' pièce(s) ouverte(s)'" />
        <x-kpi-card label="Saisies à la main" :value="number_format($this->totaux['saisies'], 0, ',', ' ')"
            sub="Le reste vient des classeurs" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
            Reprise des classeurs — {{ number_format($this->nombre, 0, ',', ' ') }} pièce(s)
        </h3>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Origine</th>
                        <th>Mois</th>
                        <th>N° pièce</th>
                        <th>Nature</th>
                        <th>N° BC</th>
                        <th>Section</th>
                        <th>Fournisseur</th>
                        <th>Type</th>
                        <th>Ville</th>
                        <th>Date facture</th>
                        <th>Réception</th>
                        <th>Règlement</th>
                        <th>Échéance</th>
                        <th>Délai</th>
                        <th style="text-align:right;">Montant</th>
                        <th style="text-align:right;">HT</th>
                        <th style="text-align:right;">TVA</th>
                        <th style="text-align:right;">Réglé</th>
                        <th style="text-align:right;">Reste</th>
                        <th style="text-align:right;">Refacturé</th>
                        <th>Mode</th>
                        <th>N° chèque</th>
                        <th>Imputation</th>
                        <th>Véhicule</th>
                        <th class="colonne-collee"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap; font-size:11.5px; color:#6B6E76;"
                                title="{{ EtatDesFournisseurs::origine($ligne) }}">
                                {{ $ligne->lot_import_id === null ? 'Saisie' : 'Classeur' }}
                            </td>
                            <td style="color:#6B6E76;">{{ $ligne->mois ?: '—' }}</td>
                            <td>{{ $ligne->numero_piece ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->nature_piece ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->numero_bc ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->section ?: '—' }}</td>
                            <td>{{ $ligne->fournisseur ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->type_transaction ?: '—' }}</td>
                            {{-- Le fichier ne porte l'atelier que sur une ligne sur six : un tiret
                                 dit « le classeur ne le dit pas », jamais « aucune ville ». --}}
                            <td style="color:#6B6E76;">{{ $ligne->ville?->nom ?? '—' }}</td>
                            <td style="white-space:nowrap;">{{ $ligne->date_facture?->format('d/m/Y') ?? '—' }}</td>
                            <td style="white-space:nowrap; color:#6B6E76;">{{ $ligne->date_reception?->format('d/m/Y') ?? '—' }}</td>
                            <td style="white-space:nowrap; color:#6B6E76;">{{ $ligne->date_reglement?->format('d/m/Y') ?? '—' }}</td>
                            <td style="white-space:nowrap; color:#6B6E76;">{{ $ligne->date_echeance?->format('d/m/Y') ?? '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->delai_reglement ?: '—' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae((int) $ligne->montant) }}</td>
                            {{-- Vide veut dire « la colonne n'existe pas dans ce classeur-là », et
                                 non « zéro » : San-Pédro porte le HT, Abidjan ne le porte pas. --}}
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                {{ $ligne->montant_ht === null ? '—' : ae((int) $ligne->montant_ht) }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                {{ $ligne->tva === null ? '—' : ae((int) $ligne->tva) }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ ae((int) $ligne->montant_regle) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne->reste_a_payer > 0 ? '#C8102E' : '#6B6E76' }};">
                                {{ ae((int) $ligne->reste_a_payer) }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                {{ $ligne->montant_refacture === null ? '—' : ae((int) $ligne->montant_refacture) }}
                            </td>
                            <td style="color:#6B6E76;">{{ $ligne->mode_reglement ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->numero_cheque ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->imputation ?: '—' }}</td>
                            <td style="color:#6B6E76;">
                                {{ $ligne->vehicule ?: '—' }}
                                @if ($ligne->immatriculation)
                                    <div style="font-size:11px;">{{ $ligne->immatriculation }}</div>
                                @endif
                            </td>
                            <td class="colonne-collee" style="text-align:right;">
                                <a href="{{ route('fournisseurs.piece', $ligne) }}" wire:navigate
                                    class="bouton bouton-secondaire"
                                    style="padding:4px 10px; font-size:12px; text-decoration:none;">Détail</a>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="25"
                            texte="Aucune pièce fournisseur. La reprise se remplit au premier dépôt du suivi fournisseur, depuis Import." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->nombre" prop="page" :par-page="20" />

        <p style="margin:14px 0 0; font-size:12.5px; color:#6B6E76;">
            Les quarante colonnes d'une pièce — refacturation, quantités, commentaires, actions à
            mener — se lisent sur sa page de <b>Détail</b> : vingt-cinq tiennent ici, pas quarante.
        </p>
    </div>
</div>

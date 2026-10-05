<?php

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Services\CorrespondancesDeFactures;
use Modules\Noyau\Exploitation\Services\PisteDeLaFiche;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Correspondances — chaque commercial coche les factures de ses prospections
|--------------------------------------------------------------------------
| **La demande, du 05/10.** Avant que la prospection ne porte son n° de devis (22/09),
| rien ne reliait une visite à la facture qui l'a suivie : on pensait saisir les factures
| ici, elles arrivent par l'import. Mesuré le 05/10 en local : 11 229 factures sur 11 332
| ne sont comptées à personne.
|
| **Le geste.** Toutes les factures non affectées, sans filtre posé d'avance. Le
| commercial coche les siennes — son nom et son code paraissent aussitôt dans la colonne
| voisine — puis valide. La ligne disparaît alors chez tous les commerciaux et reste chez
| les responsables, qui seuls peuvent annuler. Les règles sont dans
| `CorrespondancesDeFactures`, et l'écran n'en recopie aucune.
*/

state(['dateDebut' => ''])->url(except: '');
state(['dateFin' => ''])->url(except: '');
state(['villeFiltre' => ''])->url(except: '');
state(['saisisseurFiltre' => ''])->url(except: '');
state(['lieuSaisisseurFiltre' => ''])->url(except: '');
state(['recherche' => ''])->url(except: '');
// Pour les responsables : ce qui reste à affecter, ce qui vient d'être coché, ou les deux.
state(['etatFiltre' => ''])->url(except: '');
state(['cocheurFiltre' => ''])->url(except: '');
state(['filtresLibres' => []]);
state(['page' => 1]);
state(['selection' => []]);
state(['message' => '']);
state(['erreur' => '']);
// Le commercial à qui un responsable compte ce qu'il coche. Vide pour un commercial : il
// coche toujours pour lui-même, et le serveur l'ignorerait de toute façon.
state(['pourCommercial' => '']);

/*
 * Un filtre qui change ramène à la première page et vide la sélection : des cases cochées
 * sur des lignes que le tableau ne montre plus seraient validées sans avoir été revues.
 * Changer le commercial désigné, lui, ne touche ni à la page ni aux cases.
 */
$updated = function (string $propriete) {
    if (in_array($propriete, ['page', 'selection', 'message', 'erreur', 'pourCommercial'], true) || str_starts_with($propriete, 'selection')) {
        return;
    }

    $this->page = 1;
    $this->selection = [];
};

$estResponsable = computed(fn () => CorrespondancesDeFactures::estResponsable(auth()->user()));
$fiche = computed(fn () => CorrespondancesDeFactures::ficheDe(auth()->user()));
$peutCocher = computed(fn () => CorrespondancesDeFactures::peutCocher(auth()->user()));

/** Les commerciaux qu'un responsable peut désigner — liste « Affecter à ». */
$affectables = computed(fn () => $this->estResponsable
    ? CorrespondancesDeFactures::commerciauxAffectables(auth()->user())
    : collect());

/**
 * Celui dont le nom paraît à la coche : le commercial désigné, sinon soi-même.
 *
 * Relu dans la liste du périmètre et non pris tel quel du navigateur — c'est un affichage,
 * mais il annonce à qui la facture sera comptée, et il doit dire vrai.
 */
$beneficiaire = computed(function () {
    if ($this->estResponsable && $this->pourCommercial !== '') {
        return $this->affectables->firstWhere('id', (int) $this->pourCommercial);
    }

    return $this->fiche;
});

// Lu une fois : chaque ligne l'affiche à la coche, et il coûtait une requête par ligne.
$codeBeneficiaire = computed(fn () => $this->beneficiaire?->codeDeSaisie() ?? '');

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user())?->pluck('nom', 'id')->all() ?? []);
$lesVilles = computed(fn () => Ville::orderBy('nom')->pluck('nom', 'id')->all());
$optionsSaisisseurs = computed(fn () => CorrespondancesDeFactures::optionsSaisisseurs());

/** Les commerciaux du périmètre, pour le filtre « Coché par » des responsables. */
$cocheurs = computed(fn () => Commercial::query()
    ->whereIn('ville_id', CorrespondancesDeFactures::perimetre(auth()->user())[1])
    ->where('est_spontane', false)
    ->orderBy('nom')
    ->pluck('nom', 'id')
    ->all());

/** Les colonnes sans filtre propre — voir `FiltreLibre` et `x-autre-filtre`. */
$colonnesFiltrables = computed(fn () => [
    'factures.n_facture' => FiltreLibre::colonne('N° de facture'),
    'factures.n_sticker' => FiltreLibre::colonne('N° sticker'),
    'factures.reference_devis' => FiltreLibre::colonne('Fiche de réception'),
    'factures.n_sinistre' => FiltreLibre::colonne('N° sinistre'),
    'factures.client' => FiltreLibre::colonne('Client'),
    'factures.code_client' => FiltreLibre::colonne('Code client'),
    'factures.assureur' => FiltreLibre::colonne('Assureur'),
    'factures.courtier' => FiltreLibre::colonne('Courtier'),
    'factures.immatriculation' => FiltreLibre::colonne('Immatriculation'),
    'factures.marque' => FiltreLibre::colonne('Marque'),
    'factures.modele' => FiltreLibre::colonne('Modèle'),
    'factures.montant' => FiltreLibre::colonne('Montant', 'nombre'),
    'factures.activite' => FiltreLibre::colonne('Activité', 'liste', ['Mécanique' => 'Mécanique', 'Sinistre' => 'Sinistre']),
]);

/**
 * La requête filtrée, refaite à chaque appel : un constructeur gardé en mémoire puis
 * complété par un `count()` et un `get()` porterait les deux conditions à la fois.
 */
$filtrer = protect(function () {
    $requete = CorrespondancesDeFactures::requete(auth()->user());

    if ($this->villeFiltre !== '') {
        $ville = (int) $this->villeFiltre;
        $ateliers = Site::where('ville_id', $ville)->pluck('id')->all();

        $requete->where(fn ($q) => $q
            ->whereIn('factures.site_id', $ateliers)
            ->orWhere(fn ($sansAtelier) => $sansAtelier->whereNull('factures.site_id')->where('factures.ville_id', $ville)));
    }

    if ($this->dateDebut !== '') {
        $requete->whereDate('factures.date', '>=', $this->dateDebut);
    }

    if ($this->dateFin !== '') {
        $requete->whereDate('factures.date', '<=', $this->dateFin);
    }

    if ($this->saisisseurFiltre !== '') {
        CorrespondancesDeFactures::filtrerParCodes($requete, [$this->saisisseurFiltre]);
    }

    if ($this->lieuSaisisseurFiltre !== '') {
        CorrespondancesDeFactures::filtrerParCodes(
            $requete, CorrespondancesDeFactures::codesDeLaVille((int) $this->lieuSaisisseurFiltre),
        );
    }

    $mot = trim($this->recherche);

    if ($mot !== '') {
        $requete->where(fn ($q) => $q
            ->where('factures.n_facture', 'like', '%'.$mot.'%')
            ->orWhere('factures.reference_devis', 'like', '%'.$mot.'%')
            ->orWhere('factures.client', 'like', '%'.$mot.'%')
            ->orWhere('factures.immatriculation', 'like', '%'.$mot.'%')
            ->orWhere('factures.n_sticker', 'like', '%'.$mot.'%')
            ->orWhere('factures.n_sinistre', 'like', '%'.$mot.'%')
            ->orWhere('factures.code_client', 'like', '%'.$mot.'%'));
    }

    // Les deux filtres des responsables : un commercial ne voit de toute façon que le reste
    // à affecter, et les lui appliquer ne changerait rien — sinon ouvrir une porte.
    if ($this->estResponsable) {
        if ($this->etatFiltre === 'a_affecter') {
            $requete->whereNull('factures.commercial_id');
        } elseif ($this->etatFiltre === 'cochees') {
            $requete->whereExists(CorrespondancesDeFactures::correspondanceEnCours());
        }

        if ($this->cocheurFiltre !== '') {
            $requete->whereExists(fn ($sous) => $sous->select(DB::raw(1))
                ->from('correspondances_factures')
                ->whereColumn('correspondances_factures.facture_id', 'factures.id')
                ->whereNull('correspondances_factures.annulee_le')
                ->where('correspondances_factures.commercial_id', (int) $this->cocheurFiltre));
        }
    }

    return FiltreLibre::appliquer($requete, $this->colonnesFiltrables, (array) $this->filtresLibres);
});

$totaux = computed(function () {
    $ligne = $this->filtrer()->selectRaw('count(*) as nombre, coalesce(sum(factures.montant), 0) as montant')->first();

    return ['nombre' => (int) ($ligne->nombre ?? 0), 'montant' => (int) ($ligne->montant ?? 0)];
});

$parPage = 25;

$pageAffichee = computed(fn () => min(max(1, (int) $this->page), max(1, (int) ceil($this->totaux['nombre'] / 25))));

/** La page affichée seulement : on liste en base, on ne charge pas onze mille lignes. */
$lignes = computed(fn () => $this->filtrer()
    ->select('factures.*')
    ->with(['site', 'ville'])
    ->orderByDesc('factures.date')
    ->orderByDesc('factures.id')
    ->forPage($this->pageAffichee, 25)
    ->get());

$saisisseurs = computed(fn () => CorrespondancesDeFactures::saisisseurs($this->lignes));
$enCours = computed(fn () => CorrespondancesDeFactures::enCoursPour($this->lignes->pluck('id')->all()));

$oublier = protect(function () {
    unset($this->totaux, $this->lignes, $this->saisisseurs, $this->enCours, $this->pageAffichee);
});

/** Cocher toute la page affichée — on lit la page, on reconnaît les siennes. */
$cocherLaPage = function () {
    $libres = $this->lignes->whereNull('commercial_id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->selection = array_values(array_unique([...array_map('strval', (array) $this->selection), ...$libres]));
};

$viderLaSelection = function () {
    $this->selection = [];
};

$valider = function () {
    $this->message = '';
    $this->erreur = '';

    if ($this->selection === []) {
        $this->erreur = 'Aucune facture cochée.';

        return;
    }

    $bilan = CorrespondancesDeFactures::cocher(
        auth()->user(),
        (array) $this->selection,
        $this->pourCommercial !== '' ? (int) $this->pourCommercial : null,
    );

    $this->selection = [];
    $this->oublier();
    // Ce qui a été refusé se dit, et ne se tait pas derrière un compte de réussites.
    $this->erreur = implode(' ', $bilan['refus']);

    if ($bilan['faits'] > 0) {
        $nom = $this->beneficiaire?->nom ?? 'votre nom';
        $this->message = $bilan['faits'].' facture(s) affectée(s) à '.$nom.'. Elles ne paraissent plus chez les commerciaux.';
        $this->dispatch('annonce', texte: $bilan['faits'].' facture(s) affectée(s) à '.$nom.'.', ton: 'succes');
    }
};

$annuler = function (int $correspondance) {
    $this->message = '';
    $refus = CorrespondancesDeFactures::annuler(auth()->user(), $correspondance);
    $this->oublier();

    if ($refus !== null) {
        $this->erreur = $refus;

        return;
    }

    $this->erreur = '';
    $this->message = 'Affectation annulée : la facture est de nouveau proposée à tous les commerciaux.';
    $this->dispatch('annonce', texte: 'Affectation annulée.', ton: 'succes');
};

?>

{{-- `x-data` vide : il fait de la racine un composant Alpine, sans quoi le `x-show` des
     lignes ne lirait pas la sélection que Livewire tient déjà en page. --}}
<div x-data>
    <x-titre-ecran titre="Correspondances"
        sous-titre="Les factures que personne n'a encore reconnues. Cochez celles qui sont nées de vos prospections, puis validez : elles vous sont comptées." />

    {{-- Les deux pages de suivi, en tête : on y va pour relire ce qu'on a coché. --}}
    @if ($this->fiche || $this->estResponsable)
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
            @if ($this->fiche)
                <a href="{{ route('correspondances.miennes') }}" wire:navigate class="bouton bouton-sombre"
                    style="padding:9px 16px; text-decoration:none;">Mes correspondances</a>
            @endif
            @if ($this->estResponsable)
                <a href="{{ route('correspondances.suivi') }}" wire:navigate class="bouton bouton-secondaire"
                    style="padding:9px 16px; text-decoration:none;">Les correspondances</a>
            @endif
        </div>
    @endif

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="Rechercher" model="recherche" :live="true" width="220"
                placeholder="N° facture, fiche, client, plaque" />
            <x-champ label="Du" model="dateDebut" type="date" :live="true" width="150" />
            <x-champ label="Au" model="dateFin" type="date" :live="true" width="150" />

            @if (count($this->mesVilles) > 1)
                <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles" vide="Toutes" :live="true" width="160" />
            @endif

            <x-champ label="Saisi par" model="saisisseurFiltre" type="select" :options="$this->optionsSaisisseurs" vide="Tous" :live="true" width="200" />
            <x-champ label="Lieu du saisisseur" model="lieuSaisisseurFiltre" type="select" :options="$this->lesVilles" vide="Tous" :live="true" width="170" />

            @if ($this->estResponsable)
                <x-champ label="État" model="etatFiltre" type="select" :live="true" width="170"
                    :options="['a_affecter' => 'À affecter', 'cochees' => 'Cochées']" vide="Toutes" />
                <x-champ label="Coché par" model="cocheurFiltre" type="select" :options="$this->cocheurs" vide="Tous" :live="true" width="190" />
            @endif

            <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
        </div>

        @if ($message !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
        @endif

        @if ($erreur !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:18px;">
        <x-kpi-card label="Factures listées" :value="number_format($this->totaux['nombre'], 0, ',', ' ')" />
        <x-kpi-card label="Montant listé" :value="ae($this->totaux['montant'])" />
    </div>

    <div class="carte">
        {{-- Les gestes d'ensemble, au-dessus du tableau : à qui compter, cocher la page,
             décocher, valider. --}}
        @if ($this->peutCocher)
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:10px;">
                @if ($this->estResponsable)
                    {{-- Le responsable coche pour le compte d'un commercial : sans ce choix, sa
                         case ne compterait la facture à personne. --}}
                    <x-champ label="Affecter à" model="pourCommercial" type="select" :live="true" width="250"
                        :options="$this->affectables->pluck('nom', 'id')->all()"
                        :vide="$this->fiche ? 'Moi-même — '.$this->fiche->nom : '— choisir le commercial —'" />
                @endif
                <button type="button" wire:click="cocherLaPage" class="bouton bouton-secondaire"
                    style="padding:9px 14px; white-space:nowrap;">Cocher la page</button>
                @if ($selection !== [])
                    <button type="button" wire:click="viderLaSelection" class="bouton bouton-secondaire"
                        style="padding:9px 14px; white-space:nowrap;">Décocher</button>
                @endif
                <button type="button" wire:click="valider" class="bouton bouton-sombre"
                    style="padding:9px 16px; white-space:nowrap;">
                    Valider <span x-text="$wire.selection.length">{{ count($selection) }}</span> facture(s) cochée(s)
                </button>
            </div>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                Cliquez sur une ligne ou sur sa case pour la cocher. Le nom et le code de celui à qui
                elle sera comptée paraissent aussitôt dans « Coché par » ; rien n'est enregistré avant
                « Valider ».
            </p>
        @else
            <p style="margin:0 0 12px; font-size:12.5px; color:#B45309;">
                Votre compte ne porte aucune fiche commerciale : vous pouvez lire cette liste, pas y cocher.
            </p>
        @endif

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th style="width:34px;"></th>
                        <th>Coché par</th>
                        <th>Date de la facture</th>
                        <th>N° facture</th>
                        <th>N° sticker</th>
                        <th>Fiche de réception</th>
                        <th>N° sinistre</th>
                        <th>Immat. véhicule</th>
                        <th>Marque</th>
                        <th>Modèle</th>
                        <th>Code client</th>
                        <th>Client</th>
                        <th>Assureur</th>
                        <th>Courtier</th>
                        <th style="text-align:right;">Montant facture</th>
                        <th>Atelier</th>
                        <th>Activité</th>
                        <th>Origine</th>
                        <th>Saisi par</th>
                        <th>Lieu du saisisseur</th>
                        @if ($this->estResponsable)
                            <th class="colonne-collee"></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $facture)
                        @php
                            $correspondance = $this->enCours->get($facture->id);
                            $saisi = $this->saisisseurs[$facture->id] ?? ['code' => null, 'nom' => '', 'lieu' => ''];
                            $cochable = $facture->commercial_id === null && $this->peutCocher;
                            $cochee = in_array((string) $facture->id, array_map('strval', (array) $selection), true);
                        @endphp
                        {{-- Toute la ligne coche : la case est petite, et on lit la ligne avant de
                             la cocher. Un clic sur un lien ou un bouton garde son propre sens. --}}
                        <tr wire:key="facture-{{ $facture->id }}"
                            @if ($cochable)
                                x-on:click="if (! $event.target.closest('input, a, button, select')) $el.querySelector('input[type=checkbox]')?.click()"
                                x-bind:style="$wire.selection.map(String).includes('{{ $facture->id }}') ? 'background:#E5F2E8; cursor:pointer;' : 'cursor:pointer;'"
                                style="cursor:pointer; {{ $cochee ? 'background:#E5F2E8;' : '' }}"
                            @endif>
                            <td>
                                @if ($cochable)
                                    <input type="checkbox" wire:model="selection" value="{{ $facture->id }}"
                                        aria-label="Cocher la facture {{ $facture->n_facture ?: $facture->numero }}">
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                @if ($correspondance)
                                    {{-- Déjà affectée : visible des seuls responsables, avec le nom
                                         et le code de celui à qui elle est comptée. --}}
                                    <div style="font-weight:600;">{{ $correspondance->commercial?->nom ?? $correspondance->auteur }}</div>
                                    <div style="font-size:11.5px; color:#6B6E76;">
                                        {{ $correspondance->commercial?->codeDeSaisie() }} · le {{ $correspondance->created_at?->format('d/m/Y') }}
                                    </div>
                                @elseif ($cochable)
                                    {{-- Paraît à la coche, avant la validation : on voit à qui la
                                         ligne sera comptée avant de valider. Rendu par le serveur
                                         aussi, pour ne pas dépendre du script. --}}
                                    <div x-show="$wire.selection.map(String).includes('{{ $facture->id }}')"
                                        @if (! $cochee) style="display:none;" @endif>
                                        @if ($this->beneficiaire)
                                            <div style="font-weight:600; color:#1E7B34;">{{ $this->beneficiaire->nom }}</div>
                                            <div style="font-size:11.5px; color:#6B6E76;">{{ $this->codeBeneficiaire }}</div>
                                        @else
                                            <div style="font-size:11.5px; color:#B45309;">choisissez « Affecter à »</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td>{{ $facture->date?->format('d/m/Y') ?? '—' }}</td>
                            <td style="font-weight:700;">{{ $facture->n_facture ?: $facture->numero }}</td>
                            <td>{{ $facture->n_sticker ?: '—' }}</td>
                            <td>
                                @if ($facture->reference_devis && PisteDeLaFiche::peutOuvrir(auth()->user()))
                                    <a href="{{ route('parc-fiche.numero', ['numero' => $facture->reference_devis]) }}"
                                        wire:navigate style="color:inherit;">{{ $facture->reference_devis }}</a>
                                @else
                                    {{ $facture->reference_devis ?: '—' }}
                                @endif
                            </td>
                            <td>{{ $facture->n_sinistre ?: '—' }}</td>
                            <td>{{ $facture->immatriculation ?: '—' }}</td>
                            {{-- Les lignes reprises avant le 25/09 portent le véhicule entier dans
                                 une seule colonne : il s'affiche plutôt que d'être coupé. --}}
                            <td>{{ $facture->marque ?: ($facture->vehicule ?: '—') }}</td>
                            <td>{{ $facture->modele ?: '—' }}</td>
                            <td>{{ $facture->code_client ?: '—' }}</td>
                            <td>{{ $facture->client }}</td>
                            <td>{{ $facture->assureur ?: '—' }}</td>
                            <td>{{ $facture->courtier ?: '—' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae($facture->montant) }}</td>
                            <td>{{ $facture->site?->nom ?? $facture->ville?->nom ?? '—' }}</td>
                            <td>{{ $facture->activite ?: '—' }}</td>
                            <td style="white-space:nowrap; font-size:11.5px; font-weight:600;">
                                @if ($facture->est_etat_initial || $facture->exercice_impayes !== null)
                                    <span style="color:#B9791C;">État des impayés</span>
                                @elseif ($facture->lot_import_id !== null)
                                    <span style="color:#B9791C;">Reprise CATTC</span>
                                @else
                                    <span style="color:#0E9F6E;">Saisie ici</span>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                @if ($saisi['code'])
                                    <span style="font-weight:700;">{{ $saisi['code'] }}</span>
                                    @if ($saisi['nom'] !== '')
                                        <span style="color:#6B6E76;">· {{ $saisi['nom'] }}</span>
                                    @else
                                        <span style="color:#6B6E76; font-size:11.5px;">· non nommé</span>
                                    @endif
                                @else
                                    <span style="color:#6B6E76;">—</span>
                                @endif
                            </td>
                            <td>{{ $saisi['lieu'] !== '' ? $saisi['lieu'] : '—' }}</td>
                            @if ($this->estResponsable)
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    @if ($correspondance)
                                        <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                            wire:click="annuler({{ $correspondance->id }})"
                                            data-confirmer="Annuler l'affectation de la facture {{ $facture->n_facture ?: $facture->numero }} ?"
                                            data-confirmer-detail="Elle ne sera plus comptée à {{ $correspondance->commercial?->nom }} et redeviendra visible de tous les commerciaux."
                                            data-confirmer-ton="alerte">Annuler</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <x-table-vide :colspan="$this->estResponsable ? 21 : 20"
                            texte="Aucune facture à affecter avec ces filtres. Élargissez la recherche, ou toutes les factures de votre périmètre ont déjà trouvé leur commercial." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$this->pageAffichee" :total="$this->totaux['nombre']" prop="page" :par-page="25" />
    </div>
</div>

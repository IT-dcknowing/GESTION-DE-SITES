<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| État des impayés — FICORE
|--------------------------------------------------------------------------
| L'écran qui remplace le classeur.
|
| Le classeur qu'il remplace est juste — 798 999 354 F de reste à payer sur 1 332
| créances ouvertes, et la reprise en base y retombe. Ce qu'un tableur ne peut pas
| faire, en revanche, c'est se défendre contre la main qui le tient. Cet écran, si :
|
|   - un règlement supérieur au montant facturé est refusé — 29 lignes du fichier le
|     font, pour 4 446 771 F, et ce trop-perçu vient en déduction du total, donc masque
|     la dette d'autres clients ;
|   - une colonne ne peut pas glisser : dans le classeur, la formule d'ancienneté se
|     trouve sous « Mode de règlement » pour 1 198 lignes et sous « banque » pour 1 492
|     autres, soit 2 765 lignes où l'on lit des jours sous un en-tête de moyen de paiement ;
|   - un doublon est refusé à la frappe, sur la clé que le classeur n'utilisait qu'après
|     coup ;
|   - une créance sans date ni numéro est refusée : 45 lignes du fichier n'ont aucune date
|     exploitable, 142 aucun numéro. On ne relance pas une créance qu'on ne peut pas dater.
|
| Le reste à payer n'est jamais écrit. Il se déduit des encaissements rattachés, comme
| partout ailleurs dans l'application — voir Recouvrement. Une colonne peut se tromper ;
| une soustraction faite à la lecture ne peut pas.
*/

state(['exercice' => null])->url(except: '');
state(['villeFiltre' => ''])->url(except: '');
state(['siteFiltre' => ''])->url(except: '');
state(['statutFiltre' => 'ouvertes'])->url(except: 'ouvertes');
state(['reportFiltre' => ''])->url(except: '');
state(['recherche' => ''])->url(except: '');

state([
    'page' => 1,
    'formulaireOuvert' => false,

    // Les colonnes du classeur, dans son ordre et sous ses mots — voir
    // EtatDesImpayes::COLONNES_DU_FICHIER. Le superviseur de veille tient ce fichier depuis
    // quatre ans : lui présenter ses propres colonnes dans un autre ordre reviendrait à lui
    // demander de réapprendre son travail.
    'fAssureur' => '',
    'fClient' => '',
    'fSiteId' => '',
    'fCourtier' => '',
    'fDateReception' => '',
    'fDate' => '',
    'fNumero' => '',
    'fSinistre' => '',
    'fVehicule' => '',
    'fImmatriculation' => '',
    'fMontant' => '',
    'fRegle' => '',
    'fModeReglement' => '',
    'fDateReglement' => '',
    'fBanque' => '',
    'fCommentaires' => '',
]);

mount(function () {
    $this->exercice ??= EtatDesImpayes::exerciceOuvert(auth()->user()->entreprise_id);
});

$updatedVilleFiltre = function () { $this->siteFiltre = ''; $this->page = 1; };
$updatedExercice = function () { $this->page = 1; };
$updatedStatutFiltre = function () { $this->page = 1; };
$updatedReportFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };

$annee = computed(fn () => (int) ($this->exercice ?: now()->year));

$exercices = computed(function () {
    $annees = EtatDesImpayes::exercices(auth()->user()->entreprise_id);

    return array_combine($annees, $annees) ?: [now()->year => now()->year];
});

$arrete = computed(fn () => EtatDesImpayes::arreteDe($this->annee));

/*
 * Le périmètre, ramené à ce qu'attend une liste déroulante — identifiant => nom.
 *
 * `optionsVilles` et `optionsSites` rendent des modèles, et **null** quand le choix ne se pose
 * pas : une seule ville, un seul atelier. Ce null est le signal qui masque le filtre ; il n'est
 * pas une liste vide, et c'est pourquoi il est converti ici plutôt que passé tel quel.
 */
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user())?->pluck('nom', 'id')->all() ?? []);
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre)?->pluck('nom', 'id')->all() ?? []);
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));

/**
 * Les ateliers où l'on peut rattacher une créance.
 *
 * `Site::visiblesPour()` et non `sitesConsultables()` : la seconde ajoute les ateliers qu'on a
 * **occupés par le passé**, ce qui est juste pour lire un historique et faux pour écrire. Son
 * propre commentaire le dit — aucun écran de saisie ne doit l'appeler. Enregistrer une créance
 * de ce jour sur un atelier qu'on a quitté l'an dernier n'aurait aucun sens.
 */
$sitesSaisissables = computed(fn () => Site::visiblesPour(auth()->user())->sortBy('nom')->pluck('nom', 'id')->all());

$modes = computed(fn () => Referentiel::options(Referentiel::MODE_RECOUVREMENT));

/**
 * Les lignes de l'état de l'année regardée.
 *
 * Le filtre par lieu laisse passer les créances sans atelier : 42 % des lignes du classeur
 * n'ont pas de SITE, et les écarter du tableau reviendrait à cacher précisément celles
 * qu'il faut rattacher.
 */
$lignes = computed(function () {
    $requete = EtatDesImpayes::requete($this->annee)
        ->withSum('encaissements', 'montant')
        ->with('site.ville')
        ->where(fn ($q) => $q->whereIn('site_id', $this->idsSites)->orWhereNull('site_id'));

    if ($this->recherche !== '') {
        $terme = '%'.trim($this->recherche).'%';

        $requete->where(fn ($sous) => $sous
            ->where('client', 'like', $terme)
            ->orWhere('assureur', 'like', $terme)
            ->orWhere('courtier', 'like', $terme)
            ->orWhere('numero', 'like', $terme)
            ->orWhere('n_facture', 'like', $terme)
            ->orWhere('immatriculation', 'like', $terme)
            ->orWhere('n_sinistre', 'like', $terme));
    }

    $lignes = $requete->orderByDesc('date')->orderByDesc('id')->get();

    // Le solde et le report se jugent en mémoire : tous deux reposent sur des règles qui
    // vivent dans un service, et les récrire en SQL en ferait une seconde version à tenir
    // d'accord avec la première.
    if ($this->statutFiltre === 'ouvertes') {
        $lignes = $lignes->filter(fn (Facture $f) => Recouvrement::reste($f) >= Recouvrement::SEUIL_SOLDE);
    } elseif ($this->statutFiltre === 'soldees') {
        $lignes = $lignes->filter(fn (Facture $f) => Recouvrement::reste($f) < Recouvrement::SEUIL_SOLDE);
    }

    if ($this->reportFiltre === 'reportees') {
        $lignes = $lignes->filter(fn (Facture $f) => EtatDesImpayes::estReportee($f, $this->annee));
    } elseif ($this->reportFiltre === 'annee') {
        $lignes = $lignes->reject(fn (Facture $f) => EtatDesImpayes::estReportee($f, $this->annee));
    }

    return $lignes->values();
});

$totaux = computed(fn () => EtatDesImpayes::totaux($this->lignes, $this->annee));

/** Le numéro que portera la prochaine créance — montré, pas consommé. */
$apercuNumero = computed(fn () => EtatDesImpayes::apercuDuNumero(
    auth()->user()->entreprise_id,
    $this->fDate ?: null,
));

$basculerFormulaire = function () {
    $this->formulaireOuvert = ! $this->formulaireOuvert;

    if ($this->formulaireOuvert && $this->fDate === '') {
        $this->fDate = now()->toDateString();
    }

    $this->resetErrorBag();
};

/**
 * Enregistre une créance — et le règlement qui va avec, s'il y en a un.
 *
 * **Le montant réglé devient un encaissement, jamais une colonne.** C'est ce qui fait que le
 * reste à payer se calcule partout de la même façon, et que cette créance apparaît dans la
 * balance âgée, l'extrait de compte et la trésorerie sans le moindre rapprochement.
 *
 * Les deux écritures sont dans la même transaction : une créance enregistrée dont le
 * règlement se serait perdu en route se lirait comme intégralement due, et l'on relancerait
 * un client qui a payé.
 */
$enregistrer = function () {
    $donnees = $this->validate([
        // Le classeur tolérait l'absence de date et de numéro — 385 et 375 lignes. Ici les
        // deux sont exigés : une créance qu'on ne peut ni dater ni nommer ne se réclame pas.
        'fDate' => ['required', 'date', 'before_or_equal:today', 'after:2015-01-01'],
        'fNumero' => ['required', 'string', 'max:60'],
        'fClient' => ['required', 'string', 'max:255'],
        'fSiteId' => ['required', Rule::in(array_keys($this->sitesSaisissables))],
        'fMontant' => ['required', 'integer', 'min:1'],
        /*
         * La règle que le classeur ne pouvait pas tenir : on n'encaisse pas plus que ce
         * qu'on a facturé. 128 de ses lignes le faisaient, pour 4 446 771 F — et ce
         * trop-perçu venait en déduction du total, masquant la dette des autres clients.
         */
        'fRegle' => ['nullable', 'integer', 'min:0', 'lte:fMontant'],
        'fDateReception' => ['nullable', 'date', 'after_or_equal:fDate', 'before_or_equal:today'],
        // Un règlement sans moyen ni date n'est pas un règlement, c'est un chiffre.
        'fModeReglement' => ['exclude_if:fRegle,', 'required_unless:fRegle,0', Rule::in(array_keys($this->modes))],
        'fDateReglement' => ['exclude_if:fRegle,', 'required_unless:fRegle,0', 'date', 'after_or_equal:fDate', 'before_or_equal:today'],
        'fAssureur' => ['nullable', 'string', 'max:160'],
        'fCourtier' => ['nullable', 'string', 'max:160'],
        'fSinistre' => ['nullable', 'string', 'max:60'],
        'fVehicule' => ['nullable', 'string', 'max:120'],
        'fImmatriculation' => ['nullable', 'string', 'max:30'],
        'fBanque' => ['nullable', 'string', 'max:120'],
        'fCommentaires' => ['nullable', 'string', 'max:255'],
    ], [], [
        'fDate' => "date d'édition", 'fNumero' => 'numéro de la facture', 'fClient' => 'client',
        'fSiteId' => 'site', 'fMontant' => 'montant TTC', 'fRegle' => 'montant réglé',
        'fDateReception' => 'date de réception', 'fModeReglement' => 'mode de règlement',
        'fDateReglement' => 'date de règlement', 'fAssureur' => 'assureur', 'fCourtier' => 'courtier',
        'fSinistre' => 'numéro de sinistre', 'fVehicule' => 'véhicule',
        'fImmatriculation' => 'immatriculation', 'fBanque' => 'banque',
    ]);

    $date = \Illuminate\Support\Carbon::parse($donnees['fDate']);
    $montant = (int) $donnees['fMontant'];
    $regle = (int) ($donnees['fRegle'] ?? 0);
    $immatriculation = mb_strtoupper(trim((string) ($donnees['fImmatriculation'] ?? '')));

    /*
     * Le doublon, refusé à la frappe plutôt que repéré après coup.
     *
     * La clé est celle du classeur lui-même — date d'édition, numéro, immatriculation,
     * montant — et c'est aussi celle que l'import avait retenue de son côté, en éprouvant
     * les combinaisons sur le fichier entier. Le numéro seul n'identifie rien ici : ce sont
     * de simples entiers, remis à zéro chaque année depuis 2022.
     */
    $doublon = Facture::where('n_facture', $donnees['fNumero'])
        ->whereDate('date', $date)
        ->where('montant', $montant)
        ->when($immatriculation !== '', fn ($q) => $q->where('immatriculation', $immatriculation))
        ->first();

    if ($doublon !== null) {
        $this->addError('fNumero', 'Cette créance existe déjà sous la référence '.$doublon->numero.' — même numéro, même date, même montant.');

        return;
    }

    DB::transaction(function () use ($donnees, $date, $montant, $regle, $immatriculation) {
        $entrepriseId = auth()->user()->entreprise_id;

        $facture = Facture::create([
            'entreprise_id' => $entrepriseId,
            'site_id' => (int) $donnees['fSiteId'],
            /*
             * Sa propre série : IMP-1509-0001. Elle vit dans la même table que les factures
             * de l'atelier, et c'est justement pourquoi elle porte un autre préfixe — une
             * créance relevée par la veille n'a pas été émise par l'atelier, et deux origines
             * qui se lisent pareil dans un tableau finissent par se confondre au téléphone.
             */
            'numero' => GenerateurNumero::suivant($entrepriseId, EtatDesImpayes::SERIE, $date),
            'n_facture' => trim($donnees['fNumero']),
            'date' => $date,
            'date_reception' => $donnees['fDateReception'] ?: null,
            /*
             * L'année de la créance est celle de sa facture, et non celle de l'écran depuis
             * lequel on la saisit. Une facture de décembre 2025 relevée en 2026 apparaît donc
             * d'emblée dans l'état 2026 sous « Reporté 2025 », ce qui est la vérité.
             */
            'exercice_impayes' => (int) $date->format('Y'),
            'est_etat_initial' => false,
            'client' => trim($donnees['fClient']),
            'assureur' => $donnees['fAssureur'] ?: null,
            'courtier' => $donnees['fCourtier'] ?: null,
            'banque' => $donnees['fBanque'] ?: null,
            'vehicule' => $donnees['fVehicule'] ?: null,
            'immatriculation' => $immatriculation ?: null,
            'n_sinistre' => $donnees['fSinistre'] ?: null,
            'montant' => $montant,
            'activite' => EtatDesImpayes::activiteDeduite($donnees['fSinistre'] ?? null),
            'type' => 'FNE',
            'observations' => $donnees['fCommentaires'] ?: null,
            'cree_par' => auth()->id(),
        ]);

        if ($regle > 0) {
            Encaissement::create([
                'entreprise_id' => $entrepriseId,
                'site_id' => (int) $donnees['fSiteId'],
                'facture_id' => $facture->id,
                'date' => $donnees['fDateReglement'],
                'montant' => $regle,
                'type' => 'Client',
                'moyen' => $donnees['fModeReglement'],
                'client' => $facture->tiersPayant(),
                'activite' => $facture->activite,
                'cree_par' => auth()->id(),
            ]);
        }

        activity()->causedBy(auth()->user())
            ->performedOn($facture)
            ->withProperties([
                'reference' => $facture->numero,
                'n_facture' => $facture->n_facture,
                'client' => $facture->client,
                'montant' => $montant,
                'regle' => $regle,
                'exercice' => $facture->exercice_impayes,
            ])
            ->log('État des impayés — créance saisie');
    });

    $reference = Facture::where('n_facture', trim($donnees['fNumero']))->whereDate('date', $date)->latest('id')->value('numero');

    $this->reset([
        'fNumero', 'fSinistre', 'fVehicule', 'fImmatriculation', 'fMontant', 'fRegle',
        'fDateReglement', 'fBanque', 'fCommentaires', 'fDateReception',
    ]);

    unset($this->lignes, $this->totaux, $this->apercuNumero, $this->exercices);

    $this->page = 1;

    $this->dispatch('annonce', texte: 'Créance '.$reference.' enregistrée'
        .($regle > 0 ? ' — le règlement de '.ae($regle).' entre aussitôt en trésorerie.' : '.'));
};

?>

<div>
    <x-titre-ecran titre="État des impayés — FICORE"
        sous-titre="Les créances clients et leurs règlements, année par année. Ce qui n'est pas soldé se reporte de lui-même sur l'année suivante." />

    {{-- Le bandeau : l'année regardée, le périmètre, et le bouton qui ouvre la saisie. --}}
    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="Année de l'état" model="exercice" type="select" :options="$this->exercices" :live="true" width="130" />

            @if (! $this->villeUnique && $this->mesVilles !== [])
                <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles" vide="Toutes" :live="true" width="170" />
            @endif

            @if (count($this->mesSitesFiltre) > 1)
                <x-champ label="Atelier" model="siteFiltre" type="select" :options="$this->mesSitesFiltre" vide="Tous" :live="true" width="190" />
            @endif

            <x-champ label="Solde" model="statutFiltre" type="select" :live="true" width="150"
                :options="['ouvertes' => 'Non soldées', 'soldees' => 'Soldées', 'toutes' => 'Toutes']" />

            <x-champ label="Origine de la ligne" model="reportFiltre" type="select" :live="true" width="190"
                :options="['reportees' => 'Reportées d\'avant', 'annee' => 'Nées dans l\'année']" vide="Toutes" />

            <x-champ label="Recherche" model="recherche" :live="true"
                placeholder="Client, assureur, n° facture, immatriculation…" />

            <button type="button" wire:click="basculerFormulaire" class="bouton"
                style="padding:9px 16px; white-space:nowrap;">
                {{ $formulaireOuvert ? 'Fermer le formulaire' : '+ Ajouter une créance' }}
            </button>

            <a href="{{ route('impayes.etat-initial') }}" class="bouton bouton-secondaire"
                style="padding:9px 16px; white-space:nowrap; text-decoration:none;">Tableau état initial</a>
        </div>
    </div>

    {{-- Le formulaire de saisie : les colonnes du classeur, dans son ordre et sous ses mots. --}}
    @if ($formulaireOuvert)
        <div class="carte" style="margin-bottom:16px; border-left:3px solid var(--th-accent,#C8102E);">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Nouvelle créance</h3>
            <p style="font-size:12.5px; color:#6B6E76; margin:0 0 14px;">
                Elle portera la référence <strong>{{ $this->apercuNumero }}</strong>, posée à l'enregistrement.
                Le reste à payer ne se saisit pas : il se déduit du montant TTC et des règlements.
            </p>

            <form wire:submit.prevent="enregistrer">
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                    <x-champ label="ASSUREUR" model="fAssureur" width="190" />
                    <x-champ label="Client" model="fClient" :requis="true" width="220" />
                    <x-champ label="SITE" model="fSiteId" type="select" :options="$this->sitesSaisissables" vide="— choisir —" :requis="true" width="200" />
                    <x-champ label="Courtier" model="fCourtier" width="190" />
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                    <x-champ label="Date d'edition de la facture" model="fDate" type="date" :requis="true" width="200" />
                    <x-champ label="Date de reception de la facture" model="fDateReception" type="date" width="215"
                        aide="Facultative, jamais avant l'édition." />
                    <x-champ label="Numéro de la facture" model="fNumero" :requis="true" width="180" />
                    <x-champ label="Numéro Sinistre" model="fSinistre" width="180"
                        aide="Renseigné, la créance est rangée en Sinistre." />
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                    <x-champ label="Vehicule" model="fVehicule" width="200" />
                    <x-champ label="Immatriculation" model="fImmatriculation" width="160" />
                    <x-champ label="montantTTC" model="fMontant" type="number" :requis="true" width="160" />
                    <x-champ label="Montantréglé" model="fRegle" type="number" width="160"
                        aide="Jamais supérieur au montant TTC." />
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                    <x-champ label="Modederèglement" model="fModeReglement" type="select" :options="$this->modes" vide="— aucun —" width="180" />
                    <x-champ label="Datederèglement" model="fDateReglement" type="date" width="170" />
                    <x-champ label="banque" model="fBanque" width="170" />
                    <x-champ label="Commentaires" model="fCommentaires" width="240" />
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <button type="submit" class="bouton" style="padding:9px 18px;">Enregistrer la créance</button>
                    <button type="button" wire:click="basculerFormulaire" class="bouton bouton-secondaire" style="padding:9px 18px;">Annuler</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Les totaux. Le reste se somme ligne à ligne, à plancher zéro : c'est précisément ce
         que le classeur ne faisait pas, et ses 4 446 771 F de trop-perçu venaient en
         déduction de la dette des autres. --}}
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Facturé — état {{ $this->annee }}" :value="ae($this->totaux['facture'])"
            :sub="$this->totaux['lignes'].' créance(s)'" />
        <x-kpi-card label="Déjà encaissé" :value="ae($this->totaux['encaisse'])" :bon="true" />
        <x-kpi-card label="Reste à payer" :value="ae($this->totaux['reste'])"
            :accent="$this->totaux['reste'] > 0" :sub="$this->totaux['ouvertes'].' non soldée(s)'" />
        <x-kpi-card label="Dont reporté des années d'avant" :value="ae($this->totaux['resteReporte'])"
            :sub="$this->totaux['reportees'].' ligne(s) reportée(s)'" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">
            État {{ $this->annee }} — {{ $this->lignes->count() }} ligne(s)
        </h3>
        <p style="font-size:12.5px; color:#6B6E76; margin:0 0 14px;">
            Une créance non soldée reste affichée les années suivantes sans être recopiée : elle garde
            son année d'origine, et les deux dernières colonnes disent d'où elle vient.
        </p>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>ASSUREUR</th>
                        <th>Client</th>
                        <th>SITE</th>
                        <th>Courtier</th>
                        <th>Date d'édition</th>
                        <th>N° facture</th>
                        <th>N° sinistre</th>
                        <th>Immatriculation</th>
                        <th style="text-align:right;">Montant TTC</th>
                        <th style="text-align:right;">Réglé</th>
                        <th style="text-align:right;">Reste à payer</th>
                        <th>Ancienneté</th>
                        {{-- Les deux colonnes du report. Ni l'une ni l'autre n'est stockée :
                             les deux se déduisent de l'année de la créance. --}}
                        <th>Année antérieure</th>
                        <th>Report</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes->forPage($page, 15) as $ligne)
                        @php
                            $reste = Recouvrement::reste($ligne);
                            $age = Recouvrement::anciennete($ligne, $this->arrete);
                            $reportee = EtatDesImpayes::estReportee($ligne, $this->annee);
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->assureur ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td>{{ $ligne->site?->nom ?? '— à rattacher —' }}</td>
                            <td>{{ $ligne->courtier ?? '—' }}</td>
                            <td>{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_facture ?? '—' }}</td>
                            <td>{{ $ligne->n_sinistre ?? '—' }}</td>
                            <td>{{ $ligne->immatriculation ?? '—' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($ligne->montant) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">
                                {{ ae((int) ($ligne->encaissements_sum_montant ?? 0)) }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $reste >= Recouvrement::SEUIL_SOLDE ? '#C8102E' : '#6B6E76' }};">
                                {{ ae($reste) }}
                            </td>
                            <td>
                                @if ($reste < Recouvrement::SEUIL_SOLDE)
                                    <span style="color:#0E9F6E; font-weight:600;">Soldée</span>
                                @else
                                    {{ $age !== null ? $age.' j' : '—' }}
                                    <div style="font-size:11px; color:#6B6E76;">{{ EtatDesImpayes::trancheDuFichier($age) }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($reportee)
                                    <span style="font-weight:700; color:#B87A00;">Oui</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="color:#B87A00; font-weight:600;">
                                {{ EtatDesImpayes::libelleReport($ligne, $this->annee) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="15"
                            texte="Aucune créance dans l'état {{ $this->annee }}. Le bouton « Ajouter une créance » ouvre la saisie." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="15" />
    </div>
</div>

<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| État des impayés
|--------------------------------------------------------------------------
| L'écran qui remplace le classeur.
|
| **Ce que cet état recense : les factures physiquement déposées chez le client.** Ce
| n'est pas le registre de ce qui a été facturé, c'est celui de ce qui a été remis, donc
| réclamable. On ne relance pas quelqu'un sur une facture qu'il n'a jamais reçue.
|
| Deux conséquences, qui commandent tout l'écran :
|
|   - **la date de réception est la date du dépôt chez le client** — c'est elle qui ouvre
|     le droit de réclamer, elle est donc exigée, et c'est d'elle que part l'ancienneté ;
|   - **tout se saisit à la main, le numéro de facture compris.** Il se lit sur la facture
|     papier, pas dans le logiciel d'atelier — d'où des entiers simples, « 17 », « 23 »,
|     remis à zéro chaque année, qui n'identifient rien à eux seuls.
|
| Trois portes d'entrée, et une seule table :
|
|   - **Ajouter** une créance qui n'existe nulle part ailleurs ;
|   - **Porter** à l'état une facture que l'application connaît déjà — CATTC importé, saisie
|     du jour, bloc « Facture » du recouvrement — en disant seulement quand elle a été déposée
|     et ce qui a déjà été payé ;
|   - la **reprise** du classeur, par l'import, qui reste en place.
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
|     autres ;
|   - un doublon est refusé à la frappe, sur la clé que le classeur n'utilisait qu'après
|     coup ;
|   - une créance sans date ni numéro est refusée : 45 lignes du fichier n'ont aucune date
|     exploitable, 142 aucun numéro.
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

    /*
     * La ligne qu'on modifie, ou null pour une saisie neuve. C'est un identifiant venu du
     * navigateur : il n'est jamais cru sur parole, chaque action relit la ligne dans le
     * périmètre du compte avant d'y toucher.
     */
    'enModification' => null,

    // Les colonnes du classeur, dans son ordre et sous ses mots — voir
    // EtatDesImpayes::COLONNES_DU_FICHIER. Le superviseur de veille tient ce fichier depuis
    // quatre ans : lui présenter ses propres colonnes dans un autre ordre reviendrait à lui
    // demander de réapprendre son travail.
    'fAssureur' => '',
    'fClient' => '',
    'fSiteId' => '',
    'fVilleId' => '',
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

    // Porter une facture existante.
    'porterOuvert' => false,
    'porterRecherche' => '',
    'porterFactureId' => null,
    'pDateReception' => '',
    'pSiteId' => '',
    'pVilleId' => '',
    'pSinistre' => '',
    'pBanque' => '',
    'pCommentaires' => '',
    'pRegle' => '',
    'pModeReglement' => '',
    'pDateReglement' => '',
    'pPasLeMemeDossier' => false,
]);

mount(function () {
    $this->exercice ??= EtatDesImpayes::exerciceOuvert(auth()->user()->entreprise_id);

    // « Modifier » depuis la page de détail d'une créance arrive ici, formulaire ouvert.
    // L'identifiant passe par `modifier()`, qui le relit dans le périmètre du compte.
    $aModifier = request()->integer('modifier');

    if ($aModifier > 0) {
        $this->modifier($aModifier);
    }
});

$updatedVilleFiltre = function () { $this->siteFiltre = ''; $this->page = 1; };
$updatedSiteFiltre = function () { $this->page = 1; };
$updatedExercice = function () { $this->page = 1; };
$updatedStatutFiltre = function () { $this->page = 1; };
$updatedReportFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };
$updatedPorterRecherche = function () { $this->porterFactureId = null; };

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

/** Ce qu'on regarde : le périmètre, réduit par les filtres. */
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));

/**
 * Ce qu'on a le droit de toucher : le périmètre entier, **sans** les filtres.
 *
 * Les actions — détail, modifier, porter — relisent la ligne ici et non dans la liste
 * affichée. Un filtre est un réglage d'affichage ; s'en servir comme garde reviendrait à
 * laisser la sécurité dépendre de ce qu'on a choisi de regarder.
 */
$idsSitesDuCompte = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), '', ''));
$idsVillesDuCompte = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSitesDuCompte));

/**
 * Les ateliers où l'on peut rattacher une créance.
 *
 * `Site::visiblesPour()` et non `sitesConsultables()` : la seconde ajoute les ateliers qu'on a
 * **occupés par le passé**, ce qui est juste pour lire un historique et faux pour écrire. Son
 * propre commentaire le dit — aucun écran de saisie ne doit l'appeler.
 */
$sitesSaisissables = computed(fn () => Site::visiblesPour(auth()->user())->sortBy('nom')->pluck('nom', 'id')->all());

/** Les villes de ces ateliers — pour une créance dont on connaît la ville mais pas l'atelier. */
$villesSaisissables = computed(fn () => Ville::query()
    ->whereIn('id', Site::visiblesPour(auth()->user())->pluck('ville_id')->filter()->unique())
    ->orderBy('nom')->pluck('nom', 'id')->all());

$modes = computed(fn () => Referentiel::options(Referentiel::MODE_RECOUVREMENT));

/**
 * Les lignes de l'état de l'année regardée.
 *
 * Le filtre par lieu laisse passer les créances dont on ignore jusqu'à la ville : les cacher
 * reviendrait à cacher précisément celles qu'il faut situer. Celles dont la ville est connue
 * ne s'affichent, elles, que dans leur ville — voir EtatDesImpayes::dansLePerimetre().
 */
$lignes = computed(function () {
    $requete = EtatDesImpayes::dansLePerimetre(
        EtatDesImpayes::requete($this->annee)
            ->withSum('encaissements', 'montant')
            ->with(['site.ville', 'ville']),
        $this->idsSites,
        $this->idsVilles,
    );

    if ($this->recherche !== '') {
        $terme = '%'.trim($this->recherche).'%';

        $requete->where(fn ($sous) => $sous
            ->where('client', 'like', $terme)
            ->orWhere('assureur', 'like', $terme)
            ->orWhere('courtier', 'like', $terme)
            ->orWhere('numero', 'like', $terme)
            ->orWhere('n_facture', 'like', $terme)
            ->orWhere('immatriculation', 'like', $terme)
            ->orWhere('n_sinistre', 'like', $terme)
            ->orWhere('observations', 'like', $terme));
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

/** La ligne en cours de modification, relue dans le périmètre du compte. */
$ligneModifiee = computed(fn () => $this->enModification === null ? null : EtatDesImpayes::dansLePerimetre(
    Facture::query()->whereNotNull('exercice_impayes')->withSum('encaissements', 'montant'),
    $this->idsSitesDuCompte,
    $this->idsVillesDuCompte,
)->find((int) $this->enModification));

$verrouilles = computed(fn () => $this->ligneModifiee ? EtatDesImpayes::champsVerrouilles($this->ligneModifiee) : []);

/**
 * Les factures qu'on peut porter à l'état : connues de l'application, pas encore déposées.
 *
 * On ne cherche qu'à partir de deux caractères, et vingt résultats au plus : l'application
 * compte plus de onze mille factures, et une liste qui les déroulerait toutes ne servirait à
 * personne.
 */
$candidatsAPorter = computed(function () {
    $terme = trim($this->porterRecherche);

    if (mb_strlen($terme) < 2) {
        return collect();
    }

    $motif = '%'.$terme.'%';

    return EtatDesImpayes::dansLePerimetre(
        Facture::query()->whereNull('exercice_impayes')->withSum('encaissements', 'montant')->with('site'),
        $this->idsSitesDuCompte,
        $this->idsVillesDuCompte,
    )
        ->where(fn ($q) => $q
            ->where('n_facture', 'like', $motif)
            ->orWhere('numero', 'like', $motif)
            ->orWhere('client', 'like', $motif)
            ->orWhere('assureur', 'like', $motif)
            ->orWhere('courtier', 'like', $motif)
            ->orWhere('immatriculation', 'like', $motif))
        ->orderByDesc('date')->orderByDesc('id')
        ->limit(20)
        ->get();
});

$factureAPorter = computed(fn () => $this->porterFactureId === null ? null : EtatDesImpayes::dansLePerimetre(
    Facture::query()->whereNull('exercice_impayes')->withSum('encaissements', 'montant')->with('site'),
    $this->idsSitesDuCompte,
    $this->idsVillesDuCompte,
)->find((int) $this->porterFactureId));

/**
 * Les lignes de l'état qui ressemblent à la facture qu'on s'apprête à porter.
 *
 * Le CATTC et le classeur parlent des mêmes affaires sans partager de numéro : la même facture
 * peut donc déjà figurer à l'état sous « 17 », reprise du classeur, pendant qu'on la cherche
 * sous « FA -5713 ». La porter une seconde fois compterait deux fois la même créance. Le pont
 * est celui du rapprochement — immatriculation et montant — et il n'est pas unique : on
 * montre les lignes qu'il trouve et on demande de confirmer, sans refuser d'office.
 */
$semblables = computed(function () {
    $facture = $this->factureAPorter;
    $cle = $facture ? EtatDesImpayes::clePont($facture) : null;

    if ($cle === null) {
        return collect();
    }

    return Facture::query()
        ->whereNotNull('exercice_impayes')
        ->where('montant', (int) $facture->montant)
        ->whereNotNull('immatriculation')
        ->get(['id', 'numero', 'n_facture', 'date', 'client', 'immatriculation', 'montant', 'exercice_impayes'])
        ->filter(fn (Facture $f) => EtatDesImpayes::clePont($f) === $cle)
        ->values();
});

/*
 * Les champs se vident par affectation, et non par `reset()`.
 *
 * Dans un composant Volt, `reset()` rend à une propriété la valeur par défaut de sa
 * **déclaration** — null — et non celle posée par `state()`, qui est une chaîne vide. La
 * différence paraît nulle, elle ne l'est pas : la règle « un règlement sans montant n'exige ni
 * mode ni date » compare à la chaîne vide, et un null la faisait tomber. Mesuré : après un
 * premier enregistrement, corriger un simple commentaire réclamait un mode de règlement.
 */
$viderLeFormulaire = function () {
    foreach ([
        'fAssureur', 'fClient', 'fSiteId', 'fVilleId', 'fCourtier', 'fDateReception', 'fDate', 'fNumero',
        'fSinistre', 'fVehicule', 'fImmatriculation', 'fMontant', 'fRegle', 'fModeReglement',
        'fDateReglement', 'fBanque', 'fCommentaires',
    ] as $champ) {
        $this->{$champ} = '';
    }

    $this->enModification = null;
    $this->resetErrorBag();
};

$basculerFormulaire = function () {
    $ouvrir = ! $this->formulaireOuvert || $this->enModification !== null;

    $this->viderLeFormulaire();
    $this->formulaireOuvert = $ouvrir;
    $this->porterOuvert = false;

    if ($ouvrir) {
        $this->fDate = now()->toDateString();
    }
};

/** Ouvre le formulaire sur une ligne existante. */
$modifier = function (int $id) {
    $this->viderLeFormulaire();
    $this->enModification = $id;
    unset($this->ligneModifiee, $this->verrouilles);

    $ligne = $this->ligneModifiee;

    if ($ligne === null) {
        $this->enModification = null;
        $this->dispatch('annonce', texte: "Cette créance n'est pas dans votre périmètre, ou n'existe plus.");

        return;
    }

    $this->fAssureur = (string) $ligne->assureur;
    $this->fClient = (string) $ligne->client;
    $this->fSiteId = (string) ($ligne->site_id ?? '');
    $this->fVilleId = (string) ($ligne->ville_id ?? '');
    $this->fCourtier = (string) $ligne->courtier;
    $this->fDateReception = $ligne->date_reception?->toDateString() ?? '';
    $this->fDate = $ligne->date?->toDateString() ?? '';
    $this->fNumero = (string) $ligne->n_facture;
    $this->fSinistre = (string) $ligne->n_sinistre;
    $this->fVehicule = (string) $ligne->vehicule;
    $this->fImmatriculation = (string) $ligne->immatriculation;
    $this->fMontant = (string) $ligne->montant;
    $this->fBanque = (string) $ligne->banque;
    $this->fCommentaires = (string) $ligne->observations;

    $this->formulaireOuvert = true;
    $this->porterOuvert = false;
};

/**
 * Enregistre une créance — neuve ou modifiée — et le règlement qui va avec, s'il y en a un.
 *
 * **Le montant réglé devient un encaissement, jamais une colonne.** C'est ce qui fait que le
 * reste à payer se calcule partout de la même façon, et que cette créance apparaît dans la
 * balance âgée, l'extrait de compte et la trésorerie sans le moindre rapprochement. En
 * modification, le champ enregistre donc un **nouveau** règlement : les règlements déjà
 * passés ne se réécrivent pas ici, ils ont leur propre trace en caisse.
 *
 * Les deux écritures sont dans la même transaction : une créance enregistrée dont le
 * règlement se serait perdu en route se lirait comme intégralement due, et l'on relancerait
 * un client qui a payé.
 */
$enregistrer = function () {
    $ligne = null;

    if ($this->enModification !== null) {
        unset($this->ligneModifiee, $this->verrouilles);
        $ligne = $this->ligneModifiee;

        if ($ligne === null) {
            $this->addError('fNumero', "Cette créance n'est pas dans votre périmètre, ou n'existe plus.");

            return;
        }

        /*
         * Les colonnes verrouillées reprennent leur valeur en base, quoi qu'envoie le
         * navigateur. Un champ désactivé à l'écran n'est pas une protection : il suffit de
         * réécrire la requête pour qu'il arrive rempli.
         */
        foreach ($this->verrouilles as $colonne) {
            match ($colonne) {
                'date' => $this->fDate = $ligne->date?->toDateString() ?? '',
                'n_facture' => $this->fNumero = (string) $ligne->n_facture,
                'immatriculation' => $this->fImmatriculation = (string) $ligne->immatriculation,
                'montant' => $this->fMontant = (string) $ligne->montant,
            };
        }
    }

    $sitesPermis = array_keys($this->sitesSaisissables);

    // Une ligne déjà rattachée à un atelier qu'on ne gère plus garde le droit d'y rester.
    if ($ligne?->site_id !== null) {
        $sitesPermis[] = $ligne->site_id;
    }

    $donnees = $this->validate([
        // Le classeur tolérait l'absence de date et de numéro — 385 et 375 lignes. Ici les
        // deux sont exigés : une créance qu'on ne peut ni dater ni nommer ne se réclame pas.
        'fDate' => ['required', 'date', 'before_or_equal:today', 'after:2015-01-01'],
        'fNumero' => ['required', 'string', 'max:60'],
        'fClient' => ['required', 'string', 'max:255'],
        // L'atelier est exigé à la saisie. En modification il peut rester vide : 3 771 lignes
        // reprises du classeur n'en ont pas, et les forcer à en choisir un pour corriger un
        // commentaire ferait inventer un atelier à celui qui ne le connaît pas.
        'fSiteId' => [$ligne === null ? 'required' : 'nullable', Rule::in($sitesPermis)],
        'fVilleId' => ['nullable', Rule::in(array_keys($this->villesSaisissables))],
        'fMontant' => ['required', 'integer', 'min:1'],
        'fRegle' => ['nullable', 'integer', 'min:0', 'lte:fMontant'],
        /*
         * Obligatoire, depuis la décision du 16/09/2026 : c'est la date du dépôt, donc ce
         * qui fait d'une facture une créance de cet état, et le point de départ de son âge.
         */
        'fDateReception' => ['required', 'date', 'after_or_equal:fDate', 'before_or_equal:today'],
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
    ], [
        'fDateReception.required' => 'La date de réception est obligatoire : c\'est la date du dépôt chez le client.',
    ], [
        'fDate' => "date d'édition", 'fNumero' => 'numéro de la facture', 'fClient' => 'client',
        'fSiteId' => 'site', 'fVilleId' => 'ville', 'fMontant' => 'montant TTC', 'fRegle' => 'montant réglé',
        'fDateReception' => 'date de réception', 'fModeReglement' => 'mode de règlement',
        'fDateReglement' => 'date de règlement', 'fAssureur' => 'assureur', 'fCourtier' => 'courtier',
        'fSinistre' => 'numéro de sinistre', 'fVehicule' => 'véhicule',
        'fImmatriculation' => 'immatriculation', 'fBanque' => 'banque',
    ]);

    $date = Carbon::parse($donnees['fDate']);
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
    $doublon = Facture::where('n_facture', trim($donnees['fNumero']))
        ->whereDate('date', $date)
        ->where('montant', $montant)
        ->when($immatriculation !== '', fn ($q) => $q->where('immatriculation', $immatriculation))
        ->when($ligne !== null, fn ($q) => $q->whereKeyNot($ligne->id))
        ->first();

    if ($doublon !== null) {
        $this->addError('fNumero', 'Cette créance existe déjà sous la référence '.$doublon->numero.' — même numéro, même date, même montant.');

        return;
    }

    $siteId = $donnees['fSiteId'] !== '' && $donnees['fSiteId'] !== null ? (int) $donnees['fSiteId'] : null;

    $valeurs = [
        'site_id' => $siteId,
        // Sans atelier, la ville choisie ; avec un atelier, le modèle la déduit de lui.
        'ville_id' => $siteId === null && $donnees['fVilleId'] ? (int) $donnees['fVilleId'] : null,
        'n_facture' => trim($donnees['fNumero']),
        'date' => $date,
        'date_reception' => $donnees['fDateReception'],
        /*
         * L'année de la créance est celle de sa facture, et non celle de l'écran depuis
         * lequel on la saisit. Une facture de décembre 2025 relevée en 2026 apparaît donc
         * d'emblée dans l'état 2026 sous « Reporté 2025 », ce qui est la vérité.
         */
        'exercice_impayes' => (int) $date->format('Y'),
        'client' => trim($donnees['fClient']),
        'assureur' => $donnees['fAssureur'] ?: null,
        'courtier' => $donnees['fCourtier'] ?: null,
        'banque' => $donnees['fBanque'] ?: null,
        'vehicule' => $donnees['fVehicule'] ?: null,
        'immatriculation' => $immatriculation ?: null,
        'n_sinistre' => $donnees['fSinistre'] ?: null,
        'montant' => $montant,
        'observations' => $donnees['fCommentaires'] ?: null,
    ];

    $refus = DB::transaction(function () use ($ligne, $valeurs, $montant, $regle, $donnees) {
        $entrepriseId = auth()->user()->entreprise_id;

        if ($ligne === null) {
            $facture = Facture::create($valeurs + [
                'entreprise_id' => $entrepriseId,
                /*
                 * Sa propre série : IMP-1509-0001. Elle vit dans la même table que les
                 * factures de l'atelier, et c'est justement pourquoi elle porte un autre
                 * préfixe — deux origines qui se lisent pareil dans un tableau finissent par
                 * se confondre au téléphone.
                 */
                'numero' => GenerateurNumero::suivant($entrepriseId, EtatDesImpayes::SERIE, $valeurs['date']),
                'est_etat_initial' => false,
                'activite' => EtatDesImpayes::activiteDeduite($donnees['fSinistre'] ?? null),
                'type' => 'FNE',
                'cree_par' => auth()->id(),
            ]);
            $encaisse = 0;
        } else {
            /*
             * Verrou le temps du contrôle : un encaissement saisi en caisse au même instant
             * pourrait sinon faire passer le montant sous ce qui est déjà encaissé.
             */
            $facture = Facture::whereKey($ligne->id)->lockForUpdate()->first();
            $encaisse = (int) $facture->encaissements()->sum('montant');

            if ($montant < $encaisse) {
                return ['fMontant', 'Le montant ne peut pas descendre sous ce qui est déjà encaissé ('.ae($encaisse).').'];
            }

            // La ligne reprise garde sa marque d'origine ; son activité ne change que si le
            // numéro de sinistre apparaît ou disparaît.
            $facture->fill($valeurs + [
                'activite' => EtatDesImpayes::activiteDeduite($donnees['fSinistre'] ?? null),
            ])->save();
        }

        if ($regle > $montant - $encaisse) {
            return ['fRegle', 'Le règlement dépasse le reste à payer ('.ae(max(0, $montant - $encaisse)).').'];
        }

        if ($regle > 0) {
            Encaissement::create([
                'entreprise_id' => $entrepriseId,
                'site_id' => $facture->site_id,
                'facture_id' => $facture->id,
                'date' => $donnees['fDateReglement'],
                'montant' => $regle,
                'type' => 'Client',
                'moyen' => $donnees['fModeReglement'],
                'client' => $facture->tiersPayant(),
                'activite' => $facture->activite,
                'reference_origine' => $facture->n_facture,
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
            ->log($ligne === null ? 'État des impayés — créance saisie' : 'État des impayés — créance modifiée');

        return $facture->numero;
    });

    // Un refus rend un couple [champ, message] ; la transaction a déjà tout annulé.
    if (is_array($refus)) {
        $this->addError($refus[0], $refus[1]);

        return;
    }

    $modifiee = $ligne !== null;

    $this->viderLeFormulaire();

    if ($modifiee) {
        $this->formulaireOuvert = false;
    } else {
        // En saisie, on enchaîne : on garde l'atelier, le tiers et la date d'édition, qui se
        // répètent d'une ligne à l'autre quand on retape une série.
        $this->fDate = $valeurs['date']->toDateString();
        $this->fSiteId = (string) ($valeurs['site_id'] ?? '');
        $this->fClient = $valeurs['client'];
        $this->fAssureur = (string) $valeurs['assureur'];
        $this->fCourtier = (string) $valeurs['courtier'];
    }

    unset($this->lignes, $this->totaux, $this->apercuNumero, $this->exercices);

    $this->page = $modifiee ? $this->page : 1;

    $this->dispatch('annonce', texte: 'Créance '.$refus.($modifiee ? ' modifiée' : ' enregistrée')
        .($regle > 0 ? ' — le règlement de '.ae($regle).' entre aussitôt en trésorerie.' : '.'));
};

$basculerPortage = function () {
    $this->porterOuvert = ! $this->porterOuvert;
    $this->formulaireOuvert = false;
    $this->viderLeFormulaire();

    // Par affectation, pour la raison dite plus haut.
    foreach ([
        'porterRecherche', 'pDateReception', 'pSiteId', 'pVilleId', 'pSinistre', 'pBanque',
        'pCommentaires', 'pRegle', 'pModeReglement', 'pDateReglement',
    ] as $champ) {
        $this->{$champ} = '';
    }

    $this->porterFactureId = null;
    $this->pPasLeMemeDossier = false;
};

$choisirAPorter = function (int $id) {
    $this->porterFactureId = $id;
    unset($this->factureAPorter, $this->semblables);

    $facture = $this->factureAPorter;

    if ($facture === null) {
        $this->porterFactureId = null;

        return;
    }

    $this->pSinistre = (string) $facture->n_sinistre;
    $this->pSiteId = (string) ($facture->site_id ?? '');
    $this->pVilleId = (string) ($facture->ville_id ?? '');
    $this->pPasLeMemeDossier = false;
    $this->resetErrorBag();
};

/**
 * Porte à l'état une facture que l'application connaît déjà.
 *
 * **Rien n'est créé, sauf le règlement déclaré.** La facture existe — importée du CATTC, saisie
 * par l'atelier, ou créée au recouvrement. La porter, c'est dire qu'elle a été déposée chez le
 * client, et quand : elle reçoit son année d'état et sa date de réception, et entre dès lors
 * dans l'état, la balance âgée et les relances. Sa référence, son numéro, son montant et sa
 * date d'édition ne bougent pas ; ils appartiennent à l'écran qui l'a fait naître.
 *
 * **Le règlement déjà reçu se déclare dans le même geste.** Une facture du CATTC arrive sans
 * un seul encaissement : la porter sans dire ce qui a été payé la ferait lire intégralement
 * due, et c'est exactement l'erreur que le recouvrement avait écartée en laissant le CATTC
 * dehors — 1 389 635 332 F comptés comme créance au lieu de 5 046 731 F.
 */
$porter = function () {
    unset($this->factureAPorter, $this->semblables);
    $facture = $this->factureAPorter;

    if ($facture === null) {
        $this->addError('porterRecherche', "Choisissez d'abord une facture dans la liste.");

        return;
    }

    $sitesPermis = array_keys($this->sitesSaisissables);

    if ($facture->site_id !== null) {
        $sitesPermis[] = $facture->site_id;
    }

    $donnees = $this->validate([
        'pDateReception' => ['required', 'date', 'after_or_equal:'.$facture->date?->toDateString(), 'before_or_equal:today'],
        'pSiteId' => ['nullable', Rule::in($sitesPermis)],
        'pVilleId' => ['nullable', Rule::in(array_keys($this->villesSaisissables))],
        'pSinistre' => ['nullable', 'string', 'max:60'],
        'pBanque' => ['nullable', 'string', 'max:120'],
        'pCommentaires' => ['nullable', 'string', 'max:255'],
        'pRegle' => ['nullable', 'integer', 'min:0'],
        'pModeReglement' => ['exclude_if:pRegle,', 'required_unless:pRegle,0', Rule::in(array_keys($this->modes))],
        'pDateReglement' => ['exclude_if:pRegle,', 'required_unless:pRegle,0', 'date', 'before_or_equal:today'],
    ], [
        'pDateReception.required' => "La date de réception est obligatoire : c'est la date du dépôt chez le client.",
        'pDateReception.after_or_equal' => "Une facture ne se dépose pas avant d'avoir été éditée (le ".$facture->date?->format('d/m/Y').').',
    ], [
        'pDateReception' => 'date de réception', 'pSiteId' => 'site', 'pVilleId' => 'ville',
        'pRegle' => 'montant déjà réglé', 'pModeReglement' => 'mode de règlement', 'pDateReglement' => 'date de règlement',
    ]);

    if ($this->semblables->isNotEmpty() && ! $this->pPasLeMemeDossier) {
        $this->addError('pPasLeMemeDossier', "Une ligne de l'état porte déjà la même immatriculation et le même montant. "
            .'Vérifiez qu\'il ne s\'agit pas de la même facture, puis cochez la case pour confirmer.');

        return;
    }

    $regle = (int) ($donnees['pRegle'] ?? 0);

    $refus = DB::transaction(function () use ($facture, $donnees, $regle) {
        $verrouillee = Facture::whereKey($facture->id)->lockForUpdate()->first();

        // Deux personnes peuvent porter la même facture au même moment : la seconde échoue.
        if ($verrouillee === null || $verrouillee->exercice_impayes !== null) {
            return ['porterRecherche', "Cette facture vient d'être portée à l'état par quelqu'un d'autre."];
        }

        $reste = max(0, (int) $verrouillee->montant - (int) $verrouillee->encaissements()->sum('montant'));

        if ($regle > $reste) {
            return ['pRegle', 'Le règlement dépasse le reste à payer ('.ae($reste).').'];
        }

        $siteId = $donnees['pSiteId'] ? (int) $donnees['pSiteId'] : $verrouillee->site_id;
        $commentaire = trim((string) ($donnees['pCommentaires'] ?? ''));
        $observations = trim((string) $verrouillee->observations);

        $verrouillee->fill([
            'exercice_impayes' => (int) $verrouillee->date->format('Y'),
            'est_etat_initial' => false,
            'date_reception' => $donnees['pDateReception'],
            'site_id' => $siteId,
            'ville_id' => $siteId === null && $donnees['pVilleId'] ? (int) $donnees['pVilleId'] : $verrouillee->ville_id,
            'n_sinistre' => $donnees['pSinistre'] ?: $verrouillee->n_sinistre,
            'banque' => $donnees['pBanque'] ?: $verrouillee->banque,
            // Une note déjà posée sur la facture ne s'efface pas : on écrit à la suite.
            'observations' => $commentaire === '' ? ($observations ?: null)
                : mb_substr($observations === '' ? $commentaire : $observations.' — '.$commentaire, 0, 255),
        ])->save();

        if ($regle > 0) {
            Encaissement::create([
                'entreprise_id' => $verrouillee->entreprise_id,
                'site_id' => $verrouillee->site_id,
                'facture_id' => $verrouillee->id,
                'date' => $donnees['pDateReglement'],
                'montant' => $regle,
                'type' => 'Client',
                'moyen' => $donnees['pModeReglement'],
                'client' => $verrouillee->tiersPayant(),
                'activite' => $verrouillee->activite,
                'reference_origine' => $verrouillee->n_facture,
                'cree_par' => auth()->id(),
            ]);
        }

        activity()->causedBy(auth()->user())
            ->performedOn($verrouillee)
            ->withProperties([
                'reference' => $verrouillee->numero,
                'n_facture' => $verrouillee->n_facture,
                'provenance' => EtatDesImpayes::provenance($verrouillee),
                'date_reception' => $donnees['pDateReception'],
                'regle_declare' => $regle,
                'confirme_pas_un_doublon' => (bool) $this->pPasLeMemeDossier,
            ])
            ->log('État des impayés — facture portée');

        return $verrouillee;
    });

    if (is_array($refus)) {
        $this->addError($refus[0], $refus[1]);

        return;
    }

    $this->basculerPortage();
    $this->porterOuvert = false;

    // L'état qu'on regarde doit montrer la facture qu'on vient d'y porter.
    $this->exercice = (int) $refus->exercice_impayes;

    unset($this->lignes, $this->totaux, $this->exercices, $this->candidatsAPorter);

    $this->dispatch('annonce', texte: 'Facture n° '.$refus->n_facture.' portée à l\'état '.$refus->exercice_impayes
        .($regle > 0 ? ' — le règlement de '.ae($regle).' entre aussitôt en trésorerie.' : '.'));
};

?>

<div>
    <x-titre-ecran titre="État des impayés"
        sous-titre="Les factures déposées chez le client et leurs règlements, année par année. Ce qui n'est pas soldé se reporte de lui-même sur l'année suivante." />

    {{-- Le bandeau : l'année regardée, le périmètre, et les boutons qui ouvrent la saisie. --}}
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
                placeholder="Client, assureur, n° facture, immatriculation, commentaire…" />

            <button type="button" wire:click="basculerFormulaire" class="bouton"
                style="padding:9px 16px; white-space:nowrap;">
                {{ $formulaireOuvert && $enModification === null ? 'Fermer le formulaire' : '+ Ajouter une créance' }}
            </button>

            <button type="button" wire:click="basculerPortage" class="bouton bouton-secondaire"
                style="padding:9px 16px; white-space:nowrap;">
                {{ $porterOuvert ? 'Fermer' : 'Porter une facture existante' }}
            </button>

            <a href="{{ route('impayes.etat-initial') }}" class="bouton bouton-secondaire"
                style="padding:9px 16px; white-space:nowrap; text-decoration:none;">Tableau état initial</a>
        </div>
    </div>

    {{-- Le formulaire : les colonnes du classeur, dans son ordre et sous ses mots. --}}
    @if ($formulaireOuvert)
        @php $modif = $this->ligneModifiee; $verrou = $this->verrouilles; @endphp

        <div class="carte" style="margin-bottom:16px; border-left:3px solid var(--th-accent,#C8102E);">
            @if ($modif)
                <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Modifier la créance {{ $modif->numero }}</h3>
                <p style="font-size:12.5px; color:#6B6E76; margin:0 0 12px; line-height:1.55;">
                    Origine : <strong>{{ EtatDesImpayes::origine($modif) }}</strong>.
                    Déjà encaissé : <strong>{{ ae((int) ($modif->encaissements_sum_montant ?? 0)) }}</strong>.
                    @if ($verrou !== [])
                        La date d'édition, le numéro, l'immatriculation et le montant sont
                        <strong>verrouillés</strong> : c'est par eux que l'import reconnaît cette ligne,
                        et les changer ici ferait créer un doublon au prochain dépôt du classeur. Ils se
                        corrigent dans le fichier.
                    @endif
                    « Montantréglé » enregistre un <strong>nouveau</strong> règlement, qui s'ajoute aux
                    précédents — les règlements passés ne se réécrivent pas ici. Chaque modification est
                    tracée, avec l'avant et l'après, dans le détail de la ligne.
                </p>
            @else
                <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Nouvelle créance</h3>
                <p style="font-size:12.5px; color:#6B6E76; margin:0 0 12px; line-height:1.55;">
                    Référence <strong>{{ $this->apercuNumero }}</strong>, posée à l'enregistrement.
                    On saisit ici une <strong>facture déposée chez le client</strong> : sa date de
                    réception, obligatoire, est celle du dépôt — elle ouvre le droit de réclamer, et
                    l'ancienneté se compte à partir d'elle. Le reste à payer ne se saisit pas : il se
                    déduit du montant TTC et des règlements. La date de réception ne peut pas précéder
                    l'édition, le montant réglé ne peut pas dépasser le TTC, et un numéro de sinistre
                    range la créance en Sinistre. Pour une facture qui existe déjà dans l'application,
                    utilisez « Porter une facture existante ».
                </p>
            @endif

            {{-- Deux rangées, et pas quatre.
                 Les colonnes du classeur tiennent en deux bandes — l'affaire, puis l'argent —
                 parce que la saisie se fait en série : on reprend une ligne du tableur et on la
                 retape. `flex-wrap` garde le formulaire utilisable sur un écran étroit. --}}
            <form wire:submit.prevent="enregistrer">
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:flex-start;">
                    <x-champ label="ASSUREUR" model="fAssureur" width="150" />
                    <x-champ label="Client" model="fClient" :requis="true" width="175" />
                    <x-champ label="SITE" model="fSiteId" type="select" :options="$this->sitesSaisissables" vide="— choisir —" :requis="! $modif" width="155" />
                    @if ($modif && $fSiteId === '')
                        <x-champ label="Ville (sans atelier)" model="fVilleId" type="select" :options="$this->villesSaisissables" vide="— à préciser —" width="150" />
                    @endif
                    <x-champ label="Courtier" model="fCourtier" width="150" />
                    <x-champ label="Date d'édition" model="fDate" type="date" :requis="true" width="140" :disabled="in_array('date', $verrou, true)" />
                    <x-champ label="Date de réception" model="fDateReception" type="date" :requis="true" width="140" />
                    <x-champ label="N° de la facture" model="fNumero" :requis="true" width="135" :disabled="in_array('n_facture', $verrou, true)" />
                    <x-champ label="Numéro Sinistre" model="fSinistre" width="145" />
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; align-items:flex-start;">
                    <x-champ label="Vehicule" model="fVehicule" width="150" />
                    <x-champ label="Immatriculation" model="fImmatriculation" width="140" :disabled="in_array('immatriculation', $verrou, true)" />
                    <x-champ label="montantTTC" model="fMontant" type="number" :requis="true" width="130" :disabled="in_array('montant', $verrou, true)" />
                    <x-champ :label="$modif ? 'Nouveau règlement' : 'Montantréglé'" model="fRegle" type="number" width="130" />
                    <x-champ label="Modederèglement" model="fModeReglement" type="select" :options="$this->modes" vide="— aucun —" width="160" />
                    <x-champ label="Datederèglement" model="fDateReglement" type="date" width="140" />
                    <x-champ label="banque" model="fBanque" width="140" />
                    <x-champ label="Commentaires" model="fCommentaires" width="185" />
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <button type="submit" class="bouton" style="padding:9px 18px;">
                        {{ $modif ? 'Enregistrer les modifications' : 'Enregistrer la créance' }}
                    </button>
                    <button type="button" wire:click="basculerFormulaire" class="bouton bouton-secondaire" style="padding:9px 18px;">Annuler</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Porter une facture existante : la communication du reste de l'application vers l'état. --}}
    @if ($porterOuvert)
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Porter une facture existante à l'état</h3>
            <p style="font-size:12.5px; color:#6B6E76; margin:0 0 12px; line-height:1.55;">
                Pour une facture que l'application connaît déjà — <strong>importée du CATTC</strong>, saisie
                par l'atelier dans la <strong>saisie du jour</strong>, ou créée au <strong>recouvrement</strong>
                (bloc « Facture »). Rien n'est retapé : on dit quand elle a été déposée chez le client et
                ce qui a déjà été payé. Son numéro, sa date d'édition et son montant ne changent pas.
                Une facture du CATTC n'apporte <strong>aucun règlement</strong> : si elle a été payée en
                partie, indiquez-le, sinon elle se lira intégralement due.
            </p>

            <x-champ label="Chercher la facture" model="porterRecherche" :live="true"
                placeholder="N° de facture, client, assureur, courtier, immatriculation… (2 caractères au moins)" />

            @if ($this->factureAPorter === null)
                @if (mb_strlen(trim($porterRecherche)) >= 2)
                    <div class="tableau-conteneur" style="margin-top:10px;">
                        <table class="tableau">
                            <thead>
                                <tr>
                                    <th>N° facture</th><th>Date d'édition</th><th>Client</th><th>Immatriculation</th>
                                    <th>Atelier</th><th>Provenance</th>
                                    <th style="text-align:right;">Montant TTC</th><th style="text-align:right;">Déjà encaissé</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->candidatsAPorter as $candidat)
                                    <tr wire:key="porter-{{ $candidat->id }}">
                                        <td style="font-weight:700;">{{ $candidat->n_facture ?? '—' }}</td>
                                        <td>{{ $candidat->date?->format('d/m/Y') ?? '—' }}</td>
                                        <td>{{ $candidat->tiersPayant() }}</td>
                                        <td>{{ $candidat->immatriculation ?? '—' }}</td>
                                        <td>{{ $candidat->site?->nom ?? '—' }}</td>
                                        <td style="font-size:12px;">{{ EtatDesImpayes::provenance($candidat) }}</td>
                                        <td style="text-align:right;">{{ ae($candidat->montant) }}</td>
                                        <td style="text-align:right;">{{ ae((int) ($candidat->encaissements_sum_montant ?? 0)) }}</td>
                                        <td>
                                            <button type="button" wire:click="choisirAPorter({{ $candidat->id }})" class="bouton"
                                                style="padding:5px 12px; font-size:12px;">Choisir</button>
                                        </td>
                                    </tr>
                                @empty
                                    <x-table-vide :colspan="9" texte="Aucune facture hors de l'état ne correspond à cette recherche." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
                @error('porterRecherche') <span class="champ-erreur">{{ $message }}</span> @enderror
            @else
                @php $aPorter = $this->factureAPorter; @endphp

                <div style="margin:12px 0; padding:10px 12px; background:#FBF7EC; border-radius:8px; font-size:13px; line-height:1.6;">
                    <strong>N° {{ $aPorter->n_facture }}</strong> du {{ $aPorter->date?->format('d/m/Y') }}
                    — {{ $aPorter->tiersPayant() }} — {{ $aPorter->immatriculation ?? 'sans immatriculation' }}
                    — <strong>{{ ae($aPorter->montant) }}</strong>, dont {{ ae((int) ($aPorter->encaissements_sum_montant ?? 0)) }} déjà encaissés.
                    <br>Provenance : {{ EtatDesImpayes::provenance($aPorter) }}. Entrera dans l'état {{ $aPorter->date?->format('Y') }}.
                    <button type="button" wire:click="$set('porterFactureId', null)" class="bouton bouton-secondaire"
                        style="padding:3px 10px; font-size:12px; margin-left:8px;">Changer de facture</button>
                </div>

                @if ($this->semblables->isNotEmpty())
                    <div style="margin-bottom:12px; padding:10px 12px; border:1px solid #E7B85C; background:#FFF6E0; border-radius:8px; font-size:12.5px; line-height:1.55;">
                        <strong>Attention — {{ $this->semblables->count() }} ligne(s) de l'état portent la même immatriculation et le même montant</strong>,
                        peut-être la même facture reprise du classeur sous un autre numéro :
                        @foreach ($this->semblables as $s)
                            <div>· {{ $s->numero }} — n° {{ $s->n_facture }} du {{ $s->date?->format('d/m/Y') }}, {{ $s->client }} (état {{ $s->exercice_impayes }})</div>
                        @endforeach
                        <div style="margin-top:6px;">
                            <x-champ type="checkbox" label="J'ai vérifié : ce n'est pas la même facture" model="pPasLeMemeDossier" />
                            @error('pPasLeMemeDossier') <span class="champ-erreur">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif

                <form wire:submit.prevent="porter">
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; align-items:flex-start;">
                        <x-champ label="Date de réception (dépôt)" model="pDateReception" type="date" :requis="true" width="170" />
                        <x-champ label="SITE" model="pSiteId" type="select" :options="$this->sitesSaisissables" vide="— inchangé —" width="155" />
                        @if ($pSiteId === '')
                            <x-champ label="Ville (sans atelier)" model="pVilleId" type="select" :options="$this->villesSaisissables" vide="— à préciser —" width="150" />
                        @endif
                        <x-champ label="Numéro Sinistre" model="pSinistre" width="145" />
                        <x-champ label="Déjà réglé" model="pRegle" type="number" width="120" />
                        <x-champ label="Modederèglement" model="pModeReglement" type="select" :options="$this->modes" vide="— aucun —" width="160" />
                        <x-champ label="Datederèglement" model="pDateReglement" type="date" width="140" />
                        <x-champ label="banque" model="pBanque" width="130" />
                        <x-champ label="Commentaires" model="pCommentaires" width="185" />
                    </div>

                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="bouton" style="padding:9px 18px;">Porter à l'état</button>
                        <button type="button" wire:click="basculerPortage" class="bouton bouton-secondaire" style="padding:9px 18px;">Annuler</button>
                    </div>
                </form>
            @endif
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
        <p style="font-size:12.5px; color:#6B6E76; margin:0 0 14px; line-height:1.55;">
            Une créance non soldée reste affichée les années suivantes sans être recopiée : elle garde
            son année d'origine, et les colonnes « Année antérieure » et « Report » disent d'où elle vient.
            <strong>L'ancienneté</strong> compte les jours depuis le dépôt chez le client (date de
            réception), ou depuis l'édition quand le dépôt n'est pas connu, jusqu'au
            {{ $this->arrete->format('d/m/Y') }} ; la ligne du dessous la range dans les tranches du
            classeur (&lt;30, 30&lt;&gt;60, 60&lt;&gt;90, &gt;90).
        </p>

        {{-- Toutes les colonnes du classeur, sauf les deux qui n'en sont pas : « A », qui ne porte
             rien, et le reste à payer écrit, remplacé par celui qu'on calcule. Mode, date de
             règlement et banque se lisent sur le dernier règlement enregistré. --}}
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>ASSUREUR</th>
                        <th>Client</th>
                        <th>SITE</th>
                        <th>Courtier</th>
                        <th>Date de réception</th>
                        <th>Date d'édition</th>
                        <th>N° facture</th>
                        <th>N° sinistre</th>
                        <th>Véhicule</th>
                        <th>Immatriculation</th>
                        <th style="text-align:right;">Montant TTC</th>
                        <th style="text-align:right;">Réglé</th>
                        <th style="text-align:right;">Reste à payer</th>
                        <th>Mode de règlement</th>
                        <th>Date de règlement</th>
                        <th>Banque</th>
                        <th>Ancienneté</th>
                        <th style="min-width:180px;">Commentaires</th>
                        {{-- Les deux colonnes du report. Ni l'une ni l'autre n'est stockée :
                             les deux se déduisent de l'année de la créance. --}}
                        <th>Année antérieure</th>
                        <th>Report</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Les règlements ne se chargent que pour la page affichée : quinze lignes, et
                        // non les mille trois cents de l'état.
                        $pageLignes = $this->lignes->forPage($page, 15);
                        $pageLignes->load(['encaissements' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id')]);
                    @endphp

                    @forelse ($pageLignes as $ligne)
                        @php
                            $reste = Recouvrement::reste($ligne);
                            $age = Recouvrement::anciennete($ligne, $this->arrete);
                            $reportee = EtatDesImpayes::estReportee($ligne, $this->annee);
                            $dernier = $ligne->encaissements->first();
                        @endphp
                        <tr wire:key="ligne-{{ $ligne->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->assureur ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td>
                                @if ($ligne->site)
                                    {{ $ligne->site->nom }}
                                @elseif ($ligne->ville)
                                    {{ $ligne->ville->nom }}
                                    <div style="font-size:11px; color:#6B6E76;">atelier à préciser</div>
                                @else
                                    <span style="color:#B87A00;">— à préciser —</span>
                                @endif
                            </td>
                            <td>{{ $ligne->courtier ?? '—' }}</td>
                            <td>{{ $ligne->date_reception?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_facture ?? '—' }}</td>
                            <td>{{ $ligne->n_sinistre ?? '—' }}</td>
                            <td>{{ $ligne->vehicule ?? '—' }}</td>
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
                                {{ $dernier?->moyen ?? '—' }}
                                @if ($ligne->encaissements->count() > 1)
                                    <div style="font-size:11px; color:#6B6E76;">{{ $ligne->encaissements->count() }} règlements</div>
                                @endif
                            </td>
                            <td>{{ $dernier?->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->banque ?? '—' }}</td>
                            <td style="white-space:nowrap;">
                                @if ($reste < Recouvrement::SEUIL_SOLDE)
                                    <span style="color:#0E9F6E; font-weight:600;">Soldée</span>
                                @else
                                    {{ $age !== null ? $age.' j' : '—' }}
                                    <div style="font-size:11px; color:#6B6E76;">
                                        {{ EtatDesImpayes::trancheDuFichier($age) }}
                                        · {{ $ligne->date_reception ? 'dépôt' : 'édition' }}
                                    </div>
                                @endif
                            </td>
                            <td style="font-size:12.5px; max-width:260px; white-space:normal;">{{ $ligne->observations ?? '—' }}</td>
                            <td>
                                @if ($reportee)
                                    <span style="font-weight:700; color:#B87A00;">Oui</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="color:#B87A00; font-weight:600; white-space:nowrap;">
                                {{ EtatDesImpayes::libelleReport($ligne, $this->annee) }}
                            </td>
                            <td style="white-space:nowrap;">
                                {{-- Le détail a sa page : il se lit au large, et se rouvre dans un autre onglet. --}}
                                <a href="{{ route('impayes.detail', $ligne->id) }}" wire:navigate class="bouton bouton-secondaire"
                                    style="padding:4px 10px; font-size:12px; text-decoration:none;">Détail</a>
                                <button type="button" wire:click="modifier({{ $ligne->id }})" class="bouton"
                                    style="padding:4px 10px; font-size:12px;">Modifier</button>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="22"
                            texte="Aucune créance dans l'état {{ $this->annee }}. Le bouton « Ajouter une créance » ouvre la saisie." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="15" />
    </div>
</div>

<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Tracabilite\Services\SignatureDeDecision;
use Modules\Noyau\Exploitation\Modeles\SaisieJournaliere;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Commun\Concerns\GereLesDonneesLibres;
use Modules\Noyau\Commun\Modeles\NotificationApp;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Commun\Services\Notificateur;
use Modules\Noyau\Entreprises\Modeles\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{state, computed, mount, uses};

uses([GereLesDonneesLibres::class]);

state([
    'date' => null,
    'siteSaisieId' => null,

    'prosClient' => '', 'prosLocalisation' => '', 'prosMoyen' => 'RDV', 'prosCommercialId' => '',
    // Le véhicule visé : facultatif, et c'est lui qui retrouvera plus tard le devis né de
    // cette visite — le devis suit de trois à cinq jours, et personne ne revient écrire un
    // n° de fiche sur une prospection de la semaine passée.
    'prosImmatriculation' => '', 'prosNFiche' => '',
    'prosActivite' => 'Mécanique', 'prosPassage' => false, 'prosDatePassage' => null,
    'prosDevisApres' => false, 'prosDateDevis' => null, 'prosObs' => '',

    'editionProspectionId' => null,
    'editionProsClient' => '', 'editionProsLocalisation' => '', 'editionProsMoyen' => 'RDV',
    'editionProsCommercialId' => '', 'editionProsActivite' => 'Mécanique',
    'editionProsPassage' => false, 'editionProsDatePassage' => null,
    'editionProsDevisApres' => false, 'editionProsDateDevis' => null,
    'editionProsObs' => '',

    // Validation ou refus d'un devis : le montant retenu, ou le motif, se saisit
    // avant que le statut ne change.
    'devisAValiderId' => null, 'montantValidation' => '',
    'devisARefuserId' => null, 'motifRefusDevis' => '',

    'devisSelection' => [], 'devisBrouillon' => [],
    'factureSelection' => [], 'factureBrouillon' => [],

    // Saisie directe d'un devis qui ne vient d'aucune prospection enregistrée ici.
    'devisLibreOuvert' => false,
    'devLibreClient' => '', 'devLibreCommercialId' => '', 'devLibreActivite' => 'Mécanique',
    'devLibreDateReception' => null, 'devLibreFiche' => '', 'devLibreDateEmission' => null,
    'devLibreStatut' => 'En attente', 'devLibreMontant' => '', 'devLibreMontantValide' => '', 'devLibreObs' => '',

    // Saisie directe d'une facture émise hors application, rattachable à un devis par son numéro.
    'factureLibreOuvert' => false,
    'facLibreClient' => '', 'facLibreCommercialId' => '', 'facLibreActivite' => 'Mécanique',
    'facLibreType' => 'FNE', 'facLibreNumero' => '', 'facLibreRefDevis' => '',
    'facLibreMontant' => '', 'facLibreObs' => '',

    'encType' => 'Client', 'encFactureId' => '', 'encMoyen' => 'Espèces', 'encMontant' => '', 'encClient' => '', 'encTiers' => '',
    'encActivite' => '', 'encReference' => '',

    'chgDate' => null, 'chgTypeOp' => 'Charges', 'chgLibelle' => 'Achats pièces',
    'chgMoyen' => 'Espèces', 'chgMontant' => '', 'chgTiers' => '', 'chgObs' => '',
    'chgActivite' => '', 'chgReference' => '',

    'motifRefus' => [],

    'vehiculesSansFacture' => 0,
    'commentaireProspects' => '', 'commentaireDevis' => '', 'commentaireCA' => '',
    'commentaireTresorerie' => '', 'commentaireCharges' => '',

    'pageProsATraiter' => 1, 'pageProsDuJour' => 1, 'pageAttenteDevis' => 1,
    'pageDevisDuJour' => 1, 'pageDevisEnAttente' => 1, 'pageValidesNonFactures' => 1,
    'pageFacturesDuJour' => 1, 'pageEncaissementsDuJour' => 1, 'pageChargesDuJour' => 1,
]);

/** Lieux dont l'utilisateur a la charge : tous ceux de sa ville, ou le seul qui lui est confié. */
$mesSites = computed(fn () => Site::visiblesPour(auth()->user()));

/**
 * Le lieu sur lequel on saisit. On ne saisit jamais pour deux lieux à la fois : une
 * journée d'atelier se tient dans un endroit précis. Le responsable de ville en choisit
 * un quand sa ville en compte plusieurs (Abidjan) ; ailleurs le choix est sans objet.
 */
$site = computed(fn () => $this->mesSites->firstWhere('id', $this->siteSaisieId) ?? $this->mesSites->first());

$sitesActifs = computed(fn () => $this->site ? collect([$this->site]) : collect());

$siteIdsActifs = computed(fn () => $this->sitesActifs->pluck('id')->all());

/** Vrai si l'exercice est clos pour la ville de ce site à la date sélectionnée : toute saisie y est bloquée. */
$exerciceFerme = computed(fn () => $this->site
    && \Modules\Noyau\Entreprises\Modeles\Exercice::estFerme(auth()->user()->entreprise_id, $this->site->ville_id, $this->date));

mount(function () {
    $this->date = now()->toDateString();
    $this->chgDate = now()->toDateString();
    $this->siteSaisieId = $this->mesSites->first()?->id;
    $this->devLibreDateEmission = $this->date;
    $this->devLibreDateReception = $this->date;
    $this->prosCommercialId = $this->commerciauxSelectables->keys()->first() ?? '';
    $this->chargerSaisieJournaliere();
});

/** Changer de lieu recharge intégralement l'écran : commentaires, listes et brouillons. */
$updatedSiteSaisieId = function () {
    $this->devisSelection = [];
    $this->devisBrouillon = [];
    $this->factureSelection = [];
    $this->factureBrouillon = [];
    $this->reinitialiserPagination();
    $this->chargerSaisieJournaliere();
};

$reinitialiserPagination = function () {
    $this->pageProsATraiter = $this->pageProsDuJour = $this->pageAttenteDevis = 1;
    $this->pageDevisDuJour = $this->pageDevisEnAttente = $this->pageValidesNonFactures = 1;
    $this->pageFacturesDuJour = $this->pageEncaissementsDuJour = $this->pageChargesDuJour = 1;
};

$chargerSaisieJournaliere = function () {
    if (! $this->site) {
        return;
    }

    $saisie = SaisieJournaliere::where('site_id', $this->site->id)->whereDate('date', $this->date)->first();

    $this->vehiculesSansFacture = $saisie?->vehicules_sans_facture ?? 0;
    $this->commentaireProspects = $saisie?->commentaire_prospects ?? '';
    $this->commentaireDevis = $saisie?->commentaire_devis ?? '';
    $this->commentaireCA = $saisie?->commentaire_ca ?? '';
    $this->commentaireTresorerie = $saisie?->commentaire_tresorerie ?? '';
    $this->commentaireCharges = $saisie?->commentaire_charges ?? '';
};

$enregistrerSaisieJournaliere = function () {
    if (! $this->site) {
        return;
    }

    // upsert() atomique : évite la violation de contrainte unique (site_id, date) quand deux
    // champs de la saisie (ex. commentaire + véhicules sans facture) sont modifiés coup sur coup.
    SaisieJournaliere::upsert([[
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $this->site->id,
        'date' => $this->date,
        'vehicules_sans_facture' => (int) $this->vehiculesSansFacture,
        'commentaire_prospects' => $this->commentaireProspects ?: null,
        'commentaire_devis' => $this->commentaireDevis ?: null,
        'commentaire_ca' => $this->commentaireCA ?: null,
        'commentaire_tresorerie' => $this->commentaireTresorerie ?: null,
        'commentaire_charges' => $this->commentaireCharges ?: null,
        'created_at' => now(),
        'updated_at' => now(),
    ]], ['site_id', 'date'], [
        'vehicules_sans_facture', 'commentaire_prospects', 'commentaire_devis',
        'commentaire_ca', 'commentaire_tresorerie', 'commentaire_charges', 'updated_at',
    ]);
};

$updatedVehiculesSansFacture = function () {
    $this->enregistrerSaisieJournaliere();
};

$updatedCommentaireProspects = function () {
    $this->enregistrerSaisieJournaliere();
};

$updatedCommentaireDevis = function () {
    $this->enregistrerSaisieJournaliere();
};

$updatedCommentaireCA = function () {
    $this->enregistrerSaisieJournaliere();
};

$updatedCommentaireTresorerie = function () {
    $this->enregistrerSaisieJournaliere();
};

$updatedCommentaireCharges = function () {
    $this->enregistrerSaisieJournaliere();
};

$updatedDate = function () {
    $this->devisSelection = [];
    $this->devisBrouillon = [];
    $this->factureSelection = [];
    $this->factureBrouillon = [];
    $this->chgDate = $this->date;
    $this->reinitialiserPagination();
    $this->chargerSaisieJournaliere();
};

$dateLabel = computed(function () {
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $mois = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    $d = \Illuminate\Support\Carbon::parse($this->date);

    return $jours[$d->dayOfWeek].' '.$d->format('d').' '.$mois[$d->month - 1].' '.$d->format('Y');
});

/** Un commercial travaille pour toute la ville, pas pour un site précis : on le
 * retrouve donc via les villes couvertes par l'écran, pas via les sites eux-mêmes. */
$commerciauxSelectables = computed(fn () => Commercial::whereIn('ville_id', $this->sitesActifs->pluck('ville_id')->unique())->where('statut', 'Actif')->orderBy('numero')->pluck('nom', 'id'));

$prospectionsATraiter = computed(fn () => Prospection::whereIn('site_id', $this->siteIdsActifs)
    ->aTraiter()->with('commercial')->latest('date')->latest('id')->get());

/**
 * Prévient les commerciaux concernés du sort réservé à leurs transmissions.
 * Appelé AVANT la mise à jour, tant que les lignes portent encore le statut « Transmise ».
 *
 * @param  array<int, int>  $ids
 */
$avertirDuRetour = function (array $ids, string $statut, ?string $motif = null) {
    if ($ids === []) {
        return;
    }

    $destinataires = Prospection::whereIn('site_id', $this->siteIdsActifs)
        ->whereIn('id', $ids)
        ->with('commercial:id,user_id')
        ->get()
        ->pluck('commercial.user_id')
        ->filter()
        ->unique();

    if ($destinataires->isEmpty()) {
        return;
    }

    $refusee = $statut === 'Refusée';

    Notificateur::pourPlusieurs(
        destinataires: $destinataires,
        titre: $refusee ? 'Prospection refusée' : 'Prospection validée',
        corps: $refusee
            ? 'Votre responsable a refusé une prospection.'.($motif ? ' Motif : '.$motif : '')
            : 'Votre responsable a validé '.count($ids).' prospection(s).',
        canal: NotificationApp::CANAL_GESTION,
        niveau: $refusee ? NotificationApp::NIVEAU_CRITIQUE : NotificationApp::NIVEAU_SUCCES,
        lien: route('mes-prospections'),
    );
};

$validerProspection = function (int $id) {
    $this->avertirDuRetour([$id], 'Validée');

    // La décision se signe : qui, quand, d'où, depuis quel poste. Sans cette signature,
    // le commercial voyait sa ligne passer au vert sans savoir à qui s'adresser.
    Prospection::whereIn('site_id', $this->siteIdsActifs)->aTraiter()->where('id', $id)
        ->update(['statut_validation' => 'Validée', 'motif_refus' => null]
            + SignatureDeDecision::colonnes());
    unset($this->prospectionsATraiter, $this->prospectionsDuJour, $this->prospectionsAttenteDevis);

    // Valider une prospection qui annonce un devis, c'est s'engager à l'établir : le
    // brouillon s'ouvre donc aussitôt, prérempli. Sans « devis après passage », la
    // validation s'arrête là — toutes les visites ne débouchent pas sur un devis.
    $prospection = Prospection::whereIn('site_id', $this->siteIdsActifs)->find($id);

    if ($prospection?->devis_apres_passage && ! $prospection->devis()->exists()) {
        $this->devisBrouillon = $this->brouillonsDevisPour([$id]);
    }
};

$refuserProspection = function (int $id) {
    $motif = $this->motifRefus[$id] ?? null;
    $this->avertirDuRetour([$id], 'Refusée', $motif);

    // Refuser est une décision autant que valider, et c'est celle qu'on conteste : elle
    // se signe de la même façon.
    Prospection::whereIn('site_id', $this->siteIdsActifs)->aTraiter()->where('id', $id)
        ->update(['statut_validation' => 'Refusée', 'motif_refus' => $motif]
            + SignatureDeDecision::colonnes());
    unset($this->prospectionsATraiter, $this->prospectionsDuJour);
};

/*
|--------------------------------------------------------------------------
| Corriger une transmission avant de la valider
|--------------------------------------------------------------------------
| Le commercial saisit depuis le terrain, parfois dans l'urgence : il oublie de
| cocher le passage, ou coche un devis qui n'a pas eu lieu. Le responsable doit
| pouvoir rectifier avant de valider — sans quoi il ne lui resterait qu'à refuser
| une prospection réelle pour une case mal cochée.
*/
$basculerPassage = function (int $id) {
    $p = Prospection::whereIn('site_id', $this->siteIdsActifs)->aTraiter()->findOrFail($id);

    // Sans passage, il ne peut y avoir de devis après passage : la règle de la
    // création vaut ici aussi, sinon la ligne deviendrait incohérente.
    $passage = ! $p->passage;

    $p->update([
        'passage' => $passage,
        'date_passage' => $passage ? ($p->date_passage ?? $p->date) : null,
        'devis_apres_passage' => $passage ? $p->devis_apres_passage : false,
        'date_devis' => $passage ? $p->date_devis : null,
    ]);

    unset($this->prospectionsATraiter, $this->prospectionsDuJour, $this->prospectionsAttenteDevis);
};

$basculerDevisApres = function (int $id) {
    $p = Prospection::whereIn('site_id', $this->siteIdsActifs)->aTraiter()->findOrFail($id);

    $devisApres = ! $p->devis_apres_passage;
    $dateDevis = $devisApres ? ($p->date_devis ?? $p->date_passage ?? $p->date) : null;

    // Un devis après passage suppose le passage : on le coche du même geste, à la
    // même date, plutôt que d'enregistrer un devis né d'une visite qui n'a pas eu lieu.
    $p->update([
        'devis_apres_passage' => $devisApres,
        'date_devis' => $dateDevis,
        'passage' => $devisApres ? true : $p->passage,
        'date_passage' => $devisApres ? $dateDevis : $p->date_passage,
    ]);

    unset($this->prospectionsATraiter, $this->prospectionsDuJour, $this->prospectionsAttenteDevis);
};

$validerToutesProspections = function () {
    $ids = Prospection::whereIn('site_id', $this->siteIdsActifs)->aTraiter()->pluck('id')->all();
    $this->avertirDuRetour($ids, 'Validée');

    Prospection::whereIn('site_id', $this->siteIdsActifs)->aTraiter()
        ->update(['statut_validation' => 'Validée', 'motif_refus' => null]
            + SignatureDeDecision::colonnes());
    unset($this->prospectionsATraiter, $this->prospectionsDuJour);
};

/**
 * Les prospections qui attendent votre décision — celles-là seulement.
 *
 * Dès qu'une ligne est tranchée, validée comme refusée, elle quitte ce tableau :
 * son sort est scellé, elle se consulte dans la page Prospects. Celle qui annonçait
 * un devis n'est pas perdue de vue pour autant — elle réapparaît juste en dessous,
 * dans « Devis à effectuer », qui est l'endroit où il reste quelque chose à en faire.
 * Laisser une ligne dans deux tableaux à la fois oblige à deviner où agir.
 *
 * Aucun filtre sur la date : une transmission d'hier restée sans réponse doit sauter
 * aux yeux aujourd'hui, pas disparaître avec le changement de journée.
 */
$prospectionsDuJour = computed(fn () => Prospection::whereIn('site_id', $this->siteIdsActifs)
    ->where('statut_validation', 'Transmise')
    ->with(['commercial', 'donneesLibres'])
    ->latest('date')->latest('id')->get());

$prospectionsAttenteDevis = computed(fn () => Prospection::whereIn('site_id', $this->siteIdsActifs)->where('statut_validation', 'Validée')->where('devis_apres_passage', true)->doesntHave('devis')->with('commercial')->latest('date')->latest('id')->get());

/**
 * L'en-cours des devis : ceux dont le sort n'est pas scellé.
 *
 *   - en attente d'un statut ;
 *   - validés mais pas encore facturés.
 *
 * Un devis refusé, ou déjà facturé, sort de l'écran de saisie et se retrouve dans la
 * page Devis. Là encore, pas de filtre sur la date : un devis émis la semaine dernière
 * et toujours sans réponse doit rester sous les yeux.
 *
 * **Et seulement les devis saisis ici.** Une proforma reprise du logiciel d'atelier porte
 * le statut « En attente » faute que le fichier en dise autre chose — ce n'est pas une
 * décision qui se prépare, c'est une information absente. Les faire entrer dans ce tableau
 * y déverserait deux mille lignes d'historique et noierait les quelques-unes sur
 * lesquelles le responsable doit réellement se prononcer aujourd'hui. Elles restent
 * entièrement visibles dans la page Devis et comptent dans les indicateurs.
 */
$devisDuJour = computed(fn () => Devis::whereIn('site_id', $this->siteIdsActifs)
    ->saisieManuelle()
    ->where(fn ($q) => $q
        ->where('statut', 'En attente')
        ->orWhere(fn ($r) => $r->where('statut', 'Validé')->doesntHave('facture')))
    ->with('commercial')
    ->latest('date_emission')->latest('id')->get()
    // Les devis sans réponse d'abord : c'est là qu'on attend une décision. Le tri se
    // fait en mémoire — quelques lignes — plutôt qu'en SQL, dont la syntaxe de tri
    // par valeur diffère d'un moteur à l'autre. Il est stable : à l'intérieur de
    // chaque groupe, le plus récent reste en tête.
    ->sortBy(fn ($d) => $d->statut === 'En attente' ? 0 : 1)
    ->values());

$devisEnAttente = computed(fn () => Devis::whereIn('site_id', $this->siteIdsActifs)->saisieManuelle()->where('statut', 'En attente')->with('commercial')->latest('date_emission')->latest('id')->get());

$devisValidesNonFactures = computed(fn () => Devis::whereIn('site_id', $this->siteIdsActifs)->saisieManuelle()->where('statut', 'Validé')->doesntHave('facture')->with('commercial')->latest('date_emission')->latest('id')->get());

$facturesDuJour = computed(fn () => Facture::whereIn('site_id', $this->siteIdsActifs)->saisieManuelle()->whereDate('date', $this->date)->with(['commercial', 'donneesLibres'])->orderByDesc('id')->get());

$encaissementsDuJour = computed(fn () => Encaissement::whereIn('site_id', $this->siteIdsActifs)->saisieManuelle()->whereDate('date', $this->date)->with('donneesLibres')->orderByDesc('id')->get());

/**
 * Factures encore soldables sur les sites actifs : c'est le garde-fou anti-double-saisie
 * avec le caissier.
 *
 * Les factures reprises du logiciel d'atelier en sont écartées, et c'est la précaution la
 * plus importante de cet écran : **leur historique de règlement n'a pas été importé**. Une
 * facture d'octobre réglée depuis longtemps paraîtrait donc entièrement due, et on
 * proposerait de l'encaisser une seconde fois. Ce qui reste réellement à recouvrer est
 * dans l'état des impayés, qui a sa propre reprise ; tant qu'elle n'est pas faite, aucune
 * ligne importée n'entre dans un écran d'encaissement.
 */
$facturesAvecReste = computed(fn () => Facture::whereIn('site_id', $this->siteIdsActifs)->saisieManuelle()->avecResteAEncaisser()->latest('date')->latest('id')->get());

$chargesDuJour = computed(fn () => Charge::whereIn('site_id', $this->siteIdsActifs)->saisieManuelle()->whereDate('date', $this->date)->with('donneesLibres')->orderByDesc('id')->get());

/**
 * Les numéros que porteront les factures en cours de saisie.
 *
 * Simple aperçu : le compteur n'est pas touché ici, il ne l'est qu'à la validation.
 * Réserver un numéro pour une facture qu'on renonce ensuite à établir creuserait un
 * trou dans la séquence — ce qu'une facturation ne pardonne pas.
 */
$numerosFacturePrevus = computed(fn () => GenerateurNumero::apercus(
    auth()->user()->entreprise_id, 'nfa', count($this->factureBrouillon), $this->date,
));

$clientsConnus = computed(function () {
    return Prospection::whereIn('site_id', $this->siteIdsActifs)->latest('id')->limit(200)->pluck('client')
        ->merge(Devis::whereIn('site_id', $this->siteIdsActifs)->latest('id')->limit(200)->pluck('client'))
        ->unique()->values()->take(40);
});

$optionsActivite = computed(fn () => Referentiel::options(Referentiel::ACTIVITE));

$optionsMoyenProspection = computed(fn () => Referentiel::options(Referentiel::MOYEN_PROSPECTION));

$optionsMoyenPaiement = computed(fn () => Referentiel::options(Referentiel::MOYEN_PAIEMENT));

$optionsTypeEncaissement = computed(fn () => Referentiel::options(Referentiel::TYPE_ENCAISSEMENT));

$libellesOperation = computed(function () {
    // Les libellés de charge sont enrichissables par l'entreprise ; les deux libellés de
    // décaissement restent figés car ils pilotent le calcul du résultat.
    $decaissements = ['Transfert de trésorerie vers un autre site', 'Décaissements DG'];

    return $this->chgTypeOp === 'Charges'
        ? array_values(Referentiel::options(Referentiel::LIBELLE_CHARGE))
        : $decaissements;
});

$updatedChgTypeOp = function () {
    $this->chgLibelle = $this->chgTypeOp === 'Charges' ? 'Achats pièces' : 'Transfert de trésorerie vers un autre site';
};

// 1. Prospections

/** Impossible de faire un devis sans passage : cocher l'un coche et date l'autre, à la même date. */
$updatedProsPassage = function ($valeur) {
    if (! $valeur) {
        $this->prosDevisApres = false;
        $this->prosDateDevis = null;
        $this->prosDatePassage = null;

        return;
    }

    $this->prosDatePassage ??= $this->date;
};

$updatedProsDevisApres = function ($valeur) {
    if (! $valeur) {
        $this->prosDateDevis = null;

        return;
    }

    $this->prosDateDevis ??= $this->date;
    $this->prosPassage = true;
    $this->prosDatePassage = $this->prosDateDevis;
};

/** Le devis n'a pas de date propre : elle vaut toujours la date de passage. */
$updatedProsDateDevis = function ($valeur) {
    if ($this->prosDevisApres) {
        $this->prosDatePassage = $valeur;
    }
};

$ajouterProspection = function () {
    if ($this->exerciceFerme) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    $donnees = $this->validate([
        'prosClient' => ['required', 'string', 'max:255'],
        'prosLocalisation' => ['nullable', 'string', 'max:255'],
        'prosImmatriculation' => ['nullable', 'string', 'max:32'],
        'prosNFiche' => ['nullable', 'string', 'max:60'],
        'prosMoyen' => ['required', Rule::in(array_keys($this->optionsMoyenProspection))],
        'prosCommercialId' => ['required', Rule::exists('commerciaux', 'id')->whereIn('ville_id', $this->sitesActifs->pluck('ville_id')->unique())],
        'prosActivite' => ['required', Rule::in(array_keys($this->optionsActivite))],
        'prosDatePassage' => ['nullable', 'date'],
        'prosDateDevis' => ['nullable', 'date'],
        'prosObs' => ['nullable', 'string'],
    ], [], ['prosClient' => 'clients visités', 'prosCommercialId' => 'commercial', 'prosActivite' => 'activité']);

    $coherence = Prospection::normaliserPassage(
        (bool) $this->prosPassage, $donnees['prosDatePassage'],
        (bool) $this->prosDevisApres, $donnees['prosDateDevis'],
    );

    Prospection::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $this->site->id,
        'commercial_id' => $donnees['prosCommercialId'],
        // La journée saisie entre dans le numéro : P-1409-0574. On saisit souvent la
        // veille ou le lendemain — c'est la date de l'opération qui compte, pas la frappe.
        'numero' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'pro', $this->date),
        'date' => $this->date,
        'client' => $donnees['prosClient'],
        'localisation' => $donnees['prosLocalisation'] ?: null,
        'immatriculation' => $donnees['prosImmatriculation'] ?: null,
        'n_fiche_reception' => trim($donnees['prosNFiche'] ?? '') ?: null,
        'moyen' => $donnees['prosMoyen'],
        'activite' => $donnees['prosActivite'],
        ...$coherence,
        'observations' => $donnees['prosObs'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    $this->reset(['prosClient', 'prosLocalisation', 'prosImmatriculation', 'prosNFiche',
        'prosObs', 'prosPassage', 'prosDatePassage', 'prosDevisApres', 'prosDateDevis']);
    $this->prosMoyen = 'RDV';
    $this->prosActivite = 'Mécanique';
};

$modifierProspection = function (int $id) {
    $p = Prospection::whereIn('site_id', $this->siteIdsActifs)->findOrFail($id);

    $this->editionProspectionId = $p->id;
    $this->editionProsClient = $p->client;
    $this->editionProsLocalisation = $p->localisation ?? '';
    $this->editionProsMoyen = $p->moyen;
    $this->editionProsCommercialId = $p->commercial_id;
    $this->editionProsActivite = $p->activite;
    $this->editionProsPassage = $p->passage;
    $this->editionProsDatePassage = $p->date_passage?->toDateString();
    $this->editionProsDevisApres = $p->devis_apres_passage;
    $this->editionProsDateDevis = $p->date_devis?->toDateString();
    $this->editionProsObs = $p->observations ?? '';
};

$annulerEditionProspection = function () {
    $this->editionProspectionId = null;
};

/** Miroir des règles de cohérence de la création, appliquées cette fois à l'édition en ligne. */
$updatedEditionProsPassage = function ($valeur) {
    if (! $valeur) {
        $this->editionProsDevisApres = false;
        $this->editionProsDateDevis = null;
        $this->editionProsDatePassage = null;

        return;
    }

    $this->editionProsDatePassage ??= $this->date;
};

$updatedEditionProsDevisApres = function ($valeur) {
    if (! $valeur) {
        $this->editionProsDateDevis = null;

        return;
    }

    $this->editionProsDateDevis ??= $this->date;
    $this->editionProsPassage = true;
    $this->editionProsDatePassage = $this->editionProsDateDevis;
};

$updatedEditionProsDateDevis = function ($valeur) {
    if ($this->editionProsDevisApres) {
        $this->editionProsDatePassage = $valeur;
    }
};

$enregistrerEditionProspection = function () {
    $donnees = $this->validate([
        'editionProsClient' => ['required', 'string', 'max:255'],
        'editionProsLocalisation' => ['nullable', 'string', 'max:255'],
        'editionProsMoyen' => ['required', Rule::in(array_keys($this->optionsMoyenProspection))],
        'editionProsCommercialId' => ['required', Rule::exists('commerciaux', 'id')->whereIn('ville_id', $this->sitesActifs->pluck('ville_id')->unique())],
        'editionProsActivite' => ['required', Rule::in(array_keys($this->optionsActivite))],
        'editionProsDatePassage' => ['nullable', 'date'],
        'editionProsDateDevis' => ['nullable', 'date'],
        'editionProsObs' => ['nullable', 'string'],
    ], [], ['editionProsClient' => 'clients visités', 'editionProsCommercialId' => 'commercial', 'editionProsActivite' => 'activité']);

    $coherence = Prospection::normaliserPassage(
        (bool) $this->editionProsPassage, $donnees['editionProsDatePassage'],
        (bool) $this->editionProsDevisApres, $donnees['editionProsDateDevis'],
    );

    Prospection::whereIn('site_id', $this->siteIdsActifs)->findOrFail($this->editionProspectionId)->update([
        'client' => $donnees['editionProsClient'],
        'localisation' => $donnees['editionProsLocalisation'] ?: null,
        'moyen' => $donnees['editionProsMoyen'],
        'commercial_id' => $donnees['editionProsCommercialId'],
        'activite' => $donnees['editionProsActivite'],
        ...$coherence,
        'observations' => $donnees['editionProsObs'] ?: null,
    ]);

    $this->editionProspectionId = null;
    unset($this->prospectionsDuJour, $this->prospectionsATraiter, $this->prospectionsAttenteDevis);
};

// 2. Devis

$genererBrouillonsDevis = function () {
    $ids = collect($this->devisSelection)->filter()->keys()->all();
    if (empty($ids)) {
        return;
    }

    $this->devisBrouillon = $this->brouillonsDevisPour($ids);
    $this->devisSelection = [];
};

/**
 * Prépare les lignes de devis à partir de prospections. Extrait pour être appelé aussi
 * bien depuis la sélection manuelle que depuis la validation d'une prospection, sans
 * dupliquer la correspondance prospection → devis.
 *
 * @param  array<int, int>  $ids
 */
$brouillonsDevisPour = function (array $ids): array {
    return Prospection::whereIn('site_id', $this->siteIdsActifs)->whereIn('id', $ids)->get()->map(fn ($p) => [
        'prospection_id' => $p->id,
        'prospection_numero' => $p->numero,
        'date_reception' => now()->toDateString(),
        'n_fiche_reception' => '',
        'client' => $p->client,
        'date_emission' => now()->toDateString(),
        'commercial_id' => $p->commercial_id,
        'statut' => 'En attente',
        'montant_devis' => '',
        'montant_valide' => '',
        'activite' => $p->activite,
        'observations' => '',
    ])->values()->all();
};

$annulerBrouillonsDevis = function () {
    $this->devisBrouillon = [];
};

$validerDevis = function () {
    if ($this->exerciceFerme) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    // Défense en profondeur : le brouillon est un tableau lié côté client, on revérifie
    // que chaque commercial/prospection référencé appartient bien à un site du Responsable.
    $commerciauxDuSite = Commercial::whereIn('ville_id', $this->sitesActifs->pluck('ville_id')->unique())->pluck('id');
    $prospectionsDuSite = Prospection::whereIn('site_id', $this->siteIdsActifs)->pluck('id');

    foreach ($this->devisBrouillon as $ligne) {
        if (empty($ligne['montant_devis']) || ! is_numeric($ligne['montant_devis'])) {
            continue;
        }

        if (! $commerciauxDuSite->contains($ligne['commercial_id']) || ! $prospectionsDuSite->contains($ligne['prospection_id'])) {
            continue;
        }

        $devis = Devis::create([
            'entreprise_id' => auth()->user()->entreprise_id,
            'site_id' => $this->site->id,
            'commercial_id' => $ligne['commercial_id'],
            'prospection_id' => $ligne['prospection_id'],
            'numero' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'dev', $ligne['date_emission']),
            'n_fiche_reception' => $ligne['n_fiche_reception'] ?: null,
            'date_reception' => $ligne['date_reception'] ?: null,
            'date_emission' => $ligne['date_emission'],
            'client' => $ligne['client'],
            'activite' => $ligne['activite'],
            'statut' => $ligne['statut'],
            'montant_devis' => (int) $ligne['montant_devis'],
            'montant_valide' => $ligne['statut'] === 'Validé' && $ligne['montant_valide'] !== '' ? (int) $ligne['montant_valide'] : null,
            'observations' => $ligne['observations'] ?: null,
            'cree_par' => auth()->id(),
        ]);

        // Délai réception → émission supérieur à 24h : alerte le responsable du site
        // (à défaut le gérant), pour que le retard soit visible sans attendre qu'un
        // gérant tombe dessus en consultant la page Devis.
        if ($devis->date_reception) {
            $heures = $devis->date_reception->diffInHours($devis->date_emission);

            if ($heures > 24) {
                Notificateur::pourPlusieurs(
                    destinataires: Notificateur::encadrementDuSite($devis->site_id, auth()->user()->entreprise_id),
                    titre: 'Délai de devis dépassé',
                    corps: 'Le devis '.$devis->numero.' ('.$devis->client.') a un délai réception → émission de '.$heures.' h, au-delà des 24h attendues.',
                    canal: NotificationApp::CANAL_GESTION,
                    niveau: NotificationApp::NIVEAU_ALERTE,
                    lien: route('devis'),
                );
            }
        }
    }

    $this->devisBrouillon = [];
};

$changerStatutDevis = function (int $id, string $statut) {
    if (! in_array($statut, ['En attente', 'Validé', 'Refusé'], true)) {
        return;
    }

    $devis = Devis::whereIn('site_id', $this->siteIdsActifs)->findOrFail($id);

    // Valider un devis, c'est arrêter le montant réellement retenu par le client — il
    // diffère souvent de celui proposé. On ne le devine donc pas : le statut ne change
    // qu'une fois le montant saisi et confirmé, en deux temps.
    if ($statut === 'Validé') {
        $this->annulerRefusDevis();
        $this->devisAValiderId = $devis->id;
        $this->montantValidation = (string) ($devis->montant_valide ?? $devis->montant_devis);
        $this->resetValidation();

        return;
    }

    // Refuser demande la même retenue : un refus sans motif ne s'explique plus trois
    // mois après, et c'est pourtant ce qui nourrit le commentaire « Devis » du jour.
    if ($statut === 'Refusé') {
        $this->annulerValidationDevis();
        $this->devisARefuserId = $devis->id;
        $this->motifRefusDevis = (string) ($devis->motif_refus ?? '');
        $this->resetValidation();

        return;
    }

    $this->annulerValidationDevis();
    $this->annulerRefusDevis();

    $devis->update(['statut' => $statut, 'montant_valide' => null, 'motif_refus' => null]);

    unset($this->devisDuJour, $this->devisEnAttente, $this->devisValidesNonFactures);
};

$confirmerRefusDevis = function () {
    $devis = $this->devisARefuserId
        ? Devis::whereIn('site_id', $this->siteIdsActifs)->find($this->devisARefuserId)
        : null;

    if (! $devis) {
        $this->annulerRefusDevis();

        return;
    }

    $this->validate([
        'motifRefusDevis' => ['required', 'string', 'max:255'],
    ], [
        'motifRefusDevis.required' => 'Indiquez pourquoi le client a refusé ce devis.',
    ], ['motifRefusDevis' => 'motif du refus']);

    $devis->update(['statut' => 'Refusé', 'montant_valide' => null, 'motif_refus' => $this->motifRefusDevis]);

    $this->annulerRefusDevis();
    unset($this->devisDuJour, $this->devisEnAttente, $this->devisValidesNonFactures);
};

$annulerRefusDevis = function () {
    $this->devisARefuserId = null;
    $this->motifRefusDevis = '';
};

$confirmerValidationDevis = function () {
    // Le champ n'apparaît qu'un devis désigné : un appel sans devis ne peut venir que
    // d'une requête forgée, on l'ignore sans rien changer.
    $devis = $this->devisAValiderId
        ? Devis::whereIn('site_id', $this->siteIdsActifs)->find($this->devisAValiderId)
        : null;

    if (! $devis) {
        $this->annulerValidationDevis();

        return;
    }

    $this->validate([
        'montantValidation' => ['required', 'numeric', 'min:1'],
    ], [
        'montantValidation.required' => 'Indiquez le montant retenu avant de valider le devis.',
        'montantValidation.min' => 'Un devis validé porte forcément un montant.',
    ], ['montantValidation' => 'montant validé']);

    $devis->update(['statut' => 'Validé', 'montant_valide' => (int) $this->montantValidation]);

    $this->annulerValidationDevis();
    unset($this->devisDuJour, $this->devisEnAttente, $this->devisValidesNonFactures);

    // Un devis validé a vocation à être facturé : le brouillon de facture s'ouvre
    // aussitôt, au montant retenu. Reste à choisir le type (FNE ou HT) et à émettre —
    // c'est là que se décide le numéro, on ne l'attribue donc pas d'office.
    if (! $devis->facture()->exists()) {
        $this->factureBrouillon = $this->brouillonsFacturesPour([$devis->id]);
    }
};

$annulerValidationDevis = function () {
    $this->devisAValiderId = null;
    $this->montantValidation = '';
    $this->resetValidation();
};

/**
 * Tout devis ne naît pas d'une prospection : un client peut se présenter de lui-même à
 * l'atelier, ou le devis avoir été établi ailleurs (carnet, application Windev). Cette
 * saisie libre crée le devis sans prospection d'origine, exactement comme les autres.
 */
$ouvrirDevisLibre = function () {
    $this->devisLibreOuvert = true;
    $this->devLibreDateEmission = $this->date;
    $this->devLibreDateReception = $this->date;
    $this->devLibreCommercialId = $this->commerciauxSelectables->keys()->first() ?? '';
};

$annulerDevisLibre = function () {
    $this->devisLibreOuvert = false;
    $this->reset(['devLibreClient', 'devLibreFiche', 'devLibreMontant', 'devLibreMontantValide', 'devLibreObs']);
    $this->devLibreStatut = 'En attente';
};

$ajouterDevisLibre = function () {
    if ($this->exerciceFerme) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    $donnees = $this->validate([
        'devLibreClient' => ['required', 'string', 'max:255'],
        'devLibreCommercialId' => ['required', Rule::exists('commerciaux', 'id')->where('ville_id', $this->site->ville_id)],
        'devLibreActivite' => ['required', Rule::in(array_keys($this->optionsActivite))],
        'devLibreDateReception' => ['nullable', 'date'],
        'devLibreFiche' => ['nullable', 'string', 'max:255'],
        'devLibreDateEmission' => ['required', 'date'],
        'devLibreStatut' => ['required', 'in:En attente,Validé,Refusé'],
        'devLibreMontant' => ['required', 'numeric', 'min:1'],
        'devLibreMontantValide' => ['nullable', 'numeric', 'min:0'],
        'devLibreObs' => ['nullable', 'string'],
    ], [], [
        'devLibreClient' => 'client', 'devLibreCommercialId' => 'commercial',
        'devLibreActivite' => 'activité', 'devLibreMontant' => 'montant du devis',
    ]);

    Devis::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $this->site->id,
        'commercial_id' => $donnees['devLibreCommercialId'],
        'prospection_id' => null,
        'numero' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'dev', $donnees['devLibreDateEmission']),
        'n_fiche_reception' => $donnees['devLibreFiche'] ?: null,
        'date_reception' => $donnees['devLibreDateReception'] ?: null,
        'date_emission' => $donnees['devLibreDateEmission'],
        'client' => $donnees['devLibreClient'],
        'activite' => $donnees['devLibreActivite'],
        'statut' => $donnees['devLibreStatut'],
        'montant_devis' => (int) $donnees['devLibreMontant'],
        'montant_valide' => $donnees['devLibreStatut'] === 'Validé'
            ? (int) ($donnees['devLibreMontantValide'] !== '' && $donnees['devLibreMontantValide'] !== null ? $donnees['devLibreMontantValide'] : $donnees['devLibreMontant'])
            : null,
        'observations' => $donnees['devLibreObs'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    $this->annulerDevisLibre();
    unset($this->devisDuJour, $this->devisEnAttente, $this->devisValidesNonFactures);
};

// Chiffre d'affaires facturé

$genererBrouillonsFactures = function () {
    $ids = collect($this->factureSelection)->filter()->keys()->all();
    if (empty($ids)) {
        return;
    }

    $this->factureBrouillon = $this->brouillonsFacturesPour($ids);
    $this->factureSelection = [];
};

/**
 * Prépare les lignes de facture à partir de devis. Le montant part de celui qui a été
 * retenu à la validation, jamais de celui qui avait été proposé.
 *
 * @param  array<int, int>  $ids
 */
$brouillonsFacturesPour = function (array $ids): array {
    return Devis::whereIn('site_id', $this->siteIdsActifs)->whereIn('id', $ids)->get()->map(fn ($d) => [
        'devis_id' => $d->id,
        'devis_numero' => $d->numero,
        'commercial_id' => $d->commercial_id,
        'client' => $d->client,
        'type' => 'FNE',
        'activite' => $d->activite,
        'montant' => $d->montant_valide ?? $d->montant_devis,
        'observations' => '',
    ])->values()->all();
};

$annulerBrouillonsFactures = function () {
    $this->factureBrouillon = [];
};

$validerFactures = function () {
    if ($this->exerciceFerme) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    // Défense en profondeur : le brouillon est un tableau lié côté client, on revérifie
    // que chaque commercial/devis référencé appartient bien à un site du Responsable.
    $commerciauxDuSite = Commercial::whereIn('ville_id', $this->sitesActifs->pluck('ville_id')->unique())->pluck('id');
    $devisDuSite = Devis::whereIn('site_id', $this->siteIdsActifs)->pluck('id');

    foreach ($this->factureBrouillon as $ligne) {
        if (empty($ligne['montant']) || ! is_numeric($ligne['montant'])) {
            continue;
        }

        if (! $commerciauxDuSite->contains($ligne['commercial_id']) || ! $devisDuSite->contains($ligne['devis_id'])) {
            continue;
        }

        Facture::create([
            'entreprise_id' => auth()->user()->entreprise_id,
            'site_id' => $this->site->id,
            'devis_id' => $ligne['devis_id'],
            'commercial_id' => $ligne['commercial_id'],
            'numero' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'fac', $this->date),
            // Le N° de facture est généré automatiquement, jamais saisi à la main — un champ
            // manuel oublié faisait silencieusement disparaître la ligne à la validation.
            'n_facture' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'nfa', $this->date),
            'reference_devis' => $ligne['devis_numero'],
            'date' => $this->date,
            'client' => $ligne['client'],
            'type' => $ligne['type'],
            'activite' => $ligne['activite'],
            'montant' => (int) $ligne['montant'],
            'observations' => $ligne['observations'] ?: null,
            'cree_par' => auth()->id(),
        ]);
    }

    $this->factureBrouillon = [];
};

/** Numéros de devis du lieu, proposés en saisie libre pour rattacher une facture au bon devis. */
$numerosDevisRattachables = computed(fn () => Devis::whereIn('site_id', $this->siteIdsActifs)
    ->orderByDesc('id')->limit(200)->pluck('numero'));

$ouvrirFactureLibre = function () {
    $this->factureLibreOuvert = true;
    $this->facLibreCommercialId = $this->commerciauxSelectables->keys()->first() ?? '';
};

$annulerFactureLibre = function () {
    $this->factureLibreOuvert = false;
    $this->reset(['facLibreClient', 'facLibreNumero', 'facLibreRefDevis', 'facLibreMontant', 'facLibreObs']);
    $this->facLibreType = 'FNE';
};

/**
 * Facture émise hors application (carnet du jour, application Windev…). Le N° de devis
 * est saisi librement : s'il correspond à un devis du lieu, la facture lui est réellement
 * rattachée — sinon la référence est conservée telle quelle, pour que le rapprochement
 * reste possible plus tard sans bloquer la saisie du jour.
 */
$ajouterFactureLibre = function () {
    if ($this->exerciceFerme) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    $donnees = $this->validate([
        'facLibreClient' => ['required', 'string', 'max:255'],
        'facLibreCommercialId' => ['required', Rule::exists('commerciaux', 'id')->where('ville_id', $this->site->ville_id)],
        'facLibreActivite' => ['required', Rule::in(array_keys($this->optionsActivite))],
        'facLibreType' => ['required', 'in:FNE,HT'],
        'facLibreNumero' => ['nullable', 'string', 'max:255'],
        'facLibreRefDevis' => ['nullable', 'string', 'max:60'],
        'facLibreMontant' => ['required', 'numeric', 'min:1'],
        'facLibreObs' => ['nullable', 'string'],
    ], [], [
        'facLibreClient' => 'client', 'facLibreCommercialId' => 'commercial',
        'facLibreActivite' => 'activité', 'facLibreMontant' => 'montant de la facture',
    ]);

    $devisLie = $donnees['facLibreRefDevis']
        ? Devis::whereIn('site_id', $this->siteIdsActifs)->where('numero', $donnees['facLibreRefDevis'])->first()
        : null;

    Facture::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $this->site->id,
        'devis_id' => $devisLie?->id,
        'reference_devis' => $donnees['facLibreRefDevis'] ?: null,
        'commercial_id' => $donnees['facLibreCommercialId'],
        'numero' => GenerateurNumero::suivant(auth()->user()->entreprise_id, 'fac', $this->date),
        'n_facture' => $donnees['facLibreNumero'] ?: GenerateurNumero::suivant(auth()->user()->entreprise_id, 'nfa', $this->date),
        'date' => $this->date,
        'client' => $donnees['facLibreClient'],
        'type' => $donnees['facLibreType'],
        'activite' => $donnees['facLibreActivite'],
        'montant' => (int) $donnees['facLibreMontant'],
        'observations' => $donnees['facLibreObs'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    $this->annulerFactureLibre();
    unset($this->facturesDuJour, $this->facturesAvecReste);
};

// Encaissements du jour

$ajouterEncaissement = function () {
    if ($this->exerciceFerme) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    $donnees = $this->validate([
        'encType' => ['required', Rule::in(array_keys($this->optionsTypeEncaissement))],
        'encFactureId' => [$this->encType === 'Client' ? 'required' : 'nullable', 'nullable', 'integer'],
        'encMoyen' => ['required', Rule::in(array_keys($this->optionsMoyenPaiement))],
        'encMontant' => ['required', 'numeric', 'min:1'],
        'encClient' => ['nullable', 'string', 'max:255'],
        'encTiers' => ['nullable', 'string', 'max:255'],
        'encActivite' => ['nullable', Rule::in(array_keys($this->optionsActivite))],
        'encReference' => ['nullable', 'string', 'max:60'],
    ], [], ['encMontant' => 'montant', 'encFactureId' => 'facture']);

    $montant = (int) $donnees['encMontant'];
    $factureId = null;
    $client = $donnees['encClient'] ?: null;
    $siteIdResolu = $this->site->id;
    // Un encaissement adossé à une facture hérite de son activité ; sinon celui qui saisit
    // la précise, ou la laisse vide quand l'opération ne relève d'aucune des deux.
    $activite = $donnees['encActivite'] ?: null;
    $reference = $donnees['encReference'] ?: null;

    if ($donnees['encFactureId']) {
        // Verrou en base : empêche qu'un encaissement du responsable et du caissier,
        // saisis au même instant sur la même facture, dépassent ensemble son montant.
        $depassement = DB::transaction(function () use ($donnees, $montant, &$factureId, &$client, &$siteIdResolu, &$activite, &$reference) {
            $facture = Facture::whereIn('site_id', $this->siteIdsActifs)->lockForUpdate()->find($donnees['encFactureId']);

            if (! $facture || $montant > $facture->resteAEncaisser()) {
                return true;
            }

            $factureId = $facture->id;
            $client = $facture->client;
            $siteIdResolu = $facture->site_id;
            $activite = $facture->activite;
            $reference ??= $facture->n_facture;

            return false;
        });

        if ($depassement) {
            $this->addError('encMontant', 'Cette facture est déjà soldée, ou le montant dépasse son reste à encaisser.');
            unset($this->facturesAvecReste);

            return;
        }
    }

    Encaissement::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $siteIdResolu,
        'facture_id' => $factureId,
        'date' => $this->date,
        'type' => $donnees['encType'],
        'activite' => $activite,
        'moyen' => $donnees['encMoyen'],
        'montant' => $montant,
        'client' => $client,
        'autres_tiers' => $donnees['encTiers'] ?: null,
        'reference_origine' => $reference,
        'cree_par' => auth()->id(),
    ]);

    $this->reset(['encMontant', 'encClient', 'encTiers', 'encFactureId', 'encActivite', 'encReference']);
    $this->encType = 'Client';
    $this->encMoyen = 'Espèces';
    unset($this->facturesAvecReste);
};

// Charges & décaissements du jour

$ajouterCharge = function () {
    if ($this->site && \Modules\Noyau\Entreprises\Modeles\Exercice::estFerme(auth()->user()->entreprise_id, $this->site->ville_id, $this->chgDate)) {
        $this->addError('exercice', "L'exercice est clos pour ce site à cette date — saisie impossible.");

        return;
    }

    $donnees = $this->validate([
        'chgDate' => ['required', 'date'],
        'chgTypeOp' => ['required', 'in:Charges,Décaissements'],
        'chgLibelle' => ['required', 'string'],
        'chgMoyen' => ['required', Rule::in(array_keys($this->optionsMoyenPaiement))],
        'chgMontant' => ['required', 'numeric', 'min:1'],
        'chgTiers' => ['nullable', 'string', 'max:255'],
        'chgObs' => ['nullable', 'string'],
        'chgActivite' => ['nullable', Rule::in(array_keys($this->optionsActivite))],
        'chgReference' => ['nullable', 'string', 'max:60'],
    ], [], ['chgMontant' => 'montant', 'chgLibelle' => "libellé d'opération"]);

    $typeOperation = 'Charges';
    if ($donnees['chgTypeOp'] === 'Décaissements') {
        $typeOperation = str_starts_with($donnees['chgLibelle'], 'Transfert') ? 'Transfert' : 'Décaissement DG';
    }

    Charge::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => $this->site->id,
        'date' => $donnees['chgDate'],
        'type_operation' => $typeOperation,
        'activite' => $donnees['chgActivite'] ?: null,
        'libelle' => $donnees['chgLibelle'],
        'moyen' => $donnees['chgMoyen'],
        'montant' => (int) $donnees['chgMontant'],
        'tiers' => $donnees['chgTiers'] ?: null,
        'reference_origine' => $donnees['chgReference'] ?: null,
        'observations' => $donnees['chgObs'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    $this->reset(['chgMontant', 'chgTiers', 'chgObs', 'chgActivite', 'chgReference']);
    $this->chgDate = $this->date;
    $this->chgTypeOp = 'Charges';
    $this->chgLibelle = 'Achats pièces';
    $this->chgMoyen = 'Espèces';
};

?>

<div>
    @if ($this->mesSites->isEmpty())
        <x-a-venir titre="Aucun site assigné" description="Ce compte Responsable n'est rattaché à aucun site pour le moment. Contactez votre Gérant." />
    @else
        <div class="carte" style="display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap;">
            <div style="flex:1; min-width:220px;">
                <h1 style="font-size:19px; font-weight:800; margin:0 0 4px;">
                    Saisie du jour — {{ $this->site->nom }}
                </h1>
                <p style="color:#6B6E76; font-size:14px; margin:0;">Chaque ligne est enregistrée immédiatement à l'ajout et alimente les tableaux de bord en temps réel. Les deux activités (Mécanique et Sinistre) se saisissent ici, ligne par ligne.</p>
            </div>
            {{-- Les deux champs s'alignent par le haut, et non par le bas.

                 Alignés par le bas, ils ne l'étaient pas : la date porte une mention sous
                 elle — le rattachement — que le site n'avait pas, si bien que la liste du
                 site descendait de la hauteur de cette ligne et que les deux cadres ne
                 tombaient plus au même niveau. Chaque champ porte désormais sa propre
                 mention, et les deux se posent sur la même ligne d'appui. --}}
            <div style="display:flex; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                {{-- Le choix du lieu n'apparaît que là où la ville en compte plusieurs : une
                     journée se saisit toujours pour un endroit précis, jamais pour deux. --}}
                @if ($this->mesSites->count() > 1)
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label class="champ-libelle" for="site-saisie">Site de saisie</label>
                        <select id="site-saisie" wire:model.live="siteSaisieId" class="champ"
                                style="width:auto; font-weight:600;">
                            @foreach ($this->mesSites as $s)
                                <option value="{{ $s->id }}" @selected((string) $siteSaisieId === (string) $s->id)>{{ $s->nom }}</option>
                            @endforeach
                        </select>
                        <span style="font-size:11.5px; color:var(--th-gris,#6B6E76);">
                            Ville : <b>{{ $this->site->ville?->nom ?? '—' }}</b>
                        </span>
                    </div>
                @endif
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <label class="champ-libelle" for="date-journee">Date de la journée (calendrier)</label>
                    <input type="date" id="date-journee" wire:model.live="date" value="{{ $date }}"
                           class="champ" style="width:158px;">
                    <span style="font-size:11.5px; color:var(--th-gris,#6B6E76);">Rattachement : <b>{{ $this->dateLabel }}</b></span>
                </div>
            </div>
        </div>

        @if ($this->exerciceFerme)
            <div class="encart encart-alerte" style="margin-bottom:16px;">
                L'exercice est clos pour <b>{{ $this->site->ville->nom }}</b> à cette date : la saisie est bloquée. Un gérant peut réouvrir l'exercice depuis les Paramètres.
            </div>
        @endif
        @error('exercice') <div class="encart encart-alerte" style="margin-bottom:16px;">{{ $message }}</div> @enderror

        <x-carte-section titre="Flux commercial" icone="commercial" couleur="var(--th-ink,#191B20)">
            {{-- Ce tableau ne montre que les prospections à arbitrer. Une ligne tranchée,
                 validée comme refusée, s'en va aussitôt : celle qui annonçait un devis
                 réapparaît en « Devis à effectuer » juste dessous, les autres se
                 consultent dans la page Prospects. --}}
            <x-sous-titre n="1" t="Prospections à valider" />

            @if ($this->prospectionsATraiter->isNotEmpty())
                <p style="font-size:12.5px; font-weight:700; color:var(--th-bleu,#2563EB); margin:0 0 8px;">
                    {{ $this->prospectionsATraiter->count() }} prospection(s) en attente de votre validation.
                    <button type="button" wire:click="validerToutesProspections"
                        wire:confirm="Valider toutes les prospections transmises ?"
                        class="bouton bouton-petit" style="margin-left:10px;">Tout valider</button>
                </p>
            @endif

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Statut</th>
                            <th>Clients visités</th>
                            <th>Localisation</th>
                            <th>Moyens</th>
                            <th>Commercial</th>
                            <th>Activité</th>
                            <th>Passage</th>
                            <th>Devis après passage</th>
                            <th>Informations libres</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->prospectionsDuJour->forPage($pageProsDuJour, 7) as $p)
                            @php $aTraiter = $p->statut_validation === 'Transmise'; @endphp

                            @if ($editionProspectionId === $p->id)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); background:#FDF2F4;" wire:key="pros-edit-{{ $p->id }}">
                                    <td><x-numero-ligne :ligne="$p" /></td>
                                    <td>—</td>
                                    <td><input type="text" wire:model="editionProsClient" value="{{ $editionProsClient }}" class="champ" style="min-width:130px;"></td>
                                    <td><input type="text" wire:model="editionProsLocalisation" value="{{ $editionProsLocalisation }}" class="champ" style="min-width:110px;"></td>
                                    <td>
                                        <select wire:model="editionProsMoyen" class="champ">
                                            @foreach ($this->optionsMoyenProspection as $valeur => $libelle)
                                                <option value="{{ $valeur }}" @selected((string) $editionProsMoyen === (string) $valeur)>{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select wire:model="editionProsCommercialId" class="champ">
                                            @foreach ($this->commerciauxSelectables as $idCom => $nomCom)
                                                <option value="{{ $idCom }}" @selected((string) $editionProsCommercialId === (string) $idCom)>{{ $nomCom }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select wire:model="editionProsActivite" class="champ">
                                            @foreach ($this->optionsActivite as $valeur => $libelle)
                                                <option value="{{ $valeur }}" @selected((string) $editionProsActivite === (string) $valeur)>{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td style="white-space:normal; min-width:150px;">
                                        <label style="display:flex; align-items:center; gap:5px; font-size:13px;">
                                            <input type="checkbox" wire:model.live="editionProsPassage"> Passage
                                        </label>
                                        @if ($editionProsPassage && ! $editionProsDevisApres)
                                            <input type="date" wire:model="editionProsDatePassage" value="{{ $editionProsDatePassage }}" class="champ" style="margin-top:4px;">
                                        @endif
                                    </td>
                                    <td style="white-space:normal; min-width:180px;">
                                        <label style="display:flex; align-items:center; gap:5px; font-size:13px;">
                                            <input type="checkbox" wire:model.live="editionProsDevisApres"> Devis après passage
                                        </label>
                                        @if ($editionProsDevisApres)
                                            <input type="date" wire:model.live="editionProsDateDevis" value="{{ $editionProsDateDevis }}" class="champ" style="margin-top:4px;">
                                            <span style="font-size:10.5px; color:#9A9DA5;">= date de passage</span>
                                        @endif
                                    </td>
                                    <td style="white-space:normal; min-width:200px;">
                                        <textarea wire:model="editionProsObs" rows="2" placeholder="Observations"
                                            style="width:100%; box-sizing:border-box; padding:6px 8px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:13px;"></textarea>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <button type="button" wire:click="enregistrerEditionProspection" class="bouton bouton-petit bouton-vert" style="margin-right:5px;">Enregistrer</button>
                                        <button type="button" wire:click="annulerEditionProspection" class="bouton bouton-petit bouton-secondaire">Annuler</button>
                                    </td>
                                </tr>
                            @else
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);" wire:key="pros-{{ $p->id }}">
                                    <td><x-numero-ligne :ligne="$p" /></td>
                                    <td>
                                        @if ($aTraiter)
                                            <span class="pastille pastille-bleu">À valider</span>
                                        @elseif ($p->statut_validation === 'Refusée')
                                            <span class="pastille pastille-rouge" title="{{ $p->motif_refus }}">Refusée</span>
                                        @else
                                            <span class="pastille pastille-vert">Validée</span>
                                        @endif
                                    </td>
                                    <td>{{ $p->client }}</td>
                                    <td style="color:#6B6E76;">{{ $p->localisation ?? '—' }}</td>
                                    <td>{{ $p->moyen }}</td>
                                    <td>{{ $p->commercial->nom }}</td>
                                    <td>{{ $p->activite }}</td>

                                    {{-- Tant que la ligne est à valider, les cases se cochent d'un clic :
                                         corriger un passage oublié ne doit pas obliger à tout rouvrir.
                                         Une fois validée, la ligne se fige et passe par « Modifier ».
                                         La date saisie par le commercial reste visible dans les deux
                                         cas : c'est elle qui dit quand la visite a eu lieu. --}}
                                    <td style="text-align:center;">
                                        @if ($aTraiter)
                                            <input type="checkbox" wire:click="basculerPassage({{ $p->id }})"
                                                @checked($p->passage) style="width:16px; height:16px; cursor:pointer;"
                                                title="Le commercial est-il passé sur site ?">
                                        @else
                                            {{ $p->passage ? '☑' : '☐' }}
                                        @endif
                                        <x-date-sous-case :date="$p->date_passage" />
                                    </td>
                                    <td style="text-align:center;">
                                        @if ($aTraiter)
                                            <input type="checkbox" wire:click="basculerDevisApres({{ $p->id }})"
                                                @checked($p->devis_apres_passage) style="width:16px; height:16px; cursor:pointer;"
                                                title="Un devis doit-il suivre ? Cocher marque aussi le passage, et la validation ouvrira le devis.">
                                        @else
                                            {{ $p->devis_apres_passage ? '☑' : '☐' }}
                                        @endif
                                        <x-date-sous-case :date="$p->date_devis" />
                                    </td>

                                    <td style="white-space:normal; min-width:200px;">
                                        @if ($aTraiter)
                                            <input type="text" wire:model="motifRefus.{{ $p->id }}" class="champ"
                                                style="width:150px;" placeholder="Motif si refus">
                                        @else
                                            <x-saisie-libre :sujet="$p"
                                                :ouvert="$libreSujetId === $p->id && $libreSujetType === get_class($p)" />
                                        @endif
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <a href="{{ route('prospection.voir', $p) }}" wire:navigate class="bouton bouton-petit bouton-secondaire" style="margin-right:5px;">Voir</a>
                                        <button type="button" wire:click="modifierProspection({{ $p->id }})" class="bouton bouton-petit bouton-secondaire" style="margin-right:5px;">Modifier</button>
                                        @if ($aTraiter)
                                            <button type="button" wire:click="validerProspection({{ $p->id }})"
                                                class="bouton bouton-petit bouton-vert" style="margin-right:5px;">Valider</button>
                                            <button type="button" wire:click="refuserProspection({{ $p->id }})"
                                                class="bouton bouton-petit bouton-secondaire">Refuser</button>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <x-table-vide :colspan="11" texte="Aucune prospection en attente de votre validation." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageProsDuJour" :total="$this->prospectionsDuJour->count()" prop="pageProsDuJour" :par-page="7" />

            <div class="bloc-saisie">
                <x-champ label="Clients visités" model="prosClient" />
                <x-champ label="Localisation" model="prosLocalisation" width="130" />
                <x-champ label="Immatriculation" model="prosImmatriculation" width="140" placeholder="1234 AB 01" />
                <x-champ label="N° de fiche" model="prosNFiche" width="140" placeholder="FR-…" />
                <x-champ label="Moyens" model="prosMoyen" type="select" :options="$this->optionsMoyenProspection" width="130" />
                <x-champ label="Commercial" model="prosCommercialId" type="select" :options="$this->commerciauxSelectables" width="170" />
                <x-champ label="Activité" model="prosActivite" type="select" :options="$this->optionsActivite" width="140" />
                <x-champ label="Passage" model="prosPassage" type="checkbox" live="true" />
                @if ($prosPassage && ! $prosDevisApres)
                    <x-champ label="Date de passage" model="prosDatePassage" type="date" width="150" />
                @endif
                <x-champ label="Devis après passage" model="prosDevisApres" type="checkbox" live="true" />
                @if ($prosDevisApres)
                    <x-champ label="Date du devis (= date de passage)" model="prosDateDevis" type="date" live="true" width="180" />
                @endif
                <x-champ label="Observations" model="prosObs" />
                <button type="button" wire:click="ajouterProspection"
                    class="bouton bouton-sombre">
                    + Ajouter
                </button>
            </div>

            <div style="margin-top:14px;">
                <label style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:5px;">Commentaire — Prospects</label>
                <textarea wire:model.live.debounce.700ms="commentaireProspects" rows="2"
                    placeholder="Ex. : affluence en baisse (pluies, jour férié...), campagne de prospection en cours, client flotte reçu..."
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:13.5px; resize:vertical;"></textarea>
            </div>

            <x-sous-titre n="2" t="Devis" />

            @if ($this->prospectionsAttenteDevis->isNotEmpty())
                <div style="width:100%;">
                    <p style="font-size:12.5px; font-weight:700; color:#D97706; margin:0 0 6px;">
                        Devis à effectuer, issus de la prospection ({{ $this->prospectionsAttenteDevis->count() }}) — cocher puis « Ajouter devis » :
                    </p>
                    <div class="tableau-conteneur">
                        <table class="tableau">
                            <thead>
                                <tr>
                                    <th>✓</th>
                                    <th>N° prospection</th>
                                    <th>Client</th>
                                    <th>Date</th>
                                    <th>Commercial</th>
                                    <th>Activité</th>
                                    <th>Observations</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->prospectionsAttenteDevis->forPage($pageAttenteDevis, 7) as $p)
                                    <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);" wire:key="attente-devis-{{ $p->id }}">
                                        <td><input type="checkbox" wire:model="devisSelection.{{ $p->id }}"></td>
                                        <td><x-numero-ligne :ligne="$p" /></td>
                                        <td>{{ $p->client }}</td>
                                        <td>{{ $p->date->format('d/m/Y') }}</td>
                                        <td>{{ $p->commercial->nom }}</td>
                                        <td>{{ $p->activite }}</td>
                                        <td style="color:#6B6E76;">{{ $p->observations ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-pagination :page="$pageAttenteDevis" :total="$this->prospectionsAttenteDevis->count()" prop="pageAttenteDevis" :par-page="7" />
                    <button type="button" wire:click="genererBrouillonsDevis"
                        style="margin-top:8px; background:var(--th-ink,#191B20); color:#fff; border:0; border-radius:8px; padding:8px 14px; font-weight:700; font-size:13px; cursor:pointer;">
                        + Ajouter devis
                    </button>
                </div>
            @endif

            @if (! empty($devisBrouillon))
                <div style="width:100%; background:#FAF9F5; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:8px; padding:12px; margin-top:12px;">
                    <p style="font-size:12.5px; font-weight:700; margin:0 0 8px;">Renseigner les informations du tableau devis puis valider :</p>
                    @foreach ($devisBrouillon as $i => $ligne)
                        <div wire:key="devis-brouillon-{{ $i }}" style="display:flex; flex-wrap:wrap; gap:8px; align-items:end; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <label style="font-size:12.5px; font-weight:600; color:#4B4E55;">N° prospection d'origine</label>
                                <span style="padding:8px 10px; font-weight:700;">{{ $ligne['prospection_numero'] }}</span>
                            </div>
                            <x-champ label="Date de réception" model="devisBrouillon.{{ $i }}.date_reception" type="date" width="140" />
                            <x-champ label="N° fiche de réception" model="devisBrouillon.{{ $i }}.n_fiche_reception" width="130" />
                            <x-champ label="Client" model="devisBrouillon.{{ $i }}.client" />
                            <x-champ label="Date d'émission" model="devisBrouillon.{{ $i }}.date_emission" type="date" width="140" />
                            <x-champ label="Commercial" model="devisBrouillon.{{ $i }}.commercial_id" type="select" :options="$this->commerciauxSelectables" width="160" />
                            <x-champ label="Statut du devis" model="devisBrouillon.{{ $i }}.statut" type="select" :options="['En attente' => 'En attente', 'Validé' => 'Validé', 'Refusé' => 'Refusé']" width="130" live="true" />
                            <x-champ label="Montant du devis" model="devisBrouillon.{{ $i }}.montant_devis" type="number" width="130" />
                            @if (($ligne['statut'] ?? '') === 'Validé')
                                <x-champ label="Montant validé" model="devisBrouillon.{{ $i }}.montant_valide" type="number" width="130" />
                            @endif
                            <x-champ label="Activité" model="devisBrouillon.{{ $i }}.activite" type="select" :options="['Mécanique' => 'Mécanique', 'Sinistre' => 'Sinistre']" width="140" />
                            <x-champ label="Observations" model="devisBrouillon.{{ $i }}.observations" />
                        </div>
                    @endforeach
                    <div style="display:flex; gap:8px;">
                        <button type="button" wire:click="validerDevis"
                            class="bouton">
                            ✓ Valider — rejoint la liste des devis
                        </button>
                        <button type="button" wire:click="annulerBrouillonsDevis"
                            class="bouton bouton-secondaire">
                            Annuler
                        </button>
                    </div>
                </div>
            @endif

            {{-- Devis sans prospection d'origine : client venu de lui-même, devis établi
                 au carnet ou repris d'une autre application. --}}
            <div style="width:100%; margin-top:12px;">
                @if (! $devisLibreOuvert)
                    <button type="button" wire:click="ouvrirDevisLibre" class="bouton bouton-secondaire">
                        + Ajouter un devis (sans prospection)
                    </button>
                @else
                    <div style="background:#FAF9F5; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:8px; padding:12px;">
                        <p style="font-size:12.5px; font-weight:700; margin:0 0 8px;">Devis saisi directement — sans prospection d'origine :</p>
                        <div class="bloc-saisie">
                            <x-champ label="Client" model="devLibreClient" />
                            <x-champ label="Commercial" model="devLibreCommercialId" type="select" :options="$this->commerciauxSelectables" width="170" vide="— Choisir" />
                            <x-champ label="Activité" model="devLibreActivite" type="select" :options="$this->optionsActivite" width="140" />
                            <x-champ label="Date de réception" model="devLibreDateReception" type="date" width="150" />
                            <x-champ label="N° fiche de réception" model="devLibreFiche" width="140" />
                            <x-champ label="Date d'émission" model="devLibreDateEmission" type="date" width="150" />
                            <x-champ label="Statut du devis" model="devLibreStatut" type="select" :options="['En attente' => 'En attente', 'Validé' => 'Validé', 'Refusé' => 'Refusé']" width="130" live="true" />
                            <x-champ label="Montant du devis" model="devLibreMontant" type="number" width="140" />
                            @if ($devLibreStatut === 'Validé')
                                <x-champ label="Montant validé" model="devLibreMontantValide" type="number" width="140" />
                            @endif
                            <x-champ label="Observations" model="devLibreObs" />
                            <button type="button" wire:click="ajouterDevisLibre" class="bouton bouton-sombre">+ Ajouter</button>
                            <button type="button" wire:click="annulerDevisLibre" class="bouton bouton-secondaire">Annuler</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Un seul tableau pour les devis en cours : ceux qui attendent un statut et
                 ceux, validés, qui attendent leur facture. Le statut se change ici même,
                 et rien ne bascule tant que le montant retenu — ou le motif du refus —
                 n'est pas saisi. Les devis refusés et les devis facturés quittent l'écran
                 de saisie : leur histoire est close, ils se consultent dans la page Devis. --}}
            <div class="tableau-conteneur" style="margin-top:14px;">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Réception</th>
                            <th>Fiche</th>
                            <th>Client</th>
                            <th>Émission</th>
                            <th>Commercial</th>
                            <th>Activité</th>
                            <th>Montant devis</th>
                            <th>Statut</th>
                            <th>Montant retenu / motif</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->devisDuJour->forPage($pageDevisDuJour, 7) as $d)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);" wire:key="devis-{{ $d->id }}">
                                <td><x-numero-ligne :ligne="$d" /></td>
                                <td>{{ $d->date_reception?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $d->n_fiche_reception ?? '—' }}</td>
                                <td>{{ $d->client }}</td>
                                <td>{{ $d->date_emission->format('d/m/Y') }}</td>
                                <td>{{ $d->commercial->nom }}</td>
                                <td>{{ $d->activite }}</td>
                                <td>{{ ae($d->montant_devis) }}</td>
                                <td>
                                    <select wire:change="changerStatutDevis({{ $d->id }}, $event.target.value)"
                                        style="padding:5px 8px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:6px; font-size:13px;">
                                        <option {{ $d->statut === 'En attente' ? 'selected' : '' }}>En attente</option>
                                        <option {{ $d->statut === 'Validé' ? 'selected' : '' }}>Validé</option>
                                        <option {{ $d->statut === 'Refusé' ? 'selected' : '' }}>Refusé</option>
                                    </select>
                                </td>
                                <td style="min-width:270px;">
                                    @if ($devisAValiderId === $d->id)
                                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                            <input type="number" wire:model="montantValidation" value="{{ $montantValidation }}" min="1" autofocus
                                                placeholder="Montant retenu"
                                                style="padding:5px 8px; border:1px solid var(--th-accent,#C8102E); border-radius:6px; font-size:13px; width:120px;">
                                            <button type="button" wire:click="confirmerValidationDevis"
                                                class="bouton bouton-petit bouton-vert">Valider</button>
                                            <button type="button" wire:click="annulerValidationDevis"
                                                class="bouton bouton-petit bouton-secondaire">Annuler</button>
                                        </div>
                                        @error('montantValidation')
                                            <div style="font-size:11.5px; color:var(--th-accent,#C8102E); margin-top:4px;">{{ $message }}</div>
                                        @enderror
                                    @elseif ($devisARefuserId === $d->id)
                                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                            <input type="text" wire:model="motifRefusDevis" value="{{ $motifRefusDevis }}" autofocus
                                                placeholder="Motif du refus (prix, délai…)"
                                                style="padding:5px 8px; border:1px solid var(--th-accent,#C8102E); border-radius:6px; font-size:13px; width:170px;">
                                            <button type="button" wire:click="confirmerRefusDevis"
                                                class="bouton bouton-petit bouton-sombre">Refuser</button>
                                            <button type="button" wire:click="annulerRefusDevis"
                                                class="bouton bouton-petit bouton-secondaire">Annuler</button>
                                        </div>
                                        @error('motifRefusDevis')
                                            <div style="font-size:11.5px; color:var(--th-accent,#C8102E); margin-top:4px;">{{ $message }}</div>
                                        @enderror
                                    @elseif ($d->statut === 'Validé')
                                        <span style="font-weight:700; color:#0E9F6E;">{{ ae($d->montant_valide) }}</span>
                                        <span style="font-size:11.5px; color:var(--th-gris,#6B6E76);">— à facturer</span>
                                    @else
                                        <span style="font-size:11.5px; color:var(--th-gris,#6B6E76);">En attente de décision</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="10" texte="Aucun devis en cours : tout est traité." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageDevisDuJour" :total="$this->devisDuJour->count()" prop="pageDevisDuJour" :par-page="7" />

            <div style="margin-top:14px;">
                <label style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:5px;">Commentaire — Devis</label>
                <textarea wire:model.live.debounce.700ms="commentaireDevis" rows="2"
                    placeholder="Ex. : baisse des devis liée à..., motifs des refus (prix jugé élevé, délai, pièces indisponibles)..."
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:13.5px; resize:vertical;"></textarea>
            </div>

        </x-carte-section>

        <x-carte-section titre="Chiffre d'affaires facturé" icone="facture" couleur="var(--th-vert,#0E9F6E)">
            @if ($this->devisValidesNonFactures->isNotEmpty())
                <div style="width:100%;">
                    <p style="font-size:12.5px; font-weight:700; color:#D97706; margin:0 0 6px;">
                        Devis validés en attente de facturation ({{ $this->devisValidesNonFactures->count() }}) — cocher ceux à facturer :
                    </p>
                    <div class="tableau-conteneur">
                        <table class="tableau">
                            <thead>
                                <tr>
                                    <th>✓</th>
                                    <th>N° devis</th>
                                    <th>Client</th>
                                    <th>Montant validé</th>
                                    <th>Commercial</th>
                                    <th>Activité</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->devisValidesNonFactures->forPage($pageValidesNonFactures, 7) as $d)
                                    <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);" wire:key="valide-non-facture-{{ $d->id }}">
                                        <td><input type="checkbox" wire:model="factureSelection.{{ $d->id }}"></td>
                                        <td><x-numero-ligne :ligne="$d" /></td>
                                        <td>{{ $d->client }}</td>
                                        <td>{{ ae($d->montant_valide ?? $d->montant_devis) }}</td>
                                        <td>{{ $d->commercial->nom }}</td>
                                        <td>{{ $d->activite }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-pagination :page="$pageValidesNonFactures" :total="$this->devisValidesNonFactures->count()" prop="pageValidesNonFactures" :par-page="7" />
                    <button type="button" wire:click="genererBrouillonsFactures"
                        style="margin-top:8px; background:var(--th-ink,#191B20); color:#fff; border:0; border-radius:8px; padding:8px 14px; font-weight:700; font-size:13px; cursor:pointer;">
                        + Facturer la sélection
                    </button>
                </div>
            @endif

            @if (! empty($factureBrouillon))
                <div style="width:100%; background:#FAF9F5; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:8px; padding:12px; margin-top:12px;">
                    <p style="font-size:12.5px; font-weight:700; margin:0 0 8px;">Tableau de facturation — compléter puis valider :</p>
                    @foreach ($factureBrouillon as $i => $ligne)
                        <div wire:key="facture-brouillon-{{ $i }}" style="display:flex; flex-wrap:wrap; gap:8px; align-items:end; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <label style="font-size:12.5px; font-weight:600; color:#4B4E55;">N° devis d'origine</label>
                                <span style="padding:8px 10px; font-weight:700;">{{ $ligne['devis_numero'] }}</span>
                            </div>
                            <x-champ label="Commercial" model="factureBrouillon.{{ $i }}.commercial_id" type="select" :options="$this->commerciauxSelectables" width="160" />
                            <x-champ label="Clients" model="factureBrouillon.{{ $i }}.client" />
                            <x-champ label="Type" model="factureBrouillon.{{ $i }}.type" type="select" :options="['FNE' => 'FNE', 'HT' => 'HT']" width="90" />
                            {{-- Le numéro se voit avant d'enregistrer, dans un champ grisé :
                                 il n'est pas saisissable, mais on doit pouvoir le lire pour
                                 l'annoncer au client ou le noter sur un bon. Il n'est pas
                                 réservé pour autant — réserver puis abandonner laisserait un
                                 trou dans la séquence. --}}
                            <div style="display:flex; flex-direction:column; gap:4px; width:170px;">
                                <label style="font-size:12.5px; font-weight:600; color:#4B4E55;">N° de facture</label>
                                <input type="text" class="champ" disabled
                                    value="{{ $this->numerosFacturePrevus[$i] ?? '' }}"
                                    style="background:#F1EFE9; color:#6B6E76; font-weight:700;">
                                <span style="font-size:11px; color:#9A9DA5;">généré automatiquement</span>
                            </div>
                            <x-champ label="Activité" model="factureBrouillon.{{ $i }}.activite" type="select" :options="['Mécanique' => 'Mécanique', 'Sinistre' => 'Sinistre']" width="140" />
                            <x-champ label="Montant de la facture" model="factureBrouillon.{{ $i }}.montant" type="number" width="140" />
                            <x-champ label="Observations" model="factureBrouillon.{{ $i }}.observations" />
                        </div>
                    @endforeach
                    <div style="display:flex; gap:8px;">
                        <button type="button" wire:click="validerFactures"
                            class="bouton">
                            ✓ Valider la facturation
                        </button>
                        <button type="button" wire:click="annulerBrouillonsFactures"
                            class="bouton bouton-secondaire">
                            Annuler
                        </button>
                    </div>
                </div>
            @endif

            {{-- Facture émise hors application : le N° de devis rattache la ligne à son
                 devis quand il existe ici, et reste consigné tel quel sinon. --}}
            <div style="width:100%; margin-top:12px;">
                @if (! $factureLibreOuvert)
                    <button type="button" wire:click="ouvrirFactureLibre" class="bouton bouton-secondaire">
                        + Ajouter une facture (sans devis dans l'application)
                    </button>
                @else
                    <div style="background:#FAF9F5; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:8px; padding:12px;">
                        <p style="font-size:12.5px; font-weight:700; margin:0 0 8px;">Facture saisie directement — indiquer le N° de devis s'il existe :</p>
                        <div class="bloc-saisie">
                            <x-champ label="Client" model="facLibreClient" />
                            <x-champ label="Commercial" model="facLibreCommercialId" type="select" :options="$this->commerciauxSelectables" width="170" vide="— Choisir" />
                            <x-champ label="Activité" model="facLibreActivite" type="select" :options="$this->optionsActivite" width="140" />
                            <x-champ label="Type" model="facLibreType" type="select" :options="['FNE' => 'FNE', 'HT' => 'HT']" width="90" />
                            <x-champ label="N° de facture (vide = généré)" model="facLibreNumero" width="170" />
                            <div style="display:flex; flex-direction:column; gap:4px; min-width:160px;">
                                <label style="font-size:12.5px; font-weight:600; color:#4B4E55;">N° de devis d'origine</label>
                                <input type="text" wire:model="facLibreRefDevis" value="{{ $facLibreRefDevis }}" list="devis-rattachables" class="champ" placeholder="Facultatif">
                                <datalist id="devis-rattachables">
                                    @foreach ($this->numerosDevisRattachables as $numero)
                                        <option value="{{ $numero }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                            <x-champ label="Montant de la facture" model="facLibreMontant" type="number" width="150" />
                            <x-champ label="Observations" model="facLibreObs" />
                            <button type="button" wire:click="ajouterFactureLibre" class="bouton bouton-sombre">+ Ajouter</button>
                            <button type="button" wire:click="annulerFactureLibre" class="bouton bouton-secondaire">Annuler</button>
                        </div>
                    </div>
                @endif
            </div>

            <div style="overflow-x:auto; margin-top:14px;">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Commercial</th>
                            <th>Clients</th>
                            <th>Type</th>
                            <th>N° de facture</th>
                            <th>N° de devis</th>
                            <th>Activité</th>
                            <th>Montant de la facture</th>
                            <th>Observations</th>
                            <th>Informations libres</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->facturesDuJour->forPage($pageFacturesDuJour, 7) as $f)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><x-numero-ligne :ligne="$f" /></td>
                                <td>{{ $f->commercial->nom }}</td>
                                <td>{{ $f->client }}</td>
                                <td>{{ $f->type }}</td>
                                <td>{{ $f->n_facture }}</td>
                                <td>{{ $f->reference_devis ?? '—' }}</td>
                                <td>{{ $f->activite }}</td>
                                <td style="font-weight:700;">{{ ae($f->montant) }}</td>
                                <td style="color:#6B6E76;">{{ $f->observations ?? '—' }}</td>
                                <td style="white-space:normal; min-width:220px;">
                                    <x-saisie-libre :sujet="$f"
                                        :ouvert="$libreSujetId === $f->id && $libreSujetType === get_class($f)" />
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="10" texte="Aucune facture émise pour cette journée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageFacturesDuJour" :total="$this->facturesDuJour->count()" prop="pageFacturesDuJour" :par-page="7" />

            <div style="margin-top:14px;">
                <label style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:5px;">Commentaire — Chiffre d'affaires</label>
                <textarea wire:model.live.debounce.700ms="commentaireCA" rows="2"
                    placeholder="Ex. : facturation retardée (dossier assurance en attente d'expertise), gros dossier livré et facturé, avoir émis..."
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:13.5px; resize:vertical;"></textarea>
            </div>
        </x-carte-section>

        <x-carte-section titre="Encaissements" icone="encaissement" couleur="var(--th-bleu,#2563EB)">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Type d'encaissement</th>
                            <th>Activité</th>
                            <th>Moyens</th>
                            <th>Montant</th>
                            <th>Clients</th>
                            <th>Autres tiers</th>
                            <th>Origine / référence</th>
                            <th>Informations libres</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->encaissementsDuJour->forPage($pageEncaissementsDuJour, 7) as $e)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><x-numero-ligne :ligne="$e" /></td>
                                <td>{{ $e->type }}</td>
                                <td>{{ $e->activite ?? '—' }}</td>
                                <td>{{ $e->moyen }}</td>
                                <td style="font-weight:700; color:#0E9F6E;">{{ ae($e->montant) }}</td>
                                <td>{{ $e->client ?? '—' }}</td>
                                <td>{{ $e->autres_tiers ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $e->reference_origine ?? '—' }}</td>
                                <td style="white-space:normal; min-width:220px;">
                                    <x-saisie-libre :sujet="$e"
                                        :ouvert="$libreSujetId === $e->id && $libreSujetType === get_class($e)" />
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="9" texte="Aucun encaissement saisi pour cette journée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageEncaissementsDuJour" :total="$this->encaissementsDuJour->count()" prop="pageEncaissementsDuJour" :par-page="7" />

            <div class="bloc-saisie">
                <x-champ label="Type d'encaissement" model="encType" type="select" live="true" :options="$this->optionsTypeEncaissement" width="140" />
                @if ($encType === 'Client')
                    <x-champ label="Facture à encaisser" model="encFactureId" type="select" width="260" vide="— Choisir une facture"
                        :options="$this->facturesAvecReste->mapWithKeys(fn ($f) => [$f->id => $f->n_facture.' — '.$f->client.' — reste '.ae($f->resteAEncaisser())])" />
                @endif
                <x-champ label="Moyens" model="encMoyen" type="select" :options="$this->optionsMoyenPaiement" width="150" />
                <x-champ label="Montant (FCFA)" model="encMontant" type="number" width="140" />
                @if ($encType !== 'Client')
                    <div style="display:flex; flex-direction:column; gap:4px; flex:1; min-width:160px;">
                        <label style="font-size:12.5px; font-weight:600; color:#4B4E55;">Clients</label>
                        <input type="text" wire:model="encClient" value="{{ $encClient }}" list="clients-connus"
                            style="padding:8px 10px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px;">
                        <datalist id="clients-connus">
                            @foreach ($this->clientsConnus as $client)
                                <option value="{{ $client }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <x-champ label="Autres tiers à préciser" model="encTiers" />
                    {{-- Un encaissement adossé à une facture hérite de son activité ;
                         seul un encaissement libre a besoin qu'on la précise. --}}
                    <x-champ label="Activité (facultatif)" model="encActivite" type="select" width="150"
                        :options="['' => '— Non précisée'] + $this->optionsActivite" />
                @endif
                <x-champ label="Origine / référence" model="encReference" width="170" />
                <button type="button" wire:click="ajouterEncaissement"
                    class="bouton bouton-sombre">
                    + Ajouter
                </button>
            </div>
            @error('encMontant') <div style="color:#C8102E; font-size:13.5px; margin-top:8px;">{{ $message }}</div> @enderror

            <div style="margin-top:14px;">
                <label style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:5px;">Commentaire — Trésorerie</label>
                <textarea wire:model.live.debounce.700ms="commentaireTresorerie" rows="2"
                    placeholder="Ex. : trésorerie insuffisante pour..., appro reçu de la DG, promesse de règlement client X..."
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:13.5px; resize:vertical;"></textarea>
            </div>
        </x-carte-section>

        <x-carte-section titre="Charges & décaissements" icone="charge" couleur="var(--th-accent,#C8102E)">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Date</th>
                            <th>Type d'opération</th>
                            <th>Activité</th>
                            <th>Libellé d'opération</th>
                            <th>Moyens</th>
                            <th>Montant</th>
                            <th>Tiers</th>
                            <th>Origine / référence</th>
                            <th>Observations</th>
                            <th>Informations libres</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->chargesDuJour->forPage($pageChargesDuJour, 7) as $c)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><x-numero-ligne :ligne="$c" /></td>
                                <td>{{ $c->date->format('d/m/Y') }}</td>
                                <td>{{ $c->type_operation === 'Charges' ? 'Charges' : 'Décaissements' }}</td>
                                <td>{{ $c->activite ?? '—' }}</td>
                                <td>{{ $c->libelle }}</td>
                                <td>{{ $c->moyen }}</td>
                                <td style="font-weight:700; color:#C8102E;">{{ ae($c->montant) }}</td>
                                <td>{{ $c->tiers ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $c->reference_origine ?? '—' }}</td>
                                <td style="color:#6B6E76;">{{ $c->observations ?? '—' }}</td>
                                <td style="white-space:normal; min-width:220px;">
                                    <x-saisie-libre :sujet="$c"
                                        :ouvert="$libreSujetId === $c->id && $libreSujetType === get_class($c)" />
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="11" texte="Aucune charge ni décaissement saisi pour cette journée." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageChargesDuJour" :total="$this->chargesDuJour->count()" prop="pageChargesDuJour" :par-page="7" />

            <div class="bloc-saisie">
                <x-champ label="Date" model="chgDate" type="date" width="140" />
                <x-champ label="Type d'opération" model="chgTypeOp" type="select" :options="['Charges' => 'Charges', 'Décaissements' => 'Décaissements']" width="150" live="true" />
                <x-champ label="Libellé d'opération" model="chgLibelle" type="select" :options="array_combine($this->libellesOperation, $this->libellesOperation)" width="220" />
                <x-champ label="Moyens" model="chgMoyen" type="select" :options="$this->optionsMoyenPaiement" width="150" />
                <x-champ label="Montant (FCFA)" model="chgMontant" type="number" width="140" />
                <x-champ label="Tiers" model="chgTiers" width="160" />
                {{-- Beaucoup de charges (loyer, salaires) relèvent du lieu entier : l'activité
                     ne se renseigne que lorsqu'elle est réellement connue. --}}
                <x-champ label="Activité (facultatif)" model="chgActivite" type="select" width="150"
                    :options="['' => '— Non précisée'] + $this->optionsActivite" />
                <x-champ label="Origine / référence" model="chgReference" width="170" />
                <x-champ label="Observations" model="chgObs" />
                <button type="button" wire:click="ajouterCharge"
                    class="bouton bouton-sombre">
                    + Ajouter
                </button>
            </div>

            <div style="margin-top:14px;">
                <label style="display:block; font-size:12.5px; font-weight:600; color:#4B4E55; margin-bottom:5px;">Commentaire — Charges</label>
                <textarea wire:model.live.debounce.700ms="commentaireCharges" rows="2"
                    placeholder="Ex. : achat exceptionnel de matériel, hausse du prix des pièces, transfert vers le site de..., dépense DG (préciser l'objet)..."
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:13.5px; resize:vertical;"></textarea>
            </div>
        </x-carte-section>

        <x-carte-section titre="Atelier" icone="atelier" couleur="var(--th-ambre,#D97706)">
            <x-champ label="Véhicules restitués SANS facture (nb)" model="vehiculesSansFacture" type="number" width="260" live="true" />
            @if ((int) $vehiculesSansFacture > 0)
                <div style="margin-top:10px; background:#FDF2F4; border:1px solid #C8102E; color:#C8102E; border-radius:8px; padding:10px 14px; font-size:13.5px; display:flex; gap:8px; align-items:center;">
                    ⚠ Anomalie critique : signalement immédiat à la Direction.
                </div>
            @endif
            <p style="font-size:12px; color:#9A9DA5; margin-top:14px;">
                Chaque ligne (prospection, devis, facture, encaissement, opération) est enregistrée immédiatement à l'ajout et alimente les onglets en temps réel.
            </p>
        </x-carte-section>
    @endif
</div>

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Exploitation\Services\ReglementGlobal;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use Modules\Recouvrement\Support\AccesRecouvrement;
use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Saisie — le seul écran qui écrit
|--------------------------------------------------------------------------
| Quatre gestes, et une règle qui les sépare : celui qui relance et encaisse ne
| crée pas la créance qu'il poursuit. L'agent enregistre encaissements et relances ;
| la facture et le tiers relèvent du superviseur et du gérant.
|
| Ce n'est pas une précaution abstraite. Un même agent capable de créer une facture,
| d'en encaisser le règlement et de clore le dossier peut faire disparaître une somme
| sans que rien ne l'indique — c'est exactement le trou que l'audit avait relevé.
|
| Un encaissement saisi ici est un encaissement comme un autre : il est rattaché à sa
| facture, entre en trésorerie, et sort de la balance âgée à l'instant même.
*/

/*
 * La période commande les chiffres du bandeau. Trois appels séparés à `state()` : la
 * liaison à l'adresse attend une chaîne, pas un tableau.
 */
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

state([
    // La date de l'écriture qu'on s'apprête à enregistrer — et rien d'autre. Elle ne
    // commande plus les chiffres du bandeau : ceux-là suivent la période choisie en haut.
    'dateTravail' => fn () => now()->toDateString(),

    /*
     * Encaissement — refait le 28/09 sur le modèle de l'écran des impayés.
     *
     * Il ne visait **qu'une facture**, et il manquait la moitié de ce qui fait un
     * règlement : la banque, et de quoi voir le dossier qu'on solde. Le propriétaire l'a
     * relevé : « on ne sait pas chez qui ça s'est déposé, les dates et bien d'autres — on
     * n'a pas tous les champs nécessaires par rapport aux impayés ».
     *
     * Il vise maintenant **une ou plusieurs factures** : un client remet un chèque pour ce
     * qu'il doit, pas pour une pièce précise. `encFactures` porte les cases cochées.
     */
    'encTiers' => '',
    'encFactures' => [],
    'encMode' => '',
    'encMontant' => '',
    'encReference' => '',
    'encBanque' => '',

    // Relance
    'relTiers' => '',
    'relFacture' => '',
    // La relance a sa propre date. Elle empruntait celle du formulaire d'encaissement,
    // qui est à l'autre bout de l'écran : on traçait un appel du jour à la date d'une
    // écriture qu'on venait de reculer, sans que rien ne le montre.
    'relDate' => fn () => now()->toDateString(),
    'relNiveau' => 1,
    'relCanal' => '',
    'relInterlocuteur' => '',
    'relResultat' => '',
    'relPromis' => '',
    'relStatut' => 'En cours',

    // Facture (superviseur / gérant)
    'facTiers' => '',
    'facAssureur' => '',
    'facCourtier' => '',
    // Chez qui la facture a été déposée. Renseigné, il passe devant le courtier : c'est le
    // dépôt qui désigne celui à qui la créance est réclamée.
    'facDeposeChez' => '',
    'facSiteId' => '',
    'facDate' => '',
    'facNumero' => '',
    'facVehicule' => '',
    'facImmatriculation' => '',
    'facMontant' => '',
    'facActivite' => 'Sinistre',
    // Pourquoi cette créance existe. Une facture saisie ici n'a pas toujours de document
    // d'atelier derrière elle : sans un mot, six mois plus tard, personne ne sait.
    'facObservations' => '',

    // Tiers (superviseur / gérant)
    'nouveauTiers' => '',
]);

/** La période regardée, et l'arrêté qu'elle commande — voir PeriodeDeTravail. */
$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

/*
 * Lues telles que la base les rend, sans en faire des objets : cet écran additionne les
 * créances ouvertes, il n'en affiche aucune ligne à ligne. Voir lignesOuvertes().
 */
$ouvertes = computed(fn () => Recouvrement::lignesOuvertes($this->arrete));

$kpis = computed(fn () => Recouvrement::kpis($this->ouvertes, $this->arrete));

$tiers = computed(fn () => Recouvrement::tiers());

$modes = computed(fn () => Referentiel::options(Referentiel::MODE_RECOUVREMENT));

$activites = computed(fn () => Referentiel::options(Referentiel::ACTIVITE));

$sites = computed(fn () => Site::where('est_actif', true)->orderBy('nom')->pluck('nom', 'id')->all());

$peutRediger = computed(fn () => AccesRecouvrement::peutCreerUneFacture(auth()->user()));

$nombreRelances = computed(fn () => RelanceRecouvrement::count());

/**
 * Les tiers qu'on peut réellement encaisser ou relancer : ceux qui doivent encore.
 *
 * **Ce qui n'allait pas.** Les deux listes proposaient les 2 445 tiers connus de la
 * facturation. Or 2 318 d'entre eux n'ont plus rien d'ouvert : sept noms sur huit
 * menaient à « aucune facture ouverte ». On ne relance pas un compte soldé, et l'on
 * n'encaisse pas sur une facture déjà payée — ces noms n'avaient rien à faire là.
 *
 * **Ce que cela coûtait.** Cinq listes de 2 445 entrées faisaient 1,35 Mo sur les 1,42 Mo
 * de la page, et 12 285 balises `option` dans le document. Chaque choix de tiers renvoyait
 * tout ce poids au navigateur, qui devait ensuite reconstruire les 12 285 nœuds. Pendant
 * ce temps la liste des factures gardait son ancien texte, et l'on en concluait qu'elle
 * ne se remplissait pas.
 *
 * La liste se déduit des factures ouvertes elles-mêmes, et non du référentiel : elle
 * correspond ainsi exactement, et par construction, à ce que la liste des factures saura
 * proposer ensuite. Un tiers qui figure ici a forcément au moins une facture.
 */
$tiersOuverts = computed(fn () => Recouvrement::tiersDebiteurs());

/**
 * Les factures ouvertes du tiers choisi, la plus lourde en tête.
 *
 * **Sans borne de date, et c'est délibéré.** Le bandeau de chiffres s'arrête à l'arrêté —
 * c'est le sens même d'un arrêté, et ce qui le rend comparable à la balance âgée. Mais
 * borner ces listes de la même façon rendait invisible toute facture postérieure à cette
 * date : un règlement arrivé sur une facture de la semaine prochaine, ou n'importe quelle
 * facture dès qu'on reculait la date pour consulter un encours passé. La facture existait,
 * l'argent était là, et la liste répondait « aucune facture ».
 *
 * La lecture est ciblée sur le seul tiers demandé. Elle passait auparavant par la
 * collection entière des factures ouvertes : mille trois cent quarante lignes lues et
 * transformées en objets, deux fois par rendu, pour n'en afficher qu'une poignée.
 */
$facturesDuTiersEncaissement = computed(fn () => Recouvrement::facturesOuvertesDuTiers($this->encTiers)
    ->sortByDesc(fn (Facture $f) => Recouvrement::reste($f))->values());

$facturesDuTiersRelance = computed(fn () => Recouvrement::facturesOuvertesDuTiers($this->relTiers)
    ->sortByDesc(fn (Facture $f) => Recouvrement::reste($f))->values());

/**
 * Les factures cochées, et elles seules.
 *
 * On repart **toujours** de la liste du tiers plutôt que des identifiants reçus : une case
 * cochée puis un changement de tiers laisserait sinon pointer le versement sur la créance
 * de quelqu'un d'autre.
 */
$encSelection = computed(fn () => $this->facturesDuTiersEncaissement->filter(
    fn (Facture $f) => ! empty($this->encFactures[$f->id]),
)->values());

/**
 * Ce que le versement donnerait à chaque facture — montré avant d'écrire.
 *
 * La règle est écrite une seule fois, dans `ReglementGlobal` : la plus ancienne d'abord,
 * jusqu'à épuisement. L'écran la rejoue pour l'afficher, l'action la rejoue pour écrire —
 * la même, et on ne peut donc pas enregistrer autre chose que ce qui était montré.
 */
$encRepartition = computed(fn () => ReglementGlobal::repartir(
    $this->encSelection,
    (int) ($this->encMontant ?: 0),
));

$encTotalDu = computed(fn () => $this->encSelection->sum(fn (Facture $f) => Recouvrement::reste($f)));

/**
 * Le niveau qu'appelle le dossier du tiers relancé : celui de sa facture la plus
 * ancienne. On ne relance pas un assureur au niveau moyen de ses factures.
 */
$niveauRequis = computed(function () {
    $factures = $this->facturesDuTiersRelance;

    if ($factures->isEmpty()) {
        return 0;
    }

    return $factures->max(fn (Facture $f) => Recouvrement::niveau($f, $this->arrete)['niveau']);
});

$soldeDuTiersRelance = computed(fn () => $this->facturesDuTiersRelance->sum(fn (Facture $f) => Recouvrement::reste($f)));

/*
|--------------------------------------------------------------------------
| Réactions aux changements de liste
|--------------------------------------------------------------------------
*/
$updatedEncTiers = function () {
    // Les factures cochées appartenaient à l'ancien tiers : les garder ferait pointer
    // le versement sur la créance de quelqu'un d'autre.
    $this->encFactures = [];
    $this->encMontant = '';
    unset($this->facturesDuTiersEncaissement, $this->encSelection, $this->encRepartition, $this->encTotalDu);
};

/**
 * Cocher toutes les factures ouvertes du tiers, et proposer leur total.
 *
 * **Le montant se propose, il ne s'impose pas.** Cocher tout veut presque toujours dire
 * « il a tout payé » ; mais pas toujours — le propriétaire l'a précisé : « la somme donnée
 * ne veut pas dire que ça couvrira toutes les créances ». Le champ reste donc modifiable,
 * et la répartition suit ce qu'on y met.
 */
$toutCocherEncaissement = function () {
    $this->encFactures = $this->facturesDuTiersEncaissement
        ->mapWithKeys(fn (Facture $f) => [$f->id => true])->all();

    unset($this->encSelection, $this->encRepartition, $this->encTotalDu);

    $this->encMontant = (string) $this->encTotalDu;
};

$toutDecocherEncaissement = function () {
    $this->encFactures = [];
    unset($this->encSelection, $this->encRepartition, $this->encTotalDu);
};

$updatedEncFactures = function () {
    unset($this->encSelection, $this->encRepartition, $this->encTotalDu);
};

$updatedRelTiers = function () {
    $this->relFacture = '';
    // Le niveau que le dossier appelle, sans rabot : il découle de l'ancienneté de la
    // facture la plus vieille du tiers, et c'est cette ancienneté-là qui commande le
    // protocole AUPSRVE, pas le grade de celui qui la regarde.
    $this->relNiveau = max(1, $this->niveauRequis ?: 1);
};

/*
|--------------------------------------------------------------------------
| Encaissement
|--------------------------------------------------------------------------
*/
$enregistrerEncaissement = function () {
    /*
     * **Un versement, une ou plusieurs factures.** C'était une facture et une seule, et
     * cela ne correspondait pas au geste réel : un client remet un chèque pour ce qu'il
     * doit, rarement pour une pièce précise. Demandé le 28/09.
     *
     * Ce qui change ici tient en trois points, et le reste est inchangé :
     *
     * 1. on valide une **liste** de factures plutôt qu'un identifiant ;
     * 2. la répartition est celle de `ReglementGlobal` — la plus ancienne d'abord — et
     *    c'est la **même** que celle que l'écran a montrée avant le clic ;
     * 3. au-delà d'une facture, les écritures partagent une **référence de versement**,
     *    pour qu'on puisse dire six mois plus tard « ce chèque a soldé celles-ci ».
     */
    $donnees = $this->validate([
        'dateTravail' => ['required', 'date'],
        'encTiers' => ['required', 'string'],
        'encMode' => ['required', Rule::in(array_keys($this->modes))],
        'encMontant' => ['required', 'numeric', 'min:1'],
        'encReference' => ['nullable', 'string', 'max:120'],
        'encBanque' => ['nullable', 'string', 'max:120'],
    ], [], [
        'encTiers' => 'tiers', 'encMode' => 'mode d\'encaissement',
        'encMontant' => 'montant', 'dateTravail' => 'date', 'encBanque' => 'banque',
    ]);

    $choisies = $this->encSelection;

    if ($choisies->isEmpty()) {
        $this->addError('encFactures', 'Cocher au moins une facture : un règlement se rapporte à une créance.');

        return;
    }

    $montant = (int) $donnees['encMontant'];
    $idsChoisies = $choisies->pluck('id')->all();

    /*
     * Verrou en base le temps du contrôle et de l'écriture. Sans lui, deux agents qui
     * encaissent les mêmes factures au même instant passeraient tous deux le test du
     * reste à payer, et les créances se retrouveraient sur-encaissées — un trou qui ne se
     * voit qu'au rapprochement bancaire, des semaines plus tard.
     */
    $refus = DB::transaction(function () use ($donnees, $montant, $idsChoisies) {
        $factures = Facture::with('site')
            ->whereIn('id', $idsChoisies)
            ->lockForUpdate()
            ->get();

        if ($factures->count() !== count($idsChoisies)) {
            return "Une des factures n'existe plus : recommencez la sélection.";
        }

        foreach ($factures as $facture) {
            // Le rattachement se contrôle sur le tiers payant, pas sur la colonne
            // `client` : une facture apportée par un courtier est due par le courtier, et
            // c'est sous son nom qu'elle a été choisie dans la liste.
            if ($facture->tiersPayant() !== $donnees['encTiers']) {
                return "Une des factures cochées n'appartient pas à ce tiers.";
            }

            // La clôture se prononce ville par ville. Une facture sans lieu rattaché ne
            // peut pas être rapportée à une ville : on ne la bloque pas sur une clôture
            // qui ne la vise peut-être pas.
            $villeId = $facture->site?->ville_id ?? ($facture->ville_id ? (int) $facture->ville_id : null);

            if ($villeId && Exercice::estFerme(auth()->user()->entreprise_id, $villeId, $donnees['dateTravail'])) {
                return "L'exercice est clos pour une de ces villes à cette date : l'encaissement ne peut plus y être imputé.";
            }
        }

        // La répartition est refaite **sous verrou**, sur les restes à jour : ceux que
        // l'écran affichait pouvaient dater de quelques minutes.
        $repartition = ReglementGlobal::repartir($factures, $montant);

        if ($repartition['reste_du_versement'] > 0) {
            return 'Le versement dépasse de '.Recouvrement::fr($repartition['reste_du_versement'])
                .' le total dû sur les factures cochées ('.Recouvrement::fr($repartition['total_du']).').'
                .' Un trop-perçu est une décision — avoir, ou avance sur une facture à venir — et ne s\'impute pas ici.';
        }

        /*
         * La référence du versement n'est posée **qu'au-delà d'une facture**. Un règlement
         * d'une seule ligne n'est pas un règlement global, et le marquer comme tel ferait
         * apparaître des « paiements groupés » qui n'en sont pas dans la colonne de l'état.
         */
        $reference = $factures->count() > 1
            ? ReglementGlobal::reference(auth()->user()->entreprise_id, $donnees['dateTravail'])
            : null;

        foreach ($repartition['parts'] as $part) {
            if ($part['part'] <= 0) {
                continue;
            }

            $facture = $part['facture'];

            Encaissement::create([
                'entreprise_id' => auth()->user()->entreprise_id,
                /*
                 * **L'atelier de l'encaissement, et pourquoi il ne peut pas rester vide.**
                 *
                 * Il était recopié de la facture, sans plus. Or **8 937 factures sur
                 * 11 332 n'ont pas d'atelier** — mesuré le 24/09 : la colonne SITE des
                 * exports dit « ABIDJAN », et Abidjan en a deux, si bien que l'import
                 * s'arrête à la ville. L'encaissement héritait donc d'un atelier nul dans
                 * près de huit cas sur dix.
                 *
                 * Ce n'est pas anodin : l'écran *Trésorerie* retient les encaissements par
                 * `whereIn('site_id', …)`, et un `site_id` nul n'entre dans aucun
                 * `whereIn`. Le règlement serait bien enregistré, la balance âgée et
                 * l'extrait de compte le verraient — mais il **n'apparaîtrait jamais en
                 * trésorerie**, sans qu'une ligne ne le signale.
                 */
                'site_id' => $facture->site_id ?? $this->atelierPour($facture),
                'facture_id' => $facture->id,
                'date' => $donnees['dateTravail'],
                'type' => 'Client',
                // L'encaissement hérite de l'activité de la facture qu'il solde : c'est ce
                // qui rend la ligne ventilable en trésorerie.
                'activite' => $facture->activite,
                'moyen' => $donnees['encMode'],
                'montant' => $part['part'],
                // Celui qui a réellement payé, donc le courtier s'il y en a un. Inscrire
                // l'assuré ici couperait l'extrait de compte du courtier en deux : ses
                // factures d'un côté, ses règlements de l'autre.
                'client' => $facture->tiersPayant(),
                'reference_origine' => $donnees['encReference'] ?: $facture->n_facture,
                'reglement_global' => $reference,
                'cree_par' => auth()->id(),
            ]);

            // La banque est une information de la créance, pas de l'écriture : c'est
            // l'état des impayés qui la porte, et c'est là qu'elle doit se retrouver.
            if (trim((string) $donnees['encBanque']) !== '') {
                $facture->forceFill(['banque' => trim($donnees['encBanque'])])->save();
            }
        }

        return null;
    });

    if ($refus !== null) {
        $this->addError('encMontant', $refus);
        unset($this->ouvertes, $this->kpis, $this->tiersOuverts);

        return;
    }

    activity()->causedBy(auth()->user())
        ->withProperties([
            'tiers' => $donnees['encTiers'],
            'montant' => $montant,
            'mode' => $donnees['encMode'],
            'factures' => count($idsChoisies),
        ])
        ->log('Recouvrement — encaissement enregistré');

    /*
     * **Le formulaire se vide en entier, tiers compris.** Demandé le 24/09 : un formulaire
     * à demi rempli après un enregistrement se lit comme un formulaire pas encore
     * enregistré, et le geste suivant est de recliquer. On préfère retaper le tiers que
     * d'encaisser deux fois. La date de travail, elle, reste : c'est un réglage de séance.
     *
     * `fill` et non `reset` : Volt ne rend pas toujours à `reset()` la valeur déclarée dans
     * `state()`, et un `encTiers` remis à null fait sauter le rendu suivant.
     */
    $this->fill(['encTiers' => '', 'encFactures' => [], 'encMode' => '',
        'encMontant' => '', 'encReference' => '', 'encBanque' => '']);

    unset($this->ouvertes, $this->kpis, $this->tiersOuverts, $this->facturesDuTiersEncaissement,
        $this->encSelection, $this->encRepartition, $this->encTotalDu);

    $this->dispatch('annonce', ton: 'succes', texte: 'Encaissement de '.Recouvrement::fr($montant)
        .' affecté sur '.count($idsChoisies).' facture(s) — la balance âgée et la trésorerie sont à jour.');
};

/**
 * L'atelier auquel rapporter un règlement dont la facture n'en porte aucun.
 *
 * Deux replis, dans cet ordre, et aucun n'invente : l'unique atelier de la ville de la
 * facture — Bouaké et San-Pédro n'en ont qu'un, la question ne s'y pose pas —, puis celui
 * de la personne qui encaisse. Si rien de tout cela n'est connu, on rend null : mieux vaut
 * un règlement sans lieu qu'un règlement rangé au hasard entre deux ateliers d'Abidjan.
 */
$atelierPour = protect(function (Facture $facture): ?int {
    $villeId = $facture->site?->ville_id ?? ($facture->ville_id ? (int) $facture->ville_id : null);

    if ($villeId !== null) {
        $sites = Site::where('ville_id', $villeId)->where('est_actif', true)->pluck('id');

        if ($sites->count() === 1) {
            return (int) $sites->first();
        }
    }

    return auth()->user()->site_id ? (int) auth()->user()->site_id : null;
});

/*
|--------------------------------------------------------------------------
| Relance
|--------------------------------------------------------------------------
*/
$enregistrerRelance = function () {
    $donnees = $this->validate([
        'relDate' => ['required', 'date'],
        'relTiers' => ['required', 'string', 'max:160'],
        'relFacture' => ['nullable', 'string', 'max:255'],
        'relNiveau' => ['required', 'integer', 'min:1', 'max:5'],
        'relCanal' => ['required', Rule::in(RelanceRecouvrement::CANAUX)],
        'relInterlocuteur' => ['nullable', 'string', 'max:120'],
        'relResultat' => ['nullable', 'string', 'max:2000'],
        'relPromis' => ['nullable', 'numeric', 'min:0'],
        'relStatut' => ['required', Rule::in(RelanceRecouvrement::STATUTS)],
    ], [], ['relTiers' => 'tiers', 'relNiveau' => 'niveau', 'relCanal' => 'canal',
        'relDate' => 'date de la relance']);

    /*
     * Tous les niveaux sont ouverts à tous les rôles du module, N4 et N5 compris.
     *
     * **Ce choix est celui de la direction, et il remplace le précédent.** Les deux
     * derniers niveaux étaient d'abord fermés, puis escaladés « en attente de validation ».
     * Le raisonnement était qu'une mise en demeure engage l'entreprise et ne relève pas de
     * celui qui décroche le téléphone. L'usage a tranché autrement : dans une équipe de
     * deux personnes, faire repasser chaque mise en demeure par un second compte n'ajoute
     * pas de contrôle, cela ajoute un délai — et un délai, sur une créance de plus de
     * quatre-vingt-dix jours, coûte plus cher que le risque qu'il écarte.
     *
     * **Ce qui remplace le verrou, et ce n'est pas rien.** Chaque relance reste datée,
     * nominative et inscrite au journal d'audit : qui l'a tracée, à quel niveau, sur quel
     * tiers, par quel canal. Le superviseur et le gérant lisent ce journal. La question
     * « qui a envoyé cette mise en demeure » a donc toujours une réponse — elle se lit
     * après coup au lieu de se poser avant. C'est un contrôle a posteriori, assumé comme
     * tel, et non l'absence de contrôle.
     *
     * Le statut choisi dans le formulaire est enregistré tel quel : plus aucune relance
     * n'est retenue en attente.
     */
    RelanceRecouvrement::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'user_id' => auth()->id(),
        'responsable' => auth()->user()->name,
        'date' => $donnees['relDate'],
        'tiers' => $donnees['relTiers'],
        'factures_visees' => $donnees['relFacture'] ?: 'Situation globale',
        'niveau' => (int) $donnees['relNiveau'],
        'canal' => $donnees['relCanal'],
        'interlocuteur' => $donnees['relInterlocuteur'] ?: null,
        'resultat' => $donnees['relResultat'] ?: null,
        'montant_promis' => (int) ($donnees['relPromis'] ?: 0),
        'statut' => $donnees['relStatut'],
    ]);

    activity()->causedBy(auth()->user())
        ->withProperties(['tiers' => $donnees['relTiers'], 'niveau' => 'N'.$donnees['relNiveau'], 'canal' => $donnees['relCanal']])
        ->log('Recouvrement — relance N'.$donnees['relNiveau'].' tracée');

    // Vidé en entier, pour la même raison que l'encaissement : une relance N5 qu'on
    // enregistre deux fois part deux fois chez l'huissier. Le niveau revient à N1 et le
    // statut à « En cours » — leurs valeurs de départ —, la date de relance reste.
    $this->fill(['relTiers' => '', 'relFacture' => '', 'relNiveau' => 1, 'relCanal' => '',
        'relInterlocuteur' => '', 'relResultat' => '', 'relPromis' => '', 'relStatut' => 'En cours']);
    unset($this->nombreRelances);

    // Les deux derniers niveaux engagent l'entreprise : le message le rappelle au moment
    // où le geste est posé, et nomme celui qui l'a posé. C'est le seul contrôle qui reste,
    // autant qu'il soit lisible.
    $lourd = (int) $donnees['relNiveau'] >= 4;

    $this->dispatch('annonce', ton: 'succes', texte: 'Relance N'.$donnees['relNiveau']
        .' enregistrée pour '.$donnees['relTiers']
        .($lourd
            ? ' — '.((int) $donnees['relNiveau'] === 5 ? 'contentieux' : 'mise en demeure')
                .' engagée au nom de '.auth()->user()->name.', inscrite au journal.'
            : '.'));
};

/*
|--------------------------------------------------------------------------
| Facture et tiers — superviseur et gérant seulement
|--------------------------------------------------------------------------
*/
$creerFacture = function () {
    // Le contrôle est ici, pas seulement autour du formulaire : la méthode reste
    // appelable depuis le navigateur même quand la carte affiche un cadenas.
    if (! AccesRecouvrement::peutCreerUneFacture(auth()->user())) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: "La création d'une facture relève du superviseur ou du gérant.");

        return;
    }

    /*
     * Les trois tiers d'une facture sont choisis dans le référentiel, jamais frappés au
     * clavier. Ce sont des clés de regroupement : la balance âgée, l'extrait de compte et
     * la page Courtiers rassemblent les factures sur ces chaînes-là. Une valeur libre
     * envoyée à la main créerait un débiteur fantôme, invisible de la liste et donc jamais
     * relancé — la créance disparaîtrait sans qu'aucun écran ne signale rien.
     *
     * La chaîne vide fait partie des valeurs admises pour les deux champs facultatifs :
     * c'est le « — Aucun — » de la liste, et Livewire l'envoie telle quelle sans passer par
     * la conversion en null que fait le noyau HTTP.
     */
    $tiersConnus = array_keys($this->tiers);

    $donnees = $this->validate([
        'facTiers' => ['required', Rule::in($tiersConnus)],
        'facAssureur' => [Rule::in(['', ...$tiersConnus])],
        'facCourtier' => [Rule::in(['', ...$tiersConnus])],
        'facDeposeChez' => [Rule::in(['', ...$tiersConnus])],
        'facSiteId' => ['required', Rule::in(array_keys($this->sites))],
        'facDate' => ['required', 'date'],
        // Le n° de facture devient facultatif : laissé vide, l'application le numérote
        // elle-même. Exigé, il obligeait à inventer un numéro pour une créance qu'aucune
        // facture d'atelier ne porte — et un numéro inventé à la main se répète.
        'facNumero' => ['nullable', 'string', 'max:60'],
        'facVehicule' => ['nullable', 'string', 'max:120'],
        'facImmatriculation' => ['nullable', 'string', 'max:30'],
        'facMontant' => ['required', 'numeric', 'min:1'],
        'facActivite' => ['required', Rule::in(array_keys($this->activites))],
        'facObservations' => ['nullable', 'string', 'max:2000'],
    ], [], [
        'facTiers' => 'client', 'facSiteId' => 'site', 'facDate' => 'date facture',
        'facNumero' => 'n° facture', 'facMontant' => 'montant TTC',
        'facAssureur' => 'assurance représentée', 'facCourtier' => 'courtier',
        'facDeposeChez' => 'dépositaire',
    ]);

    /*
     * **Le numéro de facture se génère quand on ne le donne pas.** Demandé par le
     * propriétaire le 24/09. Une créance saisie ici n'a pas toujours de facture d'atelier
     * derrière elle : exiger un numéro obligeait à en inventer un, et un numéro inventé à
     * la main finit par se répéter.
     *
     * La règle n'est plus recopiée ici : elle vit dans `Facture::numeroDeDocument()`, et
     * les trois écrans qui créent une facture l'appellent. Trois copies d'une même règle
     * finissent par diverger — c'est ce qui était arrivé.
     */
    $numeroFacture = Facture::numeroDeDocument(
        $donnees['facNumero'] ?? null,
        auth()->user()->entreprise_id,
        $donnees['facDate'],
    );

    // Un doublon de numéro pour le même client rend l'extrait de compte incontestable —
    // dans le mauvais sens : le client conteste, et on ne sait plus laquelle est la bonne.
    $existe = Facture::where('client', $donnees['facTiers'])
        ->where('n_facture', $numeroFacture)->exists();

    if ($existe) {
        $this->addError('facNumero', 'Le n° '.$numeroFacture.' existe déjà pour '.$donnees['facTiers'].'.');

        return;
    }

    // Un courtier qui se représenterait lui-même, ou une compagnie qui serait son propre
    // courtier, ferait apparaître la même créance deux fois dans la ventilation du
    // tableau ② — une fois comme dette du courtier, une fois comme part de compagnie.
    if ($donnees['facCourtier'] !== '' && $donnees['facCourtier'] === $donnees['facAssureur']) {
        $this->addError('facCourtier', "Le courtier et l'assurance représentée ne peuvent pas être le même tiers.");

        return;
    }

    Facture::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'site_id' => (int) $donnees['facSiteId'],
        'date' => $donnees['facDate'],
        // `numero` — le numéro de **pièce**, celui que porte toute saisie de l'application —
        // n'est pas écrit ici : le trait `EstUneSaisieTracee` le pose à la création, avec la
        // date de l'opération et la série de la facture. Le recopier consommait un numéro de
        // plus pour rien, et ajoutait un quatrième endroit où la règle pouvait diverger.
        'n_facture' => $numeroFacture,
        'client' => $donnees['facTiers'],
        'assureur' => $donnees['facAssureur'] ?: null,
        'courtier' => $donnees['facCourtier'] ?: null,
        'depose_chez' => $donnees['facDeposeChez'] ?: null,
        'vehicule' => $donnees['facVehicule'] ?: null,
        'immatriculation' => $donnees['facImmatriculation'] ?: null,
        'activite' => $donnees['facActivite'],
        'montant' => (int) $donnees['facMontant'],
        // Ce qu'on veut pouvoir relire six mois plus tard : pourquoi cette créance existe.
        'observations' => trim((string) ($donnees['facObservations'] ?? '')) ?: null,
        'cree_par' => auth()->id(),
    ]);

    activity()->causedBy(auth()->user())
        ->withProperties([
            'client' => $donnees['facTiers'],
            'assureur' => $donnees['facAssureur'] ?: null,
            'courtier' => $donnees['facCourtier'] ?: null,
            'depose_chez' => $donnees['facDeposeChez'] ?: null,
            'numero' => $numeroFacture,
            'montant' => (int) $donnees['facMontant'],
        ])
        ->log('Recouvrement — facture créée');

    // Les tiers aussi : ils étaient conservés, et une seconde facture créée dans la
    // foulée héritait en silence du courtier de la précédente.
    $this->fill(['facTiers' => '', 'facAssureur' => '', 'facCourtier' => '', 'facDeposeChez' => '',
        'facNumero' => '', 'facVehicule' => '', 'facImmatriculation' => '', 'facMontant' => '',
        'facObservations' => '']);
    unset($this->ouvertes, $this->kpis, $this->tiers, $this->tiersOuverts);

    $this->dispatch('annonce', ton: 'succes', texte: 'Facture n° '.$numeroFacture
        .' créée — elle entre dans la balance âgée, l’extrait de compte et le chiffre d’affaires.');
};

$creerTiers = function () {
    if (! AccesRecouvrement::peutCreerUnTiers(auth()->user())) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: "La création d'un tiers relève du superviseur ou du gérant.");

        return;
    }

    $this->validate(['nouveauTiers' => ['required', 'string', 'max:160']], [], ['nouveauTiers' => 'nom du tiers']);

    $nom = mb_strtoupper(trim($this->nouveauTiers));

    // Comparaison insensible à la casse : « Nsia » et « NSIA » sont le même assureur, et
    // deux orthographes du même tiers coupent son encours en deux dans toutes les vues.
    $deja = collect($this->tiers)->first(fn (string $t) => mb_strtoupper($t) === $nom);

    if ($deja !== null) {
        $this->addError('nouveauTiers', "« $deja » existe déjà : ne pas créer de doublon.");

        return;
    }

    Referentiel::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'type' => Referentiel::TIERS_RECOUVREMENT,
        'valeur' => $nom,
        'est_actif' => true,
    ]);

    activity()->causedBy(auth()->user())->withProperties(['tiers' => $nom])->log('Recouvrement — tiers créé');

    $this->nouveauTiers = '';
    unset($this->tiers);

    $this->dispatch('annonce', ton: 'succes', texte: "Tiers « $nom » créé : il est proposé dans toutes les listes.");
};

?>

<x-recouvrement::coquille page="saisie">
    <x-slot:actions>
        {{-- La période commande les quatre chiffres du bandeau, et rien d'autre. La date de
             l'écriture, elle, est descendue dans chaque formulaire : c'est là qu'on la lit
             au moment de valider, et c'est le seul endroit où elle veut dire quelque chose. --}}
        <x-recouvrement::periode route="recouvrement.saisie" :periode="$this->periode" />
    </x-slot:actions>

    <div class="rec-kpis">
        <div class="rec-kpi rouge">
            <div class="lab">Reste à payer</div>
            <div class="val">{{ number_format($this->kpis['reste'], 0, ',', ' ') }}</div>
            <div class="sub">F CFA · {{ $this->kpis['ouvertes'] }} factures ouvertes</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Encaissé sur le mois</div>
            <div class="val">{{ number_format($this->kpis['encaisse_mois'], 0, ',', ' ') }}</div>
            <div class="sub">F CFA · toutes causes confondues</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Zone contentieuse N5</div>
            <div class="val">{{ number_format($this->kpis['contentieux'], 0, ',', ' ') }}</div>
            <div class="sub">+90 jours · AUPSRVE</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Relances tracées</div>
            <div class="val">{{ $this->nombreRelances }}</div>
            <div class="sub">depuis l'ouverture du module</div>
        </div>
    </div>

    {{-- **Deux colonnes qui se remplissent, et non une grille à quatre cases.**

         Les quatre cartes étaient posées dans une grille de deux colonnes : la hauteur de
         chaque rangée valait celle de sa carte la plus haute. « Tracer une relance » compte
         onze champs, « Enregistrer un encaissement » en compte six — la carte du bas de la
         colonne de gauche commençait donc à la hauteur du bas de la relance, et l'écart
         laissait un vide de plus de deux cents points au milieu de l'écran.

         Ici chaque colonne empile ses cartes pour son propre compte : rien ne s'aligne sur
         la voisine, il n'y a plus de trou. Sous 980 points la page revient à une colonne
         unique et les quatre cartes se suivent. --}}
    <div class="rec-g2" style="align-items:start;">

        <div class="rec-col">

        {{-- ─────────────── Encaissement ─────────────── --}}
        <div class="rec-carte">
            <h2>Enregistrer un encaissement <span class="chip">Tous rôles</span></h2>

            {{-- **Le formulaire refait le 28/09, sur le modèle de l'écran des impayés.**

                 Il ne visait qu'une facture et n'offrait que quatre champs. Le propriétaire
                 l'a relevé : « on ne sait pas chez qui ça s'est déposé, les dates et bien
                 d'autres — on n'a pas tous les champs nécessaires par rapport aux impayés »,
                 et il a donné la marche à suivre : « on choisit le nom, puis la ou les
                 factures qui vont avec, et en même temps les champs viendront se remplir ».

                 D'où deux changements. Les factures du tiers ne sont plus une liste
                 déroulante mais un **tableau à cocher**, qui montre ce que chaque créance
                 porte — assureur, courtier, dépositaire, véhicule, dates, reste. Et le
                 versement peut en viser **plusieurs** : un client remet un chèque pour ce
                 qu'il doit, rarement pour une pièce précise. --}}
            <div class="rec-frm">
                <div class="rec-fld" style="grid-column:span 2;">
                    {{-- Deux mille quatre cent cinquante tiers : une liste déroulante nue est
                         un mur. Le champ de recherche filtre sans quitter le `<select>`. --}}
                    <x-select-cherchable id="enc-tiers" label="Tiers / assurance"
                        model="encTiers" :valeur="$encTiers"
                        :options="$this->tiersOuverts"
                        vide="— Tiers qui doivent encore —" placeholder="Taper le nom du tiers…" />
                </div>
            </div>

            @if ($encTiers === '')
                <div class="rec-hint">
                    Choisir un tiers : ses factures ouvertes s'affichent, avec le reste à payer de
                    chacune. Un règlement peut en viser une ou plusieurs.
                </div>
            @elseif ($this->facturesDuTiersEncaissement->isEmpty())
                {{-- Une liste vide ne dit pas pourquoi elle est vide. On croit que l'écran
                     n'a pas répondu, on rechoisit le tiers, on recommence. --}}
                <div class="rec-hint warn">
                    <strong>Aucune facture ouverte pour « {{ $encTiers }} ».</strong>
                    Soit son compte est soldé, soit ses dossiers sont réglés par un courtier —
                    l'encaissement se saisit alors au nom du courtier.
                    <a href="{{ route('recouvrement.extrait', ['tiers' => $encTiers]) }}" wire:navigate
                       style="color:#C8102E; font-weight:700;">Voir son extrait</a>.
                </div>
            @else
                @php
                    /* La liste que le navigateur répartira à la frappe : identifiant, reste
                       à payer, et l'ordre d'imputation — la plus ancienne d'abord, comme
                       ReglementGlobal. Rendue par le serveur, calculée par le navigateur :
                       c'est ce qui rend la déduction immédiate. */
                    $pourLeNavigateur = $this->facturesDuTiersEncaissement
                        ->sortBy([fn ($a, $b) => ($a->date?->timestamp ?? 0) <=> ($b->date?->timestamp ?? 0),
                                  fn ($a, $b) => $a->id <=> $b->id])
                        ->map(fn ($f) => ['id' => $f->id, 'reste' => (int) Recouvrement::reste($f)])
                        ->values();
                @endphp

                {{-- **La répartition se calcule dans le navigateur, et c'est une correction.**

                     « La déduction du montant se fait lentement et n'est pas immédiate » —
                     relevé le 28/09. Le montant passait par un aller-retour Livewire avant
                     que le reste ne bouge : on tapait, et le chiffre suivait une demi-seconde
                     plus tard, ce qui donne l'impression que l'écran hésite.

                     La règle est donc rejouée ici, à l'identique : la plus ancienne d'abord,
                     jusqu'à épuisement. Ce n'est qu'un **aperçu** — le serveur la refait sous
                     verrou, sur les restes à jour, et c'est lui qui écrit. Mais l'aperçu est
                     instantané, et c'est ce qu'on regarde en tapant. --}}
                <div x-data="{
                        factures: {{ Illuminate\Support\Js::from($pourLeNavigateur) }},
                        get coches() {
                            return this.factures.filter(f => $wire.encFactures[f.id]);
                        },
                        get du() {
                            return this.coches.reduce((t, f) => t + f.reste, 0);
                        },
                        get verse() {
                            return Math.max(0, parseInt($wire.encMontant || 0, 10) || 0);
                        },
                        /** Ce que reçoit une facture : la plus ancienne servie d'abord. */
                        part(id) {
                            let reste = this.verse;
                            for (const f of this.coches) {
                                const part = Math.min(f.reste, reste);
                                if (f.id === id) { return part; }
                                reste -= part;
                            }
                            return 0;
                        },
                        get trop() { return Math.max(0, this.verse - this.du); },
                        fr(n) { return new Intl.NumberFormat('fr-FR').format(n) + ' F'; },
                    }">

                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 8px;">
                        <button type="button" class="rec-btn n" style="padding:5px 11px; font-size:12px;"
                            wire:click="toutCocherEncaissement">Tout cocher</button>
                        <button type="button" class="rec-btn" style="padding:5px 11px; font-size:12px;"
                            wire:click="toutDecocherEncaissement">Tout décocher</button>
                        <span style="font-size:12.5px; color:#6B6E76;">
                            <span x-text="coches.length"></span> facture(s) cochée(s) —
                            dû : <b x-text="fr(du)"></b>
                        </span>
                    </div>

                    <div class="tableau-conteneur" style="max-height:290px; overflow:auto;">
                        <table class="tableau" style="font-size:12.5px;">
                            <thead>
                                <tr>
                                    <th style="width:28px;"></th>
                                    <th>N° facture</th>
                                    <th>Date</th>
                                    <th>Reçue le</th>
                                    <th>Assureur</th>
                                    <th>Courtier</th>
                                    <th>Déposée chez</th>
                                    <th>Véhicule</th>
                                    <th style="text-align:right;">Montant</th>
                                    <th style="text-align:right;">Déjà réglé</th>
                                    <th style="text-align:right;">Reste</th>
                                    <th style="text-align:right;">Imputé</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->facturesDuTiersEncaissement as $facture)
                                    @php $resteFacture = (int) Recouvrement::reste($facture); @endphp
                                    <tr wire:key="enc-fac-{{ $facture->id }}"
                                        style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                        <td>
                                            <input type="checkbox" wire:model.live="encFactures.{{ $facture->id }}"
                                                style="width:15px; height:15px; cursor:pointer;">
                                        </td>
                                        <td style="font-weight:600;">{{ $facture->n_facture ?: $facture->numero }}</td>
                                        <td style="white-space:nowrap;">{{ $facture->date?->format('d/m/Y') ?? '—' }}</td>
                                        <td style="white-space:nowrap; color:#6B6E76;">{{ $facture->date_reception?->format('d/m/Y') ?? '—' }}</td>
                                        <td style="color:#6B6E76;">{{ $facture->assureur ?: '—' }}</td>
                                        <td style="color:#6B6E76;">{{ $facture->courtier ?: '—' }}</td>
                                        {{-- Le champ qui manquait, nommément cité le 28/09. --}}
                                        <td style="color:#6B6E76;">{{ $facture->depose_chez ?: '—' }}</td>
                                        <td style="color:#6B6E76;">
                                            {{ $facture->immatriculation ?: ($facture->vehicule ?: '—') }}
                                        </td>
                                        <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae((int) $facture->montant) }}</td>
                                        <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">
                                            {{ ae((int) $facture->montant - $resteFacture) }}
                                        </td>
                                        <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($resteFacture) }}</td>
                                        {{-- Ce que ce versement lui donnerait, mis à jour à la frappe. --}}
                                        <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; color:#2563EB;"
                                            x-text="$wire.encFactures[{{ $facture->id }}] ? fr(part({{ $facture->id }})) : '—'">—</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="rec-frm" style="margin-top:12px;">
                        <div class="rec-fld">
                            <label>Montant reçu (F CFA)</label>
                            {{-- `.live` sans délai : le serveur suit, mais l'affichage n'attend
                                 pas son retour — c'est Alpine qui recalcule à chaque touche. --}}
                            <input type="number" min="0" wire:model.live="encMontant" value="{{ $encMontant }}"
                                x-on:input="$wire.encMontant = $event.target.value">
                        </div>
                        <div class="rec-fld">
                            <label>Mode d'encaissement</label>
                            <select wire:model="encMode">
                                <option value="" @selected($encMode === '')>— Banque / espèce / mobile money —</option>
                                @foreach ($this->modes as $mode)
                                    <option value="{{ $mode }}" @selected((string) $encMode === (string) $mode)>{{ $mode }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rec-fld">
                            {{-- La date que portera l'écriture. Elle est ici, à côté du montant, et
                                 non dans l'en-tête : c'est au moment de valider qu'on la vérifie. --}}
                            <label for="date-enc">Date de l'écriture</label>
                            <input type="date" id="date-enc" wire:model="dateTravail" value="{{ $dateTravail }}">
                        </div>
                        <div class="rec-fld">
                            <label>Banque</label>
                            <input type="text" wire:model="encBanque" value="{{ $encBanque }}"
                                placeholder="Celle du chèque, s'il y en a un">
                        </div>
                        <div class="rec-fld" style="grid-column:span 2;">
                            <label>Référence (chèque, transaction…)</label>
                            <input type="text" wire:model="encReference" value="{{ $encReference }}">
                        </div>
                    </div>

                    {{-- Le récapitulatif, instantané lui aussi. Il dit les trois cas et un
                         seul à la fois : trop-perçu, solde partiel, ou compte apuré. --}}
                    <div class="rec-hint" x-show="coches.length > 0" x-cloak
                         x-bind:class="trop > 0 ? 'warn' : 'ok'">
                        <template x-if="trop > 0">
                            <span>
                                ⚠ Le versement dépasse de <b x-text="fr(trop)"></b> le total dû sur les
                                factures cochées. Un trop-perçu est une décision — avoir, ou avance sur
                                une facture à venir — et ne s'impute pas ici : cochez une facture de plus,
                                ou corrigez le montant.
                            </span>
                        </template>
                        <template x-if="trop === 0 && verse > 0">
                            <span>
                                ✓ <b x-text="fr(verse)"></b> réparti sur <b x-text="coches.length"></b>
                                facture(s) — la plus ancienne d'abord.
                                <template x-if="du - verse > 0">
                                    <span>Restera dû : <b x-text="fr(du - verse)"></b>.</span>
                                </template>
                                <template x-if="du - verse === 0">
                                    <span>Ces factures seront soldées.</span>
                                </template>
                                <template x-if="coches.length > 1">
                                    <span style="display:block; margin-top:5px; color:#6B6E76;">
                                        Ces écritures porteront une <b>référence de versement commune</b> :
                                        l'état des impayés l'affichera en colonne « Règlement global ».
                                    </span>
                                </template>
                            </span>
                        </template>
                        <template x-if="verse === 0">
                            <span>Saisir le montant reçu : il se répartira sur les factures cochées.</span>
                        </template>
                    </div>
                </div>
            @endif

            @error('encMontant')
                <div class="rec-hint warn">⚠ {{ $message }}</div>
            @enderror

            <x-erreurs-du-bloc prefixe="enc" />

            <div class="rec-actions">
                <button type="button" class="rec-btn r" wire:click="enregistrerEncaissement">
                    Enregistrer l'encaissement
                </button>
            </div>
        </div>

        {{-- ─────────────── Facture ───────────────

             **Masquée le 25/09, à la demande du propriétaire, et non supprimée.**

             « Retire la section « Créer une facture », car en vrai pour l'instant elle ne
             sert pas : le chiffre d'affaires suit toute une procédure avant d'être chiffre
             d'affaires, et on l'importe en plus. »

             Le raisonnement tient : une facture naît dans le logiciel d'atelier, au bout
             d'une procédure — devis, validation, facturation — et entre ici par l'import.
             En créer une à la main ouvrait une seconde porte sur la même table, avec sa
             propre numérotation, sans que rien ne la relie à la procédure. C'est d'ailleurs
             par là que le numéro manquant était passé.

             **Pourquoi masquer plutôt qu'effacer.** L'action `creerFacture` reste écrite,
             testée et gardée par l'habilitation : le besoin peut revenir — une reprise de
             garantie, une facture que le logiciel n'a pas produite — et le jour où il
             reviendra, il vaut mieux retrouver un code éprouvé qu'en réécrire un. Ce qui
             disparaît, c'est la porte, pas la serrure. Passer cette constante à `true` la
             rouvre, et rien d'autre n'est à toucher. --}}
        @php
            /* Le jour où une facture devra se créer à la main, ici et nulle part ailleurs. */
            $laFactureSeCreeAlaMain = false;
        @endphp

        @if ($laFactureSeCreeAlaMain)
        <div class="rec-carte">
            <h2>Créer une facture <span class="chip">Superviseur · Gérant</span></h2>

            @if ($this->peutRediger)
                {{-- Un seul annuaire pour les trois champs.

                     Ici, contrairement aux deux formulaires du haut, la liste complète est
                     nécessaire : on facture aussi bien un client qui ne doit rien. Mais la
                     répéter dans trois `select` coûtait 928 Ko à elle seule — près des
                     deux tiers de la page — renvoyés au navigateur à chaque aller-retour.

                     Un `datalist` s'écrit une fois et se partage par `list=` entre autant
                     de champs qu'on veut : 62 Ko au lieu de 928, et l'on tape le nom au
                     lieu de faire défiler. La garantie, elle, ne bouge pas : `creerFacture`
                     vérifie déjà chaque valeur contre le référentiel (`Rule::in`), et c'est
                     là qu'elle doit être vérifiée — une liste déroulante n'a jamais protégé
                     que contre les fautes de frappe, pas contre une requête forgée. --}}
                <datalist id="rec-tiers-connus">
                    @foreach ($this->tiers as $nom)
                        <option value="{{ $nom }}"></option>
                    @endforeach
                </datalist>

                <div class="rec-frm">
                    <div class="rec-fld">
                        <x-select-cherchable id="fac-tiers" label="Client / assuré"
                            model="facTiers" :valeur="$facTiers" source="rec-tiers-connus"
                            vide="— Choisir le client —" placeholder="Taper le nom du client…" />
                    </div>
                    <div class="rec-fld">
                        <x-select-cherchable id="fac-assureur" label="Assurance représentée"
                            model="facAssureur" :valeur="$facAssureur" source="rec-tiers-connus"
                            vide="— Aucune —" placeholder="Taper le nom de l'assurance…" />
                    </div>
                    <div class="rec-fld">
                        <x-select-cherchable id="fac-courtier" label="Courtier (devient le payeur)"
                            model="facCourtier" :valeur="$facCourtier" source="rec-tiers-connus"
                            vide="— Aucun —" placeholder="Taper le nom du courtier…" />
                    </div>
                    <div class="rec-fld">
                        <x-select-cherchable id="fac-depose" label="Déposée chez (passe devant)"
                            model="facDeposeChez" :valeur="$facDeposeChez" source="rec-tiers-connus"
                            vide="— Personne —" placeholder="Taper le nom du dépositaire…" />
                    </div>
                    <div class="rec-fld">
                        <label>Site</label>
                        <select wire:model="facSiteId">
                            <option value="" @selected($facSiteId === '')>— Site —</option>
                            @foreach ($this->sites as $id => $nom)
                                <option value="{{ $id }}" @selected((string) $facSiteId === (string) $id)>{{ $nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rec-fld">
                        <label>Date facture</label>
                        <input type="date" wire:model="facDate" value="{{ $facDate }}">
                    </div>
                    <div class="rec-fld">
                        <label>N° facture <span style="font-weight:400; color:#6B6E76;">(facultatif)</span></label>
                        {{-- La consigne est dans la case plutôt que sous elle : posée
                             dessous, elle se lit après avoir tapé, c'est-à-dire trop tard. --}}
                        <input type="text" wire:model="facNumero" value="{{ $facNumero }}"
                               placeholder="numéroté automatiquement si vide">
                    </div>
                    <div class="rec-fld">
                        <label>Véhicule</label>
                        <input type="text" wire:model="facVehicule" value="{{ $facVehicule }}">
                    </div>
                    <div class="rec-fld">
                        <label>Immatriculation</label>
                        <input type="text" wire:model="facImmatriculation" value="{{ $facImmatriculation }}">
                    </div>
                    <div class="rec-fld">
                        <label>Activité</label>
                        <select wire:model="facActivite">
                            @foreach ($this->activites as $activite)
                                <option value="{{ $activite }}" @selected((string) $facActivite === (string) $activite)>{{ $activite }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rec-fld">
                        <label>Montant TTC (F)</label>
                        <input type="number" min="0" wire:model="facMontant" value="{{ $facMontant }}">
                    </div>
                    {{-- Ce qu'on veut pouvoir relire six mois plus tard : pourquoi cette
                         créance existe. Sur toute la largeur — une explication tient
                         rarement sur la largeur d'un champ de date. --}}
                    <div class="rec-fld" style="grid-column:1 / -1;">
                        <label>Description <span style="font-weight:400; color:#6B6E76;">(facultatif)</span></label>
                        <input type="text" wire:model="facObservations" value="{{ $facObservations }}"
                               placeholder="Ex. : reprise de garantie, facture non retrouvée au logiciel…">
                    </div>
                </div>

                @error('facNumero')
                    <div class="rec-hint warn">⚠ {{ $message }}</div>
                @elseif ($errors->has('facCourtier'))
                    <div class="rec-hint warn">⚠ {{ $errors->first('facCourtier') }}</div>
                @else
                    <div class="rec-hint {{ $facCourtier !== '' || $facDeposeChez !== '' ? 'ok' : '' }}">
                        @if ($facDeposeChez !== '')
                            ✓ Facture déposée chez <b>{{ $facDeposeChez }}</b> : c'est lui qui la règle,
                            et c'est chez lui seul qu'elle est comptée. Le client facturé reste inscrit.
                        @elseif ($facCourtier !== '')
                            ✓ Facture suivie, relancée et encaissée au nom de
                            <b>{{ $facCourtier }}</b> — l'assurance représentée reste inscrite pour la
                            ventilation de la page Courtiers.
                        @else
                            Règle du courtage : dès qu'un courtier est renseigné, c'est lui le tiers
                            payant. Sans courtier, la créance est portée par l'assurance représentée si
                            elle est renseignée, sinon par le client.
                        @endif
                    </div>
                @enderror

                <x-erreurs-du-bloc prefixe="fac" />

                <div class="rec-actions">
                    <button type="button" class="rec-btn n" wire:click="creerFacture">Créer la facture</button>
                </div>
            @else
                <div class="rec-lock">
                    🔒 La création de facture est réservée au superviseur et au gérant — séparation des
                    fonctions : celui qui relance et encaisse ne crée pas la créance.
                </div>
            @endif
        </div>
        @endif

        </div>

        <div class="rec-col">

        {{-- ─────────────── Relance ─────────────── --}}
        <div class="rec-carte">
            <h2>Tracer une relance <span class="chip">N1–N5</span></h2>

            <div class="rec-frm">
                {{-- La relance a sa propre date. Elle empruntait celle du formulaire
                     d'encaissement, situé à l'autre bout de l'écran : reculer cette
                     date-là pour saisir un règlement ancien redatait discrètement la
                     prochaine relance. --}}
                <div class="rec-fld">
                    <label for="rel-date">Date de la relance</label>
                    <input type="date" id="rel-date" wire:model="relDate" value="{{ $relDate }}">
                </div>
                <div class="rec-fld">
                    <x-select-cherchable id="rel-tiers" label="Tiers / assurance"
                        model="relTiers" :valeur="$relTiers"
                        :options="$this->tiersOuverts"
                        vide="— Tiers qui doivent encore —" placeholder="Taper le nom du tiers…" />
                </div>
                {{-- Pourquoi ce champ n'affichait que « Situation globale ».

                     Il ne peut rien afficher d'autre tant que le tiers n'est pas choisi :
                     une facture appartient à un tiers, et sans tiers il n'y a pas de
                     facture à proposer. Le champ était donc correct — mais muet, et un
                     champ muet se lit comme un champ en panne. Il dit désormais ce qu'il
                     attend, et combien de factures il a trouvées une fois qu'il l'a.

                     « Situation globale » reste un choix à part entière : on relance
                     souvent sur l'ensemble du compte plutôt que pièce par pièce. --}}
                <div class="rec-fld">
                    <label for="rel-facture">Facture(s) visée(s)</label>
                    <select id="rel-facture" wire:model="relFacture" @disabled($relTiers === '')>
                        @if ($relTiers === '')
                            <option value="">— Choisissez d'abord le tiers —</option>
                        @else
                            <option value="" @selected($relFacture === '')>
                                Situation globale ({{ $this->facturesDuTiersRelance->count() }} facture(s) ouverte(s))
                            </option>
                            @foreach ($this->facturesDuTiersRelance as $facture)
                                <option value="N° {{ $facture->n_facture }} · reste {{ Recouvrement::fr(Recouvrement::reste($facture)) }}"
                                    @selected($relFacture === 'N° '.$facture->n_facture.' · reste '.Recouvrement::fr(Recouvrement::reste($facture)))>
                                    N° {{ $facture->n_facture }} · reste {{ Recouvrement::fr(Recouvrement::reste($facture)) }}
                                </option>
                            @endforeach
                        @endif
                    </select>

                    @if ($relTiers === '')
                        <div class="rec-hint" style="margin-top:6px;">
                            Une facture appartient à un tiers&nbsp;: la liste se remplit dès que
                            vous en choisissez un. Vous pourrez alors viser une pièce précise, ou
                            rester sur la <strong>situation globale</strong> du compte.
                        </div>
                    @elseif ($this->facturesDuTiersRelance->isEmpty())
                        <div class="rec-hint warn" style="margin-top:6px;">
                            <strong>Aucune facture ouverte pour « {{ $relTiers }} ».</strong>
                            Une relance reste possible sur la situation globale, mais vérifiez d'abord
                            qu'il reste bien quelque chose à réclamer.
                        </div>
                    @else
                        <div class="rec-hint ok" style="margin-top:6px;">
                            {{ $this->facturesDuTiersRelance->count() }} facture(s) ouverte(s) pour
                            « {{ $relTiers }} », soit {{ Recouvrement::fr($this->soldeDuTiersRelance) }}
                            à réclamer.
                        </div>
                    @endif
                </div>
                {{-- Les cinq niveaux sont ouverts à tous les rôles du module.

                     Ils ont d'abord été fermés au-dessus de l'habilitation, puis escaladés
                     « en attente de validation ». La direction a tranché autrement : dans
                     une équipe de deux personnes, faire repasser chaque mise en demeure par
                     un second compte n'ajoute pas de contrôle, cela ajoute un délai — et sur
                     une créance de plus de quatre-vingt-dix jours, le délai coûte plus cher
                     que le risque qu'il écarte.

                     Ce qui remplace le verrou : chaque relance reste datée, nominative et
                     inscrite au journal d'audit, que le superviseur et le gérant lisent. La
                     question « qui a envoyé cette mise en demeure » garde donc sa réponse ;
                     elle se lit après coup au lieu de se poser avant. --}}
                <div class="rec-fld">
                    <label for="rel-niveau">Niveau</label>
                    <select id="rel-niveau" wire:model.live="relNiveau">
                        @foreach (RelanceRecouvrement::NIVEAUX as $niveau => $libelle)
                            <option value="{{ $niveau }}" @selected((int) $relNiveau === (int) $niveau)>
                                {{ $libelle }}
                            </option>
                        @endforeach
                    </select>

                    {{-- Les deux derniers niveaux engagent l'entreprise : la mise en demeure
                         ouvre les délais de l'AUPSRVE, le contentieux sort du commercial. On
                         ne l'interdit plus, mais on le dit — un geste lourd doit s'annoncer
                         comme tel au moment où on le pose. --}}
                    @if ((int) $relNiveau >= 4)
                        <div class="rec-hint warn" style="margin-top:6px;">
                            <strong>{{ (int) $relNiveau === 5 ? 'Contentieux' : 'Mise en demeure' }} —
                            cette relance engage l'entreprise.</strong>
                            Elle part au nom de {{ auth()->user()->name }}, à la date choisie, et
                            figure au journal d'audit.
                        </div>
                    @endif
                </div>
                                <div class="rec-fld">
                    <label>Canal</label>
                    <select wire:model="relCanal">
                        <option value="" @selected($relCanal === '')>— Canal —</option>
                        @foreach (RelanceRecouvrement::CANAUX as $canal)
                            <option value="{{ $canal }}" @selected((string) $relCanal === (string) $canal)>{{ $canal }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rec-fld">
                    <label>Interlocuteur</label>
                    <input type="text" wire:model="relInterlocuteur" value="{{ $relInterlocuteur }}">
                </div>
                <div class="rec-fld">
                    <label>Résultat / engagement</label>
                    <input type="text" wire:model="relResultat" value="{{ $relResultat }}">
                </div>
                <div class="rec-fld">
                    <label>Montant promis (F)</label>
                    <input type="number" min="0" wire:model="relPromis" value="{{ $relPromis }}">
                </div>
                <div class="rec-fld">
                    <label>Statut du dossier</label>
                    <select wire:model="relStatut">
                        @foreach (RelanceRecouvrement::STATUTS as $statut)
                            <option value="{{ $statut }}" @selected((string) $relStatut === (string) $statut)>{{ $statut }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @error('relNiveau')
                <div class="rec-hint warn">⚠ {{ $message }}</div>
            @else
                @if ($this->relTiers === '')
                    <div class="rec-hint">
                        Le niveau proposé découle de l'ancienneté la plus élevée des factures ouvertes du tiers.
                    </div>
                @elseif ($this->niveauRequis >= 4)
                    <div class="rec-hint warn">
                        ⚠ Ce dossier appelle un niveau N{{ $this->niveauRequis }} —
                        {{ $this->niveauRequis >= 5 ? 'contentieux' : 'mise en demeure' }}.
                        Solde du tiers&nbsp;: {{ Recouvrement::fr($this->soldeDuTiersRelance) }}.
                    </div>
                @else
                    <div class="rec-hint ok">
                        ✓ Solde du tiers : {{ Recouvrement::fr($this->soldeDuTiersRelance) }} ·
                        niveau suggéré : N{{ max(1, $this->niveauRequis) }}.
                    </div>
                @endif
            @enderror

            <x-erreurs-du-bloc prefixe="rel" />

            <div class="rec-actions">
                <button type="button" class="rec-btn r" wire:click="enregistrerRelance">
                    Enregistrer la relance
                </button>
            </div>
        </div>

        {{-- ─────────────── Tiers ─────────────── --}}
        <div class="rec-carte">
            <h2>Créer un client / tiers <span class="chip">Superviseur · Gérant</span></h2>

            @if ($this->peutRediger)
                <div class="rec-frm">
                    <div class="rec-fld" style="grid-column:span 2;">
                        <label>Nom du tiers (majuscules)</label>
                        <input type="text" wire:model="nouveauTiers" value="{{ $nouveauTiers }}" style="text-transform:uppercase;">
                    </div>
                </div>

                @error('nouveauTiers')
                    <div class="rec-hint warn">⚠ {{ $message }}</div>
                @else
                    <div class="rec-hint">
                        Contrôle anti-doublon automatique, insensible à la casse. Le tiers créé est
                        immédiatement proposé dans toutes les listes déroulantes.
                    </div>
                @enderror

                <div class="rec-actions">
                    <button type="button" class="rec-btn n" wire:click="creerTiers">Créer le tiers</button>
                </div>
            @else
                <div class="rec-lock">
                    🔒 La création d'un tiers est réservée au superviseur et au gérant, pour garder un
                    référentiel client unique — deux orthographes du même assureur coupent son encours en deux.
                </div>
            @endif
        </div>

        </div>
    </div>
</x-recouvrement::coquille>

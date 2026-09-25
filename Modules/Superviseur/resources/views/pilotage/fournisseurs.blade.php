<?php

use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\ConditionsFournisseur;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;

use function Livewire\Volt\{computed, mount, state};

/**
 * Ce que l'entreprise doit à ses fournisseurs, et pour quand.
 *
 * **Pourquoi cet écran existe.** Le fichier « Suivi fournisseurs » était lu, ses mille huit
 * cent quarante-huit factures étaient en base — et aucun écran ne les montrait. Toute
 * l'application regardait ce qu'on nous doit ; personne ne regardait ce que nous devons.
 * Un compte d'exploitation qui ne voit qu'un côté de la balance n'est pas un compte
 * d'exploitation.
 *
 * **L'échéance existe désormais, et pour une partie des lignes seulement.** On avait écrit
 * ici que le fichier n'en portait aucune — c'était vrai de l'onglet qu'on lisait alors, et
 * faux du classeur. Depuis le 22/09, la feuille « DETAIL » est lue en entier : sur 7 350
 * lignes reprises, **1 480 portent une date d'échéance**. L'écran l'affiche donc, en disant
 * combien de lignes la connaissent — une pièce sans échéance n'est pas une pièce à jour.
 *
 * L'ancienneté depuis la date de facture reste affichée à côté, et c'est elle qui trie :
 * c'est la seule date que toutes les lignes portent. Remplacer l'une par l'autre aurait
 * fait disparaître du haut de la liste les dettes les plus vieilles, faute d'échéance.
 *
 * **Le périmètre se lit par ville.** Seule une ligne sur six porte un atelier ; les autres
 * n'ont que la ville. Filtrer par atelier aurait vidé l'écran de cinq lignes sur six, ce
 * qui aurait ressemblé à une panne alors que c'est le fichier qui ne le dit pas.
 *
 * **L'état se tient par année depuis le 24/09, comme celui des impayés.** L'écran listait
 * toutes les pièces depuis 2023 dans une seule suite : on ne peut pas arrêter un exercice
 * sur une liste sans fin. L'année choisie montre ce qui y a été facturé **plus ce qui
 * traîne depuis avant** — le report se fait tout seul, et se défait tout seul le jour où la
 * pièce est réglée. Rien n'est recopié ni déplacé : une pièce garde l'année de sa facture,
 * pour toujours. Voir `EtatDesFournisseurs`.
 *
 * Aucune période au jour n'est proposée, et c'est délibéré : une dette ne s'arrête pas un
 * mardi. L'année suffit, et le report fait le reste.
 *
 * **L'écran n'est plus seulement de lecture.** Une facture reçue entre deux dépôts n'avait
 * nulle part où aller : il fallait attendre qu'elle paraisse dans le classeur du mois
 * suivant. Elle se saisit maintenant ici. Tout le monde n'a pas à engager l'entreprise
 * auprès d'un fournisseur : le responsable d'atelier continue de lire, il n'écrit pas.
 */
state(['exercice' => null])->url(except: '');

state([
    'villeFiltre' => '',
    'etatFiltre' => 'ouvertes',
    'recherche' => '',
    'pageDetail' => 1,
    'formulaireOuvert' => false,
]);

/* Les champs de la saisie à la main. Vidés par affectation, jamais par `reset()`, qui
   remettrait à null au lieu de la chaîne vide. */
state([
    'fournisseur' => '',
    'numeroPiece' => '',
    'naturePiece' => '',
    'dateFacture' => '',
    'dateEcheance' => '',
    'montant' => '',
    'montantRegle' => '',
    'modeReglement' => '',
    'imputation' => '',
    'immatriculation' => '',
    'observations' => '',
    'villeSaisie' => '',
    'siteSaisie' => '',
]);

/*
 * Le reste des colonnes du classeur — celles que la saisie à la main ne proposait pas.
 *
 * **Ce qui manquait, relevé par le propriétaire le 24/09.** La table porte quarante
 * colonnes depuis la migration du 22/09 ; le formulaire en offrait douze. Une pièce saisie
 * ici était donc plus pauvre que la même pièce venue du classeur, et la page de détail
 * affichait des cases vides qu'aucun écran ne permettait de remplir.
 *
 * **La liste vient des deux classeurs**, lus feuille « DETAIL » en main : celui d'Abidjan
 * (FSF L2A) et celui de San-Pédro. On garde ce qu'ils ont en commun et l'on complète par
 * le surplus de chacun — le n° de FEB et le montant HT ne sont qu'à San-Pédro, les
 * quantités, la marge et les deux numéros de facture ne sont qu'à Abidjan. Un seul
 * formulaire pour les deux, comme demandé.
 *
 * **Rien de tout cela n'est obligatoire.** Ce sont des précisions : une pièce reçue entre
 * deux dépôts se saisit en trente secondes avec le fournisseur, la date et le montant, et
 * le reste se complète si on l'a sous les yeux.
 */
state([
    'mois' => '',
    'section' => '',
    'numeroBc' => '',
    'dateReception' => '',
    'dateReglement' => '',
    'delaiReglement' => '',
    'typeTransaction' => '',
    'numeroCheque' => '',
    'numeroFeb' => '',
    'numeroFiche' => '',
    'codePiece' => '',
    'vehicule' => '',
    'montantHt' => '',
    'tva' => '',
    'tva2' => '',
    'montantRefacture' => '',
    'montantNetAchat' => '',
    'montantNetVente' => '',
    'quantiteTotale' => '',
    'quantiteRefacturee' => '',
    'numeroFactureAchat' => '',
    'numeroFactureVente' => '',
    'numeroFactureClient' => '',
    'resultatIndicatif' => '',
    'observationsFacturation' => '',
    'commentaires' => '',
    'actionsAMener' => '',
]);

/* Les précisions se déplient : posées à plat, elles faisaient un formulaire de six rangées
   devant un tableau qu'on venait consulter. */
state(['precisionsOuvertes' => false]);

mount(function () {
    $this->exercice ??= EtatDesFournisseurs::exerciceOuvert(auth()->user()->entreprise_id);
    $this->dateFacture = $this->dateFacture ?: now()->format('Y-m-d');
    $this->villeSaisie = $this->villeSaisie ?: (string) (auth()->user()->ville_id ?? '');
});

$updatedExercice = function () { $this->pageDetail = 1; };
$updatedVilleFiltre = function () { $this->pageDetail = 1; };
$updatedEtatFiltre = function () { $this->pageDetail = 1; };
$updatedRecherche = function () { $this->pageDetail = 1; };

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

$annee = computed(fn () => (int) ($this->exercice ?: now()->year));

$exercices = computed(function () {
    $annees = EtatDesFournisseurs::exercices(auth()->user()->entreprise_id);

    return array_combine($annees, $annees);
});

$peutEcrire = computed(fn () => EtatDesFournisseurs::peutEcrire(auth()->user()));

$villesOuSaisir = computed(fn () => PerimetreSites::optionsVilles(auth()->user())
    ?->pluck('nom', 'id')->all() ?? []);

/**
 * Les ateliers de la ville choisie — et seulement quand il y a un choix à faire.
 *
 * **Demandé le 24/09** : « quand la ville qui a au moins deux sites est sélectionnée, on
 * doit lui demander le site précis ». C'est le cas d'Abidjan, et d'elle seule : Bouaké et
 * San-Pédro n'ont qu'un atelier, et leur poser la question serait offrir un choix qui n'en
 * est pas un. Le champ apparaît donc, ou n'apparaît pas.
 *
 * @return array<int, string>
 */
$sitesOuSaisir = computed(function () {
    if ($this->villeSaisie === '') {
        return [];
    }

    $sites = Site::where('ville_id', (int) $this->villeSaisie)
        ->where('est_actif', true)
        ->orderBy('nom')
        ->pluck('nom', 'id')
        ->all();

    return count($sites) > 1 ? $sites : [];
});

/* Changer de ville rend caduc l'atelier choisi dans la précédente. */
$updatedVilleSaisie = function () {
    $this->siteSaisie = '';
    unset($this->sitesOuSaisir);
};

/**
 * Le périmètre : l'année regardée, ses reports, et les villes du lecteur.
 *
 * Sans le filtre d'état ni la recherche : les totaux du bandeau ne doivent pas dépendre de
 * ce qu'on cherche, sans quoi « reste à payer » voudrait dire « reste à payer parmi ce que
 * vous avez tapé ».
 */
$perimetre = computed(fn () => EtatDesFournisseurs::dansLePerimetre(
    EtatDesFournisseurs::requete($this->annee),
    $this->idsVilles,
));

$requete = computed(function () {
    $requete = (clone $this->perimetre)
        ->when(trim($this->recherche) !== '', function ($q) {
            $terme = '%'.trim($this->recherche).'%';

            $q->where(fn ($sous) => $sous->where('fournisseur', 'like', $terme)
                ->orWhere('numero_piece', 'like', $terme)
                ->orWhere('immatriculation', 'like', $terme)
                ->orWhere('imputation', 'like', $terme));
        });

    return match ($this->etatFiltre) {
        'ouvertes' => $requete->where('reste_a_payer', '>', 0),
        'anciennes' => $requete->where('reste_a_payer', '>', 0)
            ->whereDate('date_facture', '<', now()->subDays(90)),
        // Échue veut dire : l'échéance est connue, et elle est passée. Une ligne sans
        // échéance n'y figure pas — on ne la déclare ni à jour ni en retard.
        'echues' => $requete->where('reste_a_payer', '>', 0)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now()),
        'soldees' => $requete->where('reste_a_payer', '<=', 0),
        default => $requete,
    };
});

$kpis = computed(fn () => EtatDesFournisseurs::totaux($this->perimetre, $this->annee) + [
    // Le facturé, le réglé, le reste, les avoirs, les reports : sept nombres, une seule
    // lecture. La dette ne compte que les restes positifs — un trop-payé ne rembourse pas
    // la dette d'un autre fournisseur. Voir EtatDesFournisseurs::totaux().
    // L'ancienneté se compte depuis la date de facture : c'est la seule date que toutes
    // les lignes portent, et une dette de plus de trois mois est un signal à elle seule.
    'ancien' => (int) (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereDate('date_facture', '<', now()->subDays(90))
        ->sum('reste_a_payer'),
    'plusAncienne' => (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->min('date_facture'),
    'fournisseurs' => (clone $this->perimetre)->distinct()->count('fournisseur'),

    /*
     * L'échu, et ce qu'on en sait.
     *
     * Les deux nombres vont ensemble : « 12 M échus » ne veut rien dire sans « sur les
     * 1 480 pièces dont l'échéance est connue ». Annoncer le premier seul laisserait croire
     * que le reste est à jour, alors qu'il est simplement sans date.
     */
    'echu' => (int) (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereNotNull('date_echeance')->whereDate('date_echeance', '<', now())
        ->sum('reste_a_payer'),
    'avecEcheance' => (clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereNotNull('date_echeance')->count(),
]);

/**
 * À qui l'on doit le plus : c'est par là qu'une négociation de délai commence.
 *
 * **Le déjà payé y figure, et ce n'est pas un ornement.** Le tableau ne montrait que la
 * dette. Or on n'aborde pas de la même façon un fournisseur à qui l'on doit deux millions
 * sur trois millions engagés et un autre à qui l'on doit les deux millions d'une première
 * commande : le premier est un compte qui tourne, le second un compte qui s'installe. Le
 * chiffre existait dans le détail d'un fournisseur ; il manquait là où l'on décide.
 *
 * Le réglé est additionné sur **toutes** les pièces du fournisseur, y compris soldées,
 * quand la dette ne compte que les pièces ouvertes : c'est ce que « déjà payé » veut dire.
 * D'où la jointure sur une seconde lecture plutôt qu'une colonne de plus dans la première.
 */
$principaux = computed(function () {
    $dus = (clone $this->perimetre)
        ->where('reste_a_payer', '>', 0)
        ->selectRaw('fournisseur, count(*) as pieces, sum(reste_a_payer) as du')
        ->groupBy('fournisseur')->orderByDesc('du')->limit(10)->get();

    if ($dus->isEmpty()) {
        return $dus;
    }

    $regles = (clone $this->perimetre)
        ->whereIn('fournisseur', $dus->pluck('fournisseur')->all())
        ->selectRaw('fournisseur, sum(montant_regle) as regle, sum(montant) as engage')
        ->groupBy('fournisseur')->get()->keyBy('fournisseur');

    return $dus->each(function ($ligne) use ($regles) {
        $ligne->regle = (int) ($regles[$ligne->fournisseur]->regle ?? 0);
        $ligne->engage = (int) ($regles[$ligne->fournisseur]->engage ?? 0);
    });
});

/**
 * Consigne une facture fournisseur reçue entre deux dépôts.
 *
 * **L'autorisation se vérifie ici autant qu'à la route.** Une route protégée ne protège que
 * l'entrée : l'action, elle, est appelable par tout ce qui sait parler à Livewire. Et la
 * ville est relue dans le périmètre du compte, jamais reprise telle qu'elle arrive.
 *
 * **Le numéro de pièce est celui du fournisseur quand on l'a.** Il est sur sa facture, et
 * c'est sous ce numéro qu'il la réclamera. À défaut, on en fabrique un — FRS-2309-0001 —
 * plutôt que de laisser la pièce sans repère : une dette sans référence ne se retrouve pas
 * au téléphone.
 *
 * **Le doublon est refusé à la frappe**, sur la clé que l'import emploie déjà : fournisseur,
 * numéro, date et montant. Sans elle, la même facture saisie deux fois — une fois à la main,
 * une fois par le fichier du mois suivant — compterait la dette en double.
 */
$enregistrer = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $donnees = $this->validate([
        'fournisseur' => ['required', 'string', 'max:200'],
        'numeroPiece' => ['nullable', 'string', 'max:60'],
        'naturePiece' => ['nullable', 'string', 'max:60'],
        'dateFacture' => ['required', 'date'],
        'dateEcheance' => ['nullable', 'date', 'after_or_equal:dateFacture'],
        'montant' => ['required', 'integer', 'min:1'],
        'montantRegle' => ['nullable', 'integer', 'min:0'],
        'modeReglement' => ['nullable', 'string', 'max:200'],
        'imputation' => ['nullable', 'string', 'max:120'],
        'immatriculation' => ['nullable', 'string', 'max:40'],
        'observations' => ['nullable', 'string', 'max:2000'],
        'villeSaisie' => ['nullable', 'integer'],
        'siteSaisie' => ['nullable', 'integer'],

        /* Les précisions du classeur. Aucune n'est exigée : une pièce reçue entre deux
           dépôts se saisit avec le fournisseur, la date et le montant, et le reste se
           complète si on l'a sous les yeux. */
        'mois' => ['nullable', 'integer', 'min:1', 'max:12'],
        'section' => ['nullable', 'string', 'max:60'],
        'numeroBc' => ['nullable', 'string', 'max:60'],
        'dateReception' => ['nullable', 'date'],
        'dateReglement' => ['nullable', 'date'],
        'delaiReglement' => ['nullable', 'string', 'max:40'],
        'typeTransaction' => ['nullable', 'string', 'max:120'],
        'numeroCheque' => ['nullable', 'string', 'max:60'],
        'numeroFeb' => ['nullable', 'string', 'max:60'],
        'numeroFiche' => ['nullable', 'string', 'max:40'],
        'codePiece' => ['nullable', 'string', 'max:60'],
        'vehicule' => ['nullable', 'string', 'max:120'],
        'montantHt' => ['nullable', 'integer'],
        'tva' => ['nullable', 'integer'],
        'tva2' => ['nullable', 'integer'],
        'montantRefacture' => ['nullable', 'integer'],
        'montantNetAchat' => ['nullable', 'integer'],
        'montantNetVente' => ['nullable', 'integer'],
        'quantiteTotale' => ['nullable', 'numeric', 'min:0'],
        'quantiteRefacturee' => ['nullable', 'numeric', 'min:0'],
        'numeroFactureAchat' => ['nullable', 'string', 'max:60'],
        'numeroFactureVente' => ['nullable', 'string', 'max:60'],
        'numeroFactureClient' => ['nullable', 'string', 'max:60'],
        'resultatIndicatif' => ['nullable', 'string', 'max:120'],
        'observationsFacturation' => ['nullable', 'string', 'max:2000'],
        'commentaires' => ['nullable', 'string', 'max:2000'],
        'actionsAMener' => ['nullable', 'string', 'max:2000'],
    ], [
        'dateEcheance.after_or_equal' => "L'échéance ne peut pas précéder la facture.",
    ]);

    $entrepriseId = (int) auth()->user()->entreprise_id;
    $montant = (int) $donnees['montant'];
    $regle = (int) ($donnees['montantRegle'] ?: 0);

    if ($regle > $montant) {
        $this->addError('montantRegle', 'Le réglé ne peut pas dépasser le montant de la facture.');

        return;
    }

    // La ville doit être une des siennes : celle qui arrive du formulaire ne prouve rien.
    $villeId = in_array((int) $donnees['villeSaisie'], $this->idsVilles, true)
        ? (int) $donnees['villeSaisie']
        : null;

    /* L'atelier n'est retenu que s'il appartient à cette ville-là. Un identifiant recopié
       à la main rattacherait sinon la pièce à l'atelier d'une autre ville — et de proche
       en proche son montant avec. */
    $siteId = null;

    if ($villeId !== null && ($donnees['siteSaisie'] ?? null)) {
        $siteId = Site::where('id', (int) $donnees['siteSaisie'])
            ->where('ville_id', $villeId)
            ->where('est_actif', true)
            ->value('id');
    }

    $numero = trim((string) $donnees['numeroPiece']) !== ''
        ? trim((string) $donnees['numeroPiece'])
        : GenerateurNumero::suivant($entrepriseId, EtatDesFournisseurs::SERIE, $donnees['dateFacture']);

    $deja = FactureFournisseur::query()
        ->where('fournisseur', $donnees['fournisseur'])
        ->where('numero_piece', $numero)
        ->whereDate('date_facture', $donnees['dateFacture'])
        ->where('montant', $montant)
        ->first();

    if ($deja !== null) {
        $this->addError('numeroPiece', 'Cette pièce est déjà enregistrée — même fournisseur, même numéro, même date, même montant.');

        return;
    }

    $piece = FactureFournisseur::create([
        'entreprise_id' => $entrepriseId,
        'ville_id' => $villeId,
        'user_id' => auth()->id(),
        'fournisseur' => $donnees['fournisseur'],
        'numero_piece' => $numero,
        'nature_piece' => $donnees['naturePiece'] ?: null,
        'date_facture' => $donnees['dateFacture'],
        'date_echeance' => $donnees['dateEcheance'] ?: null,
        'montant' => $montant,
        'montant_regle' => $regle,
        // Le reste se déduit, il ne se saisit pas : une colonne qu'on peut contredire à la
        // main finit toujours par l'être.
        'reste_a_payer' => $montant - $regle,
        'mode_reglement' => $donnees['modeReglement'] ?: null,
        'imputation' => $donnees['imputation'] ?: null,
        'immatriculation' => $donnees['immatriculation'] ?: null,
        'observations' => $donnees['observations'] ?: null,
        'source_rattachement' => 'saisie',
        'site_id' => $siteId,

        /* Les précisions du classeur, telles quelles. Une chaîne vide devient null : une
           case laissée vide dit « on ne sait pas », pas « c'est vide ». */
        'mois' => $donnees['mois'] !== '' ? (int) $donnees['mois'] : null,
        'section' => $donnees['section'] ?: null,
        'numero_bc' => $donnees['numeroBc'] ?: null,
        'date_reception' => $donnees['dateReception'] ?: null,
        'date_reglement' => $donnees['dateReglement'] ?: null,
        'delai_reglement' => $donnees['delaiReglement'] ?: null,
        'type_transaction' => $donnees['typeTransaction'] ?: null,
        'numero_cheque' => $donnees['numeroCheque'] ?: null,
        'numero_feb' => $donnees['numeroFeb'] ?: null,
        'numero_fiche' => $donnees['numeroFiche'] ?: null,
        'code_piece' => $donnees['codePiece'] ?: null,
        'vehicule' => $donnees['vehicule'] ?: null,
        'montant_ht' => $donnees['montantHt'] !== '' ? (int) $donnees['montantHt'] : null,
        'tva' => $donnees['tva'] !== '' ? (int) $donnees['tva'] : null,
        'tva_2' => $donnees['tva2'] !== '' ? (int) $donnees['tva2'] : null,
        'montant_refacture' => $donnees['montantRefacture'] !== '' ? (int) $donnees['montantRefacture'] : null,
        'montant_net_achat' => $donnees['montantNetAchat'] !== '' ? (int) $donnees['montantNetAchat'] : null,
        'montant_net_vente' => $donnees['montantNetVente'] !== '' ? (int) $donnees['montantNetVente'] : null,
        'quantite_totale' => $donnees['quantiteTotale'] !== '' ? $donnees['quantiteTotale'] : null,
        'quantite_refacturee' => $donnees['quantiteRefacturee'] !== '' ? $donnees['quantiteRefacturee'] : null,
        'numero_facture_achat' => $donnees['numeroFactureAchat'] ?: null,
        'numero_facture_vente' => $donnees['numeroFactureVente'] ?: null,
        'numero_facture_client' => $donnees['numeroFactureClient'] ?: null,
        'resultat_indicatif' => $donnees['resultatIndicatif'] ?: null,
        'observations_facturation' => $donnees['observationsFacturation'] ?: null,
        'commentaires' => $donnees['commentaires'] ?: null,
        'actions_a_mener' => $donnees['actionsAMener'] ?: null,
    ]);

    activity()->performedOn($piece)->causedBy(auth()->user())
        ->log('Pièce fournisseur saisie à la main');

    // Vidés par affectation : `reset()` les remettrait à null, et la pièce suivante se
    // verrait réclamer une date qu'on croirait avoir laissée.
    $this->fournisseur = '';
    $this->numeroPiece = '';
    $this->naturePiece = '';
    $this->dateEcheance = '';
    $this->montant = '';
    $this->montantRegle = '';
    $this->modeReglement = '';
    $this->imputation = '';
    $this->immatriculation = '';
    $this->observations = '';

    // Les précisions se vident aussi : la pièce suivante n'a aucune raison d'hériter du
    // n° de chèque de la précédente.
    foreach ([
        'mois', 'section', 'numeroBc', 'dateReception', 'dateReglement', 'delaiReglement',
        'typeTransaction', 'numeroCheque', 'numeroFeb', 'numeroFiche', 'codePiece', 'vehicule',
        'montantHt', 'tva', 'tva2', 'montantRefacture', 'montantNetAchat', 'montantNetVente',
        'quantiteTotale', 'quantiteRefacturee', 'numeroFactureAchat', 'numeroFactureVente',
        'numeroFactureClient', 'resultatIndicatif', 'observationsFacturation', 'commentaires',
        'actionsAMener',
    ] as $champ) {
        $this->{$champ} = '';
    }

    // L'année de la pièce saisie devient celle qu'on regarde : sans cela, on viendrait de
    // consigner une facture qui n'apparaît nulle part à l'écran.
    $this->exercice = (int) date('Y', strtotime($donnees['dateFacture']));
    $this->pageDetail = 1;

    unset($this->perimetre, $this->requete, $this->detail, $this->kpis, $this->principaux, $this->exercices);

    $this->dispatch('annonce', texte: 'La pièce '.$numero.' est enregistrée.', ton: 'succes');
};

$detail = computed(fn () => (clone $this->requete)
    ->with('ville')
    // La plus vieille dette en tête : faute d'échéance dans le fichier, c'est
    // l'ancienneté de la facture qui dit ce qui presse.
    ->orderBy('date_facture')
    ->paginate(25, ['*'], 'pageDetail', $this->pageDetail));

/**
 * Les fiches des fournisseurs de la page affichée, en une requête.
 *
 * Elles servent à afficher une échéance là où le fichier n'en donne pas. Une requête par
 * ligne en aurait fait vingt-cinq : c'est la leçon de la page « Clients & tiers », tombée
 * à cinq secondes pour cette raison exacte.
 */
$fiches = computed(fn () => ConditionsFournisseur::pour(
    (int) auth()->user()->entreprise_id,
    $this->detail->items(),
));

?>

<div>
    <x-titre-ecran titre="Fournisseurs"
        sous-titre="Ce que l'entreprise doit, à qui, et depuis combien de temps — repris du suivi fournisseurs.">
        {{-- Les deux exports du logiciel comptable s'ouvrent d'ici : c'est la page où l'on
             se pose la question, et c'est donc là que doivent être les réponses. --}}
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-top:10px;">
            <x-champ label="Année de l'état" model="exercice" type="select" :options="$this->exercices" :live="true" width="130" />
            {{-- Le pendant du « Tableau état initial » des impayés. Il manquait, et son
                 absence se remarquait : l'état par année ne montre jamais l'ensemble d'un
                 coup, et une pièce soldée d'une année passée n'y paraît nulle part. --}}
            <a href="{{ route('fournisseurs.tableau-initial') }}" wire:navigate class="bouton bouton-secondaire">Tableau initial</a>
            {{-- « Référentiel » ne disait pas ce qu'on y trouve. Cette page porte le terme de
                 règlement d'un fournisseur et sa TVA — c'est de là que sort l'échéance
                 attendue d'une pièce que le classeur n'a pas datée. --}}
            <a href="{{ route('referentiel-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Conditions de règlement</a>
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance fournisseurs</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements fournisseurs</a>
            @if ($this->peutEcrire)
                {{-- Rendu replié et déplié par x-show : un @if autour du formulaire rendrait
                     son ouverture aussi lente qu'une requête, pour un panneau déjà en page. --}}
                <button type="button" class="bouton"
                    x-on:click="$wire.$set('formulaireOuvert', ! $wire.formulaireOuvert, false)"
                    x-text="$wire.formulaireOuvert ? 'Fermer le formulaire' : '+ Ajouter une pièce'">
                    {{ $formulaireOuvert ? 'Fermer le formulaire' : '+ Ajouter une pièce' }}
                </button>
            @endif
        </div>
    </x-titre-ecran>

    @if (session('message'))
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #0E9F6E;">
            <p style="margin:0; font-size:13px; color:#1E7B34; font-weight:600;">{{ session('message') }}</p>
        </div>
    @endif

    @if ($this->peutEcrire)
        <div x-show="$wire.formulaireOuvert" @if (! $formulaireOuvert) style="display:none;" @endif>
            <div class="carte" style="margin-bottom:16px;">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Ajouter une facture fournisseur</h3>
                <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                    Pour une facture reçue entre deux dépôts. Le n° de pièce est celui du fournisseur ;
                    laissé vide, un numéro est attribué. Le reste à payer se déduit du montant et du réglé.
                </p>

                {{-- **Ce que ce formulaire couvre, et comment il tient sur l'écran.**

                     Les deux classeurs tenus à la main — celui d'Abidjan et celui de
                     San-Pédro — portent quarante colonnes à eux deux. La saisie n'en
                     offrait que douze : une pièce saisie ici était donc plus pauvre que la
                     même venue du classeur, et sa page de détail montrait des cases vides
                     qu'aucun écran ne permettait de remplir (relevé le 24/09).

                     Elles y sont toutes. Mais posées à plat, elles feraient six rangées de
                     champs devant un tableau qu'on vient consulter : **l'essentiel reste
                     visible, le reste se déplie**. Neuf fois sur dix, une pièce reçue entre
                     deux dépôts se saisit avec le fournisseur, la date et le montant. --}}
                <form wire:submit="enregistrer">
                    <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                        <x-champ label="Fournisseur" model="fournisseur" requis width="230" />
                        <x-champ label="N° de pièce" model="numeroPiece" placeholder="Celui du fournisseur" width="150" />
                        <x-champ label="Nature" model="naturePiece" width="130" />
                        <x-champ label="Date de facture" model="dateFacture" type="date" requis width="155" />
                        <x-champ label="Échéance" model="dateEcheance" type="date" width="155" />
                        <x-champ label="Montant" model="montant" type="number" requis width="140" />
                        <x-champ label="Déjà réglé" model="montantRegle" type="number" width="140" />
                        <x-champ label="Mode de règlement" model="modeReglement" width="170" />
                        <x-champ label="Imputation" model="imputation" width="170" />
                        <x-champ label="Immatriculation" model="immatriculation" width="145" />
                        @if (count($this->villesOuSaisir) > 1)
                            <x-champ label="Ville" model="villeSaisie" type="select" live="true"
                                :options="$this->villesOuSaisir" vide="— à préciser —" width="165" />
                        @endif
                        {{-- L'atelier n'apparaît que là où il y a un choix à faire : Abidjan
                             en a deux, Bouaké et San-Pédro un seul. Demander « lequel ? »
                             quand il n'y en a qu'un, c'est poser une question qui n'en est
                             pas une. --}}
                        @if ($this->sitesOuSaisir !== [])
                            <x-champ label="Atelier" model="siteSaisie" type="select"
                                :options="$this->sitesOuSaisir" vide="— à préciser —" width="185" />
                        @endif
                    </div>

                    <div style="margin-top:12px;">
                        <x-champ label="Observations" model="observations" type="textarea" />
                    </div>

                    {{-- ------------------------------------------- les colonnes du classeur --}}
                    <div style="margin-top:14px;">
                        <button type="button" class="bouton bouton-secondaire bouton-petit"
                            wire:click="$toggle('precisionsOuvertes')">
                            {{ $precisionsOuvertes ? '− Masquer' : '+ Ajouter' }} les précisions du classeur
                        </button>
                        <span style="font-size:12px; color:#6B6E76; margin-left:8px;">
                            Section, bon de commande, TVA, refacturation, quantités, marge, n° FEB,
                            n° de chèque — facultatives, et reprises telles que le classeur les nomme.
                        </span>
                    </div>

                    <div x-show="$wire.precisionsOuvertes" @if (! $precisionsOuvertes) style="display:none;" @endif>
                        <div style="margin-top:12px;">
                            <p class="sous-titre" style="margin-top:0;">Le dossier</p>
                            <div class="bloc-saisie">
                                <x-champ label="Mois" model="mois" type="number" width="100" />
                                <x-champ label="Section" model="section" width="160"
                                    placeholder="Peinture, Carrosserie…" />
                                <x-champ label="N° BC" model="numeroBc" width="140" />
                                <x-champ label="Date de réception" model="dateReception" type="date" width="165" />
                                <x-champ label="Type de transaction / services" model="typeTransaction" width="220" />
                                <x-champ label="N° de fiche de réception" model="numeroFiche" width="180"
                                    placeholder="FR-…" />
                                <x-champ label="Véhicule" model="vehicule" width="170" />
                                <x-champ label="Code pièce" model="codePiece" width="145" />
                            </div>

                            <p class="sous-titre">Le règlement</p>
                            <div class="bloc-saisie">
                                <x-champ label="Date de règlement" model="dateReglement" type="date" width="165" />
                                <x-champ label="Délai de règlement" model="delaiReglement" width="165"
                                    placeholder="Comptant, 30 jours…" />
                                <x-champ label="N° de chèque" model="numeroCheque" width="150" />
                                <x-champ label="N° FEB" model="numeroFeb" width="130" />
                            </div>

                            <p class="sous-titre">Les montants</p>
                            <div class="bloc-saisie">
                                <x-champ label="Montant HT" model="montantHt" type="number" width="140" />
                                <x-champ label="TVA" model="tva" type="number" width="120" />
                                <x-champ label="TVA 2" model="tva2" type="number" width="120" />
                                <x-champ label="Montant refacturé" model="montantRefacture" type="number" width="160" />
                                <x-champ label="Montant net achat" model="montantNetAchat" type="number" width="160" />
                                <x-champ label="Montant net vente" model="montantNetVente" type="number" width="160" />
                                <x-champ label="Qté totale" model="quantiteTotale" type="number" width="130" />
                                <x-champ label="Qté refacturée" model="quantiteRefacturee" type="number" width="145" />
                            </div>

                            <p class="sous-titre">La refacturation au client</p>
                            <div class="bloc-saisie">
                                <x-champ label="N° facture achat (FA)" model="numeroFactureAchat" width="175" />
                                <x-champ label="N° facture vente (FV)" model="numeroFactureVente" width="175" />
                                <x-champ label="N° facture client" model="numeroFactureClient" width="165" />
                                <x-champ label="Résultat indicatif" model="resultatIndicatif" width="170" />
                            </div>

                            {{-- La marge, le taux et la différence ne se saisissent pas : le
                                 classeur les calcule, et une colonne qu'on peut contredire à
                                 la main finit toujours par l'être. --}}
                            <div class="imp-hint" style="margin-top:4px; font-size:12px;">
                                La <b>marge</b>, le <b>taux</b>, la <b>différence</b> et le <b>reste à payer</b>
                                ne figurent pas ici : ils se déduisent des montants, comme dans le classeur où
                                ce sont des colonnes vertes. Les saisir permettrait de les contredire.
                            </div>

                            <p class="sous-titre">Ce qu'on en dit</p>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:12px;">
                                <x-champ label="Observations sur la facturation client"
                                    model="observationsFacturation" type="textarea" />
                                <x-champ label="Commentaires" model="commentaires" type="textarea" />
                                <x-champ label="Actions à mener" model="actionsAMener" type="textarea" />
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <button type="submit" class="bouton">Enregistrer la pièce</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Reste à payer — {{ $this->libellePerimetre }}" :value="ae($this->kpis['reste'])"
            couleur="#C8102E" :sub="$this->kpis['ouvertes'].' facture(s) ouverte(s) · '.$this->kpis['fournisseurs'].' fournisseur(s)'
                .($this->kpis['avoirs'] > 0 ? ' · '.ae($this->kpis['avoirs']).' de trop-payé à réclamer' : '')" />
        <x-kpi-card label="Dû depuis plus de 90 jours" :value="ae($this->kpis['ancien'])"
            :accent="$this->kpis['ancien'] > 0"
            :sub="$this->kpis['plusAncienne']
                ? 'La plus ancienne date du '.\Illuminate\Support\Carbon::parse($this->kpis['plusAncienne'])->format('d/m/Y')
                : 'Aucune pièce ouverte'" />
        <x-kpi-card label="Échu" :value="ae($this->kpis['echu'])"
            :accent="$this->kpis['echu'] > 0"
            :sub="$this->kpis['avecEcheance'] > 0
                ? 'Sur les '.$this->kpis['avecEcheance'].' pièce(s) ouverte(s) dont l\'échéance est connue'
                : 'Aucune pièce ouverte ne porte d\'échéance'" />
        <x-kpi-card label="Reporté des années passées" :value="ae($this->kpis['resteReporte'])"
            :sub="$this->kpis['reportees'].' pièce(s) antérieure(s) encore due(s) — rien n\'est recopié'" />
        <x-kpi-card label="Facturé en {{ $this->annee }}" :value="ae($this->kpis['facture'])"
            :sub="$this->kpis['lignes'].' pièce(s) à l\'état de cette année'" />
        <x-kpi-card label="Déjà réglé" :value="ae($this->kpis['regle'])" couleur="#0E9F6E" />
    </div>

    @if ($this->mesVilles !== null && $this->mesVilles->count() > 1)
        <div class="carte" style="margin-bottom:16px; padding:12px 15px;">
            <label for="ville-fourn" style="font-size:12.5px; font-weight:600; color:#4B4E55; margin-right:9px;">Ville</label>
            <select id="ville-fourn" wire:model.live="villeFiltre" class="champ" style="max-width:280px;">
                <option value="" @selected($villeFiltre === '')>Toutes les villes</option>
                {{-- Cette liste rend des villes, pas des paires identifiant/nom : la parcourir
                     comme un tableau associatif affichait chaque ville sous sa forme JSON
                     complète, coordonnées et couleur comprises. --}}
                @foreach ($this->mesVilles as $ville)
                    <option value="{{ $ville->id }}" @selected((string) $villeFiltre === (string) $ville->id)>{{ $ville->nom }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($this->principaux->isNotEmpty())
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">À qui nous devons le plus</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th style="text-align:right;">Pièces ouvertes</th>
                            <th style="text-align:right;">Déjà payé</th>
                            <th style="text-align:right;">Reste à payer</th>
                            <th style="text-align:right;">Part de la dette</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->principaux as $f)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $f->fournisseur ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $f->pieces }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">
                                    {{ ae((int) $f->regle) }}
                                    @if ($f->engage > 0)
                                        {{-- La part réglée dit d'un coup d'œil si le compte tourne
                                             ou s'il s'installe. --}}
                                        <div style="font-size:11px; color:#6B6E76;">{{ round($f->regle / $f->engage * 100) }} % de l'engagé</div>
                                    @endif
                                </td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae((int) $f->du) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                    {{ $this->kpis['reste'] > 0 ? round($f->du / $this->kpis['reste'] * 100) : 0 }} %
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                Factures reçues ({{ number_format($this->detail->total(), 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Fournisseur, n° de pièce, immatriculation…" class="champ" style="min-width:280px;">

                <select wire:model.live="etatFiltre" class="champ">
                    <option value="ouvertes" @selected($etatFiltre === 'ouvertes')>Reste à payer</option>
                    <option value="anciennes" @selected($etatFiltre === 'anciennes')>Dues depuis plus de 90 jours</option>
                    <option value="echues" @selected($etatFiltre === 'echues')>Échues</option>
                    <option value="soldees" @selected($etatFiltre === 'soldees')>Soldées</option>
                    <option value="toutes" @selected($etatFiltre === 'toutes')>Toutes</option>
                </select>

                {{-- Les filtres voyagent dans l'adresse du lien : le fichier emporté contient
                     exactement ce que le tableau montre, et le lien se transmet tel quel. --}}
                <x-telecharger route="fournisseurs.telecharger"
                    :parametres="['exercice' => $this->annee, 'ville' => $villeFiltre, 'etat' => $etatFiltre, 'recherche' => $recherche]" />
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Fournisseur</th>
                        <th>N° pièce</th>
                        <th>Report</th>
                        <th>Facture</th>
                        <th>Échéance</th>
                        <th>Ancienneté</th>
                        <th>Section</th>
                        <th>Imputation</th>
                        <th style="text-align:right;">Montant</th>
                        <th style="text-align:right;">Réglé</th>
                        <th style="text-align:right;">Reste</th>
                        <th class="colonne-collee"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail as $ligne)
                        @php
                            $jours = $ligne->date_facture
                                ? NombreDeJours::entre($ligne->date_facture, now())
                                : null;
                            $vieille = $ligne->reste_a_payer > 0 && $jours !== null && $jours > 90;
                            $echue = $ligne->reste_a_payer > 0 && $ligne->date_echeance?->isPast();
                            /* L'échéance attendue, quand le fichier n'en porte pas : elle
                               vient du terme du fournisseur et se dit « attendue ». Elle
                               n'entre pas dans le filtre « Échues » ni dans le KPI de
                               l'échu — une relance se fonde sur ce que le fournisseur a
                               écrit, pas sur ce que nous avons calculé pour lui. */
                            $echeance = ConditionsFournisseur::echeance(
                                $ligne,
                                ConditionsFournisseur::fiche($this->fiches, $ligne),
                            );
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>{{ $ligne->fournisseur ?: '—' }}</td>
                            <td style="color:#6B6E76;">
                                {{ $ligne->numero_piece ?: '—' }}
                                @if ($ligne->lot_import_id === null)
                                    {{-- Saisie à la main : le dire évite de la chercher dans un
                                         fichier où elle n'est pas. --}}
                                    <div style="font-size:11px; color:#9A9DA5;">saisie</div>
                                @endif
                            </td>
                            <td style="white-space:nowrap; color:#6B6E76;">
                                {{ EtatDesFournisseurs::libelleReport($ligne, $this->annee) }}
                            </td>
                            <td style="white-space:nowrap;">{{ $ligne->date_facture?->format('d/m/Y') ?? '—' }}</td>
                            {{-- Un tiret dit « le fichier ne le sait pas », jamais « à jour ». --}}
                            <td style="white-space:nowrap; {{ $echue ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                @if ($echeance['source'] === 'fichier')
                                    {{ $echeance['date']->format('d/m/Y') }}
                                @elseif ($echeance['source'] === 'terme')
                                    <span style="color:#9A9DA5;">{{ $echeance['date']->format('d/m/Y') }}</span>
                                    <div style="font-size:11px; color:#9A9DA5;" title="Déduite du terme « {{ $echeance['terme'] }} » — le fichier ne porte pas d'échéance sur cette ligne.">attendue</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="white-space:nowrap; {{ $vieille ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                {{ $jours === null ? '—' : number_format((int) $jours, 0, ',', ' ').' j' }}
                            </td>
                            <td style="color:#6B6E76;">{{ $ligne->section ?: '—' }}</td>
                            <td style="color:#6B6E76;">
                                {{ $ligne->imputation ?: ($ligne->immatriculation ?: '—') }}
                                @if ($ligne->vehicule)
                                    <div style="font-size:11px;">{{ $ligne->vehicule }}{{ $ligne->immatriculation ? ' · '.$ligne->immatriculation : '' }}</div>
                                @endif
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae((int) $ligne->montant) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ ae((int) $ligne->montant_regle) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne->reste_a_payer > 0 ? '#C8102E' : '#6B6E76' }};">
                                {{ ae((int) $ligne->reste_a_payer) }}
                            </td>
                            <td class="colonne-collee" style="text-align:right;">
                                <a href="{{ route('fournisseurs.piece', $ligne) }}" wire:navigate
                                    class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px; text-decoration:none;">Détail</a>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="12"
                            texte="Aucune facture fournisseur pour cette année et ce filtre. Le suivi fournisseurs se dépose depuis le module Import." />
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Paginé par `$set` et non par un lien : le paginateur d'Eloquent rend de vraies
             ancres `?pageDetail=2`, qui rechargent la page et la ramènent en haut — on
             perdait la ligne qu'on était en train de lire à chaque page tournée. --}}
        <x-pagination :page="$this->detail->currentPage()" :total="$this->detail->total()"
            prop="pageDetail" :par-page="25" />
    </div>
</div>

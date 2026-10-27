<?php

use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\ConditionsFournisseur;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Services\NumeroDePiece;

use function Livewire\Volt\{computed, mount, state, protect};

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
    /*
     * **Toutes les factures par défaut — 22/10** : « c'est la page des fournisseurs, on doit y
     * avoir toutes les factures fournisseurs — suivies ou pas, venant de la FNE ou pas ». Elle
     * s'ouvrait sur « Reste à payer » : une pièce soldée, ou une facture FNE pas encore au
     * suivi, n'y paraissait qu'en changeant de filtre.
     */
    'etatFiltre' => 'toutes',
    // D'où vient la ligne : le suivi (importé, saisi, porté), ou la FNE pas encore au suivi.
    'provenanceFiltre' => 'toutes',
    // Rapprochée de sa facture FNE ou non — 26/10 : « un filtre rapproché, non rapproché ».
    'rapprochementFiltre' => '',
    /*
     * **Un seul tableau, deux boutons — 22/10** : « Factures reçues » et « À qui nous devons le
     * plus » étaient deux tableaux l'un sous l'autre ; ils deviennent deux vues du même cadre.
     */
    'vueTableau' => 'factures',
    'recherche' => '',
    'pageDetail' => 1,
    /*
     * Les filtres posés sur les colonnes sans filtre propre — voir `FiltreLibre` et le
     * composant `x-autre-filtre`. Hors de l'adresse : un tableau de tableaux ne se
     * sérialise pas lisiblement dans une URL, pour un gain nul.
     */
    'filtresLibres' => [],
    'formulaireOuvert' => false,
    // Les précisions du classeur, repliées sous l'essentiel (rétablies le 22/10).
    'precisionsOuvertes' => false,
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
 *
 * **Le 20/10, le formulaire les avait retirées** (« c'est trop, on garde l'essentiel »). **Le
 * 22/10, le propriétaire les rétablit, sauf trois** : « voici ce qu'il faut retirer : Mois, Code
 * pièce, Résultat indicatif ; tous les autres champs des autres sections peuvent rester ». Elles
 * reviennent repliées sous l'essentiel. Les trois retirées restent en base, reprises du classeur
 * à l'import et lisibles sur la page de la pièce ; seule la saisie à la main ne les propose plus.
 */
state([
    'section' => '',
    'numeroBc' => '',
    'dateReception' => '',
    'dateReglement' => '',
    'delaiReglement' => '',
    'typeTransaction' => '',
    'numeroCheque' => '',
    'numeroFeb' => '',
    'numeroFiche' => '',
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
    'observationsFacturation' => '',
    'commentaires' => '',
    'actionsAMener' => '',
]);


/*
 * La facture FNE qu'on est en train de porter au suivi — 18/10 : « le bouton Porter doit faire
 * apparaître les informations dans le formulaire du suivi, sur cette page ». Elle préremplit le
 * formulaire ; c'est l'enregistrement qui crée la ligne et la relie à la FNE. Rien n'est réglé.
 */
state(['porterFne' => null]);
// L'adresse `?porter=<id>` que donne le bouton « Porter au suivi » de la page des factures FNE.
state(['porter' => null])->url(except: null);
// `?ajouter=1` : le bouton « + Ajouter une pièce » de la page Charges arrive formulaire ouvert (23/10).
state(['ajouter' => null])->url(except: null);

/*
 * **Deux voies pour ajouter une pièce — 23/10** : « le bouton Ajouter une pièce ouvre deux choses :
 * un champ pour sélectionner la facture FNE, avec une recherche comme les autres — sélectionnée, ses
 * informations se reportent comme le fait le bouton Porter — ; et un bouton de saisie, qui ouvre les
 * champs du suivi ». '' = le choix n'est pas fait ; 'fne' = une facture choisie ; 'libre' = saisie.
 */
state(['modeSaisie' => '', 'fneChoisie' => '']);

/*
 * **« Suivre » un décaissement de caisse — 23/10.** `?suivre=<id>` vient de la page des
 * décaissements fournisseurs : le formulaire s'ouvre prérempli de la sortie, et l'enregistrement
 * crée la pièce **réglée par cette sortie** — le règlement pointe la sortie, et les deux lignes se
 * répondent (lettrage). La sortie est relue sous l'entreprise, et seulement si rien ne la lettre déjà.
 */
state(['suivre' => null])->url(except: null);
state(['suivreSortie' => null]);

mount(function () {
    $this->exercice ??= EtatDesFournisseurs::exerciceOuvert(auth()->user()->entreprise_id);
    $this->dateFacture = $this->dateFacture ?: now()->format('Y-m-d');
    $this->villeSaisie = $this->villeSaisie ?: (string) (auth()->user()->ville_id ?? '');

    $aPorter = (int) ($this->porter ?? 0);
    $this->porter = null;

    if ($this->ajouter && EtatDesFournisseurs::peutEcrire(auth()->user())) {
        $this->formulaireOuvert = true;
    }
    $this->ajouter = null;

    $aSuivre = (int) ($this->suivre ?? 0);
    $this->suivre = null;

    if ($aSuivre > 0 && EtatDesFournisseurs::peutEcrire(auth()->user()) && ($sortie = $this->sortieASuivre($aSuivre)) !== null) {
        $this->suivreSortie = $sortie->id;
        $this->modeSaisie = 'libre';
        $this->formulaireOuvert = true;
        $this->fournisseur = (string) mb_substr(trim((string) ($sortie->beneficiaire ?: $sortie->libelle)), 0, 200);
        $this->dateFacture = $sortie->date?->format('Y-m-d') ?? $this->dateFacture;
        $this->montant = (string) montant_de($sortie->montant);
        $this->modeReglement = 'Espèces';
        $this->observations = 'Suivi d\'un décaissement de caisse du '.($sortie->date?->format('d/m/Y') ?? '—')
            .' — '.trim($sortie->libelle.($sortie->numero_piece ? ' (pièce '.$sortie->numero_piece.')' : '')).'.';

        if ($sortie->date) {
            $this->exercice = $sortie->date->year;
        }
    }

    if ($aPorter > 0 && EtatDesFournisseurs::peutEcrire(auth()->user()) && \App\Support\SchemaDisponible::table('factures_fne')) {
        $fne = \Modules\Noyau\Imports\Modeles\FactureFne::query()->whereNull('facture_fournisseur_id')->find($aPorter);

        if ($fne !== null) {
            $this->porterFne = $fne->id;
            $this->modeSaisie = 'fne';
            $this->formulaireOuvert = true;
            $this->prendreLaFactureFne($fne);
            $this->observations = 'Portée depuis la FNE (NCC '.($fne->emetteur_ncc ?: '—').').';

            if ($fne->date_facture) {
                $this->exercice = $fne->date_facture->year;
            }
        }
    }
});

/*
 * Ce que dit la facture FNE, recopié dans le formulaire — 20/10 : « les champs préremplis doivent
 * être en premier, et ne doivent pas pouvoir être modifiés ». Appelé au montage **et** à
 * l'enregistrement : ce qui arrive du navigateur pour ces champs est remplacé par la facture
 * certifiée, qu'on ait touché ou non à la page. Porter n'est pas régler : le réglé reste à zéro.
 */
$prendreLaFactureFne = protect(function (\Modules\Noyau\Imports\Modeles\FactureFne $fne) {
    $this->fournisseur = (string) mb_substr($fne->emetteur_nom ?: 'Fournisseur NCC '.$fne->emetteur_ncc, 0, 200);
    $this->numeroPiece = (string) mb_substr((string) $fne->reference, 0, 60);
    $this->naturePiece = $fne->est_avoir ? 'Avoir FNE' : 'Facture FNE';
    $this->dateFacture = $fne->date_facture?->format('Y-m-d') ?? $this->dateFacture;
    $this->montant = (string) montant_de($fne->montant_ttc);
    $this->montantRegle = '';
    $this->montantHt = (string) montant_de($fne->montant_ht);
    $this->tva = (string) montant_de($fne->montant_tva);
    $this->numeroFactureAchat = (string) mb_substr((string) $fne->reference, 0, 60);
    $this->modeReglement = $this->modeReglement ?: (string) ($fne->moyen_paiement ?? '');
});

/** La sortie de caisse à suivre : une sortie de l'entreprise, que nul règlement ne lettre encore. */
$sortieASuivre = protect(fn (int $id) => \Modules\Noyau\Imports\Modeles\MouvementCaisse::query()
    ->where('sens', \Modules\Noyau\Imports\Modeles\MouvementCaisse::SORTIE)
    ->when(\App\Support\SchemaDisponible::colonne('reglements_fournisseur', 'mouvement_caisse_id'),
        fn ($q) => $q->whereNotExists(fn ($r) => $r->selectRaw('1')->from('reglements_fournisseur')
            ->whereColumn('reglements_fournisseur.mouvement_caisse_id', 'mouvements_caisse.id')))
    ->find($id));

$abandonnerLePortage = function () {
    $this->suivreSortie = null;
    $this->porterFne = null;
    $this->modeSaisie = '';
    $this->fneChoisie = '';
    foreach (['fournisseur', 'numeroPiece', 'naturePiece', 'montant', 'montantRegle', 'montantHt', 'tva', 'numeroFactureAchat', 'modeReglement', 'observations'] as $champ) {
        $this->{$champ} = '';
    }
    $this->dateFacture = now()->format('Y-m-d');
    $this->formulaireOuvert = false;
};

/**
 * Les factures FNE de l'année qu'aucune ligne du suivi ne porte : les choix du champ « Facture FNE ».
 *
 * @return array<int, string>
 */
$fnesAPorter = computed(fn () => ! \App\Support\SchemaDisponible::table('factures_fne') ? [] : FactureFne::query()
    ->whereNull('facture_fournisseur_id')->where('exercice', $this->annee)
    ->orderByDesc('date_facture')
    ->get(['id', 'reference', 'date_facture', 'emetteur_nom', 'montant_ttc'])
    ->mapWithKeys(fn (FactureFne $f) => [$f->id => $f->reference.' · '.($f->emetteur_nom ?: '—').' · '
        .($f->date_facture?->format('d/m/Y') ?? '—').' · '.ae(montant_de($f->montant_ttc))])
    ->all());

/** Une facture FNE choisie : ses informations se reportent comme par « Porter », relues sous l'entreprise. */
$updatedFneChoisie = function () {
    if (! EtatDesFournisseurs::peutEcrire(auth()->user())) {
        abort(403);
    }

    $fne = FactureFne::query()->whereNull('facture_fournisseur_id')->find((int) $this->fneChoisie);

    if ($fne === null) {
        return;
    }

    $this->porterFne = $fne->id;
    $this->modeSaisie = 'fne';
    $this->prendreLaFactureFne($fne);
    $this->observations = 'Portée depuis la FNE (NCC '.($fne->emetteur_ncc ?: '—').').';
};

$choisirLaSaisie = function () {
    $this->porterFne = null;
    $this->fneChoisie = '';
    $this->modeSaisie = 'libre';
};

$updatedExercice = function () { $this->pageDetail = 1; };
$updatedVilleFiltre = function () { $this->pageDetail = 1; };
$updatedEtatFiltre = function () { $this->pageDetail = 1; };
$updatedRapprochementFiltre = function () { $this->pageDetail = 1; };
/* La FNE pas encore au suivi ne dit pas ce qui en a été réglé : la choisir, c'est regarder toutes
   ses pièces, sans filtre d'état qui n'aurait rien à lire. */
$updatedProvenanceFiltre = function () {
    $this->pageDetail = 1;

    if ($this->provenanceFiltre === 'fne') {
        $this->etatFiltre = 'toutes';
    }
};
$montrer = function (string $vue) {
    $this->vueTableau = in_array($vue, ['factures', 'principaux'], true) ? $vue : 'factures';
};
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

    /*
     * Les colonnes des quarante du classeur qu'aucun filtre du haut ne couvre. Posées
     * avant le filtre d'état, puisque celui-ci clôt la requête par un `match`.
     */
    FiltreLibre::appliquer($requete, $this->colonnesFiltrables, (array) $this->filtresLibres);

    // Le rapprochement au relevé FNE, par le n° de pièce (26/10).
    if (in_array($this->rapprochementFiltre, ['rapprochees', 'non'], true) && \App\Support\SchemaDisponible::table('factures_fne')) {
        $lie = fn ($q) => $q->selectRaw('1')->from('factures_fne')->whereColumn('factures_fne.facture_fournisseur_id', 'factures_fournisseurs.id');
        $this->rapprochementFiltre === 'rapprochees' ? $requete->whereExists($lie) : $requete->whereNotExists($lie);
    }

    return match ($this->etatFiltre) {
        'ouvertes' => $requete->where('reste_a_payer', '>', 0),
        'anciennes' => $requete->where('reste_a_payer', '>', 0)
            ->whereDate('date_facture', '<', now()->subDays(90)),
        // Échue : l'échéance est passée — ou, sans échéance ni règlement, la facture elle-même
        // est passée (12/10 : « s'il n'y a ni date de règlement ni échéance, compter les jours
        // écoulés depuis la facture ; pas de facture écartée »).
        'echues' => $requete->where('reste_a_payer', '>', 0)
            ->where(fn ($q) => $q->whereDate('date_echeance', '<', now())
                ->orWhere(fn ($r) => $r->whereNull('date_echeance')->whereNull('date_reglement')
                    ->whereDate('date_facture', '<', now()))),
        'soldees' => $requete->where('reste_a_payer', '<=', 0),
        default => $requete,
    };
});

/*
 * **Les fournisseurs normalisés — 20/10** : « affiche aussi des KPI qui reprennent les totaux des
 * fournisseurs normalisés ». Les factures FNE de l'année (toute l'entreprise : le portail ne dit
 * pas la ville), ce qui en est au suivi et ce qui ne l'est pas encore ; et la part du suivi que la
 * caisse a réglée — le lien règlement ↔ sortie de caisse posé dans « Régler ».
 */
$normalises = computed(function () {
    $vide = ['nombre' => 0, 'ttc' => 0, 'nAuSuivi' => 0, 'auSuivi' => 0, 'nHorsSuivi' => 0, 'horsSuivi' => 0, 'suiviNormalise' => 0, 'parLaCaisse' => 0];

    if (! \App\Support\SchemaDisponible::table('factures_fne')) {
        return $vide;
    }

    $f = \Modules\Noyau\Imports\Modeles\FactureFne::query()->where('exercice', $this->annee)
        ->selectRaw('count(*) as n, coalesce(sum(montant_ttc), 0) as ttc')
        ->selectRaw('coalesce(sum(case when facture_fournisseur_id is not null then 1 else 0 end), 0) as n_suivi')
        ->selectRaw('coalesce(sum(case when facture_fournisseur_id is not null then montant_ttc else 0 end), 0) as m_suivi')
        ->toBase()->first();

    $suiviNormalise = montant_de((clone $this->perimetre)->whereExists(fn ($q) => $q->selectRaw('1')->from('factures_fne')
        ->whereColumn('factures_fne.facture_fournisseur_id', 'factures_fournisseurs.id'))->sum('montant'));

    $parLaCaisse = ! \App\Support\SchemaDisponible::colonne('reglements_fournisseur', 'mouvement_caisse_id') ? 0
        : montant_de(\Modules\Noyau\Imports\Modeles\ReglementFournisseur::query()->whereNotNull('mouvement_caisse_id')
            ->whereIn('facture_fournisseur_id', (clone $this->perimetre)->reorder()->select('factures_fournisseurs.id'))
            ->sum('montant'));

    return [
        'nombre' => (int) ($f->n ?? 0),
        'ttc' => montant_de($f->ttc ?? 0),
        'nAuSuivi' => (int) ($f->n_suivi ?? 0),
        'auSuivi' => montant_de($f->m_suivi ?? 0),
        'nHorsSuivi' => (int) ($f->n ?? 0) - (int) ($f->n_suivi ?? 0),
        'horsSuivi' => montant_de(montant_de($f->ttc ?? 0) - montant_de($f->m_suivi ?? 0)),
        'suiviNormalise' => $suiviNormalise,
        'parLaCaisse' => $parLaCaisse,
    ];
});

$kpis = computed(fn () => EtatDesFournisseurs::totaux($this->perimetre, $this->annee) + [
    // Le facturé, le réglé, le reste, les avoirs, les reports : sept nombres, une seule
    // lecture. La dette ne compte que les restes positifs — un trop-payé ne rembourse pas
    // la dette d'un autre fournisseur. Voir EtatDesFournisseurs::totaux().
    // L'ancienneté se compte depuis la date de facture : c'est la seule date que toutes
    // les lignes portent, et une dette de plus de trois mois est un signal à elle seule.
    'ancien' => montant_de((clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->whereDate('date_facture', '<', now()->subDays(90))
        ->sum('reste_a_payer')),
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
    // Sans échéance ni règlement, la date de la facture fait foi (12/10) : aucune pièce
    // n'échappe à l'échu parce qu'une date manque.
    'echu' => montant_de((clone $this->perimetre)->where('reste_a_payer', '>', 0)
        ->where(fn ($q) => $q->whereDate('date_echeance', '<', now())
            ->orWhere(fn ($r) => $r->whereNull('date_echeance')->whereNull('date_reglement')
                ->whereDate('date_facture', '<', now())))
        ->sum('reste_a_payer')),
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
        $ligne->regle = montant_de($regles[$ligne->fournisseur]->regle ?? 0);
        $ligne->engage = montant_de($regles[$ligne->fournisseur]->engage ?? 0);
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

    // La facture FNE portée : relue sous l'entreprise, et seulement si personne ne l'a portée
    // entre-temps. Ses champs reprennent la valeur certifiée avant toute validation.
    $fne = $this->porterFne === null ? null : \Modules\Noyau\Imports\Modeles\FactureFne::query()
        ->whereNull('facture_fournisseur_id')->find((int) $this->porterFne);

    if ($fne !== null) {
        $this->prendreLaFactureFne($fne);
    }

    $donnees = $this->validate([
        'fournisseur' => ['required', 'string', 'max:200'],
        'numeroPiece' => ['nullable', 'string', 'max:60'],
        'naturePiece' => ['nullable', 'string', 'max:60'],
        'dateFacture' => ['required', 'date'],
        'dateEcheance' => ['nullable', 'date', 'after_or_equal:dateFacture'],
        // Tels que donnés (piège 17) : des centimes, et un avoir en négatif — une facture FNE portée
        // en a souvent. Seul zéro est refusé : une pièce de zéro ne doit rien.
        'montant' => ['required', 'numeric', 'not_in:0'],
        'montantRegle' => ['nullable', 'numeric'],
        'modeReglement' => ['nullable', 'string', 'max:200'],
        'imputation' => ['nullable', 'string', 'max:120'],
        'immatriculation' => ['nullable', 'string', 'max:40'],
        'observations' => ['nullable', 'string', 'max:2000'],
        'villeSaisie' => ['nullable', 'integer'],
        'siteSaisie' => ['nullable', 'integer'],

        /* Les précisions du classeur. Aucune n'est exigée : une pièce reçue entre deux
           dépôts se saisit avec le fournisseur, la date et le montant, et le reste se
           complète si on l'a sous les yeux. */
        'section' => ['nullable', 'string', 'max:60'],
        'numeroBc' => ['nullable', 'string', 'max:60'],
        'dateReception' => ['nullable', 'date'],
        'dateReglement' => ['nullable', 'date'],
        'delaiReglement' => ['nullable', 'string', 'max:40'],
        'typeTransaction' => ['nullable', 'string', 'max:120'],
        'numeroCheque' => ['nullable', 'string', 'max:60'],
        'numeroFeb' => ['nullable', 'string', 'max:60'],
        'numeroFiche' => ['nullable', 'string', 'max:40'],
        'vehicule' => ['nullable', 'string', 'max:120'],
        'montantHt' => ['nullable', 'numeric'],
        'tva' => ['nullable', 'numeric'],
        'tva2' => ['nullable', 'numeric'],
        'montantRefacture' => ['nullable', 'numeric'],
        'montantNetAchat' => ['nullable', 'numeric'],
        'montantNetVente' => ['nullable', 'numeric'],
        'quantiteTotale' => ['nullable', 'numeric', 'min:0'],
        'quantiteRefacturee' => ['nullable', 'numeric', 'min:0'],
        'numeroFactureAchat' => ['nullable', 'string', 'max:60'],
        'numeroFactureVente' => ['nullable', 'string', 'max:60'],
        'numeroFactureClient' => ['nullable', 'string', 'max:60'],
        'observationsFacturation' => ['nullable', 'string', 'max:2000'],
        'commentaires' => ['nullable', 'string', 'max:2000'],
        'actionsAMener' => ['nullable', 'string', 'max:2000'],
    ], [
        'dateEcheance.after_or_equal' => "L'échéance ne peut pas précéder la facture.",
    ]);

    $entrepriseId = (int) auth()->user()->entreprise_id;
    $montant = montant_de($donnees['montant']);
    $regle = montant_de($donnees['montantRegle'] ?: 0);

    // « Suivre » : la sortie relue, et la pièce doit au moins couvrir ce qu'elle a payé.
    $sortie = $this->suivreSortie === null ? null : $this->sortieASuivre((int) $this->suivreSortie);

    if ($this->suivreSortie !== null && $sortie === null) {
        $this->suivreSortie = null;
        $this->addError('fournisseur', 'Ce décaissement est déjà suivi, ou introuvable.');

        return;
    }

    if ($sortie !== null) {
        if ($montant < montant_de($sortie->montant)) {
            $this->addError('montant', 'La pièce doit couvrir au moins le décaissement qui la paie ('.ae(montant_de($sortie->montant)).').');

            return;
        }

        // Le règlement est la sortie elle-même, posé après la création : rien de « déjà réglé » saisi en plus.
        $regle = 0;
    }

    if (abs($regle) > abs($montant) || ($regle != 0 && ($regle > 0) !== ($montant > 0))) {
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

    /*
     * Le doublon se cherche avec la **même règle que l'import**, noyau du numéro compris :
     * une pièce saisie « 1827 » et la même déjà entrée « 22319I091/0001827 » sont la même
     * facture. Deux règles de doublon, une à l'écran et une à l'import, laisseraient passer
     * à la main ce que le fichier refuse — et la dette compterait double.
     */
    $deja = FactureFournisseur::query()
        ->where('fournisseur', $donnees['fournisseur'])
        ->whereDate('date_facture', $donnees['dateFacture'])
        ->where('montant', $montant)
        ->get()
        ->first(fn (FactureFournisseur $piece) => NumeroDePiece::memePiece($numero, $piece->numero_piece));

    if ($deja !== null) {
        $this->addError('numeroPiece', 'Cette pièce est déjà enregistrée sous le n° « '
            .$deja->numero_piece.' » — même fournisseur, même date, même montant.');

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
        'source_rattachement' => $fne !== null ? 'fne' : 'saisie',
        'site_id' => $siteId,

        /* Les précisions du classeur, telles quelles. Une chaîne vide devient null : une
           case laissée vide dit « on ne sait pas », pas « c'est vide ». */
        'section' => $donnees['section'] ?: null,
        'numero_bc' => $donnees['numeroBc'] ?: null,
        'date_reception' => $donnees['dateReception'] ?: null,
        'date_reglement' => $donnees['dateReglement'] ?: null,
        'delai_reglement' => $donnees['delaiReglement'] ?: null,
        'type_transaction' => $donnees['typeTransaction'] ?: null,
        'numero_cheque' => $donnees['numeroCheque'] ?: null,
        'numero_feb' => $donnees['numeroFeb'] ?: null,
        'numero_fiche' => $donnees['numeroFiche'] ?: null,
        'vehicule' => $donnees['vehicule'] ?: null,
        'montant_ht' => $donnees['montantHt'] !== '' ? montant_de($donnees['montantHt']) : null,
        'tva' => $donnees['tva'] !== '' ? montant_de($donnees['tva']) : null,
        'tva_2' => $donnees['tva2'] !== '' ? montant_de($donnees['tva2']) : null,
        'montant_refacture' => $donnees['montantRefacture'] !== '' ? montant_de($donnees['montantRefacture']) : null,
        'montant_net_achat' => $donnees['montantNetAchat'] !== '' ? montant_de($donnees['montantNetAchat']) : null,
        'montant_net_vente' => $donnees['montantNetVente'] !== '' ? montant_de($donnees['montantNetVente']) : null,
        'quantite_totale' => $donnees['quantiteTotale'] !== '' ? $donnees['quantiteTotale'] : null,
        'quantite_refacturee' => $donnees['quantiteRefacturee'] !== '' ? $donnees['quantiteRefacturee'] : null,
        'numero_facture_achat' => $donnees['numeroFactureAchat'] ?: null,
        'numero_facture_vente' => $donnees['numeroFactureVente'] ?: null,
        'numero_facture_client' => $donnees['numeroFactureClient'] ?: null,
        'observations_facturation' => $donnees['observationsFacturation'] ?: null,
        'commentaires' => $donnees['commentaires'] ?: null,
        'actions_a_mener' => $donnees['actionsAMener'] ?: null,
    ]);

    activity()->performedOn($piece)->causedBy(auth()->user())
        ->log($fne !== null ? 'Facture FNE portée au suivi' : ($sortie !== null ? 'Décaissement de caisse suivi au suivi fournisseurs' : 'Pièce fournisseur saisie à la main'));

    // Le lettrage caisse ↔ suivi : la pièce est réglée par la sortie qu'on suit.
    if ($sortie !== null) {
        \Modules\Noyau\Imports\Services\AchatsFne::regler($piece, montant_de($sortie->montant), $sortie->date?->toDateString() ?? $donnees['dateFacture'], 'Espèces', auth()->user(), $sortie->id);
        $this->suivreSortie = null;
    }

    // Le lien qui fait que la facture ne compte qu'une fois : par la ligne du suivi.
    if ($fne !== null) {
        $fne->forceFill(['facture_fournisseur_id' => $piece->id])->save();
        $this->porterFne = null;
    }

    $this->modeSaisie = '';
    $this->fneChoisie = '';
    unset($this->fnesAPorter);

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
        'section', 'numeroBc', 'dateReception', 'dateReglement', 'delaiReglement',
        'typeTransaction', 'numeroCheque', 'numeroFeb', 'numeroFiche', 'vehicule',
        'montantHt', 'tva', 'tva2', 'montantRefacture', 'montantNetAchat', 'montantNetVente',
        'quantiteTotale', 'quantiteRefacturee', 'numeroFactureAchat', 'numeroFactureVente',
        'numeroFactureClient', 'observationsFacturation', 'commentaires',
        'actionsAMener',
    ] as $champ) {
        $this->{$champ} = '';
    }

    // L'année de la pièce saisie devient celle qu'on regarde : sans cela, on viendrait de
    // consigner une facture qui n'apparaît nulle part à l'écran.
    $this->exercice = (int) date('Y', strtotime($donnees['dateFacture']));
    $this->pageDetail = 1;

    unset($this->perimetre, $this->requete, $this->detail, $this->kpis, $this->principaux, $this->exercices, $this->normalises);

    $this->dispatch('annonce', texte: 'La pièce '.$numero.' est enregistrée.', ton: 'succes');
};

/**
 * Les colonnes du tableau qu'aucun filtre du haut ne couvre.
 *
 * La pièce porte quarante colonnes ; l'écran en montre douze et n'en filtre que quatre.
 * Ce sont celles qu'on cherche réellement — une section, un mode de règlement, un numéro
 * de chèque, une tranche de montants — et qui obligeaient jusqu'ici à ouvrir le classeur.
 */
$colonnesFiltrables = computed(fn () => [
    'factures_fournisseurs.fournisseur' => FiltreLibre::colonne('Fournisseur'),
    'factures_fournisseurs.numero_piece' => FiltreLibre::colonne('N° de pièce'),
    'factures_fournisseurs.numero_bc' => FiltreLibre::colonne('N° de bon de commande'),
    'factures_fournisseurs.section' => FiltreLibre::colonne('Section'),
    'factures_fournisseurs.type_transaction' => FiltreLibre::colonne('Type de transaction'),
    'factures_fournisseurs.mode_reglement' => FiltreLibre::colonne('Mode de règlement'),
    'factures_fournisseurs.numero_cheque' => FiltreLibre::colonne('N° de chèque'),
    'factures_fournisseurs.delai_reglement' => FiltreLibre::colonne('Délai de règlement'),
    'factures_fournisseurs.imputation' => FiltreLibre::colonne('Imputation'),
    'factures_fournisseurs.vehicule' => FiltreLibre::colonne('Véhicule'),
    'factures_fournisseurs.immatriculation' => FiltreLibre::colonne('Immatriculation'),
    'factures_fournisseurs.commentaires' => FiltreLibre::colonne('Commentaires'),
    'factures_fournisseurs.actions_a_mener' => FiltreLibre::colonne('Actions à mener'),
    'factures_fournisseurs.montant' => FiltreLibre::colonne('Montant', 'nombre'),
    'factures_fournisseurs.reste_a_payer' => FiltreLibre::colonne('Reste à payer', 'nombre'),
    'factures_fournisseurs.date_reception' => FiltreLibre::colonne('Date de réception', 'date'),
    'factures_fournisseurs.date_reglement' => FiltreLibre::colonne('Date de règlement', 'date'),
    'factures_fournisseurs.date_echeance' => FiltreLibre::colonne('Date d’échéance', 'date'),
]);

/**
 * Les factures FNE de l'année qu'aucune ligne du suivi ne porte encore — 22/10.
 *
 * « Sur cette page, on doit avoir toutes les factures fournisseurs : suivies ou pas, venant de la
 * FNE ou pas. » Une pièce du suivi liée à sa FNE paraît une fois, par sa ligne du suivi ; celle que
 * personne n'a encore portée paraît ici, telle que le portail la donne. Elle ne dit ni ce qui en a
 * été réglé ni la ville : elle n'entre que sous « Toutes » (un filtre d'état n'aurait rien à y lire)
 * et paraît dans chaque périmètre, comme toute ligne dont on ne sait pas où elle est. Les « autres
 * filtres » visent des colonnes du classeur qu'elle n'a pas : posés, ils l'écartent.
 */
$fneHorsSuivi = computed(function () {
    // Une FNE hors suivi n'est, par définition, rapprochée de rien.
    if ($this->provenanceFiltre === 'suivi' || $this->etatFiltre !== 'toutes' || $this->rapprochementFiltre === 'rapprochees'
        || FiltreLibre::compter($this->colonnesFiltrables, (array) $this->filtresLibres) > 0
        || ! \App\Support\SchemaDisponible::table('factures_fne')) {
        return null;
    }

    $terme = trim($this->recherche);

    return FactureFne::query()->whereNull('facture_fournisseur_id')->where('exercice', $this->annee)
        ->when($terme !== '', fn ($q) => $q->where(fn ($sous) => $sous
            ->where('emetteur_nom', 'like', '%'.$terme.'%')
            ->orWhere('emetteur_ncc', 'like', '%'.$terme.'%')
            ->orWhere('reference', 'like', '%'.$terme.'%')));
});

/**
 * Le tableau « Factures reçues » : les pièces du suivi et les factures FNE pas encore au suivi, en
 * une suite paginée en base (`union all` sur la source, l'identifiant et la date), puis chaque ligne
 * relue dans son modèle — deux requêtes pour la page, jamais une par ligne.
 */
$detail = computed(function () {
    $parties = [];

    if ($this->provenanceFiltre !== 'fne') {
        $parties[] = (clone $this->requete)->reorder()->toBase()
            ->selectRaw("'suivi' as source, factures_fournisseurs.id as id, factures_fournisseurs.date_facture as date_tri");
    }

    if ($this->fneHorsSuivi !== null) {
        $parties[] = (clone $this->fneHorsSuivi)->toBase()
            ->selectRaw("'fne' as source, factures_fne.id as id, factures_fne.date_facture as date_tri");
    }

    if ($parties === []) {
        return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1);
    }

    $union = array_shift($parties);

    foreach ($parties as $partie) {
        $union->unionAll($partie);
    }

    // La plus vieille dette en tête : faute d'échéance dans le fichier, c'est
    // l'ancienneté de la facture qui dit ce qui presse.
    $page = \Illuminate\Support\Facades\DB::query()->fromSub($union, 'toutes')
        ->orderBy('date_tri')->orderByDesc('source')->orderBy('id')
        ->paginate(25, ['*'], 'pageDetail', $this->pageDetail);

    $ids = fn (string $source) => $page->getCollection()->where('source', $source)->pluck('id')->all();
    $pieces = FactureFournisseur::query()->with(['ville', 'recuePar'])
        ->when(\App\Support\SchemaDisponible::table('factures_fne'), fn ($q) => $q->with('factureFne:id,reference,facture_fournisseur_id'))
        ->whereIn('id', $ids('suivi'))->get()->keyBy('id');
    $fne = $ids('fne') === [] ? collect() : FactureFne::query()->whereIn('id', $ids('fne'))
        ->get(['id', 'reference', 'est_avoir', 'date_facture', 'emetteur_nom', 'emetteur_ncc', 'montant_ttc'])->keyBy('id');

    return $page->setCollection($page->getCollection()
        ->map(fn ($l) => $l->source === 'suivi' ? ($pieces[$l->id] ?? null) : ($fne[$l->id] ?? null))
        ->filter()->values());
});

/**
 * Les fiches des fournisseurs de la page affichée, en une requête.
 *
 * Elles servent à afficher une échéance là où le fichier n'en donne pas. Une requête par
 * ligne en aurait fait vingt-cinq : c'est la leçon de la page « Clients & tiers », tombée
 * à cinq secondes pour cette raison exacte.
 */
$fiches = computed(fn () => ConditionsFournisseur::pour(
    (int) auth()->user()->entreprise_id,
    array_values(array_filter($this->detail->items(), fn ($ligne) => $ligne instanceof FactureFournisseur)),
));

?>

<div>
    {{-- « Charges fournisseurs » depuis le 23/10 : la page s'ouvre depuis les Charges, et n'est plus au menu. --}}
    <x-titre-ecran titre="Charges fournisseurs"
        sous-titre="Ce que l'entreprise doit, à qui, et depuis combien de temps — repris du suivi fournisseurs et de la FNE.">
        {{-- Les deux exports du logiciel d'atelier s'ouvrent d'ici : c'est la page où l'on
             se pose la question, et c'est donc là que doivent être les réponses. --}}
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-top:10px;">
            <x-champ label="Année de l'état" model="exercice" type="select" :options="$this->exercices" :live="true" width="130" />
            {{-- Le pendant du « Tableau état initial » des impayés. Il manquait, et son
                 absence se remarquait : l'état par année ne montre jamais l'ensemble d'un
                 coup, et une pièce soldée d'une année passée n'y paraît nulle part. --}}
            <a href="{{ route('charges') }}" wire:navigate class="bouton bouton-secondaire">← Charges</a>
            <a href="{{ route('fournisseurs.tableau-initial') }}" wire:navigate class="bouton bouton-secondaire">Tableau initial</a>
            {{-- L'annuaire, demandé le 28/09 : cet écran liste des pièces, les conditions
                 listent des accords, aucun ne disait quels fournisseurs on connaît. --}}
            <a href="{{ route('fournisseurs.liste') }}" wire:navigate class="bouton bouton-secondaire">Liste des fournisseurs</a>
            {{-- « Référentiel » ne disait pas ce qu'on y trouve. Cette page porte le terme de
                 règlement d'un fournisseur et sa TVA — c'est de là que sort l'échéance
                 attendue d'une pièce que le classeur n'a pas datée. --}}
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance fournisseurs</a>
            {{-- Le grand livre : l'extrait de compte d'un fournisseur, comme au recouvrement (23/10). --}}
            <a href="{{ route('fournisseurs.grand-livre') }}" wire:navigate class="bouton bouton-secondaire">Grand livre fournisseur</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements fournisseurs</a>
            {{-- Les décaissements de caisse faits pour des fournisseurs, à suivre (23/10). --}}
            <a href="{{ route('fournisseurs.decaissements') }}" wire:navigate class="bouton bouton-secondaire">Décaissements fournisseurs (caisse)</a>
            {{-- Les achats relevés au portail FNE, 12/10 : ils confirment le suivi ou s'y portent. --}}
            <a href="{{ route('fournisseurs.fne') }}" wire:navigate class="bouton bouton-secondaire">Factures FNE</a>
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
                <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">{{ $porterFne ? 'Porter au suivi une facture FNE' : 'Ajouter une facture fournisseur' }}</h3>
                <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                    @if ($porterFne)
                        La facture devient une dette du suivi : rien n'est réglé. Elle reste liée à sa facture FNE.
                    @else
                        Pour une facture reçue entre deux dépôts. Le n° de pièce est celui du fournisseur ;
                        laissé vide, un numéro est attribué. Le reste à payer se déduit du montant et du réglé.
                    @endif
                </p>

                {{-- **L'essentiel d'abord — 20/10** : « les champs de saisie du suivi, c'est trop ; on garde
                     l'essentiel ». Les précisions du classeur sont rétablies le 22/10, repliées dessous. --}}
                @if (! $porterFne && $modeSaisie !== 'libre')
                    {{-- Les deux voies : choisir la facture FNE (ses informations se reportent), ou saisir. --}}
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:6px;">
                        <div style="flex:1 1 380px; max-width:620px;">
                            <x-select-cherchable id="ajout-fne" label="Facture FNE à suivre ({{ $this->annee }})" model="fneChoisie" :valeur="$fneChoisie"
                                :options="$this->fnesAPorter" vide="— choisir la facture FNE —" placeholder="N° FNE, fournisseur, date, montant…" :seuil="0" />
                            <div style="font-size:11.5px; color:#6B6E76; margin-top:3px;">{{ count($this->fnesAPorter) }} facture(s) FNE de l'année pas encore au suivi.</div>
                        </div>
                        <span style="font-size:12.5px; color:#6B6E76; padding-bottom:9px;">ou</span>
                        <button type="button" wire:click="choisirLaSaisie" class="bouton bouton-secondaire">Saisir une pièce</button>
                    </div>
                @endif

                @if ($porterFne || $modeSaisie === 'libre')
                @if ($suivreSortie)
                    <div style="padding:10px 12px; background:#FFFBEB; border:1px solid #FDE68A; border-radius:10px; margin-bottom:12px; font-size:13px;">
                        <b>Suivi d'un décaissement de caisse</b> — la pièce enregistrée sera <b>réglée par ce décaissement</b>,
                        et les deux lignes seront lettrées (<a href="{{ route('fournisseurs.decaissements', ['sortie' => $suivreSortie]) }}" wire:navigate>voir la sortie</a>).
                        Corrigez le fournisseur et ajoutez le n° de sa facture si vous l'avez.
                    </div>
                @endif
                <form wire:submit="enregistrer">
                    @if ($porterFne)
                        {{-- Ce que dit la facture certifiée, en premier et en lecture seule : le serveur
                             le relit de toute façon dans la facture FNE à l'enregistrement. --}}
                        <div style="padding:12px 14px; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:10px; margin-bottom:12px;">
                            <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
                                <b style="font-size:13px; color:#1E3A8A;">Repris de la facture FNE — non modifiable</b>
                                <span style="font-size:12.5px;">
                                    <a href="{{ route('fournisseurs.fne.detail', $porterFne) }}" wire:navigate>Détails de la facture</a>
                                    · <button type="button" wire:click="abandonnerLePortage" style="all:unset; cursor:pointer; color:#C8102E; text-decoration:underline;">Abandonner</button>
                                </span>
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:8px 16px; font-size:13px;">
                                @foreach ([
                                    'Fournisseur' => $fournisseur,
                                    'N° de facture FNE' => $numeroPiece,
                                    'Nature' => $naturePiece,
                                    'Date de facture' => $dateFacture ? \Illuminate\Support\Carbon::parse($dateFacture)->format('d/m/Y') : '—',
                                    'Montant HT' => $montantHt !== '' ? ae(montant_de($montantHt)) : '—',
                                    'TVA' => $tva !== '' ? ae(montant_de($tva)) : '—',
                                    'Montant TTC' => ae(montant_de($montant ?: 0)),
                                ] as $libelle => $valeur)
                                    <div><div style="font-size:11px; color:#6B6E76; text-transform:uppercase; letter-spacing:.04em;">{{ $libelle }}</div><b>{{ $valeur ?: '—' }}</b></div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                        @unless ($porterFne)
                            <x-champ label="Fournisseur" model="fournisseur" requis width="230" />
                            <x-champ label="N° de pièce" model="numeroPiece" placeholder="Celui du fournisseur" width="150" />
                            <x-champ label="Date de facture" model="dateFacture" type="date" requis width="155" />
                            <x-champ label="Montant TTC" model="montant" type="number" requis width="140" />
                            @unless ($suivreSortie)
                                <x-champ label="Déjà réglé" model="montantRegle" type="number" width="140" />
                            @endunless
                        @endunless
                        <x-champ label="Échéance" model="dateEcheance" type="date" width="155" />
                        <x-champ label="Mode de règlement" model="modeReglement" width="170" />
                        @if (count($this->villesOuSaisir) > 1)
                            <x-champ label="Ville" model="villeSaisie" type="select" live="true"
                                :options="$this->villesOuSaisir" vide="— à préciser —" width="165" />
                        @endif
                        {{-- L'atelier n'apparaît que là où il y a un choix à faire : Abidjan
                             en a deux, Bouaké et San-Pédro un seul. --}}
                        @if ($this->sitesOuSaisir !== [])
                            <x-champ label="Atelier" model="siteSaisie" type="select"
                                :options="$this->sitesOuSaisir" vide="— à préciser —" width="185" />
                        @endif
                        <x-champ label="Immatriculation" model="immatriculation" width="145" />
                        @unless ($porterFne)
                            <x-champ label="Nature" model="naturePiece" width="130" />
                        @endunless
                        <x-champ label="Imputation" model="imputation" width="170" />
                    </div>

                    <div style="margin-top:12px;">
                        <x-champ label="Observations" model="observations" type="textarea" />
                    </div>

                    {{-- **Les précisions du classeur, rétablies le 22/10** — sans le mois, le code pièce ni le
                         résultat indicatif, retirés à la demande du propriétaire. Repliées : une pièce reçue
                         entre deux dépôts se saisit toujours avec l'essentiel. --}}
                    <div style="margin-top:14px;">
                        <button type="button" class="bouton bouton-secondaire bouton-petit"
                            wire:click="$toggle('precisionsOuvertes')">
                            {{ $precisionsOuvertes ? '− Masquer' : '+ Ajouter' }} les précisions du classeur
                        </button>
                        <span style="font-size:12px; color:#6B6E76; margin-left:8px;">
                            Section, bon de commande, TVA, refacturation, quantités, n° FEB, n° de chèque —
                            facultatives, et reprises telles que le classeur les nomme.
                        </span>
                    </div>

                    <div x-show="$wire.precisionsOuvertes" @if (! $precisionsOuvertes) style="display:none;" @endif>
                        <div style="margin-top:12px;">
                            <p class="sous-titre" style="margin-top:0;">Le dossier</p>
                            <div class="bloc-saisie">
                                <x-champ label="Section" model="section" width="160" placeholder="Peinture, Carrosserie…" />
                                <x-champ label="N° BC" model="numeroBc" width="140" />
                                <x-champ label="Date de réception" model="dateReception" type="date" width="165" />
                                <x-champ label="Type de transaction / services" model="typeTransaction" width="220" />
                                <x-champ label="N° de fiche de réception" model="numeroFiche" width="180" placeholder="FR-…" />
                                <x-champ label="Véhicule" model="vehicule" width="170" />
                            </div>

                            <p class="sous-titre">Le règlement</p>
                            <div class="bloc-saisie">
                                <x-champ label="Date de règlement" model="dateReglement" type="date" width="165" />
                                <x-champ label="Délai de règlement" model="delaiReglement" width="165" placeholder="Comptant, 30 jours…" />
                                <x-champ label="N° de chèque" model="numeroCheque" width="150" />
                                <x-champ label="N° FEB" model="numeroFeb" width="130" />
                            </div>

                            <p class="sous-titre">Les montants</p>
                            <div class="bloc-saisie">
                                {{-- Le HT et la TVA d'une facture FNE portée sont ceux de la pièce certifiée,
                                     affichés plus haut et relus à l'enregistrement. --}}
                                @unless ($porterFne)
                                    <x-champ label="Montant HT" model="montantHt" type="number" width="140" />
                                    <x-champ label="TVA" model="tva" type="number" width="120" />
                                @endunless
                                <x-champ label="TVA 2" model="tva2" type="number" width="120" />
                                <x-champ label="Montant refacturé" model="montantRefacture" type="number" width="160" />
                                <x-champ label="Montant net achat" model="montantNetAchat" type="number" width="160" />
                                <x-champ label="Montant net vente" model="montantNetVente" type="number" width="160" />
                                <x-champ label="Qté totale" model="quantiteTotale" type="number" width="130" />
                                <x-champ label="Qté refacturée" model="quantiteRefacturee" type="number" width="145" />
                            </div>

                            <p class="sous-titre">La refacturation au client</p>
                            <div class="bloc-saisie">
                                @unless ($porterFne)
                                    <x-champ label="N° facture achat (FA)" model="numeroFactureAchat" width="175" />
                                @endunless
                                <x-champ label="N° facture vente (FV)" model="numeroFactureVente" width="175" />
                                <x-champ label="N° facture client" model="numeroFactureClient" width="165" />
                            </div>

                            <p class="sous-titre">Ce qu'on en dit</p>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:12px;">
                                <x-champ label="Observations sur la facturation client" model="observationsFacturation" type="textarea" />
                                <x-champ label="Commentaires" model="commentaires" type="textarea" />
                                <x-champ label="Actions à mener" model="actionsAMener" type="textarea" />
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <button type="submit" class="bouton">{{ $porterFne ? 'Enregistrer au suivi' : 'Enregistrer la pièce' }}</button>
                        <button type="button" wire:click="abandonnerLePortage" class="bouton bouton-secondaire">Annuler</button>
                    </div>
                </form>
                @endif
            </div>
        </div>
    @endif

    {{-- Les lignes du fichier mises de côté comme doublons à l'import, et le bouton qui les intègre — 14/10. --}}
    <livewire:commun.doublons-d-import :formats="['fournisseurs', 'reglements-fournisseurs']" :ids-sites="\Modules\Noyau\Entreprises\Modeles\Site::withoutGlobalScopes()->whereIn('ville_id', $this->idsVilles)->pluck('id')->map(fn ($id) => (int) $id)->all()" :ids-villes="$this->idsVilles"
        :debut="null" :fin="null"
        :wire:key="'doublons-fournisseurs-'.md5(json_encode([$this->idsVilles, null, null]))" />

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
            :sub="'Échéance passée, ou — sans échéance ni règlement — facture passée ('.$this->kpis['avecEcheance'].' pièce(s) ouverte(s) portent une échéance)'" />
        <x-kpi-card label="Reporté des années passées" :value="ae($this->kpis['resteReporte'])"
            :sub="$this->kpis['reportees'].' pièce(s) antérieure(s) encore due(s) — rien n\'est recopié'" />
        <x-kpi-card label="Facturé en {{ $this->annee }}" :value="ae($this->kpis['facture'])"
            :sub="$this->kpis['lignes'].' pièce(s) à l\'état de cette année'" />
        <x-kpi-card label="Déjà réglé" :value="ae($this->kpis['regle'])" couleur="#0E9F6E"
            :sub="$this->normalises['parLaCaisse'] != 0 ? 'dont par la caisse '.ae($this->normalises['parLaCaisse']) : null" />
    </div>

    {{-- Les fournisseurs normalisés — 20/10. --}}
    @php $n = $this->normalises; @endphp
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <a href="{{ route('fournisseurs.fne') }}" wire:navigate style="all:unset; display:block; cursor:pointer;" title="Ouvrir les factures FNE">
            <x-kpi-card label="Factures FNE reçues en {{ $this->annee }}" :value="ae($n['ttc'])" couleur="#7C3AED"
                :sub="number_format($n['nombre'], 0, ',', ' ').' facture(s) normalisée(s) — toute l\'entreprise ›'" />
        </a>
        <x-kpi-card label="FNE au suivi" :value="ae($n['auSuivi'])" couleur="#0E9F6E"
            :sub="number_format($n['nAuSuivi'], 0, ',', ' ').' facture(s) portée(s) ou rapprochée(s)'" />
        <x-kpi-card label="FNE pas encore au suivi" :value="ae($n['horsSuivi'])" :accent="$n['nHorsSuivi'] > 0"
            :sub="number_format($n['nHorsSuivi'], 0, ',', ' ').' facture(s) à porter'" />
        <x-kpi-card label="Suivi normalisé — {{ $this->libellePerimetre }}" :value="ae($n['suiviNormalise'])"
            :sub="'Pièces du suivi liées à une facture FNE · non normalisé '.ae(montant_de($this->kpis['facture'] - $n['suiviNormalise']))" />
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

    {{-- **Un seul cadre, deux boutons — 22/10** : « Factures reçues » et « À qui nous devons le plus »
         étaient deux tableaux superposés ; le bouton choisi décide de ce que montre le cadre. --}}
    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <button type="button" wire:click="montrer('factures')" class="bouton {{ $vueTableau === 'factures' ? '' : 'bouton-secondaire' }}" style="padding:7px 12px;">
                    Factures reçues ({{ montant_nombre($this->detail->total()) }})
                </button>
                <button type="button" wire:click="montrer('principaux')" class="bouton {{ $vueTableau === 'principaux' ? '' : 'bouton-secondaire' }}" style="padding:7px 12px;">
                    À qui nous devons le plus
                </button>
            </div>

            @if ($vueTableau === 'factures')
            <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center;">
{{-- **`width:auto` n'est pas un détail de style, c'est la correction du 29/09** :
                     « les deux filtres sont longs pour rien, aligne-les sur la ligne des boutons
                     PDF et Word ». La classe `.champ` porte `width:100%` — elle est faite pour un
                     formulaire en colonnes, où un champ occupe sa ligne. Posée dans une rangée en
                     flex, elle demande à chaque champ la largeur entière de la rangée : la
                     recherche prenait une ligne, le sélecteur une autre, et les boutons se
                     retrouvaient rejetés trois lignes plus bas. Le reste de la maison écrit déjà
                     `width:auto` sur ses filtres en rangée (voir `x-filtre-periode`) ; ces deux-là
                     l'avaient oublié. --}}
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Fournisseur, n° de pièce…" class="champ" style="width:auto; min-width:0; flex:0 1 230px;">

                <select wire:model.live="etatFiltre" class="champ" style="width:auto;">
                    <option value="ouvertes" @selected($etatFiltre === 'ouvertes')>Reste à payer</option>
                    <option value="anciennes" @selected($etatFiltre === 'anciennes')>Dues depuis plus de 90 jours</option>
                    <option value="echues" @selected($etatFiltre === 'echues')>Échues</option>
                    <option value="soldees" @selected($etatFiltre === 'soldees')>Soldées</option>
                    <option value="toutes" @selected($etatFiltre === 'toutes')>Toutes</option>
                </select>

                <select wire:model.live="rapprochementFiltre" class="champ" style="width:auto;" aria-label="Rapprochement FNE">
                    <option value="" @selected($rapprochementFiltre === '')>Rapprochées et non rapprochées</option>
                    <option value="rapprochees" @selected($rapprochementFiltre === 'rapprochees')>Rapprochées de la FNE ✔</option>
                    <option value="non" @selected($rapprochementFiltre === 'non')>Non rapprochées ✘</option>
                </select>

                <select wire:model.live="provenanceFiltre" class="champ" style="width:auto;" aria-label="Provenance">
                    <option value="toutes" @selected($provenanceFiltre === 'toutes')>Suivi et FNE</option>
                    <option value="suivi" @selected($provenanceFiltre === 'suivi')>Suivi fournisseurs seul</option>
                    <option value="fne" @selected($provenanceFiltre === 'fne')>FNE pas encore au suivi</option>
                </select>

                {{-- Les dix-huit colonnes des quarante qu'aucun filtre ne couvre : la section,
                     le mode de règlement, le n° de chèque, l'imputation, les tranches de
                     montants et de dates. Demandé le 28/09. --}}
                <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />

                {{-- Les filtres voyagent dans l'adresse du lien : le fichier emporté contient
                     exactement ce que le tableau montre, et le lien se transmet tel quel.
                     Les filtres libres, eux, n'y sont pas — ils restent à l'écran. --}}
                <x-telecharger route="fournisseurs.telecharger"
                    :parametres="['exercice' => $this->annee, 'ville' => $villeFiltre, 'etat' => $etatFiltre, 'recherche' => $recherche]" />
            </div>
            @endif
        </div>

        @if ($vueTableau === 'principaux')
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
                                {{ ae(montant_de($f->regle)) }}
                                @if ($f->engage > 0)
                                    {{-- La part réglée dit d'un coup d'œil si le compte tourne
                                         ou s'il s'installe. --}}
                                    <div style="font-size:11px; color:#6B6E76;">{{ round($f->regle / $f->engage * 100) }} % de l'engagé</div>
                                @endif
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae(montant_de($f->du)) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                {{ $this->kpis['reste'] > 0 ? round($f->du / $this->kpis['reste'] * 100) : 0 }} %
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($this->principaux->isEmpty())
            <p style="margin:10px 0 0; font-size:12.5px; color:#6B6E76;">Aucune dette ouverte pour cette année et ce périmètre.</p>
        @endif
        @else
            @if ($etatFiltre !== 'toutes' && $provenanceFiltre !== 'suivi')
                <p style="margin:-6px 0 10px; font-size:12px; color:#6B6E76;">
                    Les factures FNE pas encore au suivi ne paraissent que sous « Toutes » : le portail ne dit pas ce qui en a été réglé.
                </p>
            @endif

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Fournisseur</th>
                        <th>N° pièce</th>
                        {{-- 26/10 : « une colonne qui dit si c'est rapproché ou pas, et un lien vers son pareil ». --}}
                        <th>FNE</th>
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
                        @if ($ligne instanceof FactureFne)
                            {{-- Une facture FNE qu'aucune ligne du suivi ne porte encore : telle que le
                                 portail la donne, sans règlement connu. --}}
                            <tr wire:key="fne-{{ $ligne->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8); background:#FAF7FF;">
                                <td>
                                    {{ $ligne->emetteur_nom ?: '—' }}
                                    <div style="font-size:11px; color:#7C3AED;">FNE — pas encore au suivi</div>
                                </td>
                                <td style="color:#6B6E76;">{{ $ligne->reference }}@if ($ligne->est_avoir) <span style="color:#C8102E; font-weight:700;">avoir</span>@endif</td>
                                <td style="white-space:nowrap;"><span style="color:#C8102E; font-weight:800;" title="Aucune ligne du suivi ne porte ce n°">✘</span></td>
                                <td style="color:#6B6E76;">—</td>
                                <td style="white-space:nowrap;">{{ $ligne->date_facture?->format('d/m/Y') ?? '—' }}</td>
                                <td style="color:#6B6E76;">—</td>
                                <td style="color:#6B6E76;">—</td>
                                <td style="color:#6B6E76;">—</td>
                                <td style="color:#6B6E76; font-size:11px;">NCC {{ $ligne->emetteur_ncc ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae(montant_de($ligne->montant_ttc)) }}</td>
                                <td style="text-align:right; color:#9A9DA5;">—</td>
                                <td style="text-align:right; color:#9A9DA5; font-size:11.5px;">non suivie</td>
                                <td class="colonne-collee" style="text-align:right; white-space:nowrap;">
                                    @if ($this->peutEcrire)
                                        <a href="{{ route('fournisseurs', ['porter' => $ligne->id]) }}" wire:navigate
                                            class="bouton" style="padding:4px 10px; font-size:12px; text-decoration:none;">Porter</a>
                                    @endif
                                    <a href="{{ route('fournisseurs.fne.detail', $ligne->id) }}" wire:navigate
                                        class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px; text-decoration:none;">Détails</a>
                                </td>
                            </tr>
                            @continue
                        @endif
                        @php
                            $jours = $ligne->date_facture
                                ? NombreDeJours::entre($ligne->date_facture, now())
                                : null;
                            $vieille = $ligne->reste_a_payer > 0 && $jours !== null && $jours > 90;
                            $echue = $ligne->reste_a_payer > 0 && ($ligne->date_echeance
                                ? $ligne->date_echeance->isPast()
                                : ($ligne->date_reglement === null && $ligne->date_facture?->isPast()));
                            /* L'échéance attendue, quand le fichier n'en porte pas : elle
                               vient du terme du fournisseur et se dit « attendue ». Elle
                               n'entre pas dans le filtre « Échues » ni dans le KPI de
                               l'échu — une relance se fonde sur ce que le fournisseur a
                               écrit, pas sur ce que nous avons calculé pour lui. */
                            $echeance = ConditionsFournisseur::echeance(
                                $ligne,
                                ConditionsFournisseur::fiche($this->fiches, $ligne),
                            );
                            // 27/10 : « pour ceux qui sont rapprochés, mets la ligne en vert ». En style
                            // écrit sur la ligne, pour passer devant l'alternance des lignes du tableau.
                            $rapprochee = $ligne->relationLoaded('factureFne') && $ligne->factureFne;
                            $fondRapprochee = $rapprochee ? ' background:#E7F6EE;' : '';
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);{{ $fondRapprochee }}{{ $rapprochee ? ' box-shadow:inset 3px 0 0 #0E9F6E;' : '' }}"
                            @if ($rapprochee) title="Rapprochée de sa facture FNE" @endif>
                            <td>
                                {{ $ligne->fournisseur ?: '—' }}
                                @if ($ligne->recuePar)
                                    {{-- La ville a commandé, une autre a reçu la facture (13/10). --}}
                                    <div style="font-size:11px; color:#9A9DA5;">Reçue par {{ $ligne->recuePar->nom }}</div>
                                @endif
                            </td>
                            <td style="color:#6B6E76;">
                                {{ $ligne->numero_piece ?: '—' }}
                                @if ($ligne->lot_import_id === null)
                                    {{-- Saisie à la main : le dire évite de la chercher dans un
                                         fichier où elle n'est pas. --}}
                                    <div style="font-size:11px; color:#9A9DA5;">saisie</div>
                                @endif
                            </td>
                            <td style="white-space:nowrap; font-size:12px;">
                                @if ($ligne->relationLoaded('factureFne') && $ligne->factureFne)
                                    <span style="color:#0E9F6E; font-weight:800;">✔</span>
                                    <a href="{{ route('fournisseurs.fne.detail', $ligne->factureFne->id) }}" wire:navigate title="La facture FNE rapprochée">{{ $ligne->factureFne->reference }}</a>
                                @else
                                    <span style="color:#C8102E; font-weight:800;" title="Aucune facture FNE ne porte ce n° de pièce">✘</span>
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
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae(montant_de($ligne->montant)) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ ae(montant_de($ligne->montant_regle)) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne->reste_a_payer > 0 ? '#C8102E' : '#6B6E76' }};">
                                {{ ae(montant_de($ligne->reste_a_payer)) }}
                            </td>
                            <td class="colonne-collee" style="text-align:right;{{ $fondRapprochee }}">
                                <a href="{{ route('fournisseurs.piece', $ligne) }}" wire:navigate
                                    class="bouton bouton-secondaire" style="padding:4px 10px; font-size:12px; text-decoration:none;">Détail</a>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="13"
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
        @endif
    </div>
</div>

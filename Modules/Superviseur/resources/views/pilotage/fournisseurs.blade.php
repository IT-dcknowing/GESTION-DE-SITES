<?php

use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\ConditionsFournisseur;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
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
    'etatFiltre' => 'ouvertes',
    'recherche' => '',
    'pageDetail' => 1,
    /*
     * Les filtres posés sur les colonnes sans filtre propre — voir `FiltreLibre` et le
     * composant `x-autre-filtre`. Hors de l'adresse : un tableau de tableaux ne se
     * sérialise pas lisiblement dans une URL, pour un gain nul.
     */
    'filtresLibres' => [],
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
 *
 * **Depuis le 20/10, le formulaire ne les montre plus** (« c'est trop, on garde l'essentiel »).
 * L'état et l'enregistrement les gardent : le portage FNE y écrit le HT, la TVA et le n° de
 * facture d'achat, et elles restent reprises du classeur à l'import.
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


/*
 * La facture FNE qu'on est en train de porter au suivi — 18/10 : « le bouton Porter doit faire
 * apparaître les informations dans le formulaire du suivi, sur cette page ». Elle préremplit le
 * formulaire ; c'est l'enregistrement qui crée la ligne et la relie à la FNE. Rien n'est réglé.
 */
state(['porterFne' => null]);
// L'adresse `?porter=<id>` que donne le bouton « Porter au suivi » de la page des factures FNE.
state(['porter' => null])->url(except: null);

mount(function () {
    $this->exercice ??= EtatDesFournisseurs::exerciceOuvert(auth()->user()->entreprise_id);
    $this->dateFacture = $this->dateFacture ?: now()->format('Y-m-d');
    $this->villeSaisie = $this->villeSaisie ?: (string) (auth()->user()->ville_id ?? '');

    $aPorter = (int) ($this->porter ?? 0);
    $this->porter = null;

    if ($aPorter > 0 && EtatDesFournisseurs::peutEcrire(auth()->user()) && \App\Support\SchemaDisponible::table('factures_fne')) {
        $fne = \Modules\Noyau\Imports\Modeles\FactureFne::query()->whereNull('facture_fournisseur_id')->find($aPorter);

        if ($fne !== null) {
            $this->porterFne = $fne->id;
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

$abandonnerLePortage = function () {
    $this->porterFne = null;
    foreach (['fournisseur', 'numeroPiece', 'naturePiece', 'montant', 'montantRegle', 'montantHt', 'tva', 'numeroFactureAchat', 'modeReglement', 'observations'] as $champ) {
        $this->{$champ} = '';
    }
    $this->dateFacture = now()->format('Y-m-d');
    $this->formulaireOuvert = false;
};

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

    /*
     * Les colonnes des quarante du classeur qu'aucun filtre du haut ne couvre. Posées
     * avant le filtre d'état, puisque celui-ci clôt la requête par un `match`.
     */
    FiltreLibre::appliquer($requete, $this->colonnesFiltrables, (array) $this->filtresLibres);

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
        'resultatIndicatif' => ['nullable', 'string', 'max:120'],
        'observationsFacturation' => ['nullable', 'string', 'max:2000'],
        'commentaires' => ['nullable', 'string', 'max:2000'],
        'actionsAMener' => ['nullable', 'string', 'max:2000'],
    ], [
        'dateEcheance.after_or_equal' => "L'échéance ne peut pas précéder la facture.",
    ]);

    $entrepriseId = (int) auth()->user()->entreprise_id;
    $montant = montant_de($donnees['montant']);
    $regle = montant_de($donnees['montantRegle'] ?: 0);

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
        'resultat_indicatif' => $donnees['resultatIndicatif'] ?: null,
        'observations_facturation' => $donnees['observationsFacturation'] ?: null,
        'commentaires' => $donnees['commentaires'] ?: null,
        'actions_a_mener' => $donnees['actionsAMener'] ?: null,
    ]);

    activity()->performedOn($piece)->causedBy(auth()->user())
        ->log($fne !== null ? 'Facture FNE portée au suivi' : 'Pièce fournisseur saisie à la main');

    // Le lien qui fait que la facture ne compte qu'une fois : par la ligne du suivi.
    if ($fne !== null) {
        $fne->forceFill(['facture_fournisseur_id' => $piece->id])->save();
        $this->porterFne = null;
    }

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

$detail = computed(fn () => (clone $this->requete)
    ->with(['ville', 'recuePar'])
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
            {{-- L'annuaire, demandé le 28/09 : cet écran liste des pièces, les conditions
                 listent des accords, aucun ne disait quels fournisseurs on connaît. --}}
            <a href="{{ route('fournisseurs.liste') }}" wire:navigate class="bouton bouton-secondaire">Liste des fournisseurs</a>
            {{-- « Référentiel » ne disait pas ce qu'on y trouve. Cette page porte le terme de
                 règlement d'un fournisseur et sa TVA — c'est de là que sort l'échéance
                 attendue d'une pièce que le classeur n'a pas datée. --}}
            <a href="{{ route('balance-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Balance fournisseurs</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements fournisseurs</a>
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

                {{-- **L'essentiel seulement — 20/10** : « les champs de saisie du suivi, c'est trop ; on garde
                     l'essentiel ». Les colonnes du classeur (section, BC, quantités, refacturation…) ne se
                     saisissent plus ici ; elles restent en base, reprises du fichier, et se lisent sur la
                     page de la pièce. --}}
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
                            <x-champ label="Déjà réglé" model="montantRegle" type="number" width="140" />
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
                    </div>

                    <div style="margin-top:12px;">
                        <x-champ label="Observations" model="observations" type="textarea" />
                    </div>

                    <div style="margin-top:14px;">
                        <button type="submit" class="bouton">{{ $porterFne ? 'Enregistrer au suivi' : 'Enregistrer la pièce' }}</button>
                    </div>
                </form>
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
        </div>
    @endif

    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                Factures reçues ({{ montant_nombre($this->detail->total()) }})
            </h3>

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
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
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

<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\VentilationActivite;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use function Livewire\Volt\{state, computed, mount};

state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'villeFiltre' => '',
    'siteFiltre' => '',
    'activiteFiltre' => '',
    'pageDetail' => 1,
    /*
     * Les filtres posés sur les colonnes sans filtre propre — voir `FiltreLibre` et le
     * composant `x-autre-filtre`. Hors de l'adresse : un tableau de tableaux ne se
     * sérialise pas lisiblement dans une URL, pour un gain nul.
     */
    'filtresLibres' => [],
    // Le tableau de toutes les charges (18/10) : provenance, genre, recherche.
    'provenanceFiltre' => '',
    'typeFiltre' => '',
    'rechercheCharges' => '',
]);

/*
 * La reprise de sorties du journal de caisse — demandée le 07/10 : « pour certaines charges,
 * fais en sorte qu'on puisse les récupérer sur la caisse et les mettre dans les charges ».
 * Repliée tant qu'on ne la demande pas. **Certaines** : on choisit ligne à ligne — une sortie
 * de caisse n'est pas toujours une charge (un versement en banque, un transfert vers un
 * autre site), et les reprendre toutes d'office gonflerait les charges de ce qui n'en est pas.
 */
state([
    'repriseOuverte' => false,
    'repriseRecherche' => '',
    'repriseChoix' => [],
    'repriseLibelle' => 'Achats pièces',
    'repriseSiteId' => '',
    'pageReprise' => 1,
]);

/*
 * **Les charges selon la caisse — 14/10.** « Il n'y a aucun détail, la carte n'est même pas
 * cliquable, et on n'a pas de tableau. Au tableau, un bouton "Charges selon la caisse" qui montre
 * les lignes ; la carte cliquée donne le détail par catégorie, en se basant sur le libellé. »
 * Tout se lit dans le journal de caisse, sans rien écrire : les catégories viennent de
 * `CategorieDeSortie`, et une sortie ne devient une charge que reprise.
 */
state([
    'categoriesOuvertes' => false,
    'vueTableau' => 'charges',
    'categorieCaisse' => '',
    'pageCaisse' => 1,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; };
/** Changer de ville rend caduc le lieu choisi dans la précédente. */
$updatedVilleFiltre = function () { $this->siteFiltre = ''; };
$updatedRepriseRecherche = function () { $this->pageReprise = 1; };
$updatedCategorieCaisse = function () { $this->pageCaisse = 1; };
$updatedProvenanceFiltre = function () { $this->pageDetail = 1; };
$updatedTypeFiltre = function () { $this->pageDetail = 1; };
$updatedRechercheCharges = function () { $this->pageDetail = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

$requeteCa = computed(function () {
    [$debut, $fin] = $this->plage;

    // Le CA vrai (fichier du CA ou saisie), placé par l'atelier sinon la ville — 12/10.
    // Il s'écrivait `whereIn('site_id', …)` : une facture sans atelier n'entrait dans aucun
    // résultat net (piège n° 1).
    return EtatDesImpayes::dansLePerimetre(Facture::query()->duChiffreDAffaires(), $this->idsSites, EtatDesImpayes::villesDesSites($this->idsSites))
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$caPeriode = computed(fn () => montant_de((clone $this->requeteCa)->sum('montant')));

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * **Ce qu'est une charge — 20/10.** « Toutes les sorties de caisse sont des charges » ; les
 * fournisseurs « ne font que retracer, c'est juste un suivi ». Les charges de la page sont donc
 * les sorties du journal de caisse (reprises ou non) et les charges saisies qui ne viennent pas
 * de la caisse — règle écrite une fois, dans `ChargesDeLaPeriode`, et lue par le CA, l'accueil du
 * gérant et la synthèse par atelier. Le suivi fournisseurs se lit **à côté**, jamais additionné :
 * une facture payée en espèces compte une fois, par sa sortie de caisse.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
$charges = computed(function () {
    [$debut, $fin] = $this->plage;

    return new \Modules\Noyau\Exploitation\Services\ChargesDeLaPeriode(
        $this->idsSites, EtatDesImpayes::villesDesSites($this->idsSites), $debut, $fin, (string) $this->activiteFiltre,
    );
});

$totauxCharges = computed(fn () => $this->charges->totaux());

/** Chaque charge, avec sa nature : lue une fois pour les cartes et le graphique. */
$lignesCharges = computed(fn () => $this->charges->lignes());

/** Le suivi fournisseurs de la période, pour la carte « à côté » et le genre « fournisseurs » du tableau. */
$chargesFournisseurs = computed(function () {
    [$debut, $fin] = $this->plage;

    return new \Modules\Noyau\Exploitation\Services\ChargesFournisseurs(
        (int) auth()->user()->entreprise_id, $this->idsSites, EtatDesImpayes::villesDesSites($this->idsSites), $debut, $fin,
    );
});

$fournisseurs = computed(fn () => $this->chargesFournisseurs->totaux());

/*
 * **Les charges source par source — 22/10.** « On a la caisse, le suivi des fournisseurs, la FNE et
 * la banque. » La caisse se partage par son libellé en charges administratives et charges
 * fournisseurs ; le suivi, la FNE et la banque se lisent tels qu'ils sont, sur la période et le
 * filtre — sans lettrage, donc sans s'additionner. Voir `ChargesParSource`.
 */
$parSource = computed(function () {
    [$debut, $fin] = $this->plage;

    return \Modules\Noyau\Exploitation\Services\ChargesParSource::pourLesSites(
        (int) auth()->user()->entreprise_id, $this->idsSites, $debut, $fin, (string) $this->activiteFiltre,
    )->tout();
});

$origines = computed(function () {
    $t = $this->totauxCharges;
    $importees = montant_de((clone $this->charges->saisies())->whereNotNull('charges.lot_import_id')->sum('charges.montant'));

    return [
        'total' => $t['total'],
        'caisse' => $t['caisse'],
        'nCaisse' => $t['nCaisse'],
        'saisies' => montant_de($t['saisies'] - $importees),
        'importees' => $importees,
        'nSaisies' => $t['nSaisies'],
    ];
});

/** Ce que dit le journal de caisse sur la période : ses sorties, et ce qui en a été repris. */
$selonLaCaisse = computed(function () {
    [$debut, $fin] = $this->plage;

    $ligne = PerimetreDeTresorerie::mouvementsDeCaisse(
        MouvementCaisse::query(), $this->idsSites, EtatDesImpayes::villesDesSites($this->idsSites),
    )
        ->where('mouvements_caisse.sens', MouvementCaisse::SORTIE)
        ->whereBetween('mouvements_caisse.date', [$debut, $fin])
        ->selectRaw('count(*) as nombre, coalesce(sum(mouvements_caisse.montant), 0) as montant')
        ->toBase()->first();

    return ['sorties' => montant_de($ligne->montant ?? 0), 'nombre' => (int) ($ligne->nombre ?? 0)];
});

/**
 * Les sorties du journal de la période et du périmètre, chacune avec sa catégorie — lues seulement
 * quand on les regarde (carte ouverte ou tableau « selon la caisse »).
 *
 * La catégorie se calcule en PHP : c'est une lecture de texte (`CategorieDeSortie`), que SQL ne sait
 * pas faire sans dupliquer la règle. Le volume est celui d'une période — 1 656 sorties sur l'exercice
 * 2026 à la capture du 14/10 —, et seules les colonnes utiles sont lues.
 */
$sortiesClassees = computed(function () {
    if (! $this->categoriesOuvertes && $this->vueTableau !== 'caisse') {
        return collect();
    }

    [$debut, $fin] = $this->plage;
    $reprise = \App\Support\SchemaDisponible::colonne('charges', 'mouvement_caisse_id');

    return PerimetreDeTresorerie::mouvementsDeCaisse(
        MouvementCaisse::query(), $this->idsSites, EtatDesImpayes::villesDesSites($this->idsSites),
    )
        ->where('mouvements_caisse.sens', MouvementCaisse::SORTIE)
        ->whereBetween('mouvements_caisse.date', [$debut, $fin])
        ->with(['site:id,nom', 'ville:id,nom'])
        ->select(['mouvements_caisse.id', 'mouvements_caisse.date', 'mouvements_caisse.libelle', 'mouvements_caisse.motif',
            'mouvements_caisse.beneficiaire', 'mouvements_caisse.numero_piece', 'mouvements_caisse.montant',
            'mouvements_caisse.site_id', 'mouvements_caisse.ville_id'])
        ->when($reprise, fn ($q) => $q->selectSub(fn ($sous) => $sous->from('charges')->selectRaw('count(*)')
            ->whereColumn('charges.mouvement_caisse_id', 'mouvements_caisse.id'), 'reprises'))
        ->orderByDesc('mouvements_caisse.date')->orderByDesc('mouvements_caisse.id')
        ->get()
        ->each(fn (MouvementCaisse $m) => $m->setAttribute('categorie',
            \Modules\Noyau\Exploitation\Services\CategorieDeSortie::de($m->libelle, $m->motif, $m->beneficiaire)));
});

/** Le détail de la carte : par catégorie, combien, pour quel montant, et ce qui en est déjà repris. */
$categoriesDeCaisse = computed(function () {
    $sorties = $this->sortiesClassees;
    $total = montant_de($sorties->sum('montant'));

    return $sorties->groupBy('categorie')
        ->map(fn ($lignes, $categorie) => [
            'categorie' => $categorie,
            'nombre' => $lignes->count(),
            'montant' => montant_de($lignes->sum('montant')),
            'part' => $total > 0 ? $lignes->sum('montant') / $total : null,
            'repris' => montant_de($lignes->filter(fn ($m) => (int) ($m->reprises ?? 0) > 0)->sum('montant')),
            'charge' => \Modules\Noyau\Exploitation\Services\CategorieDeSortie::estUneCharge($categorie),
            // Les libellés qui pèsent le plus : ce qu'on vérifie d'un coup d'œil avant de reprendre.
            'libelles' => $lignes->groupBy(fn ($m) => trim((string) $m->libelle))
                ->map(fn ($g) => montant_de($g->sum('montant')))->sortDesc()->take(3)->all(),
        ])
        ->sortByDesc('montant')
        ->values();
});

$sortiesAffichees = computed(fn () => $this->categorieCaisse === ''
    ? $this->sortiesClassees
    : $this->sortiesClassees->where('categorie', $this->categorieCaisse)->values());

$ouvrirLesCategories = function () {
    $this->categoriesOuvertes = ! $this->categoriesOuvertes;
};

/** Montrer au tableau les lignes de la caisse — d'une catégorie, ou toutes. */
$voirLaCaisse = function (string $categorie = '') {
    $this->vueTableau = 'caisse';
    $this->categorieCaisse = in_array($categorie, \Modules\Noyau\Exploitation\Services\CategorieDeSortie::toutes(), true) ? $categorie : '';
    $this->pageCaisse = 1;
};

/** Le rang dans `CategorieDeSortie::toutes()` plutôt que le nom : un nombre passe sans échappement. */
$voirLaCategorie = function (int $rang) {
    $this->voirLaCaisse(\Modules\Noyau\Exploitation\Services\CategorieDeSortie::toutes()[$rang] ?? '');
};

$voirLesCharges = function () {
    $this->vueTableau = 'charges';
};

$kpis = computed(function () {
    $lignes = $this->lignesCharges;
    $total = $this->totauxCharges['total'];
    $deNature = fn (string $n) => $lignes->where('nature', $n);

    return [
        'total' => $total,
        'totalVentile' => $this->totauxCharges['ventile'],
        'caisse' => $this->totauxCharges['caisse'],
        'pieces' => montant_de($deNature('Achats pièces')->sum('montant')),
        'piecesVentile' => VentilationActivite::repartirCollection($deNature('Achats pièces')),
        'salaires' => montant_de($deNature('Salaires & personnel')->sum('montant')),
        'salairesVentile' => VentilationActivite::repartirCollection($deNature('Salaires & personnel')),
        'fonctionnement' => montant_de($deNature('Fonctionnement')->sum('montant')),
        'fonctionnementVentile' => VentilationActivite::repartirCollection($deNature('Fonctionnement')),
        'autres' => montant_de($deNature('Autres décaissements')->sum('montant')),
        'autresVentile' => VentilationActivite::repartirCollection($deNature('Autres décaissements')),
        'resultat' => montant_de($this->caPeriode - $total),
        'resultatVentile' => VentilationActivite::difference(
            VentilationActivite::repartir($this->requeteCa),
            $this->totauxCharges['ventile'],
        ),
    ];
});

/*
 * Le graphique lit les mêmes lignes que les cartes (`lignesCharges`) : saisies et sorties de
 * caisse, par nature. Il ne lisait que la table des charges — vide en ligne, où tout est dans le
 * journal : « les graphes ne disent rien du tout » (20/10). Une seule lecture, classée par point.
 */
$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);
    $natures = ['Achats pièces' => '#191B20', 'Salaires & personnel' => '#2563EB', 'Fonctionnement' => '#D97706', 'Autres décaissements' => '#9A9DA5'];

    $labels = [];
    $series = array_fill_keys(array_keys($natures), []);
    $lignes = $this->lignesCharges;

    foreach ($points as $point) {
        $debutDuPoint = $point['debut']->toDateString();
        $finDuPoint = $point['fin']->toDateString();
        $duPoint = $lignes->filter(fn ($l) => $l->date >= $debutDuPoint && $l->date <= $finDuPoint);

        $labels[] = $point['label'];

        foreach ($natures as $nature => $couleur) {
            $series[$nature][] = montant_de($duPoint->where('nature', $nature)->sum('montant'));
        }
    }

    return [
        'labels' => $labels,
        'datasets' => collect($natures)->map(fn ($couleur, $nature) => ['label' => $nature, 'data' => $series[$nature], 'color' => $couleur])->values()->all(),
    ];
});


/**
 * Les colonnes du tableau qu'aucun filtre du haut ne couvre.
 *
 * Ne figurent pas ici celles qui en ont déjà un : la période, la ville, l'atelier et
 * l'activité.
 */
$colonnesFiltrables = computed(fn () => [
    'charges.libelle' => FiltreLibre::colonne("Libellé d'opération"),
    'charges.tiers' => FiltreLibre::colonne('Tiers'),
    'charges.moyen' => FiltreLibre::colonne('Moyen'),
    'charges.reference_origine' => FiltreLibre::colonne('Référence'),
    'charges.motif' => FiltreLibre::colonne('Motif'),
    'charges.montant' => FiltreLibre::colonne('Montant', 'nombre'),
]);

// ------------------------------------------------------------------ reprendre de la caisse

/** Reprendre engage l'entreprise : les rôles qui saisissent la caisse, comme sur l'écran Caisse. */
// Et seulement une fois la colonne du 07/10 en base : sans elle, une reprise ne saurait pas
// dire de quelle sortie elle vient.
$peutReprendre = computed(fn () => auth()->user()?->hasAnyRole(['gerant', 'responsable_ville', 'caissier']) === true
    && \App\Support\SchemaDisponible::colonne('charges', 'mouvement_caisse_id'));

/**
 * Les sorties du journal de caisse de la période et du périmètre, **pas encore reprises**.
 *
 * Le périmètre est celui de la caisse (`PerimetreDeTresorerie::mouvementsDeCaisse`) :
 * l'atelier s'il est connu, sinon la ville — le journal d'Abidjan ne dit presque jamais
 * l'atelier. Une sortie déjà reprise disparaît de la liste : l'index unique la refuserait de
 * toute façon, mais la proposer encore laisserait croire qu'elle manque aux charges.
 */
$sortiesDeCaisse = computed(function () {
    [$debut, $fin] = $this->plage;
    $terme = trim($this->repriseRecherche);

    return PerimetreDeTresorerie::mouvementsDeCaisse(
        MouvementCaisse::query(), $this->idsSites, EtatDesImpayes::villesDesSites($this->idsSites),
    )
        ->where('mouvements_caisse.sens', MouvementCaisse::SORTIE)
        ->whereBetween('mouvements_caisse.date', [$debut, $fin])
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('charges')
            ->whereColumn('charges.mouvement_caisse_id', 'mouvements_caisse.id'))
        ->when($terme !== '', fn ($q) => $q->where(fn ($sous) => $sous
            ->where('mouvements_caisse.libelle', 'like', '%'.$terme.'%')
            ->orWhere('mouvements_caisse.motif', 'like', '%'.$terme.'%')
            ->orWhere('mouvements_caisse.beneficiaire', 'like', '%'.$terme.'%')
            ->orWhere('mouvements_caisse.numero_piece', 'like', '%'.$terme.'%')));
});

$nombreSortiesDeCaisse = computed(fn () => $this->repriseOuverte ? (clone $this->sortiesDeCaisse)->count() : 0);

$pageSortiesDeCaisse = computed(fn () => ! $this->repriseOuverte ? collect() : (clone $this->sortiesDeCaisse)
    ->with(['site', 'ville'])
    ->orderByDesc('mouvements_caisse.date')->orderByDesc('mouvements_caisse.id')
    ->forPage($this->pageReprise, 15)->get());

/** Les ateliers où ranger une sortie qui n'en dit aucun : `charges.site_id` est obligatoire. */
$ateliersDeReprise = computed(fn () => PerimetreSites::sitesConsultables(auth()->user())
    ->whereIn('id', $this->idsSites)->pluck('nom', 'id')->all());

$libellesDeCharge = computed(fn () => Referentiel::options(Referentiel::LIBELLE_CHARGE));

$ouvrirLaReprise = function () {
    $this->repriseOuverte = ! $this->repriseOuverte;
    $this->repriseChoix = [];
    $this->pageReprise = 1;
    $this->repriseSiteId = isset($this->ateliersDeReprise[(int) auth()->user()->site_id])
        ? (string) auth()->user()->site_id : '';
    $this->resetErrorBag();
};

/**
 * Reprend les sorties cochées en charges.
 *
 * Chaque charge garde le lien vers sa sortie (`mouvement_caisse_id`) : c'est lui qui dit
 * « Caisse » dans la colonne Origine, et qui empêche les écrans lisant le journal et les
 * charges ensemble de la compter deux fois. Les sorties sont relues **sous le périmètre et
 * parmi celles non reprises** : un identifiant envoyé par le navigateur ne suffit pas à
 * écrire une charge.
 */
$reprendreDeLaCaisse = function () {
    abort_unless($this->peutReprendre, 403, 'La reprise des sorties de caisse est réservée à ceux qui tiennent la caisse.');

    $this->validate([
        'repriseChoix' => ['required', 'array', 'min:1'],
        'repriseLibelle' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys($this->libellesDeCharge))],
        'repriseSiteId' => ['nullable', \Illuminate\Validation\Rule::in(array_map('strval', array_keys($this->ateliersDeReprise)))],
    ], [
        'repriseChoix.required' => 'Cochez au moins une sortie à reprendre.',
    ], ['repriseLibelle' => "libellé d'opération", 'repriseSiteId' => 'atelier']);

    $ids = array_map('intval', (array) $this->repriseChoix);
    $sorties = (clone $this->sortiesDeCaisse)->whereIn('mouvements_caisse.id', $ids)->get();

    // L'atelier de la sortie s'il est dans le périmètre, sinon celui qu'on a choisi.
    $atelierDe = fn (MouvementCaisse $m) => $m->site_id && isset($this->ateliersDeReprise[$m->site_id])
        ? (int) $m->site_id
        : ($this->repriseSiteId !== '' ? (int) $this->repriseSiteId : null);

    if ($sorties->contains(fn ($m) => $atelierDe($m) === null)) {
        $this->addError('repriseSiteId', "Certaines sorties ne disent pas leur atelier : choisissez celui où les ranger.");

        return;
    }

    \Illuminate\Support\Facades\DB::transaction(function () use ($sorties, $atelierDe) {
        foreach ($sorties as $m) {
            Charge::create([
                'entreprise_id' => auth()->user()->entreprise_id,
                'site_id' => $atelierDe($m),
                'mouvement_caisse_id' => $m->id,
                'date' => $m->date,
                'type_operation' => 'Charges',
                'libelle' => $this->repriseLibelle,
                // Ce que le journal disait de la sortie, mot pour mot : c'est la trace.
                'motif' => trim(implode(' — ', array_filter([$m->libelle, $m->motif]))) ?: null,
                // Une caisse tient des espèces : le moyen n'est pas une question.
                'moyen' => 'Espèces',
                'montant' => montant_de($m->montant),
                'tiers' => $m->beneficiaire ?: null,
                'reference_origine' => $m->numero_piece ?: null,
                'observations' => 'Reprise du journal de caisse',
                'cree_par' => auth()->id(),
            ]);
        }
    });

    $nombre = $sorties->count();
    $this->repriseChoix = [];
    unset($this->sortiesDeCaisse, $this->nombreSortiesDeCaisse, $this->pageSortiesDeCaisse, $this->toutesLesCharges, $this->nombreCharges, $this->montantCharges, $this->pageCharges, $this->origines, $this->kpis, $this->graphique);

    $this->dispatch('annonce', ton: 'succes',
        texte: $nombre.' sortie(s) de caisse classée(s) — libellé et atelier retenus.');
};

/*
 * **Le tableau de toutes les charges — 18/10, revu le 20/10.** Les charges saisies et chaque
 * sortie du journal de caisse, en une requête paginée en base (`ChargesDeLaPeriode::tableau`),
 * avec un filtre de provenance. Le genre « Suivi fournisseurs » montre les pièces du suivi à la
 * place — à côté, jamais additionnées.
 *
 * Les « autres filtres » visent les colonnes des charges ; ceux qui ont un sens pour toute ligne
 * (libellé = nature, tiers, référence, montant) s'appliquent aussi au tableau entier.
 */
$toutesLesCharges = computed(function () {
    $saisies = FiltreLibre::appliquer($this->charges->saisies(), $this->colonnesFiltrables, (array) $this->filtresLibres);

    // Les charges comptées (saisies + sorties de caisse), ou — genre « fournisseurs » — le suivi, à côté.
    $requete = $this->charges->tableau($saisies, $this->chargesFournisseurs, $this->provenanceFiltre, $this->typeFiltre, $this->rechercheCharges);

    $communs = ['charges.libelle' => 'nature', 'charges.tiers' => 'tiers', 'charges.reference_origine' => 'reference', 'charges.montant' => 'montant'];
    $retenus = [];
    foreach ($communs as $colonne => $alias) {
        $valeur = $this->filtresLibres[FiltreLibre::alias($colonne)] ?? null;
        if (is_array($valeur)) {
            $retenus[$alias] = $valeur;
        }
    }

    return FiltreLibre::appliquer($requete, collect($communs)->mapWithKeys(fn ($alias, $colonne) => [$alias => $this->colonnesFiltrables[$colonne]])->all(), $retenus);
});

$nombreCharges = computed(fn () => (clone $this->toutesLesCharges)->count());

$montantCharges = computed(fn () => montant_de((clone $this->toutesLesCharges)->sum('montant')));

$pageCharges = computed(function () {
    $lignes = (clone $this->toutesLesCharges)->orderByDesc('date')->orderByDesc('id')->forPage($this->pageDetail, 15)->get();

    // Les noms des lieux, lus une fois pour la page.
    $sites = \Modules\Noyau\Entreprises\Modeles\Site::query()->whereIn('id', $lignes->pluck('site_id')->filter()->unique())->pluck('nom', 'id');
    $villes = \Modules\Noyau\Entreprises\Modeles\Ville::query()->whereIn('id', $lignes->pluck('ville_id')->filter()->unique())->pluck('nom', 'id');

    return $lignes->each(function ($l) use ($sites, $villes) {
        $l->lieu = $l->site_id ? ($sites[$l->site_id] ?? '—') : ($l->ville_id ? ($villes[$l->ville_id] ?? '—').' — atelier non précisé' : 'Toute l\'entreprise');
    });
});

?>

<div>
    <x-titre-ecran titre="Charges & décaissements"
        sous-titre="Ce que l'exploitation a coûté sur la période." />

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    {{-- Les colonnes qu'aucun filtre ne couvre : le libellé, le tiers, le moyen, la
         référence, le motif, le montant. Demandé le 28/09. --}}
    <div style="display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
        @if ($this->peutReprendre)
            <button type="button" wire:click="ouvrirLaReprise"
                class="bouton {{ $repriseOuverte ? '' : 'bouton-secondaire' }}" style="padding:8px 14px;">
                Classer des sorties de caisse
            </button>
        @endif
        <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
    </div>

    @if ($repriseOuverte && $this->peutReprendre)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">
                Sorties du journal de caisse pas encore classées ({{ number_format($this->nombreSortiesDeCaisse, 0, ',', ' ') }})
            </h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
                Toutes les sorties de caisse comptent déjà dans les charges (20/10). Classer une sortie
                lui donne son <b>libellé d'opération</b> et son <b>atelier</b> — elle ne compte pas
                deux fois. Une sortie classée quitte cette liste.
            </p>

            <div class="bloc-saisie" style="background:#fff; border-style:solid; margin-bottom:12px;">
                <x-champ label="Rechercher" model="repriseRecherche" :live="true" width="220" placeholder="Libellé, motif, bénéficiaire…" />
                <x-champ label="Libellé d'opération" model="repriseLibelle" type="select" :requis="true" width="210"
                    :options="$this->libellesDeCharge" />
                <x-champ label="Atelier (si la sortie n'en dit pas)" model="repriseSiteId" type="select" width="230"
                    :options="$this->ateliersDeReprise" vide="— choisir —" />
                <button type="button" wire:click="reprendreDeLaCaisse" class="bouton">
                    Classer la sélection ({{ count($repriseChoix) }})
                </button>
            </div>
            <x-erreurs-du-bloc prefixe="reprise" />

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th></th><th>Date</th><th>N° pièce</th><th>Libellé</th><th>Bénéficiaire</th><th>Lieu</th>
                            <th style="text-align:right;">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pageSortiesDeCaisse as $sortie)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td><input type="checkbox" wire:model.live="repriseChoix" value="{{ $sortie->id }}"></td>
                                <td style="white-space:nowrap;">{{ $sortie->date?->format('d/m/Y') }}</td>
                                <td>{{ $sortie->numero_piece ?: '—' }}</td>
                                <td>{{ $sortie->libelle }}@if ($sortie->motif)<div style="font-size:11.5px; color:#6B6E76;">{{ $sortie->motif }}</div>@endif</td>
                                <td style="color:#6B6E76;">{{ $sortie->beneficiaire ?: '—' }}</td>
                                <td style="color:#6B6E76;">{{ $sortie->site?->nom ?? ($sortie->ville?->nom ? $sortie->ville->nom.' — atelier non précisé' : '—') }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae(montant_de($sortie->montant)) }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="7" texte="Aucune sortie de caisse à reprendre sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageReprise" :total="$this->nombreSortiesDeCaisse" prop="pageReprise" :par-page="15" />
        </div>
    @endif

    {{-- ─────────────────────────────── d'où viennent les charges — 12/10 --}}
    @php $o = $this->origines; $c = $this->selonLaCaisse; $exercice = \Illuminate\Support\Carbon::parse($this->plage[0])->year; @endphp
    <div class="carte" style="margin-bottom:16px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">D'où viennent les charges — exercice {{ $exercice }}</h3>
        <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
            Toutes les sorties du journal de caisse sont des charges, avec celles saisies dans
            l'application ; la caisse se partage par son libellé entre charges administratives
            (salaires, transport, fonctionnement…) et charges fournisseurs (pièces, travaux, mécanique,
            sinistre). Le suivi fournisseurs, la FNE et la banque se lisent à côté, tels qu'ils sont :
            sans lettrage, une même dépense peut y figurer deux fois, et ils ne s'additionnent pas.
        </p>
        @php $src = $this->parSource; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px;">
            {{-- La caisse, partagée par son libellé — 22/10 : « on garde le KPI des charges de la caisse,
                 et dessous, voici le montant des charges administratives, et voici celui des charges
                 fournisseurs ». --}}
            <x-kpi-card label="Charges selon la caisse" :value="ae($src['caisse']['total'])" couleur="#B45309"
                sub="{{ number_format($src['caisse']['n'], 0, ',', ' ') }} sortie(s) du journal — toutes des charges"
                :lignes="[
                    'Charges administratives ('.number_format($src['caisse']['nAdministratif'], 0, ',', ' ').')' => ae($src['caisse']['administratif']),
                    'Charges fournisseurs ('.number_format($src['caisse']['nFournisseurs'], 0, ',', ' ').')' => ae($src['caisse']['fournisseurs']),
                ]" />
            <x-kpi-card label="Saisies dans l'application" :value="ae($o['saisies'])"
                sub="{{ number_format($o['nSaisies'], 0, ',', ' ') }} décaissement(s){{ $o['importees'] != 0 ? ' · importées '.ae($o['importees']) : '' }}" />
            {{-- Le suivi fournisseurs de la période et du filtre, tel qu'importé — 22/10 : « voici le
                 montant du suivi fournisseur, suivant la date sélectionnée ; sans lettrage ». --}}
            <a href="{{ route('fournisseurs') }}" wire:navigate style="all:unset; display:block; cursor:pointer;" title="Ouvrir la page Fournisseurs">
                <x-kpi-card label="Suivi fournisseurs" :value="ae($src['suivi']['total'])" couleur="#7C3AED"
                    sub="{{ number_format($src['suivi']['n'], 0, ',', ' ') }} pièce(s) facturée(s) sur la période — à côté, non additionné ›" />
            </a>
            <a href="{{ route('fournisseurs.fne') }}" wire:navigate style="all:unset; display:block; cursor:pointer;" title="Ouvrir les factures FNE">
                <x-kpi-card label="Factures FNE" :value="ae($src['fne']['total'])" couleur="#7C3AED"
                    sub="{{ number_format($src['fne']['n'], 0, ',', ' ') }} facture(s) d'achat relevée(s) au portail — toute l'entreprise, à côté ›" />
            </a>
            <a href="{{ route('banques') }}" wire:navigate style="all:unset; display:block; cursor:pointer;" title="Ouvrir les banques">
                <x-kpi-card label="Banque — débits des relevés" :value="ae($src['banque']['total'])" couleur="#2563EB"
                    sub="{{ number_format($src['banque']['n'], 0, ',', ' ') }} débit(s) — règlements fournisseurs et autres sorties, à côté ›" />
            </a>
            {{-- Cliquable — 14/10 : le détail par catégorie se déplie dessous. --}}
            <button type="button" wire:click="ouvrirLesCategories" title="Voir le détail par catégorie"
                style="all:unset; display:block; cursor:pointer; border-radius:10px; {{ $categoriesOuvertes ? 'outline:2px solid #B45309;' : '' }}">
                <x-kpi-card label="Sorties de caisse par catégorie" :value="$c['nombre'] > 0 ? ae($c['sorties']) : '—'"
                    sub="{{ $c['nombre'] > 0 ? number_format($c['nombre'], 0, ',', ' ').' sortie(s) — '.($categoriesOuvertes ? 'masquer le détail ▲' : 'détail par catégorie ▼') : 'Aucun journal de caisse sur la période' }}" />
            </button>
        </div>

        @if ($categoriesOuvertes)
            <div style="margin-top:14px;">
                <h4 style="font-size:13.5px; font-weight:700; margin:0 0 4px;">Sorties de caisse par catégorie</h4>
                <p style="margin:0 0 10px; font-size:12px; color:#6B6E76; line-height:1.5;">
                    La catégorie se lit dans le motif du journal, sinon dans le libellé, sinon dans le
                    bénéficiaire. Cliquer une catégorie montre ses lignes dans le tableau du bas.
                    « Versements & transferts » ne sont pas des charges : l'argent change de poche.
                </p>
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th>Catégorie</th><th style="text-align:right;">Sorties</th><th style="text-align:right;">Montant</th>
                                <th style="text-align:right;">Part</th><th style="text-align:right;">Déjà reprises en charges</th><th>Libellés qui pèsent le plus</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->categoriesDeCaisse as $cat)
                                <tr wire:click="voirLaCategorie({{ array_search($cat['categorie'], \Modules\Noyau\Exploitation\Services\CategorieDeSortie::toutes(), true) }})" style="cursor:pointer; border-bottom:1px solid var(--th-ligne,#E2E0D8); {{ $cat['charge'] ? '' : 'color:#6B6E76;' }}">
                                    <td style="font-weight:700;">{{ $cat['categorie'] }}</td>
                                    <td style="text-align:right;">{{ number_format($cat['nombre'], 0, ',', ' ') }}</td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae($cat['montant']) }}</td>
                                    <td style="text-align:right;">{{ an($cat['part']) }}</td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $cat['repris'] > 0 ? ae($cat['repris']) : '—' }}</td>
                                    <td style="font-size:11.5px; color:#6B6E76;">
                                        @foreach ($cat['libelles'] as $lib => $m)<div>{{ \Illuminate\Support\Str::limit($lib ?: '—', 60) }} · {{ ae($m) }}</div>@endforeach
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="6" texte="Aucune sortie de caisse sur cette période." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="Total charges — {{ $this->libellePerimetre }}" :value="ae($this->kpis['total'])" sub="Sorties de caisse et charges saisies — hors transferts et décaissements DG saisis"
            :mecanique="$ventile ? ae($this->kpis['totalVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['totalVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['totalVentile']['nonVentile'] ? ae($this->kpis['totalVentile']['nonVentile']) : null" />
        @foreach (['pieces' => 'Achats pièces', 'salaires' => 'Salaires & personnel', 'fonctionnement' => 'Fonctionnement', 'autres' => 'Autres décaissements'] as $cle => $libelle)
            <x-kpi-card :label="$libelle" :value="ae($this->kpis[$cle])"
                :mecanique="$ventile ? ae($this->kpis[$cle.'Ventile']['mecanique']) : null"
                :sinistre="$ventile ? ae($this->kpis[$cle.'Ventile']['sinistre']) : null"
                :non-ventile="$ventile && $this->kpis[$cle.'Ventile']['nonVentile'] ? ae($this->kpis[$cle.'Ventile']['nonVentile']) : null" />
        @endforeach
        <x-kpi-card label="Résultat net — {{ $this->libellePerimetre }}" :value="ae($this->kpis['resultat'])" :couleur="$this->kpis['resultat'] >= 0 ? '#0E9F6E' : '#C8102E'" sub="CA facturé − charges"
            :mecanique="$ventile ? ae($this->kpis['resultatVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['resultatVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['resultatVentile']['nonVentile'] ? ae($this->kpis['resultatVentile']['nonVentile']) : null" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="Charges par nature" id="charges-hebdo"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    <div class="carte">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 14px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                @if ($vueTableau === 'caisse')
                    Charges selon la caisse — sorties du journal ({{ number_format($this->sortiesAffichees->count(), 0, ',', ' ') }} · {{ ae(montant_de($this->sortiesAffichees->sum('montant'))) }})
                @else
                    Toutes les charges ({{ number_format($this->nombreCharges, 0, ',', ' ') }} · {{ ae($this->montantCharges) }})
                @endif
            </h3>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <button type="button" wire:click="voirLesCharges" class="bouton {{ $vueTableau === 'charges' ? '' : 'bouton-secondaire' }}" style="padding:7px 12px;">Toutes les charges</button>
                <button type="button" wire:click="voirLaCaisse" class="bouton {{ $vueTableau === 'caisse' ? '' : 'bouton-secondaire' }}" style="padding:7px 12px;">Charges selon la caisse</button>
                <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire" style="padding:7px 12px;">Charges fournisseurs ›</a>
            </div>
        </div>

        @if ($vueTableau === 'caisse')
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                <select wire:model.live="categorieCaisse" class="champ" style="width:auto;">
                    <option value="">Toutes les catégories</option>
                    @foreach (\Modules\Noyau\Exploitation\Services\CategorieDeSortie::toutes() as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </select>
                <span style="font-size:12px; color:#6B6E76;">Toutes comptent dans les charges (20/10). « Classer » donne à une sortie son libellé d'opération et son atelier.</span>
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th><th>N° pièce</th><th>Libellé</th><th>Bénéficiaire</th><th>Lieu</th><th>Catégorie</th>
                            <th style="text-align:right;">Montant</th><th>En charge ?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->sortiesAffichees->forPage($pageCaisse, 15) as $sortie)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ $sortie->date?->format('d/m/Y') }}</td>
                                <td>{{ $sortie->numero_piece ?: '—' }}</td>
                                <td>{{ $sortie->libelle }}@if ($sortie->motif)<div style="font-size:11.5px; color:#6B6E76;">{{ $sortie->motif }}</div>@endif</td>
                                <td style="color:#6B6E76;">{{ $sortie->beneficiaire ?: '—' }}</td>
                                <td style="color:#6B6E76;">{{ $sortie->site?->nom ?? ($sortie->ville?->nom ? $sortie->ville->nom.' — atelier non précisé' : '—') }}</td>
                                <td style="font-size:12px;">{{ $sortie->categorie }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae(montant_de($sortie->montant)) }}</td>
                                <td style="font-size:11.5px; color:{{ (int) ($sortie->reprises ?? 0) > 0 ? '#B45309' : '#6B6E76' }};">{{ (int) ($sortie->reprises ?? 0) > 0 ? 'Reprise' : '—' }}</td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="8" texte="Aucune sortie de caisse sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageCaisse" :total="$this->sortiesAffichees->count()" prop="pageCaisse" :par-page="15" />
        @else
            @php $provenances = \Modules\Noyau\Exploitation\Services\ChargesFournisseurs::PROVENANCES; @endphp
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                <select wire:model.live="provenanceFiltre" class="champ" style="width:auto;" aria-label="Provenance">
                    <option value="">Toutes les provenances</option>
                    @foreach ($provenances as $cle => $libelle)
                        <option value="{{ $cle }}">{{ $libelle }}</option>
                    @endforeach
                </select>
                <select wire:model.live="typeFiltre" class="champ" style="width:auto;" aria-label="Genre">
                    <option value="">Charges (sorties de caisse et saisies)</option>
                    <option value="fournisseurs">Suivi fournisseurs — à côté, non additionné</option>
                </select>
                <input type="search" wire:model.live.debounce.400ms="rechercheCharges" class="champ" style="width:220px;" placeholder="Tiers, référence, libellé…" aria-label="Rechercher">
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th><th>Genre</th><th>Libellé / nature</th><th>Tiers</th><th>Référence</th><th>Lieu</th>
                            <th style="text-align:right;">Montant</th>
                            {{-- D'où vient la ligne : caisse, fichier, saisie, FNE (07/10, élargi le 18/10). --}}
                            <th>Provenance</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pageCharges as $ligne)
                            <tr wire:key="charge-{{ $ligne->source }}-{{ $ligne->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ \Illuminate\Support\Carbon::parse($ligne->date)->format('d/m/Y') }}</td>
                                <td>{{ $ligne->type_op }}</td>
                                <td>{{ $ligne->nature }}@if ($ligne->observations)<div style="font-size:11.5px; color:#6B6E76;">{{ \Illuminate\Support\Str::limit($ligne->observations, 70) }}</div>@endif</td>
                                <td style="color:#6B6E76;">{{ $ligne->tiers ?: '—' }}</td>
                                <td style="font-size:12px;">{{ $ligne->reference ?: '—' }}</td>
                                <td style="color:#6B6E76; font-size:12px;">{{ $ligne->lieu }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae(montant_de($ligne->montant)) }}</td>
                                <td style="font-size:11.5px; white-space:nowrap; color:{{ match ($ligne->provenance) { 'caisse' => '#B45309', 'fne' => '#7C3AED', default => '#6B6E76' } }}; font-weight:{{ in_array($ligne->provenance, ['caisse', 'fne'], true) ? '700' : '400' }};">
                                    {{ $provenances[$ligne->provenance] ?? $ligne->provenance }}@if ($ligne->provenance !== 'fne' && (int) $ligne->normalisee === 1)<div style="color:#7C3AED;">facture FNE rattachée</div>@endif
                                </td>
                                <td style="white-space:nowrap;">
                                    @if ($ligne->source === 'fournisseur')
                                        <a href="{{ route('fournisseurs.piece', $ligne->id) }}" wire:navigate style="font-size:12px;">Détails</a>
                                    @elseif ($ligne->source === 'fne')
                                        <a href="{{ route('fournisseurs.fne.detail', $ligne->id) }}" wire:navigate style="font-size:12px;">Détails</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="9" texte="Aucune charge sur cette période avec ces filtres." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageDetail" :total="$this->nombreCharges" prop="pageDetail" :par-page="15" />
        @endif
    </div>
</div>

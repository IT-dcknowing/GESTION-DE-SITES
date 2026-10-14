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

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

/**
 * Une charge n'est ventilée par activité que si celui qui l'a saisie l'a précisée :
 * beaucoup d'entre elles (loyer, salaires, fonctionnement) concernent réellement le
 * lieu entier. Isoler une activité les écarte donc volontairement du total — ce sont
 * les seules dont on puisse affirmer qu'elles lui appartiennent.
 */
$requeteBase = computed(function () {
    [$debut, $fin] = $this->plage;

    return Charge::query()->whereIn('site_id', $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

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
 * **D'où viennent les charges — demandé le 12/10.** « Comme on l'a fait pour la banque —
 * ce que dit réellement la banque, et ce que disent les impayés —, fais-le pour les
 * charges ; comme on alimente, on doit avoir une trace. »
 *
 * | Origine | Lue sur |
 * |---|---|
 * | reprise de la caisse | `mouvement_caisse_id` |
 * | facture fournisseur FNE | le module FNE, à venir : zéro tant qu'il n'existe pas |
 * | importée | `lot_import_id` |
 * | saisie dans l'application | ni l'un ni l'autre |
 *
 * « Non normalisée » = tout ce qui n'est pas porté par une facture FNE : aujourd'hui, toutes.
 * Le journal de caisse se lit **à côté**, jamais additionné : une sortie reprise en charge
 * est la même dépense, la compter deux fois gonflerait les charges. L'exercice est celui du
 * filtre de période, comme partout.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
$origines = computed(function () {
    $colonne = \App\Support\SchemaDisponible::colonne('charges', 'mouvement_caisse_id');

    $ligne = (clone $this->requeteBase)->where('type_operation', 'Charges')->reorder()
        ->selectRaw('count(*) as nombre, coalesce(sum(montant), 0) as montant')
        ->when($colonne, fn ($q) => $q
            ->selectRaw('coalesce(sum(case when mouvement_caisse_id is not null then 1 else 0 end), 0) as n_caisse')
            ->selectRaw('coalesce(sum(case when mouvement_caisse_id is not null then montant else 0 end), 0) as m_caisse')
            ->selectRaw('coalesce(sum(case when mouvement_caisse_id is null and lot_import_id is not null then montant else 0 end), 0) as m_import')
            ->selectRaw('coalesce(sum(case when mouvement_caisse_id is null and lot_import_id is null then montant else 0 end), 0) as m_saisie')
            ->selectRaw('coalesce(sum(case when mouvement_caisse_id is null and lot_import_id is null then 1 else 0 end), 0) as n_saisie'))
        ->toBase()->first();

    return [
        'total' => montant_de($ligne->montant ?? 0),
        'nombre' => (int) ($ligne->nombre ?? 0),
        // Des montants : lus sans couper leurs centimes (piège 17) — ils passaient par `(int)`.
        'caisse' => montant_de($ligne->m_caisse ?? 0),
        'nCaisse' => (int) ($ligne->n_caisse ?? 0),
        'importees' => montant_de($ligne->m_import ?? 0),
        'saisies' => montant_de($ligne->m_saisie ?? 0),
        'nSaisies' => (int) ($ligne->n_saisie ?? 0),
        'fne' => 0,
        'nonNormalisees' => montant_de($ligne->montant ?? 0),
    ];
});

/**
 * Les achats normalisés FNE de la période — 14/10. Le module existe depuis le 12/10 (relevé du
 * portail, `factures_fne`) ; la carte disait encore « à venir ». Ce sont les factures et avoirs que
 * des fournisseurs ont certifiés au NCC de l'entreprise : **toute l'entreprise**, le portail ne
 * dit pas la ville. Ils se lisent à côté des charges, jamais additionnés : une facture d'achat
 * n'est une charge que lorsqu'on la règle ou qu'on la saisit.
 */
$achatsFne = computed(function () {
    if (! \App\Support\SchemaDisponible::table('factures_fne')) {
        return null;
    }

    [$debut, $fin] = $this->plage;

    $ligne = \Modules\Noyau\Imports\Modeles\FactureFne::withoutGlobalScopes()
        ->where('entreprise_id', auth()->user()->entreprise_id)
        ->whereBetween('date_facture', [$debut, $fin])
        ->selectRaw('count(*) as nombre, coalesce(sum(montant_ttc), 0) as ttc')
        ->selectRaw('coalesce(sum(case when facture_fournisseur_id is not null then 1 else 0 end), 0) as portees')
        ->toBase()->first();

    return ['ttc' => montant_de($ligne->ttc ?? 0), 'nombre' => (int) ($ligne->nombre ?? 0), 'portees' => (int) ($ligne->portees ?? 0)];
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
    $lignes = (clone $this->requeteBase)->where('type_operation', 'Charges')->get();
    $total = montant_de($lignes->sum('montant'));

    // Les lignes sont déjà en mémoire : on ventile ici plutôt que de relancer des
    // requêtes d'agrégat pour des chiffres qu'on a sous la main.
    $ventileTotal = VentilationActivite::repartirCollection($lignes);

    return [
        'total' => $total,
        'totalVentile' => $ventileTotal,
        'pieces' => montant_de($lignes->where('libelle', 'Achats pièces')->sum('montant')),
        'piecesVentile' => VentilationActivite::repartirCollection($lignes->where('libelle', 'Achats pièces')),
        'salaires' => montant_de($lignes->where('libelle', 'Salaires & personnel')->sum('montant')),
        'salairesVentile' => VentilationActivite::repartirCollection($lignes->where('libelle', 'Salaires & personnel')),
        'resultat' => $this->caPeriode - $total,
        'resultatVentile' => VentilationActivite::difference(
            VentilationActivite::repartir($this->requeteCa),
            $ventileTotal,
        ),
    ];
});

$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);
    $natures = ['Achats pièces' => '#191B20', 'Salaires & personnel' => '#2563EB', 'Fonctionnement' => '#D97706', 'Autres décaissements' => '#9A9DA5'];

    $labels = [];
    $series = array_fill_keys(array_keys($natures), []);

    /*
     * **Les lignes sont lues une fois, et non une fois par point.**
     *
     * Mesuré le 02/10 : un changement de filtre relançait cette requête autant de fois qu'il
     * y a de points — jusqu'à trente et une pour un mois affiché jour par jour, alors qu'il
     * s'agit des mêmes lignes, triées autrement.
     *
     * **Bornes prises sur les points, pas sur la plage.** Un point hebdomadaire commence au
     * lundi, donc parfois avant le début de la plage ; borner à la plage rognerait le premier
     * point. C'est le même piège que dans `SerieParPoint`, et la même réponse.
     */
    $bornes = collect($points)->flatMap(fn ($point) => [$point['debut'], $point['fin']]);

    $toutes = (clone $this->requeteBase)
        ->where('type_operation', 'Charges')
        ->whereBetween('date', [$bornes->min(), $bornes->max()])
        ->get();

    foreach ($points as $point) {
        // Les dates sont comparées au jour, comme `whereBetween` le faisait.
        $debutDuPoint = $point['debut']->toDateString();
        $finDuPoint = $point['fin']->toDateString();

        $lignes = $toutes->filter(function ($ligne) use ($debutDuPoint, $finDuPoint) {
            $jour = substr((string) $ligne->date, 0, 10);

            return $jour >= $debutDuPoint && $jour <= $finDuPoint;
        });

        $labels[] = $point['label'];

        foreach ($natures as $nature => $couleur) {
            $series[$nature][] = montant_de($lignes->where('libelle', $nature)->sum('montant'));
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
    unset($this->sortiesDeCaisse, $this->nombreSortiesDeCaisse, $this->pageSortiesDeCaisse, $this->detail, $this->kpis, $this->graphique);

    $this->dispatch('annonce', ton: 'succes',
        texte: $nombre.' sortie(s) de caisse reprise(s) en charges — colonne Origine « Caisse ».');
};

$detail = computed(fn () => FiltreLibre::appliquer(
    (clone $this->requeteBase)->with('site'),
    $this->colonnesFiltrables,
    (array) $this->filtresLibres,
)->latest('date')->latest('id')->get());

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
                Reprendre des sorties de caisse
            </button>
        @endif
        <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
    </div>

    @if ($repriseOuverte && $this->peutReprendre)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">
                Sorties du journal de caisse pas encore reprises ({{ number_format($this->nombreSortiesDeCaisse, 0, ',', ' ') }})
            </h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
                Cochez celles qui sont des charges : elles entrent dans les charges avec l'origine
                <b>Caisse</b>, et ne sont jamais comptées deux fois — la caisse et la trésorerie
                les lisent sous leur sortie. Une sortie reprise quitte cette liste.
            </p>

            <div class="bloc-saisie" style="background:#fff; border-style:solid; margin-bottom:12px;">
                <x-champ label="Rechercher" model="repriseRecherche" :live="true" width="220" placeholder="Libellé, motif, bénéficiaire…" />
                <x-champ label="Libellé d'opération" model="repriseLibelle" type="select" :requis="true" width="210"
                    :options="$this->libellesDeCharge" />
                <x-champ label="Atelier (si la sortie n'en dit pas)" model="repriseSiteId" type="select" width="230"
                    :options="$this->ateliersDeReprise" vide="— choisir —" />
                <button type="button" wire:click="reprendreDeLaCaisse" class="bouton">
                    Reprendre la sélection ({{ count($repriseChoix) }})
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
            Chaque charge dit son origine. Le journal de caisse se lit à côté, sans jamais
            s'additionner : une sortie reprise en charge est la même dépense.
        </p>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px;">
            <x-kpi-card label="Charges venant de la caisse" :value="ae($o['caisse'])"
                sub="{{ number_format($o['nCaisse'], 0, ',', ' ') }} sortie(s) du journal reprise(s)" couleur="#B45309" />
            @php $f = $this->achatsFne; @endphp
            <a href="{{ route('fournisseurs.fne') }}" style="all:unset; display:block; cursor:pointer;" title="Ouvrir Fournisseurs › Factures FNE">
                <x-kpi-card label="Achats normalisés — factures fournisseurs FNE" :value="$f === null ? '—' : ae($f['ttc'])"
                    sub="{{ $f === null ? 'Module FNE pas encore migré sur ce serveur' : ($f['nombre'] > 0
                        ? number_format($f['nombre'], 0, ',', ' ').' pièce(s) relevée(s) au portail · '.number_format($f['portees'], 0, ',', ' ').' portée(s) au suivi — toute l\'entreprise, à côté des charges'
                        : 'Aucune pièce relevée sur la période — le relevé attend les accès au portail') }}" />
            </a>
            <x-kpi-card label="Charges non normalisées" :value="ae($o['nonNormalisees'])"
                sub="Charges enregistrées sans facture FNE rattachée — aujourd'hui, toutes" couleur="#C8102E" />
            <x-kpi-card label="Saisies dans l'application" :value="ae($o['saisies'])"
                sub="{{ number_format($o['nSaisies'], 0, ',', ' ') }} décaissement(s){{ $o['importees'] > 0 ? ' · importées '.ae($o['importees']) : '' }}" />
            {{-- Cliquable — 14/10 : le détail par catégorie se déplie dessous. --}}
            <button type="button" wire:click="ouvrirLesCategories" title="Voir le détail par catégorie"
                style="all:unset; display:block; cursor:pointer; border-radius:10px; {{ $categoriesOuvertes ? 'outline:2px solid #B45309;' : '' }}">
                <x-kpi-card label="Selon la caisse — sorties du journal" :value="$c['nombre'] > 0 ? ae($c['sorties']) : '—'"
                    sub="{{ $c['nombre'] > 0 ? number_format($c['nombre'], 0, ',', ' ').' sortie(s) · toutes ne sont pas des charges — '.($categoriesOuvertes ? 'masquer le détail ▲' : 'détail par catégorie ▼') : 'Aucun journal de caisse sur la période' }}" />
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

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="Total charges — {{ $this->libellePerimetre }}" :value="ae($this->kpis['total'])" sub="Hors transferts et décaissements DG"
            :mecanique="$ventile ? ae($this->kpis['totalVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['totalVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['totalVentile']['nonVentile'] ? ae($this->kpis['totalVentile']['nonVentile']) : null" />
        <x-kpi-card label="Achats pièces" :value="ae($this->kpis['pieces'])"
            :mecanique="$ventile ? ae($this->kpis['piecesVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['piecesVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['piecesVentile']['nonVentile'] ? ae($this->kpis['piecesVentile']['nonVentile']) : null" />
        <x-kpi-card label="Salaires & personnel" :value="ae($this->kpis['salaires'])"
            :mecanique="$ventile ? ae($this->kpis['salairesVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['salairesVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['salairesVentile']['nonVentile'] ? ae($this->kpis['salairesVentile']['nonVentile']) : null" />
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
                    Détail des opérations ({{ $this->detail->count() }})
                @endif
            </h3>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <button type="button" wire:click="voirLesCharges" class="bouton {{ $vueTableau === 'charges' ? '' : 'bouton-secondaire' }}" style="padding:7px 12px;">Charges enregistrées</button>
                <button type="button" wire:click="voirLaCaisse" class="bouton {{ $vueTableau === 'caisse' ? '' : 'bouton-secondaire' }}" style="padding:7px 12px;">Charges selon la caisse</button>
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
                <span style="font-size:12px; color:#6B6E76;">Lues dans le journal de caisse, jamais additionnées aux charges : une sortie ne devient une charge que reprise (« Reprendre des sorties de caisse »).</span>
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
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type d'opération</th>
                        <th>Libellé d'opération</th>
                        <th>Moyens</th>
                        <th>Tiers</th>
                        @if (count($this->idsSites) > 1)
                            <th>Site</th>
                        @endif
                        <th>Montant</th>
                        {{-- Demandée le 07/10 : d'où vient la ligne — saisie, import, ou reprise de la caisse. --}}
                        <th>Origine</th>
                        <th>Observations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail->forPage($pageDetail, 10) as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>{{ $ligne->date->format('d/m/Y') }}</td>
                            <td>{{ $ligne->type_operation }}</td>
                            <td>{{ $ligne->libelle }}</td>
                            <td>{{ $ligne->moyen }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->tiers ?? '—' }}</td>
                            @if (count($this->idsSites) > 1)
                                <td>{{ $ligne->site->nom }}</td>
                            @endif
                            <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne->montant) }}</td>
                            <td style="font-size:11.5px; color:{{ $ligne->origine() === 'Caisse' ? '#B45309' : '#6B6E76' }}; font-weight:{{ $ligne->origine() === 'Caisse' ? '700' : '400' }};">{{ $ligne->origine() }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->observations ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="count($this->idsSites) > 1 ? 9 : 8" texte="Aucune charge enregistrée sur cette période." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageDetail" :total="$this->detail->count()" prop="pageDetail" />
        @endif
    </div>
</div>

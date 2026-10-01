<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\SupportDeReglement;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\VentilationActivite;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\LotImport;
use function Livewire\Volt\{state, computed, mount, protect};

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
    'pageEncaissements' => 1,
    /*
     * Les filtres posés sur les colonnes sans filtre propre — voir `FiltreLibre` et le
     * composant `x-autre-filtre`. Hors de l'adresse : un tableau de tableaux ne se
     * sérialise pas lisiblement dans une URL, pour un gain nul.
     */
    'filtresLibres' => [],
    'pageDecaissements' => 1,

    /*
     * La ligne dont on a demandé le détail, de chaque côté. Une seule à la fois : le
     * détail s'ouvre sous la ligne, et deux volets ouverts feraient perdre celle qu'on
     * regardait. L'identifiant vient du navigateur, il n'est donc jamais cru sur parole —
     * la ligne est relue dans la liste déjà filtrée par le périmètre du compte.
     */
    'detailDecaissement' => null,
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; };
/** Changer de ville rend caduc le lieu choisi dans la précédente. */
$updatedVilleFiltre = function () { $this->siteFiltre = ''; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre));

/*
 * Les villes des ateliers retenus — pour placer les lignes qui n'ont pas d'atelier.
 *
 * Elles sont la seconde branche de la règle de la maison : l'atelier s'il est connu, sinon
 * la ville, sinon la ligne paraît partout. Voir `PerimetreDeTresorerie`.
 */
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));

/*
 * **Le filtre par support, demandé le 30/09** : « tu ajoutes un filtre en fonction de toute
 * la tréso qu'on a ».
 *
 * L'axe n'est pas « importé ou saisi » — celui-là dit d'où vient la *ligne* — mais **par où
 * est passé l'argent**, qui dit où il est. C'est le seul qui permette de répondre à « combien
 * ai-je en caisse » et « combien en banque », qui sont les deux questions de cet écran.
 *
 * Dans l'adresse, comme les autres filtres de la page : un lien se transmet tel quel.
 */
state(['supportFiltre' => ''])->url(except: '');
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

/*
 * **Les trois sources passent par `PerimetreDeTresorerie`, et c'est une correction.**
 *
 * Elles s'écrivaient ici `whereIn('site_id', $this->idsSites)`, et un `site_id` nul n'entre
 * dans aucun `whereIn`. Mesuré le 30/09 : **7 627 des 7 714 encaissements n'ont pas
 * d'atelier**, si bien que cet écran en montrait **87**. La page annonçait lire « les
 * règlements clients » et en affichait un pour cent — ce n'était pas un filtre trop serré,
 * c'était un total faux d'un facteur cent sur l'écran qui dit ce qu'on a en caisse.
 *
 * Les 7 627 sont tous rattachés à une facture, et **4 088 de ces factures ont une ville** :
 * l'argent est entré là où la facture a été émise, et c'est par là qu'on les place.
 *
 * Troisième écran mordu par ce même `whereIn`, après les encaissements du recouvrement et
 * le chiffre d'affaires. La règle est désormais écrite une fois par table, et seulement là.
 */
$encaissementsQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return SupportDeReglement::appliquer(
        PerimetreDeTresorerie::encaissements(
            Encaissement::query(), $this->idsSites, $this->idsVilles,
        ),
        'encaissements',
        $this->supportFiltre,
    )
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$chargesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return SupportDeReglement::appliquer(
        PerimetreDeTresorerie::charges(Charge::query(), $this->idsSites),
        'charges',
        $this->supportFiltre,
    )
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

$facturesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return EtatDesImpayes::dansLePerimetre(Facture::query(), $this->idsSites, $this->idsVilles)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);
});

/**
 * Encaissements et charges n'ont pas d'étiquette Mécanique/Sinistre en base — ils ne
 * sont rattachés qu'au site où ils sont enregistrés, et un même site accueille souvent
 * un commercial qui vend sur les deux activités. Une ventilation par activité du site
 * afficherait donc « Sinistre : 0 F » même quand des mouvements liés à cette activité
 * existent bel et bien — trompeur plutôt qu'informatif. Ces KPI restent consolidés.
 */
$kpis = computed(function () {
    /*
     * **Les trois sources, et non plus deux.** Le journal de caisse était hors de ce total,
     * et l'écran l'annonçait au lieu de le corriger. Une trésorerie qui ignore la caisse
     * n'est pas une trésorerie.
     */
    $journal = $this->journalTotaux;

    $encaisse = (int) (clone $this->encaissementsQ)->sum('montant') + $journal['entrees'];
    $decaisse = (int) (clone $this->chargesQ)->sum('montant') + $journal['sorties'];
    $facture = (int) (clone $this->facturesQ)->sum('montant');

    // Un encaissement ou un décaissement ne porte son activité que si celui qui l'a
    // saisi la connaissait : la part restante s'affiche « non ventilée » plutôt que
    // d'être répartie au jugé.
    $encaisseVentile = VentilationActivite::repartir($this->encaissementsQ);
    $decaisseVentile = VentilationActivite::repartir($this->chargesQ);
    $factureVentile = VentilationActivite::repartir($this->facturesQ);

    return [
        'encaisse' => $encaisse,
        'encaisseVentile' => $encaisseVentile,
        'decaisse' => $decaisse,
        'decaisseVentile' => $decaisseVentile,
        'net' => $encaisse - $decaisse,
        'netVentile' => VentilationActivite::difference($encaisseVentile, $decaisseVentile),
        'nonEncaisse' => max(0, $facture - $encaisse),
        'nonEncaisseVentile' => array_map(
            fn ($v) => max(0, $v),
            VentilationActivite::difference($factureVentile, $encaisseVentile),
        ),
    ];
});

/**
 * **Le journal de caisse entre dans la trésorerie — 01/10.**
 *
 * Il en était dehors, et un encart l'annonçait : « cette page ne regroupe pas tout ». Le
 * propriétaire a tranché, et il a raison : *« ne me dis pas que la tréso ne voit que les
 * encaissements de l'application : non. Comme le nom le dit, c'est une trésorerie. »*
 *
 * Une trésorerie qui ignore la caisse n'est pas une trésorerie, c'est un extrait. Les
 * 1 155 mouvements du journal sont de l'argent réellement entré et sorti du tiroir : ils
 * comptent ici comme le reste.
 *
 * **Et ils ne font double emploi avec rien.** Le journal vient du logiciel d'atelier ; les
 * espèces saisies ici sont celles qu'il ne connaît pas encore — c'est tout l'objet de
 * l'écart que l'écran Caisse affiche. Deux sources, aucune ligne commune.
 */
$journalQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return PerimetreDeTresorerie::mouvementsDeCaisse(
        MouvementCaisse::query(), $this->idsSites, $this->idsVilles,
    )->whereBetween('mouvements_caisse.date', [$debut, $fin]);
});

/**
 * Le journal compte-t-il, vu le filtre de support posé ?
 *
 * Il est de la caisse par nature : un journal de caisse ne porte que des espèces. Filtrer
 * sur « banque » doit donc l'écarter en entier, et non en retenir une part.
 */
$journalCompte = computed(fn () => in_array(
    $this->supportFiltre, ['', SupportDeReglement::CAISSE], true,
));

/**
 * Les trois nombres du journal, en **une** requête.
 *
 * Trois clones donnaient trois lectures de la même table pour trois sommes qui se calculent
 * en une passe. Un `CASE` par sens suffit, et la base ne lit qu'une fois.
 */
$journalTotaux = computed(function () {
    if (! $this->journalCompte) {
        return ['entrees' => 0, 'sorties' => 0, 'nombre' => 0];
    }

    $ligne = (clone $this->journalQ)
        ->selectRaw(
            'count(*) as nombre, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as entrees, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as sorties',
            [MouvementCaisse::ENTREE, MouvementCaisse::SORTIE],
        )
        ->first();

    return [
        'entrees' => (int) ($ligne->entrees ?? 0),
        'sorties' => (int) ($ligne->sorties ?? 0),
        'nombre' => (int) ($ligne->nombre ?? 0),
    ];
});

/**
 * Ce que la trésorerie regroupe, support par support.
 *
 * **Le cœur de la demande du 30/09** : « la page tréso servira d'une grande page de tableau
 * de bord pour toutes ces informations de la tréso, elle sera une page de KPI, montrant ce
 * que la tréso regroupe ».
 *
 * **Calculé sans le filtre de support**, et c'est tout l'intérêt : ce bloc est la carte des
 * lieux où l'argent se trouve. La réduire au support déjà choisi afficherait une seule case
 * pleine et trois vides, c'est-à-dire répéterait le filtre au lieu de le situer. Les autres
 * filtres — ville, atelier, période, activité — s'appliquent, eux : ils disent de quel
 * périmètre on parle, pas de quel support.
 *
 * La période et le périmètre sont donc refaits ici plutôt que repris de `encaissementsQ`,
 * qui porte déjà le support.
 */
$parSupport = computed(function () {
    [$debut, $fin] = $this->plage;

    $encaissements = PerimetreDeTresorerie::encaissements(
        Encaissement::query(), $this->idsSites, $this->idsVilles,
    )
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);

    $charges = PerimetreDeTresorerie::charges(Charge::query(), $this->idsSites)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);

    $entrees = SupportDeReglement::repartirParRequete($encaissements, 'encaissements');
    $sorties = SupportDeReglement::repartirParRequete($charges, 'charges');

    /*
     * Le journal de caisse rejoint la case « Caisse », et aucune autre : un journal de
     * caisse ne porte que des espèces. C'est aussi la case que l'écran Caisse appelle
     * « consolidée » — les deux doivent tomber sur le même nombre, sans quoi l'un des deux
     * ment.
     */
    // La même passe que `journalTotaux`, mais sans le filtre de support : ce bloc est la
    // carte des lieux, il compte le journal quel que soit le support choisi.
    $brut = (clone $this->journalQ)
        ->selectRaw(
            'count(*) as nombre, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as entrees, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as sorties',
            [MouvementCaisse::ENTREE, MouvementCaisse::SORTIE],
        )
        ->first();

    $journal = [
        'entrees' => (int) ($brut->entrees ?? 0),
        'sorties' => (int) ($brut->sorties ?? 0),
        'nombre' => (int) ($brut->nombre ?? 0),
    ];

    $entrees[SupportDeReglement::CAISSE]['montant'] += $journal['entrees'];
    $entrees[SupportDeReglement::CAISSE]['nombre'] += $journal['nombre'];
    $sorties[SupportDeReglement::CAISSE]['montant'] += $journal['sorties'];

    $lignes = [];

    foreach (SupportDeReglement::options() as $support => $libelle) {
        $lignes[$support] = [
            'libelle' => $libelle,
            'entrees' => $entrees[$support]['montant'],
            'sorties' => $sorties[$support]['montant'],
            'net' => $entrees[$support]['montant'] - $sorties[$support]['montant'],
            'nombre' => $entrees[$support]['nombre'] + $sorties[$support]['nombre'],
        ];
    }

    return $lignes;
});

$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);

    $labels = [];
    $entrees = [];
    $sorties = [];
    $cumul = [];
    $total = 0;

    foreach ($points as $point) {
        $e = (int) (clone $this->encaissementsQ)->whereBetween('date', [$point['debut'], $point['fin']])->sum('montant');
        $so = (int) (clone $this->chargesQ)->whereBetween('date', [$point['debut'], $point['fin']])->sum('montant');
        $total += $e - $so;

        $labels[] = $point['label'];
        $entrees[] = $e;
        $sorties[] = $so;
        $cumul[] = $total;
    }

    return [
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Entrées', 'data' => $entrees, 'color' => '#0E9F6E'],
            ['label' => 'Sorties', 'data' => $sorties, 'color' => '#C8102E'],
            ['label' => 'Trésorerie nette cumulée', 'data' => $cumul, 'color' => '#2563EB', 'type' => 'line'],
        ],
    ];
});

/*
 * Les deux listes portent de quoi montrer la pièce d'origine : son atelier, sa facture
 * (pour un encaissement) et le lot d'import dont elle vient. Préchargés ici, sans quoi
 * ouvrir un détail déclencherait trois requêtes de plus par ligne affichée.
 */
/**
 * Les colonnes du tableau des encaissements qu'aucun filtre du haut ne couvre.
 *
 * Ne figurent pas ici celles qui en ont déjà un : la période, la ville, l'atelier et
 * l'activité. Le filtre ne porte que sur les **encaissements** : les décaissements sont un
 * autre tableau, avec d'autres colonnes, et mêler les deux dans un même panneau ferait
 * poser une condition sur une colonne que l'autre n'a pas.
 */
$colonnesFiltrables = computed(fn () => [
    'encaissements.client' => FiltreLibre::colonne('Client'),
    'encaissements.autres_tiers' => FiltreLibre::colonne('Autres tiers'),
    'encaissements.type' => FiltreLibre::colonne("Type d'encaissement"),
    'encaissements.moyen' => FiltreLibre::colonne('Moyen'),
    'encaissements.numero' => FiltreLibre::colonne('Référence'),
    'encaissements.reference_origine' => FiltreLibre::colonne("Référence d'origine"),
    'encaissements.reglement_global' => FiltreLibre::colonne('Règlement global'),
    'encaissements.montant' => FiltreLibre::colonne('Montant', 'nombre'),
]);

/**
 * **La page ne charge plus que la page — corrigé le 01/10.**
 *
 * Les deux tableaux faisaient `->get()` sur la période entière, puis `forPage(…, 10)` en
 * mémoire : sur l'exercice 2026, cela hydratait **plusieurs milliers** d'objets Encaissement
 * avec leurs relations pour en afficher dix. C'était le premier poste de lenteur de l'écran
 * — mesuré le 01/10 : 1 651 ms, dont 914 ms de SQL.
 *
 * La requête est désormais rendue telle quelle, et les deux lectures qu'on en fait — compter,
 * et prendre dix lignes — se font chacune en base.
 */
$requeteEncaissements = computed(fn () => FiltreLibre::appliquer(
    clone $this->encaissementsQ,
    $this->colonnesFiltrables,
    (array) $this->filtresLibres,
));

$nombreEncaissements = computed(fn () => (clone $this->requeteEncaissements)->count());

$detailEncaissements = computed(fn () => (clone $this->requeteEncaissements)
    ->with(['site', 'facture:id,numero,n_facture,client,immatriculation', 'lot:id,nom_fichier,format,created_at'])
    ->latest('date')->latest('id')
    ->forPage($this->pageEncaissements, 10)
    ->get());

$nombreDecaissements = computed(fn () => (clone $this->chargesQ)->count());

$detailDecaissements = computed(fn () => (clone $this->chargesQ)
    ->with(['site', 'lot:id,nom_fichier,format,created_at'])
    ->latest('date')->latest('id')
    ->forPage($this->pageDecaissements, 10)
    ->get());

/*
 * Le détail d'un encaissement a sa page depuis le 28/09 — voir `pilotage.encaissement-detail`.
 *
 * « Les encaissements de la page trésorerie viennent avec moins de détails comparé à ceux
 * des impayés, et les détails doivent ouvrir dans une page. » C'était exact : six colonnes
 * et quatre lignes dépliées, là où l'écran des impayés montre la créance entière, ses
 * règlements, qui l'a touchée et quand. Un règlement mérite autant — c'est de l'argent
 * entré, et quand on le cherche six mois plus tard, c'est qu'il y a un désaccord.
 */
$voirDecaissement = function (int $id) {
    $this->detailDecaissement = $this->detailDecaissement === $id ? null : $id;
};

/**
 * Ce que « Autres » recouvre, poste par poste.
 *
 * **Pourquoi ce bloc existe.** « Autres » est une valeur du référentiel, côté type
 * d'encaissement comme côté libellé de charge. C'est commode à la saisie et muet à la
 * lecture : on voit une somme, on ne sait pas ce qu'elle contient, et c'est justement
 * celle dont on voudrait le détail. La réponse était déjà en base — chaque ligne porte son
 * tiers et sa référence —, elle n'était simplement affichée nulle part.
 *
 * Les postes sont rangés du plus lourd au plus léger : c'est l'ordre dans lequel on les
 * regarde, et les trois premiers suffisent presque toujours à comprendre.
 *
 * @return array{encaissements: array<int, array{poste: string, montant: int, lignes: int}>,
 *               decaissements: array<int, array{poste: string, montant: int, lignes: int}>,
 *               totalEncaisse: int, totalDecaisse: int}
 */
$autres = computed(function () {
    $ranger = function ($lignes, callable $poste) {
        $postes = [];

        foreach ($lignes as $ligne) {
            $cle = trim((string) $poste($ligne)) ?: 'Sans précision';
            $postes[$cle] ??= ['poste' => $cle, 'montant' => 0, 'lignes' => 0];
            $postes[$cle]['montant'] += (int) $ligne->montant;
            $postes[$cle]['lignes']++;
        }

        usort($postes, fn ($a, $b) => $b['montant'] <=> $a['montant']);

        return $postes;
    };

    /*
     * **Chacun sa requête, et non la collection du tableau.**
     *
     * Ce bloc lisait `$detailEncaissements`, qui chargeait alors la période entière. Depuis
     * que ce tableau ne rend plus que ses dix lignes, il lui faut sa propre lecture — et
     * elle est bien plus étroite : « Autres » est une poignée de lignes sur des milliers.
     */
    $encaissements = (clone $this->requeteEncaissements)
        ->where('encaissements.type', 'Autres')
        ->get(['encaissements.autres_tiers', 'encaissements.client', 'encaissements.moyen', 'encaissements.montant']);

    // Côté dépenses, le libellé est déjà le poste : on ne retient que celui qui ne dit
    // rien — « Autres décaissements » — et l'on regarde alors le tiers payé.
    $decaissements = (clone $this->chargesQ)
        ->where('charges.libelle', 'like', '%utre%')
        ->get(['charges.tiers', 'charges.observations', 'charges.moyen', 'charges.montant', 'charges.libelle']);

    return [
        'encaissements' => $ranger($encaissements, fn ($e) => $e->autres_tiers ?: ($e->client ?: $e->moyen)),
        'decaissements' => $ranger($decaissements, fn ($c) => $c->tiers ?: ($c->observations ?: $c->moyen)),
        'totalEncaisse' => (int) $encaissements->sum('montant'),
        'totalDecaisse' => (int) $decaissements->sum('montant'),
    ];
});

/**
 * La pièce d'origine d'une ligne, en une phrase : saisie ici, ou reprise d'un fichier.
 *
 * `protect()` et non une simple fonction : dans un composant Volt, une closure posée en
 * tête devient une action appelable depuis le navigateur. Celle-ci n'a rien à y faire.
 */
$origineDe = protect(function ($ligne) {
    if ($ligne->lot_import_id === null) {
        return 'Saisie dans l\'application'
            .($ligne->code_auteur ? ' · code '.$ligne->code_auteur : '')
            .($ligne->created_at ? ' le '.$ligne->created_at->format('d/m/Y à H:i') : '');
    }

    $lot = $ligne->lot;

    return 'Reprise du fichier '.($lot?->nom_fichier ?: 'importé')
        .($lot?->format ? ' ('.$lot->format.')' : '')
        .($lot?->created_at ? ' déposé le '.$lot->created_at->format('d/m/Y') : '');
});

?>

<div>
    <x-titre-ecran titre="Trésorerie"
        sous-titre="Ce qui est entré, ce qui est sorti, et ce qu'il reste en caisse.">
        {{-- **Le bouton Caisse, demandé le 29/09.** Le lien existait déjà, mais noyé dans
             l'encart d'avertissement en dessous : personne ne va chercher un bouton dans un
             paragraphe. Les deux écrans se répondent — la Trésorerie porte les règlements et
             les charges, la Caisse porte en plus le journal du logiciel et l'écart entre les
             deux — et passer de l'un à l'autre est le geste le plus fréquent d'un comptable.
             Il est donc là où se trouvent les autres actions d'écran. --}}
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('caisse') }}" wire:navigate class="bouton"
                style="padding:8px 14px; text-decoration:none;">Caisse</a>
            <a href="{{ route('banques') }}" wire:navigate class="bouton"
                style="padding:8px 14px; text-decoration:none;">Banques</a>
        </div>
    </x-titre-ecran>

    {{-- **L'encart « cette page ne regroupe pas tout » a été retiré le 01/10, et il le
         fallait.** Il décrivait honnêtement un défaut au lieu de le corriger : le journal de
         caisse restait dehors, et la page disait qu'elle n'était pas une trésorerie.

         *« Ne me dis pas que la tréso ne voit que les encaissements de l'application : non.
         Comme le nom le dit, c'est une trésorerie. »* Le journal y entre désormais — voir
         `$journalQ` —, et il n'y a plus rien à avertir. Un écran qui explique ce qu'il ne
         sait pas faire use la confiance qu'on lui porte sur ce qu'il sait faire. --}}

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    {{-- ─────────────────────────────── ce que la trésorerie regroupe

         **Le cœur de la demande du 30/09** : « elle sera une page de KPI, montrant ce que la
         tréso regroupe ». Quatre cases, et elles répondent à la question qu'on pose à une
         trésorerie — *où est l'argent* —, pas à celle de savoir d'où vient la ligne.

         Ce bloc **ignore le filtre de support** : il est la carte des lieux. Le réduire au
         support déjà choisi afficherait une case pleine et trois vides, c'est-à-dire
         répéterait le filtre au lieu de le situer. Chaque case est cliquable et pose le
         filtre : on lit d'abord la carte, puis on entre. --}}
    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">Ce que la trésorerie regroupe</h3>
            @if ($supportFiltre !== '')
                <button type="button" wire:click="$set('supportFiltre', '')" class="bouton bouton-secondaire"
                    style="padding:4px 11px; font-size:12px;">Voir tous les supports</button>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px;">
            @foreach ($this->parSupport as $cle => $part)
                @php $actif = $supportFiltre === $cle; @endphp
                <button type="button" wire:click="$set('supportFiltre', '{{ $actif ? '' : $cle }}')"
                    @if ($actif) aria-current="true" @endif
                    style="text-align:left; cursor:pointer; font-family:inherit; padding:13px 15px;
                           border:1.5px solid {{ $actif ? '#191B20' : 'var(--th-ligne,#E2E0D8)' }};
                           border-radius:10px; background:{{ $actif ? '#FBFAF7' : '#fff' }};">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; font-weight:700;">
                        {{ $part['libelle'] }}
                    </div>
                    <div style="font-family:'Barlow Condensed',sans-serif; font-size:25px; font-weight:700;
                                font-variant-numeric:tabular-nums; white-space:nowrap;
                                color:{{ $part['net'] >= 0 ? '#0E9F6E' : '#C8102E' }};">
                        {{ ae($part['net']) }}
                    </div>
                    <div style="margin-top:6px; padding-top:6px; border-top:1px solid var(--th-ligne,#E2E0D8);
                                font-size:11.5px; color:#4B4E55; display:flex; flex-direction:column; gap:2px;">
                        <span style="display:flex; justify-content:space-between; gap:8px;">
                            <span>Encaissé</span><b style="font-variant-numeric:tabular-nums;">{{ ae($part['entrees']) }}</b>
                        </span>
                        <span style="display:flex; justify-content:space-between; gap:8px;">
                            <span>Décaissé</span><b style="font-variant-numeric:tabular-nums;">{{ ae($part['sorties']) }}</b>
                        </span>
                    </div>
                    <div style="margin-top:5px; font-size:11px; color:#6B6E76;">
                        {{ number_format($part['nombre'], 0, ',', ' ') }} écriture(s)
                    </div>
                </button>
            @endforeach
        </div>

        {{-- Dire ce que « non précisé » recouvre évite qu'on le prenne pour une anomalie :
             ce sont des lignes reprises d'un fichier qui ne disait pas le moyen. --}}
        <p style="margin:12px 0 0; font-size:12.5px; color:#6B6E76; line-height:1.55;">
            Le support se lit sur le <b>moyen de règlement</b> : un chèque et un virement passent
            par une banque, des espèces par le tiroir. « Moyen non précisé » n'est pas une
            anomalie — ce sont des lignes reprises d'un fichier qui ne disait pas comment.
            Le <b>mobile money</b> est à part : un portefeuille Orange Money ou Wave ne paraît
            sur aucun relevé bancaire.
        </p>
    </div>

    @if ($supportFiltre !== '')
        <p style="margin:-6px 0 14px; font-size:13px; color:#4B4E55;">
            Tout ce qui suit est filtré sur <b>{{ \Modules\Noyau\Exploitation\Services\SupportDeReglement::libelle($supportFiltre) }}</b>.
        </p>
    @endif

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        @php $ventile = ! $activiteFiltre; @endphp
        <x-kpi-card label="Encaissements — {{ $this->libellePerimetre }}" :value="ae($this->kpis['encaisse'])" couleur="#0E9F6E"
            :mecanique="$ventile ? ae($this->kpis['encaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['encaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['encaisseVentile']['nonVentile'] ? ae($this->kpis['encaisseVentile']['nonVentile']) : null" />
        <x-kpi-card label="Décaissements — {{ $this->libellePerimetre }}" :value="ae($this->kpis['decaisse'])" couleur="#C8102E"
            :mecanique="$ventile ? ae($this->kpis['decaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['decaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['decaisseVentile']['nonVentile'] ? ae($this->kpis['decaisseVentile']['nonVentile']) : null" />
        <x-kpi-card label="Trésorerie nette — {{ $this->libellePerimetre }}" :value="ae($this->kpis['net'])" :accent="$this->kpis['net'] < 0" :couleur="$this->kpis['net'] >= 0 ? '#0E9F6E' : '#C8102E'"
            :mecanique="$ventile ? ae($this->kpis['netVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['netVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['netVentile']['nonVentile'] ? ae($this->kpis['netVentile']['nonVentile']) : null" />
        <x-kpi-card label="Facturé non encaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['nonEncaisse'])" sub="Créances clients"
            :mecanique="$ventile ? ae($this->kpis['nonEncaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['nonEncaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['nonEncaisseVentile']['nonVentile'] ? ae($this->kpis['nonEncaisseVentile']['nonVentile']) : null" />
    </div>

    <div style="margin-bottom:20px;">
        <x-chart-card titre="Entrées et sorties" id="treso-hebdo"
            :labels="$this->graphique['labels']" :datasets="$this->graphique['datasets']" />
    </div>

    @if ($this->autres['totalEncaisse'] > 0 || $this->autres['totalDecaisse'] > 0)
        {{-- « Autres » ne dit rien par construction. Ce bloc dit ce qu'il contient, du plus
             lourd au plus léger — et ne s'affiche pas quand il n'y a rien dedans : un
             indicateur qui répète « zéro » cesse d'être lu. --}}
        <div class="carte" style="margin-bottom:20px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Ce que « Autres » recouvre</h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                Le détail des lignes rangées sous « Autres », par tiers et par poste, sur la période
                et le périmètre regardés.
            </p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <div>
                    <h4 style="font-size:13px; font-weight:700; margin:0 0 8px; color:#0E9F6E;">
                        Encaissements — {{ ae($this->autres['totalEncaisse']) }}
                    </h4>
                    @forelse ($this->autres['encaissements'] as $poste)
                        <div style="display:flex; justify-content:space-between; gap:12px; padding:5px 0;
                                    border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:13px;">
                            <span>{{ $poste['poste'] }}
                                <span style="color:#6B6E76; font-size:11px;">· {{ $poste['lignes'] }} ligne(s)</span>
                            </span>
                            <span style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($poste['montant']) }}</span>
                        </div>
                    @empty
                        <p style="margin:0; font-size:13px; color:#6B6E76;">Rien sous « Autres » sur cette période.</p>
                    @endforelse
                </div>

                <div>
                    <h4 style="font-size:13px; font-weight:700; margin:0 0 8px; color:#C8102E;">
                        Décaissements — {{ ae($this->autres['totalDecaisse']) }}
                    </h4>
                    @forelse ($this->autres['decaissements'] as $poste)
                        <div style="display:flex; justify-content:space-between; gap:12px; padding:5px 0;
                                    border-bottom:1px solid var(--th-ligne,#E2E0D8); font-size:13px;">
                            <span>{{ $poste['poste'] }}
                                <span style="color:#6B6E76; font-size:11px;">· {{ $poste['lignes'] }} ligne(s)</span>
                            </span>
                            <span style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($poste['montant']) }}</span>
                        </div>
                    @empty
                        <p style="margin:0; font-size:13px; color:#6B6E76;">Rien sous « Autres » sur cette période.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- Les deux tableaux se rangent l'un sous l'autre quand l'écran ne peut plus les tenir
         côte à côte : à moins de 430 px chacun, ils ne montraient plus rien d'utile. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(430px, 1fr)); gap:20px;">
        <div class="carte">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin:0 0 14px;">
                <h3 style="font-size:15px; font-weight:700; margin:0;">Encaissements ({{ number_format($this->nombreEncaissements, 0, ',', ' ') }})</h3>
                {{-- Les colonnes qu'aucun filtre ne couvre : le client, le type, le moyen,
                     la référence, le règlement global, le montant. Demandé le 28/09. --}}
                <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
            </div>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Référence</th>
                            <th>Type d'encaissement</th>
                            <th>Moyens</th>
                            <th>Montant</th>
                            <th>Clients</th>
                            <th>Facture réglée</th>
                            {{-- **Demandée le 28/09** : « affiche une colonne pour marquer
                                 l'origine (importé et saisi ici) ». C'est la première chose
                                 qu'on cherche quand un chiffre surprend — et jusqu'ici il
                                 fallait déplier chaque ligne pour la lire. --}}
                            <th>Origine</th>
                            <th class="colonne-collee"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->detailEncaissements as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ $ligne->date->format('d/m/Y') }}</td>
                                <td style="font-size:12px; color:#6B6E76;">{{ $ligne->numero ?: '—' }}</td>
                                <td>{{ $ligne->type }}</td>
                                <td>{{ $ligne->moyen }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; color:#0E9F6E;">{{ ae($ligne->montant) }}</td>
                                <td>{{ $ligne->client ?? '—' }}</td>
                                <td style="font-size:12.5px;">
                                    @if ($ligne->facture)
                                        <a href="{{ route('impayes.detail', $ligne->facture_id) }}" wire:navigate
                                           style="color:#2563EB;">{{ $ligne->facture->n_facture ?: $ligne->facture->numero }}</a>
                                    @else
                                        <span style="color:#9A9DA5;">—</span>
                                    @endif
                                </td>
                                <td style="white-space:nowrap; font-size:12px;">
                                    @if ($ligne->lot_import_id === null)
                                        <span style="color:#2563EB; font-weight:600;">Saisi ici</span>
                                    @else
                                        <span style="color:#6B6E76;">Importé</span>
                                    @endif
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    {{-- Une page, et non un panneau déplié : il poussait le tableau
                                         vers le bas, se perdait au changement de page et ne se
                                         transmettait pas. Demandé le 28/09. --}}
                                    <a href="{{ route('tresorerie.encaissement', $ligne->id) }}" wire:navigate
                                        class="bouton bouton-secondaire"
                                        style="padding:3px 9px; font-size:11.5px; text-decoration:none;">Détail</a>
                                </td>
                            </tr>

                        @empty
                            <x-table-vide :colspan="9" texte="Aucun encaissement sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageEncaissements" :total="$this->nombreEncaissements" prop="pageEncaissements" />
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Décaissements ({{ number_format($this->nombreDecaissements, 0, ',', ' ') }})</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type d'opération</th>
                            <th>Libellé d'opération</th>
                            <th>Moyens</th>
                            <th>Montant</th>
                            <th>Tiers</th>
                            <th>Origine</th>
                            <th class="colonne-collee"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->detailDecaissements as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $ligne->date->format('d/m/Y') }}</td>
                                <td>{{ $ligne->type_operation }}</td>
                                <td>{{ $ligne->libelle }}</td>
                                <td>{{ $ligne->moyen }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; color:#C8102E;">{{ ae($ligne->montant) }}</td>
                                <td style="color:#6B6E76;">{{ $ligne->tiers ?? '—' }}</td>
                                {{-- Toutes les charges sont saisies ici aujourd'hui — mesuré :
                                     198 sur 198. La colonne le dit plutôt que de le laisser
                                     supposer, et elle dira autre chose le jour où un fichier
                                     de charges sera déposé. --}}
                                <td style="white-space:nowrap; font-size:12px;">
                                    @if ($ligne->lot_import_id === null)
                                        <span style="color:#2563EB; font-weight:600;">Saisi ici</span>
                                    @else
                                        <span style="color:#6B6E76;">Importé</span>
                                    @endif
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" wire:click="voirDecaissement({{ $ligne->id }})"
                                        class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">
                                        {{ (int) $detailDecaissement === (int) $ligne->id ? 'Fermer' : 'Détail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ((int) $detailDecaissement === (int) $ligne->id)
                                <tr style="background:#F7F5EF;">
                                    <td colspan="8" style="font-size:12.5px; padding:10px 12px;">
                                        <div><b>Référence</b> : {{ $ligne->numero ?: '—' }}</div>
                                        <div><b>Origine</b> : {{ $this->origineDe($ligne) }}</div>
                                        <div><b>Atelier</b> : {{ $ligne->site?->nom ?: '—' }}</div>
                                        <div><b>Activité</b> : {{ $ligne->activite ?: 'non ventilée' }}</div>
                                        @if ($ligne->observations)
                                            <div><b>Observations</b> : {{ $ligne->observations }}</div>
                                        @endif
                                        @if ($ligne->reference_origine)
                                            <div><b>Référence d'origine</b> : {{ $ligne->reference_origine }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <x-table-vide :colspan="8" texte="Aucun décaissement sur cette période." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-pagination :page="$pageDecaissements" :total="$this->nombreDecaissements" prop="pageDecaissements" />
        </div>
    </div>
</div>

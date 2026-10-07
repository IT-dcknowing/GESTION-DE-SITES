<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\RapprochementDeTresorerie;
use Modules\Noyau\Exploitation\Services\SupportDeReglement;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Exploitation\Services\MouvementsDeTresorerie;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\SerieParPoint;
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

    /*
     * Les lignes de rapprochement — 07/10. Le tableau ouvert (« banque » ou « caisse »), et le
     * filtre « identique / pas identique ».
     */
    'rapprochement' => '',
    'rapprochementEtat' => '',
    'pageRapprochement' => 1,
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
     * **Les règlements seuls — depuis le 07/10.** Le journal de caisse y était ajouté le 01/10,
     * et c'était une erreur de méthode : un règlement en espèces est écrit à l'état des
     * impayés **et** au journal, un chèque à l'état **et** au relevé. Les additionner comptait
     * cet argent deux fois. « Sans rien mélanger — ni gonfler, ni diminuer » : la caisse et la
     * banque ont désormais leur bloc, `$reels`, et la comparaison le sien. Rien n'a quitté
     * l'écran ; seule l'addition entre familles a disparu.
     */
    $encaisse = (int) (clone $this->encaissementsQ)->sum('montant');
    $decaisse = (int) (clone $this->chargesQ)->sum('montant');
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

    // Le journal n'est plus ajouté à la case « Caisse » depuis le 07/10 : il a son bloc,
    // `$reels`, à côté des règlements et non dedans. Voir `$kpis`.

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

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Selon la caisse et la banque — demandé le 07/10.
 *
 * Les données réellement importées — le journal de caisse, les relevés bancaires — lues
 * telles quelles, à côté des règlements et jamais additionnées à eux. Voir
 * `RapprochementDeTresorerie` pour le pourquoi.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
$reels = computed(function () {
    [$debut, $fin] = $this->plage;

    $brut = (clone $this->journalQ)
        ->selectRaw(
            'count(*) as nombre, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as entrees, '
            .'coalesce(sum(case when sens = ? then montant else 0 end), 0) as sorties',
            [MouvementCaisse::ENTREE, MouvementCaisse::SORTIE],
        )
        ->first();

    $releves = RapprochementDeTresorerie::releves($debut, $fin);

    return [
        'caisse' => [
            'entrees' => (int) ($brut->entrees ?? 0),
            'sorties' => (int) ($brut->sorties ?? 0),
            'nombre' => (int) ($brut->nombre ?? 0),
        ],
        'banques' => $releves,
        'banque' => [
            'credits' => (int) $releves->sum('credits'),
            'debits' => (int) $releves->sum('debits'),
            // La somme des soldes annoncés, compte par compte : un solde ne se recalcule pas.
            'solde' => $releves->every(fn ($r) => $r['solde_fin'] === null) ? null : (int) $releves->sum('solde_fin'),
        ],
    ];
});

/**
 * Les règlements par côté — en sommes, en base. C'est ce que la comparaison affiche toujours.
 *
 * Le pointage ligne à ligne (`$rapprochements`) ne se fait qu'à l'ouverture d'un tableau :
 * mesuré le 07/10 sur 7 000 règlements et 6 800 opérations, le faire à chaque clic portait
 * l'écran de 0,04 s à 0,67 s.
 */
$reglementsParCote = computed(function () {
    [$debut, $fin] = $this->plage;

    $base = fn () => PerimetreDeTresorerie::encaissements(Encaissement::query(), $this->idsSites, $this->idsVilles)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);

    return [
        'banque' => (int) SupportDeReglement::appliquer($base(), 'encaissements', SupportDeReglement::BANQUE)->sum('montant')
            + (int) SupportDeReglement::appliquer($base(), 'encaissements', SupportDeReglement::INCONNU)->sum('montant'),
        'caisse' => (int) SupportDeReglement::appliquer($base(), 'encaissements', SupportDeReglement::CAISSE)->sum('montant'),
    ];
});

/** Les règlements d'un support, sur le périmètre et la période, sans le filtre de support de l'écran. */
$reglementsDuSupport = protect(function (array $supports) {
    [$debut, $fin] = $this->plage;

    $base = fn () => PerimetreDeTresorerie::encaissements(Encaissement::query(), $this->idsSites, $this->idsVilles)
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->whereBetween('date', [$debut, $fin]);

    return collect($supports)->flatMap(fn ($support) => SupportDeReglement::appliquer($base(), 'encaissements', $support)
        ->with('facture:id,n_facture')
        ->get(['id', 'date', 'montant', 'client', 'moyen', 'facture_id', 'banque_id'])
        ->map(fn ($e) => (object) [
            'id' => (int) $e->id, 'date' => $e->date, 'montant' => (int) $e->montant, 'client' => $e->client,
            'moyen' => $e->moyen, 'support' => $support, 'facture_id' => $e->facture_id,
            'facture' => $e->facture?->n_facture,
        ]));
});

/**
 * Les deux rapprochements, ligne à ligne : chaque règlement, et l'opération retenue.
 *
 * **CA-Banque** : les règlements par banque, **et ceux dont le moyen n'est pas dit** — un
 * règlement repris d'un fichier muet sur le moyen peut fort bien être passé par la banque ;
 * c'est justement le pointage qui le dira. **CA-Caisse** : les règlements en espèces, face aux
 * entrées du journal de caisse.
 */
$rapprochements = computed(function () {
    [$debut, $fin] = $this->plage;
    $de = Illuminate\Support\Carbon::parse($debut);
    $a = Illuminate\Support\Carbon::parse($fin);

    $credits = fn () => Modules\Noyau\Imports\Modeles\MouvementBancaire::query()
        ->where('sens', Modules\Noyau\Imports\Modeles\MouvementBancaire::ENTREE)
        ->whereBetween('date_operation', [
            $de->copy()->subDays(RapprochementDeTresorerie::FENETRE_BANQUE[0])->toDateString(),
            $a->copy()->addDays(RapprochementDeTresorerie::FENETRE_BANQUE[1])->toDateString(),
        ])
        ->with('banque:id,nom')
        ->get(['id', 'banque_id', 'date_operation', 'credit', 'libelle', 'contrepartie'])
        ->map(fn ($m) => (object) [
            'id' => (int) $m->id, 'date' => $m->date_operation, 'montant' => (int) $m->credit,
            'libelle' => $m->libelle, 'contrepartie' => $m->contrepartie, 'ou' => $m->banque?->nom,
        ]);

    $entreesCaisse = fn () => PerimetreDeTresorerie::mouvementsDeCaisse(MouvementCaisse::query(), $this->idsSites, $this->idsVilles)
        ->where('sens', MouvementCaisse::ENTREE)
        ->whereBetween('mouvements_caisse.date', [
            $de->copy()->subDays(RapprochementDeTresorerie::FENETRE_CAISSE[0])->toDateString(),
            $a->copy()->addDays(RapprochementDeTresorerie::FENETRE_CAISSE[1])->toDateString(),
        ])
        ->get(['mouvements_caisse.id', 'mouvements_caisse.date', 'mouvements_caisse.montant', 'mouvements_caisse.libelle'])
        ->map(fn ($m) => (object) [
            'id' => (int) $m->id, 'date' => $m->date, 'montant' => (int) $m->montant,
            'libelle' => $m->libelle, 'contrepartie' => null, 'ou' => 'Journal de caisse',
        ]);

    // Les dates en texte « AAAA-MM-JJ » : elles se comparent et se trient telles quelles, sans
    // fabriquer un objet date par ligne — mesuré, c'était l'essentiel du clic.
    $jour = fn ($date) => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
    [$premier, $dernier] = [$de->format('Y-m-d'), $a->format('Y-m-d')];

    $faire = function ($reglements, $operations, $fenetre) use ($jour, $premier, $dernier) {
        $paires = RapprochementDeTresorerie::rapprocher($reglements, $operations, $fenetre);
        $lignes = $reglements->map(fn ($r) => (object) ((array) $r + [
            'operation' => $paires[$r->id] ?? null,
        ]))->sortByDesc(fn ($r) => $jour($r->date).sprintf('%012d', $r->id))->values();

        $prises = array_flip(collect($paires)->filter()->pluck('id')->all());
        $orphelines = $operations->filter(fn ($o) => ! isset($prises[$o->id])
            && $jour($o->date) >= $premier && $jour($o->date) <= $dernier);

        return [
            'lignes' => $lignes,
            'identiques' => ['nombre' => $lignes->whereNotNull('operation')->count(), 'montant' => (int) $lignes->whereNotNull('operation')->sum('montant')],
            'differents' => ['nombre' => $lignes->whereNull('operation')->count(), 'montant' => (int) $lignes->whereNull('operation')->sum('montant')],
            'orphelines' => ['nombre' => $orphelines->count(), 'montant' => (int) $orphelines->sum('montant')],
        ];
    };

    // Seul le côté ouvert est pointé : l'autre ne coûte rien tant qu'on ne le demande pas.
    return [
        'banque' => $this->rapprochement === 'banque'
            ? $faire($this->reglementsDuSupport([SupportDeReglement::BANQUE, SupportDeReglement::INCONNU]), $credits(), RapprochementDeTresorerie::FENETRE_BANQUE)
            : null,
        'caisse' => $this->rapprochement === 'caisse'
            ? $faire($this->reglementsDuSupport([SupportDeReglement::CAISSE]), $entreesCaisse(), RapprochementDeTresorerie::FENETRE_CAISSE)
            : null,
    ];
});

/** La page de lignes demandée, filtrée par « identique / pas identique ». */
$lignesRapprochement = computed(function () {
    if (! in_array($this->rapprochement, ['banque', 'caisse'], true)) {
        return collect();
    }

    return ($this->rapprochements[$this->rapprochement]['lignes'] ?? collect())
        ->when($this->rapprochementEtat === 'identique', fn ($l) => $l->whereNotNull('operation'))
        ->when($this->rapprochementEtat === 'different', fn ($l) => $l->whereNull('operation'))
        ->values();
});

$updatedRapprochementEtat = function () { $this->pageRapprochement = 1; };

$ouvrirLeRapprochement = function (string $quoi) {
    $this->rapprochement = $this->rapprochement === $quoi ? '' : (in_array($quoi, ['banque', 'caisse'], true) ? $quoi : '');
    $this->pageRapprochement = 1;
};

$graphique = computed(function () {
    [$debut, $fin] = $this->plage;
    $points = PeriodeCalculateur::points($debut, $fin);

    $labels = [];
    $entrees = [];
    $sorties = [];
    $cumul = [];
    $total = 0;

    /*
     * **Deux requêtes pour la courbe, et non deux par point.**
     *
     * Mesuré le 02/10 : un changement de filtre sur cet écran coûtait cinquante-trois
     * requêtes, dont **vingt-six ici** — une somme par point, pour les entrées et pour les
     * sorties. Le calcul est le même, et `SerieParPoint` le tient par un test qui le compare
     * chiffre par chiffre à la boucle qu'il remplace.
     */
    $sommesEntrees = SerieParPoint::sommes($this->encaissementsQ, $points, 'date', 'montant');
    $sommesSorties = SerieParPoint::sommes($this->chargesQ, $points, 'date', 'montant');

    foreach ($points as $rang => $point) {
        $e = $sommesEntrees[$rang];
        $so = $sommesSorties[$rang];
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

/*
 * **Les deux listes portent aussi le journal de caisse — 02/10.**
 *
 * *« Les encaissements et décaissements doivent enregistrer ceux importés, c'est juste qu'il
 * les liste, c'est tout. La tréso prend tout, pas seulement ceux d'ici. »*
 *
 * Le journal était entré dans les **totaux** le 01/10 et dans aucune des deux **listes**.
 * Sur le serveur, cela donnait « Décaissements (0) » sous un total de sorties de
 * 436 783 059 F : l'écran se contredisait à dix centimètres d'intervalle.
 *
 * L'union se trie et se pagine en base. Charger les deux sources pour en afficher dix
 * remettrait sur cet écran ce qu'on en a retiré la veille.
 */
$unionEncaissements = computed(fn () => MouvementsDeTresorerie::entrees(
    (clone $this->requeteEncaissements)->toBase(),
    $this->journalCompte ? (clone $this->journalQ)->toBase() : null,
    $this->colonnesFiltrables,
    (array) $this->filtresLibres,
));

$nombreEncaissements = computed(fn () => MouvementsDeTresorerie::compter($this->unionEncaissements));

$detailEncaissements = computed(fn () => $this->garnir(
    MouvementsDeTresorerie::page($this->unionEncaissements, (int) $this->pageEncaissements),
));

$unionDecaissements = computed(fn () => MouvementsDeTresorerie::sorties(
    (clone $this->chargesQ)->toBase(),
    $this->journalCompte ? (clone $this->journalQ)->toBase() : null,
));

$nombreDecaissements = computed(fn () => MouvementsDeTresorerie::compter($this->unionDecaissements));

$detailDecaissements = computed(fn () => $this->garnir(
    MouvementsDeTresorerie::page($this->unionDecaissements, (int) $this->pageDecaissements),
));

/**
 * Les noms que l'union ne peut pas ramener : l'atelier, et la facture réglée.
 *
 * Une union de deux tables ne porte pas de relations Eloquent. Plutôt qu'une requête par
 * ligne, on relit les deux en un coup sur les seuls identifiants de la page — dix lignes,
 * donc deux requêtes au plus, et souvent zéro.
 *
 * @param  \Illuminate\Support\Collection<int, \stdClass>  $lignes
 */
$garnir = protect(function ($lignes) {
    $sites = Site::whereIn('id', $lignes->pluck('site_id')->filter()->unique())->pluck('nom', 'id');

    $factures = Facture::whereIn('id', $lignes->pluck('facture_id')->filter()->unique())
        ->get(['id', 'numero', 'n_facture'])->keyBy('id');

    return $lignes->map(function ($ligne) use ($sites, $factures) {
        $ligne->date = \Illuminate\Support\Carbon::parse($ligne->date);
        $ligne->site_nom = $sites[$ligne->site_id] ?? null;
        $ligne->facture = isset($ligne->facture_id) ? ($factures[$ligne->facture_id] ?? null) : null;
        $ligne->vientDuJournal = $ligne->source === 'caisse';

        return $ligne;
    });
});

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
            <h3 style="font-size:15px; font-weight:700; margin:0;">Selon les règlements — par où l'argent est passé</h3>
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

    {{-- Les quatre cartes lisent les **règlements** : ce que le CA, l'état des impayés et les
         saisies disent avoir encaissé et payé. La caisse et la banque réelles ont leur bloc,
         juste en dessous, et ne s'y additionnent pas — 07/10. --}}
    <h3 style="font-size:14px; font-weight:700; margin:0 0 8px;">Selon les règlements — CA, impayés, saisies</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:16px;">
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

    {{-- ─────────────────────────────── selon la caisse et la banque — 07/10

         « Une section de KPI qui montre la situation de la caisse ou de la banque selon ce qui
         la nourrit — le CA ou les impayés —, et tu gardes les données de caisse et de banque
         réellement importées, sans toucher à quelque chose ; ensuite un KPI de comparaison. »
         Les soldes de banque sont ceux que la banque annonce, jamais recalculés. --}}
    @php $reels = $this->reels; $rap = $this->rapprochements; @endphp
    <div class="carte" style="margin-bottom:16px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Selon la caisse et la banque — relevés importés, tels quels</h3>
        <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
            Le journal de caisse et les relevés bancaires, lus sans rien y changer. Ils ne s'ajoutent
            pas aux règlements ci-dessus : un chèque est écrit à l'état des impayés <b>et</b> au
            relevé — les additionner le compterait deux fois. Les comptes bancaires sont ceux de
            l'entreprise : le filtre de ville ne s'y applique pas.
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px;">
            <div style="padding:13px 15px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px;">
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; font-weight:700;">Caisse — journal importé</div>
                <div style="font-family:'Barlow Condensed',sans-serif; font-size:25px; font-weight:700; font-variant-numeric:tabular-nums;
                            color:{{ $reels['caisse']['entrees'] - $reels['caisse']['sorties'] >= 0 ? '#0E9F6E' : '#C8102E' }};">
                    {{ ae($reels['caisse']['entrees'] - $reels['caisse']['sorties']) }}
                </div>
                <div style="font-size:11.5px; color:#4B4E55;">Entrées <b>{{ ae($reels['caisse']['entrees']) }}</b> · Sorties <b>{{ ae($reels['caisse']['sorties']) }}</b></div>
                <div style="font-size:11px; color:#6B6E76; margin-top:4px;">
                    {{ $reels['caisse']['nombre'] > 0 ? number_format($reels['caisse']['nombre'], 0, ',', ' ').' mouvement(s) sur la période' : 'Aucun journal de caisse déposé pour cette période.' }}
                </div>
            </div>

            @forelse ($reels['banques'] as $releve)
                <div style="padding:13px 15px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#6B6E76; font-weight:700;">Banque — {{ $releve['banque']->nom }}</div>
                    <div style="font-family:'Barlow Condensed',sans-serif; font-size:25px; font-weight:700; font-variant-numeric:tabular-nums;
                                color:{{ ($releve['solde_fin'] ?? 0) >= 0 ? '#0E9F6E' : '#C8102E' }};">
                        {{ $releve['solde_fin'] === null ? '—' : ae($releve['solde_fin']) }}
                    </div>
                    <div style="font-size:11.5px; color:#4B4E55;">Crédits <b>{{ ae($releve['credits']) }}</b> · Débits <b>{{ ae($releve['debits']) }}</b></div>
                    <div style="font-size:11px; color:#6B6E76; margin-top:4px;">
                        Solde annoncé par la banque{{ $releve['date_solde'] ? ' au '.\Illuminate\Support\Carbon::parse($releve['date_solde'])->format('d/m/Y') : '' }}
                    </div>
                </div>
            @empty
                <div style="padding:13px 15px; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:10px; font-size:12.5px; color:#6B6E76;">
                    Aucun relevé bancaire déposé. Les relevés se déposent à l'import, type
                    « Relevé bancaire — suivi de la caissière », en choisissant le compte.
                </div>
            @endforelse
        </div>

        {{-- La comparaison : ce que les règlements disent, face à ce que la banque et la caisse ont vu. --}}
        <h4 style="font-size:13.5px; font-weight:700; margin:16px 0 8px;">Comparaison — règlements face aux relevés</h4>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:10px;">
            @foreach (['banque' => 'Banque', 'caisse' => 'Caisse'] as $cote => $nom)
                @php
                    $r = $rap[$cote];
                    $releve = $cote === 'banque' ? $reels['banque']['credits'] : $reels['caisse']['entrees'];
                    $declare = $this->reglementsParCote[$cote];
                @endphp
                <div style="padding:12px 14px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; font-size:12.5px;">
                    <div style="display:flex; justify-content:space-between;"><span>{{ $cote === 'banque' ? 'Crédits des relevés' : 'Entrées du journal de caisse' }}</span><b>{{ ae($releve) }}</b></div>
                    <div style="display:flex; justify-content:space-between;"><span>Règlements {{ $cote === 'banque' ? 'par banque ou sans moyen dit' : 'en espèces' }}</span><b>{{ ae($declare) }}</b></div>
                    <div style="display:flex; justify-content:space-between; border-top:1px solid var(--th-ligne,#E2E0D8); margin-top:4px; padding-top:4px;">
                        <span>Écart ({{ $nom }} − règlements)</span>
                        <b style="color:{{ $releve - $declare === 0 ? '#0E9F6E' : '#C8102E' }};">{{ ae($releve - $declare) }}</b></div>
                    @if ($r === null)
                        <div style="color:#6B6E76; font-size:11.5px; margin-top:6px;">Le pointage ligne à ligne s'affiche en ouvrant « Lignes rapprochement CA-{{ $nom }} ».</div>
                    @else
                    <div style="display:flex; justify-content:space-between;"><span>Identiques — retrouvés {{ $cote === 'banque' ? 'à la banque' : 'au journal' }}</span>
                        <b style="color:#0E9F6E;">{{ $r['identiques']['nombre'] }} · {{ ae($r['identiques']['montant']) }}</b></div>
                    <div style="display:flex; justify-content:space-between;"><span>Pas identiques — non retrouvés</span>
                        <b style="color:#C8102E;">{{ $r['differents']['nombre'] }} · {{ ae($r['differents']['montant']) }}</b></div>
                    <div style="display:flex; justify-content:space-between;"><span>{{ $cote === 'banque' ? 'Crédits de banque' : 'Entrées de caisse' }} sans règlement</span>
                        <b>{{ $r['orphelines']['nombre'] }} · {{ ae($r['orphelines']['montant']) }}</b></div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Les lignes, comme « Où part l'argent » : un bouton, un tableau, une question à la fois. --}}
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
            <button type="button" wire:click="ouvrirLeRapprochement('banque')"
                class="bouton {{ $rapprochement === 'banque' ? '' : 'bouton-secondaire' }}" style="padding:7px 13px;">
                Lignes rapprochement CA-Banque
            </button>
            <button type="button" wire:click="ouvrirLeRapprochement('caisse')"
                class="bouton {{ $rapprochement === 'caisse' ? '' : 'bouton-secondaire' }}" style="padding:7px 13px;">
                Lignes rapprochement CA-Caisse
            </button>
        </div>

        @if ($rapprochement !== '')
            <div style="margin-top:12px;">
                <div style="display:flex; align-items:end; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                    <x-champ label="État" model="rapprochementEtat" type="select" live="true" width="200"
                        :options="['' => 'Tous', 'identique' => 'Identique', 'different' => 'Pas identique']" />
                    <span style="font-size:12.5px; color:#6B6E76;">
                        {{ $rapprochement === 'banque'
                            ? 'Règlements par banque, et ceux dont le moyen n\'est pas dit, face aux crédits des relevés : même montant, de 3 jours avant à 10 jours après.'
                            : 'Règlements en espèces face aux entrées du journal de caisse : même montant, à 3 jours près.' }}
                        Chaque opération ne sert qu'une fois.
                    </span>
                </div>
                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th>Date</th><th>Client</th><th>Facture</th><th>Moyen</th><th>Montant</th><th>État</th>
                                <th>{{ $rapprochement === 'banque' ? 'Opération de banque retenue' : 'Entrée de caisse retenue' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->lignesRapprochement->forPage($pageRapprochement, 25) as $ligne)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                    <td style="white-space:nowrap;">{{ \Illuminate\Support\Carbon::parse($ligne->date)->format('d/m/Y') }}</td>
                                    <td>{{ $ligne->client ?? '—' }}</td>
                                    <td>{{ $ligne->facture ?? '—' }}</td>
                                    <td>{{ $ligne->moyen ?: 'non précisé' }}</td>
                                    <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne->montant) }}</td>
                                    <td>
                                        @if ($ligne->operation)
                                            <span style="color:#0E9F6E; font-weight:700;">Identique</span>
                                        @else
                                            <span style="color:#C8102E; font-weight:700;">Pas identique</span>
                                        @endif
                                    </td>
                                    <td style="font-size:12px;">
                                        @if ($ligne->operation)
                                            {{ \Illuminate\Support\Carbon::parse($ligne->operation->date)->format('d/m/Y') }}
                                            · {{ $ligne->operation->ou }} · {{ $ligne->operation->contrepartie ?: $ligne->operation->libelle }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="7" texte="Aucune ligne pour ce filtre sur la période." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-pagination :page="$pageRapprochement" :total="$this->lignesRapprochement->count()" prop="pageRapprochement" :par-page="25" />
            </div>
        @endif
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
                                    {{-- Trois origines depuis le 02/10, et non deux : le journal de
                                         caisse se dit lui-même, sans quoi on le prendrait pour un
                                         encaissement importé et l'on chercherait sa facture. --}}
                                    @if ($ligne->vientDuJournal)
                                        <span style="color:#B87A00; font-weight:600;">Journal de caisse</span>
                                    @elseif ($ligne->lot_import_id === null)
                                        <span style="color:#2563EB; font-weight:600;">Saisi ici</span>
                                    @else
                                        <span style="color:#6B6E76;">Importé</span>
                                    @endif
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    {{-- Une page, et non un panneau déplié : il poussait le tableau
                                         vers le bas, se perdait au changement de page et ne se
                                         transmettait pas. Demandé le 28/09.

                                         Une ligne du journal n'a pas de page à elle : elle renvoie
                                         à la caisse, qui est l'endroit où elle se lit en entier. --}}
                                    <a href="{{ $ligne->vientDuJournal ? route('caisse') : route('tresorerie.encaissement', $ligne->id) }}"
                                        wire:navigate class="bouton bouton-secondaire"
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
                                {{-- Trois origines depuis le 02/10. Les charges sont toutes saisies
                                     ici — mesuré : 198 sur 198 —, et le journal de caisse apporte
                                     les sorties d'espèces qui n'étaient listées nulle part alors
                                     qu'elles comptaient déjà dans le total au-dessus. --}}
                                <td style="white-space:nowrap; font-size:12px;">
                                    @if ($ligne->vientDuJournal)
                                        <span style="color:#B87A00; font-weight:600;">Journal de caisse</span>
                                    @elseif ($ligne->lot_import_id === null)
                                        <span style="color:#2563EB; font-weight:600;">Saisi ici</span>
                                    @else
                                        <span style="color:#6B6E76;">Importé</span>
                                    @endif
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    @if ($ligne->vientDuJournal)
                                        <a href="{{ route('caisse') }}" wire:navigate class="bouton bouton-secondaire"
                                            style="padding:3px 9px; font-size:11.5px; text-decoration:none;">Détail</a>
                                    @else
                                        <button type="button" wire:click="voirDecaissement({{ $ligne->id }})"
                                            class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">
                                            {{ (int) $detailDecaissement === (int) $ligne->id ? 'Fermer' : 'Détail' }}
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            {{-- L'union de deux tables ne porte pas de relations : l'atelier est
                                 relu à part pour les seules lignes de la page. Observations et
                                 référence d'origine quittent ce panneau — elles ne valaient que
                                 pour une charge, et le détail complet est sur la page de la
                                 charge elle-même. --}}
                            @if (! $ligne->vientDuJournal && (int) $detailDecaissement === (int) $ligne->id)
                                <tr style="background:#F7F5EF;">
                                    <td colspan="8" style="font-size:12.5px; padding:10px 12px;">
                                        <div><b>Référence</b> : {{ $ligne->numero ?: '—' }}</div>
                                        <div><b>Atelier</b> : {{ $ligne->site_nom ?: '—' }}</div>
                                        <div><b>Activité</b> : {{ $ligne->activite ?: 'non ventilée' }}</div>
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

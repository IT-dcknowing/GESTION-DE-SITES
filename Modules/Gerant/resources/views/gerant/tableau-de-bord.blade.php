<?php

use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Formats\Format;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Modeles\SaisieJournaliere;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\SerieParPoint;
use Modules\Noyau\Commun\Services\VentilationActivite;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\SyntheseParAtelier;
use Modules\Noyau\Exploitation\Services\TransformationDesDevis;
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
    'commercialFiltre' => '',
    'pageSynthese' => 1,
    'pageAlertes' => 1,
    'pageCommentaires' => [],
    // Le mois des objectifs (AAAA-MM) — 20/10 : « c'est par mois qu'on vérifie ».
    'moisObjectifs' => '',
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

$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre, $this->siteFiltre, $this->activiteFiltre));

/** Lieux réellement pris en compte par le filtre ville + site. */
$sitesRetenus = computed(function () {
    $ids = PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre);

    return Site::whereIn('id', $ids)->with('ville')->orderBy('nom')->get();
});

/**
 * Commerciaux des sites retenus, pour le filtre qui apparaît une fois la ville connue —
 * y compris les "Client spontané" : une vente sans commercial nommé reste un cas réel
 * qu'on doit pouvoir isoler.
 */
// Un commercial travaille pour toute une ville, jamais pour un seul de ses sites.
$commerciaux = computed(fn () => Commercial::whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre))->orderBy('est_spontane')->orderBy('nom')->get());

/**
 * Résout le filtre commercial en identifiants concrets : chaque ville a son propre
 * "Client spontané", donc choisir "Client spontané" dans la liste doit filtrer sur
 * ceux de toutes les villes retenues à la fois, pas sur un seul.
 */
$idsCommercialFiltre = computed(function () {
    if ($this->commercialFiltre === 'spontane') {
        return $this->commerciaux->where('est_spontane', true)->pluck('id')->all();
    }

    return $this->commercialFiltre ? [(int) $this->commercialFiltre] : null;
});

/**
 * Les villes des ateliers retenus, pour placer les lignes qui n'ont que leur ville.
 *
 * Le 05/10, cet écran est resté à 0 F partout après l'import de l'état des impayés et de
 * données de trésorerie : les lignes importées portent une ville et presque jamais
 * d'atelier, et l'écran ne les cherchait que par atelier. Voir `SyntheseParAtelier`.
 */
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->sitesRetenus->pluck('id')->all()));

/**
 * Une ligne par atelier retenu, puis une par ville pour ce qui n'a pas d'atelier. Cinq
 * requêtes en tout au lieu de six par atelier : mesuré le 02/10, ce tableau coûtait trente
 * des requêtes d'un clic.
 */
$synthese = computed(function () {
    [$debut, $fin] = $this->plage;

    $lignes = SyntheseParAtelier::calculer($this->sitesRetenus, $debut, $fin, (string) $this->activiteFiltre, $this->idsCommercialFiltre, $this->idsVilles);

    /*
     * **« Devis en attente » sans les devis facturés — 12/10.** La colonne comptait le statut
     * « En attente », que porte tout devis importé, facturé ou non : 2 484 pour Abidjan 1.
     * Un devis suivi d'une facture n'attend plus rien (`TransformationDesDevis`).
     */
    $transformes = $this->devisTransformes;
    $parSite = $this->devisLus->where('statut', 'En attente')
        ->reject(fn ($d) => isset($transformes[(int) $d->id]))
        ->countBy(fn ($d) => (string) $d->site_id);

    $lignes = $lignes->map(function (array $ligne) use ($parSite) {
        $site = $ligne['site'];
        // Une ligne « Ville — atelier non précisé » ne porte pas de devis : un devis sans
        // atelier ne dit pas sa ville. La dernière ligne, « Lieu non précisé », les prend.
        $ligne['devisAttente'] = $site->id !== null
            ? (int) ($parSite[(string) $site->id] ?? 0)
            : ($site->relationLoaded('ville') ? 0 : (int) ($parSite[''] ?? 0));

        return $ligne;
    });

    /*
     * **Le tableau doit refaire le CA — 12/10.** « Le montant annoncé n'est pas le vrai total :
     * le CA fait 1 867 767 452 et le cumul de la synthèse ne fait pas ce calcul. » Pour celui
     * qui voit tout, le CA de l'entreprise sur la période se compte une seconde fois **sans
     * regarder le lieu** ; ce que les lignes ci-dessus n'ont pas pris (un atelier ou une ville
     * désactivés, une ligne dont le lieu n'est dans aucun d'eux) paraît dans une ligne qui le
     * dit, au lieu de manquer en silence. Le total est alors celui du CA, pas celui du tableau.
     */
    if ($this->voitToutLeCa) {
        $complet = montant_de(Facture::query()->duChiffreDAffaires()
            ->whereBetween('date', $this->plage)
            ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
            ->when($this->idsCommercialFiltre !== null, fn ($q) => $q->whereIn('commercial_id', $this->idsCommercialFiltre))
            ->sum('montant'));
        $ecart = montant_de($complet - montant_de($lignes->sum('ca')));

        if ($ecart !== 0) {
            $lignes->push([
                'site' => new Site(['nom' => 'Autres lignes du CA']),
                'genre' => 'hors',
                'ca' => $ecart, 'charges' => 0, 'resultat' => $ecart,
                'encaisse' => 0, 'treso' => 0, 'devisAttente' => 0, 'sansFacture' => 0,
            ]);
        }
    }

    return $lignes;
});

/** Celui qui voit toute l'entreprise, sans filtre de lieu : le seul pour qui « le CA » a un total unique. */
$voitToutLeCa = computed(fn () => auth()->user()->hasRole('gerant')
    && $this->villeFiltre === '' && $this->siteFiltre === '');

/**
 * Les lignes du dernier fichier du CA que l'import a **refusées** — date absente ou illisible,
 * montant illisible. Elles ne sont dans aucune facture, donc dans aucun total ; le montant que
 * le fichier leur donne est dit ici pour qu'on retrouve le total de son classeur.
 */
$rejetsDuCa = computed(function () {
    $lot = LotImport::query()->where('format', 'factures')->where('etat', 'termine')->latest('id')->first();

    if ($lot === null) {
        return null;
    }

    $rejets = $lot->rejets()->get(['motif', 'valeurs']);

    if ($rejets->isEmpty()) {
        return null;
    }

    return [
        'n' => $rejets->count(),
        'montant' => montant_de($rejets->sum(fn ($r) => (float) (Format::montant($r->valeurs['montant'] ?? null) ?? 0))),
        'motifs' => $rejets->countBy('motif')->sortDesc()->all(),
        'fichier' => $lot->nom_fichier,
    ];
});

/**
 * Les devis de la période, en colonnes légères, lus **une fois** : les trois taux et la
 * colonne « Devis en attente » de la synthèse se calculent sur la même liste.
 */
$devisLus = computed(function () {
    [$debut, $fin] = $this->plage;
    $idsSites = $this->sitesRetenus->pluck('id');

    // Un devis sans atelier ne porte pas de ville : il paraît, comme dans le tableau.
    return Devis::where(fn ($q) => $q->whereIn('site_id', $idsSites)->orWhereNull('site_id'))
        ->whereBetween('date_emission', [$debut, $fin])
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->when($this->idsCommercialFiltre !== null, fn ($q) => $q->whereIn('commercial_id', $this->idsCommercialFiltre))
        ->get(['id', 'site_id', 'activite', 'statut', 'n_fiche_reception', 'date_emission']);
});

$devisTransformes = computed(fn () => TransformationDesDevis::transformes($this->devisLus));

/**
 * La première section : le CA seul. Une ligne sans atelier qui ne porte ni CA ni devis n'y
 * paraît pas — elle n'existait que par ses règlements, qui sont à la seconde section. Elle
 * reste dans `$synthese`, dont les cartes du haut font la somme.
 */
$syntheseDuCa = computed(fn () => $this->synthese
    ->reject(fn ($l) => ($l['genre'] ?? 'atelier') !== 'atelier' && $l['ca'] === 0 && $l['devisAttente'] === 0)
    ->values());

/*
 * **Les charges par source — 22/10.** « Un gros KPI Charges, et dans ces charges une sélection qui
 * départage l'information, comme on l'a fait pour le chiffre d'affaires avec la synthèse par site :
 * les charges de la caisse, de la FNE, de la banque, du suivi fournisseur. Pas de lettrage pour
 * l'instant : on met les informations telles qu'elles sont. » Le total retenu reste caisse +
 * saisies ; les autres sources se lisent à côté — voir `ChargesParSource`.
 */
$chargesParSource = computed(function () {
    [$debut, $fin] = $this->plage;

    return \Modules\Noyau\Exploitation\Services\ChargesParSource::pourLesSites(
        (int) auth()->user()->entreprise_id, $this->sitesRetenus->pluck('id')->all(), $debut, $fin, (string) $this->activiteFiltre,
    )->tout();
});

/** La seconde section de la synthèse : l'état des impayés par lieu — 12/10, voir `SyntheseParAtelier::impayes`. */
$syntheseImpayes = computed(function () {
    [$debut, $fin] = $this->plage;

    return SyntheseParAtelier::impayes($this->sitesRetenus, $debut, $fin, (string) $this->activiteFiltre, $this->idsCommercialFiltre, $this->idsVilles);
});

$kpis = computed(function () {
    [$debut, $fin] = $this->plage;
    $idsSites = $this->sitesRetenus->pluck('id');

    // Les devis sont lus une fois pour tout l'écran — voir `$devisLus`.
    $devisLus = $this->devisLus;
    $transformes = $this->devisTransformes;

    $facturesQ = EtatDesImpayes::dansLePerimetre(Facture::query()->duChiffreDAffaires(), $idsSites->all(), $this->idsVilles)
        ->whereBetween('date', [$debut, $fin])
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->when($this->idsCommercialFiltre !== null, fn ($q) => $q->whereIn('commercial_id', $this->idsCommercialFiltre));

    // Factures et devis portent toujours leur activité. Charges et encaissements ne la
    // renseignent que lorsque celui qui saisit la connaît : le reliquat apparaît alors
    // sous « Non ventilé » plutôt que d'être réparti au jugé — les trois lignes refont
    // ainsi toujours le total exact. Les véhicules sans facture, eux, relèvent du lieu
    // entier et ne se ventilent pas du tout.
    $sortiesQ = Charge::whereIn('site_id', $idsSites)->whereBetween('date', [$debut, $fin])
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre));

    $encaisseQ = PerimetreDeTresorerie::encaissements(Encaissement::query(), $idsSites->all(), $this->idsVilles)
        ->whereBetween('date', [$debut, $fin])
        ->when($this->activiteFiltre, fn ($q) => $q->deLActivite($this->activiteFiltre));

    $ca = VentilationActivite::repartir($facturesQ);

    // À côté du CA, jamais dedans : ce que l'état des impayés porte sans que le fichier du CA
    // ne l'ait retrouvé — 12/10, voir `Facture::scopeSelonLesImpayesSeulement`.
    $caSelonImpayes = montant_de(EtatDesImpayes::dansLePerimetre(Facture::query()->selonLesImpayesSeulement(), $idsSites->all(), $this->idsVilles)
        ->whereBetween('date', [$debut, $fin])
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre))
        ->when($this->idsCommercialFiltre !== null, fn ($q) => $q->whereIn('commercial_id', $this->idsCommercialFiltre))
        ->sum('montant'));
    // Les charges : sorties de caisse administratives, charges saisies et suivi fournisseurs
    // (`ChargesDeLaPeriode`, 26/10) — la caisse et le suivi, sans activité, vont à « Autres », pour que mécanique + sinistre + autres refassent le total.
    $charges = \Modules\Noyau\Exploitation\Services\ChargesDeLaPeriode::pourLesSites($idsSites->all(), $debut, $fin, (string) $this->activiteFiltre)->totaux()['ventile'];
    $sorties = VentilationActivite::repartir($sortiesQ);
    $encaisse = VentilationActivite::repartir($encaisseQ, 'montant', Encaissement::ACTIVITE_SQL);

    return [
        'ca' => montant_de($this->synthese->sum('ca')),
        'caSelonImpayes' => $caSelonImpayes,
        'caMecanique' => $ca['mecanique'],
        'caSinistre' => $ca['sinistre'],
        'caNonVentile' => $ca['nonVentile'],
        'charges' => montant_de($this->synthese->sum('charges')),
        'chargesVentilees' => $charges,
        'resultat' => montant_de($this->synthese->sum('resultat')),
        'resultatVentile' => VentilationActivite::difference($ca, $charges),
        'encaisse' => montant_de($this->synthese->sum('encaisse')),
        'encaisseVentile' => $encaisse,
        'treso' => montant_de($this->synthese->sum('treso')),
        'tresoVentilee' => VentilationActivite::difference($encaisse, $sorties),
        // Taux de transformation des devis émis sur la période.
        'tauxTransfo' => TransformationDesDevis::taux($devisLus, $transformes),
        'tauxTransfoMecanique' => TransformationDesDevis::taux($devisLus, $transformes, 'Mécanique'),
        'tauxTransfoSinistre' => TransformationDesDevis::taux($devisLus, $transformes, 'Sinistre'),
        // Anomalie critique remontée par les responsables de site.
        'sansFacture' => (int) SaisieJournaliere::whereIn('site_id', $idsSites)
            ->whereBetween('date', [$debut, $fin])->sum('vehicules_sans_facture'),
    ];
});

$graphiqueSites = computed(fn () => [
    'labels' => $this->synthese->pluck('site.nom')->all(),
    'datasets' => [
        ['label' => 'CA facturé', 'data' => $this->synthese->pluck('ca')->all(), 'color' => '#191B20'],
        ['label' => 'Charges', 'data' => $this->synthese->pluck('charges')->all(), 'color' => '#C8102E'],
        ['label' => 'Résultat net', 'data' => $this->synthese->pluck('resultat')->all(), 'color' => '#0E9F6E'],
    ],
]);

/** Flux de trésorerie hebdomadaire : entrées, sorties et cumul net. */
$graphiqueFlux = computed(function () {
    [$debut, $fin] = $this->plage;
    $idsSites = $this->sitesRetenus->pluck('id');
    $points = PeriodeCalculateur::points($debut, $fin);

    $labels = [];
    $entrees = [];
    $sorties = [];
    $cumul = [];
    $total = 0;

    // Deux requêtes pour la courbe, et non deux par point — c'est l'écran d'accueil du
    // gérant, celui qu'on paie à chaque connexion. Voir `SerieParPoint`.
    // Aucune borne de date ici, et c'est voulu : les points hebdomadaires commencent au
    // lundi, donc parfois **avant** le début de la plage. Borner au début rognerait le
    // premier point. `SerieParPoint` borne à l'union exacte des points, ce qui ne retire
    // rien et laisse l'index servir.
    $activite = fn ($requete) => $requete
        ->when($this->activiteFiltre, fn ($q) => $q->where('activite', $this->activiteFiltre));

    // Les entrées se placent comme dans le tableau : atelier, sinon ville de la facture.
    $sommesEntrees = SerieParPoint::sommes(
        $activite(PerimetreDeTresorerie::encaissements(Encaissement::query(), $idsSites->all(), $this->idsVilles)),
        $points, 'encaissements.date', 'encaissements.montant',
    );
    $sommesSorties = SerieParPoint::sommes($activite(Charge::query()->whereIn('site_id', $idsSites)), $points, 'date', 'montant');

    foreach ($points as $rang => $point) {
        $e = $sommesEntrees[$rang];
        $s = $sommesSorties[$rang];
        $total += $e - $s;

        $labels[] = $point['label'];
        $entrees[] = $e;
        $sorties[] = $s;
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

/**
 * Alertes de gestion, classées par gravité : un résultat ou une trésorerie négative,
 * des devis restés sans statut, et surtout les véhicules restitués sans facture.
 */
$alertes = computed(function () {
    $alertes = [];

    foreach ($this->synthese as $ligne) {
        $site = $ligne['site']->nom;

        if ($ligne['sansFacture'] > 0) {
            $alertes[] = ['niveau' => 'CRITIQUE', 'texte' => "$site : {$ligne['sansFacture']} véhicule(s) restitué(s) sans facture"];
        }
        if ($ligne['resultat'] < 0) {
            $alertes[] = ['niveau' => 'ÉLEVÉ', 'texte' => "$site : résultat net négatif (".ae($ligne['resultat']).')'];
        }
        if ($ligne['treso'] < 0) {
            $alertes[] = ['niveau' => 'ÉLEVÉ', 'texte' => "$site : trésorerie nette négative (".ae($ligne['treso']).')'];
        }
        if ($ligne['devisAttente'] > 0) {
            $alertes[] = ['niveau' => 'MOYEN', 'texte' => "$site : {$ligne['devisAttente']} devis en attente de statut"];
        }
    }

    $ordre = ['CRITIQUE' => 0, 'ÉLEVÉ' => 1, 'MOYEN' => 2];

    return collect($alertes)->sortBy(fn ($a) => $ordre[$a['niveau']])->values();
});

/**
 * Les objectifs du mois, employé par employé — 20/10.
 *
 * « L'objectif n'est pas réellement pour l'entreprise mais pour les employés concernés, et c'est
 * par mois qu'on vérifie. » La carte montrait le CA de toute l'entreprise face aux tranches,
 * ramenées à la période. Elle montre maintenant, pour le mois choisi, chaque accès concerné
 * (création d'accès ou rattrapage du Super admin), son objectif, ce qu'il a réalisé lui-même et
 * les tranches atteintes — `AccesAuxObjectifs::suiviDesConcernes`.
 */
$moisDesObjectifs = computed(fn () => preg_match('/^\d{4}-\d{2}$/', (string) $this->moisObjectifs)
    ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $this->moisObjectifs.'-01')->startOfDay()
    : now()->startOfMonth());

$objectifsDuMois = computed(fn () => \Modules\Noyau\Exploitation\Services\AccesAuxObjectifs::suiviDesConcernes(
    (int) auth()->user()->entreprise_id,
    $this->moisDesObjectifs,
    PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre),
));

/** Les douze derniers mois, pour le choix du mois. */
$moisPossibles = computed(fn () => collect(range(0, 11))
    ->mapWithKeys(function ($i) {
        $m = now()->startOfMonth()->subMonths($i);

        return [$m->format('Y-m') => ucfirst($m->locale('fr')->translatedFormat('F Y'))];
    })->all());

/** Classement des commerciaux du groupe par taux de réalisation de leur objectif au prorata. */
$topCommerciaux = computed(function () {
    [$debut, $fin] = $this->plage;

    return Commercial::actifs()->where('est_spontane', false)
        ->whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre))
        ->with('ville')->get()
        ->map(function ($c) use ($debut, $fin) {
            $realisation = montant_de(Facture::where('commercial_id', $c->id)->whereBetween('date', [$debut, $fin])->sum('montant'));
            // Un montant : jamais arrondi (piège 17).
            $objectif = montant_de(PeriodeCalculateur::objectifProrata((float) $c->objectif_mensuel, $debut, $fin));

            return [
                'commercial' => $c,
                'realisation' => $realisation,
                'taux' => $objectif > 0 ? $realisation / $objectif : null,
            ];
        })
        ->sortByDesc(fn ($l) => $l['taux'] ?? -1)
        ->take(3)->values();
});

/** Commentaires laissés par les responsables de site dans leur saisie journalière. */
$commentaires = computed(function () {
    [$debut, $fin] = $this->plage;

    $saisies = SaisieJournaliere::whereIn('site_id', $this->sitesRetenus->pluck('id'))
        ->whereBetween('date', [$debut, $fin])
        ->with('site')->latest('date')->latest('id')->get();

    // Clés sans espace ni apostrophe : elles voyagent jusque dans le wire:click de la
    // pagination, où « Chiffre d'affaires » romprait la chaîne JavaScript.
    $rubriques = [
        'prospects' => ['Prospects', 'commentaire_prospects'],
        'devis' => ['Devis', 'commentaire_devis'],
        'ca' => ["Chiffre d'affaires", 'commentaire_ca'],
        'tresorerie' => ['Trésorerie', 'commentaire_tresorerie'],
        'charges' => ['Charges', 'commentaire_charges'],
    ];

    return collect($rubriques)->map(fn ($rubrique) => [
        'libelle' => $rubrique[0],
        'lignes' => $saisies
            ->filter(fn ($s) => filled($s->{$rubrique[1]}))
            ->map(fn ($s) => ['site' => $s->site->nom, 'date' => $s->date, 'texte' => $s->{$rubrique[1]}])
            ->values(),
    ]);
});

?>

<div>
    <x-titre-ecran titre="Tableau de bord"
        sous-titre="La marche de l'entreprise, toutes villes confondues." />

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        :commerciaux="$this->commerciaux" :commercial-filtre="$commercialFiltre" />

    {{-- 250 px au moins par carte — 20/10 : à 165 px, les montants à dix chiffres débordaient
         (« 726 564 942,7696 F ») ; les cartes de trop passent à la ligne suivante. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:10px; margin-bottom:16px;">
        {{-- Le détail par activité n'a plus de sens dès qu'on a filtré sur l'une d'elles :
             il répéterait le total. On le masque alors sur toutes les cartes à la fois. --}}
        @php $ventile = ! $activiteFiltre; @endphp

        <x-kpi-card label="CA — {{ $this->libellePerimetre }}" :value="ae($this->kpis['ca'])"
            :mecanique="$ventile ? ae($this->kpis['caMecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['caSinistre']) : null"
            :non-ventile="$ventile && $this->kpis['caNonVentile'] ? ae($this->kpis['caNonVentile']) : null"
            sub="Fichier du CA et saisies" autres="Autres : factures du CA dont l'activité n'est pas dite — ni fiche de réception ni n° de sinistre pour la trancher." :autres-lien="route('chiffre-affaires')"
            />
        <x-kpi-card label="CA selon les impayés — {{ $this->libellePerimetre }}" :value="ae($this->kpis['caSelonImpayes'])"
            sub="Créances de l'état non retrouvées dans le fichier du CA — à côté, jamais additionné" couleur="#B45309" />
        <x-kpi-card label="Charges — {{ $this->libellePerimetre }}" :value="ae($this->kpis['charges'])"
            :mecanique="$ventile ? ae($this->kpis['chargesVentilees']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['chargesVentilees']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['chargesVentilees']['nonVentile'] ? ae($this->kpis['chargesVentilees']['nonVentile']) : null" autres="Autres : décaissements de caisse (le journal ne dit jamais mécanique ou sinistre), pièces du suivi fournisseurs et charges saisies sans activité." :autres-lien="route('charges')"
            />
        <x-kpi-card label="Résultat net — {{ $this->libellePerimetre }}" :value="ae($this->kpis['resultat'])"
            sub="CA facturé − charges" :bon="$this->kpis['resultat'] >= 0" :accent="$this->kpis['resultat'] < 0"
            :mecanique="$ventile ? ae($this->kpis['resultatVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['resultatVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['resultatVentile']['nonVentile'] ? ae($this->kpis['resultatVentile']['nonVentile']) : null" :signes="true" autres="Autres : le « Autres » du CA moins le « Autres » des charges — surtout les décaissements de caisse, sans activité." :autres-lien="route('charges')"
            />
        <x-kpi-card label="Encaissé — {{ $this->libellePerimetre }}" :value="ae($this->kpis['encaisse'])"
            :sub="$this->kpis['ca'] > 0 ? an($this->kpis['encaisse'] / $this->kpis['ca']).' du CA facturé' : null"
            :mecanique="$ventile ? ae($this->kpis['encaisseVentile']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['encaisseVentile']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['encaisseVentile']['nonVentile'] ? ae($this->kpis['encaisseVentile']['nonVentile']) : null" autres="Autres : règlements dont la facture ne dit pas l'activité, ou qui ne sont rattachés à aucune facture." :autres-lien="route('tresorerie')"
            />
        <x-kpi-card label="Trésorerie nette — {{ $this->libellePerimetre }}" :value="ae($this->kpis['treso'])" :accent="$this->kpis['treso'] < 0"
            :mecanique="$ventile ? ae($this->kpis['tresoVentilee']['mecanique']) : null"
            :sinistre="$ventile ? ae($this->kpis['tresoVentilee']['sinistre']) : null"
            :non-ventile="$ventile && $this->kpis['tresoVentilee']['nonVentile'] ? ae($this->kpis['tresoVentilee']['nonVentile']) : null" :signes="true" autres="Autres : encaissé sans activité moins décaissé sans activité." :autres-lien="route('tresorerie')"
            />
        <x-kpi-card label="Taux transfo devis — {{ $this->libellePerimetre }}" :value="an($this->kpis['tauxTransfo'])" sub="Devis validés ou facturés"
            :mecanique="$ventile ? an($this->kpis['tauxTransfoMecanique']) : null"
            :sinistre="$ventile ? an($this->kpis['tauxTransfoSinistre']) : null" />
        <x-kpi-card label="Véhicules sans facture — {{ $this->libellePerimetre }}" :value="$this->kpis['sansFacture']"
            :sub="$this->kpis['sansFacture'] > 0 ? 'Anomalie à signaler à la Direction' : 'Aucune anomalie'"
            :accent="$this->kpis['sansFacture'] > 0" :bon="$this->kpis['sansFacture'] === 0" />
    </div>

    {{-- ─────────────── les objectifs du mois, par employé concerné — 20/10 --}}
    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
            <h3 style="font-size:15px; font-weight:700; margin:0;">Objectifs du mois — employés concernés</h3>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <select wire:model.live="moisObjectifs" class="champ" style="width:auto;" aria-label="Mois">
                    <option value="">{{ ucfirst(now()->locale('fr')->translatedFormat('F Y')) }} (en cours)</option>
                    @foreach (array_slice($this->moisPossibles, 1, null, true) as $cle => $libelle)
                        <option value="{{ $cle }}">{{ $libelle }}</option>
                    @endforeach
                </select>
                <a href="{{ route('objectifs-mensuels') }}" class="bouton bouton-secondaire" style="padding:6px 12px;">Configurer les objectifs</a>
            </div>
        </div>
        @if ($this->objectifsDuMois->isEmpty())
            <p style="margin:0; font-size:13px; color:#6B6E76;">
                Aucun employé concerné par un objectif ce mois-ci. On le dit à la création de l'accès, ou au
                rattrapage du Super admin pour les accès d'avant.
            </p>
        @else
            <p style="margin:0 0 10px; font-size:12.5px; color:#6B6E76;">
                Le réalisé est celui de l'employé lui-même : ses factures s'il est commercial, sinon le CA de
                son atelier ou de sa ville — du 1er au dernier jour du mois (à aujourd'hui pour le mois en cours).
            </p>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Employé</th><th>Objectif</th><th style="text-align:right;">Réalisé du mois</th>
                            <th>Tranches</th><th>Avancement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->objectifsDuMois as $l)
                            @php
                                $plafond = collect($l['tranches'])->max('montant') ?: 0;
                                $suivante = collect($l['tranches'])->first(fn ($t) => $l['realise'] < $t['montant']);
                                $taux = $plafond > 0 ? $l['realise'] / $plafond : null;
                            @endphp
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;">
                                <td><b>{{ $l['acces']->name }}</b><div style="font-size:11.5px; color:#6B6E76;">{{ $l['portee'] }}</div></td>
                                <td>{{ $l['objectif']->libelle }}@if ($l['objectif']->activite)<div style="font-size:11.5px; color:#6B6E76;">{{ $l['objectif']->activite }}</div>@endif</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae($l['realise']) }}</td>
                                <td style="font-size:12px;">
                                    @foreach ($l['tranches'] as $t)
                                        <div style="white-space:nowrap; color:{{ $l['realise'] >= $t['montant'] ? '#0E9F6E' : '#4B4E55' }};">
                                            {{ $l['realise'] >= $t['montant'] ? '✔' : '○' }} {{ $t['libelle'] }} · {{ ae($t['montant']) }}
                                            {{-- Le pourcentage réalisé, tranche atteinte ou non (23/10). --}}
                                            @if ($t['montant'] > 0) <span style="color:#6B6E76;">— {{ an($l['realise'] / $t['montant']) }} réalisé</span> @endif
                                        </div>
                                    @endforeach
                                </td>
                                <td style="min-width:180px;">
                                    <div style="height:6px; background:#E5E7EB; border-radius:3px; margin:4px 0; overflow:hidden;">
                                        <div style="height:6px; width:{{ min(100, max(0, ($taux ?? 0) * 100)) }}%; background:{{ $suivante === null ? '#0E9F6E' : '#D97706' }};"></div>
                                    </div>
                                    <div style="font-size:12px; color:{{ $suivante === null ? '#0E9F6E' : '#6B6E76' }}; font-weight:600;">
                                        {{ an($taux) }} réalisé · {{ $l['atteintes'] }} / {{ count($l['tranches']) }} tranche(s)
                                        @if ($suivante) — reste {{ ae(montant_de($suivante['montant'] - $l['realise'])) }} pour « {{ $suivante['libelle'] }} » @else — toutes atteintes ✔ @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); gap:16px; margin-bottom:20px;">
        <x-chart-card titre="Comparaison des sites : CA, charges, résultat net" id="cmp-sites"
            :labels="$this->graphiqueSites['labels']" :datasets="$this->graphiqueSites['datasets']" />
        <x-chart-card titre="Flux de trésorerie" id="flux-treso"
            :labels="$this->graphiqueFlux['labels']" :datasets="$this->graphiqueFlux['datasets']" />
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:16px; margin-bottom:20px;">
        <div class="carte">
            <h3 class="titre-section">Alertes ({{ $this->alertes->count() }})</h3>
            @forelse ($this->alertes->forPage($pageAlertes, 7) as $alerte)
                @php
                    $couleur = ['CRITIQUE' => 'pastille-rouge', 'ÉLEVÉ' => 'pastille-ambre', 'MOYEN' => 'pastille-bleu'][$alerte['niveau']];
                @endphp
                <div style="display:flex; gap:9px; align-items:flex-start; padding:7px 0; border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                    <span class="pastille {{ $couleur }}" style="flex:0 0 auto;">{{ $alerte['niveau'] }}</span>
                    <span style="font-size:13px; line-height:1.4;">{{ $alerte['texte'] }}</span>
                </div>
            @empty
                <div class="etat-vide" style="min-height:130px;">
                    <span class="etat-vide-texte">Aucune alerte sur cette période.</span>
                </div>
            @endforelse
            <x-pagination :page="$pageAlertes" :total="$this->alertes->count()" prop="pageAlertes" :par-page="7" />
        </div>

        <div class="carte">
            <h3 class="titre-section">Top commerciaux (taux de réalisation)</h3>
            @forelse ($this->topCommerciaux as $rang => $ligne)
                @php
                    // Or, argent, bronze : la couleur du rang, comme dans la maquette.
                    $medaille = ['#D4AF37', '#9CA3AF', '#B87333'][$rang] ?? '#9CA3AF';
                @endphp
                <div style="display:flex; gap:11px; align-items:center; padding:8px 0; border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                    <span style="flex:0 0 auto; width:26px; height:26px; border-radius:99px; background:{{ $medaille }};
                                 color:#fff; display:inline-flex; align-items:center; justify-content:center;
                                 font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:15px;">{{ $rang + 1 }}</span>
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:700; font-size:13.5px;">{{ $ligne['commercial']->nom }}</div>
                        <div style="font-size:11.5px; color:var(--th-gris,#6B6E76);">
                            {{ $ligne['commercial']->ville->nom }}
                        </div>
                        <div style="font-size:11.5px; color:var(--th-gris,#6B6E76);">
                            Réalisation {{ ae($ligne['realisation']) }} · atteinte
                            <b style="color:{{ ($ligne['taux'] ?? 0) >= 1 ? '#0E9F6E' : '#D97706' }};">{{ an($ligne['taux']) }}</b>
                        </div>
                    </div>
                </div>
            @empty
                <div class="etat-vide" style="min-height:130px;">
                    <span class="etat-vide-texte">Aucun commercial sur cette période.</span>
                </div>
            @endforelse
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:16px; margin-bottom:20px;">
        @foreach ($this->commentaires as $cle => $rubrique)
            <div class="carte">
                <h3 class="titre-section">Commentaires — {{ $rubrique['libelle'] }}</h3>
                @php $pageRubrique = (int) ($pageCommentaires[$cle] ?? 1); @endphp
                @forelse ($rubrique['lignes']->forPage($pageRubrique, 7) as $c)
                    <div style="padding:7px 0; border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                        <div style="font-size:11.5px; color:var(--th-gris,#6B6E76); font-weight:600;">
                            {{ $c['site'] }} · {{ $c['date']->format('d/m/Y') }}
                        </div>
                        <div style="font-size:13px; line-height:1.45;">{{ $c['texte'] }}</div>
                    </div>
                @empty
                    <div class="etat-vide" style="min-height:110px;">
                        <span class="etat-vide-texte">Aucun commentaire saisi sur la période.</span>
                    </div>
                @endforelse
                <x-pagination :page="$pageRubrique" :total="$rubrique['lignes']->count()"
                    prop="pageCommentaires.{{ $cle }}" :par-page="7" />
            </div>
        @endforeach
    </div>

    {{-- ─────────────────────────────── les charges par source — 22/10 --}}
    @php $src = $this->chargesParSource; @endphp
    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <h3 class="titre-section" style="margin-bottom:2px;">Charges — synthèse par source</h3>
                <p style="margin:0; font-size:12.5px; color:#6B6E76; line-height:1.5; max-width:640px;">
                    Ce que dit chaque source sur la période et le filtre. Les charges retenues (26/10) : la caisse
                    administrative, les saisies et le suivi fournisseurs. Les sorties de caisse fournisseurs (le suivi
                    les porte), la FNE et la banque se lisent à côté, sans s'additionner.
                </p>
            </div>
            <div style="text-align:right;">
                <div style="font-size:11px; color:#6B6E76; text-transform:uppercase; letter-spacing:.04em;">Charges retenues — {{ $this->libellePerimetre }}</div>
                <div style="font-size:26px; font-weight:800; color:#C8102E; font-variant-numeric:tabular-nums;">{{ ae($src['retenu']) }}</div>
            </div>
        </div>
        <div class="tableau-conteneur" style="margin-top:12px;">
            <table class="tableau">
                <thead>
                    <tr><th>Source</th><th style="text-align:right;">Lignes</th><th style="text-align:right;">Montant</th><th>Dans les charges retenues ?</th></tr>
                </thead>
                <tbody>
                    <tr style="font-weight:700;">
                        <td>Caisse — sorties administratives</td>
                        <td style="text-align:right;">{{ number_format($src['caisse']['n'], 0, ',', ' ') }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ ae($src['caisse']['total']) }}</td>
                        <td style="color:#0E9F6E;">Oui</td>
                    </tr>
                    <tr>
                        <td style="padding-left:26px; color:#4B4E55;">Caisse — sorties fournisseurs <span style="font-size:11px; color:#9A9DA5;">(pièces, travaux, mécanique, sinistre)</span></td>
                        <td style="text-align:right; color:#6B6E76;">{{ number_format($src['caisse']['nFournisseurs'], 0, ',', ' ') }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; color:#4B4E55;">{{ ae($src['caisse']['fournisseurs']) }}</td>
                        <td style="color:#6B6E76;">Non — le suivi fournisseurs les porte</td>
                    </tr>
                    <tr style="font-weight:700;">
                        <td>Saisies dans l'application</td>
                        <td style="text-align:right;">{{ number_format($src['saisies']['n'], 0, ',', ' ') }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ ae($src['saisies']['total']) }}</td>
                        <td style="color:#0E9F6E;">Oui</td>
                    </tr>
                    <tr style="font-weight:700;">
                        <td>Suivi fournisseurs — pièces facturées</td>
                        <td style="text-align:right;">{{ number_format($src['suivi']['n'], 0, ',', ' ') }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ ae($src['suivi']['total']) }}</td>
                        <td style="color:#0E9F6E;">Oui (26/10)</td>
                    </tr>
                    @foreach (['banque' => 'Banque — débits des relevés', 'fne' => "Factures FNE — achats relevés au portail"] as $cle => $libelle)
                        <tr>
                            <td>{{ $libelle }}</td>
                            <td style="text-align:right;">{{ number_format($src[$cle]['n'], 0, ',', ' ') }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ ae($src[$cle]['total']) }}</td>
                            <td style="color:#6B6E76;">À côté — non additionné</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─────────────────────────────── la synthèse, en deux sections — 12/10

         « Une section qui illustre uniquement le CA importé, et une deuxième qui montre celui
         des impayés, pour éviter les confusions. » Les règlements de l'état des impayés
         remplissaient les lignes « non précisé » du tableau du CA, où on les lisait comme du
         CA mal rangé. Chacun a désormais son tableau. --}}
    <div class="carte" style="margin-bottom:16px;">
        <h3 class="titre-section">Synthèse par site — chiffre d'affaires (fichier du CA et saisies)</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>CA facturé</th>
                        <th>Charges</th>
                        <th>Résultat net</th>
                        <th>Devis en attente</th>
                        <th>Sans facture</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->syntheseDuCa->forPage($pageSynthese, 10) as $ligne)
                        <tr>
                            <td style="font-weight:700;">
                                <span style="display:inline-block; width:9px; height:9px; border-radius:99px; background:{{ $ligne['site']->ville->couleur ?? '#2563EB' }}; margin-right:8px;"></span>
                                {{ $ligne['site']->nom }}
                                @if (($ligne['genre'] ?? 'atelier') !== 'atelier')
                                    <div style="font-size:11px; font-weight:400; color:#6B6E76; margin-top:2px;">
                                        @if ($ligne['genre'] === 'ville')
                                            Lignes du fichier du CA : ville connue (par le code de la fiche), pas l'atelier
                                        @elseif ($ligne['genre'] === 'hors')
                                            Lignes du CA rattachées à un lieu absent de ce tableau (atelier ou ville désactivé)
                                        @else
                                            Lignes du fichier du CA dont le code de fiche ne désigne aucune ville
                                            @if (! empty($ligne['codesSansLieu']))
                                                :
                                                @foreach (array_slice($ligne['codesSansLieu'], 0, 8) as $c)
                                                    <b>{{ $c['code'] !== '' ? $c['code'] : 'sans fiche' }}</b> ({{ $c['n'] }} ligne{{ $c['n'] > 1 ? 's' : '' }}, {{ ae($c['ca']) }}){{ $loop->last ? '' : ' ·' }}
                                                @endforeach
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['ca']) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['charges']) }}</td>
                            <td style="font-variant-numeric:tabular-nums; color:{{ $ligne['resultat'] >= 0 ? '#0E9F6E' : '#C8102E' }}; font-weight:700;">{{ ae($ligne['resultat']) }}</td>
                            <td>{{ $ligne['devisAttente'] }}</td>
                            <td style="font-weight:700; color:{{ $ligne['sansFacture'] > 0 ? '#C8102E' : 'inherit' }};">{{ $ligne['sansFacture'] }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="6" texte="Aucun site à afficher." />
                    @endforelse
                </tbody>
                {{-- Le total du CA, 12/10 : sur toutes les lignes du tableau, pas sur la page. --}}
                @if ($this->syntheseDuCa->isNotEmpty())
                    <tfoot>
                        <tr style="font-weight:800; border-top:2px solid #191B20;">
                            <td>Total</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae(montant_de($this->syntheseDuCa->sum('ca'))) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae(montant_de($this->syntheseDuCa->sum('charges'))) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae(montant_de($this->syntheseDuCa->sum('resultat'))) }}</td>
                            <td>{{ $this->syntheseDuCa->sum('devisAttente') }}</td>
                            <td>{{ $this->syntheseDuCa->sum('sansFacture') }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <x-pagination :page="$pageSynthese" :total="$this->syntheseDuCa->count()" prop="pageSynthese" />
        @if ($this->rejetsDuCa)
            {{-- Ce qui explique un total de classeur plus haut que celui-ci : les lignes refusées
                 n'entrent dans aucune facture. --}}
            <p style="margin:10px 0 0; font-size:12px; color:#B45309; line-height:1.5;">
                <b>{{ $this->rejetsDuCa['n'] }} ligne(s)</b> du dernier fichier du CA
                ({{ $this->rejetsDuCa['fichier'] }}) ont été refusées à l'import et ne sont pas comptées ici —
                {{ ae($this->rejetsDuCa['montant']) }} d'après le fichier :
                @foreach ($this->rejetsDuCa['motifs'] as $motif => $nombre)
                    {{ $motif }} ({{ $nombre }}){{ $loop->last ? '' : ' ;' }}
                @endforeach
                — à corriger depuis l'écran des imports.
            </p>
        @endif
    </div>

    <div class="carte">
        <h3 class="titre-section">Synthèse par site — état des impayés</h3>
        <p style="margin:-4px 0 10px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
            Les créances portées à l'état et leurs règlements, à part du CA : un règlement n'est pas
            une vente, et l'état ne dit presque jamais l'atelier. « Dont hors CA » : ce que le fichier
            du CA n'a pas retrouvé.
        </p>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Créances de la période</th>
                        <th>Dont hors CA</th>
                        <th>Encaissé</th>
                        <th>Reste à encaisser</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->syntheseImpayes as $ligne)
                        <tr>
                            <td style="font-weight:700;">
                                <span style="display:inline-block; width:9px; height:9px; border-radius:99px; background:{{ $ligne['site']->ville->couleur ?? '#2563EB' }}; margin-right:8px;"></span>
                                {{ $ligne['site']->nom }}
                                @if ($ligne['genre'] !== 'atelier')
                                    <div style="font-size:11px; font-weight:400; color:#6B6E76; margin-top:2px;">
                                        {{ $ligne['genre'] === 'ville'
                                            ? "Vient de l'état des impayés : ville connue, pas l'atelier"
                                            : "Vient de l'état des impayés : ni fiche de réception, ni atelier, ni ville" }}
                                    </div>
                                @endif
                            </td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae($ligne['creances']) }}</td>
                            <td style="font-variant-numeric:tabular-nums; color:#B45309;">{{ ae($ligne['horsCa']) }}</td>
                            <td style="font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ ae($ligne['encaisse']) }}</td>
                            <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne['reste']) }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="5" texte="Aucune créance ni règlement sur cette période." />
                    @endforelse
                </tbody>
                @if ($this->syntheseImpayes->isNotEmpty())
                    <tfoot>
                        <tr style="font-weight:800; border-top:2px solid #191B20;">
                            <td>Total</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae(montant_de($this->syntheseImpayes->sum('creances'))) }}</td>
                            <td style="font-variant-numeric:tabular-nums; color:#B45309;">{{ ae(montant_de($this->syntheseImpayes->sum('horsCa'))) }}</td>
                            <td style="font-variant-numeric:tabular-nums; color:#0E9F6E;">{{ ae(montant_de($this->syntheseImpayes->sum('encaisse'))) }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ ae(montant_de($this->syntheseImpayes->sum('reste'))) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

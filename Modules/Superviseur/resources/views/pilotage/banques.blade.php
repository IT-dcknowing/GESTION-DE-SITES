<?php

use Illuminate\Validation\Rule;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\LibelleDeBanque;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\PerimetreDeTresorerie;
use Modules\Noyau\Exploitation\Services\RapprochementDeTresorerie;
use Modules\Noyau\Exploitation\Services\ReconnaissanceDeBanque;
use Modules\Noyau\Exploitation\Services\SupportDeReglement;

use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Banques — ce qui est passé par chaque compte
|--------------------------------------------------------------------------
| **Le pendant de la caisse.** L'argent passe par un tiroir ou par un compte ; les deux
| écrans répondent à la même question sur deux supports, et tous deux s'ouvrent depuis la
| trésorerie, qui les additionne.
|
| **La ligne de boutons, telle que demandée le 01/10.** « Toutes les banques », puis une par
| banque de l'entreprise — *« et si on a ORANGE MONEY mets-le, si on a MTN money mets-le ; et
| pour le dernier, ceux dont le mode n'a pas été déclaré, mets Moyen non précisé »*.
|
| Trois sortes de boutons, donc, et elles ne naissent pas de la même façon :
|
| | Bouton | D'où il vient | Paraît |
| |---|---|---|
| | une banque | d'une fiche déclarée à la main | **toujours**, même sans une seule écriture |
| | un portefeuille mobile | d'un moyen trouvé dans les écritures | dès qu'une écriture le porte |
| | Moyen non précisé | des écritures sans moyen lisible | dès qu'il y en a une |
|
| **Une banque déclarée paraît même vide**, et c'est voulu : on la crée *avant* d'y encaisser,
| et un bouton qui n'apparaîtrait qu'une fois la première écriture passée laisserait croire que
| la déclaration n'a pas pris.
|
| **Ce sont nos comptes, pas ceux des autres.** La colonne « banque émettrice » d'un relevé
| désigne la banque du chèque **reçu** ; elle n'a rien à faire ici. Ce que cet écran range,
| c'est l'argent qui entre sur **nos** comptes.
|
| **L'origine se filtre, elle ne fait pas de boutons.** *« Au niveau de chacune des banques
| ajoute un filtre pour pouvoir trier ce qui est saisi dans l'application, ce qui est importé,
| et les deux à la fois — au lieu de venir mettre des boutons. »* Les boutons disent *où* est
| l'argent ; l'origine dit *d'où vient la ligne*. Deux questions, deux formes.
*/

state(['villeFiltre' => ''])->url(except: '');
state(['supportFiltre' => ''])->url(except: '');
state(['origineFiltre' => ''])->url(except: '');

/*
 * **Le portefeuille précis, quand on regarde le mobile money.**
 *
 * Demandé le 01/10 : « dans la page de mobile money, on doit avoir un filtre qui choisit le
 * mobile précis si disponible, mais par défaut doit rester sur mobile money ». D'où un
 * bouton unique pour l'ensemble, et un sous-filtre qui ne paraît que là : les opérateurs
 * sont quatre, et quatre boutons de plus noieraient les banques.
 */
state(['portefeuilleFiltre' => ''])->url(except: '');
state(['recherche' => '']);
state(['page' => 1]);
state(['filtresLibres' => []]);

/* La période, comme sur les autres écrans d'argent. */
state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
]);

/*
 * La déclaration d'une banque, repliée tant qu'on ne la demande pas.
 *
 * **Sans ville, et c'est une correction du 01/10** : « retire le champ ville au niveau du
 * formulaire, car la banque créée devra s'afficher en liste déroulante partout ». Une banque
 * rattachée à une ville ne se proposerait pas aux autres, alors que le compte sert
 * l'entreprise entière — et c'est ce que le logiciel comptable fait déjà : il demande le
 * compte sans demander de site.
 */
state([
    'formulaireOuvert' => false,
    /*
     * **Le type, demandé le 01/10** : « au niveau du formulaire de création des banques,
     * ajoute un champ pour sélectionner si c'est une banque ou un mobile money — les deux
     * sont des banques, mais on veut préciser ».
     *
     * Les deux reçoivent de l'argent, le gardent et le rendent : ce sont des comptes, et ils
     * vivent dans la même table. Ce qui les sépare tient en une ligne : un portefeuille
     * mobile ne paraît sur aucun relevé bancaire, et ne se règle pas « par chèque ». D'où un
     * type, et non deux tables.
     */
    'type' => Banque::BANQUE,
    'nom' => '',
    'note' => '',
    /*
     * Le libellé d'où part la déclaration, quand on l'ouvre par « Modifier » — demandé le
     * 07/10. On corrige le nom (« BGIF » devient « BGFI ») avant de déclarer ; le libellé
     * d'origine est alors affecté à la banque créée, sans quoi ses règlements resteraient
     * dans la liste des non rangés, sous la nouvelle banque qui porte pourtant leur nom.
     */
    'libelleOrigine' => '',
]);

/*
 * « Affecter à » — demandé le 07/10 : le libellé désigne une banque déjà déclarée, il n'en
 * faut pas une de plus. Le formulaire est replié tant qu'on ne le demande pas.
 */
state(['libelleAAffecter' => '', 'banqueAffectee' => '']);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedVilleFiltre = function () { $this->page = 1; };
$updatedSupportFiltre = function () { $this->page = 1; };
$updatedOrigineFiltre = function () { $this->page = 1; };
$updatedPortefeuilleFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };
$updatedFiltresLibres = function () { $this->page = 1; };
$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->page = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->page = 1; };
$updatedJourFiltre = function () { $this->page = 1; };
$updatedPeriode = function () { $this->page = 1; };
$updatedDateDebut = function () { $this->page = 1; };
$updatedDateFin = function () { $this->page = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

/* Les villes **en modèles** : `x-filtre-periode` lit `$ville->id`. */
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, ''));
$idsVilles = computed(fn () => EtatDesImpayes::villesDesSites($this->idsSites));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

/** Le gérant déclare les banques ; les autres les lisent. Vérifié ici **et** dans l'action. */
$peutDeclarer = computed(fn () => auth()->user()->hasRole('gerant'));

// Chargées avec leurs libellés affectés : la reconnaissance les lit sur chaque fiche, et les
// charger ici évite une requête par banque.
$banques = computed(fn () => Banque::query()->with('libellesAffectes')->where('est_active', true)->orderBy('nom')->get());

/**
 * Le code que le prochain compte recevra — montré, jamais saisi.
 *
 * **Demandé le 01/10** : « pour le champ code, le système doit le préremplir automatiquement,
 * pas cliquable ». La raison est la même que pour les tiers et les fournisseurs : un code
 * tapé à la main finit par exister en double, et deux comptes qui portent le même code
 * rendent impointable tout rapprochement qui s'appuie dessus.
 *
 * **C'est un aperçu, et il le dit.** `apercus()` lit le compteur sans le consommer : le
 * numéro définitif est posé à l'enregistrement. Réserver un code puis abandonner le
 * formulaire laisserait un trou dans la série — ce qu'une numérotation ne pardonne pas.
 */
$codeAVenir = computed(fn () => GenerateurNumero::apercus(
    (int) auth()->user()->entreprise_id, Banque::SERIE, 1,
)[0] ?? '—');

// ------------------------------------------------------------------ ce que la page lit

/**
 * Les entrées et les sorties qui ne sont pas passées par le tiroir.
 *
 * **Le support fait le tri avant la banque**, et c'est l'ordre juste : un règlement en espèces
 * n'a pas de compte, et sa facture peut pourtant porter une banque — celle du chèque reçu la
 * fois d'avant. Les ranger ici gonflerait un compte de ce qui n'y est jamais passé.
 */
$entreesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return PerimetreDeTresorerie::encaissements(
        Encaissement::query(), $this->idsSites, $this->idsVilles,
    )
        ->whereBetween('encaissements.date', [$debut, $fin])
        ->whereNotIn('encaissements.moyen', SupportDeReglement::moyensDuSupport('encaissements', SupportDeReglement::CAISSE));
});

$sortiesQ = computed(function () {
    [$debut, $fin] = $this->plage;

    return PerimetreDeTresorerie::charges(Charge::query(), $this->idsSites)
        ->whereBetween('charges.date', [$debut, $fin])
        ->whereNotIn('charges.moyen', SupportDeReglement::moyensDuSupport('charges', SupportDeReglement::CAISSE));
});

/**
 * Les libellés de banque trouvés sur les créances, et ce que chacun porte.
 *
 * Compté par la base en une requête : un `join` sur les factures, un `group by` sur leur
 * colonne `banque`. Quatorze lignes reviennent, pas sept mille.
 */
$libelles = computed(fn () => (clone $this->entreesQ)
    ->leftJoin('factures', 'factures.id', '=', 'encaissements.facture_id')
    ->selectRaw('factures.banque as libelle, count(*) as nombre, sum(encaissements.montant) as montant')
    ->groupBy('factures.banque')
    ->get());

/** Les libellés rangés sous la banque qu'ils désignent, et ceux qu'on ne sait pas ranger. */
$repartition = computed(function () {
    $banques = $this->banques;
    $parBanque = [];
    $nonRanges = [];

    foreach ($this->libelles as $ligne) {
        $verdict = ReconnaissanceDeBanque::pour($ligne->libelle, $banques);

        // Seule la certitude range. Une ressemblance **propose** — la poser d'office
        // rangerait des écritures sous une banque qui n'est pas la bonne, et rien ne le
        // dirait ensuite.
        if ($verdict['verdict'] === ReconnaissanceDeBanque::CERTAINE) {
            $id = $verdict['banque']->id;

            $parBanque[$id] ??= ['nombre' => 0, 'montant' => 0, 'libelles' => []];
            $parBanque[$id]['nombre'] += (int) $ligne->nombre;
            $parBanque[$id]['montant'] += (int) $ligne->montant;
            $parBanque[$id]['libelles'][] = (string) $ligne->libelle;

            continue;
        }

        $nonRanges[] = [
            'libelle' => $ligne->libelle,
            'nombre' => (int) $ligne->nombre,
            'montant' => (int) $ligne->montant,
            'raison' => $verdict['raison'],
            // La banque qui ressemble, s'il y en a une : « Affecter à » la présélectionne.
            'proposee' => $verdict['banque']?->id,
        ];
    }

    return ['parBanque' => $parBanque, 'nonRanges' => $nonRanges];
});

/**
 * Ce que chaque compte déclaré porte, en lisant les deux chemins.
 *
 * **Deux chemins, parce qu'il y a deux époques.** Depuis le 01/10, une écriture désigne son
 * compte : `encaissements.banque_id`. Avant, elle ne portait qu'un nom écrit à la main sur la
 * créance — `factures.banque` —, et c'est de là que viennent les quatorze orthographes pour
 * quatre banques. Les deux se lisent, et se totalisent ensemble : abandonner l'ancien chemin
 * ferait disparaître la quasi-totalité de l'historique.
 *
 * @return array<int, array{nombre: int, montant: int}>
 */
$parCompteDesigne = computed(function () {
    $groupes = (clone $this->entreesQ)
        ->whereNotNull('encaissements.banque_id')
        ->selectRaw('encaissements.banque_id as compte, count(*) as nombre, sum(encaissements.montant) as montant')
        ->groupBy('encaissements.banque_id')
        ->get();

    $parCompte = [];

    foreach ($groupes as $groupe) {
        $parCompte[(int) $groupe->compte] = [
            'nombre' => (int) $groupe->nombre,
            'montant' => (int) $groupe->montant,
        ];
    }

    return $parCompte;
});

/** Ce que les écritures sans moyen lisible pèsent — le dernier bouton, s'il a lieu d'être. */
$nonPrecise = computed(function () {
    $ligne = SupportDeReglement::appliquer(
        (clone $this->entreesQ)->whereNull('encaissements.banque_id'),
        'encaissements',
        SupportDeReglement::INCONNU,
    )
        ->selectRaw('count(*) as nombre, coalesce(sum(encaissements.montant), 0) as montant')
        ->first();

    return ['nombre' => (int) ($ligne->nombre ?? 0), 'montant' => (int) ($ligne->montant ?? 0)];
});

/**
 * La ligne de boutons, dans l'ordre demandé.
 *
 * | Bouton | D'où il vient | Paraît |
 * |---|---|---|
 * | une banque | d'une fiche déclarée | **toujours**, même sans une seule écriture |
 * | Mobile money | de l'existence d'au moins un portefeuille déclaré | dès qu'il y en a un |
 * | Moyen non précisé | des écritures sans moyen lisible | dès qu'il y en a une |
 *
 * **Un seul bouton pour tout le mobile money**, et un sous-filtre pour l'opérateur précis :
 * les opérateurs sont quatre, et quatre boutons de plus noieraient les banques. C'est ce que
 * le propriétaire a demandé — « par défaut doit rester sur mobile money ».
 *
 * @return array<string, array{libelle: string, montant: int, nombre: int, sorte: string, vide: bool}>
 */
$comptes = computed(function () {
    $designes = $this->parCompteDesigne;
    $parLibelle = $this->repartition['parBanque'];
    $comptes = [];

    foreach ($this->banques->where('type', Banque::BANQUE) as $banque) {
        $ancien = $parLibelle[$banque->id] ?? ['nombre' => 0, 'montant' => 0];
        $neuf = $designes[$banque->id] ?? ['nombre' => 0, 'montant' => 0];

        $nombre = (int) $ancien['nombre'] + (int) $neuf['nombre'];

        $comptes['b'.$banque->id] = [
            'libelle' => $banque->nom,
            'montant' => (int) $ancien['montant'] + (int) $neuf['montant'],
            'nombre' => $nombre,
            'sorte' => 'banque',
            // Une banque déclarée paraît même sans écriture : on la crée avant d'y encaisser.
            'vide' => $nombre === 0,
        ];
    }

    $mobiles = $this->banques->where('type', Banque::MOBILE);

    if ($mobiles->isNotEmpty()) {
        $nombre = 0;
        $montant = 0;

        foreach ($mobiles as $portefeuille) {
            $part = $designes[$portefeuille->id] ?? ['nombre' => 0, 'montant' => 0];
            $nombre += (int) $part['nombre'];
            $montant += (int) $part['montant'];
        }

        $comptes['mobile'] = [
            'libelle' => 'Mobile money',
            'montant' => $montant,
            'nombre' => $nombre,
            'sorte' => 'mobile',
            'vide' => $nombre === 0,
        ];
    }

    if ($this->nonPrecise['nombre'] > 0) {
        $comptes['inconnu'] = [
            'libelle' => 'Moyen non précisé',
            'montant' => $this->nonPrecise['montant'],
            'nombre' => $this->nonPrecise['nombre'],
            'sorte' => 'inconnu',
            'vide' => false,
        ];
    }

    return $comptes;
});

/** Les portefeuilles déclarés, pour le sous-filtre du mobile money. */
$portefeuilles = computed(fn () => $this->banques->where('type', Banque::MOBILE)->pluck('nom', 'id')->all());

$compteChoisi = computed(fn () => $this->comptes[$this->supportFiltre] ?? null);

// ------------------------------------------------------------------ le tableau

/**
 * Les règlements du compte choisi, avec l'origine demandée.
 *
 * L'origine se lit sur `lot_import_id` : une ligne qui porte un lot vient d'un fichier, une
 * ligne qui n'en porte pas a été tapée ici. C'est la seule chose qui les distingue en base,
 * et c'est suffisant.
 */
$requete = computed(function () {
    $requete = clone $this->entreesQ;
    $choisi = $this->supportFiltre;

    if ($choisi !== '' && isset($this->comptes[$choisi])) {
        $compte = $this->comptes[$choisi];

        if ($compte['sorte'] === 'banque') {
            /*
             * **Les deux chemins, réunis par un `or`.** Une écriture récente désigne son
             * compte ; une écriture ancienne ne porte qu'un nom écrit sur la créance.
             * N'en lire qu'un ferait disparaître la moitié du compte sans le dire.
             */
            $id = (int) substr($choisi, 1);
            $libelles = $this->repartition['parBanque'][$id]['libelles'] ?? [];

            $requete->where(fn ($q) => $q
                ->where('encaissements.banque_id', $id)
                ->when($libelles !== [], fn ($sous) => $sous->orWhere(fn ($ancien) => $ancien
                    ->whereNull('encaissements.banque_id')
                    ->whereHas('facture', fn ($f) => $f->whereIn('banque', $libelles)))));
        } elseif ($compte['sorte'] === 'mobile') {
            // Tous les portefeuilles, ou celui que le sous-filtre désigne.
            $ids = $this->portefeuilleFiltre !== '' && isset($this->portefeuilles[(int) $this->portefeuilleFiltre])
                ? [(int) $this->portefeuilleFiltre]
                : array_keys($this->portefeuilles);

            $requete->whereIn('encaissements.banque_id', $ids ?: [0]);
        } else {
            $requete = SupportDeReglement::appliquer(
                $requete->whereNull('encaissements.banque_id'),
                'encaissements',
                SupportDeReglement::INCONNU,
            );
        }
    }

    return FiltreLibre::appliquer(
        $requete
            ->when($this->origineFiltre === 'import', fn ($q) => $q->whereNotNull('encaissements.lot_import_id'))
            ->when($this->origineFiltre === 'saisie', fn ($q) => $q->whereNull('encaissements.lot_import_id'))
            ->when(trim($this->recherche) !== '', function ($q) {
                $terme = '%'.trim($this->recherche).'%';

                $q->where(fn ($sous) => $sous
                    ->where('encaissements.client', 'like', $terme)
                    ->orWhere('encaissements.numero', 'like', $terme)
                    ->orWhere('encaissements.reference_origine', 'like', $terme)
                    ->orWhere('encaissements.motif', 'like', $terme));
            }),
        $this->colonnesFiltrables,
        (array) $this->filtresLibres,
    );
});

/**
 * Les colonnes du tableau qu'aucun filtre du haut ne couvre.
 *
 * Ni la banque ni l'origine n'y figurent : elles ont leurs propres commandes, et les proposer
 * deux fois laisserait poser deux conditions contradictoires sur la même donnée.
 */
$colonnesFiltrables = computed(fn () => [
    'encaissements.client' => FiltreLibre::colonne('Client'),
    'encaissements.numero' => FiltreLibre::colonne('N° de règlement'),
    'encaissements.reference_origine' => FiltreLibre::colonne('Référence (chèque, transaction)'),
    'encaissements.motif' => FiltreLibre::colonne('Motif'),
    'encaissements.type' => FiltreLibre::colonne('Type'),
    'encaissements.montant' => FiltreLibre::colonne('Montant', 'nombre'),
    'encaissements.date' => FiltreLibre::colonne('Date du règlement', 'date'),
]);

/**
 * Les trois indicateurs, en **une** requête.
 *
 * **Mesuré le 01/10** : chaque lecture d'`encaissements` sous le périmètre coûte 120 à
 * 180 ms — le périmètre s'écrit en `OR` sur `site_id`, ce qu'aucun index ne sait suivre, et
 * la sous-requête corrélée s'évalue ligne par ligne. Trois clones pour trois nombres, c'était
 * trois fois ce prix-là.
 *
 * `count(distinct …)` et `sum()` tiennent dans la même passe : la base lit une fois, et rend
 * les trois.
 */
$kpis = computed(function () {
    $ligne = (clone $this->requete)
        ->selectRaw('count(*) as nombre, coalesce(sum(encaissements.montant), 0) as montant, '
            .'count(distinct encaissements.client) as clients')
        ->first();

    return [
        'montant' => (int) ($ligne->montant ?? 0),
        'nombre' => (int) ($ligne->nombre ?? 0),
        'clients' => (int) ($ligne->clients ?? 0),
    ];
});

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Ce que disent les règlements, ce que dit la banque — demandé le 07/10.
 *
 * « Lorsque BGFI sera cliquée, on doit avoir des KPI qui donnent ce que disent les règlements
 * des factures importées, et ce que dit réellement la banque, avec les factures pas encore
 * réglées, comme sur la page trésorerie. » Les trois se lisent côte à côte et ne
 * s'additionnent jamais : un chèque est écrit à l'état des impayés **et** au relevé.
 *
 * Les montants ont quitté les boutons pour la même raison : un total sur « BGFI » sans dire
 * de quelle source il vient se lisait comme le solde du compte, et ne l'était pas.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */

/** Les banques (au sens strict) que le bouton choisi désigne : une, toutes, ou aucune. */
$banquesRegardees = computed(function () {
    $choisi = (string) $this->supportFiltre;

    if ($choisi === '') {
        return $this->banques->where('type', Banque::BANQUE)->pluck('id')->all();
    }

    return ($this->comptes[$choisi]['sorte'] ?? null) === 'banque' ? [(int) substr($choisi, 1)] : [];
});

/**
 * Le relevé des banques regardées : crédits, débits, solde annoncé — tels que la banque les
 * dit, jamais recalculés. Mêmes chiffres que le bloc « Selon la caisse et la banque » de la
 * trésorerie : c'est la même lecture, `RapprochementDeTresorerie::releves()`.
 *
 * @return array{credits: int, debits: int, nombre: int, solde: ?int, date: ?string}
 */
$releveRegarde = computed(function () {
    [$debut, $fin] = $this->plage;
    $ids = $this->banquesRegardees;

    $releves = RapprochementDeTresorerie::releves($debut, $fin)
        ->filter(fn ($r) => in_array($r['banque']->id, $ids, true));

    return [
        'credits' => (int) $releves->sum('credits'),
        'debits' => (int) $releves->sum('debits'),
        'nombre' => (int) $releves->sum('nombre'),
        // Le solde n'existe que si au moins un relevé le dit ; zéro serait une affirmation.
        'solde' => $releves->every(fn ($r) => $r['solde_fin'] === null) ? null : (int) $releves->sum('solde_fin'),
        'date' => $releves->pluck('date_solde')->filter()->max(),
    ];
});

/** Ce que les règlements disent des banques regardées — la somme des boutons, sans filtre d'origine. */
$reglementsRegardes = computed(function () {
    $ids = $this->banquesRegardees;

    return collect($this->comptes)
        ->filter(fn ($c, $cle) => $c['sorte'] === 'banque' && in_array((int) substr((string) $cle, 1), $ids, true))
        ->reduce(fn ($t, $c) => ['montant' => $t['montant'] + $c['montant'], 'nombre' => $t['nombre'] + $c['nombre']],
            ['montant' => 0, 'nombre' => 0]);
});

/**
 * Les factures pas encore réglées, rangées sous la banque **notée sur la créance**.
 *
 * C'est le seul lien qu'une facture non payée ait avec une banque : elle n'a pas encore de
 * règlement, donc pas de compte désigné. La banque notée (« BGFI » sur la créance d'un
 * assureur qui règle par chèque BGFI) dit où l'argent est attendu. Rangée par la même
 * reconnaissance que les règlements, affectations comprises : un libellé non reconnu ne se
 * range nulle part, comme plus haut.
 *
 * Mêmes bornes que la trésorerie : les factures de la période, au même seuil de solde que
 * partout (`Facture::scopeAvecResteAEncaisser`). Une requête groupée sur le libellé : quelques
 * lignes reviennent, pas des milliers.
 *
 * @return array<int, array{nombre: int, reste: int}>
 */
$facturesOuvertesParBanque = computed(function () {
    [$debut, $fin] = $this->plage;

    $groupes = EtatDesImpayes::dansLePerimetre(Facture::query(), $this->idsSites, $this->idsVilles)
        ->whereBetween('factures.date', [$debut, $fin])
        ->whereNotNull('factures.banque')
        ->avecResteAEncaisser()
        ->groupBy('factures.banque')
        ->selectRaw('factures.banque as libelle, count(*) as nombre, '
            .'coalesce(sum(factures.montant - '.Facture::ENCAISSE_SQL.'), 0) as reste')
        ->toBase()->get();

    $parBanque = [];

    foreach ($groupes as $groupe) {
        $verdict = ReconnaissanceDeBanque::pour($groupe->libelle, $this->banques);

        if ($verdict['verdict'] !== ReconnaissanceDeBanque::CERTAINE) {
            continue;
        }

        $id = $verdict['banque']->id;
        $parBanque[$id] ??= ['nombre' => 0, 'reste' => 0];
        $parBanque[$id]['nombre'] += (int) $groupe->nombre;
        $parBanque[$id]['reste'] += (int) $groupe->reste;
    }

    return $parBanque;
});

$facturesOuvertesRegardees = computed(fn () => collect($this->facturesOuvertesParBanque)
    ->only($this->banquesRegardees)
    ->reduce(fn ($t, $f) => ['nombre' => $t['nombre'] + $f['nombre'], 'reste' => $t['reste'] + $f['reste']],
        ['nombre' => 0, 'reste' => 0]));

/** Les libellés affectés à la banque choisie, pour pouvoir retirer une affectation. */
$libellesAffectesDuCompte = computed(function () {
    $ids = $this->banquesRegardees;

    return (string) $this->supportFiltre === '' || count($ids) !== 1
        ? collect()
        : ($this->banques->firstWhere('id', $ids[0])?->libellesAffectes ?? collect());
});

$lignes = computed(fn () => (clone $this->requete)
    ->with(['facture', 'banque', 'site.ville'])
    ->orderByDesc('encaissements.date')
    ->orderByDesc('encaissements.id')
    ->forPage($this->page, 25)
    ->get());

/* Le même nombre que `kpis['nombre']` : le relire coûterait une seconde lecture entière. */
$total = computed(fn () => $this->kpis['nombre']);

// ------------------------------------------------------------------ déclarer une banque

$ouvrirLaDeclaration = function (?string $nomPropose = null, ?string $type = null) {
    $this->formulaireOuvert = true;
    $this->type = $type ?: Banque::BANQUE;
    $this->nom = $nomPropose ? Banque::formePresentable($nomPropose) : '';
    $this->note = '';
    $this->libelleOrigine = '';
    $this->libelleAAffecter = '';
    $this->resetErrorBag();
};

/*
 * « Modifier » — demandé le 07/10 : le libellé est presque le bon nom ; on le corrige dans
 * le formulaire de création, prérempli, puis on déclare. Le libellé d'origine suit la
 * banque créée — voir `libelleOrigine`.
 */
$ouvrirLaModification = function (string $libelle) {
    $this->ouvrirLaDeclaration($libelle);
    $this->libelleOrigine = $libelle;
    $this->note = 'Déclarée depuis le libellé « '.$libelle.' » trouvé sur les créances.';
};

$fermerLaDeclaration = function () {
    $this->formulaireOuvert = false;
    $this->fill(['type' => Banque::BANQUE, 'nom' => '', 'note' => '', 'libelleOrigine' => '']);
    $this->resetErrorBag();
};

$ouvrirLAffectation = function (string $libelle, ?int $proposee = null) {
    $this->fermerLaDeclaration();
    $this->libelleAAffecter = $libelle;
    $this->banqueAffectee = $proposee ? (string) $proposee : '';
};

$fermerLAffectation = function () {
    $this->fill(['libelleAAffecter' => '', 'banqueAffectee' => '']);
    $this->resetErrorBag();
};

/**
 * Affecte un libellé à une banque déclarée.
 *
 * Une ligne écrite, et une seule : la créance garde ce qui y a été noté. Le libellé se range
 * aussitôt sous la banque — dans ses totaux, son tableau et ses factures pas encore réglées.
 */
$affecterLeLibelle = function () {
    abort_unless($this->peutDeclarer, 403, 'L’affectation des libellés est réservée au gérant.');

    $this->validate([
        'libelleAAffecter' => ['required', 'string', 'max:160'],
        'banqueAffectee' => ['required', Rule::in($this->banques->pluck('id')->map(fn ($id) => (string) $id)->all())],
    ], attributes: ['banqueAffectee' => 'banque', 'libelleAAffecter' => 'libellé']);

    $cle = Banque::clePour($this->libelleAAffecter);

    if ($cle === '') {
        $this->addError('banqueAffectee', 'Ce libellé est vide : il n’y a rien à affecter.');

        return;
    }

    $deja = LibelleDeBanque::query()->where('cle', $cle)->with('banque')->first();

    if ($deja !== null) {
        $this->addError('banqueAffectee', 'Ce libellé est déjà affecté à '.($deja->banque?->nom ?? 'une banque')
            .'. Retirez cette affectation sous cette banque avant d’en poser une autre.');

        return;
    }

    LibelleDeBanque::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'banque_id' => (int) $this->banqueAffectee,
        'libelle' => mb_substr($this->libelleAAffecter, 0, 160),
        'cle' => $cle,
        'cree_par' => auth()->id(),
    ]);

    $nom = $this->banques->firstWhere('id', (int) $this->banqueAffectee)?->nom;
    $this->fermerLAffectation();
    $this->oublierLesBanques();

    session()->flash('message', 'Le libellé est affecté à '.$nom.' : ses règlements sont désormais rangés sous cette banque.');
};

/** Retire une affectation : le libellé revient dans la liste des non rangés. */
$retirerLAffectation = function (int $id) {
    abort_unless($this->peutDeclarer, 403, 'L’affectation des libellés est réservée au gérant.');

    LibelleDeBanque::query()->whereKey($id)->delete();
    $this->oublierLesBanques();
};

/** Tout ce qui dépend des fiches et de leurs libellés se relit. */
$oublierLesBanques = function () {
    unset($this->banques, $this->repartition, $this->libelles, $this->comptes, $this->banquesRegardees,
        $this->facturesOuvertesParBanque, $this->libellesAffectesDuCompte);
};

/*
 * Passer d'un type à l'autre vide le nom.
 *
 * « BGFI » n'a pas de sens sous « mobile money », et « ORANGE » n'en a pas sous « banque ».
 * Le garder ferait déclarer un portefeuille nommé d'après une banque, que l'écran rangerait
 * ensuite dans la mauvaise ligne de boutons.
 */
$updatedType = function () {
    $this->nom = '';
};

/**
 * Déclare une banque.
 *
 * **Le droit est revérifié ici**, et pas seulement sur la route : une route ne protège que
 * l'entrée, et une action Livewire s'appelle depuis le navigateur.
 *
 * **Le nom est unique par sa forme réduite**, pas par sa lettre : « BGFI » et « B.G.F.I » sont
 * le même établissement, et deux fiches couperaient ses totaux en deux.
 */
$declarerLaBanque = function () {
    abort_unless($this->peutDeclarer, 403, 'La déclaration des banques est réservée au gérant.');

    $donnees = $this->validate([
        'type' => ['required', Rule::in([Banque::BANQUE, Banque::MOBILE])],
        'nom' => ['required', 'string', 'max:120'],
        'note' => ['nullable', 'string', 'max:500'],
    ], attributes: ['nom' => 'nom du compte', 'type' => 'type de compte']);

    $cle = Banque::clePour($donnees['nom']);

    if ($cle === '') {
        $this->addError('nom', "Ce nom ne porte aucune lettre : ce n'est pas un nom de banque.");

        return;
    }

    if (Banque::query()->where('nom_normalise', $cle)->exists()) {
        $this->addError('nom', 'Cette banque est déjà déclarée : ses règlements se rejoindraient sur deux fiches.');

        return;
    }

    $banque = Banque::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'nom' => Banque::formePresentable($donnees['nom']),
        'nom_normalise' => $cle,
        'type' => $donnees['type'],
        // Le code est posé par le système, jamais saisi : un code tapé à la main finit par
        // exister en double, et deux comptes de même code rendent impointable tout
        // rapprochement qui s'appuie dessus.
        'code' => GenerateurNumero::suivant((int) auth()->user()->entreprise_id, Banque::SERIE),
        'note' => $donnees['note'] ?: null,
        'cree_par' => auth()->id(),
    ]);

    unset($this->codeAVenir);

    // Ouverte par « Modifier » : le libellé d'origine suit la banque, sauf s'il porte déjà
    // son nom — la reconnaissance le range alors sans aide.
    $origine = Banque::clePour($this->libelleOrigine);

    if ($origine !== '' && $origine !== $cle && ! LibelleDeBanque::query()->where('cle', $origine)->exists()) {
        LibelleDeBanque::create([
            'entreprise_id' => $banque->entreprise_id,
            'banque_id' => $banque->id,
            'libelle' => mb_substr($this->libelleOrigine, 0, 160),
            'cle' => $origine,
            'cree_par' => auth()->id(),
        ]);
    }

    $this->oublierLesBanques();

    $this->fermerLaDeclaration();

    session()->flash('message', 'La banque est déclarée. Elle paraît aussitôt dans la ligne ci-dessous, '
        .'et dans les listes de l’import et des saisies.');
};

?>

<div>
    <x-titre-ecran titre="Banques"
        sous-titre="Ce qui est passé par chaque compte : les règlements reçus autrement qu'en espèces.">
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            @if ($this->peutDeclarer)
                <button type="button" wire:click="ouvrirLaDeclaration" class="bouton" style="padding:8px 14px;">
                    + Déclarer une banque
                </button>
            @endif

            <a href="{{ route('tresorerie') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; text-decoration:none; margin-left:auto;">← Retour à la trésorerie</a>
        </div>
    </x-titre-ecran>

    @if (session('message'))
        <div class="encart encart-succes" style="margin-bottom:16px;">{{ session('message') }}</div>
    @endif

    @if ($formulaireOuvert)
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Déclarer une banque</h3>

            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="Type de compte" model="type" type="select" :live="true" width="190"
                    :options="[Banque::BANQUE => 'Banque', Banque::MOBILE => 'Mobile money']" />

                @if ($type === Banque::MOBILE)
                    {{-- **Les portefeuilles sont proposés, jamais créés d'office.** Le
                         propriétaire les a nommés — « MOBILE MONEY (si pas précisé), MTN,
                         ORANGE, MOOV, WAVE » —, et ils sont offerts ici. Les poser en base à
                         l'installation écrirait cinq comptes que personne n'a demandés dans
                         une base qui porte des données réelles.

                         « MOBILE MONEY » vient en premier, et c'est le défaut voulu : le
                         relevé de caisse dit souvent « mobile money » sans nommer
                         l'opérateur, et forcer un choix ferait inventer une précision. --}}
                    {{-- Les deux champs portent la **même** propriété, et c'est voulu :
                         « on choisit si disponible, sinon on crée ». La liste est `live`
                         pour que le champ libre montre aussitôt ce qu'on vient d'y choisir —
                         sans quoi l'un afficherait une valeur et l'autre une autre. --}}
                    <x-champ label="Portefeuille" model="nom" type="select" :live="true" :requis="true" width="220"
                        :options="array_combine(Banque::PORTEFEUILLES, Banque::PORTEFEUILLES)"
                        vide="— à choisir, ou saisir ci-contre —" />
                    <x-champ label="…ou un autre nom" model="nom" :live="true" width="200"
                        placeholder="Un opérateur non listé" />
                @else
                    <x-champ label="Nom de la banque" model="nom" :requis="true" width="260"
                        placeholder="BGFI, BNI, BDA…" />
                @endif

                {{-- Le code est posé par le système : il se montre, il ne se touche pas. --}}
                <x-champ-fige label="Code" :valeur="$this->codeAVenir" width="120" />

                <x-champ label="Note" model="note" width="240" placeholder="Facultatif" />

                <button type="button" wire:click="declarerLaBanque" class="bouton">Déclarer</button>
                <button type="button" wire:click="fermerLaDeclaration" class="bouton bouton-secondaire">Annuler</button>
            </div>

            <p style="margin:10px 0 0; font-size:12.5px; color:#6B6E76; line-height:1.55;">
                Le nom est enregistré en capitales, et deux écritures du même établissement se
                rejoignent sur leur forme réduite : « BGFI » et « B.G.F.I » sont le même compte.
                <b>Pas de ville</b> : le compte sert l'entreprise entière, et il doit se proposer
                partout — à l'import comme aux saisies du recouvrement et des impayés.
            </p>

            <x-erreurs-du-bloc prefixe="type" />
            <x-erreurs-du-bloc prefixe="nom" />
        </div>
    @endif

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin"
        :villes="$this->mesVilles" :ville-unique="$this->villeUnique" :ville-filtre="$villeFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        masquer-activite />

    {{-- ─────────────────────────────── la ligne des comptes

         Trois sortes de boutons : les banques déclarées — qui paraissent **même vides** —, les
         portefeuilles mobiles trouvés dans les écritures, et le reliquat des moyens illisibles.

         **Sans montant depuis le 07/10** : « ne mets pas le solde des montants sur les banques,
         de sorte à ne pas prêter à confusion ». Un total sur « BGFI » se lisait comme le solde
         du compte ; il n'était que la somme des règlements notés. Les chiffres sont dans les
         KPI ci-dessous, chacun avec sa source. --}}
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
        <button type="button" wire:click="$set('supportFiltre', '')"
            class="bouton {{ $supportFiltre === '' ? '' : 'bouton-secondaire' }}"
            @if ($supportFiltre === '') aria-current="true" @endif
            style="padding:9px 16px;">
            Toutes les banques
        </button>

        @foreach ($this->comptes as $cle => $compte)
            @php $actif = (string) $supportFiltre === (string) $cle; @endphp
            <button type="button" wire:click="$set('supportFiltre', '{{ $actif ? '' : $cle }}')"
                class="bouton {{ $actif ? '' : 'bouton-secondaire' }}"
                @if ($actif) aria-current="true" @endif
                @if ($compte['vide']) title="Déclarée, aucune écriture sur cette période" @endif
                style="padding:9px 16px; {{ $compte['vide'] && ! $actif ? 'opacity:.62;' : '' }}">
                {{ $compte['libelle'] }}
            </button>
        @endforeach

        @if ($this->banques->isEmpty())
            <span style="font-size:13px; color:#6B6E76; align-self:center;">
                Aucune banque déclarée — les libellés trouvés sont listés plus bas.
            </span>
        @endif
    </div>

    {{-- ─────────────────────────────── l'origine, en filtre et non en boutons

         Demandé le 01/10. Les boutons du dessus disent **où** est l'argent ; ce filtre dit
         **d'où vient la ligne**. Deux questions, deux formes — et les mêmes trois valeurs que
         sur l'écran Caisse, pour qu'on ne les réapprenne pas. --}}
    <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center; margin-bottom:16px;">
        <span style="font-size:12.5px; color:#6B6E76;">Origine</span>
        <select wire:model.live="origineFiltre" class="champ" style="width:auto;">
            <option value="" @selected($origineFiltre === '')>Importé et saisi</option>
            <option value="import" @selected($origineFiltre === 'import')>Importé seulement</option>
            <option value="saisie" @selected($origineFiltre === 'saisie')>Saisi dans l’application</option>
        </select>

        {{-- ─────────────────────────────── l'opérateur, et seulement sous le mobile money

             Demandé le 01/10 : « dans la page de mobile money, on doit avoir un filtre qui
             choisit le mobile précis si disponible, mais par défaut doit rester sur mobile
             money ». D'où un bouton unique pour l'ensemble et ce sous-filtre : quatre
             opérateurs feraient quatre boutons de plus, et noieraient les banques. --}}
        @if (($this->compteChoisi['sorte'] ?? null) === 'mobile' && $this->portefeuilles !== [])
            <span style="font-size:12.5px; color:#6B6E76; margin-left:8px;">Opérateur</span>
            <select wire:model.live="portefeuilleFiltre" class="champ" style="width:auto;">
                <option value="" @selected($portefeuilleFiltre === '')>Tous les portefeuilles</option>
                @foreach ($this->portefeuilles as $id => $nom)
                    <option value="{{ $id }}" @selected((string) $portefeuilleFiltre === (string) $id)>{{ $nom }}</option>
                @endforeach
            </select>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Encaissé — {{ $this->compteChoisi['libelle'] ?? 'tous les comptes' }}"
            :value="ae($this->kpis['montant'])" couleur="#0E9F6E" :sub="$this->libellePerimetre" />
        <x-kpi-card label="Règlements" :value="number_format($this->kpis['nombre'], 0, ',', ' ')"
            sub="Hors espèces" />
        <x-kpi-card label="Clients distincts" :value="number_format($this->kpis['clients'], 0, ',', ' ')" />
    </div>

    {{-- ─────────────────────────────── règlements face au relevé — 07/10

         Seulement pour une banque, ou pour toutes : un portefeuille mobile ne paraît sur aucun
         relevé, et « Moyen non précisé » n'est pas un compte. --}}
    @if ($this->banquesRegardees !== [])
        @php
            $reg = $this->reglementsRegardes;
            $rel = $this->releveRegarde;
            $ouv = $this->facturesOuvertesRegardees;
            $nomRegarde = $supportFiltre === '' ? 'toutes les banques' : ($this->compteChoisi['libelle'] ?? '');
        @endphp
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">Ce que disent les règlements, ce que dit la banque — {{ $nomRegarde }}</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
                Trois sources, lues côte à côte et jamais additionnées : un chèque est écrit à l'état
                des impayés <b>et</b> au relevé. Le relevé est celui de l'entreprise : le filtre de
                ville ne s'y applique pas.
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px;">
                <x-kpi-card label="Selon les règlements importés et saisis" :value="ae($reg['montant'])" couleur="#0E9F6E"
                    sub="{{ number_format($reg['nombre'], 0, ',', ' ') }} règlement(s) — CA, état des impayés, saisies" />
                <x-kpi-card label="Selon la banque — crédits du relevé" :value="$rel['nombre'] > 0 ? ae($rel['credits']) : '—'"
                    sub="{{ $rel['nombre'] > 0 ? 'Débits '.ae($rel['debits']).' · '.number_format($rel['nombre'], 0, ',', ' ').' opération(s)' : 'Aucun relevé déposé pour cette période' }}" />
                <x-kpi-card label="Solde annoncé par la banque" :value="$rel['solde'] === null ? '—' : ae($rel['solde'])"
                    :couleur="($rel['solde'] ?? 0) >= 0 ? '#0E9F6E' : '#C8102E'"
                    sub="{{ $rel['date'] ? 'Au '.\Illuminate\Support\Carbon::parse($rel['date'])->format('d/m/Y').', tel quel' : 'Tel que le relevé le dit, jamais recalculé' }}" />
                <x-kpi-card label="Écart (banque − règlements)" :value="$rel['nombre'] > 0 ? ae($rel['credits'] - $reg['montant']) : '—'"
                    :couleur="$rel['nombre'] > 0 && $rel['credits'] - $reg['montant'] !== 0 ? '#C8102E' : '#0E9F6E'"
                    sub="Le détail ligne à ligne : Trésorerie, « Lignes rapprochement CA-Banque »" />
                <x-kpi-card label="Factures pas encore réglées" :value="ae($ouv['reste'])" couleur="#B45309"
                    sub="{{ number_format($ouv['nombre'], 0, ',', ' ') }} facture(s) de la période, banque notée sur la créance" />
            </div>

            @if ($this->libellesAffectesDuCompte->isNotEmpty())
                <div style="margin-top:12px; font-size:12.5px; color:#4B4E55; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span style="color:#6B6E76;">Libellés affectés à cette banque :</span>
                    @foreach ($this->libellesAffectesDuCompte as $affecte)
                        <span style="border:1px solid var(--th-ligne,#E2E0D8); border-radius:999px; padding:2px 4px 2px 10px;">
                            {{ $affecte->libelle }}
                            @if ($this->peutDeclarer)
                                <button type="button" wire:click="retirerLAffectation({{ $affecte->id }})"
                                    class="bouton bouton-secondaire" style="padding:1px 7px; font-size:11px; margin-left:4px;"
                                    title="Retirer : le libellé revient dans la liste des non rangés">Retirer</button>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ─────────────────────────────── ce qu'on ne sait pas ranger

         **Rendu à part plutôt que fondu dans un total.** C'est la liste à corriger, et elle
         vaut mieux qu'un total faux qui ne dit rien. Chaque ligne porte la raison du refus :
         une faute de frappe ne se corrige pas comme deux banques dans une même case. --}}
    @if ($this->repartition['nonRanges'] !== [])
        <div class="carte" style="margin-bottom:16px; border-left:3px solid #C8102E;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">
                {{ count($this->repartition['nonRanges']) }} libellé(s) ne sont rangés sous aucune banque
            </h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.55;">
                Ils ne sont comptés dans aucun bouton ci-dessus. Les ranger au jugé fausserait des
                totaux sans que rien ne le signale. <b>Déclarer</b> crée la banque sous ce nom ;
                <b>Modifier</b> ouvre la même création pour corriger le nom d'abord ; <b>Affecter à</b>
                range le libellé sous une banque déjà déclarée. La créance garde ce qui y a été noté.
            </p>

            @if ($libelleAAffecter !== '')
                <div class="bloc-saisie" style="background:#fff; border-style:solid; margin-bottom:12px;">
                    <span style="font-size:13px; align-self:center;">Affecter « <b>{{ $libelleAAffecter }}</b> » à</span>
                    <x-champ label="Banque" model="banqueAffectee" type="select" :requis="true" width="220"
                        :options="$this->banques->pluck('nom', 'id')->all()" vide="— choisir une banque —" />
                    <button type="button" wire:click="affecterLeLibelle" class="bouton">Affecter</button>
                    <button type="button" wire:click="fermerLAffectation" class="bouton bouton-secondaire">Annuler</button>
                </div>
                <x-erreurs-du-bloc prefixe="banqueAffectee" />
            @endif

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Libellé trouvé</th>
                            <th style="text-align:right;">Règlements</th>
                            <th style="text-align:right;">Montant</th>
                            <th>Pourquoi</th>
                            @if ($this->peutDeclarer)
                                <th class="colonne-collee"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->repartition['nonRanges'] as $ligne)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $ligne['libelle'] ?: '— vide —' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $ligne['nombre'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne['montant']) }}</td>
                                <td style="font-size:12.5px; color:#6B6E76;">{{ $ligne['raison'] }}</td>
                                @if ($this->peutDeclarer)
                                    <td class="colonne-collee" style="white-space:nowrap;">
                                        @if ($ligne['libelle'])
                                            <button type="button" class="bouton bouton-secondaire"
                                                style="padding:3px 9px; font-size:11.5px;"
                                                wire:click="ouvrirLaDeclaration(@js($ligne['libelle']))">
                                                Déclarer
                                            </button>
                                            @if ($this->banques->isNotEmpty())
                                                <button type="button" class="bouton bouton-secondaire"
                                                    style="padding:3px 9px; font-size:11.5px;"
                                                    wire:click="ouvrirLAffectation(@js($ligne['libelle']), {{ $ligne['proposee'] ?? 'null' }})">
                                                    Affecter à…
                                                </button>
                                            @endif
                                            <button type="button" class="bouton bouton-secondaire"
                                                style="padding:3px 9px; font-size:11.5px;"
                                                wire:click="ouvrirLaModification(@js($ligne['libelle']))">
                                                Modifier
                                            </button>
                                        @endif
                                    </td>
                                @endif
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
                Règlements ({{ number_format($this->total, 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap; align-items:center;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Client, n° de règlement…" class="champ"
                    style="width:auto; min-width:0; flex:0 1 230px;">

                <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>N° de règlement</th>
                        <th>Client</th>
                        <th>Moyen</th>
                        {{-- Deux colonnes et non une : « Compte » est ce que l'écriture
                             désigne, « Banque notée » ce que quelqu'un a écrit sur la
                             créance. Les fondre en une ferait passer une mention pour une
                             désignation. --}}
                        <th>Compte</th>
                        <th>Banque notée</th>
                        <th>Origine</th>
                        <th class="colonne-collee" style="text-align:right;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->numero ?: '—' }}</td>
                            <td>{{ $ligne->client ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->moyen ?: '—' }}</td>
                            <td>{{ $ligne->banque?->nom ?: '—' }}</td>
                            {{-- « Banque notée » et non « Banque » : c'est ce que quelqu'un a
                                 écrit sur la créance, pas ce qu'un relevé confirme. --}}
                            <td style="color:#6B6E76;">{{ $ligne->facture?->banque ?: '—' }}</td>
                            <td style="font-size:11.5px; color:#6B6E76;">
                                {{ $ligne->lot_import_id === null ? 'Saisi ici' : 'Importé' }}
                            </td>
                            <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">
                                {{ ae((int) $ligne->montant) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="8" texte="Aucun règlement sur cette période pour ce compte." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->total" prop="page" :par-page="25" />
    </div>
</div>

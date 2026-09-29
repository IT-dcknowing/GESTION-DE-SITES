<?php

use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Illuminate\Validation\Rule;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Modeles\OuvertureCaisse;

use function Livewire\Volt\{computed, mount, protect, state};

/**
 * Les états de caisse repris du logiciel d'atelier.
 *
 * **Pourquoi cet écran existe.** Le fichier « États de caisse » était lu, ses mille cent
 * cinquante-cinq mouvements étaient en base depuis le 8 septembre — et aucun écran ne les
 * affichait. Une donnée importée que personne ne peut voir n'a pas été importée : elle a
 * été rangée. Le travail de dépôt, de contrôle et de correction ne servait à rien tant que
 * la dernière marche manquait.
 *
 * **Le périmètre se lit par ville, pas par atelier.** Le fichier ne porte pas l'atelier :
 * les mille cent cinquante-cinq lignes ont une ville et aucune n'a de site. Filtrer par
 * site aurait rendu l'écran vide, ce qui aurait ressemblé à une panne alors que c'est le
 * fichier qui ne le dit pas.
 *
 * **Le solde annoncé est montré à côté du nôtre, jamais à sa place.** Le fichier porte son
 * propre solde courant ; on le recopie sans le corriger. Un écart entre les deux n'est pas
 * une erreur de calcul de notre côté, c'est le signe qu'une ligne a été retouchée à la main
 * dans le classeur — et c'est précisément ce qu'on veut pouvoir montrer.
 *
 * **Refait le 23/09 sur les colonnes du journal.** L'écran avait été bâti sur le classeur
 * tenu à la main d'Abidjan, seule source lue à l'époque. Depuis que le **journal de caisse
 * imprimé** entre à son tour — c'est tout Bouaké et tout San-Pédro, 1 104 mouvements —,
 * la page liste ce que les fichiers portent, et dans leur ordre : le **n° de pièce**, le
 * **motif**, le **remettant ou le bénéficiaire** sortis du libellé, le **solde progressif**,
 * le **nom de la caisse** et le **solde avant la période**. C'est la règle posée par le
 * propriétaire : les colonnes d'une page listent d'abord celles du fichier d'origine.
 *
 * **Une colonne que la source ne porte pas ne s'affiche pas.** Le classeur d'Abidjan n'a ni
 * numéro de pièce ni motif ; les lui réserver deux colonnes de tirets donnerait à croire
 * qu'il manque une saisie, alors que le fichier ne le dit simplement pas. Les colonnes
 * propres au journal n'apparaissent donc que si la période regardée en contient.
 *
 * **Le solde avant la période ne se devine pas.** Il part de ce que la source **annonce**
 * — « SOLDE AVANT LA PERIODE : 31 260 » sur le journal, « SOLDE D'OUVERTURE » en
 * quatrième ligne de chaque onglet du classeur — auquel on ajoute les mouvements survenus
 * entre cette annonce et le début de la période regardée. Sans annonce, on le reconstitue
 * à partir des seuls mouvements connus, **et la page le dit** : la caisse vivait avant le
 * premier fichier déposé, et présenter un cumul partiel comme un solde serait faux.
 */
state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'villeFiltre' => '',
    'caisseFiltre' => '',
    'sensFiltre' => '',
    'recherche' => '',
    'pageDetail' => 1,
    /*
     * D'où vient la ligne : du journal du logiciel, ou saisie ici. C'est un filtre de
     * l'écran et non un « autre filtre », parce que les deux sources ne vivent pas dans la
     * même table — la condition ne se pose pas sur une colonne, elle choisit une source.
     */
    'origineFiltre' => '',

    // Le formulaire de saisie, replié tant qu'on ne le demande pas.
    'saisieOuverte' => false,
    'saisieSens' => 'entree',
    'saisieDate' => '',
    'saisieMontant' => '',
    'saisieLibelle' => '',
    'saisieTiers' => '',
    'saisieReference' => '',
    'saisieVilleId' => '',
    /*
     * Les filtres posés sur les colonnes sans filtre propre — voir `FiltreLibre` et le
     * composant `x-autre-filtre`. Hors de l'adresse : un tableau de tableaux ne se
     * sérialise pas lisiblement dans une URL, pour un gain nul.
     */
    'filtresLibres' => [],
]);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->pageDetail = 1; };
$updatedVilleFiltre = function () { $this->caisseFiltre = ''; $this->pageDetail = 1; };
$updatedCaisseFiltre = function () { $this->pageDetail = 1; };
$updatedSensFiltre = function () { $this->pageDetail = 1; };
$updatedRecherche = function () { $this->pageDetail = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin,
    $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null,
));

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));

/*
 * Les villes **du formulaire de saisie**, en `id => nom`.
 *
 * `optionsVilles()` rend des modèles Ville, et le filtre de période sait les lire : il
 * parcourt la collection et prend `$ville->nom`. Le composant `x-champ`, lui, parcourt ses
 * options en `valeur => libellé` : donnez-lui des modèles et il affiche la clé numérique de
 * la collection en valeur et **le modèle lui-même en libellé**, que Blade rend alors en
 * JSON. C'est la liste illisible relevée le 29/09.
 *
 * Elle rend un tableau vide plutôt que null, et ce n'est pas du zèle : la vue compte ces
 * villes pour décider d'afficher le champ, et `count(null)` est fatal en PHP 8 — le
 * formulaire entier serait tombé pour un chef d'atelier qui ne voit qu'une ville.
 */
$villesDeSaisie = computed(fn () => $this->mesVilles?->pluck('nom', 'id')->all() ?? []);
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsVilles = computed(fn () => PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre));
$libellePerimetre = computed(fn () => PerimetreSites::libellePerimetre(auth()->user(), $this->villeFiltre));

/**
 * Le périmètre nu : la ville et la période, rien d'autre.
 *
 * Séparé des filtres de confort à dessein. Le rapprochement du solde suit une chaîne de
 * mouvements dans l'ordre où ils se sont produits ; la calculer sur une liste réduite à
 * « sorties seulement » ou au résultat d'une recherche donnerait un écart qui ne dirait
 * rien d'autre que « vous avez filtré ».
 */
$perimetre = computed(fn () => (clone $this->perimetreDeLaCaisse)
    ->whereBetween('date', $this->plage));

/**
 * La caisse et la ville, sans la période.
 *
 * Le solde d'avant se lit forcément **hors** de la période regardée : c'est tout son
 * objet. Il lui faut donc un périmètre qui s'arrête à la ville et à la caisse.
 */
$perimetreDeLaCaisse = computed(fn () => MouvementCaisse::query()
    ->whereIn('ville_id', $this->idsVilles)
    ->when($this->caisseFiltre !== '', fn ($q) => $q->where('caisse', $this->caisseFiltre)));

/** Les caisses que la ville regardée connaît — le journal les nomme, le classeur non. */
$caisses = computed(fn () => MouvementCaisse::query()
    ->whereIn('ville_id', $this->idsVilles)
    ->whereNotNull('caisse')
    ->distinct()
    ->orderBy('caisse')
    ->pluck('caisse')
    ->all());

/**
 * Les colonnes que les fichiers de cette période portent réellement.
 *
 * Deux lectures en base, et elles évitent d'afficher trois colonnes vides à qui ne dépose
 * qu'un classeur tenu à la main.
 */
$colonnesDuFichier = computed(fn () => [
    'piece' => (clone $this->requete)->whereNotNull('numero_piece')->exists(),
    'motif' => (clone $this->requete)->whereNotNull('motif')->exists(),
    'caisse' => count($this->caisses) > 1,
]);

/**
 * Les colonnes du journal qu'aucun filtre du haut ne couvre.
 *
 * Ne figurent pas ici celles qui en ont déjà un : la période, la ville, le sens, et la
 * recherche sur le libellé, le bénéficiaire, le motif, la pièce et la plaque.
 *
 * `caisse` est une liste : le journal ne connaît qu'une poignée de caisses nommées, et les
 * taper à la main avec une faute ne trouverait rien.
 */
$colonnesFiltrables = computed(fn () => [
    // `caisses` rend un tableau simple : on le retourne en valeur => libellé, qui est ce
    // qu'une liste déroulante attend.
    'mouvements_caisse.caisse' => FiltreLibre::colonne('Caisse', 'liste',
        array_combine($this->caisses, $this->caisses) ?: []),
    'mouvements_caisse.type_piece' => FiltreLibre::colonne('Type de pièce'),
    'mouvements_caisse.role_tiers' => FiltreLibre::colonne('Rôle du tiers'),
    'mouvements_caisse.beneficiaire' => FiltreLibre::colonne('Bénéficiaire / remettant'),
    'mouvements_caisse.motif' => FiltreLibre::colonne('Motif'),
    'mouvements_caisse.numero_piece' => FiltreLibre::colonne('N° de pièce'),
    'mouvements_caisse.immatriculation' => FiltreLibre::colonne('Immatriculation'),
    'mouvements_caisse.montant' => FiltreLibre::colonne('Montant', 'nombre'),
    'mouvements_caisse.date' => FiltreLibre::colonne('Date du mouvement', 'date'),
]);

$requete = computed(fn () => FiltreLibre::appliquer(
    (clone $this->perimetre)
        ->when($this->sensFiltre, fn ($q) => $q->where('sens', $this->sensFiltre))
        ->when(trim($this->recherche) !== '', function ($q) {
            $terme = '%'.trim($this->recherche).'%';

            $q->where(fn ($sous) => $sous->where('libelle', 'like', $terme)
                ->orWhere('beneficiaire', 'like', $terme)
                ->orWhere('motif', 'like', $terme)
                ->orWhere('numero_piece', 'like', $terme)
                ->orWhere('immatriculation', 'like', $terme));
        }),
    $this->colonnesFiltrables,
    (array) $this->filtresLibres,
));

/**
 * Ce que la caisse contenait avant le premier jour regardé.
 *
 * On part de l'annonce la plus récente qui précède la période — le document la donne, on
 * ne la recalcule pas — puis on lui applique les mouvements qui séparent cette annonce du
 * début de la période. Les deux bouts se rejoignent ainsi sans qu'on ait à supposer que
 * notre base connaît toute la vie de la caisse, ce qu'elle ne fait pas.
 *
 * @return array{montant: int, annonce: OuvertureCaisse|null}
 */
$soldeAvant = computed(function () {
    [$debut] = $this->plage;

    $annonce = OuvertureCaisse::query()
        ->whereIn('ville_id', $this->idsVilles)
        ->when($this->caisseFiltre !== '', fn ($q) => $q->where('caisse', $this->caisseFiltre))
        ->whereDate('debut', '<=', $debut)
        ->orderByDesc('debut')
        ->first();

    $avant = (clone $this->perimetreDeLaCaisse)->whereDate('date', '<', $debut);

    if ($annonce !== null) {
        // Les mouvements d'avant l'annonce sont déjà compris dedans : les recompter les
        // ferait compter deux fois.
        $avant->whereDate('date', '>=', $annonce->debut);
    }

    $entrees = (int) (clone $avant)->where('sens', MouvementCaisse::ENTREE)->sum('montant');
    $sorties = (int) (clone $avant)->where('sens', MouvementCaisse::SORTIE)->sum('montant');

    return [
        'montant' => (int) ($annonce->solde_avant ?? 0) + $entrees - $sorties,
        'annonce' => $annonce,
        'phrase' => $annonce === null
            ? "Reconstitué à partir des seuls mouvements connus : aucun fichier ne l'annonce."
            : sprintf(
                'Annoncé par le fichier au %s, puis suivi mouvement par mouvement.',
                $annonce->debut?->format('d/m/Y') ?? '—',
            ),
    ];
});

/**
 * Les totaux de la période — les deux sources comprises.
 *
 * **Ils comptent aussi ce qui a été saisi ici**, et c'est nécessaire : ce sont de vraies
 * espèces entrées ou sorties du tiroir. Les taire ferait afficher un solde de période qui
 * ne correspondrait à rien de réel, et personne ne saurait pourquoi la caisse ne tombe pas
 * juste. Le filtre d'origine les suit : demander « le journal seulement » donne les totaux
 * du journal seul.
 */
$kpis = computed(function () {
    $lignes = $this->mouvements;

    $entrees = (int) $lignes->where('sens', MouvementCaisse::ENTREE)->sum('montant');
    $sorties = (int) $lignes->where('sens', MouvementCaisse::SORTIE)->sum('montant');

    return [
        'entrees' => $entrees,
        'sorties' => $sorties,
        'solde' => $entrees - $sorties,
        'lignes' => $lignes->count(),
        // Ce que la saisie apporte, dit à part : c'est ce que le prochain état de caisse
        // devra porter, et c'est le chiffre d'un rapprochement.
        'saisies' => $this->origineFiltre === 'journal' ? 0 : $this->saisiesEnEspeces->count(),
    ];
});

/**
 * Le solde du fichier, refait plutôt que recopié.
 *
 * **Ce qui n'allait pas.** L'écran prenait le solde courant de la dernière ligne et le
 * posait à côté du total entrées moins sorties de la période, en appelant écart la
 * différence entre les deux. Ce n'en était pas un : le solde courant du classeur part d'un
 * fonds de caisse déjà présent avant la période, tandis que notre total ne compte que ce
 * qui a bougé pendant. Deux nombres qui ne mesurent pas la même chose ne peuvent pas
 * diverger — ils n'ont jamais été d'accord.
 *
 * **Ce qu'on fait maintenant.** On part du solde que le fichier lui-même annonce sur sa
 * première ligne de la période, on lui applique un à un tous les mouvements qui suivent,
 * et on compare le résultat au solde de la dernière ligne. Là, les deux nombres mesurent
 * exactement la même chose, et l'écart devient un vrai renseignement : quelque part entre
 * les deux, le solde du classeur a sauté sans qu'un mouvement l'explique — une ligne
 * retouchée à la main, un apport d'espèces non saisi, ou un report entre deux feuillets.
 *
 * Rendu seulement quand il y a quelque chose à dire : quand les deux coïncident, il n'y a
 * pas d'écart à montrer, et un indicateur qui répète « tout va bien » cesse d'être lu.
 *
 * @return array{depart: MouvementCaisse, arrivee: MouvementCaisse, attendu: int, annonce: int, ecart: int}|null
 */
$rapprochement = computed(function () {
    $annonces = fn () => (clone $this->perimetre)->whereNotNull('solde_annonce');

    $depart = $annonces()->orderBy('date')->orderBy('id')->first();
    $arrivee = $annonces()->orderByDesc('date')->orderByDesc('id')->first();

    // Une seule ligne annoncée ne fait pas une chaîne : il n'y a rien à vérifier.
    if ($depart === null || $arrivee === null || $depart->id === $arrivee->id) {
        return null;
    }

    // Tout ce qui vient après la ligne de départ. Elle-même est exclue : le solde qu'elle
    // annonce la comprend déjà, et la recompter la ferait compter deux fois.
    $jourDepart = $depart->date->toDateString();

    $suite = (clone $this->perimetre)->where(fn ($q) => $q
        ->whereDate('date', '>', $jourDepart)
        ->orWhere(fn ($memeJour) => $memeJour->whereDate('date', $jourDepart)->where('id', '>', $depart->id)));

    $attendu = (int) $depart->solde_annonce
        + (int) (clone $suite)->where('sens', MouvementCaisse::ENTREE)->sum('montant')
        - (int) (clone $suite)->where('sens', MouvementCaisse::SORTIE)->sum('montant');

    $annonce = (int) $arrivee->solde_annonce;

    if ($annonce === $attendu) {
        return null;
    }

    // La phrase est montée ici plutôt que dans le gabarit : elle porte des apostrophes,
    // et une apostrophe dans une expression d'attribut Blade casse la compilation.
    $phrase = sprintf(
        "Solde du %s suivi mouvement par mouvement jusqu'au %s. Le solde du classeur a sauté sans qu'un mouvement l'explique.",
        $depart->date->format('d/m/Y'),
        $arrivee->date->format('d/m/Y'),
    );

    return compact('depart', 'arrivee', 'attendu', 'annonce', 'phrase') + ['ecart' => $annonce - $attendu];
});

/**
 * Les plus grosses sorties : c'est là que se joue la caisse, pas dans les petites lignes.
 *
 * **On groupe par motif quand la source en donne un.** Le journal imprimé range ses
 * dépenses sous seize motifs — ACHATS DIVERS, CARBURANT, REGLEMENT — et écrit à côté le
 * détail libre de l'opérateur. Grouper ce détail donnerait quatre cent soixante-huit
 * postes d'une ligne chacun, c'est-à-dire aucun poste. Le classeur tenu à la main, lui,
 * n'a pas de motif : son libellé **est** le poste, et c'est lui qu'on groupe.
 */
$grossesSorties = computed(function () {
    $colonne = $this->colonnesDuFichier['motif'] ? 'motif' : 'libelle';

    return (clone $this->requete)
        ->where('sens', MouvementCaisse::SORTIE)
        ->selectRaw($colonne.' as poste, count(*) as nombre, sum(montant) as total')
        ->groupBy($colonne)->orderByDesc('total')->limit(8)->get();
});

/**
 * La page affichée du tableau unique.
 *
 * Paginée à la main plutôt que par la base : les deux sources vivent dans des tables
 * différentes, et une union SQL entre elles coûterait plus cher à écrire et à relire
 * qu'elle ne rapporte — la période borne déjà le volume à quelques centaines de lignes.
 */
$detail = computed(fn () => $this->mouvements->forPage(max(1, (int) $this->pageDetail), 25));

/**
 * Qui peut saisir un mouvement de caisse.
 *
 * Les mêmes que pour une pièce fournisseur : le gérant, le responsable de ville et le
 * comptable. L'écran était en lecture seule, et c'est à ce titre qu'il avait été ouvert
 * largement — le responsable d'atelier continue donc de lire sans écrire.
 */
$peutSaisir = computed(fn () => auth()->user()?->hasAnyRole(['gerant', 'responsable_ville', 'caissier']) === true);

/**
 * Saisir un mouvement d'espèces — et où il faut qu'il aille.
 *
 * **La demande, du 29/09** : « mettre un bouton dans cette page caisse et permettre
 * d'ouvrir le formulaire et saisir […] où doit-on le mettre (caisse ou tréso ou les deux à
 * la fois) ; on doit avoir le bouton encaissement et décaissement ».
 *
 * **Le bouton est ici, et l'écriture va ailleurs.** Ce n'est pas une contradiction, c'est
 * la réponse à la question. Le geste appartient à la caisse — on compte des espèces, on
 * les note — mais l'écriture doit aller là où **tout le reste de l'application lit** :
 * `encaissements` pour une entrée, `charges` pour une sortie. C'est de là que se lisent la
 * trésorerie, la balance âgée, l'extrait de compte et le résultat.
 *
 * **Pourquoi pas dans `mouvements_caisse`.** Cette table porte le journal du logiciel, et
 * sa colonne « solde annoncé » forme une chaîne qui prouve qu'aucune ligne n'a été perdue
 * à l'import. Y écrire romprait cette preuve, et l'écriture resterait invisible partout
 * ailleurs — un mouvement d'argent que seul cet écran connaîtrait.
 *
 * Le mouvement paraît donc sur **les deux écrans** : ici sous « Saisi ici », et en
 * trésorerie parmi les encaissements ou les charges. C'est la réponse à « les deux doivent
 * communiquer ».
 *
 * **Le moyen est imposé à « ESPÈCES »**, et il n'est pas proposé : un chèque ou un virement
 * n'entre pas dans un tiroir. Se tromper de moyen ici ferait apparaître en caisse de
 * l'argent qui n'y est jamais passé.
 */
$ouvrirLaSaisie = function (string $sens) {
    if (! $this->peutSaisir) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: 'La saisie de caisse relève du gérant, du responsable de ville ou du comptable.');

        return;
    }

    $this->fill([
        'saisieOuverte' => true,
        'saisieSens' => $sens === MouvementCaisse::SORTIE ? MouvementCaisse::SORTIE : MouvementCaisse::ENTREE,
        'saisieDate' => $this->saisieDate ?: now()->toDateString(),
        'saisieVilleId' => $this->saisieVilleId ?: (string) ($this->villeFiltre ?: (auth()->user()->ville_id ?? '')),
    ]);
};

$fermerLaSaisie = function () {
    $this->fill(['saisieOuverte' => false, 'saisieMontant' => '', 'saisieLibelle' => '',
        'saisieTiers' => '', 'saisieReference' => '']);
};

$enregistrerLaSaisie = function () {
    if (! $this->peutSaisir) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: 'La saisie de caisse relève du gérant, du responsable de ville ou du comptable.');

        return;
    }

    $donnees = $this->validate([
        'saisieSens' => ['required', Rule::in([MouvementCaisse::ENTREE, MouvementCaisse::SORTIE])],
        'saisieDate' => ['required', 'date'],
        'saisieMontant' => ['required', 'numeric', 'min:1'],
        'saisieLibelle' => ['required', 'string', 'max:255'],
        'saisieTiers' => ['nullable', 'string', 'max:160'],
        'saisieReference' => ['nullable', 'string', 'max:120'],
        'saisieVilleId' => ['required', Rule::in(array_map('strval', $this->idsVilles))],
    ], [], [
        'saisieDate' => 'date', 'saisieMontant' => 'montant', 'saisieLibelle' => 'libellé',
        'saisieVilleId' => 'ville',
    ]);

    /*
     * L'atelier où ranger l'écriture. La caisse se tient par ville — le journal ne porte
     * pas l'atelier — mais `encaissements` et `charges` se lisent par atelier : la
     * trésorerie les retient par `whereIn('site_id', …)`, et un atelier nul n'entre dans
     * aucun `whereIn`. On descend donc à l'unique atelier de la ville quand il n'y en a
     * qu'un, sinon à celui de la personne qui saisit. C'est la même règle que pour un
     * encaissement du recouvrement.
     */
    $villeId = (int) $donnees['saisieVilleId'];
    $sites = Site::where('ville_id', $villeId)->where('est_actif', true)->pluck('id');

    $siteId = $sites->count() === 1
        ? (int) $sites->first()
        : (int) (auth()->user()->site_id ?: ($sites->first() ?? 0));

    if ($siteId === 0) {
        $this->addError('saisieVilleId', "Aucun atelier actif dans cette ville : l'écriture n'aurait nulle part où être rangée.");

        return;
    }

    $montant = (int) $donnees['saisieMontant'];
    $entree = $donnees['saisieSens'] === MouvementCaisse::ENTREE;

    if ($entree) {
        Encaissement::create([
            'entreprise_id' => auth()->user()->entreprise_id,
            'site_id' => $siteId,
            'date' => $donnees['saisieDate'],
            'type' => 'Caisse',
            // **Le moyen est imposé**, et il n'est pas proposé : une caisse tient des
            // espèces, un chèque n'entre pas dans un tiroir. Se tromper de moyen ici ferait
            // apparaître en caisse de l'argent qui n'y est jamais passé.
            'moyen' => 'ESPÈCES',
            'montant' => $montant,
            'client' => trim((string) $donnees['saisieTiers']) ?: null,
            'motif' => $donnees['saisieLibelle'],
            'reference_origine' => trim((string) $donnees['saisieReference']) ?: null,
            'cree_par' => auth()->id(),
        ]);
    } else {
        Charge::create([
            'entreprise_id' => auth()->user()->entreprise_id,
            'site_id' => $siteId,
            'date' => $donnees['saisieDate'],
            'type_operation' => 'Charges',
            'libelle' => $donnees['saisieLibelle'],
            'moyen' => 'ESPÈCES',
            'montant' => $montant,
            'tiers' => trim((string) $donnees['saisieTiers']) ?: null,
            'cree_par' => auth()->id(),
        ]);
    }

    activity()->causedBy(auth()->user())
        ->withProperties([
            'sens' => $entree ? 'entree' : 'sortie',
            'montant' => $montant,
            'ville_id' => $villeId,
            'libelle' => $donnees['saisieLibelle'],
        ])
        ->log('Caisse — mouvement saisi');

    $this->fermerLaSaisie();

    unset($this->saisiesEnEspeces, $this->mouvements, $this->detail, $this->kpis);

    $this->dispatch('annonce', ton: 'succes',
        texte: ($entree ? 'Entrée' : 'Sortie').' de '.ae($montant)
            .' enregistrée — elle paraît ici et en trésorerie.');
};



/**
 * Ce que l'application a enregistré en espèces, et que le journal du logiciel ne porte pas.
 *
 * **Le constat, mesuré le 28/09**, après la question du propriétaire — « est-ce que ces
 * deux pages communiquent ? » :
 *
 *   - `mouvements_caisse` : **1 155 lignes, toutes importées, aucune saisie** ;
 *   - `encaissements` : 7 714 lignes, dont **85 saisies dans l'application** ;
 *   - `charges` : 198 lignes, **toutes saisies dans l'application**.
 *
 * Les deux écrans ne communiquent donc pas, et la compréhension qu'on pouvait en avoir
 * était inverse : la **Caisse** ne porte que le journal du logiciel, elle ne regroupe rien ;
 * la **Trésorerie** ne regroupe pas tout — elle ignore complètement le journal de caisse.
 *
 * **Pourquoi on ne les fond pas dans un seul tableau.** La colonne « solde annoncé » du
 * journal forme une chaîne : chaque ligne porte le solde que le logiciel a imprimé après
 * elle, et c'est cette chaîne qui prouve qu'aucune ligne n'a été perdue à l'import. Y
 * insérer des écritures qui ne figurent pas dans le journal romprait la seule vérification
 * qu'on ait sur ce fichier.
 *
 * **Ce qu'on fait donc :** on les montre **à côté**, nommément, avec leur origine. Ce qui
 * manque au journal se lit alors d'un coup d'œil au lieu de se deviner en comparant deux
 * écrans.
 *
 * **La règle du « en espèces ».** Une caisse tient des espèces : on retient les écritures
 * dont le moyen les nomme. Un virement ou un chèque n'a rien à faire dans un journal de
 * caisse, et l'y faire paraître ferait douter du rapprochement au lieu de l'aider.
 */
$enEspeces = protect(fn (?string $moyen) => $moyen !== null
    // `CorrespondanceImport::normaliser()` et non un `strtolower` maison : c'est la
    // normalisation de la maison — majuscules, accents retirés, ponctuation ôtée — et deux
    // façons de comparer deux chaînes finissent toujours par se contredire.
    && str_contains(CorrespondanceImport::normaliser($moyen), 'ESPECE'));

/**
 * Les mouvements d'espèces saisis dans l'application, mis à la forme du journal.
 *
 * **Un seul tableau, et c'est la demande du 29/09** : « j'ai pas demandé de faire ce
 * tableau, les deux tableaux doivent rester en un, mais à travers le filtre on pourra
 * retirer ». Deux tableaux côte à côte obligent à lire deux fois et à rapprocher de tête
 * ce qui s'est passé dans la caisse ce jour-là.
 *
 * Ils sont donc ramenés à la **même forme** que les lignes du journal, et la colonne
 * « Origine » dit d'où chacune vient. Le filtre d'origine permet de n'en voir qu'une sorte.
 *
 * **Ce qu'on ne mélange pas pour autant : la chaîne des soldes annoncés.** Le journal
 * porte, ligne à ligne, le solde que le logiciel a imprimé après elle ; c'est cette chaîne
 * qui prouve qu'aucune ligne n'a été perdue à l'import. Une écriture saisie ici n'en a
 * pas — sa colonne « Solde » reste vide — et le rapprochement plus bas continue de ne
 * compter que le journal. Mélanger les deux ferait croire à un écart là où il n'y a qu'une
 * écriture que le logiciel ne connaît pas encore.
 */
$saisiesEnEspeces = computed(function () {
    [$debut, $fin] = $this->plage;
    $sites = PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, null);

    $entrees = Encaissement::query()
        ->whereIn('site_id', $sites)
        ->whereNull('lot_import_id')
        ->whereBetween('date', [$debut, $fin])
        ->with('site.ville')
        ->get()
        ->filter(fn ($e) => $this->enEspeces($e->moyen))
        ->map(fn ($e) => (object) [
            'cle' => 'enc-'.$e->id,
            'date' => $e->date,
            'sens' => MouvementCaisse::ENTREE,
            'numero_piece' => $e->numero,
            'type_piece' => $e->type,
            'page' => null,
            'motif' => $e->motif,
            // L'objet de l'entrée d'abord : c'est ce qu'on cherche en relisant une caisse.
            // Il est rangé dans `motif` à la saisie — `libelle` n'existe pas sur un
            // encaissement —, et le montrer sous le nom du remettant le perdait.
            'libelle' => $e->motif ?: ($e->client ?: ($e->type ?: 'Encaissement')),
            'tiers' => $e->client,
            'immatriculation' => $e->facture?->immatriculation,
            'caisse' => null,
            'ville' => $e->site?->ville,
            'montant' => (int) $e->montant,
            'solde_annonce' => null,
            'origine' => 'saisie',
            'lien' => route('tresorerie.encaissement', $e->id),
        ]);

    $sorties = Charge::query()
        ->whereIn('site_id', $sites)
        ->whereNull('lot_import_id')
        ->whereBetween('date', [$debut, $fin])
        ->with('site.ville')
        ->get()
        ->filter(fn ($c) => $this->enEspeces($c->moyen))
        ->map(fn ($c) => (object) [
            'cle' => 'dec-'.$c->id,
            'date' => $c->date,
            'sens' => MouvementCaisse::SORTIE,
            'numero_piece' => $c->numero,
            'type_piece' => $c->type_operation,
            'page' => null,
            'motif' => $c->motif,
            'libelle' => $c->libelle ?: ($c->type_operation ?: 'Charge'),
            'tiers' => $c->tiers,
            'immatriculation' => null,
            'caisse' => null,
            'ville' => $c->site?->ville,
            'montant' => (int) $c->montant,
            'solde_annonce' => null,
            'origine' => 'saisie',
            'lien' => null,
        ]);

    return $entrees->concat($sorties);
});

/**
 * Les mouvements du journal, mis à la même forme — pour qu'un seul tableau les rende tous.
 */
$mouvementsDuJournal = computed(fn () => (clone $this->requete)
    ->with('ville')
    ->get()
    ->map(fn (MouvementCaisse $m) => (object) [
        'cle' => 'jrn-'.$m->id,
        'date' => $m->date,
        'sens' => $m->sens,
        'numero_piece' => $m->numero_piece,
        'type_piece' => $m->type_piece,
        'page' => $m->page,
        'motif' => $m->motif,
        'libelle' => $m->libelle,
        'tiers' => $m->tiers(),
        'immatriculation' => $m->immatriculation,
        'caisse' => $m->caisse,
        'ville' => $m->ville,
        'montant' => (int) $m->montant,
        'solde_annonce' => $m->solde_annonce,
        'origine' => 'journal',
        'lien' => null,
    ]));

/**
 * Les deux sources réunies, filtrées, et rangées de la plus récente à la plus ancienne.
 *
 * Le filtre d'origine est appliqué ici et non dans une requête : les deux sources vivent
 * dans des tables différentes, et choisir « journal seulement » revient à ne pas lire la
 * seconde, pas à poser une condition.
 */
$mouvements = computed(function () {
    $lignes = match ($this->origineFiltre) {
        'journal' => $this->mouvementsDuJournal,
        'saisie' => $this->saisiesEnEspeces,
        default => $this->mouvementsDuJournal->concat($this->saisiesEnEspeces),
    };

    return $lignes
        ->sortByDesc(fn ($l) => [$l->date?->timestamp ?? 0, $l->cle])
        ->values();
});

?>

<div>
    <x-titre-ecran titre="Caisse"
        sous-titre="Les entrées et les sorties d'espèces, reprises des états de caisse de l'atelier.">
        {{-- L'autre question qu'on pose à la caisse, et qui ne se pose pas sur une
             période : celle d'un véhicule précis. --}}
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('caisse.vehicule') }}" wire:navigate class="bouton bouton-secondaire"
                style="padding:8px 14px; text-decoration:none;">Rechercher un véhicule</a>

            {{-- **Les deux gestes de la caisse, demandés le 29/09.** Le bouton est ici —
                 c'est là qu'on compte les espèces — et l'écriture va là où tout le reste de
                 l'application lit : les encaissements pour une entrée, les charges pour une
                 sortie. Le mouvement paraît donc sur les deux écrans, ce qui est la réponse
                 à « les deux doivent communiquer ». --}}
            @if ($this->peutSaisir)
                <button type="button" class="bouton" style="padding:8px 14px;"
                    wire:click="ouvrirLaSaisie('entree')">+ Encaissement</button>
                <button type="button" class="bouton bouton-secondaire"
                    style="padding:8px 14px; color:#C8102E; border-color:#C8102E;"
                    wire:click="ouvrirLaSaisie('sortie')">− Décaissement</button>
            @endif
        </div>
    </x-titre-ecran>

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="null" :site-filtre="null"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre" />

    {{-- Le formulaire, replié tant qu'on ne le demande pas. Rendu par le serveur et non
         ouvert par un aller-retour : ce qui s'ouvre par un clic n'a pas à faire un voyage. --}}
    @if ($saisieOuverte && $this->peutSaisir)
        <div class="carte" style="margin-bottom:16px; border-left:3px solid {{ $saisieSens === 'entree' ? '#0E9F6E' : '#C8102E' }};">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">
                {{ $saisieSens === 'entree' ? 'Entrée de caisse' : 'Sortie de caisse' }}
            </h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                En <b>espèces</b> — c'est ce qu'une caisse tient. L'écriture paraîtra ici sous
                « Saisi ici », et en <b>trésorerie</b> parmi
                {{ $saisieSens === 'entree' ? 'les encaissements' : 'les charges' }}.
            </p>

            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="Date" model="saisieDate" type="date" :requis="true" width="150" />
                <x-champ label="Montant (F CFA)" model="saisieMontant" type="number" :requis="true" width="160" />
                <x-champ :label="$saisieSens === 'entree' ? 'Objet de l’entrée' : 'Objet de la dépense'"
                    model="saisieLibelle" :requis="true" width="280" />
                <x-champ :label="$saisieSens === 'entree' ? 'Remettant' : 'Bénéficiaire'"
                    model="saisieTiers" width="220" />
                <x-champ label="Référence" model="saisieReference" width="170"
                    placeholder="Reçu, bordereau…" />
                @if (count($this->villesDeSaisie) > 1)
                    <x-champ label="Ville" model="saisieVilleId" type="select"
                        :options="$this->villesDeSaisie" :requis="true" width="170" />
                @endif
                <button type="button" wire:click="enregistrerLaSaisie" class="bouton">Enregistrer</button>
                <button type="button" wire:click="fermerLaSaisie" class="bouton bouton-secondaire">Annuler</button>
            </div>

            <x-erreurs-du-bloc prefixe="saisie" />
        </div>
    @endif

    {{-- **Ce que cette page lit, et ce qu'elle ne lit pas.**

         Question posée le 28/09 : « est-ce que ces deux pages communiquent ? ». Non, et la
         compréhension qu'on pouvait en avoir était l'inverse de la réalité. Mesuré le même
         jour, et c'est sans appel. --}}
    <div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
        <p style="margin:0 0 9px; font-size:13px; line-height:1.6;">
            <strong>Caisse et Trésorerie ne lisent pas la même chose, et ne se parlent pas.</strong>
        </p>
        <table class="tableau" style="font-size:12.5px; margin:0 0 9px; max-width:640px;">
            <thead>
                <tr><th>Écran</th><th>Ce qu'il lit</th><th style="text-align:right;">Lignes</th><th>Origine</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><b>Caisse</b> (cet écran)</td>
                    <td>le journal de caisse du logiciel</td>
                    <td style="text-align:right;">1 155</td>
                    <td>100&nbsp;% importées</td>
                </tr>
                <tr>
                    <td rowspan="2"><b>Trésorerie</b></td>
                    <td>les règlements clients</td>
                    <td style="text-align:right;">7 714</td>
                    <td>7 629 importées · 85 saisies</td>
                </tr>
                <tr>
                    <td>les charges</td>
                    <td style="text-align:right;">198</td>
                    <td>100&nbsp;% saisies ici</td>
                </tr>
            </tbody>
        </table>
        <p style="margin:0; font-size:13px; line-height:1.6;">
            La Caisse <b>ne regroupe donc pas</b> la trésorerie, et la Trésorerie
            <b>ne voit pas</b> le journal de caisse.
        </p>
        <p style="margin:9px 0 0; font-size:13px; line-height:1.6;">
            <strong>Ce que le tableau du bas rassemble, en revanche.</strong> Les mouvements du
            journal et ceux que vous saisissez ici — boutons <b>+ Encaissement</b> et
            <b>− Décaissement</b> en haut — sont dans un <b>seul tableau</b>, avec une colonne
            <b>Origine</b> et un filtre pour n'en voir qu'une sorte.
            Une écriture saisie ici part dans les <b>encaissements</b> ou les <b>charges</b> :
            elle paraît donc aussi en trésorerie. C'est par elle que les deux écrans communiquent.
        </p>
        <p style="margin:9px 0 0; font-size:13px; line-height:1.6;">
            <strong>Une seule chose n'est pas mélangée : le solde annoncé.</strong> Le journal le
            porte ligne à ligne, et cette chaîne prouve qu'aucune ligne n'a été perdue à l'import.
            Une écriture saisie ici n'en a pas — le logiciel ne la connaît pas encore — et sa case
            reste vide plutôt que de porter un nombre calculé qu'on prendrait pour une annonce.
        </p>
    </div>


    {{-- Le choix de la caisse ne s'affiche que là où il y en a plusieurs : ailleurs, une
         liste à un seul élément fait croire qu'il existe un second choix caché. --}}
    @if (count($this->caisses) > 1)
        <div style="display:flex; align-items:center; gap:9px; flex-wrap:wrap; margin-bottom:14px;">
            <span style="font-size:12.5px; color:#6B6E76;">Caisse</span>
            <select wire:model.live="caisseFiltre" class="champ" style="width:auto;">
                <option value="" @selected($caisseFiltre === '')>Toutes les caisses</option>
                @foreach ($this->caisses as $uneCaisse)
                    <option value="{{ $uneCaisse }}" @selected($caisseFiltre === $uneCaisse)>{{ $uneCaisse }}</option>
                @endforeach
            </select>
        </div>
    @endif

    {{-- Trois indicateurs, et un quatrième seulement quand le rapprochement a quelque
         chose à dire. La grille s'adapte d'elle-même au nombre de cartes. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:10px; margin-bottom:16px;">
        {{-- Ce que la caisse contenait avant le premier jour regardé. La phrase dit d'où
             vient le nombre : une annonce du fichier, ou notre seul cumul — les deux n'ont
             pas la même valeur, et les confondre serait présenter une reconstitution
             partielle comme un relevé. --}}
        <x-kpi-card label="Solde avant la période" :value="ae($this->soldeAvant['montant'])"
            :sub="$this->soldeAvant['phrase']" />

        <x-kpi-card label="Entrées — {{ $this->libellePerimetre }}" :value="ae($this->kpis['entrees'])"
            :sub="$this->kpis['lignes'].' mouvement(s) sur la période'" />
        <x-kpi-card label="Sorties" :value="ae($this->kpis['sorties'])" couleur="#C8102E" />

        {{-- « Mouvement net » et non « solde » : ce nombre dit de combien la caisse a varié
             pendant la période, pas ce qu'elle contient. C'est la confusion entre les deux
             qui faisait passer un fonds de caisse d'avant la période pour une anomalie. --}}
        <x-kpi-card label="Mouvement net de la période" :value="ae($this->kpis['solde'])"
            :couleur="$this->kpis['solde'] >= 0 ? '#0E9F6E' : '#C8102E'" sub="Entrées − sorties" />

        <x-kpi-card label="Solde à la fin de la période"
            :value="ae($this->soldeAvant['montant'] + $this->kpis['solde'])"
            sub="Solde d'avant, plus le mouvement net" />

        @if ($this->rapprochement)
            <x-kpi-card label="Écart avec le fichier"
                :value="ae(abs($this->rapprochement['ecart']))" :accent="true"
                :lignes="[
                    'Le fichier annonce' => ae($this->rapprochement['annonce']),
                    'Notre calcul donne' => ae($this->rapprochement['attendu']),
                ]"
                :sub="$this->rapprochement['phrase']" />
        @endif
    </div>

    @if ($this->grossesSorties->isNotEmpty())
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 12px;">Où part l'argent — huit premiers postes</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>{{ $this->colonnesDuFichier['motif'] ? 'Motif' : 'Libellé' }}</th>
                            <th style="text-align:right;">Mouvements</th>
                            <th style="text-align:right;">Total</th>
                            <th style="text-align:right;">Part des sorties</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->grossesSorties as $poste)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ $poste->poste ?: '—' }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $poste->nombre }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae((int) $poste->total) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">
                                    {{ $this->kpis['sorties'] > 0 ? round($poste->total / $this->kpis['sorties'] * 100) : 0 }} %
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
                Mouvements ({{ number_format($this->mouvements->count(), 0, ',', ' ') }})
            </h3>

            <div style="display:flex; gap:9px; flex-wrap:wrap;">
                <input type="search" wire:model.live.debounce.400ms="recherche" value="{{ $recherche }}"
                    placeholder="Libellé, bénéficiaire…" class="champ" style="width:auto; min-width:0; flex:0 1 230px;">

                <select wire:model.live="sensFiltre" class="champ">
                    <option value="" @selected($sensFiltre === '')>Entrées et sorties</option>
                    <option value="entree" @selected($sensFiltre === 'entree')>Entrées seulement</option>
                    <option value="sortie" @selected($sensFiltre === 'sortie')>Sorties seulement</option>
                </select>

                {{-- **Un seul tableau, et ce filtre pour n'en voir qu'une sorte.** Demandé
                     le 29/09 : « les deux tableaux doivent rester en un, mais à travers le
                     filtre on pourra retirer ». C'est un filtre de l'écran et non un
                     « autre filtre » : les deux sources vivent dans des tables différentes,
                     et choisir revient à ne pas lire l'une, pas à poser une condition. --}}
                <select wire:model.live="origineFiltre" class="champ">
                    <option value="" @selected($origineFiltre === '')>
                        Journal et saisies ({{ $this->mouvementsDuJournal->count() + $this->saisiesEnEspeces->count() }})
                    </option>
                    <option value="journal" @selected($origineFiltre === 'journal')>
                        Journal du logiciel ({{ $this->mouvementsDuJournal->count() }})
                    </option>
                    <option value="saisie" @selected($origineFiltre === 'saisie')>
                        Saisi ici ({{ $this->saisiesEnEspeces->count() }})
                    </option>
                </select>

                {{-- Les colonnes du journal qu'aucun filtre ne couvre : la caisse, le type de
                     pièce, le rôle du tiers, le montant, la date. Demandé le 28/09. --}}
                <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
            </div>
        </div>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Date</th>
                        @if ($this->colonnesDuFichier['piece'])
                            <th>N° de pièce</th>
                        @endif
                        <th>Sens</th>
                        @if ($this->colonnesDuFichier['motif'])
                            <th>Motif</th>
                        @endif
                        <th>Libellé</th>
                        <th>Remettant / bénéficiaire</th>
                        <th>Immatriculation</th>
                        @if ($this->colonnesDuFichier['caisse'])
                            <th>Caisse</th>
                        @endif
                        <th>Ville</th>
                        {{-- D'où vient la ligne. Les deux sources sont dans le même tableau
                             depuis le 29/09 : deux tableaux côte à côte obligeaient à lire
                             deux fois et à rapprocher de tête ce qui s'est passé ce jour-là. --}}
                        <th>Origine</th>
                        <th style="text-align:right;">Montant</th>
                        {{-- Le solde que le fichier affiche après cette ligne. Recopié, jamais
                             recalculé : c'est ce que la caisse déclarait à cet instant. Une
                             écriture saisie ici n'en a pas — le logiciel ne la connaît pas
                             encore — et sa case reste vide plutôt que de porter un nombre
                             calculé qu'on prendrait pour une annonce. --}}
                        <th class="colonne-collee" style="text-align:right;">Solde annoncé</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->detail as $ligne)
                        <tr wire:key="{{ $ligne->cle }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            @if ($this->colonnesDuFichier['piece'])
                                <td style="white-space:nowrap; font-variant-numeric:tabular-nums;"
                                    title="{{ $ligne->type_piece ? $ligne->type_piece.' — page '.$ligne->page : '' }}">
                                    {{ $ligne->numero_piece ?: '—' }}
                                </td>
                            @endif
                            <td>
                                <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600;
                                    background:{{ $ligne->sens === 'entree' ? '#E5F2E8' : '#FCF0F2' }};
                                    color:{{ $ligne->sens === 'entree' ? '#1E7B34' : '#C8102E' }};">
                                    {{ $ligne->sens === 'entree' ? 'Entrée' : 'Sortie' }}
                                </span>
                            </td>
                            @if ($this->colonnesDuFichier['motif'])
                                <td style="color:#4B4E55;">{{ $ligne->motif ?: '—' }}</td>
                            @endif
                            <td>{{ $ligne->libelle ?: '—' }}</td>
                            <td style="color:#6B6E76;">{{ $ligne->tiers ?: '—' }}</td>
                            <td style="color:#6B6E76;">
                                @if ($ligne->immatriculation)
                                    {{-- La plaque mène au dossier du véhicule : c'est le geste
                                         qu'on fait de toute façon, en la recopiant à la main. --}}
                                    <a href="{{ route('caisse.vehicule', ['plaque' => $ligne->immatriculation]) }}"
                                        wire:navigate style="color:#191B20; font-weight:600;">{{ $ligne->immatriculation }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            @if ($this->colonnesDuFichier['caisse'])
                                <td style="color:#6B6E76;">{{ $ligne->caisse ?: '—' }}</td>
                            @endif
                            <td style="color:#6B6E76;">{{ $ligne->ville?->nom ?? '—' }}</td>
                            <td style="white-space:nowrap; font-size:12px;">
                                @if ($ligne->origine === 'saisie')
                                    @if ($ligne->lien)
                                        <a href="{{ $ligne->lien }}" wire:navigate
                                           style="color:#2563EB; font-weight:600;">Saisi ici</a>
                                    @else
                                        <span style="color:#2563EB; font-weight:600;">Saisi ici</span>
                                    @endif
                                @else
                                    <span style="color:#6B6E76;">Journal</span>
                                @endif
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $ligne->sens === 'entree' ? '#1E7B34' : '#C8102E' }};">
                                {{ ae($ligne->montant) }}
                            </td>
                            <td class="colonne-collee"
                                style="text-align:right; font-variant-numeric:tabular-nums; color:#4B4E55;">
                                {{ $ligne->solde_annonce === null ? '—' : ae($ligne->solde_annonce) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="9 + count(array_filter($this->colonnesDuFichier))"
                            texte="Aucun mouvement de caisse sur cette période. Les états de caisse se déposent depuis le module Import, et les mouvements du jour se saisissent avec les boutons du haut." />
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Paginé par `$set` et non par un lien : le paginateur d'Eloquent rend de vraies
             ancres `?pageDetail=2`, qui rechargent la page et la ramènent en haut — on
             perdait la ligne qu'on était en train de lire à chaque page tournée. --}}
        <x-pagination :page="$pageDetail" :total="$this->mouvements->count()"
            prop="pageDetail" :par-page="25" />
    </div>
</div>

<?php

use App\Models\User;
use Modules\Noyau\Entreprises\Actions\PurgeParModule;
use Modules\Noyau\Entreprises\Actions\PurgerDonneesEntreprise;
use Modules\Noyau\Entreprises\Actions\SupprimerEntreprise;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Imports\Modeles\LotImport;
use Illuminate\Support\Facades\DB;
use App\Support\JournalDesIncidents;

use function Livewire\Volt\{boot, computed, protect, state};

/*
|--------------------------------------------------------------------------
| Maintenance : un tableau d'entrée, puis un thème à la fois — 25/11
|--------------------------------------------------------------------------
| « Ça ne présente pas les éléments clairement, on peut se perdre à l'intérieur. » La page
| empilait sept sections — rôles, habilitations, pannes, trois gestes d'effacement — sur
| plusieurs écrans de hauteur, sans sommaire. Elle s'ouvre désormais sur cinq cartes, et
| chaque thème s'affiche seul.
|
| **Le thème est dans l'adresse** (`?theme=…`, et `&geste=…` pour l'effacement) : un lien se
| partage, le retour du navigateur ramène au thème quitté, et la page se lit sans JavaScript.
| Les actions, leurs noms et leurs contrôles sont ceux d'avant : seul l'affichage a changé.
|
| Effacer : deux gestes de portée très différente, séparés à dessein.
|
| La purge vide les écritures et laisse l'entreprise debout : c'est ce qu'on
| fait après une période d'essai, quand la structure est bonne mais que les
| chiffres ne le sont pas.
|
| La suppression, elle, ne laisse rien. Les deux ont leur propre confirmation
| par le nom, et leur propre bouton : un seul formulaire pour les deux aurait
| tôt ou tard fait effacer une entreprise qu'on voulait seulement vider.
*/

state([
    'entrepriseId' => '',
    'purgerCommerciaux' => false,
    'purgerAcces' => false,
    'purgerReglagesImport' => false,
    'confirmation' => '',
    'resultat' => null,

    // Suppression définitive : son propre identifiant et sa propre confirmation,
    // pour qu'aucun champ ne soit partagé avec la purge.
    'suppressionId' => '',
    'confirmationSuppression' => '',
    'resultatSuppression' => null,

    /*
     * **Vider ce qu'on désigne — demandé le 01/10.**
     *
     * « Fais des cases à cocher des pages ayant des données, et dès que les pages seront
     * cochées et supprimées, les données seront supprimées. » Son propre identifiant
     * d'entreprise et sa propre confirmation : aucun champ n'est partagé avec les deux
     * autres gestes, parce qu'un champ partagé finit par faire déclencher l'un pour l'autre.
     */
    'choixId' => '',
    'lotsChoisis' => [],
    /*
     * **Les volumes sont retenus, et non recomptés à chaque clic.**
     *
     * Relevé le 02/10 : « les boutons tout cocher sont lents ». Ils l'étaient, et chaque case
     * aussi. Les volumes étaient un `computed`, donc refaits à chaque aller-retour Livewire —
     * **vingt et un `count(*)`** sur des tables qui portent onze mille factures et sept mille
     * encaissements, pour une case qu'on vient de cocher.
     *
     * Ils ne changent qu'avec l'entreprise regardée, et c'est là qu'on les relève. Le geste
     * qui supprime, lui, recompte en base : voir `$viderLesChoisis`.
     */
    'volumesLots' => [],
    /*
     * **Les dépôts un par un — demandé le 07/10.** « Propose-moi ce qu'il y a à supprimer :
     * que je coche ce que je veux, ou tout. » La carte « Dépôts de fichiers » n'avait qu'une
     * case, qui effaçait tous les dépôts d'un coup. La liste est relevée au choix de
     * l'entreprise, comme les volumes, et relue en base au moment d'effacer.
     */
    'depots' => [],
    'depotsChoisis' => [],
    // Les relevés bancaires, banque par banque — 27/10 : « fais des sélections comme pour les fichiers ».
    'relevesBanques' => [],
    'banquesChoisies' => [],
    'confirmationChoix' => '',
    'resultatChoix' => null,
]);

// Le thème ouvert, et dans « Effacer des données » le geste choisi. Lus en liste blanche plus
// bas : une valeur inventée dans l'adresse ramène au tableau d'entrée, elle n'ouvre rien.
state(['theme' => ''])->url(except: '');
state(['geste' => ''])->url(except: '');

/*
 * **Le contrôle d'accès est refait à chaque requête, pas seulement à la route.** Le middleware
 * `habilitation:maintenance` ne garde que l'ouverture de la page : les clics suivants passent
 * par `/livewire/update`, où il ne joue pas. Les deux sections déjà découpées
 * (`roles-configures`, `habilitations`) se gardaient elles-mêmes ; les purges et la
 * suppression d'entreprise, les gestes les plus graves de la plateforme, ne le faisaient pas.
 */
$garder = protect(function () {
    abort_unless(auth()->user()?->peutAccederA('maintenance'), 403, 'Cette section ne vous est pas ouverte.');
});

boot(fn () => $this->garder());

/** Les cinq thèmes du tableau d'entrée, dans l'ordre où on les cherche. */
$themes = computed(fn () => [
    'pannes' => [
        'titre' => 'Pannes et santé',
        'texte' => 'Retrouver une erreur par sa référence ERR-…, voir les pannes encore ouvertes.',
    ],
    'roles' => [
        'titre' => 'Rôles et pages',
        'texte' => 'Créer ou modifier un rôle : son nom, les pages qu’il voit.',
    ],
    'habilitations' => [
        'titre' => 'Habilitations',
        'texte' => 'Donner ou retirer « modifier » et « supprimer » à un rôle ou à un compte.',
    ],
    'liaisons' => [
        'titre' => 'COMPTAFLOW et IA',
        'texte' => 'Relier une entreprise à son dossier COMPTAFLOW ; régler les IA.',
    ],
    'donnees' => [
        'titre' => 'Effacer des données',
        'texte' => 'Vider un import, toute une entreprise, ou la supprimer. Irréversible.',
        'danger' => true,
    ],
]);

$themeOuvert = computed(fn () => array_key_exists((string) $this->theme, $this->themes) ? (string) $this->theme : '');

/** Les trois gestes d'effacement, du plus étroit au plus large : on choisit avant de voir le formulaire. */
$gestes = computed(fn () => [
    'partie' => [
        'titre' => 'Vider une partie',
        'texte' => 'Cocher ce qui part — un dépôt, un relevé, un ensemble. Le reste ne bouge pas.',
    ],
    'tout' => [
        'titre' => 'Vider toute une entreprise',
        'texte' => 'Toutes ses écritures et tous ses imports. L’entreprise, ses lieux et ses accès restent.',
    ],
    'supprimer' => [
        'titre' => 'Supprimer une entreprise',
        'texte' => 'Tout disparaît, accès compris. Ne se rattrape pas.',
    ],
]);

$gesteOuvert = computed(fn () => array_key_exists((string) $this->geste, $this->gestes) ? (string) $this->geste : '');

$entreprises =computed(fn () => Entreprise::orderBy('nom')->get());

$cible = computed(fn () => $this->entrepriseId ? Entreprise::find($this->entrepriseId) : null);

$cibleSuppression = computed(fn () => $this->suppressionId ? Entreprise::find($this->suppressionId) : null);

$volumes = computed(function () {
    if (! $this->entrepriseId) {
        return null;
    }

    $id = $this->entrepriseId;

    return [
        'Prospections' => Prospection::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Devis' => Devis::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Factures' => Facture::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Encaissements' => Encaissement::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Charges' => Charge::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Fiches de réception' => DB::table('dossiers_vehicules')->where('entreprise_id', $id)->count(),
        'Mouvements de caisse' => DB::table('mouvements_caisse')->where('entreprise_id', $id)->count(),
        'Factures fournisseurs' => DB::table('factures_fournisseurs')->where('entreprise_id', $id)->count(),
        'Entrées et sorties' => DB::table('mouvements_vehicules')->where('entreprise_id', $id)->count(),
        'Relances' => DB::table('relances_recouvrement')->where('entreprise_id', $id)->count(),
        'Dépôts de fichiers' => DB::table('lots_import')->where('entreprise_id', $id)->count(),
        'Fichiers sur le disque' => LotImport::nombreDeFichiersDe((int) $id),
    ];
});

/** Ce que la suppression emporterait : on le montre avant, pas après. */
$portee = computed(function () {
    if (! $this->suppressionId) {
        return null;
    }

    $id = $this->suppressionId;

    return [
        'Villes' => Ville::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Lieux' => Site::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Accès' => User::withoutGlobalScopes()->where('entreprise_id', $id)->count(),
        'Écritures' => Prospection::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Devis::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Facture::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Encaissement::withoutGlobalScopes()->where('entreprise_id', $id)->count()
            + Charge::withoutGlobalScopes()->where('entreprise_id', $id)->count(),

        // Les lignes venues des imports sont comptées à part : on ne les mélange pas aux
        // écritures, parce que ce ne sont pas les mêmes gestes qui les ont produites.
        'Lignes importées' => DB::table('dossiers_vehicules')->where('entreprise_id', $id)->count()
            + DB::table('mouvements_caisse')->where('entreprise_id', $id)->count()
            + DB::table('mouvements_vehicules')->where('entreprise_id', $id)->count()
            + DB::table('factures_fournisseurs')->where('entreprise_id', $id)->count(),

        'Fichiers déposés' => LotImport::nombreDeFichiersDe((int) $id),
    ];
});

$cibleChoix = computed(fn () => $this->choixId ? Entreprise::find($this->choixId) : null);

/** Ce que chaque ensemble porte — relevé au choix de l'entreprise, puis simplement relu. */
$volumesParLot = computed(fn () => $this->volumesLots);

/**
 * Les ensembles réellement cochés, ramenés à ceux qui existent et qui portent quelque chose.
 *
 * **Un ensemble vide est écarté ici**, et pas seulement grisé à l'écran : une case cochée
 * puis vidée par un autre geste ferait annoncer une suppression qui ne supprimerait rien, et
 * le bilan dirait « 0 » là où l'on attendait un chiffre.
 */
/**
 * Les dépôts cochés un à un — sans objet quand la case « tous » est cochée, qui les emporte.
 *
 * Bornés à la liste relevée pour cette entreprise : un identifiant qui n'y est pas ne
 * désigne rien ici, et le service le relit de toute façon en base.
 */
$depotsRetenus = computed(function () {
    if (in_array('lots', $this->lotsRetenus, true)) {
        return [];
    }

    $connus = collect($this->depots)->pluck('id')->all();

    return collect($this->depotsChoisis)
        ->filter(fn ($coche, $id) => $coche && in_array((int) $id, $connus, true))
        ->keys()->map(fn ($id) => (int) $id)->values()->all();
});

/** Les banques cochées une à une — sans objet quand « Relevés bancaires » entier est coché. */
$banquesRetenues = computed(function () {
    if (in_array('mouvements_bancaires', $this->lotsRetenus, true)) {
        return [];
    }

    $connues = collect($this->relevesBanques)->pluck('id')->all();

    return collect($this->banquesChoisies)
        ->filter(fn ($coche, $id) => $coche && in_array((int) $id, $connues, true))
        ->keys()->map(fn ($id) => (int) $id)->values()->all();
});

$lotsRetenus = computed(function () {
    $volumes = $this->volumesParLot;

    return collect($this->lotsChoisis)
        ->filter(fn ($coche, $cle) => $coche && ($volumes[$cle] ?? 0) > 0)
        ->keys()
        ->all();
});

/**
 * Ce que la boîte de confirmation dira, mot pour mot.
 *
 * **Elle nomme les lignes et les entraînements.** Le propriétaire l'a demandé — « dire ce
 * qui sera vraiment supprimé » —, et c'est la seule protection qui vaille : un écran qui
 * demande « êtes-vous sûr ? » sans dire de quoi ne protège de rien.
 */
$recapitulatif = computed(function () {
    $lots = PurgeParModule::lots();
    $volumes = $this->volumesParLot;
    $lignes = [];
    $entraines = [];

    foreach ($this->lotsRetenus as $cle) {
        $lignes[] = $lots[$cle]['libelle'].' : '.number_format($volumes[$cle], 0, ',', ' ').' ligne(s)';

        if (isset($lots[$cle]['entraine'])) {
            $entraines[] = $lots[$cle]['entraine'];
        }
    }

    $total = array_sum(array_map(fn ($cle) => $volumes[$cle] ?? 0, $this->lotsRetenus));

    $depots = collect($this->depots)->whereIn('id', $this->depotsRetenus);

    foreach ($depots as $depot) {
        $lignes[] = 'Dépôt « '.$depot['fichier'].' » ('.$depot['format'].')'
            .($depot['rejets'] + $depot['corrections'] > 0 ? ', avec '.($depot['rejets'] + $depot['corrections']).' rejet(s) et correction(s)' : '');
        $total += 1 + $depot['rejets'] + $depot['corrections'];
    }

    if ($depots->isNotEmpty() && isset($lots['lots']['entraine'])) {
        $entraines[] = $lots['lots']['entraine'];
    }

    foreach (collect($this->relevesBanques)->whereIn('id', $this->banquesRetenues) as $banque) {
        $lignes[] = 'Relevé bancaire '.$banque['nom'].' : '.number_format($banque['operations'], 0, ',', ' ').' opération(s)';
        $total += $banque['operations'];
    }

    return ['lignes' => $lignes, 'entraines' => array_values(array_unique($entraines)), 'total' => $total];
});

/** Cocher ou décocher un module entier — « un bouton tout cocher au niveau de chaque module ». */
$basculerLeModule = function (string $module) {
    $lots = PurgeParModule::MODULES[$module]['lots'] ?? [];
    $volumes = $this->volumesParLot;

    // Tout cocher si l'un au moins manque ; tout décocher si l'on est déjà complet. C'est
    // ce qu'un seul bouton peut faire sans qu'on se demande dans quel sens il agit.
    $aCompleter = collect($lots)->contains(
        fn ($lot, $cle) => ($volumes[$cle] ?? 0) > 0 && empty($this->lotsChoisis[$cle]),
    );

    foreach ($lots as $cle => $lot) {
        if (($volumes[$cle] ?? 0) > 0) {
            $this->lotsChoisis[$cle] = $aCompleter;
        }
    }
};

$updatedChoixId = function () {
    // Changer d'entreprise remet les cases à zéro : des cases cochées pour une entreprise
    // n'ont aucun sens pour une autre, et les garder ferait supprimer ailleurs.
    $this->lotsChoisis = [];
    $this->depotsChoisis = [];
    $this->banquesChoisies = [];
    $this->confirmationChoix = '';
    $this->resultatChoix = null;
    $this->depots = $this->choixId ? PurgeParModule::depots((int) $this->choixId) : [];
    $this->relevesBanques = $this->choixId ? PurgeParModule::relevesParBanque((int) $this->choixId) : [];

    // Les vingt et un comptages se font ici, et seulement ici : changer d'entreprise est le
    // seul geste de cet écran qui change ce qu'il y a à compter.
    $this->volumesLots = $this->choixId ? PurgeParModule::volumes((int) $this->choixId) : [];

    unset($this->volumesParLot);
};

/**
 * Vide les ensembles cochés.
 *
 * **Le nom de l'entreprise doit être retapé**, comme pour les deux autres gestes. Ce n'est
 * pas une formalité : c'est le seul moment où la personne écrit elle-même ce qu'elle vise,
 * et c'est ce qui distingue un clic d'une décision.
 */
$viderLesChoisis = function (PurgeParModule $action) {
    // Redit ici, en plus de `boot` : c'est le geste qui efface, il ne dépend d'aucun autre.
    $this->garder();

    $this->validate([
        'choixId' => ['required', 'exists:entreprises,id'],
    ], [], ['choixId' => 'entreprise']);

    $cible = $this->cibleChoix;

    /*
     * **Les volumes se relèvent ici, en base, et non tels qu'ils nous reviennent.**
     *
     * Depuis le 02/10 ils sont retenus dans une propriété plutôt que recomptés à chaque clic
     * — c'est ce qui a rendu les cases instantanées. Mais une propriété Livewire fait
     * l'aller-retour par le navigateur : un chiffre reçu ne commande rien, et le geste qui
     * supprime recompte avant de décider ce qu'il retient.
     */
    $this->volumesLots = PurgeParModule::volumes((int) $this->choixId);
    $this->depots = PurgeParModule::depots((int) $this->choixId);
    $this->relevesBanques = PurgeParModule::relevesParBanque((int) $this->choixId);
    unset($this->volumesParLot, $this->lotsRetenus, $this->depotsRetenus, $this->banquesRetenues, $this->recapitulatif);

    if ($this->lotsRetenus === [] && $this->depotsRetenus === [] && $this->banquesRetenues === []) {
        $this->addError('lotsChoisis', 'Cochez au moins un ensemble, un dépôt ou une banque qui porte des données.');

        return;
    }

    if (trim($this->confirmationChoix) !== $cible->nom) {
        $this->addError('confirmationChoix', "Saisissez exactement « {$cible->nom} » pour confirmer.");

        return;
    }

    $this->resultatChoix = array_merge(
        $this->lotsRetenus !== [] ? $action->executer($cible, $this->lotsRetenus) : [],
        $this->depotsRetenus !== [] ? $action->executerDepots($cible, $this->depotsRetenus) : [],
        $this->banquesRetenues !== [] ? $action->executerReleves($cible, $this->banquesRetenues) : [],
    );

    activity()
        ->causedBy(auth()->user())
        ->performedOn($cible)
        ->withProperties($this->resultatChoix)
        ->log('Purge sélective des données');

    $this->reset(['lotsChoisis', 'depotsChoisis', 'banquesChoisies', 'confirmationChoix']);

    // Ce qui vient de partir n'est plus à compter : on relève de nouveau, une fois.
    $this->volumesLots = PurgeParModule::volumes((int) $this->choixId);
    $this->depots = PurgeParModule::depots((int) $this->choixId);
    $this->relevesBanques = PurgeParModule::relevesParBanque((int) $this->choixId);
    unset($this->depotsRetenus, $this->banquesRetenues, $this->recapitulatif);

    unset($this->volumesParLot, $this->volumes);
};

$purger = function (PurgerDonneesEntreprise $action) {
    $this->garder();

    $this->validate([
        'entrepriseId' => ['required', 'exists:entreprises,id'],
    ], [], ['entrepriseId' => 'entreprise']);

    // Garde-fou : le nom exact de l'entreprise doit être retapé.
    if (trim($this->confirmation) !== $this->cible->nom) {
        $this->addError('confirmation', "Saisissez exactement « {$this->cible->nom} » pour confirmer la purge.");

        return;
    }

    $this->resultat = $action->executer(
        $this->cible, $this->purgerCommerciaux, $this->purgerAcces, $this->purgerReglagesImport,
    );

    activity()
        ->causedBy(auth()->user())
        ->performedOn($this->cible)
        ->withProperties($this->resultat)
        ->log("Purge des données d'exploitation");

    $this->reset(['confirmation', 'purgerCommerciaux', 'purgerAcces', 'purgerReglagesImport']);
    unset($this->volumes);
};

$supprimer = function (SupprimerEntreprise $action) {
    $this->garder();

    $this->validate([
        'suppressionId' => ['required', 'exists:entreprises,id'],
    ], [], ['suppressionId' => 'entreprise']);

    $entreprise = $this->cibleSuppression;

    if (trim($this->confirmationSuppression) !== $entreprise->nom) {
        $this->addError('confirmationSuppression', "Saisissez exactement « {$entreprise->nom} » pour confirmer la suppression.");

        return;
    }

    // Personne ne se supprime le sol sous les pieds : un super administrateur
    // rattaché à cette entreprise perdrait sa propre session en cours de route.
    if (auth()->user()->entreprise_id === $entreprise->id) {
        $this->addError('confirmationSuppression', "Vous appartenez à cette entreprise : vous ne pouvez pas la supprimer.");

        return;
    }

    $nom = $entreprise->nom;

    // Le journal est écrit avant : la ligne de l'entreprise ne sera plus là après,
    // et une trace qui disparaît avec son sujet ne prouve plus rien.
    activity()
        ->causedBy(auth()->user())
        ->withProperties(['entreprise' => $nom, 'id' => $entreprise->id, 'portee' => $this->portee])
        ->log("Suppression définitive de l'entreprise « $nom »");

    $this->resultatSuppression = ['nom' => $nom, 'bilan' => $action->executer($entreprise)];

    $this->reset(['suppressionId', 'confirmationSuppression']);
    unset($this->entreprises, $this->portee, $this->volumes);
};

// ------------------------------------------------------------------ les incidents — 09/10

/*
 * « Dans la page maintenance, une section avec un champ : dès que je dépose le code, je dois
 * voir l'erreur ; et tous les codes qui surviennent, listés et cliquables. » La liste lit la
 * table `incidents` et le journal du serveur : un incident d'avant la table s'y trouve aussi.
 */
state(['referenceCherchee' => '']);

$incidentsRecents = computed(fn () => JournalDesIncidents::recents(50));

$migrationsEnAttente = computed(fn () => JournalDesIncidents::migrationsEnAttente());

$ouvrirLIncident = function () {
    $reference = strtoupper(trim((string) $this->referenceCherchee));

    // On accepte « XEYTWV » comme « ERR-XEYTWV » : on recopie souvent sans le préfixe.
    if ($reference !== '' && ! str_starts_with($reference, 'ERR-')) {
        $reference = 'ERR-'.$reference;
    }

    if ($reference === '') {
        $this->addError('referenceCherchee', 'Tapez la référence lue sur la page de panne — par exemple ERR-XEYTWV.');

        return;
    }

    if (preg_match('/^ERR-[A-Z0-9]{4,12}$/', $reference) !== 1) {
        $this->addError('referenceCherchee', 'Une référence s’écrit ERR- suivi de six caractères, par exemple ERR-XEYTWV.');

        return;
    }

    $this->redirectRoute('super-admin.incident', ['reference' => $reference], navigate: true);
};

/*
 * « Corrigé » : la panne quitte la liste, avec toutes ses répétitions. Si elle revient après,
 * elle reparaît — voir `JournalDesIncidents::declarerRegle()`.
 */
$declarerRegle = function (string $reference) {
    JournalDesIncidents::declarerRegle($reference, auth()->id());
    unset($this->incidentsRecents);
    $this->dispatch('annonce', ton: 'succes', texte: 'Panne déclarée réglée : elle quitte la liste. Si elle revient, elle reparaîtra.');
};

?>

<div>
    {{-- Les règles sont posées par classes, jamais en `style=""` sur les éléments qu'elles visent :
         un style en ligne bat la feuille (piège du `<nav>`). Aucun `display:none` dans les grilles :
         ce qui n'est pas montré n'est pas rendu (`@if`). --}}
    <style>
        .maint-tuiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .maint-tuile { display: block; background: #fff; border: 1px solid var(--th-ligne, #E2E0D8);
                       border-left: 4px solid var(--th-steel, #2A2E35); border-radius: 10px; padding: 14px 16px;
                       color: inherit; text-decoration: none; }
        .maint-tuile:hover, .maint-tuile:focus-visible { border-color: var(--th-steel, #2A2E35); box-shadow: 0 4px 14px rgba(25, 27, 32, .08); }
        .maint-tuile.est-danger { border-left-color: var(--th-accent, #C8102E); }
        .maint-tuile-titre { display: block; font-weight: 800; font-size: 15px; }
        .maint-tuile.est-danger .maint-tuile-titre { color: var(--th-accent, #C8102E); }
        .maint-tuile-texte { display: block; font-size: 13px; color: #6B6E76; margin-top: 4px; line-height: 1.45; }
        .maint-tuile-etat { display: block; font-size: 12.5px; font-weight: 700; margin-top: 8px; }
        .maint-fil { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 16px; }
        .maint-puce { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 600;
                      border: 1px solid var(--th-ligne, #E2E0D8); background: #fff; color: inherit; text-decoration: none; }
        .maint-puce.est-ouvert { background: var(--th-steel, #2A2E35); border-color: var(--th-steel, #2A2E35); color: #fff; }
        .maint-puce.est-danger { color: var(--th-accent, #C8102E); }
        .maint-puce.est-danger.est-ouvert { background: var(--th-accent, #C8102E); border-color: var(--th-accent, #C8102E); color: #fff; }
        .maint-retour { font-weight: 700; margin-right: 6px; color: inherit; }
        .maint-aide { margin: 0 0 12px; font-size: 13px; color: #6B6E76; line-height: 1.5; }
        .maint-comptes { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 16px;
                         list-style: none; margin: 14px 0; padding: 0; font-size: 13px; }
        .maint-comptes li { display: flex; justify-content: space-between; gap: 8px; border-bottom: 1px dotted var(--th-ligne, #E2E0D8); padding: 3px 0; }
        .maint-comptes b { font-variant-numeric: tabular-nums; }
        .maint-option { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; margin-bottom: 10px; }
        .maint-option small { display: block; font-size: 12px; color: #6B6E76; margin-top: 2px; }
    </style>

    <x-titre-ecran titre="Maintenance"
        sous-titre="Choisissez un thème : il s'ouvre seul. Le retour ramène à ce tableau." />

    {{-- Une migration en attente fait tomber des pages : dit en tête de chaque thème, et non au
         fond de l'un d'eux, parce que c'est la première chose à régler. --}}
    @if ($this->migrationsEnAttente !== [])
        <div class="encart encart-alerte">
            <b>{{ count($this->migrationsEnAttente) }} migration(s) en attente sur ce serveur.</b>
            Les pages qui en dépendent tombent en panne : lancer <code>php artisan app:deployer</code>.
            <span style="font-size:12px;">({{ implode(', ', $this->migrationsEnAttente) }})</span>
        </div>
    @endif

    @php $ouvert = $this->themeOuvert; @endphp

    @if ($ouvert === '')
        {{-- ═══════════════════════════════ le tableau d'entrée --}}
        @php $pannes = $this->incidentsRecents; @endphp

        <div class="maint-tuiles">
            @foreach ($this->themes as $cle => $theme)
                <a href="{{ route('super-admin.maintenance', ['theme' => $cle]) }}" wire:navigate
                   class="maint-tuile {{ ! empty($theme['danger']) ? 'est-danger' : '' }}">
                    <span class="maint-tuile-titre">{{ $theme['titre'] }}</span>
                    <span class="maint-tuile-texte">{{ $theme['texte'] }}</span>
                    @if ($cle === 'pannes')
                        <span class="maint-tuile-etat" style="color:{{ count($pannes) > 0 ? '#C8102E' : '#0E9F6E' }};">
                            {{ count($pannes) > 0 ? count($pannes).' panne(s) ouverte(s)' : 'Aucune panne ouverte' }}
                        </span>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- Les trois dernières pannes, cliquables d'ici : quand quelqu'un appelle, c'est souvent
             la plus récente. Hors des cartes, parce qu'un lien ne se pose pas dans un lien. --}}
        @if (count($pannes) > 0)
            <p class="maint-aide">
                Dernières pannes :
                @foreach (collect($pannes)->take(3) as $incident)
                    <a href="{{ route('super-admin.incident', $incident['reference']) }}" wire:navigate
                       style="font-weight:700; color:var(--th-accent,#C8102E);">{{ $incident['reference'] }}</a>@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif
    @else
        {{-- ═══════════════════════════════ un thème ouvert : le fil pour passer à un autre --}}
        <nav class="maint-fil" aria-label="Thèmes de la maintenance">
            <a href="{{ route('super-admin.maintenance') }}" wire:navigate class="maint-retour">← Tous les thèmes</a>
            @foreach ($this->themes as $cle => $theme)
                <a href="{{ route('super-admin.maintenance', ['theme' => $cle]) }}" wire:navigate
                   class="maint-puce {{ $cle === $ouvert ? 'est-ouvert' : '' }} {{ ! empty($theme['danger']) ? 'est-danger' : '' }}"
                   @if ($cle === $ouvert) aria-current="page" @endif>{{ $theme['titre'] }}</a>
            @endforeach
        </nav>

        @if ($ouvert === 'pannes')
            {{-- ═══════════════════════════════ pannes — 09/10
                 « Dès que je dépose le code, je dois voir l'erreur ; et tous les codes qui
                 surviennent, listés et cliquables. » --}}
            <x-carte-section titre="Pannes et santé">
                <form wire:submit="ouvrirLIncident" class="bloc-saisie" style="margin-bottom:12px;">
                    <x-champ label="Référence lue sur la page de panne" model="referenceCherchee" width="240" placeholder="ERR-XEYTWV" />
                    <button type="submit" class="bouton">Voir l'erreur</button>
                </form>
                <p class="maint-aide">
                    Seules les pannes <b>encore ouvertes</b> sont listées, une ligne par panne. « Corrigé » la
                    retire ; si elle revient, elle reparaît.
                </p>

                <div class="tableau-conteneur">
                    <table class="tableau">
                        <thead>
                            <tr><th>Référence</th><th>Dernière fois</th><th style="text-align:right;">Fois</th><th>Erreur</th><th>Adresse</th><th class="colonne-collee"></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($this->incidentsRecents as $incident)
                                <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                    <td style="white-space:nowrap;">
                                        <a href="{{ route('super-admin.incident', $incident['reference']) }}" wire:navigate
                                            style="font-weight:700; color:var(--th-accent,#C8102E);">{{ $incident['reference'] }}</a>
                                    </td>
                                    <td style="white-space:nowrap; color:#6B6E76;">{{ $incident['date'] ?? '—' }}</td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums;" title="Depuis le {{ $incident['premiere'] ?? '—' }}">{{ $incident['fois'] }}</td>
                                    <td style="font-size:12.5px;">{{ \Illuminate\Support\Str::limit((string) $incident['message'], 140) }}</td>
                                    <td style="font-size:12px; color:#6B6E76; word-break:break-all;">{{ \Illuminate\Support\Str::limit((string) $incident['url'], 70) }}</td>
                                    <td class="colonne-collee" style="white-space:nowrap;">
                                        <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                            wire:click="declarerRegle('{{ $incident['reference'] }}')">Corrigé</button>
                                    </td>
                                </tr>
                            @empty
                                <x-table-vide :colspan="6" texte="Aucune panne ouverte." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-carte-section>
        @elseif ($ouvert === 'roles')
            {{-- ═══════════════════════════════ rôles — 22/10. Son propre composant, qui se garde lui-même. --}}
            <x-carte-section titre="Rôles et pages">
                <livewire:superadmin.roles-configures />
            </x-carte-section>
        @elseif ($ouvert === 'habilitations')
            {{-- ═══════════════════════════════ habilitations — 26/10. Son propre composant, qui se garde lui-même. --}}
            <x-carte-section titre="Habilitations — modifier et supprimer">
                <livewire:superadmin.habilitations />
            </x-carte-section>
        @elseif ($ouvert === 'liaisons')
            {{-- ═══════════════════════════════ COMPTAFLOW et IA : deux pages à part, on y mène.
                 L'écran de l'IA est refait dans un autre chantier : il n'est que lié d'ici. --}}
            <div class="maint-tuiles">
                <a href="{{ route('super-admin.comptaflow.liaison') }}" wire:navigate class="maint-tuile">
                    <span class="maint-tuile-titre">Liaison COMPTAFLOW →</span>
                    <span class="maint-tuile-texte">Relier une entreprise à son dossier COMPTAFLOW, vérifier la liaison,
                        associer clients et fournisseurs.</span>
                </a>
                <a href="{{ route('super-admin.ia.configuration') }}" wire:navigate class="maint-tuile">
                    <span class="maint-tuile-titre">Intelligence artificielle →</span>
                    <span class="maint-tuile-texte">Les clés des fournisseurs d'IA, et l'IA affectée à chaque usage :
                        le rapport, les imputations comptables.</span>
                </a>
            </div>
        @elseif ($ouvert === 'donnees')
            {{-- ═══════════════════════════════ effacer : trois gestes, un à la fois

                 Ils étaient trois cartes l'une sous l'autre, qu'on lisait toutes avant de trouver
                 la sienne. On choisit d'abord le geste, puis on voit son seul formulaire. Chacun
                 garde son entreprise, sa confirmation par le nom et son bouton : un champ partagé
                 finirait par faire déclencher l'un pour l'autre. --}}
            @php $geste = $this->gesteOuvert; @endphp

            <div class="encart encart-alerte">
                <b>Tout ce qui est ici est définitif.</b> Chaque geste demande de retaper le nom de
                l'entreprise, puis une dernière confirmation.
            </div>

            @if ($geste === '')
                <div class="maint-tuiles">
                    @foreach ($this->gestes as $cle => $choix)
                        <a href="{{ route('super-admin.maintenance', ['theme' => 'donnees', 'geste' => $cle]) }}" wire:navigate
                           class="maint-tuile {{ $cle === 'supprimer' ? 'est-danger' : '' }}">
                            <span class="maint-tuile-titre">{{ $choix['titre'] }}</span>
                            <span class="maint-tuile-texte">{{ $choix['texte'] }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <nav class="maint-fil" aria-label="Gestes d'effacement">
                    @foreach ($this->gestes as $cle => $choix)
                        <a href="{{ route('super-admin.maintenance', ['theme' => 'donnees', 'geste' => $cle]) }}" wire:navigate
                           class="maint-puce est-danger {{ $cle === $geste ? 'est-ouvert' : '' }}"
                           @if ($cle === $geste) aria-current="page" @endif>{{ $choix['titre'] }}</a>
                    @endforeach
                </nav>
            @endif

            @if ($geste === 'partie')
                {{-- ─────────── vider ce qu'on désigne — 01/10 : « des cases à cocher des pages ayant
                     des données […] classer par module […] un bouton tout cocher par module ».
                     Les ensembles vides sont montrés et désactivés, plutôt que cachés : une liste
                     qui change de longueur d'une entreprise à l'autre fait chercher ce qui a disparu. --}}
                <x-carte-section titre="Vider une partie">
                    <p class="maint-aide">
                        Seul ce qui est coché part. <b>Ne sont jamais touchés :</b> la fiche entreprise, les villes,
                        les lieux, les accès, les exercices et le journal d'activité.
                    </p>

                    <div class="bloc-saisie">
                        <x-champ label="Entreprise" model="choixId" type="select" live="true" width="280"
                            :options="collect(['' => '— Choisir une entreprise —'])->union($this->entreprises->pluck('nom', 'id'))" />
                    </div>

                    @if ($this->cibleChoix)
                        @php $volumes = $this->volumesParLot; @endphp

                        @foreach (\Modules\Noyau\Entreprises\Actions\PurgeParModule::MODULES as $cleModule => $module)
                            @php
                                $porte = collect($module['lots'])->contains(fn ($lot, $cle) => ($volumes[$cle] ?? 0) > 0);
                            @endphp

                            <div style="margin-top:18px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:14px;">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                                    <h3 style="font-size:14.5px; font-weight:700; margin:0;">{{ $module['libelle'] }}</h3>

                                    {{-- Un seul bouton, et il agit dans le sens qui reste à faire : tout
                                         cocher tant qu'il manque quelque chose, tout décocher ensuite. --}}
                                    @if ($porte)
                                        <button type="button" wire:click="basculerLeModule('{{ $cleModule }}')"
                                            class="bouton bouton-secondaire" style="padding:4px 11px; font-size:12px;">
                                            Tout cocher / décocher
                                        </button>
                                    @endif
                                </div>

                                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:8px;">
                                    @foreach ($module['lots'] as $cle => $lot)
                                        @php $nombre = $volumes[$cle] ?? 0; @endphp
                                        <label style="display:flex; align-items:flex-start; gap:9px; padding:9px 11px;
                                                      border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px;
                                                      background:{{ $nombre > 0 ? '#fff' : '#F7F6F2' }};
                                                      cursor:{{ $nombre > 0 ? 'pointer' : 'not-allowed' }};
                                                      opacity:{{ $nombre > 0 ? '1' : '.6' }};">
                                            <input type="checkbox" wire:model.live="lotsChoisis.{{ $cle }}"
                                                @disabled($nombre === 0) style="margin-top:3px;">
                                            <span style="min-width:0;">
                                                <b style="font-size:13.5px;">{{ $lot['libelle'] }}</b>
                                                <span style="display:block; font-size:11.5px; color:#6B6E76;">
                                                    Écran : {{ $lot['ecran'] }}
                                                </span>
                                                <span style="display:block; font-size:12.5px; font-weight:700; margin-top:3px;
                                                             font-variant-numeric:tabular-nums;
                                                             color:{{ $nombre > 0 ? '#C8102E' : '#6B6E76' }};">
                                                    {{ $nombre > 0 ? number_format($nombre, 0, ',', ' ').' ligne(s)' : 'rien à vider' }}
                                                </span>
                                                @if (isset($lot['entraine']) && $nombre > 0)
                                                    {{-- L'entraînement est dit à côté de la case : c'est au moment de cocher qu'il compte. --}}
                                                    <span style="display:block; font-size:11.5px; color:#B45309; margin-top:4px; line-height:1.45;">
                                                        ⚠ {{ $lot['entraine'] }}
                                                    </span>
                                                @endif
                                            </span>
                                        </label>

                                        {{-- Les relevés banque par banque, sous la case qui les prend tous — 27/10. --}}
                                        @if ($cle === 'mouvements_bancaires' && $this->relevesBanques !== [])
                                            <div style="grid-column:1 / -1; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:8px; padding:9px 11px;">
                                                <div style="font-size:12.5px; font-weight:700; margin-bottom:6px;">
                                                    Ou seulement le relevé de ces banques — la banque reste déclarée, le relevé se redépose ensuite :
                                                </div>
                                                <div style="display:grid; gap:4px;">
                                                    @foreach ($this->relevesBanques as $banque)
                                                        <label wire:key="purge-banque-{{ $banque['id'] }}" style="display:flex; align-items:center; gap:9px; font-size:12.5px; cursor:pointer;">
                                                            <input type="checkbox" wire:model.live="banquesChoisies.{{ $banque['id'] }}"
                                                                @disabled(! empty($lotsChoisis['mouvements_bancaires']))>
                                                            <span>
                                                                <b>{{ $banque['nom'] }}</b>
                                                                <span style="color:#6B6E76;">
                                                                    · {{ number_format($banque['operations'], 0, ',', ' ') }} opération(s)
                                                                    @if ($banque['du']) · du {{ \Illuminate\Support\Carbon::parse($banque['du'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($banque['au'])->format('d/m/Y') }} @endif
                                                                </span>
                                                            </span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Les dépôts un par un, sous la case qui les prend tous — 07/10. Ils ne se
                                             cochent plus quand « tous » l'est : elle les emporte déjà. --}}
                                        @if ($cle === 'lots' && $this->depots !== [])
                                            <div style="grid-column:1 / -1; border:1px dashed var(--th-ligne,#E2E0D8); border-radius:8px; padding:9px 11px;">
                                                <div style="font-size:12.5px; font-weight:700; margin-bottom:6px;">
                                                    Ou seulement ceux-ci — {{ count($this->depots) }} dépôt(s), du plus récent au plus ancien :
                                                </div>
                                                <div style="display:grid; gap:4px; max-height:320px; overflow:auto;">
                                                    @foreach ($this->depots as $depot)
                                                        <label style="display:flex; align-items:center; gap:9px; font-size:12.5px; cursor:pointer;">
                                                            <input type="checkbox" wire:model.live="depotsChoisis.{{ $depot['id'] }}"
                                                                @disabled(! empty($lotsChoisis['lots']))>
                                                            <span style="min-width:0; overflow-wrap:anywhere;">
                                                                <b>{{ $depot['fichier'] }}</b>
                                                                <span style="color:#6B6E76;">
                                                                    · {{ $depot['format'] }}
                                                                    · déposé le {{ \Illuminate\Support\Carbon::parse($depot['depose_le'])->format('d/m/Y H:i') }}
                                                                    @if ($depot['deposant'] !== '') par {{ $depot['deposant'] }} @endif
                                                                    · {{ number_format($depot['creees'], 0, ',', ' ') }} ligne(s) créée(s)
                                                                    @if ($depot['rejets'] > 0) · {{ $depot['rejets'] }} rejet(s) @endif
                                                                </span>
                                                            </span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        @php $recap = $this->recapitulatif; @endphp

                        @if ($recap['lignes'] !== [])
                            <div class="encart encart-alerte" style="margin-top:18px;">
                                <b>{{ montant_nombre($recap['total']) }} ligne(s)</b> seront supprimées pour
                                <b>{{ $this->cibleChoix->nom }}</b> :
                                <ul style="margin:8px 0 0; padding-left:18px; font-size:13px;">
                                    @foreach ($recap['lignes'] as $ligne)
                                        <li>{{ $ligne }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="bloc-saisie" style="margin-top:14px;">
                            <x-champ label="Retapez le nom de l'entreprise pour confirmer" model="confirmationChoix"
                                width="320" :placeholder="$this->cibleChoix->nom" />

                            {{-- La boîte de confirmation de l'application, au centre de l'écran (01/10) :
                                 elle dit ce qui part, ligne par ligne. Sans JavaScript le geste part
                                 directement ; le nom retapé, lui, ne dépend d'aucun script. --}}
                            <button type="button" wire:click="viderLesChoisis" class="bouton bouton-sombre"
                                data-confirmer="Supprimer définitivement {{ montant_nombre($recap['total']) }} ligne(s) de « {{ $this->cibleChoix->nom }} » ?"
                                data-confirmer-titre="Vider les données cochées"
                                data-confirmer-ton="alerte"
                                data-confirmer-detail="{{ implode(' · ', $recap['lignes']) }}{{ $recap['entraines'] !== [] ? ' — '.implode(' ', $recap['entraines']) : '' }}">
                                Vider les ensembles cochés
                            </button>
                        </div>

                        <x-erreurs-du-bloc prefixe="lotsChoisis" />
                        <x-erreurs-du-bloc prefixe="confirmationChoix" />
                        <x-erreurs-du-bloc prefixe="choixId" />
                    @endif

                    @if ($resultatChoix)
                        <div class="encart encart-succes" style="margin-top:16px;">
                            <b>Fait.</b>
                            <ul style="margin:8px 0 0; padding-left:18px; font-size:13px;">
                                @foreach ($resultatChoix as $libelle => $nombre)
                                    <li>{{ $libelle }} : {{ number_format($nombre, 0, ',', ' ') }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-carte-section>
            @elseif ($geste === 'tout')
                {{-- ─────────── la purge : vide les écritures, laisse l'entreprise debout. --}}
                <x-carte-section titre="Vider toute une entreprise">
                    <p class="maint-aide">
                        <b>Part :</b> tout ce qui a été saisi ou importé — écritures, fiches de réception, caisse,
                        entrées et sorties, factures fournisseurs, relances, dépôts d'import et
                        <b>leurs fichiers sur le disque</b> (le même fichier pourra être redéposé). La numérotation
                        repart à zéro.<br>
                        <b>Reste :</b> la fiche entreprise, les villes, les lieux, les exercices, les listes déroulantes,
                        les accès et le journal d'activité.
                    </p>

                    <div class="bloc-saisie">
                        <x-champ label="Entreprise" model="entrepriseId" type="select" live="true" width="280"
                            :options="collect(['' => '— Choisir une entreprise —'])->union($this->entreprises->pluck('nom', 'id'))" />
                    </div>

                    @if ($this->volumes)
                        <ul class="maint-comptes" aria-label="Ce qui sera vidé">
                            @foreach ($this->volumes as $libelle => $nombre)
                                <li><span>{{ $libelle }}</span><b>{{ number_format($nombre, 0, ',', ' ') }}</b></li>
                            @endforeach
                        </ul>

                        <label class="maint-option">
                            <input type="checkbox" wire:model="purgerCommerciaux" style="margin-top:3px;">
                            <span>Vider aussi les fiches commerciales
                                <small>Les comptes utilisateurs sont conservés.</small></span>
                        </label>

                        {{-- Le gérant est épargné à dessein : c'est lui qui recréera les autres. Une
                             entreprise sans aucun accès ne se rouvre plus depuis l'application. --}}
                        <label class="maint-option">
                            <input type="checkbox" wire:model="purgerAcces" style="margin-top:3px;">
                            <span>Supprimer aussi <b>tous les accès sauf celui du gérant</b>
                                <small>Le gérant reste pour rouvrir les accès. Votre propre compte n'est jamais touché.</small></span>
                        </label>

                        {{-- Codes agents et correspondances : des réponses données à la main aux questions
                             des imports. Les refaire après chaque purge n'aurait aucun sens. --}}
                        <label class="maint-option">
                            <input type="checkbox" wire:model="purgerReglagesImport" style="margin-top:3px;">
                            <span>Supprimer aussi les <b>réglages de rattachement des imports</b>
                                <small>Codes agents et correspondances de libellés. Sans cette case, ils restent ; seules
                                    les questions encore sans réponse sont retirées.</small></span>
                        </label>

                        <label class="champ-libelle">
                            Retapez le nom de l'entreprise pour confirmer : <b>{{ $this->cible?->nom }}</b>
                        </label>
                        <input type="text" wire:model="confirmation" value="{{ $confirmation }}" class="champ" style="max-width:420px;">
                        @error('confirmation') <span class="champ-erreur">{{ $message }}</span> @enderror

                        <div style="margin-top:16px;">
                            <button type="button" wire:click="purger" class="bouton bouton-danger"
                                data-confirmer="Vider définitivement les données de « {{ $this->cible?->nom }} » ?"
                                data-confirmer-titre="Vider toute une entreprise"
                                data-confirmer-ton="alerte"
                                data-confirmer-detail="L'entreprise, ses lieux et ses accès restent ; ses écritures et ses imports partent.">
                                Vider définitivement les données
                            </button>
                        </div>
                    @endif

                    @if ($resultat)
                        <div class="encart encart-succes" style="margin-top:18px;">
                            <b>Purge effectuée.</b>
                            {{ collect($resultat)->map(fn ($n, $t) => "$t : $n")->implode(' · ') }}
                        </div>
                    @endif
                </x-carte-section>
            @elseif ($geste === 'supprimer')
                {{-- ─────────── la suppression : ne laisse rien. --}}
                <x-carte-section titre="Supprimer une entreprise">
                    <p class="maint-aide">
                        <b>Rien ne reste :</b> villes, lieux, écritures, listes, exercices, conversations, imports,
                        fichiers sur le disque — et <b>tous les accès, gérant compris</b>. Pour ne vider que les
                        chiffres, choisissez plutôt « Vider toute une entreprise ».
                    </p>

                    <div class="bloc-saisie">
                        <x-champ label="Entreprise à supprimer" model="suppressionId" type="select" live="true" width="280"
                            :options="collect(['' => '— Choisir une entreprise —'])->union($this->entreprises->pluck('nom', 'id'))" />
                    </div>

                    @if ($this->portee)
                        <ul class="maint-comptes" aria-label="Ce qui sera supprimé">
                            @foreach ($this->portee as $libelle => $nombre)
                                <li><span>{{ $libelle }}</span><b>{{ number_format($nombre, 0, ',', ' ') }}</b></li>
                            @endforeach
                        </ul>

                        <label class="champ-libelle">
                            Retapez le nom de l'entreprise pour confirmer : <b>{{ $this->cibleSuppression?->nom }}</b>
                        </label>
                        <input type="text" wire:model="confirmationSuppression" value="{{ $confirmationSuppression }}" class="champ" style="max-width:420px;">
                        @error('confirmationSuppression') <span class="champ-erreur">{{ $message }}</span> @enderror

                        <div style="margin-top:16px;">
                            <button type="button" wire:click="supprimer" class="bouton bouton-danger"
                                data-confirmer="Supprimer définitivement « {{ $this->cibleSuppression?->nom }} », ses données et tous ses accès ?"
                                data-confirmer-titre="Supprimer une entreprise"
                                data-confirmer-ton="alerte"
                                data-confirmer-detail="Cette action ne se rattrape pas.">
                                Supprimer définitivement l'entreprise
                            </button>
                        </div>
                    @endif

                    @if ($resultatSuppression)
                        <div class="encart encart-succes" style="margin-top:18px;">
                            <b>« {{ $resultatSuppression['nom'] }} » supprimée.</b>
                            {{ collect($resultatSuppression['bilan'])->map(fn ($n, $t) => "$t : $n")->implode(' · ') }}
                        </div>
                    @endif
                </x-carte-section>
            @endif
        @endif
    @endif
</div>

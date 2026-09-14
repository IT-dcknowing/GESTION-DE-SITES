<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Actions\RenvoyerLAcces;
use Modules\Noyau\Entreprises\Actions\SupprimerAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use function Livewire\Volt\{state, computed};

state([
    'recherche' => '',
    'motDePasseGenerePour' => null,
    'motDePasseGenere' => null,
    'message' => null,
    // Écrasement manuel : l'identifiant de la personne dont on saisit le mot de passe.
    'cibleId' => null,
    'nouveauMotDePasse' => '',
    'nouveauMotDePasseConfirmation' => '',
    'exigerChangement' => true,
    'pageUtilisateurs' => 1,

    // Accès cochés en vue d'un geste groupé — activation ou renvoi du courriel.
    // Préparer quatorze accès puis les ouvrir un par un, c'est quatorze allers-retours
    // pour un seul geste réel.
    'selection' => [],

    /*
     * L'entreprise choisie commande trois choses à la fois : elle restreint la liste,
     * elle restreint l'annuaire téléchargé, et elle coche d'emblée tout son personnel.
     *
     * C'est le geste réel : on ne renvoie pas un courriel à « quelques personnes », on le
     * renvoie à une équipe qui n'a rien reçu. Choisir l'entreprise, vérifier la liste,
     * valider — plutôt que cocher quatorze cases en espérant n'en oublier aucune.
     */
    'entrepriseFiltre' => '',
]);

/*
 * Ville et site sont chargés avec le compte, et non lus ligne par ligne : dix lignes
 * affichées feraient sinon vingt requêtes de plus, pour deux mots par ligne.
 */
$utilisateurs = computed(function () {
    $recherche = trim($this->recherche);

    return User::with(['entreprise', 'ville', 'site.ville'])
        ->when($this->entrepriseFiltre !== '', fn ($q) => $q->where('entreprise_id', (int) $this->entrepriseFiltre))
        ->when($recherche !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('name', 'like', "%$recherche%")
            ->orWhere('email', 'like', "%$recherche%")))
        ->orderByDesc('created_at')->get();
});

$roles = computed(fn () => User::nomsRolesParUtilisateur($this->utilisateurs->pluck('id')));

/**
 * Ville et site d'affectation de chaque ligne, résolus une fois pour toutes.
 *
 * La ville n'est pas toujours écrite au même endroit : un responsable de site n'a pas de
 * `ville_id`, la sienne est celle de son lieu. Afficher un tiret pour lui laisserait
 * croire qu'il n'est rattaché nulle part, alors qu'il tient un atelier précis.
 *
 * Un compte sans ville ni site n'est pas pour autant sans périmètre : le gérant couvre
 * l'entreprise entière, le super administrateur la plateforme. Le dire vaut mieux qu'un
 * tiret, qui se lit comme un oubli de saisie.
 *
 * @return array<int, array{ville: string, site: string}>
 */
$perimetres = computed(fn () => $this->utilisateurs->mapWithKeys(fn (User $u) => [$u->id => [
    'ville' => $u->ville?->nom
        ?? $u->site?->ville?->nom
        ?? ($u->entreprise_id ? "Toute l'entreprise" : 'Plateforme'),
    'site' => $u->site?->nom ?? ($u->ville_id ? 'Toute la ville' : '—'),
]])->all());

/** La personne dont on est en train d'écraser le mot de passe, ou null. */
$cible = computed(fn () => $this->cibleId ? User::find($this->cibleId) : null);

$entreprises = computed(fn () => Entreprise::orderBy('nom')->pluck('nom', 'id'));

/** Accès inactifs de la liste courante : ce sont eux que l'activation groupée vise. */
$inactifs = computed(fn () => $this->utilisateurs->where('est_actif', false)->pluck('id')->all());

/**
 * Comptes de la liste sur lesquels un geste groupé peut porter.
 *
 * Son propre compte en est écarté : se révoquer soi-même couperait la session en cours,
 * et se renvoyer son propre courriel d'accueil n'apprend rien à personne.
 */
$selectionnables = computed(fn () => $this->utilisateurs
    ->where('id', '!=', auth()->id())
    ->pluck('id')->map(fn ($id) => (string) $id)->all());

/** Comptes cochés qui pourraient encore recevoir un courriel : ils ont une entreprise. */
$destinatairesPossibles = computed(fn () => $this->utilisateurs
    ->whereIn('id', array_map('intval', $this->selection))
    ->filter(fn (User $u) => $u->entreprise_id !== null)
    ->count());

$updatedRecherche = function () {
    $this->pageUtilisateurs = 1;
    // La recherche change : garder des cases cochées sur des lignes devenues invisibles
    // ferait agir sur des accès qu'on ne voit plus au moment de valider.
    $this->selection = [];
};

/**
 * Choisir une entreprise coche tout son personnel.
 *
 * C'est le raccourci qui rend le geste groupé utilisable : le cas réel n'est pas
 * « quelques personnes », c'est « toute l'équipe de cette entreprise n'a rien reçu ».
 * Les cases restent décochables une par une — la présélection propose, elle n'impose pas.
 */
$updatedEntrepriseFiltre = function () {
    $this->pageUtilisateurs = 1;
    $this->message = null;
    $this->resetErrorBag(['suppression', 'renvoi']);

    // Les computed dépendent du filtre : les vider avant de lire « selectionnables »,
    // sinon on cocherait la liste d'avant.
    unset($this->utilisateurs, $this->roles, $this->perimetres, $this->inactifs, $this->selectionnables);

    $this->selection = $this->entrepriseFiltre === '' ? [] : $this->selectionnables;
};

$basculerActif = function (int $id) {
    if ($id === auth()->id()) {
        return;
    }

    $utilisateur = User::findOrFail($id);

    if ($utilisateur->est_actif) {
        $utilisateur->update(['est_actif' => false]);
        $this->message = "Accès de {$utilisateur->name} révoqué.";

        return;
    }

    // C'est l'action qui ouvre l'accès, car c'est elle qui sait souhaiter la bienvenue :
    // un accès préparé inactif n'a reçu aucun courriel, il le reçoit maintenant.
    app(CreerAcces::class)->activer($utilisateur);
    $this->message = "Accès de {$utilisateur->name} activé — courriel de bienvenue envoyé.";
};

/*
|--------------------------------------------------------------------------
| Activation groupée
|--------------------------------------------------------------------------
| Quatorze accès préparés le même jour s'ouvrent le même jour. Les cocher puis
| valider en une fois évite quatorze confirmations identiques — et surtout évite
| d'en oublier un, ce qui ne se voit qu'au moment où la personne ne peut pas entrer.
|
| La sélection ne porte que sur des accès inactifs : cocher un accès déjà ouvert
| n'aurait aucun effet, et lui renverrait au mieux un second courriel de bienvenue.
*/
$toutSelectionner = function () {
    $this->selection = $this->selectionnables;
};

/** Ne cocher que les accès jamais ouverts : le cas le plus fréquent du renvoi groupé. */
$selectionnerLesInactifs = function () {
    $this->selection = array_values(array_intersect(
        array_map('strval', $this->inactifs),
        $this->selectionnables,
    ));
};

$viderSelection = function () {
    $this->selection = [];
};

$activerSelection = function (CreerAcces $action) {
    // On repart de la base plutôt que de la liste reçue : les identifiants viennent du
    // navigateur, et seuls comptent ceux qui existent, sont bien inactifs, et ne sont
    // pas le compte de celui qui clique.
    $comptes = User::whereIn('id', array_map('intval', $this->selection))
        ->where('est_actif', false)
        ->where('id', '!=', auth()->id())
        ->get();

    if ($comptes->isEmpty()) {
        $this->message = 'Aucun accès inactif dans la sélection : rien à activer.';
        $this->selection = [];

        return;
    }

    $ouverts = $comptes->filter(fn (User $compte) => $action->activer($compte));

    activity()
        ->causedBy(auth()->user())
        ->withProperties(['comptes' => $ouverts->pluck('email')->all()])
        ->log('Activation groupée de '.$ouverts->count().' accès');

    $this->selection = [];
    $this->message = $ouverts->count().' accès '.($ouverts->count() > 1 ? 'activés' : 'activé')
        .' — courriel de bienvenue envoyé à '.$ouverts->pluck('name')->implode(', ').'.';
};

/*
|--------------------------------------------------------------------------
| Renvoi du courriel d'accès
|--------------------------------------------------------------------------
| Un courriel qui n'arrive pas ne fait pas de bruit : l'accès est ouvert côté
| administration, la personne n'a rien reçu, et chacun attend l'autre. Ce bouton
| coupe court.
|
| Ce que l'action ne fait jamais, et c'est le point : elle ne touche à aucune
| donnée. Sur un accès déjà en service — quelqu'un s'en sert, a saisi des lignes,
| a choisi son mot de passe — le message repart en simple rappel, sans rien
| modifier. Le mot de passe n'est ni changé ni réinitialisé.
*/
$renvoyer = function (int $id, RenvoyerLAcces $action) {
    $cible = $this->utilisateurs->firstWhere('id', $id);

    if (! $cible) {
        return;
    }

    try {
        $bilan = $action->executer(auth()->user(), $cible);
    } catch (\RuntimeException $e) {
        $this->message = null;
        $this->addError('renvoi', $e->getMessage());

        return;
    }

    $this->resetErrorBag('renvoi');
    unset($this->utilisateurs, $this->inactifs, $this->perimetres);

    $this->message = "Courriel renvoyé à {$cible->name} ({$cible->email})"
        .($bilan['active'] ? " — l'accès, qui était encore fermé, vient d'être ouvert." : '.')
        .($bilan['lien'] === 'definition'
            ? ' Le message contient un lien pour choisir son mot de passe.'
            : ' Ce compte est déjà en service : son mot de passe est inchangé.');
};

/**
 * Renvoi groupé.
 *
 * Comme pour l'activation, on repart de la base plutôt que de la liste reçue : les
 * identifiants viennent du navigateur. Chaque envoi est isolé — une adresse refusée par
 * le serveur de messagerie ne doit pas interrompre la tournée et laisser la moitié de
 * l'équipe sans message, sans qu'on sache laquelle.
 */
$renvoyerSelection = function (RenvoyerLAcces $action) {
    $comptes = User::whereIn('id', array_map('intval', $this->selection))
        ->where('id', '!=', auth()->id())
        ->whereNotNull('entreprise_id')
        ->get();

    if ($comptes->isEmpty()) {
        $this->message = 'Aucun destinataire dans la sélection : rien à renvoyer.';

        return;
    }

    $partis = [];
    $echecs = [];

    foreach ($comptes as $compte) {
        try {
            $action->executer(auth()->user(), $compte);
            $partis[] = $compte->name;
        } catch (\RuntimeException $e) {
            $echecs[] = $compte->email;
        }
    }

    activity()
        ->causedBy(auth()->user())
        ->withProperties(['partis' => count($partis), 'echecs' => $echecs])
        ->log('Renvoi groupé du courriel d\'accès à '.count($partis).' destinataire(s)');

    $this->selection = [];
    unset($this->utilisateurs, $this->inactifs, $this->perimetres);

    $this->message = count($partis).' courriel'.(count($partis) > 1 ? 's' : '').' renvoyé'
        .(count($partis) > 1 ? 's' : '').($partis ? ' — '.implode(', ', $partis).'.' : '.');

    if ($echecs) {
        // Nommer les adresses en échec : « 12 sur 14 » sans dire lesquelles oblige à
        // tout recommencer pour retrouver les deux manquantes.
        $this->addError('renvoi', count($echecs).' envoi(s) en échec : '.implode(', ', $echecs)
            .'. Les accès sont ouverts ; le mot de passe peut être transmis autrement.');
    }
};

/*
|--------------------------------------------------------------------------
| Suppression d'un accès
|--------------------------------------------------------------------------
| Actif ou préparé, un accès se supprime — quelqu'un s'en va, ou l'on s'est trompé
| d'adresse en le créant. Ce que la personne a saisi, en revanche, reste : une
| prospection appartient à l'entreprise, pas à celui qui l'a tapée. L'action s'en
| charge et dit ce qu'elle a fait de la fiche commerciale.
*/
$supprimer = function (int $id, SupprimerAcces $action) {
    $cible = User::find($id);

    if (! $cible) {
        return;
    }

    try {
        $bilan = $action->executer(auth()->user(), $cible);
    } catch (\RuntimeException $e) {
        $this->message = null;
        $this->addError('suppression', $e->getMessage());

        return;
    }

    // La ligne disparaît : une case restée cochée désignerait un compte absent.
    $this->selection = array_values(array_diff($this->selection, [(string) $id]));
    $this->resetErrorBag('suppression');
    unset($this->utilisateurs, $this->roles, $this->inactifs, $this->perimetres);

    $this->message = "Accès de {$cible->name} supprimé — fiche commerciale : {$bilan['fiche commerciale']}.";
};

$forcerReinitialisation = function (int $id) {
    $utilisateur = User::findOrFail($id);
    $motDePasse = Str::password(12);

    $utilisateur->forceFill([
        'password' => Hash::make($motDePasse),
        'doit_changer_mot_de_passe' => true,
    ])->save();

    $this->journaliser($utilisateur, 'mot de passe réinitialisé (généré)');

    $this->motDePasseGenerePour = $utilisateur->name;
    $this->motDePasseGenere = $motDePasse;
    $this->fermerEcrasement();
};

/*
|--------------------------------------------------------------------------
| Écrasement manuel du mot de passe
|--------------------------------------------------------------------------
| Quand quelqu'un a perdu son mot de passe et qu'aucun courriel ne lui parvient,
| le super administrateur en fixe un nouveau de vive voix. L'opération est
| journalisée, et le compte est marqué comme devant changer ce mot de passe à la
| connexion suivante : celui saisi ici a transité par la voix ou par un message,
| il ne doit pas rester le mot de passe définitif.
*/
$ouvrirEcrasement = function (int $id) {
    $this->cibleId = $id;
    $this->nouveauMotDePasse = '';
    $this->nouveauMotDePasseConfirmation = '';
    $this->exigerChangement = true;
    $this->motDePasseGenere = null;
    $this->message = null;
    $this->resetValidation();
};

$fermerEcrasement = function () {
    $this->reset(['cibleId', 'nouveauMotDePasse', 'nouveauMotDePasseConfirmation']);
    $this->resetValidation();
};

$enregistrerMotDePasse = function () {
    // Le formulaire n'apparaît qu'une cible désignée ; un appel sans cible ne peut venir
    // que d'une requête forgée, on l'ignore sans rien changer ni rien révéler.
    $utilisateur = $this->cibleId ? User::find($this->cibleId) : null;

    if (! $utilisateur) {
        $this->fermerEcrasement();

        return;
    }

    $this->validate([
        'nouveauMotDePasse' => ['required', 'confirmed:nouveauMotDePasseConfirmation', Password::min(8)->letters()->numbers()],
    ], [
        'nouveauMotDePasse.confirmed' => 'Les deux mots de passe saisis ne correspondent pas.',
    ], [
        'nouveauMotDePasse' => 'mot de passe',
    ]);

    $utilisateur->forceFill([
        'password' => Hash::make($this->nouveauMotDePasse),
        'doit_changer_mot_de_passe' => (bool) $this->exigerChangement,
    ])->save();

    $this->journaliser($utilisateur, 'mot de passe redéfini manuellement');

    $this->message = "Mot de passe de {$utilisateur->name} remplacé."
        .($this->exigerChangement ? ' Il devra en choisir un autre à sa prochaine connexion.' : '');

    $this->fermerEcrasement();
};

/** Toute intervention sur un mot de passe laisse une trace nominative dans le journal. */
$journaliser = function (User $utilisateur, string $action) {
    activity()
        ->causedBy(auth()->user())
        ->performedOn($utilisateur)
        ->withProperties(['email' => $utilisateur->email])
        ->log($action);
};

?>

<x-a-venir titre="Gestion des accès"
        description="Créer, révoquer et redéfinir le mot de passe de toute personne, toutes entreprises confondues.">

    @if (session('refus-switch'))
        <x-boite-message titre="Mode switch refusé" ton="alerte">{{ session('refus-switch') }}</x-boite-message>
    @endif

    @if (session('annonce-switch'))
        <div style="background:#EAF9F3; border:1px solid #0E9F6E55; color:#0E9F6E; border-radius:8px;
                    padding:10px 12px; font-size:14.5px; margin-bottom:14px;">
            {{ session('annonce-switch') }}
        </div>
    @endif

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:16px;">
            <a href="{{ route('super-admin.acces.creer') }}" wire:navigate
               style="display:inline-block; background:#C8102E; color:#fff; border-radius:8px; padding:9px 16px; font-weight:700; font-size:14.5px; text-decoration:none;">
                + Créer un accès
            </a>

            {{-- Le téléchargement n'est pas une action Livewire : il lui faut une vraie
                 réponse HTTP, donc un lien ordinaire — et surtout pas wire:navigate,
                 qui chargerait le PDF en arrière-plan sans jamais l'ouvrir. --}}
            <a href="{{ route('super-admin.annuaire', $entrepriseFiltre ? ['entreprise' => $entrepriseFiltre] : []) }}"
               style="display:inline-block; border:1px solid var(--th-ligne,#E2E0D8); color:#4B4E55; border-radius:8px; padding:9px 16px; font-weight:700; font-size:14.5px; text-decoration:none; background:#fff;">
                ↓ Annuaire PDF
            </a>

            <select wire:model.live="entrepriseFiltre"
                style="padding:9px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; font-size:14px; background:#fff; max-width:250px;">
                <option value="" @selected($entrepriseFiltre === '')>Toutes les entreprises</option>
                @foreach ($this->entreprises as $id => $nom)
                    <option value="{{ $id }}" @selected((string) $entrepriseFiltre === (string) $id)>{{ $nom }}</option>
                @endforeach
            </select>
        </div>

        <p style="font-size:12px; color:#9A9DA5; margin:-8px 0 16px; max-width:680px;">
            Choisir une entreprise filtre la liste, restreint l'annuaire téléchargé <b>et coche
            d'emblée tout son personnel</b> — de quoi lui renvoyer le courriel d'accès en un geste.
            L'annuaire, lui, liste rôle, nom, adresse et périmètre de chacun, groupés par entreprise
            puis par ville ; le gérant et le superviseur téléchargent le leur depuis leur propre écran.
        </p>

        <div style="margin-bottom:16px; max-width:340px;">
            <input type="search" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}" class="champ"
                placeholder="Rechercher un nom ou un e-mail…">
        </div>

        {{-- Barre des gestes groupés. Elle porte deux actions distinctes : ouvrir des accès
             préparés, et renvoyer le courriel à ceux qui ne l'ont pas reçu. La seconde vaut
             pour tout le monde, ouvert ou non — c'est justement quand l'accès est ouvert
             depuis longtemps sans que la personne ait pu entrer qu'elle sert. --}}
        @if (count($this->selectionnables) > 0)
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#FFFBEA; border:1px solid #D9770633; border-radius:8px; padding:10px 14px; margin-bottom:16px;">
                <span style="font-size:13.5px; color:#4B4E55;">
                    @if (count($selection))
                        <b>{{ count($selection) }}</b> compte{{ count($selection) > 1 ? 's' : '' }} sélectionné{{ count($selection) > 1 ? 's' : '' }}
                        @if ($entrepriseFiltre)
                            — {{ $this->entreprises[$entrepriseFiltre] ?? '' }}
                        @endif
                    @elseif (count($this->inactifs) > 0)
                        <b>{{ count($this->inactifs) }}</b> accès préparé{{ count($this->inactifs) > 1 ? 's' : '' }},
                        en attente d'activation.
                    @else
                        Cocher des lignes, ou choisir une entreprise pour cocher toute son équipe.
                    @endif
                </span>

                <button type="button" wire:click="toutSelectionner"
                    style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); color:#4B4E55; border-radius:6px; padding:6px 12px; font-size:12.5px; font-weight:600; cursor:pointer;">
                    Tout cocher ({{ count($this->selectionnables) }})
                </button>

                @if (count($this->inactifs) > 0)
                    <button type="button" wire:click="selectionnerLesInactifs"
                        style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); color:#4B4E55; border-radius:6px; padding:6px 12px; font-size:12.5px; font-weight:600; cursor:pointer;">
                        Cocher les non activés ({{ count($this->inactifs) }})
                    </button>
                @endif

                @if (count($selection))
                    <button type="button" wire:click="viderSelection"
                        style="background:transparent; border:1px solid var(--th-ligne,#E2E0D8); color:#6B6E76; border-radius:6px; padding:6px 12px; font-size:12.5px; font-weight:600; cursor:pointer;">
                        Tout décocher
                    </button>

                    <span style="flex-basis:100%; height:0;"></span>

                    <button type="button" wire:click="renvoyerSelection"
                        wire:confirm="Renvoyer le courriel d'accès aux {{ $this->destinatairesPossibles }} destinataire(s) sélectionné(s) ?&#10;&#10;Aucune donnée ne sera modifiée. Les comptes déjà en service reçoivent un simple rappel : leur mot de passe reste le leur."
                        @disabled($this->destinatairesPossibles === 0)
                        style="background:#1D4ED8; border:0; color:#fff; border-radius:6px; padding:7px 14px; font-size:12.5px; font-weight:700; cursor:pointer; {{ $this->destinatairesPossibles === 0 ? 'opacity:.45; cursor:not-allowed;' : '' }}">
                        ✉ Renvoyer le mail ({{ $this->destinatairesPossibles }})
                    </button>

                    <button type="button" wire:click="activerSelection"
                        wire:confirm="Activer les accès encore fermés parmi les {{ count($selection) }} sélectionnés ? Un courriel de bienvenue partira vers chacun."
                        style="background:#0E9F6E; border:0; color:#fff; border-radius:6px; padding:7px 14px; font-size:12.5px; font-weight:700; cursor:pointer;">
                        Activer les accès fermés
                    </button>
                @endif
            </div>
        @endif

        {{-- Le sac d'erreurs est lu directement plutôt que par @error : cette directive
             définit puis détruit une variable $message, et l'écran en possède déjà une
             — la sienne disparaîtrait pour tout le reste du gabarit. --}}
        @if ($errors->has('renvoi'))
            <div class="encart encart-alerte">{{ $errors->first('renvoi') }}</div>
        @endif

        @if ($message)
            <div class="encart encart-succes">{{ $message }}</div>
        @endif

        @if ($errors->has('suppression'))
            <div class="encart encart-alerte">{{ $errors->first('suppression') }}</div>
        @endif

        @if ($motDePasseGenere)
            <div style="background:#FFFBEA; border:1px solid #D97706; border-radius:8px; padding:12px 14px; margin-bottom:16px; font-size:14px;">
                Nouveau mot de passe provisoire pour <b>{{ $motDePasseGenerePour }}</b> :
                <code style="background:#fff; border:1px solid #D9770655; border-radius:6px; padding:2px 8px; font-size:14.5px; margin:0 4px;">{{ $motDePasseGenere }}</code>
                — à transmettre à la personne concernée. Elle devra le changer à sa prochaine connexion.
            </div>
        @endif

        @if ($this->cible)
            <div class="carte" style="margin-bottom:16px; border:1px solid #C8102E44;">
                <h3 class="titre-section">Nouveau mot de passe — {{ $this->cible->name }}</h3>
                <p style="font-size:13px; color:var(--th-gris,#6B6E76); margin:0 0 14px; line-height:1.55;">
                    {{ $this->cible->email }} · à utiliser lorsque la personne a perdu son mot de passe.
                    L'opération est enregistrée dans le journal.
                </p>

                <div class="bloc-saisie">
                    <x-champ label="Nouveau mot de passe" model="nouveauMotDePasse" type="password" width="220" />
                    <x-champ label="Confirmer" model="nouveauMotDePasseConfirmation" type="password" width="220" />
                    <x-champ label="Devra le changer à la prochaine connexion" model="exigerChangement" type="checkbox" />
                </div>

                <p style="font-size:12px; color:var(--th-gris,#6B6E76); margin:10px 0 14px;">
                    8 caractères minimum, avec au moins une lettre et un chiffre.
                </p>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" wire:click="enregistrerMotDePasse" class="bouton bouton-sombre">
                        Enregistrer le mot de passe
                    </button>
                    <button type="button" wire:click="fermerEcrasement" class="bouton bouton-secondaire">Annuler</button>
                </div>
            </div>
        @endif

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th style="width:28px;"></th>
                        <th>Utilisateur</th>
                        <th>Entreprise</th>
                        <th>Rôle</th>
                        <th>Ville</th>
                        <th>Site</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->utilisateurs->forPage($pageUtilisateurs, 10) as $utilisateur)
                        <tr wire:key="acces-{{ $utilisateur->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td>
                                {{-- La case couvre désormais toute ligne autre que la sienne : le
                                     renvoi du courriel concerne aussi les accès ouverts depuis
                                     longtemps, dont le titulaire n'a jamais reçu de message. --}}
                                @if ($utilisateur->id !== auth()->id())
                                    <input type="checkbox" wire:model.live="selection" value="{{ $utilisateur->id }}"
                                        aria-label="Sélectionner {{ $utilisateur->name }}">
                                @endif
                            </td>
                            <td>
                                <div style="font-weight:600;">{{ $utilisateur->name }}</div>
                                <div style="color:#6B6E76; font-size:13.5px;">{{ $utilisateur->email }}</div>
                            </td>
                            <td>{{ $utilisateur->entreprise?->nom ?? '— Plateforme —' }}</td>
                            <td>{{ \Modules\Noyau\Entreprises\Support\LibellesRoles::liste($this->roles[$utilisateur->id] ?? null) }}</td>
                            <td>{{ $this->perimetres[$utilisateur->id]['ville'] }}</td>
                            <td>{{ $this->perimetres[$utilisateur->id]['site'] }}</td>
                            <td>
                                @if ($utilisateur->est_actif)
                                    <span style="color:#0E9F6E; font-weight:600;">Actif</span>
                                @else
                                    <span style="color:#C8102E; font-weight:600;">Révoqué</span>
                                @endif
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                @if ($utilisateur->id !== auth()->id())
                                    {{-- L'ecran de creation sert aussi a reprendre : les champs sont
                                         les memes, et un formulaire logé dans une cellule de tableau
                                         devenait illisible des que l'adresse depassait la colonne. --}}
                                    <a href="{{ route('super-admin.acces.modifier', $utilisateur) }}" wire:navigate
                                        style="display:inline-block; text-decoration:none; background:transparent; border:1px solid var(--th-ligne,#E2E0D8); color:#4B4E55; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; margin-right:6px;">
                                        Modifier
                                    </a>

                                    {{-- Entrer dans le compte pour assister quelqu'un.

                                         Une personne au téléphone décrit un écran qu'on ne voit
                                         pas : habilitations, périmètre, ville de travail, rôle —
                                         tout cela change ce qui s'affiche, et aucune capture
                                         d'écran ne remplace le fait d'y être.

                                         Le passage n'inscrit **aucune connexion** au compte
                                         assisté : le journal de présence s'en abstient, et c'est
                                         le journal d'audit qui garde la trace, au nom de
                                         l'administrateur. Un accès révoqué reste fermé, y compris
                                         pour l'assistance. --}}
                                    @if ($utilisateur->est_actif)
                                        <form method="POST" action="{{ route('super-admin.switch.entrer', $utilisateur->id) }}"
                                              style="display:inline;">
                                            @csrf
                                            {{-- Sans question : le geste n'écrit rien, et le
                                                 bandeau rouge qui suit dit en permanence où
                                                 l'on se trouve. Une confirmation ici coûtait
                                                 un clic et ne protégeait rien. --}}
                                            <button type="submit"
                                                title="Voir l'application comme cette personne — aucune connexion ne sera inscrite à son compte"
                                                style="background:#191B20; border:0; color:#fff; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer; margin-right:6px; font-family:inherit;">
                                                ⇥ Accéder
                                            </button>
                                        </form>
                                    @endif
                                    <button type="button" wire:click="basculerActif({{ $utilisateur->id }})"
                                        wire:confirm="{{ $utilisateur->est_actif ? 'Révoquer l\'accès de '.$utilisateur->name.' ?' : 'Réactiver l\'accès de '.$utilisateur->name.' ?' }}"
                                        style="background:transparent; border:1px solid {{ $utilisateur->est_actif ? '#C8102E55' : '#0E9F6E55' }}; color:{{ $utilisateur->est_actif ? '#C8102E' : '#0E9F6E' }}; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer; margin-right:6px;">
                                        {{ $utilisateur->est_actif ? 'Révoquer' : 'Réactiver' }}
                                    </button>
                                    {{-- Renvoi du courriel : le geste courant quand quelqu'un dit
                                         n'avoir jamais rien reçu. Il n'écrit aucune donnée, et sur
                                         un compte déjà en service il ne touche pas au mot de passe. --}}
                                    @if ($utilisateur->entreprise_id)
                                        <button type="button" wire:click="renvoyer({{ $utilisateur->id }})"
                                            wire:confirm="Renvoyer le courriel d'accès à {{ $utilisateur->name }} ({{ $utilisateur->email }}) ?&#10;&#10;Aucune donnée ne sera modifiée."
                                            title="Renvoyer le courriel d'accès"
                                            style="background:transparent; border:1px solid #1D4ED855; color:#1D4ED8; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer; margin-right:6px;">
                                            ✉ Renvoyer
                                        </button>
                                    @endif

                                    <button type="button" wire:click="ouvrirEcrasement({{ $utilisateur->id }})"
                                        style="background:transparent; border:1px solid #C8102E55; color:#C8102E; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer; margin-right:6px;">
                                        Définir un mot de passe
                                    </button>
                                    <button type="button" wire:click="forcerReinitialisation({{ $utilisateur->id }})"
                                        wire:confirm="Générer un nouveau mot de passe provisoire pour {{ $utilisateur->name }} ?"
                                        style="background:transparent; border:1px solid var(--th-ligne,#E2E0D8); color:#4B4E55; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer; margin-right:6px;">
                                        Générer
                                    </button>

                                    {{-- Supprimer efface le compte, actif ou préparé. Ce que la
                                         personne a saisi reste : la fiche commerciale qui porte
                                         des écritures est conservée en Inactif, pas détruite. --}}
                                    <button type="button" wire:click="supprimer({{ $utilisateur->id }})"
                                        wire:confirm="Supprimer définitivement l'accès de {{ $utilisateur->name }} ({{ $utilisateur->email }}) ?&#10;&#10;La personne ne pourra plus se connecter. Ses saisies, elles, sont conservées : elles appartiennent à l'entreprise."
                                        style="background:#C8102E; border:0; color:#fff; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer;">
                                        Supprimer
                                    </button>
                                @else
                                    <span style="color:#9A9DA5; font-size:12.5px;">— vous-même —</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="8" texte="Aucun compte ne correspond à cette recherche." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageUtilisateurs" :total="$this->utilisateurs->count()" prop="pageUtilisateurs" />
    </x-a-venir>

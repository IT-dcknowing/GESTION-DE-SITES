<?php

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Support\ChoixDeLieu;
use Modules\Noyau\Entreprises\Support\ChoixDeVille;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;
use App\Models\User;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;
use function Livewire\Volt\{state, computed, mount};

/*
|--------------------------------------------------------------------------
| Ouvrir un acces, ou le reprendre
|--------------------------------------------------------------------------
| Le meme ecran sert aux deux : les champs sont les memes, et corriger un acces
| dans une ligne de tableau donnait un formulaire a l'etroit, illisible des que
| l'adresse depassait la largeur de la colonne.
|
| Le role et l'entreprise ne se reprennent que sur un acces jamais ouvert. Une
| fois qu'il a servi, ses ecritures lui sont rattachees : le deplacer les
| laisserait derriere lui, rattachees a un perimetre qui n'est plus le sien.
*/

state([
    'entrepriseId' => '',
    'roleActif' => 'gerant',
    'nom' => '',
    'email' => '',
    'motDePasse' => '',
    // Un acces prepare n'est pas un acces ouvert : cree inactif, il attend qu'on
    // l'active. Aucun courriel ne part, et la connexion est refusee entre-temps.
    'ouverture' => 'actif',
    'villeChoix' => '',
    'siteChoix' => '',
    'objectifGlobal' => Commercial::OBJECTIF_MENSUEL_DEFAUT,
    'pourcentageMecanique' => (int) (Commercial::PART_MECANIQUE_DEFAUT * 100),
    'confirmation' => null,

    // Renseigne quand on reprend un acces existant ; null a la creation.
    'modifieId' => null,
    'telephone' => '',

    /*
     * Le code de deux lettres du logiciel d'atelier. Facultatif, et il doit le rester :
     * tout le monde ne saisit pas dans ce logiciel, et beaucoup d'arrivées se font sans
     * qu'on le connaisse encore. Le poser ici quand on l'a évite un aller-retour par
     * l'écran des codes ; ne pas le poser n'empêche rien.
     */
    'codeAgent' => '',
]);

mount(function (?int $utilisateur = null) {
    if (! $utilisateur) {
        /*
         * Ouverture d'un accès depuis la section « Code-import ».
         *
         * Ce qu'on y a déjà noté de la personne — son nom, sa fonction, son atelier, ses
         * deux lettres — arrive par l'adresse et remplit le formulaire. Sans cela, on
         * viendrait de saisir ces informations dans un écran pour les retaper mot pour mot
         * dans le suivant, et la moitié des accès s'ouvriraient sans code faute de courage.
         *
         * Rien n'est appliqué au passage : ce sont des valeurs de départ, que l'on corrige
         * et que l'on valide comme n'importe quelle création. Le code ne sera attribué qu'à
         * l'enregistrement, par le chemin habituel.
         */
        $this->entrepriseId = (string) (request()->integer('entreprise') ?: '');
        $this->nom = trim(request()->string('nom')->value());
        $this->telephone = trim(request()->string('telephone')->value());
        $this->codeAgent = mb_strtoupper(trim(request()->string('code')->value()));
        $this->villeChoix = (string) (request()->integer('ville') ?: '');
        $this->siteChoix = (string) (request()->integer('site') ?: '');

        return;
    }

    $compte = User::withoutGlobalScopes()->find($utilisateur);

    abort_if(! $compte, 404);

    $this->modifieId = $compte->id;
    $this->entrepriseId = $compte->entreprise_id ?? '';
    $this->nom = $compte->name;
    $this->email = $compte->email;
    $this->telephone = $compte->telephone ?? '';
    $this->ouverture = $compte->est_actif ? 'actif' : 'inactif';
    $this->villeChoix = ChoixDeVille::choixActuel($compte);
    // On relit les désignations plutôt que `site_id`, nul dès que la personne répond de
    // plusieurs ateliers : le champ reviendrait vide à chaque reprise d'accès.
    $this->siteChoix = ChoixDeLieu::choixActuel($compte);

    app(PermissionRegistrar::class)->setPermissionsTeamId($compte->entreprise_id);
    $this->roleActif = $compte->getRoleNames()->first() ?? 'commercial';

    $this->codeAgent = CodeDeLAtelier::de($compte)?->code ?? '';

    $fiche = Commercial::withoutGlobalScopes()->where('user_id', $compte->id)->first();

    if ($fiche) {
        $global = (int) $fiche->objectif_mecanique + (int) $fiche->objectif_sinistre;
        $this->objectifGlobal = $global;
        $this->pourcentageMecanique = $global > 0
            ? (int) round((int) $fiche->objectif_mecanique * 100 / $global)
            : (int) (Commercial::PART_MECANIQUE_DEFAUT * 100);
    }
});

/** Vrai quand on reprend un acces au lieu d'en ouvrir un. */
$enModification = computed(fn () => $this->modifieId !== null);

/** L'acces repris, ou null. */
$compteModifie = computed(fn () => $this->modifieId
    ? User::withoutGlobalScopes()->find($this->modifieId)
    : null);

/**
 * Un acces jamais ouvert se remodele entierement ; un acces en service, non.
 *
 * Changer le role ou l'entreprise d'un compte qui a deja saisi laisserait ses
 * ecritures derriere lui, rattachees a un perimetre qui n'est plus le sien. Tant
 * qu'il est inactif et ne s'est jamais connecte, il n'a rien saisi : tout est
 * encore repris sans consequence.
 */
$structureModifiable = computed(function () {
    $compte = $this->compteModifie;

    return ! $compte || (! $compte->est_actif && $compte->derniere_connexion_le === null);
});

/**
 * Le role, lui, se reprend — et c'etait la confusion.
 *
 * Une ecriture porte un lieu et un auteur, jamais un role : une facture est rattachee a un
 * site, pas a « responsable de site ». Changer le role de quelqu'un ne deplace donc aucune
 * donnee. Le refuser obligeait a creer un second compte pour la meme personne, dont un seul
 * portait l'historique — exactement ce qu'on voulait eviter.
 *
 * Deux reserves, et elles tiennent :
 *
 * - **on ne devient pas gerant par ici.** Le gerant ne saisit nulle part et repond de toute
 *   l'entreprise : son acces se cree, il ne s'obtient pas par glissement ;
 * - **on ne demet pas le dernier gerant.** Une entreprise sans direction n'a plus personne
 *   pour nommer qui que ce soit, y compris pour reparer l'erreur.
 */
$roleModifiable = computed(function () {
    $compte = $this->compteModifie;

    if (! $compte) {
        return true;
    }

    if ($this->structureModifiable) {
        return true;
    }

    return ! $this->estLeDernierGerant;
});

/** Vrai si retirer son role a ce compte laisserait son entreprise sans gerant. */
$estLeDernierGerant = computed(function () {
    $compte = $this->compteModifie;

    if (! $compte || ! $compte->hasRole('gerant')) {
        return false;
    }

    return User::where('entreprise_id', $compte->entreprise_id)
        ->whereKeyNot($compte->id)
        ->whereHas('roles', fn ($r) => $r->where('name', 'gerant'))
        ->doesntExist();
});

/** Les roles vers lesquels on peut basculer un acces existant : jamais gerant. */
$rolesAtteignables = computed(function () {
    if ($this->structureModifiable) {
        return $this->rolesDisponibles;
    }

    return collect($this->rolesDisponibles)->except('gerant')->all();
});

$entreprises = computed(fn () => Entreprise::where('est_active', true)->orderBy('nom')->get());

/**
 * Villes de l'entreprise choisie : périmètre du responsable de ville, du commercial et de
 * la comptabilité.
 *
 * « Toutes les villes » ne s'y ajoute que pour le responsable commercial : certains
 * groupes n'ont qu'un animateur pour l'ensemble de leurs villes, et la seule façon de le
 * dire jusqu'ici était de le nommer gérant — un excès de droits pour combler un manque de
 * vocabulaire.
 */
$optionsVille = computed(fn () => $this->entrepriseId
    ? ChoixDeVille::options(
        (int) $this->entrepriseId,
        ChoixDeVille::peutCouvrirToutesLesVilles($this->roleActif),
    )
    : []);

/**
 * Lieux de l'entreprise choisie : périmètre du seul responsable de site.
 *
 * La liste porte aussi « Abidjan — tous les sites » quand une ville compte plusieurs
 * ateliers : la même personne en dirige parfois deux, et la base l'a toujours permis.
 */
$optionsSite = computed(fn () => $this->entrepriseId
    ? ChoixDeLieu::options((int) $this->entrepriseId)
    : []);

/** Sites précis de la ville choisie pour un commercial, sans rendre ce choix obligatoire. */
$optionsSiteCommercial = computed(fn () => $this->roleActif === 'commercial' && $this->villeChoix
    ? Site::withoutGlobalScopes()
        ->where('entreprise_id', (int) $this->entrepriseId)
        ->where('ville_id', (int) $this->villeChoix)
        ->where('est_actif', true)
        ->orderBy('nom')
        ->pluck('nom', 'id')
        ->all()
    : []);

/*
 * La liste des rôles suit celle que le provisionneur crée réellement en base : un rôle
 * proposé ici mais absent de l'entreprise ferait échouer l'affectation au moment
 * d'enregistrer, et un rôle créé sans être proposé resterait inattribuable.
 */
$rolesDisponibles = computed(fn () => collect(ProvisionneurEntreprise::ROLES)
    ->mapWithKeys(fn (string $role) => [$role => LibellesRoles::de($role)])
    ->all());

/** Rôles dont le titulaire prospecte : il reçoit une fiche commercial et des objectifs. */
$roleAvecObjectifs = computed(fn () => in_array($this->roleActif, ['responsable_ville', 'responsable_site', 'responsable_commercial', 'commercial'], true));

/** Répartition Mécanique/Sinistre de l'objectif global : tout lieu accueille les deux activités. */
$objectifMecanique = computed(fn () => (int) round((int) $this->objectifGlobal * ((int) $this->pourcentageMecanique) / 100));

$objectifSinistre = computed(fn () => (int) $this->objectifGlobal - $this->objectifMecanique);

$objectifAnnuel = computed(fn () => (int) $this->objectifGlobal * 12);

/**
 * Changer d'entreprise vide le perimetre.
 *
 * Une ville appartient a une entreprise : conservee telle quelle, elle designerait
 * un lieu de l'ancienne. La validation la refuserait, mais l'ecran afficherait
 * entre-temps un choix qui n'existe plus dans la liste.
 */
$updatedEntrepriseId = function () {
    $this->villeChoix = '';
    $this->siteChoix = '';
    $this->resetValidation();
};

$choisirRole = function (string $role) {
    if (! array_key_exists($role, $this->rolesAtteignables) || ! $this->roleModifiable) {
        return;
    }

    $this->roleActif = $role;
    $this->resetValidation();
};

/**
 * Reprend un acces existant.
 *
 * Le mot de passe reste facultatif : le laisser vide ne le touche pas. Le
 * remplacer ici est un geste separe, qui coupe la connexion en cours du
 * titulaire — on ne le fait pas par inadvertance en corrigeant une adresse.
 *
 * Le role, l'entreprise, le perimetre et la fiche commerciale sont repris d'un
 * bloc par l'action : changer le role sans les trois autres laissait un compte
 * incoherent — un superviseur encore inscrit comme responsable de son ancienne
 * ville, ou un commercial devenu comptable qui figurait toujours aux objectifs.
 */
$enregistrer = function (\Modules\Noyau\Entreprises\Actions\ModifierAcces $action) {
    $compte = $this->modifieId ? User::withoutGlobalScopes()->find($this->modifieId) : null;

    if (! $compte) {
        $this->confirmation = null;

        return;
    }

    $regles = [
        'nom' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($compte->id)],
        'telephone' => ['nullable', 'string', 'max:40'],
        'motDePasse' => ['nullable', 'string', 'min:8'],
        'codeAgent' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
    ];

    // L'entreprise n'est exigee que si elle est encore reprenable : figee, le champ
    // est desactive, et un navigateur n'envoie rien pour un champ desactive.
    if ($this->structureModifiable) {
        $regles['entrepriseId'] = ['required', 'exists:entreprises,id'];
    }

    if ($this->roleActif === 'responsable_site') {
        $regles['siteChoix'] = ['required', 'in:'.implode(',', array_keys($this->optionsSite))];
    } elseif ($this->roleActif !== 'gerant') {
        $regles['villeChoix'] = ['required', 'in:'.implode(',', array_keys($this->optionsVille))];

        if ($this->roleActif === 'commercial') {
            $regles['siteChoix'] = ['nullable', 'in:'.implode(',', array_keys($this->optionsSiteCommercial))];
        }
    }

    if ($this->roleAvecObjectifs) {
        $regles['objectifGlobal'] = ['required', 'numeric', 'min:0'];
        $regles['pourcentageMecanique'] = ['required', 'numeric', 'min:0', 'max:100'];
    }

    $donnees = $this->validate($regles, [], [
        'entrepriseId' => 'entreprise',
        'nom' => 'nom et prenoms',
        'email' => 'adresse e-mail',
        'telephone' => 'telephone',
        'motDePasse' => 'mot de passe',
        'codeAgent' => "code d'atelier",
        'villeChoix' => 'ville',
        'siteChoix' => 'site',
        'objectifGlobal' => 'objectif mensuel',
        'pourcentageMecanique' => 'pourcentage Mecanique',
    ]);

    $changements = $action->executer($compte, $this->roleActif, [
        'nom' => $donnees['nom'],
        'email' => $donnees['email'],
        'telephone' => $donnees['telephone'] ?? null,
        'mot_de_passe' => $donnees['motDePasse'] ?? null,
        'entreprise_id' => $donnees['entrepriseId'] ?? $compte->entreprise_id,
        'ville_id' => $donnees['villeChoix'] ?? null,
        'site_id' => $donnees['siteChoix'] ?? null,
        'objectif_mecanique' => $this->objectifMecanique,
        'objectif_sinistre' => $this->objectifSinistre,
    ], $this->structureModifiable, $this->roleModifiable);

    activity()
        ->causedBy(auth()->user())
        ->performedOn($compte)
        ->withProperties($changements)
        ->log("Reprise de l'acces de {$donnees['nom']}");

    /*
     * Le code d'atelier, après le reste.
     *
     * Il passe par le service plutôt que par l'action : lui seul journalise le geste et
     * remet la confirmation à zéro — la personne devra reconnaître son nouveau code. Le
     * super administrateur peut le reprendre à quelqu'un d'autre, c'est son rôle ;
     * l'échec ne fait pas échouer la reprise de l'accès, il se dit.
     */
    $refusDuCode = null;

    try {
        CodeDeLAtelier::attribuer($compte->refresh(), $this->codeAgent, auth()->user(), deplacerSiPris: true);
    } catch (\InvalidArgumentException $refus) {
        $refusDuCode = $refus->getMessage();
    }

    $this->motDePasse = '';
    unset($this->compteModifie, $this->structureModifiable, $this->roleModifiable,
        $this->estLeDernierGerant, $this->rolesAtteignables);

    $this->confirmation = "Acces de {$donnees['nom']} mis a jour."
        .($refusDuCode ? " Le code n'a pas ete pose : ".$refusDuCode : '')
        .($changements ? ' ('.collect($changements)->map(fn ($v, $k) => "$k : $v")->implode(' · ').')' : '');
};

$creer = function (CreerAcces $action) {
    $regles = [
        'entrepriseId' => ['required', 'exists:entreprises,id'],
        'nom' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'motDePasse' => ['required', 'string', 'min:8'],
        // Facultatif : tout le monde ne saisit pas dans le logiciel d'atelier, et une
        // arrivée se prépare souvent avant qu'on connaisse son code.
        'codeAgent' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
    ];

    if ($this->roleActif === 'responsable_site') {
        $regles['siteChoix'] = ['required', 'in:'.implode(',', array_keys($this->optionsSite))];
    } elseif ($this->roleActif !== 'gerant') {
        $regles['villeChoix'] = ['required', 'in:'.implode(',', array_keys($this->optionsVille))];

        if ($this->roleActif === 'commercial') {
            $regles['siteChoix'] = ['nullable', 'in:'.implode(',', array_keys($this->optionsSiteCommercial))];
        }
    }

    if ($this->roleAvecObjectifs) {
        $regles['objectifGlobal'] = ['required', 'numeric', 'min:0'];
        $regles['pourcentageMecanique'] = ['required', 'numeric', 'min:0', 'max:100'];
    }

    $donnees = $this->validate($regles, [], [
        'entrepriseId' => 'entreprise',
        'nom' => 'nom et prénoms',
        'email' => 'adresse e-mail',
        'motDePasse' => 'mot de passe',
        'codeAgent' => "code d'atelier",
        'villeChoix' => 'ville',
        'siteChoix' => 'site',
        'objectifGlobal' => 'objectif mensuel',
        'pourcentageMecanique' => 'pourcentage Mécanique',
    ]);

    $entreprise = Entreprise::findOrFail($donnees['entrepriseId']);

    $action->executer($entreprise, $this->roleActif, [
        'nom' => $donnees['nom'],
        'email' => $donnees['email'],
        'mot_de_passe' => $donnees['motDePasse'],
        'ville_id' => $donnees['villeChoix'] ?? null,
        'site_id' => $this->roleActif === 'commercial' ? ($donnees['siteChoix'] ?? null) : null,
        'objectif_mecanique' => $this->objectifMecanique,
        'objectif_sinistre' => $this->objectifSinistre,
        'est_actif' => $this->ouverture === 'actif',
        'code_agent' => $donnees['codeAgent'] ?? null,
    ]);

    $this->reset(['nom', 'email', 'motDePasse', 'siteChoix', 'villeChoix', 'codeAgent']);
    $this->objectifGlobal = Commercial::OBJECTIF_MENSUEL_DEFAUT;
    $this->pourcentageMecanique = (int) (Commercial::PART_MECANIQUE_DEFAUT * 100);
    $this->confirmation = "Accès créé pour {$entreprise->nom} — mot de passe à changer à la première connexion.";
};

?>

<div>
    <div class="carte">
        @if ($this->enModification)
            <h1 style="font-size:18px; font-weight:800; margin:0 0 4px;">Modifier un accès</h1>
            <p style="color:#6B6E76; font-size:15px; margin:0 0 12px;">
                {{ $this->compteModifie?->email }} — les champs sont pré-remplis. Laissez le mot de passe vide
                pour ne pas y toucher.
            </p>
            @unless ($this->structureModifiable)
                {{-- Trois verrous distincts, et non un seul : l'ancien message les confondait
                     et interdisait bien plus qu'il ne fallait. --}}
                <div class="encart encart-info">
                    <strong>Cet accès a déjà servi.</strong>
                    <ul style="margin:7px 0 0; padding-left:20px; line-height:1.6;">
                        <li><strong>L'entreprise est figée</strong> — ses écritures y sont rattachées, et le
                            déplacer les laisserait derrière lui.</li>
                        @if ($this->estLeDernierGerant)
                            <li><strong>Le rôle est figé</strong> — c'est le dernier gérant de cette entreprise :
                                la démettre la laisserait sans direction.</li>
                        @else
                            <li><strong>Le rôle reste modifiable</strong> — une écriture porte un lieu et un
                                auteur, jamais un rôle. Le changer ne déplace rien de ce qui a été saisi.
                                Le rôle de gérant, lui, ne s'obtient pas par ici : cet accès se crée.</li>
                        @endif
                        <li><strong>La ville et l'atelier</strong> se changent par une
                            <em>réaffectation</em> — Paramètres → Personnel — qui garde l'historique et laisse
                            à l'intéressé la lecture de son ancien poste.</li>
                    </ul>
                </div>
            @endunless
            <a href="{{ route('super-admin.acces.index') }}" wire:navigate
                style="display:inline-block; font-size:13px; color:var(--th-gris,#6B6E76); margin-bottom:14px;">‹ Retour à la liste des accès</a>
        @else
            <h1 style="font-size:18px; font-weight:800; margin:0 0 4px;">Créer un accès</h1>
            <p style="color:#6B6E76; font-size:15px; margin:0 0 18px;">Provisionnez un compte pour n'importe quel rôle, dans n'importe quelle entreprise cliente.</p>
        @endif

        <div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
            @foreach ($this->rolesAtteignables as $cle => $libelle)
                <button type="button" wire:click="choisirRole('{{ $cle }}')" @disabled(! $this->roleModifiable)
                    style="padding:9px 16px; border-radius:8px; font-size:14.5px; font-weight:700;
                           cursor:{{ $this->roleModifiable ? 'pointer' : 'not-allowed' }};
                           border:2px solid {{ $roleActif === $cle ? '#C8102E' : '#E2E0D8' }};
                           background:{{ $roleActif === $cle ? '#FDF2F4' : '#fff' }};
                           color:{{ $roleActif === $cle ? '#C8102E' : '#4B4E55' }};">
                    {{ $libelle }}
                </button>
            @endforeach
        </div>

        @if ($confirmation)
            <div style="background:#EAF9F3; border:1px solid #0E9F6E55; color:#0E9F6E; border-radius:8px; padding:10px 12px; font-size:14.5px; margin-bottom:16px;">
                {{ $confirmation }}
            </div>
        @endif

        <form wire:submit="{{ $this->enModification ? 'enregistrer' : 'creer' }}" style="max-width:480px;">
            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin-bottom:6px;">Entreprise</label>
            {{-- .live : les villes et les lieux proposes plus bas dependent de
                 l'entreprise choisie. Differee, la liste serait restee celle de
                 l'entreprise precedente jusqu'au prochain aller-retour. --}}
            <select wire:model.live="entrepriseId" @disabled(! $this->structureModifiable)
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px; margin-bottom:4px; {{ $this->structureModifiable ? '' : 'background:#F1EFE9; color:#6B6E76;' }}">
                <option value="" @selected($entrepriseId === '')>— Choisir une entreprise —</option>
                @foreach ($this->entreprises as $entreprise)
                    <option value="{{ $entreprise->id }}" @selected((string) $entrepriseId === (string) $entreprise->id)>{{ $entreprise->nom }}</option>
                @endforeach
            </select>
            @error('entrepriseId') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror

            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Nom et prénoms</label>
            <input type="text" wire:model="nom" value="{{ $nom }}"
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px; margin-bottom:4px;">
            @error('nom') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror

            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Adresse e-mail</label>
            <input type="email" wire:model="email"
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px; margin-bottom:4px;">
            @error('email') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror

            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Téléphone</label>
            <input type="text" wire:model="telephone" value="{{ $telephone }}" placeholder="+225 07 ..."
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px; margin-bottom:4px;">
            @error('telephone') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror

            {{-- Le code de deux lettres du logiciel d'atelier.

                 **Facultatif, et il doit le rester.** Tout le monde ne saisit pas dans le
                 logiciel, et une arrivée se prépare souvent avant qu'on connaisse son code.
                 Le poser ici quand on l'a évite un détour par l'écran des codes ; ne pas le
                 poser n'empêche rien, il se rattache plus tard.

                 Ce qui suit sa saisie : la personne verra la question à son prochain écran,
                 « est-ce bien votre code ? ». C'est elle qui tranche, pas nous. --}}
            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">
                Code d'atelier <span style="font-weight:400; color:#6B6E76;">(facultatif)</span>
            </label>
            <input type="text" wire:model="codeAgent" value="{{ $codeAgent }}" maxlength="2" placeholder="KZ"
                pattern="[A-Za-z]{2}" autocomplete="off"
                style="width:110px; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8;
                       border-radius:8px; font-size:15.5px; margin-bottom:4px; text-transform:uppercase;
                       letter-spacing:2px; font-weight:700;">
            @error('codeAgent') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror
            <div style="font-size:12.5px; color:#6B6E76; line-height:1.5; margin-bottom:4px;">
                Les deux lettres par lesquelles le logiciel de l'atelier désigne cette personne, au
                milieu de ses numéros de fiche&nbsp;:
                <span style="font-family:ui-monospace,Consolas,monospace;">FR-<b>KZ</b>N° 010669</span>.
                Laissez vide si elle n'y saisit pas, ou si vous ne le connaissez pas encore.
            </div>

            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">
                {{ $this->enModification ? 'Nouveau mot de passe (facultatif)' : 'Mot de passe provisoire' }}
            </label>
            <input type="password" wire:model="motDePasse"
                placeholder="{{ $this->enModification ? 'Laisser vide pour ne pas y toucher' : '' }}"
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px; margin-bottom:4px;">
            @error('motDePasse') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror

            {{-- Un accès préparé n'est pas un accès ouvert. Créé inactif, il existe avec
                 son rôle et son périmètre, mais son titulaire n'en sait rien : aucun
                 courriel ne part, et la connexion lui est refusée. C'est le brouillon
                 d'un accès — utile quand on prépare une arrivée à l'avance. --}}
            <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Ouverture de l'accès</label>
            <select wire:model.live="ouverture" style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px;">
                <option value="actif" @selected((string) $ouverture === 'actif')>Actif — courriel envoyé, le titulaire peut se connecter</option>
                <option value="inactif" @selected((string) $ouverture === 'inactif')>Inactif — accès préparé, aucun courriel, connexion refusée</option>
            </select>
            <p style="font-size:11.5px; color:#9A9DA5; margin:5px 0 0;">
                Le courriel de bienvenue partira le jour où vous activerez l'accès, pas avant.
            </p>

            {{-- Un responsable de site répond d'un lieu précis ; le responsable de ville,
                 le commercial et la comptabilité couvrent une ville entière. --}}
            @if ($roleActif === 'responsable_site')
                <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Site (lieu dont il répond)</label>
                <select wire:model="siteChoix" style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px;">
                    <option value="" @selected($siteChoix === '')>— Choisir un site —</option>
                    @foreach ($this->optionsSite as $id => $nom)
                        <option value="{{ $id }}" @selected((string) $siteChoix === (string) $id)>{{ $nom }}</option>
                    @endforeach
                </select>
                @error('siteChoix') <div style="color:#C8102E; font-size:13.5px; margin-top:6px;">{{ $message }}</div> @enderror
            @elseif ($roleActif !== 'gerant')
                <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Ville</label>
                <select wire:model.live="villeChoix" style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px;">
                    <option value="" @selected($villeChoix === '')>— Choisir une ville —</option>
                    @foreach ($this->optionsVille as $id => $nom)
                        <option value="{{ $id }}" @selected((string) $villeChoix === (string) $id)>{{ $nom }}</option>
                    @endforeach
                </select>
                @error('villeChoix') <div style="color:#C8102E; font-size:13.5px; margin-top:6px;">{{ $message }}</div> @enderror

                @if ($roleActif === 'commercial' && count($this->optionsSiteCommercial) > 1)
                    <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">
                        Site <span style="font-weight:400; color:#6B6E76;">(facultatif)</span>
                    </label>
                    <select wire:model="siteChoix" style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px;">
                        <option value="" @selected($siteChoix === '')>— Toute la ville —</option>
                        @foreach ($this->optionsSiteCommercial as $id => $nom)
                            <option value="{{ $id }}" @selected((string) $siteChoix === (string) $id)>{{ $nom }}</option>
                        @endforeach
                    </select>
                    @error('siteChoix') <div style="color:#C8102E; font-size:13.5px; margin-top:6px;">{{ $message }}</div> @enderror
                @endif
            @endif

            @if ($this->roleAvecObjectifs)
                {{-- Les responsables prospectent eux aussi : ils apparaissent parmi les
                     commerciaux et portent donc leurs propres objectifs. --}}
                <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:14px 0 6px;">Objectif mensuel global (FCFA)</label>
                <input type="number" wire:model.live="objectifGlobal" value="{{ $objectifGlobal }}"
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px;">
                @error('objectifGlobal') <div style="color:#C8102E; font-size:13.5px; margin-top:6px;">{{ $message }}</div> @enderror

                <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Répartition — % Mécanique (le reste va au Sinistre)</label>
                <input type="number" wire:model.live="pourcentageMecanique" value="{{ $pourcentageMecanique }}" min="0" max="100"
                    style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #E2E0D8; border-radius:8px; font-size:15.5px;">
                @error('pourcentageMecanique') <div style="color:#C8102E; font-size:13.5px; margin-top:6px;">{{ $message }}</div> @enderror

                <div style="display:flex; gap:16px; margin-top:10px; padding:10px 12px; background:#F9F9F7; border-radius:8px; font-size:13px; color:#4B4E55; flex-wrap:wrap;">
                    <span>Mécanique : <b>{{ ae($this->objectifMecanique) }}</b> ({{ $pourcentageMecanique }}%)</span>
                    <span>Sinistre : <b>{{ ae($this->objectifSinistre) }}</b> ({{ 100 - (int) $pourcentageMecanique }}%)</span>
                    <span>Équivalent annuel : <b>{{ ae($this->objectifAnnuel) }}</b></span>
                </div>
            @endif

            <button type="submit" wire:loading.attr="disabled"
                style="background:#C8102E; color:#fff; border:0; border-radius:8px; padding:10px 20px; font-weight:700; font-size:15.5px; cursor:pointer; margin-top:18px;">
                {{ $this->enModification ? 'Enregistrer les modifications' : "+ Créer l'accès" }}
            </button>
        </form>
    </div>
</div>

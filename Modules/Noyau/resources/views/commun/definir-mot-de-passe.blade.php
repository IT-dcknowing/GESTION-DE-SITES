<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use function Livewire\Volt\{state, mount, layout, title};

// Mise en page nue, sans la navigation de l'application : on n'y est pas encore entre.
// Afficher les menus autour d'un ecran de creation de mot de passe donnait a croire
// que le compte etait deja ouvert.
layout('layouts.guest');
title('Choisir mon mot de passe');

/*
|--------------------------------------------------------------------------
| Première connexion — choisir son mot de passe
|--------------------------------------------------------------------------
| On arrive ici par le lien du courriel d'accueil, et par lui seul : l'adresse
| est signée avec la clé de l'application, donc infalsifiable, et valable une
| semaine. Personne n'a encore de mot de passe à ce stade — le demander serait
| absurde, c'est justement ce qu'on vient créer.
|
| Trois gardes se cumulent :
|   - la signature de l'adresse, vérifiée par le middleware « signed » ;
|   - le drapeau « doit changer son mot de passe », qui retombe dès que le
|     mot de passe est choisi : le lien ne resservira pas une seconde fois ;
|   - la relecture de ce drapeau au moment d'enregistrer, et non seulement à
|     l'affichage, pour qu'un appel forgé ne rejoue pas un lien déjà consommé.
|
| Une fois le mot de passe choisi, on ne connecte pas d'office : l'utilisateur
| repasse par la page de connexion et s'en sert tout de suite. Le geste ancre
| le mot de passe et vérifie qu'il fonctionne, tant qu'on l'a encore en tête.
*/

state([
    'utilisateurId' => null,
    'entreprise' => null,
    'nom' => '',
    'email' => '',
    'lienDejaUtilise' => false,
    'nouveauMotDePasse' => '',
    'nouveauMotDePasse_confirmation' => '',
]);

mount(function (int $utilisateur) {
    $compte = User::find($utilisateur);

    // Signature valide mais compte disparu : rien à proposer.
    abort_if(! $compte, 404);

    $this->utilisateurId = $compte->id;
    $this->nom = $compte->name;
    $this->email = $compte->email;
    $this->lienDejaUtilise = ! $compte->doit_changer_mot_de_passe;

    // Le titulaire doit reconnaître la maison avant de saisir quoi que ce soit :
    // une page nue qui réclame un mot de passe ressemble à un piège.
    $this->entreprise = $compte->entreprise()->withoutGlobalScopes()->first();
});

$enregistrer = function () {
    $compte = User::where('doit_changer_mot_de_passe', true)->find($this->utilisateurId);

    if (! $compte) {
        // Le lien a déjà servi entre l'affichage et l'envoi, ou l'appel est forgé.
        $this->lienDejaUtilise = true;

        return;
    }

    $donnees = $this->validate([
        'nouveauMotDePasse' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
    ], [], ['nouveauMotDePasse' => 'mot de passe']);

    $compte->forceFill([
        'password' => Hash::make($donnees['nouveauMotDePasse']),
        'doit_changer_mot_de_passe' => false,
    ])->save();

    $this->reset(['nouveauMotDePasse', 'nouveauMotDePasse_confirmation']);

    // Le poste est peut-être partagé, et une session ouverte au nom de quelqu'un
    // d'autre n'a rien à faire ici : on repart d'une page de connexion propre.
    if (Auth::check()) {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
    }

    session()->flash('status', 'Votre mot de passe est enregistré. Connectez-vous avec.');

    $this->redirectRoute('login', navigate: false);
};

?>

{{-- Page à part entière, hors de l'application : ni menu, ni bandeau d'exercice.
     On n'est pas encore entré, et l'écran ne doit pas laisser croire le contraire. --}}
<div style="min-height:100vh; background:var(--th-paper,#F4F3EF); display:flex; align-items:center;
            justify-content:center; padding:32px 20px; box-sizing:border-box;">
    <div class="carte" style="width:100%; max-width:460px; padding:30px; box-shadow:0 18px 44px rgba(25,27,32,.10);">

        <div style="text-align:center; margin-bottom:22px;">
            @if ($entreprise?->logoUrl())
                <img src="{{ $entreprise->logoUrl() }}" alt="{{ $entreprise->nom }}" style="height:52px; margin:0 auto 12px; display:block;">
            @endif
            <p style="font-family:'Barlow Condensed',sans-serif; font-size:15px; font-weight:700; text-transform:uppercase;
                      letter-spacing:1.4px; margin:0; color:var(--th-gris,#6B6E76);">
                {{ $entreprise?->nom ?? config('app.name') }}
            </p>
        </div>

        @if ($lienDejaUtilise)
            <h1 style="font-size:20px; font-weight:800; margin:0 0 6px;">Mot de passe déjà choisi</h1>
            <p style="color:#6B6E76; font-size:15px; margin:0 0 18px;">
                Ce lien a déjà servi. Connectez-vous avec le mot de passe que vous avez enregistré.
                Si vous l'avez oublié, demandez à votre administrateur d'en ouvrir un nouveau.
            </p>
            <a href="{{ route('login') }}" class="bouton">Aller à la connexion</a>
        @else
            <h1 style="font-size:20px; font-weight:800; margin:0 0 6px;">Choisissez votre mot de passe</h1>
            <p style="color:#6B6E76; font-size:15px; margin:0 0 4px;">
                Bienvenue {{ $nom }}. Votre accès est ouvert au nom de
                <strong>{{ $email }}</strong>.
            </p>
            <p style="color:#6B6E76; font-size:13.5px; margin:0 0 18px;">
                Au moins 8 caractères, dont des lettres et des chiffres. Vous serez ensuite invité
                à vous connecter avec.
            </p>

            <form wire:submit="enregistrer">
                <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin-bottom:6px;">Nouveau mot de passe</label>
                <div class="champ-mot-de-passe">
                    <input type="password" wire:model="nouveauMotDePasse" class="champ" autocomplete="new-password" autofocus>
                    <x-oeil-mot-de-passe />
                </div>
                @error('nouveauMotDePasse') <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div> @enderror

                <label style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">Confirmer le mot de passe</label>
                <div class="champ-mot-de-passe">
                    <input type="password" wire:model="nouveauMotDePasse_confirmation" class="champ" autocomplete="new-password">
                    <x-oeil-mot-de-passe />
                </div>

                <button type="submit" wire:loading.attr="disabled" class="bouton" style="margin-top:14px;">
                    Enregistrer et aller à la connexion
                </button>
            </form>
        @endif
    </div>
</div>

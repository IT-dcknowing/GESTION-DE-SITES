<?php

use function Livewire\Volt\{state};

/*
|--------------------------------------------------------------------------
| Changer son mot de passe
|--------------------------------------------------------------------------
| L'écran n'a plus d'action à lui : il montre, et le formulaire poste vers
| ChangerLeMotDePasse, où sont écrites les deux règles qui comptent — le mot de
| passe actuel exigé au changement volontaire, et pas au premier passage imposé.
|
| Ce qu'il reste ici de Volt n'est qu'une lecture : le drapeau qui dit lequel des
| deux passages on est en train de faire.
*/

state([
    'premiereConnexion' => fn () => (bool) auth()->user()->doit_changer_mot_de_passe,
]);

?>

<div style="max-width:480px; margin:40px auto;">
    <div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:26px;">
        <h1 style="font-size:20px; font-weight:800; margin:0 0 6px;">
            {{ $premiereConnexion ? 'Choisir mon mot de passe' : 'Changer mon mot de passe' }}
        </h1>

        @if ($premiereConnexion)
            <p style="color:#D97706; font-size:14.5px; margin:0 0 18px; background:#FFFBEA; border:1px solid #D9770655; border-radius:8px; padding:10px 12px;">
                Votre accès a été ouvert avec un mot de passe provisoire. Choisissez le vôtre
                pour continuer&nbsp;: il remplacera le provisoire, qui cessera aussitôt de valoir.
            </p>
        @else
            <p style="color:#6B6E76; font-size:15px; margin:0 0 18px;">Choisissez un mot de passe d'au moins 8 caractères.</p>
        @endif

        <form method="POST" action="{{ route('mot-de-passe.enregistrer') }}">
            @csrf

            {{-- Le mot de passe actuel n'est demandé qu'au changement volontaire. Au premier
                 passage, il vient d'être saisi à la connexion : le redemander ne prouve rien
                 et fait retenir un mot de passe qu'on est en train de remplacer. --}}
            @unless ($premiereConnexion)
                <label for="mdp-actuel" style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin-bottom:6px;">
                    Mot de passe actuel
                </label>
                <div class="champ-mot-de-passe">
                    <input type="password" id="mdp-actuel" name="motDePasseActuel"
                           class="champ" autocomplete="current-password" required>
                    <x-oeil-mot-de-passe />
                </div>
                @error('motDePasseActuel')
                    <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div>
                @enderror
            @endunless

            <label for="mdp-nouveau" style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">
                Nouveau mot de passe
            </label>
            <div class="champ-mot-de-passe">
                <input type="password" id="mdp-nouveau" name="nouveauMotDePasse"
                       class="champ" autocomplete="new-password" minlength="8" required autofocus>
                <x-oeil-mot-de-passe />
            </div>
            @error('nouveauMotDePasse')
                <div style="color:#C8102E; font-size:13.5px; margin-bottom:8px;">{{ $message }}</div>
            @enderror

            <label for="mdp-confirme" style="display:block; font-size:14px; font-weight:600; color:#4B4E55; margin:10px 0 6px;">
                Confirmer le nouveau mot de passe
            </label>
            <div class="champ-mot-de-passe">
                <input type="password" id="mdp-confirme" name="nouveauMotDePasse_confirmation"
                       class="champ" autocomplete="new-password" minlength="8" required>
                <x-oeil-mot-de-passe />
            </div>

            <div style="font-size:12.5px; color:#6B6E76; margin:10px 0 4px; line-height:1.55;">
                Huit caractères au minimum. Un mot de passe déjà utilisé ici est refusé&nbsp;:
                le provisoire doit cesser de fonctionner.
            </div>

            <button type="submit" class="bouton">
                Enregistrer le nouveau mot de passe
            </button>
        </form>
    </div>
</div>

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

/**
 * Connexion Google : les comptes sont provisionnés par un Gérant, un Responsable
 * ou le Super Admin — Google ne sert qu'à authentifier une adresse déjà connue,
 * jamais à créer un compte de toutes pièces.
 */
class GoogleAuthController extends Controller
{
    public function rediriger(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        /*
         * Un retour de Google après une session expirée, un bouton « précédent » ou un
         * second onglet ne porte plus le jeton attendu : Socialite lève alors
         * `InvalidStateException`. Ce n'est pas une panne — ERR-F3QP71, le 14/09, en était
         * une dans la liste des incidents —, c'est une connexion à recommencer.
         */
        try {
            $compteGoogle = Socialite::driver('google')->user();
        } catch (\Laravel\Socialite\Two\InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'email' => 'La connexion Google a expiré. Cliquez de nouveau sur « Se connecter avec Google ».',
            ]);
        }

        $utilisateur = User::where('google_id', $compteGoogle->getId())
            ->orWhere('email', $compteGoogle->getEmail())
            ->first();

        if (! $utilisateur) {
            return redirect()->route('login')->withErrors([
                'email' => 'Aucun compte trouvé pour cette adresse Google. Contactez votre administrateur.',
            ]);
        }

        if (! $utilisateur->est_actif || ($utilisateur->entreprise_id && ! $utilisateur->entreprise?->est_active)) {
            return redirect()->route('login')->withErrors([
                'email' => 'Cet accès a été désactivé. Contactez votre administrateur.',
            ]);
        }

        if (! $utilisateur->google_id) {
            $utilisateur->forceFill(['google_id' => $compteGoogle->getId()])->save();
        }

        $utilisateur->forceFill(['derniere_connexion_le' => now()])->save();

        auth()->login($utilisateur, remember: true);

        return redirect()->intended(route('redirection'));
    }
}

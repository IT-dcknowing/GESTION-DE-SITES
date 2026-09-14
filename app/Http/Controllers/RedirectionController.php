<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * Point d'entrée unique après connexion : chaque rôle a son écran d'atterrissage.
 */
class RedirectionController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $utilisateur = auth()->user();

        $destination = match (true) {
            $utilisateur->hasRole('super_admin') => route('super-admin.dashboard'),
            $utilisateur->hasRole('gerant') => route('tableau-de-bord'),
            // Le responsable commercial atterrit là aussi : c'est le premier onglet de
            // son bandeau, et un atterrissage doit tomber sur l'écran qu'on lui montre.
            $utilisateur->hasRole('responsable_ville'),
            $utilisateur->hasRole('responsable_site'),
            $utilisateur->hasRole('responsable_commercial') => route('saisie-du-jour'),
            $utilisateur->hasRole('commercial') => route('ma-performance'),
            $utilisateur->hasRole('caissier') => route('caissier.tableau-de-bord'),
            // Les deux rôles du recouvrement atterrissent sur la première page qui leur
            // est ouverte : l'agent sur la Saisie, comme le superviseur. Passer par
            // AccesRecouvrement plutôt que d'écrire la route en dur évite qu'un
            // changement d'habilitation demain fasse atterrir quelqu'un sur un 403.
            $utilisateur->hasRole('superviseur_recouvrement'), $utilisateur->hasRole('agent_recouvrement')
                => route('recouvrement.'.(\Modules\Recouvrement\Support\AccesRecouvrement::pagesDe($utilisateur)[0] ?? 'saisie')),
            default => null,
        };

        if ($destination) {
            return redirect($destination);
        }

        // Sans rôle reconnu, renvoyer vers /connexion en laissant la session active
        // boucle indéfiniment : Fortify renvoie tout utilisateur déjà connecté qui
        // retombe sur /login droit vers cette même route. Se déconnecter casse la
        // boucle et affiche un message exploitable au lieu d'un ERR_TOO_MANY_REDIRECTS.
        auth()->guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('connexion')->withErrors(['email' => "Aucun rôle n'est associé à ce compte. Contactez votre administrateur."]);
    }
}

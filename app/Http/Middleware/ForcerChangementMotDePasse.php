<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un accès créé par un administrateur (Gérant, Responsable, Super Admin) ou dont le mot de
 * passe a été réinitialisé de force doit être changé avant tout accès au reste de l'application.
 */
class ForcerChangementMotDePasse
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        // Seules les navigations complètes (GET) sont redirigées. L'enregistrement du
        // nouveau mot de passe est un POST vers cette même adresse : le renvoyer ici
        // bouclerait, et le formulaire ne pourrait jamais aboutir.
        if ($utilisateur
            && $utilisateur->doit_changer_mot_de_passe
            && $request->isMethod('GET')
            && ! $request->routeIs('mot-de-passe.modifier')
            // Le lien signé du courriel d'accueil mène ici : c'est déjà un écran de
            // choix de mot de passe, le renvoyer vers l'autre ferait réclamer un mot
            // de passe actuel que le titulaire n'a précisément pas encore.
            && ! $request->routeIs('mot-de-passe.definir')
            && ! $request->routeIs('logout')
        ) {
            return redirect()->route('mot-de-passe.modifier');
        }

        return $next($request);
    }
}

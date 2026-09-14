<?php

namespace Modules\Recouvrement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Recouvrement\Support\AccesRecouvrement;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme une page du recouvrement à qui n'y a pas droit.
 *
 * Le middleware de rôle dit qui entre dans le module ; celui-ci dit jusqu'où. Sans lui,
 * un agent qui devine l'adresse `/recouvrement/audit` lirait le journal de ses propres
 * gestes — l'onglet est grisé dans la barre latérale, mais une barre latérale n'a jamais
 * fermé une URL.
 *
 * Le refus renvoie vers la première page ouverte plutôt que d'afficher une erreur : la
 * personne n'a rien fait de mal, elle a suivi un lien ou un favori devenu hors de son
 * périmètre.
 */
class VerifiePageRecouvrement
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $utilisateur = $request->user();

        if (AccesRecouvrement::peutVoir($utilisateur, $page)) {
            return $next($request);
        }

        $ouvertes = AccesRecouvrement::pagesDe($utilisateur);

        if ($ouvertes === []) {
            abort(403, "Le recouvrement ne vous est pas ouvert.");
        }

        return redirect()
            ->route('recouvrement.'.$ouvertes[0])
            ->with('refus-recouvrement', 'Cette vue relève d\'une habilitation supérieure à la vôtre.');
    }
}

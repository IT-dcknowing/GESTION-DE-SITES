<?php

namespace Modules\Import\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Import\Support\AccesImport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme une page de l'import à qui n'y a pas droit.
 *
 * Le middleware de rôle dit qui entre dans le module ; celui-ci dit jusqu'où. Sans lui, un
 * responsable de site qui devine l'adresse `/import/codes` déciderait de quel atelier
 * relève chaque fiche — dont le sien. L'onglet est grisé dans la barre latérale, mais une
 * barre latérale n'a jamais fermé une URL.
 *
 * Le refus renvoie vers la première page ouverte plutôt que d'afficher une erreur : la
 * personne n'a rien fait de mal, elle a suivi un lien devenu hors de son périmètre.
 */
class VerifiePageImport
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $utilisateur = $request->user();

        if (AccesImport::peutVoir($utilisateur, $page)) {
            return $next($request);
        }

        $premiere = AccesImport::premierePage($utilisateur);

        if ($premiere === null) {
            abort(403, "L'import ne vous est pas ouvert.");
        }

        return redirect()
            ->route('import.'.$premiere)
            ->with('refus-import', "Cette vue relève d'une habilitation supérieure à la vôtre.");
    }
}

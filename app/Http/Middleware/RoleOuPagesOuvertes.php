<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Noyau\Entreprises\Support\CatalogueDesPages;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le middleware `role:` de Spatie, qui laisse aussi entrer un rôle configuré sur ses pages — 22/10.
 *
 * Les routes nomment les rôles du métier qu'elles admettent (`role:gerant|caissier`) ; un rôle
 * créé dans la Maintenance n'y figure pas, et ne peut pas y figurer sans réécrire chaque route à
 * chaque case cochée. On garde donc la règle de Spatie telle quelle, et l'on ajoute une seule
 * porte : la route est l'une des pages cochées pour le rôle du compte (`CatalogueDesPages::ouvre`).
 * La décision se lit sur l'identité du lecteur et le nom de la route, jamais sur un paramètre reçu.
 *
 * Il délègue au middleware de Spatie plutôt que d'en hériter : sa signature change d'une version
 * à l'autre, et un héritage qui ne la recopie pas exactement casse au chargement.
 */
class RoleOuPagesOuvertes
{
    public function handle(Request $request, Closure $next, mixed ...$arguments): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur !== null && CatalogueDesPages::ouvre($utilisateur, $request->route()?->getName())) {
            return $next($request);
        }

        return app(RoleMiddleware::class)->handle($request, $next, ...$arguments);
    }
}

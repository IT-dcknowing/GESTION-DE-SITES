<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Les proxys de confiance, lus dans la configuration **au moment de la requête**.
 *
 * Ils étaient lus par `env()` dans `bootstrap/app.php`. Deux défauts à la fois : à cet
 * instant le `.env` n'est pas encore chargé — la valeur retombait donc toujours sur « * » —,
 * et ce seul `env()` interdisait `php artisan config:cache`, alors qu'une configuration en
 * cache évite de relire une quarantaine de fichiers à chaque page. Mesuré le 12/10 : sur la
 * base de test, une page coûte 10 à 40 ms à l'application ; les cinq secondes vues en ligne
 * viennent de ce que le serveur refait à chaque requête, et c'est l'une de ces choses.
 *
 * Même règle qu'avant : absente ou vide, la valeur vaut « * » — une ligne
 * « PROXYS_DE_CONFIANCE= » oubliée ne peut pas ramener la boucle de redirection.
 */
class FaireConfianceAuxProxys extends TrustProxies
{
    protected function proxies()
    {
        $proxys = config('app.proxys_de_confiance') ?: '*';

        return $proxys === '*' ? '*' : array_map('trim', explode(',', (string) $proxys));
    }
}

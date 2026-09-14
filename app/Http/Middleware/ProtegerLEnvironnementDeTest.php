<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un serveur de test ouvert sur Internet porte de vraies données clients.
 *
 * **Le problème.** Le serveur de développement travaille sur une copie de la production :
 * les mêmes noms de clients, les mêmes montants, les mêmes téléphones. Il vit sur une
 * adresse publique, et il affiche volontiers ses erreurs puisque c'est son métier. Sans
 * porte d'entrée, c'est la production en clair pour qui connaît l'adresse.
 *
 * **Pourquoi pas un .htaccess.** C'était la réponse évidente, et elle avait deux défauts
 * ici : l'hébergeur ne propose pas l'outil, et `public/.htaccess` est suivi par git — le
 * modifier sur le serveur créerait un conflit à chaque mise à jour, jusqu'au jour où
 * quelqu'un l'écraserait pour s'en débarrasser. Une protection qu'un déploiement peut
 * effacer n'en est pas une. Celle-ci est dans le dépôt, elle suit le code.
 *
 * **Trois règles, dans cet ordre.**
 *
 * 1. En production, ce filtre ne fait rien. L'application a sa propre page de connexion,
 *    et un second mot de passe devant n'ajouterait qu'une gêne.
 * 2. Depuis une adresse locale ou privée, il ne fait rien non plus : c'est le poste de
 *    développement, derrière lequel il n'y a personne d'autre que soi.
 * 3. Partout ailleurs — c'est-à-dire depuis Internet —, il exige un mot de passe.
 *
 * **Et s'il n'est pas configuré, il ferme.** C'est délibéré. Un oubli de configuration
 * doit se voir tout de suite, pas rester invisible jusqu'au jour où quelqu'un tombe sur
 * l'adresse. Le message dit exactement quoi ajouter.
 */
class ProtegerLEnvironnementDeTest
{
    public function handle(Request $requete, Closure $suite): Response
    {
        if (app()->environment('production') || $this->vientDuReseauLocal($requete)) {
            return $suite($requete);
        }

        $attendu = (string) config('app.acces_test.utilisateur');
        $secret = (string) config('app.acces_test.mot_de_passe');

        if ($attendu === '' || $secret === '') {
            return response(
                "Cet environnement de test n'a pas de mot de passe et porte des données réelles. "
                ."Ajoutez ACCES_TEST_UTILISATEUR et ACCES_TEST_MOT_DE_PASSE dans le fichier .env, "
                ."puis lancez « php artisan config:clear ».",
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        // hash_equals plutôt que « === » : la comparaison prend le même temps quelle que
        // soit l'erreur, et ne laisse donc rien deviner à qui mesure les réponses.
        $utilisateurOk = hash_equals($attendu, (string) $requete->getUser());
        $motDePasseOk = hash_equals($secret, (string) $requete->getPassword());

        if ($utilisateurOk && $motDePasseOk) {
            return $suite($requete);
        }

        // L'en-tête WWW-Authenticate est ce qui déclenche la fenêtre du navigateur : sans
        // lui, le visiteur recevrait un 401 nu, sans moyen de s'identifier.
        return response('Environnement de test — accès réservé.', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="Environnement de test", charset="UTF-8"',
        ]);
    }

    /**
     * La requête vient-elle du poste lui-même, ou du réseau local ?
     *
     * Sans cette porte, le poste de développement sous Laragon — qui tourne aussi en
     * APP_ENV=local — se retrouverait à demander un mot de passe à son propre développeur.
     */
    private function vientDuReseauLocal(Request $requete): bool
    {
        $ip = (string) $requete->ip();

        if ($ip === '') {
            return false;
        }

        // filter_var renvoie false quand l'adresse est privée ou réservée : c'est
        // précisément le cas qu'on cherche, d'où la négation.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}

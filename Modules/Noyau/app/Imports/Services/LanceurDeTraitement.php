<?php

namespace Modules\Noyau\Imports\Services;

use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Modeles\LotImport;
use Throwable;

/**
 * Lance la lecture d'un fichier déposé — tout de suite, sans attendre personne.
 *
 * **Le défaut qu'il corrige.** Le dépôt mettait le travail en file d'attente et rendait la
 * main. Encore fallait-il qu'un exécuteur vide cette file : sur le poste local il n'y en a pas,
 * et sur les serveurs la tâche planifiée ne passe qu'une fois par minute. Le dépôt réussissait,
 * l'écran disait « Déposé », la barre ne bougeait pas — et il fallait aller cliquer « Traiter
 * maintenant » ou « Tout traiter » pour que quelque chose se passe. Un bouton qui sert à faire
 * ce qu'on vient de demander n'est pas un bouton, c'est un défaut qu'on a laissé à l'écran.
 *
 * **Ce qui se passe désormais, dans l'ordre.**
 *
 * 1. Le travail est mis en file, comme avant. C'est le filet : si rien d'autre ne le prend, la
 *    tâche planifiée du serveur le prendra.
 * 2. La lecture démarre aussitôt dans un **processus à part** — `php artisan import:traiter-lot`
 *    — détaché de la requête. La page rend la main tout de suite, et la barre avance pendant
 *    qu'on regarde. C'est le seul mécanisme qui marche aussi bien sur le poste local
 *    (`artisan serve` ne sert qu'une requête à la fois) que sur un hébergement mutualisé.
 * 3. Si l'hébergement interdit de lancer un processus, la lecture se fait **après l'envoi de
 *    la réponse**, dans le même processus PHP.
 *
 * Le fichier n'est jamais lu deux fois : le premier arrivé prend le lot, les autres le
 * trouvent pris — voir `SuiviDuTraitement::prendre()`.
 */
final class LanceurDeTraitement
{
    public static function lancer(LotImport $lot, bool $ecrire = true): void
    {
        TraiterUnLot::dispatch($lot, $ecrire);

        // Les tests pilotent la file eux-mêmes ; une commande a déjà son processus.
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            return;
        }

        if (self::detacher((int) $lot->id, $ecrire)) {
            return;
        }

        TraiterUnLot::dispatchAfterResponse($lot, $ecrire);
    }

    /**
     * Démarre `import:traiter-lot` sans l'attendre.
     *
     * Les arguments sont un identifiant entier et des chemins de l'application, tous passés par
     * `escapeshellarg` : rien de ce qu'envoie le navigateur n'entre dans la ligne de commande.
     */
    private static function detacher(int $lotId, bool $ecrire): bool
    {
        $php = self::phpEnLigneDeCommande();

        if ($php === null) {
            return false;
        }

        $arguments = [$php, base_path('artisan'), 'import:traiter-lot', (string) $lotId];

        if (! $ecrire) {
            $arguments[] = '--controle';
        }

        $commande = implode(' ', array_map('escapeshellarg', $arguments));

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                if (! self::permise('popen')) {
                    return false;
                }

                // `start /B` rend la main aussitôt ; le processus lancé continue seul.
                $flux = popen('start "" /B '.$commande.' > NUL 2>&1', 'r');

                if ($flux === false) {
                    return false;
                }

                pclose($flux);

                return true;
            }

            if (! self::permise('exec')) {
                return false;
            }

            exec($commande.' > /dev/null 2>&1 &', $sortie, $code);

            return $code === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Le PHP « ligne de commande » — pas celui qui sert les pages.
     *
     * Sous PHP-FPM ou LiteSpeed, `PHP_BINARY` désigne le moteur web, qui ne sait pas exécuter
     * `artisan`. On prend donc, dans l'ordre : le chemin réglé dans `.env` (IMPORT_PHP_CLI), le
     * binaire courant quand c'est déjà un PHP en ligne de commande — le cas de `artisan serve`
     * —, puis les emplacements habituels, dont celui que la tâche planifiée du serveur utilise.
     */
    private static function phpEnLigneDeCommande(): ?string
    {
        $candidats = [config('import.php_cli')];

        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            || preg_match('/^php[\d.]*(\.exe)?$/i', basename((string) PHP_BINARY))) {
            $candidats[] = PHP_BINARY;
        }

        $candidats[] = '/usr/local/bin/php';
        $candidats[] = '/usr/bin/php';

        foreach ($candidats as $candidat) {
            if (is_string($candidat) && $candidat !== '' && @is_file($candidat) && @is_executable($candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    private static function permise(string $fonction): bool
    {
        if (! function_exists($fonction)) {
            return false;
        }

        $interdites = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($fonction, $interdites, true);
    }
}

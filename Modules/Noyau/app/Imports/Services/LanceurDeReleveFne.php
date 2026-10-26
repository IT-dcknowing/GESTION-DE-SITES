<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lance `SCRAPER-PORTAIL-FNE/achats.js` — 12/10.
 *
 * Deux usages, un seul script :
 *
 * - **le planificateur** (`fne:actualiser`) : le lance et **attend** sa fin, puis range ce qu'il a
 *   déposé ;
 * - **le bouton « Actualiser »** : depuis le 26/10, il ne lance plus rien lui-même. Il **dépose une
 *   demande** (`demander`), que le planificateur prend dans la minute (`fne:actualiser --si-demande`) ;
 *   la page va ensuite voir si le fichier est arrivé.
 *
 * **Pourquoi plus de lancement depuis la page — 26/10.** Lancé par le processus web, le navigateur
 * vivait dans le même compte d'hébergement que le site : sa mémoire s'ajoutait à celle des pages, et
 * l'hébergeur répondait « 503 Service Unavailable » à tout le monde pendant plus de dix minutes — le
 * relevé lui-même finissait coupé. Lancé par la tâche planifiée, en priorité basse (`nice`), il ne
 * retient aucun processus web. `detacher` reste pour le poste de développement (Windows).
 *
 * Un verrou empêche deux navigateurs sur le portail en même temps : le bouton cliqué dix fois, ou
 * pressé pendant le passage horaire, ne rouvre pas dix sessions avec le mot de passe de l'entreprise.
 */
final class LanceurDeReleveFne
{
    public const VERROU = 'fne_releve_achats';

    /** La demande du bouton « Actualiser », en attente de la tâche planifiée. */
    public const DEMANDE = 'fne_releve_demande';

    /** Dépose une demande de relevé ; vrai si elle est nouvelle (une demande en attente suffit). */
    public static function demander(bool $complet, ?string $login = null, ?int $par = null): bool
    {
        return Cache::add(self::DEMANDE, [
            'complet' => $complet, 'login' => $login, 'par' => $par, 'le' => now()->timestamp,
        ], now()->addSeconds((int) config('fne.delai_secondes')));
    }

    /** La demande en attente, sans la prendre — pour dire à l'écran que la tâche ne l'a pas encore lue. */
    public static function demandeEnAttente(): ?array
    {
        $demande = Cache::get(self::DEMANDE);

        return is_array($demande) ? $demande : null;
    }

    /** Prend la demande : elle n'est servie qu'une fois. */
    public static function prendreLaDemande(): ?array
    {
        $demande = Cache::pull(self::DEMANDE);

        return is_array($demande) ? $demande : null;
    }

    /**
     * Vérifie que Node et le script existent, sans rien lancer : le bouton le dit tout de suite
     * plutôt que d'attendre dix minutes une tâche qui échouera.
     *
     * @throws RuntimeException
     */
    public static function verifierLeLancement(bool $complet, ?string $login = null): void
    {
        self::verifier(self::commande($complet, $login));
    }

    /** Vrai si un relevé tourne (verrou posé par la tâche planifiée). */
    public static function enCours(): bool
    {
        return Cache::has(self::VERROU);
    }

    public static function actif(): bool
    {
        return (bool) config('fne.actif');
    }

    public static function dossierDesReleves(): string
    {
        return storage_path('app/portail-fne/achats');
    }

    public static function journal(): string
    {
        return storage_path('logs/fne-scraper.log');
    }

    /**
     * Les arguments du script. Sans `$login`, il relève le compte configuré (le seul).
     *
     * @return list<string>
     */
    public static function commande(bool $complet, ?string $login = null): array
    {
        $arguments = [(string) config('fne.node'), (string) config('fne.script')];

        if ($login !== null && trim($login) !== '') {
            $arguments[] = trim($login);
        }

        if (! $complet) {
            $arguments[] = '--depuis='.now()->subDays(max(1, (int) config('fne.depuis_jours')))->toDateString();
        }

        return $arguments;
    }

    /** Pose le verrou ; faux si un relevé est déjà en route. */
    public static function verrouiller(): bool
    {
        return Cache::add(self::VERROU, now()->timestamp, now()->addSeconds((int) config('fne.delai_secondes') + 60));
    }

    public static function deverrouiller(): void
    {
        Cache::forget(self::VERROU);
    }

    /**
     * Lance le scraper en arrière-plan, sa sortie versée au journal.
     *
     * @throws RuntimeException si Node est introuvable ou le script absent
     */
    public static function detacher(bool $complet, ?string $login = null): void
    {
        $arguments = self::commande($complet, $login);
        self::verifier($arguments);

        $ligne = implode(' ', array_map(fn ($a) => '"'.str_replace('"', '""', $a).'"', $arguments));
        $journal = '"'.self::journal().'"';

        // Le dossier du journal doit exister avant que le shell n'y redirige quoi que ce soit.
        if (! is_dir(dirname(self::journal()))) {
            mkdir(dirname(self::journal()), 0775, true);
        }

        $commande = \PHP_OS_FAMILY === 'Windows'
            ? 'start /B "" '.$ligne.' >> '.$journal.' 2>&1'
            : 'cd '.escapeshellarg(dirname((string) config('fne.script'))).' && nohup '.$ligne.' >> '.$journal.' 2>&1 &';

        $tube = popen($commande, 'r');

        if ($tube === false) {
            throw new RuntimeException('Le lancement du relevé a échoué (popen).');
        }

        pclose($tube);
    }

    /**
     * Lance le scraper et attend sa fin — pour le planificateur.
     *
     * @return array{0: bool, 1: string} réussi ?, ce qu'il a imprimé (la fin)
     */
    public static function executer(bool $complet, ?string $login = null): array
    {
        $arguments = self::commande($complet, $login);
        self::verifier($arguments);

        // En priorité basse quand `nice` existe : le site passe avant le navigateur du relevé (26/10).
        $nice = \PHP_OS_FAMILY === 'Windows' ? null : (new ExecutableFinder)->find('nice');

        if ($nice !== null) {
            $arguments = [$nice, '-n', '19', ...$arguments];
        }

        $processus = new Process($arguments, dirname((string) config('fne.script')), null, null, (float) config('fne.delai_secondes'));
        $processus->run();

        $sortie = trim($processus->getOutput()."\n".$processus->getErrorOutput());
        @file_put_contents(self::journal(), '['.now()->toDateTimeString()."] fne:actualiser\n".$sortie."\n", FILE_APPEND);

        return [$processus->isSuccessful(), mb_substr($sortie, -800)];
    }

    /**
     * Les fichiers de relevé arrivés depuis `$depuis` (horodatage Unix), le plus récent d'abord.
     *
     * @return list<string>
     */
    public static function relevesArrivesDepuis(int $depuis): array
    {
        $fichiers = glob(self::dossierDesReleves().'/*.json') ?: [];
        $fichiers = array_values(array_filter($fichiers, fn ($f) => filemtime($f) >= $depuis));
        usort($fichiers, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $fichiers;
    }

    /** Le programme et le script doivent exister AVANT d'être lancés : sinon l'échec se fait en silence. */
    private static function verifier(array $arguments): void
    {
        $programme = $arguments[0];

        if (! is_file($programme) && (new ExecutableFinder)->find($programme) === null) {
            throw new RuntimeException("Node est introuvable (« {$programme} »). Indiquez son chemin complet dans FNE_NODE.");
        }

        if (! is_file($arguments[1])) {
            throw new RuntimeException("Le script du relevé est introuvable (« {$arguments[1]} »).");
        }

        if (! is_dir(dirname($arguments[1]).'/node_modules')) {
            throw new RuntimeException('Le scraper n’est pas installé : lancez « npm install » dans SCRAPER-PORTAIL-FNE.');
        }
    }
}

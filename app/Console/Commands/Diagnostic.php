<?php

namespace App\Console\Commands;

use Modules\Noyau\Commun\Modeles\AbonnementPush;
use Modules\Noyau\Commun\Services\WebPush\EnvoyeurPush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vérifie en une commande que l'installation locale est complète.
 * Répond à la question « pourquoi je ne vois pas la cloche / la messagerie ? ».
 */
class Diagnostic extends Command
{
    protected $signature = 'app:diagnostic';

    protected $description = "Contrôle l'état de l'installation : migrations, ressources compilées, notifications.";

    private bool $toutVaBien = true;

    public function handle(): int
    {
        $this->newLine();
        $this->info('=== Version du code ===');
        $this->ligne('Branche Git', trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null')) ?: 'inconnue');
        $this->ligne('Dernier commit', trim((string) shell_exec('git log --oneline -1 2>/dev/null')) ?: 'inconnu');

        $this->newLine();
        $this->info('=== Tables attendues ===');
        foreach ([
            'conversations' => 'Messagerie',
            'messages' => 'Messagerie',
            'notifications_app' => 'Cloche de notifications',
            'notes' => 'Bloc-notes',
            'dossiers_notes' => 'Bloc-notes',
            'abonnements_push' => 'Notifications poussées',
        ] as $table => $usage) {
            $this->verifier(
                "Table $table ($usage)",
                Schema::hasTable($table),
                'Lancez : php artisan migrate',
            );
        }

        $this->verifier(
            'Colonne users.habilitations (Super Admins secondaires)',
            Schema::hasColumn('users', 'habilitations'),
            'Lancez : php artisan migrate',
        );

        $this->newLine();
        $this->info('=== Fichiers servis au navigateur ===');
        $this->verifier(
            'Ressources compilées (public/build/manifest.json)',
            file_exists(public_path('build/manifest.json')),
            'Lancez : npm install && npm run build',
        );
        $this->verifier(
            'Agent de service (public/sw.js)',
            file_exists(public_path('sw.js')),
            'Le fichier manque : votre copie du code n’est pas à jour.',
        );
        $this->verifier(
            'Lien public/storage (photos, pièces jointes)',
            file_exists(public_path('storage')),
            'Lancez : php artisan storage:link',
        );

        $this->newLine();
        $this->info('=== Vitesse ===');
        $this->controlerLaVitesse();

        $this->newLine();
        $this->info('=== Logos des entreprises ===');
        $this->controlerLesLogos();

        $this->newLine();
        $this->info('=== Redirections et session (hébergement mutualisé) ===');
        $this->controlerRedirections();

        $this->newLine();
        $this->info('=== Caches ===');
        foreach ([
            'Cache de configuration' => 'bootstrap/cache/config.php',
            'Cache des routes' => 'bootstrap/cache/routes-v7.php',
        ] as $intitule => $chemin) {
            if (file_exists(base_path($chemin))) {
                $this->line("  <fg=yellow>!</> $intitule actif — après une mise à jour, lancez : php artisan optimize:clear");
            } else {
                $this->line("  <fg=green>✓</> $intitule inactif (les changements sont pris en compte immédiatement)");
            }
        }

        $this->newLine();
        $this->info('=== Notifications ===');
        $this->line('  <fg=green>✓</> Cloche dans l’application (badge + son) : toujours active');

        if (EnvoyeurPush::estConfigure()) {
            $this->line('  <fg=green>✓</> Notifications poussées : clés VAPID configurées');
            $this->ligne('Appareils abonnés', (string) AbonnementPush::count());
        } else {
            $this->line('  <fg=yellow>!</> Notifications poussées : inactives (clés VAPID absentes)');
            $this->line('      Pour les activer : php artisan push:cles, puis recopier les lignes dans .env');
            $this->line('      Elles exigent une adresse en https:// (ou localhost).');
        }

        $this->newLine();
        $this->info('=== Données ===');
        foreach (['entreprises', 'sites', 'users', 'prospections', 'factures'] as $table) {
            if (Schema::hasTable($table)) {
                $this->ligne(ucfirst($table), (string) DB::table($table)->count());
            }
        }

        $this->newLine();

        if ($this->toutVaBien) {
            $this->info('Installation complète : la cloche et la messagerie doivent être visibles.');
        } else {
            $this->warn('Des éléments manquent — suivez les indications ci-dessus, puis relancez cette commande.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Cherche les causes connues de « cette page vous a redirigé un trop grand nombre
     * de fois ». Elles tiennent presque toujours à un désaccord entre l'adresse réelle
     * du site et ce que Laravel croit être son adresse, ou à un cookie de session
     * que le navigateur refuse d'enregistrer.
     */
    private function controlerRedirections(): void
    {
        $appUrl = (string) config('app.url');
        $schemaApp = parse_url($appUrl, PHP_URL_SCHEME);
        $hoteApp = parse_url($appUrl, PHP_URL_HOST);

        $this->ligne('APP_URL', $appUrl ?: '(vide)');

        $this->verifier(
            'APP_URL renseignée',
            filled($appUrl) && $hoteApp !== null,
            "Renseignez APP_URL dans .env avec l'adresse exacte du site, https:// compris.",
        );

        // La déclaration retombe sur « * » quand la variable est absente ou vide :
        // elle est donc toujours active. On se contente d'afficher la valeur retenue.
        $proxys = env('PROXYS_DE_CONFIANCE') ?: '*';
        $this->line('  <fg=green>✓</> Proxys de confiance déclarés (HTTPS derrière un frontal)');
        $this->ligne('Proxys retenus', $proxys === '*' ? '* (tous — usage courant en mutualisé)' : $proxys);

        // Un cookie « secure » n'est jamais renvoyé par le navigateur sur une page en http :
        // la session se perd à chaque page, l'utilisateur est renvoyé sans fin vers la connexion.
        $cookieSecurise = filter_var(env('SESSION_SECURE_COOKIE'), FILTER_VALIDATE_BOOL);

        if ($cookieSecurise && $schemaApp !== 'https') {
            $this->toutVaBien = false;
            $this->line('  <fg=red>✗</> SESSION_SECURE_COOKIE=true alors que APP_URL est en '.($schemaApp ?: 'http'));
            $this->line('      → Cause classique de boucle : le cookie de session n’est jamais enregistré.');
            $this->line('      → Passez APP_URL en https:// ou retirez SESSION_SECURE_COOKIE.');
        } else {
            $this->line('  <fg=green>✓</> Cohérence entre SESSION_SECURE_COOKIE et le schéma de APP_URL');
        }

        // Un domaine de cookie qui ne correspond pas au site produit le même effet.
        $domaineSession = env('SESSION_DOMAIN');

        if ($domaineSession && $hoteApp && ! str_ends_with($hoteApp, ltrim((string) $domaineSession, '.'))) {
            $this->toutVaBien = false;
            $this->line("  <fg=red>✗</> SESSION_DOMAIN ($domaineSession) ne correspond pas à l’hôte de APP_URL ($hoteApp)");
            $this->line('      → Le navigateur rejette le cookie : videz SESSION_DOMAIN ou corrigez-le.');
        } else {
            $this->line('  <fg=green>✓</> SESSION_DOMAIN compatible avec APP_URL'.($domaineSession ? " ($domaineSession)" : ' (non défini)'));
        }

        $pilote = (string) config('session.driver');
        $this->ligne('Pilote de session', $pilote);

        if ($pilote === 'database') {
            $this->verifier(
                'Table sessions présente',
                Schema::hasTable('sessions'),
                'Lancez : php artisan migrate',
            );
        }

        if ($pilote === 'file') {
            $this->verifier(
                'Dossier storage/framework/sessions accessible en écriture',
                is_writable(storage_path('framework/sessions')),
                'Donnez les droits d’écriture : chmod -R 775 storage bootstrap/cache',
            );
        }

        $this->verifier(
            'Dossier storage accessible en écriture',
            is_writable(storage_path('logs')) && is_writable(storage_path('framework/views')),
            'Donnez les droits d’écriture : chmod -R 775 storage bootstrap/cache',
        );
    }

    /**
     * Ce qui fait qu'une page arrive vite, ou lentement.
     *
     * Trois choses, dans l'ordre de ce qu'elles coûtent :
     *
     * - **OPcache** : sans lui, PHP relit et recompile les milliers de fichiers du cadre à
     *   *chaque* requête. C'est, de loin, le premier poste — mesuré en local, la différence
     *   entre un démarrage à froid et un démarrage à chaud est de l'ordre de deux secondes ;
     * - les **caches de routes, d'évènements et de gabarits**, refaits par `app:deployer` ;
     * - le **magasin de cache** : en base, chaque lecture — dont les droits des rôles, à chaque
     *   page — est un aller-retour MySQL de plus qu'en fichier.
     *
     * **Ce que cette commande ne peut pas voir.** Elle s'exécute dans le PHP « ligne de
     * commande », qui n'est pas celui qui sert les pages : son OPcache à lui est presque
     * toujours éteint, et ne dit rien de l'autre. Elle affiche donc ce qu'elle voit en le
     * disant, et rappelle où se règle celui du web.
     */
    private function controlerLaVitesse(): void
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        $this->ligne(
            'OPcache (ligne de commande)',
            is_array($opcache) && ($opcache['opcache_enabled'] ?? false) ? 'actif' : 'inactif — normal',
        );
        $this->line('      → Celui qui compte est celui du PHP <fg=yellow>qui sert les pages</> : cPanel →');
        $this->line('        « Select PHP Version » → Extensions → cocher <fg=yellow>opcache</>.');

        foreach ([
            'Cache des routes' => app()->getCachedRoutesPath(),
            'Cache des évènements' => app()->getCachedEventsPath(),
        ] as $intitule => $chemin) {
            $this->verifier($intitule, file_exists($chemin), 'Lancez : php artisan app:deployer');
        }

        $gabarits = is_dir(config('view.compiled'))
            ? count(glob(rtrim((string) config('view.compiled'), '/\\').'/*.php') ?: [])
            : 0;

        $this->ligne('Gabarits compilés', $gabarits > 0 ? $gabarits.' fichier(s)' : 'aucun — première visite lente');

        $magasin = (string) config('cache.default');
        $this->ligne('Magasin de cache', $magasin);

        if ($magasin === 'database') {
            $this->line('      → <fg=yellow>CACHE_STORE=file</> dans le .env évite un aller-retour MySQL par lecture.');
        }
    }

    /**
     * L'état réel de chaque logo : ce que dit la base, ce qu'il y a sur le disque, et
     * l'adresse effectivement produite.
     *
     * Une image absente ne provoque aucune erreur : elle manque, simplement, et personne
     * ne sait dire pourquoi. Les trois informations côte à côte tranchent — colonne vide,
     * fichier disparu, ou fichier présent mais non servi.
     */
    private function controlerLesLogos(): void
    {
        if (! Schema::hasTable('entreprises')) {
            $this->line('  (table absente — migrations à passer)');

            return;
        }

        $entreprises = \Modules\Noyau\Entreprises\Modeles\Entreprise::all();

        if ($entreprises->isEmpty()) {
            $this->line('  (aucune entreprise)');

            return;
        }

        foreach ($entreprises as $entreprise) {
            $this->line('  '.$entreprise->nom);
            $this->ligne('    colonne', $entreprise->logo_chemin ?: '(vide — aucun logo choisi)');

            if (! $entreprise->logo_chemin) {
                continue;
            }

            $chemin = str_starts_with($entreprise->logo_chemin, 'public:')
                ? public_path(substr($entreprise->logo_chemin, 7))
                : \Illuminate\Support\Facades\Storage::disk('public')->path($entreprise->logo_chemin);

            $this->ligne('    fichier sur le disque', is_file($chemin) ? 'présent' : 'ABSENT — '.$chemin);
            $this->ligne('    adresse produite', $entreprise->logoUrl() ?: 'AUCUNE');
        }

        $this->line('  Ouvrez cette adresse dans un navigateur : elle doit afficher l\'image.');
        $this->line('  Si elle répond 404, c\'est le serveur web qui ne sert pas le dossier — pas l\'application.');
    }

    private function verifier(string $intitule, bool $present, string $remede): void
    {
        if ($present) {
            $this->line("  <fg=green>✓</> $intitule");

            return;
        }

        $this->toutVaBien = false;
        $this->line("  <fg=red>✗</> $intitule");
        $this->line("      → $remede");
    }

    private function ligne(string $intitule, string $valeur): void
    {
        $this->line("  <fg=gray>·</> ".str_pad($intitule, 34).$valeur);
    }
}

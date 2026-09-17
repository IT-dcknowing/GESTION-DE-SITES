<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Séquence de mise en service, à lancer après chaque « git pull ».
 *
 * Le vidage des gabarits compilés n'est pas optionnel : Volt met en cache la classe
 * de chaque composant à côté de son gabarit, et ne la régénère que si la date du
 * fichier source est plus récente. Selon la manière dont les fichiers arrivent sur
 * le serveur, cette comparaison peut échouer : le gabarit est alors à jour tandis
 * que la classe reste l'ancienne, et la page tombe en erreur 500 sur une propriété
 * introuvable. Supprimer le dossier lève toute ambiguïté.
 */
class Deployer extends Command
{
    protected $signature = 'app:deployer {--sans-migration : Ne pas exécuter les migrations}';

    protected $description = 'Prépare l’application après une mise à jour : migrations et vidage des caches.';

    public function handle(): int
    {
        $this->newLine();

        if (! $this->option('sans-migration')) {
            $this->info('→ Migrations');
            $this->call('migrate', ['--force' => true]);
        }

        $this->info('→ Vidage des caches');
        $this->call('config:clear');
        $this->call('route:clear');
        $this->call('event:clear');
        $this->call('cache:clear');

        // view:clear vide le dossier, mais on s'assure qu'aucun résidu ne subsiste :
        // un seul fichier oublié suffit à ramener la panne.
        $this->call('view:clear');
        $this->supprimerGabaritsCompiles();

        $this->reconstruireLesCaches();

        // Le lien existe déjà dans la plupart des cas : inutile d'afficher une erreur.
        if (! file_exists(public_path('storage'))) {
            $this->info('→ Lien des fichiers publics');
            $this->call('storage:link');
        }

        $this->newLine();
        $this->info('Application à jour. Lancez « php artisan app:diagnostic » pour contrôler l’installation.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Refait, à neuf, les caches qu'on vient de vider — pour la vitesse.
     *
     * Vidés sans être refaits, ils laissaient chaque requête relire les fichiers de routes de
     * tous les modules et recompiler à la première visite chaque gabarit. Refaits juste après
     * le vidage, ils partent des fichiers qui viennent d'arriver : la panne de la classe Volt
     * périmée, qui justifie le vidage, ne peut pas revenir par là.
     *
     * **Pas `config:cache`**, délibérément : `bootstrap/app.php` lit `PROXYS_DE_CONFIANCE` par
     * `env()`, et une configuration en cache ne charge plus le `.env` — la valeur retomberait en
     * silence sur « * ».
     *
     * Un cache qui refuse de se construire ne bloque pas la mise à jour : il est revidé, et
     * l'application tourne comme avant, seulement moins vite.
     */
    private function reconstruireLesCaches(): void
    {
        $this->info('→ Reconstruction des caches (routes, évènements, gabarits)');

        foreach (['route' => 'route:clear', 'event' => 'event:clear', 'view' => 'view:clear'] as $cache => $vider) {
            try {
                if ($this->callSilently($cache.':cache') !== self::SUCCESS) {
                    throw new \RuntimeException('code de sortie non nul');
                }
            } catch (\Throwable $e) {
                $this->callSilently($vider);
                $this->warn("  Cache « {$cache} » non reconstruit ({$e->getMessage()}) : l'application fonctionne sans.");
            }
        }
    }

    private function supprimerGabaritsCompiles(): void
    {
        $dossier = config('view.compiled');

        if (! $dossier || ! File::isDirectory($dossier)) {
            return;
        }

        $restants = collect(File::files($dossier))
            ->filter(fn ($fichier) => $fichier->getExtension() === 'php')
            ->each(fn ($fichier) => File::delete($fichier->getPathname()))
            ->count();

        if ($restants > 0) {
            $this->line("  <fg=gray>·</> $restants gabarit(s) compilé(s) supprimé(s) en complément");
        }
    }
}

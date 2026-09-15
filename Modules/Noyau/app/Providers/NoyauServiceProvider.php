<?php

namespace Modules\Noyau\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

/**
 * Le Noyau est le socle commun : modèles, migrations, services métier et écrans
 * accessibles à tous les rôles (messagerie, notifications, mot de passe, espace
 * personnel). Tous les autres modules en dépendent, et lui ne dépend d'aucun d'eux —
 * c'est ce qui empêche les dépendances circulaires entre rôles.
 */
class NoyauServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Noyau', 'database/migrations'));
        $this->loadViewsFrom(module_path('Noyau', 'resources/views'), 'noyau');
        // Les routes d'un module doivent traverser le groupe « web » (session, cookies,
        // protection CSRF) : chargées seules, elles s'en trouveraient privées et toute
        // page authentifiée échouerait faute de session.
        Route::middleware('web')->group(module_path('Noyau', 'routes/web.php'));

        Volt::mount([module_path('Noyau', 'resources/views')]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Noyau\Console\RattacherLesCodes::class,
                \Modules\Noyau\Console\RangerLesColonnesDesFactures::class,
            ]);
        }
    }
}

<?php

namespace Modules\Import\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

/**
 * Import des exports du logiciel d'atelier.
 *
 * Le module ne possède rien : les tables, les lecteurs et les services vivent dans le
 * Noyau, sous `Imports/`. Ce qui est ici, ce sont les écrans — et c'est voulu. Une fiche
 * de réception importée n'appartient pas à l'import : elle appartient à l'entreprise, qui
 * la facture, la relance et la compte dans son chiffre d'affaires. Le jour où les données
 * arriveront par une connexion directe à la base du logiciel plutôt que par des fichiers,
 * c'est ce module-ci qui changera, et lui seul.
 */
class ImportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(module_path('Import', 'resources/views'), 'import');

        // La coquille de la section est un composant anonyme : <x-import::coquille>.
        Blade::anonymousComponentPath(module_path('Import', 'resources/views/components'), 'import');

        // Les routes doivent traverser le groupe « web » : sans lui, ni session, ni
        // protection CSRF, et toute page authentifiée échouerait.
        Route::middleware('web')->group(module_path('Import', 'routes/web.php'));

        Volt::mount([module_path('Import', 'resources/views')]);
    }
}

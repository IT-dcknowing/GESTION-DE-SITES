<?php

namespace Modules\Recouvrement\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

/**
 * Recouvrement des créances.
 *
 * Le module ne crée pas les factures qu'il poursuit : il les lit là où elles sont déjà,
 * dans le Noyau. Une facture n'appartient pas au recouvrement — elle appartient à
 * l'entreprise, qui la facture, l'encaisse, la déclare, et la relance quand il le faut.
 * C'est ce qui garantit qu'un encaissement saisi ici apparaît aussitôt en trésorerie,
 * sans reprise ni rapprochement.
 */
class RecouvrementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(module_path('Recouvrement', 'resources/views'), 'recouvrement');

        // La coquille (bandeau et barre latérale de la section) est un composant anonyme :
        // <x-recouvrement::coquille>. Sans cet enregistrement, Blade la chercherait dans
        // resources/views/components de l'application et ne la trouverait pas.
        Blade::anonymousComponentPath(module_path('Recouvrement', 'resources/views/components'), 'recouvrement');

        // Les routes doivent traverser le groupe « web » : sans lui, ni session, ni
        // protection CSRF, et toute page authentifiée échouerait.
        Route::middleware('web')->group(module_path('Recouvrement', 'routes/web.php'));

        Volt::mount([module_path('Recouvrement', 'resources/views')]);
    }
}

<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Comptabilité — la caisse d'une ville
|--------------------------------------------------------------------------
| Le préfixe d'URL et les noms de route restent « caissier » : les renommer
| casserait les liens déjà en circulation (notifications déjà envoyées, favoris
| des utilisateurs) sans rien apporter. Le rôle Spatie porte le même nom
| historique ; seul l'affichage dit « Comptabilité ».
*/

Route::middleware(['auth', 'role:caissier'])
    ->prefix('caissier')->name('caissier.')
    ->group(function () {
        Volt::route('/tableau-de-bord', 'comptabilite.tableau-de-bord')->name('tableau-de-bord');
        Volt::route('/encaissements', 'comptabilite.encaissements')->name('encaissements');
        Volt::route('/decaissements', 'comptabilite.decaissements')->name('decaissements');
    });

/*
 * Les écritures comptables — 27/10 : « on va paramétrer les numéros de compte, faire la
 * configuration ». Le gérant et la comptabilité. Voir `PlanComptable`.
 */
Route::middleware(['auth', 'role:gerant|caissier'])->group(function () {
    Volt::route('/ecritures-comptables/parametrage', 'comptabilite.ecritures-parametrage')->name('ecritures.parametrage');
});

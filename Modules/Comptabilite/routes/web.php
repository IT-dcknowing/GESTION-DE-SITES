<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Comptabilite\Http\Controllers\JustificatifComptableController;

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
 * configuration ». Voir `PlanComptable`.
 *
 * Depuis le 25/11, un module donné **par habilitation** (`comptabilite.*`, le gérant au départ),
 * comme le Rapport : aucun rôle à la route, chaque écran et chaque action le revérifient
 * (`AccesALaComptabilite`). Un rôle à la route aurait renvoyé ailleurs le compte habilité qui n'a
 * pas le rôle, et laissé entrer le rôle à qui l'on a retiré le geste.
 */
Route::middleware(['auth'])->group(function () {
    Volt::route('/ecritures-comptables/parametrage', 'comptabilite.ecritures-parametrage')->name('ecritures.parametrage');
    // Les charges restées « Autres » : l'IA propose, une personne décide — 24/11, page à part le 25/11.
    Volt::route('/ecritures-comptables/imputations-ia', 'comptabilite.ecritures-imputations')->name('ecritures.imputations');
    // Le journal des écritures générées, la TVA à lire et le déversement vers COMPTAFLOW — 24/11.
    Volt::route('/ecritures-comptables', 'comptabilite.ecritures')->name('ecritures.index');
    // La pièce justificative d'une opération : l'ouvrir, en joindre le PDF — 25/11.
    Route::get('/ecritures-comptables/justificatif/{operation}', [JustificatifComptableController::class, 'ouvrir'])
        ->whereNumber('operation')->name('ecritures.justificatif');
    Route::post('/ecritures-comptables/justificatif/{operation}', [JustificatifComptableController::class, 'deposer'])
        ->whereNumber('operation')->name('ecritures.justificatif.deposer');
});

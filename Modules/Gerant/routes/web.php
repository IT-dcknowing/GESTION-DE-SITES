<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Gerant\Http\Controllers\ReaffectationController;

/*
|--------------------------------------------------------------------------
| Gérant — direction de l'entreprise
|--------------------------------------------------------------------------
| Deux écrans qui lui sont propres. Les indicateurs qu'il consulte par ailleurs
| (Prospects, Devis, CA, Charges, Trésorerie, Commerciaux) sont déclarés par le
| module Superviseur, qui les partage avec le gérant et les responsables
| de site : un même écran, un même code, trois rôles qui le lisent.
*/

Route::middleware(['auth', 'role:gerant'])->group(function () {
    Volt::route('/tableau-de-bord', 'gerant.tableau-de-bord')->name('tableau-de-bord');
    Volt::route('/parametres', 'gerant.parametres')->name('parametres');
    /*
     * Le barème de commission a sa page, et non un onglet de plus dans Paramètres.
     *
     * Il se lit autant qu'il se règle : on l'ouvre pour savoir ce qu'un chiffre d'affaires
     * produit, pas seulement pour changer un taux. Et il décide de ce que quelqu'un touche
     * à la fin du mois — d'où le gérant seul, ici comme dans le menu.
     */
    Volt::route('/parametres/bareme-commission', 'gerant.bareme-commission')->name('bareme-commission');
    // La mutation d'un employé passe par un vrai POST : elle change ce qu'une personne
    // voit et où elle saisit, et ne doit pas pouvoir échouer sans le dire.
    Route::post('/parametres/reaffecter', [ReaffectationController::class, 'store'])->name('reaffecter');
});

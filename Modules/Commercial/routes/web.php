<?php

use Illuminate\Support\Facades\Route;
use Modules\Noyau\Entreprises\Support\RolesCommerciaux;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Commercial — le terrain
|--------------------------------------------------------------------------
| Il saisit ses prospections et suit sa propre performance. Il n'accède à aucun
| indicateur consolidé : son périmètre s'arrête à ce qu'il a lui-même produit.
*/

/*
 * Le responsable commercial entre ici : il encadre, mais il vend aussi, et ses propres
 * prospections doivent lui être accessibles comme à n'importe quel vendeur. Sans cela il
 * porterait des objectifs sans avoir d'écran pour les servir.
 */
/*
 * Sa performance individuelle est ouverte à tous ceux qui vendent — responsable de ville
 * et responsable de site compris. Ils portent une fiche commercial et des objectifs depuis
 * toujours ; simplement, aucun écran ne les leur montrait. Des objectifs qu'on ne peut pas
 * consulter ne sont pas des objectifs.
 */
Route::middleware(['auth', 'role:'.RolesCommerciaux::pourMiddleware()])->group(function () {
    Volt::route('/ma-performance', 'commercial.ma-performance')->name('ma-performance');
});

/*
 * La saisie de ses propres prospections et son bloc-notes restent au commercial et à son
 * responsable : les deux autres rôles saisissent depuis « Saisie du jour », qui porte le
 * même travail avec le périmètre de leur lieu.
 */
Route::middleware(['auth', 'role:commercial|responsable_commercial'])->group(function () {
    Volt::route('/mes-prospections', 'commercial.mes-prospections')->name('mes-prospections');
    Volt::route('/mes-notes', 'commercial.mes-notes')->name('mes-notes');
});

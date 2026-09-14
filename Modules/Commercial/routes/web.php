<?php

use Illuminate\Support\Facades\Route;
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
Route::middleware(['auth', 'role:commercial|responsable_commercial'])->group(function () {
    Volt::route('/ma-performance', 'commercial.ma-performance')->name('ma-performance');
    Volt::route('/mes-prospections', 'commercial.mes-prospections')->name('mes-prospections');
    Volt::route('/mes-notes', 'commercial.mes-notes')->name('mes-notes');
});

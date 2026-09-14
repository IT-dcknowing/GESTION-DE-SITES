<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Responsable de site — la saisie du jour
|--------------------------------------------------------------------------
| La saisie est ancrée sur un lieu : c'est là que la journée d'atelier se
| déroule, et c'est le métier du responsable de site. Ces écrans lui appartiennent.
|
| Le superviseur de ville y accède également : quand sa ville n'a qu'un seul lieu,
| c'est lui qui tient la saisie. Le gérant en est exclu — il ne saisit rien.
*/

Route::middleware(['auth', 'role:responsable_ville|responsable_site'])->group(function () {
    Volt::route('/saisie-du-jour', 'saisie.saisie-du-jour')->name('saisie-du-jour');
});

/*
 * La fiche d'une prospection est ouverte au gérant en plus des deux responsables : le
 * tableau des prospects, qu'il consulte, mène désormais ici par son bouton « Détail », et
 * un bouton qui conduit à un refus vaut moins qu'un bouton absent. L'écran vérifie de son
 * côté que le site fait bien partie du périmètre de celui qui regarde.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site'])->group(function () {
    Volt::route('/saisie-du-jour/prospections/{prospection}', 'saisie.prospection-voir')->name('prospection.voir');
});

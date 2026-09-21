<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Controleurs\TelechargerAnnuaire;
use Modules\SuperAdmin\Http\Controllers\CodesAtelierController;
use Modules\SuperAdmin\Http\Controllers\SwitchController;

/*
|--------------------------------------------------------------------------
| Super Admin — la plateforme, hors périmètre d'une entreprise
|--------------------------------------------------------------------------
| Chaque écran porte en plus une habilitation : un Super Admin secondaire ne
| voit que les sections qui lui ont été ouvertes.
*/

Route::middleware(['auth', 'role:super_admin'])
    ->prefix('super-admin')->name('super-admin.')
    ->group(function () {
        Volt::route('/', 'superadmin.tableau-de-bord')->name('dashboard')->middleware('habilitation:dashboard');
        Volt::route('/entreprises', 'superadmin.entreprises-liste')->name('entreprises.index')->middleware('habilitation:entreprises');
        Volt::route('/entreprises/{entreprise}', 'superadmin.entreprises-detail')->name('entreprises.show')->middleware('habilitation:entreprises');
        Volt::route('/acces', 'superadmin.acces-liste')->name('acces.index')->middleware('habilitation:acces');
        Volt::route('/acces/creer', 'superadmin.acces-creer')->name('acces.creer')->middleware('habilitation:acces');
        // Même écran que la création : les champs sont les mêmes, et corriger un accès
        // dans une ligne de tableau donnait un formulaire à l'étroit, illisible dès que
        // l'adresse dépassait la largeur de la colonne.
        Volt::route('/acces/{utilisateur}/modifier', 'superadmin.acces-creer')->name('acces.modifier')->middleware('habilitation:acces');
        // Un téléchargement n'est pas une page Livewire : il lui faut une vraie réponse
        // HTTP. Le paramètre ?entreprise= restreint le document à une seule entreprise.
        Route::get('/annuaire.pdf', TelechargerAnnuaire::class)->name('annuaire')->middleware('habilitation:acces');
        Volt::route('/administrateurs', 'superadmin.administrateurs')->name('administrateurs')->middleware('habilitation:acces');

        /*
         * Les codes d'atelier, personne par personne. Même habilitation que les accès :
         * donner son code à quelqu'un, c'est décider de quel atelier recevra son travail —
         * cela relève de qui ouvre les comptes, pas d'une section de plus.
         */
        Volt::route('/codes', 'superadmin.codes')->name('codes')->middleware('habilitation:acces');
        Route::post('/codes', [CodesAtelierController::class, 'update'])
            ->name('codes.enregistrer')->middleware('habilitation:acces');
        /*
         * Les codes vus dans les imports et qui n'appartiennent à aucun compte : on y note
         * qui les porte, sans rien leur ouvrir. Même habilitation, puisque c'est la même
         * donnée regardée par l'autre bout.
         */
        Route::post('/codes/import', [CodesAtelierController::class, 'identifier'])
            ->name('codes.import')->middleware('habilitation:acces');
        Volt::route('/journal', 'superadmin.journal')->name('journal.index')->middleware('habilitation:journal');
        // Même habilitation que le journal : les deux écrans répondent à la même
        // question — que s'est-il passé, et par qui. Ouvrir une section de plus aurait
        // laissé les administrateurs secondaires déjà habilités devant un onglet mort.
        Volt::route('/tracabilite', 'superadmin.tracabilite')->name('tracabilite')->middleware('habilitation:journal');
        Volt::route('/maintenance', 'superadmin.maintenance')->name('maintenance')->middleware('habilitation:maintenance');

        /*
         * Entrer dans le compte de quelqu'un pour l'assister. Même habilitation que la
         * gestion des accès : c'est le même pouvoir, exercé autrement.
         */
        // Borné aux nombres : sans cela, « /switch/sortir » se lirait comme un
        // identifiant d'utilisateur et la sortie du mode switch tomberait en 404.
        Route::post('/switch/{utilisateur}', [SwitchController::class, 'entrer'])
            ->whereNumber('utilisateur')
            ->name('switch.entrer')->middleware('habilitation:acces');
    });

/*
 * La sortie du mode switch vit hors du groupe, et ce n'est pas un oubli.
 *
 * Pendant le détour, l'utilisateur authentifié est la personne assistée : elle n'a ni le
 * rôle de super administrateur ni l'habilitation « accès ». Garder la sortie derrière le
 * même filtre enfermerait l'administrateur dans le compte qu'il vient d'ouvrir, sans autre
 * issue que la déconnexion. Elle se garde autrement, et suffisamment : elle ne fait rien si
 * aucune identité d'origine n'est en session, et cette identité n'a pu y être posée que par
 * une entrée légitime.
 */
Route::middleware('auth')->post('/super-admin/switch/sortir', [SwitchController::class, 'sortir'])
    ->name('super-admin.switch.sortir');

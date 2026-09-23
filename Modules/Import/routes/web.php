<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Import\Http\Controllers\CodesController;
use Modules\Import\Http\Controllers\DepotController;
use Modules\Import\Http\Controllers\LotController;
use Modules\Import\Http\Controllers\RejetsController;
use Modules\Import\Http\Controllers\TraitementsController;
use Modules\Import\Http\Middleware\VerifiePageImport;

/*
|--------------------------------------------------------------------------
| Import — l'alimentation du système par les exports du logiciel
|--------------------------------------------------------------------------
| Deux contrôles se superposent, et ce n'est pas une redondance :
|
| - `role:` dit qui entre dans le module ;
| - `page-import:` dit jusqu'où chacun va à l'intérieur.
|
| Le second est indispensable : les trois écrans partagent le même préfixe, et sans
| lui un responsable de site qui tape `/import/codes` déciderait de l'atelier
| auquel des milliers de fiches sont rattachées — dont les siennes.
*/

Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site|caissier'])
    ->prefix('import')->name('import.')
    ->group(function () {
        Volt::route('/', 'import.depot')
            ->name('depot')->middleware(VerifiePageImport::class.':depot');
        // Le dépôt lui-même est une requête HTTP ordinaire, pas un geste interactif.
        // C'est le chemin dont tout le reste dépend : il ne doit tomber en panne que
        // bruyamment. Voir DepotController pour le raisonnement.
        Route::post('/', [DepotController::class, 'store'])
            ->name('deposer')->middleware(VerifiePageImport::class.':depot');
        // Une notice, pas un écran de chiffres : quel fichier alimente quelle page.
        Volt::route('/informations', 'import.informations')
            ->name('informations')->middleware(VerifiePageImport::class.':informations');
        // Ce qui tourne. La lecture démarre d'elle-même au dépôt : il n'y a plus de geste
        // « tout traiter », donc plus d'adresse qui le reçoive.
        Volt::route('/traitements', 'import.traitements')
            ->name('traitements')->middleware(VerifiePageImport::class.':traitements');
        /*
         * L'état des traitements, en JSON. Il sert la veille posée dans la mise en page :
         * un import se termine pendant qu'on travaille ailleurs, et c'est à ce moment-là
         * que la nouvelle sert. Pas de middleware de page : le contrôleur rend une réponse
         * vide à qui n'a rien à y voir, plutôt qu'une redirection qu'un fetch ne saurait
         * pas lire.
         */
        Route::get('/traitements/etat', [TraitementsController::class, 'etat'])
            ->name('traitements.etat')->withoutMiddleware(VerifiePageImport::class);
        /*
         * Reconnaître les commerciaux nommés sur les fiches de réception. Un vrai POST,
         * comme le dépôt et l'annuaire des codes : la question se pose là où l'on suit la
         * lecture, et la réponse se donne sans dépendre de la couche interactive.
         */
        Route::post('/traitements/commerciaux', [TraitementsController::class, 'reconnaitreLesCommerciaux'])
            ->name('traitements.commerciaux')->middleware(VerifiePageImport::class.':traitements');
        Volt::route('/journal', 'import.lots')
            ->name('lots')->middleware(VerifiePageImport::class.':lots');
        Volt::route('/journal/{lot}', 'import.lot')
            ->name('lot')->middleware(VerifiePageImport::class.':lots');
        /*
         * Les gestes qu'on pose sur un dépôt — réimporter, arrêter la lecture, annuler —
         * passent par un vrai POST. Ils étaient posés sur la couche interactive et ne
         * partaient donc jamais dans un navigateur où celle-ci ne démarre pas : le clic
         * n'allait nulle part, sans erreur ni trace.
         */
        Route::post('/journal/{lot}', [LotController::class, 'agir'])
            ->name('lot.agir')->middleware(VerifiePageImport::class.':lots');
        // Le fichier déposé, tel qu'il a été reçu : c'est la pièce d'origine d'un audit.
        Route::get('/journal/{lot}/fichier', [LotController::class, 'fichier'])
            ->name('lot.fichier')->middleware(VerifiePageImport::class.':lots');
        // Corriger les lignes refusées, puis rejouer l'import sur le même fichier.
        Volt::route('/journal/{lot}/corrections', 'import.rejets')
            ->name('lot.rejets')->middleware(VerifiePageImport::class.':lots');
        Route::post('/journal/{lot}/corrections', [RejetsController::class, 'store'])
            ->name('lot.corriger')->middleware(VerifiePageImport::class.':lots');
        // La traçabilité a sa page : ce qui était, ce qui a été mis, par qui, depuis où.
        Volt::route('/journal/{lot}/tracabilite', 'import.tracabilite')
            ->name('lot.tracabilite')->middleware(VerifiePageImport::class.':lots');
        Volt::route('/codes-employes', 'import.codes')
            ->name('codes')->middleware(VerifiePageImport::class.':codes');
        // La fiche d'un employé a son adresse : elle s'ouvre, elle se partage, et elle ne
        // se cherche pas au milieu d'un tableau de trente-huit lignes.
        Volt::route('/codes-employes/{code}', 'import.code')
            ->name('codes.fiche')->middleware(VerifiePageImport::class.':codes')
            ->where('code', '[A-Za-z]{2}');
        // Enregistrer un employé : requête ordinaire, comme le dépôt. Un écran de
        // référentiel n'a aucune raison de dépendre de la couche interactive.
        Route::post('/codes-employes', [CodesController::class, 'update'])
            ->name('codes.enregistrer')->middleware(VerifiePageImport::class.':codes');
    });

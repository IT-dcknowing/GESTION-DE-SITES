<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Controleurs\TelechargerAnnuaire;
use Modules\Superviseur\Http\Controllers\AccesController;

/*
|--------------------------------------------------------------------------
| Superviseur de ville — le pilotage
|--------------------------------------------------------------------------
| Le superviseur répond de tous les lieux de sa ville : son métier est de lire
| les indicateurs et de nommer les accès sous lui. Ces écrans lui appartiennent.
|
| Deux autres rôles les consultent : le gérant, qui suit sans saisir, et le
| responsable de site, à qui Site::visiblesPour() n'ouvre que son propre lieu.
| Une route ne pouvant être déclarée qu'une fois pour une URL donnée, elle est
| déclarée ici — chez celui dont c'est le métier — et son middleware nomme les
| trois rôles admis.
*/

/*
 * La chaîne commerciale — prospects, devis, chiffre d'affaires, commerciaux. Le
 * responsable commercial y entre : c'est exactement son métier, et c'est la seule façon
 * pour lui de voir le travail des vendeurs qu'il encadre.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site|responsable_commercial'])->group(function () {
    Volt::route('/prospects', 'pilotage.prospects')->name('prospects');
    Volt::route('/devis', 'pilotage.devis')->name('devis');
    Volt::route('/chiffre-affaires', 'pilotage.chiffre-affaires')->name('chiffre-affaires');
    Volt::route('/commerciaux', 'pilotage.commerciaux')->name('commerciaux');
});

/*
 * Les charges et la trésorerie restent fermées au responsable commercial. Animer une
 * équipe de vente ne donne aucun titre à lire ce que l'entreprise dépense — et l'ouvrir
 * « parce qu'il est responsable » confondrait le rang avec la branche.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site'])->group(function () {
    Volt::route('/charges', 'pilotage.charges')->name('charges');
    Volt::route('/tresorerie', 'pilotage.tresorerie')->name('tresorerie');
    // Les deux écrans qui manquaient à ce qui était déjà importé : mille cent cinquante-cinq
    // mouvements de caisse et mille huit cent quarante-huit factures fournisseurs dormaient
    // en base sans qu'aucune page ne les affiche. Une donnée qu'on ne peut pas voir n'a pas
    // été importée, elle a été rangée.
    Volt::route('/caisse', 'pilotage.caisse')->name('caisse');
    Volt::route('/fournisseurs', 'pilotage.fournisseurs')->name('fournisseurs');
    // Le parc vit ici, avec les autres indicateurs, et non dans le module Import :
    // l'import le remplit, l'exploitation le consulte. On le lit tous les jours,
    // on n'importe qu'une fois par semaine.
    Volt::route('/parc-vehicules', 'pilotage.parc-vehicules')->name('parc-vehicules');
    // Les clients de l'entreprise — à ne pas confondre avec « Clients & tiers » du
    // recouvrement, qui regarde les payeurs et leur dette. Ici, c'est qui vient à
    // l'atelier : la question de l'exploitation, pas celle du recouvrement.
    Volt::route('/clients', 'pilotage.clients')->name('clients');
    // Les entrées et sorties s'importaient depuis un moment et ne s'affichaient nulle
    // part : cent quarante-sept lignes en base, aucun écran pour les lire.
    Volt::route('/entrees-sorties', 'pilotage.mouvements-vehicules')->name('mouvements-vehicules');
    // La fiche a son adresse propre : elle se met en favori et se transmet, ce qu'un volet
    // replié sous un tableau ne permet pas.
    Volt::route('/parc-vehicules/{dossier}', 'pilotage.parc-fiche')->name('parc-fiche');
});

/*
 * La création d'accès est ouverte à un rôle de plus : le superviseur recouvrement, qui
 * nomme ses agents. L'écran est le même, mais il n'y voit que ce que HierarchieAcces lui
 * laisse voir — les agents de recouvrement, et personne d'autre. Route séparée pour que
 * les six écrans de pilotage, eux, ne s'ouvrent pas à lui : il n'a pas à lire le chiffre
 * d'affaires des villes.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site|superviseur_recouvrement'])->group(function () {
    Volt::route('/acces/creer', 'pilotage.acces-creer')->name('acces.creer');

    /*
     * Reprendre un accès existant. Il manquait purement et simplement : on créait,
     * on révoquait, on supprimait — corriger une adresse mal tapée obligeait à
     * supprimer puis recréer, donc à perdre le lien entre la personne et ce qu'elle
     * avait déjà écrit.
     *
     * La fiche a sa page : les champs y sont au large, et l'adresse se partage.
     */
    Volt::route('/acces/{utilisateur}/modifier', 'pilotage.acces-modifier')->name('acces.modifier');
    Route::post('/acces/{utilisateur}/modifier', [AccesController::class, 'update'])
        ->name('acces.modifier.enregistrer');

    /*
     * Révoquer, renvoyer, supprimer : trois gestes qui ferment ou rouvrent l'accès de
     * quelqu'un. Ils reposaient sur la couche interactive et ne partaient donc jamais
     * dans un navigateur où celle-ci ne démarre pas — sans erreur, sans trace. Ce sont
     * exactement les gestes qui ne doivent jamais échouer en silence.
     */
    Route::post('/acces/{utilisateur}/agir', [AccesController::class, 'agir'])->name('acces.agir');
});

/*
 * L'annuaire s'arrête au superviseur. Le gérant y voit toute son entreprise, le
 * superviseur sa seule ville — c'est le service qui le détermine, d'après l'identité
 * du lecteur, jamais d'après un paramètre reçu. Le responsable de site en est écarté :
 * il n'encadre qu'un lieu, dont il connaît déjà les quelques noms.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville'])->group(function () {
    Route::get('/annuaire.pdf', TelechargerAnnuaire::class)->name('annuaire');
});

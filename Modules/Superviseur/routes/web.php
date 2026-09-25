<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Controleurs\TelechargerAnnuaire;
use Modules\Noyau\Commun\Services\Exportateur;
use Modules\Superviseur\Http\Controllers\AccesController;
use Modules\Superviseur\Http\Controllers\OuvrirLaFicheParSonNumero;
use Modules\Superviseur\Http\Controllers\TelechargerLesFournisseurs;

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
 * Les indicateurs d'argent : ce qui entre, ce qui sort, ce qu'on doit.
 *
 * **Le comptable y entre, et c'est le sens du chantier 11.** Il tenait la caisse sans
 * pouvoir lire l'état de cette caisse, ni la trésorerie qu'il alimente, ni ce que
 * l'entreprise doit à ses fournisseurs : trois écrans faits de ses propres écritures, et
 * fermés à lui. Son périmètre s'y applique comme à tout le monde : il ne voit que sa ville.
 *
 * **Depuis le 24/09, l'une d'elles écrit.** Le suivi fournisseur reçoit une saisie — une
 * facture arrivée entre deux dépôts n'avait nulle part où aller. Tout le monde n'a pas à
 * engager l'entreprise auprès d'un fournisseur : le responsable d'atelier continue de lire
 * la page, il n'y écrit pas. La règle est dans `EtatDesFournisseurs::peutEcrire()`, et elle
 * est vérifiée dans l'action autant qu'ici — une route ne protège que l'entrée.
 *
 * **Ils restent fermés au responsable commercial.** Animer une équipe de vente ne donne
 * aucun titre à lire ce que l'entreprise dépense — et l'ouvrir « parce qu'il est
 * responsable » confondrait le rang avec la branche.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site|caissier'])->group(function () {
    Volt::route('/charges', 'pilotage.charges')->name('charges');
    Volt::route('/tresorerie', 'pilotage.tresorerie')->name('tresorerie');
    // Les deux écrans qui manquaient à ce qui était déjà importé : mille cent cinquante-cinq
    // mouvements de caisse et mille huit cent quarante-huit factures fournisseurs dormaient
    // en base sans qu'aucune page ne les affiche. Une donnée qu'on ne peut pas voir n'a pas
    // été importée, elle a été rangée.
    Volt::route('/caisse', 'pilotage.caisse')->name('caisse');
    // La question du comptoir — « cette plaque, on a payé quoi dessus, et reste-t-il
    // quelque chose ? » — a sa page : elle ne se pose pas sur une période, et la mêler à
    // l'écran de caisse aurait donné un écran qui répond mal aux deux questions.
    Volt::route('/caisse/vehicule', 'pilotage.caisse-vehicule')->name('caisse.vehicule');
    Volt::route('/fournisseurs', 'pilotage.fournisseurs')->name('fournisseurs');
    // La reprise des deux classeurs a son adresse propre, comme celle du classeur des
    // impayés : toutes années mêlées, en lecture seule. L'état par année ne peut pas la
    // montrer — une pièce soldée d'une année passée n'entre dans aucun exercice.
    Volt::route('/fournisseurs/tableau-initial', 'pilotage.fournisseurs-tableau-initial')
        ->name('fournisseurs.tableau-initial');
    /*
     * Les deux exports du logiciel comptable ont leur page, à côté du suivi tenu à la main.
     *
     * Trois écrans donc, et c'est voulu : le suivi dit ce que l'atelier croit devoir, la
     * balance ce que la comptabilité a enregistré, les règlements ce qu'elle a payé. Les
     * réunir dans un seul tableau reviendrait à mélanger deux sources et à perdre la seule
     * chose qu'on cherche — leur écart.
     */
    // La pièce, en entier : une quarantaine de colonnes que le tableau d'ensemble ne peut
    // pas montrer, et qu'il fallait pouvoir lire — et corriger — quelque part.
    Volt::route('/fournisseurs/piece/{piece}', 'pilotage.fournisseur-piece')
        ->name('fournisseurs.piece')->whereNumber('piece');
    // Le fournisseur lui-même, et non ses factures : à quel terme il se règle, s'il
    // facture la TVA. C'est de cette page que sort l'échéance attendue d'une pièce que le
    // fichier n'a pas datée — 5 184 sur 7 350 lignes à la mesure du 24/09.
    Volt::route('/fournisseurs/referentiel', 'pilotage.referentiel-fournisseurs')->name('referentiel-fournisseurs');
    Volt::route('/fournisseurs/balance', 'pilotage.balance-fournisseurs')->name('balance-fournisseurs');
    Volt::route('/fournisseurs/reglements', 'pilotage.reglements-fournisseurs')->name('reglements-fournisseurs');
    // Emporter le même tableau, avec les mêmes filtres : l'habilitation est celle de
    // l'écran d'où il vient, puisqu'un export n'est rien d'autre qu'une lecture.
    Route::get('/fournisseurs/telecharger/{format}', TelechargerLesFournisseurs::class)
        ->name('fournisseurs.telecharger')
        ->whereIn('format', array_keys(Exportateur::FORMATS));
});

/*
 * Le reste du pilotage : le parc, les clients, les mouvements de véhicules et l'état des
 * impayés. Le comptable n'y entre pas — ce ne sont plus ses chiffres mais ceux de
 * l'exploitation, et l'état des impayés est un écran de saisie.
 */
Route::middleware(['auth', 'role:gerant|responsable_ville|responsable_site'])->group(function () {
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
    Volt::route('/parc-vehicules/{dossier}', 'pilotage.parc-fiche')->name('parc-fiche')->whereNumber('dossier');
    /*
     * La même fiche, atteinte par son numéro.
     *
     * Le n° de fiche est la seule clé commune aux états du logiciel d'atelier : il figure
     * sur le devis, sur la facture et sur les entrées et sorties. Le rendre cliquable
     * depuis ces écrans sans cette route coûterait une requête par ligne affichée — ici
     * elle n'a lieu qu'au clic. Le périmètre y est relu, et un numéro introuvable dit
     * pourquoi plutôt que de laisser croire à une panne.
     */
    Route::get('/parc-vehicules/fiche/{numero}', OuvrirLaFicheParSonNumero::class)
        ->name('parc-fiche.numero')
        ->where('numero', '[A-Za-z0-9\s\-\x{00B0}]{1,40}');

    /*
     * L'état des impayés, et ce qui va avec.
     *
     * Il est ici et non dans le module Recouvrement, et le choix se défend : ce que le
     * superviseur de veille tient dans son classeur, c'est l'état du chiffre d'affaires et de
     * ses règlements — un indicateur de l'exploitation. Le recouvrement, lui, poursuit les
     * créances que cet état fait apparaître ; il les lit, il ne les relève pas.
     *
     * Fermé au responsable commercial, comme les charges et la trésorerie : animer une équipe
     * de vente ne donne aucun titre à lire ce que les clients de l'entreprise doivent.
     */
    Volt::route('/impayes', 'pilotage.impayes')->name('impayes');
    // La reprise du classeur a son adresse propre : c'est une page qu'on ouvre à côté de
    // l'autre pour comparer, pas un volet qu'on replie sous elle.
    Volt::route('/impayes/etat-initial', 'pilotage.impayes-etat-initial')->name('impayes.etat-initial');
    // Le détail d'une créance a sa page, comme la fiche d'un véhicule : il se lit au large,
    // se rouvre dans un autre onglet et se transmet. Déplié sous sa ligne, il poussait le
    // tableau vers le bas et se perdait au premier changement de page.
    Volt::route('/impayes/creance/{creance}', 'pilotage.impayes-detail')
        ->name('impayes.detail')->whereNumber('creance');
    Volt::route('/rapprochement-ca-impayes', 'pilotage.rapprochement-ca-impayes')->name('rapprochement-ca-impayes');

    /*
     * Le rapprochement prospection / devis.
     *
     * Il est ici, avec l'exploitation, et non dans le module Commercial : confirmer un
     * rapprochement porte un devis au compte d'un commercial, c'est-à-dire un chiffre à
     * quelqu'un. Ce geste appartient à celui qui arbitre, pas à celui qui est compté — un
     * commercial qui se rattacherait lui-même les devis de l'atelier n'aurait aucun mal à
     * gonfler sa performance.
     */
    Volt::route('/rapprochement-prospections-devis', 'pilotage.rapprochement-prospections-devis')
        ->name('rapprochement.prospections-devis');
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

<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\LogoEntrepriseController;
use App\Http\Controllers\RedirectionController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Controleurs\ChangerLeMotDePasse;
use Modules\Noyau\Commun\Controleurs\ChoisirLaLoupe;
use Modules\Noyau\Commun\Controleurs\ConfirmerLeCodeAtelier;
use Modules\Noyau\Commun\Controleurs\EnregistrerLaLiaison;
use Modules\Noyau\Commun\Controleurs\EnregistrerSonTelephone;

/*
|--------------------------------------------------------------------------
| Noyau — écrans accessibles quel que soit le rôle
|--------------------------------------------------------------------------
| Connexion, inscription, messagerie, notifications, mot de passe et espace
| personnel. Aucun de ces écrans n'appartient à un rôle en particulier : c'est
| pour cela qu'ils vivent dans le socle et non dans un module de rôle.
*/

Route::redirect('/', '/connexion');

// Alias francophone de la route de connexion générée par Fortify (name: login).
Route::get('/connexion', fn () => redirect()->route('login'))->name('connexion');

/*
 * Première connexion : le lien du courriel d'accueil mène ici, pas à la page de
 * connexion. Le titulaire n'a pas encore de mot de passe — lui en réclamer un pour
 * entrer, puis un autre pour en changer, était le parcours qu'il fallait défaire.
 *
 * L'adresse est signée avec la clé de l'application et vaut une semaine ; « signed »
 * la vérifie avant même d'atteindre l'écran. Volontairement hors du groupe « guest » :
 * quelqu'un déjà connecté sur un poste partagé doit pouvoir ouvrir son propre lien
 * sans être renvoyé ailleurs sans explication.
 */
Route::middleware(['signed'])->group(function () {
    // Mise en page nue, sans la navigation de l'application : on n'y est pas encore
    // entré. Afficher les menus autour d'un écran de création de mot de passe donnait
    // à croire que le compte était déjà ouvert.
    Volt::route('/premiere-connexion/{utilisateur}', 'commun.definir-mot-de-passe')
        ->name('mot-de-passe.definir');
});

/*
 * Logo d'entreprise servi par l'application. Hors session, volontairement : un courriel
 * est lu dans une boîte aux lettres, et l'écran de connexion est par définition ouvert.
 * Le repli n'est utilisé que si le lien symbolique public/storage manque — voir
 * Entreprise::logoUrl().
 */
Route::get('/entreprises/{entreprise}/logo', LogoEntrepriseController::class)
    ->name('entreprise.logo');

Route::get('/auth/google', [GoogleAuthController::class, 'rediriger'])->name('auth.google');
Route::get('/auth/callback', [GoogleAuthController::class, 'callback'])->name('auth.callback');

Route::middleware(['guest'])->group(function () {
    Volt::route('/inscription', 'commun.inscription')->name('inscription');
    // Auto-inscription du personnel avec le code communiqué par le gérant.
    Volt::route('/rejoindre', 'commun.inscription-personnel')->name('inscription.personnel');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/redirection', RedirectionController::class)->name('redirection');

    // Les deux loupes — année et ville regardées. Elles ne modifient rien en base mais
    // commandent ce que tout écran calcule : elles ne doivent pas dépendre d'un script.
    Route::post('/loupe/ville', [ChoisirLaLoupe::class, 'ville'])->name('loupe.ville');
    Route::post('/loupe/exercice', [ChoisirLaLoupe::class, 'exercice'])->name('loupe.exercice');

    // Le profil : les deux codes qui désignent la personne, et celui qui se modifie.
    Volt::route('/mon-profil', 'commun.mon-profil')->name('mon-profil');
    // Le rattachement au code du logiciel d'atelier : requête ordinaire, comme le dépôt.
    // Un écran où l'on relie son propre travail n'a pas à dépendre de la couche interactive.
    Route::post('/mon-profil/liaison', EnregistrerLaLiaison::class)->name('mon-profil.liaison');

    // « Oui, c'est bien mon code. » Posée sur tous les écrans tant qu'on n'a pas répondu,
    // la question se ferme ici — et laisse une ligne au journal, comme l'attribution.
    Route::post('/mon-profil/code-atelier/confirmer', ConfirmerLeCodeAtelier::class)
        ->name('code-atelier.confirmer');

    // « Où vous joindre ? » Le numéro manque sur une bonne part des accès ouverts en série,
    // et c'est de lui qu'on a besoin le jour où une fiche pose question. Chacun donne le
    // sien, une fois, depuis n'importe quel écran.
    Route::post('/mon-profil/telephone', EnregistrerSonTelephone::class)
        ->name('mon-profil.telephone');

    /*
     * La fiche d'une prospection vue par le commercial — sa ligne, et la signature de la
     * décision prise dessus. Le responsable a la sienne, qui porte en plus l'historique
     * des modifications ; les deux montrent la même traçabilité, par le même composant.
     */
    Route::middleware(['role:commercial'])->group(function () {
        Volt::route('/prospections/{prospection}', 'commun.prospection-fiche')
            ->name('prospection.fiche')->whereNumber('prospection');
    });

    Volt::route('/mon-compte/mot-de-passe', 'commun.mot-de-passe')->name('mot-de-passe.modifier');
    // L'enregistrement est une requete ordinaire : cet ecran est un passage oblige, et un
    // bouton qui depend d'un script est ici une porte fermee sur un compte neuf.
    Route::post('/mon-compte/mot-de-passe', ChangerLeMotDePasse::class)->name('mot-de-passe.enregistrer');

    // Messagerie interne : ouverte à tous les rôles, les destinataires étant filtrés
    // par AnnuaireMessagerie selon le rôle et l'entreprise.
    Volt::route('/messages', 'commun.messages')->name('messages');

    // Activation et diagnostic des notifications poussées, ouverts à tous les rôles.
    Volt::route('/mes-notifications', 'commun.mes-notifications')->name('mes-notifications');

    /*
     * Espace personnel : profil, photo, rattachement, listes déroulantes.
     *
     * Il était fermé aux deux rôles du recouvrement, dont le bandeau proposait pourtant
     * l'onglet : on cliquait sur « Paramètres » et l'on tombait sur un refus. Un écran où
     * l'on règle son propre compte n'a pas à dépendre du métier qu'on exerce — le gérant
     * garde le sien, plus vaste, et tous les autres ont celui-ci.
     */
    Route::middleware([
        'role:responsable_ville|responsable_site|commercial|caissier'
            .'|superviseur_recouvrement|agent_recouvrement',
    ])->group(function () {
        Volt::route('/mon-espace', 'commun.mon-espace')->name('mon-espace');
    });
});

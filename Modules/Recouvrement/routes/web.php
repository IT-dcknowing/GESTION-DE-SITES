<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Modules\Recouvrement\Http\Controllers\TelechargementController;
use Modules\Recouvrement\Http\Middleware\VerifiePageRecouvrement;
use Modules\Recouvrement\Support\AccesRecouvrement;

/*
|--------------------------------------------------------------------------
| Recouvrement — la poursuite des créances
|--------------------------------------------------------------------------
| Deux contrôles se superposent, et ce n'est pas une redondance :
|
| - `role:` dit qui entre dans le module ;
| - `page-recouvrement:` dit jusqu'où chacun va à l'intérieur.
|
| Le second est indispensable : les neuf écrans partagent le même préfixe, et sans
| lui un agent qui tape `/recouvrement/audit` lirait le journal de ses propres
| gestes. Le grisé de la barre latérale n'a jamais fermé une adresse.
*/

/*
 * Le commercial, le responsable commercial et le responsable de site entrent depuis le 28/10 :
 * cinq écrans, sur les seuls clients qu'ils suivent (`AccesRecouvrement::PAGES_DU_COMMERCIAL`,
 * `PerimetreCommercialDuRecouvrement`).
 */
Route::middleware(['auth', 'role:gerant|superviseur_recouvrement|agent_recouvrement|responsable_ville|caissier|'
    .implode('|', AccesRecouvrement::ROLES_DU_PORTEFEUILLE)])
    ->prefix('recouvrement')->name('recouvrement.')
    ->group(function () {
        /*
         * Le tableau de bord — la page d'arrivée du module.
         *
         * Elle se lit avec les mêmes chiffres que la balance âgée, par construction :
         * c'est le même service qui calcule l'encours. Un tableau de bord qui
         * contredirait la balance ne serait pas un tableau de bord, ce serait un doute
         * de plus.
         */
        Volt::route('/tableau-de-bord', 'recouvrement.tableau-de-bord')
            ->name('tableau-de-bord')->middleware(VerifiePageRecouvrement::class.':tableau-de-bord');

        /*
         * Le dossier d'un tiers, en toutes lettres et sur sa propre adresse : ses factures
         * ouvertes, ses relances, ses règlements, et qui a fait quoi. Le tiers voyage dans
         * l'adresse et non dans un état de page — la fiche se met en favori, se transmet,
         * et s'ouvre dans un autre onglet sans rien perdre.
         */
        Volt::route('/dossier/{tiers}', 'recouvrement.dossier')
            ->name('dossier')->middleware(VerifiePageRecouvrement::class.':tableau-de-bord');

        Volt::route('/', 'recouvrement.saisie')
            ->name('saisie')->middleware(VerifiePageRecouvrement::class.':saisie');
        Volt::route('/synthese', 'recouvrement.synthese')
            ->name('synthese')->middleware(VerifiePageRecouvrement::class.':synthese');
        Volt::route('/balance-agee', 'recouvrement.balance')
            ->name('balance')->middleware(VerifiePageRecouvrement::class.':balance');
        Volt::route('/courtiers', 'recouvrement.courtiers')
            ->name('courtiers')->middleware(VerifiePageRecouvrement::class.':courtiers');
        Volt::route('/clients', 'recouvrement.clients')
            ->name('clients')->middleware(VerifiePageRecouvrement::class.':clients');
        Volt::route('/extrait-de-compte', 'recouvrement.extrait')
            ->name('extrait')->middleware(VerifiePageRecouvrement::class.':extrait');
        Volt::route('/relances', 'recouvrement.relances')
            ->name('relances')->middleware(VerifiePageRecouvrement::class.':relances');
        Volt::route('/encaissements', 'recouvrement.encaissements')
            ->name('encaissements')->middleware(VerifiePageRecouvrement::class.':encaissements');
        Volt::route('/piste-audit', 'recouvrement.audit')
            ->name('audit')->middleware(VerifiePageRecouvrement::class.':audit');

        // Emporter un document. L'habilitation est celle de l'écran d'où il vient : on ne
        // télécharge pas ce qu'on n'a pas le droit de lire — un export est une lecture.
        Route::get('/telecharger/{document}', TelechargementController::class)
            ->name('telecharger')
            ->whereIn('document', array_keys(TelechargementController::DOCUMENTS))
            ->middleware(VerifiePageRecouvrement::class.':balance');
    });

/*
 * La correction des clients, sur les données du recouvrement — 28/10 : « la correction part dans le
 * recouvrement, car elle doit puiser dans les données du recouvrement ». Comme la fusion de l'état
 * des impayés, aucun rôle à la route : elle s'ouvre à qui a le geste dans Maintenance ›
 * Habilitations (`tiers_recouvrement.corriger`, le gérant par défaut), un rôle ou un compte précis.
 * Le composant le vérifie à l'ouverture et à chaque action.
 */
Route::middleware(['auth'])->prefix('recouvrement')->name('recouvrement.')->group(function () {
    Volt::route('/correction-des-clients', 'recouvrement.correction')->name('correction');
});

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Le serveur de test est ouvert sur Internet et porte de vraies données clients.
 *
 * **Pourquoi cette protection vit dans le code.** La réponse habituelle est un
 * `.htaccess`. Elle ne tenait pas ici : l'hébergeur ne propose pas l'outil, et
 * `public/.htaccess` est suivi par git — le modifier sur le serveur créerait un conflit à
 * chaque mise à jour, jusqu'au jour où quelqu'un l'écraserait pour s'en débarrasser. Une
 * protection qu'un déploiement peut effacer n'en est pas une.
 *
 * Ce que ces tests tiennent, ce sont les trois portes : production ouverte, réseau local
 * ouvert, Internet fermé — et fermé **même quand personne n'a configuré le mot de passe**,
 * parce qu'un oubli doit se voir tout de suite.
 */
class ProtectionEnvironnementDeTestTest extends TestCase
{
    use RefreshDatabase;

    private const DEHORS = ['REMOTE_ADDR' => '41.66.12.34'];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/tests/porte', fn () => 'entré')->middleware('web');

        config([
            'app.acces_test.utilisateur' => 'equipe',
            'app.acces_test.mot_de_passe' => 'un-vrai-secret',
        ]);
    }

    public function test_en_production_aucun_mot_de_passe_n_est_demande(): void
    {
        // L'application a sa propre page de connexion : un second mot de passe devant
        // n'ajouterait qu'une gêne, et on finirait par le retirer.
        $this->app['env'] = 'production';

        $this->withServerVariables(self::DEHORS)
            ->get('/tests/porte')
            ->assertOk()
            ->assertSee('entré');
    }

    public function test_depuis_le_poste_de_developpement_rien_n_est_demande(): void
    {
        /*
         * Laragon tourne aussi en APP_ENV=local. Sans cette porte, le filtre demanderait
         * un mot de passe au développeur sur sa propre machine — et la première chose
         * qu'on ferait serait de le désactiver.
         */
        foreach (['127.0.0.1', '192.168.1.20', '10.0.0.5'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->get('/tests/porte')
                ->assertOk();
        }
    }

    public function test_depuis_internet_le_mot_de_passe_est_exige(): void
    {
        $reponse = $this->withServerVariables(self::DEHORS)->get('/tests/porte');

        $reponse->assertStatus(401);
        $reponse->assertDontSee('entré');

        // C'est cet en-tête qui fait apparaître la fenêtre du navigateur. Sans lui, le
        // visiteur reçoit un 401 nu, sans aucun moyen de s'identifier.
        $this->assertStringContainsString(
            'Basic realm="Environnement de test"',
            (string) $reponse->headers->get('WWW-Authenticate'),
        );
    }

    public function test_les_bons_identifiants_ouvrent(): void
    {
        $this->withServerVariables(self::DEHORS + [
            'PHP_AUTH_USER' => 'equipe',
            'PHP_AUTH_PW' => 'un-vrai-secret',
        ])->get('/tests/porte')->assertOk()->assertSee('entré');
    }

    public function test_un_mot_de_passe_approchant_ne_suffit_pas(): void
    {
        $this->withServerVariables(self::DEHORS + [
            'PHP_AUTH_USER' => 'equipe',
            'PHP_AUTH_PW' => 'un-vrai-secre',
        ])->get('/tests/porte')->assertStatus(401);
    }

    public function test_sans_configuration_la_porte_reste_fermee(): void
    {
        /*
         * Le point important. Un environnement de test non configuré pourrait rester
         * ouvert sans que personne ne s'en aperçoive — jusqu'au jour où quelqu'un tombe
         * sur l'adresse. On ferme, et le message dit quoi ajouter.
         */
        config(['app.acces_test.utilisateur' => null, 'app.acces_test.mot_de_passe' => null]);

        $this->withServerVariables(self::DEHORS)
            ->get('/tests/porte')
            ->assertStatus(503)
            ->assertSee('ACCES_TEST_UTILISATEUR');
    }
}

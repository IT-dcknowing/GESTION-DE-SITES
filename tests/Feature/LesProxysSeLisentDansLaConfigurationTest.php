<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Le frontal HTTPS de l'hébergeur reste cru, maintenant que la liste se lit dans la
 * configuration (12/10) — ce qui rend `config:cache` possible. Voir `FaireConfianceAuxProxys`.
 */
class LesProxysSeLisentDansLaConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/tests/schema', fn () => request()->isSecure() ? 'https' : 'http')->middleware('web');
    }

    public function test_sans_reglage_tout_frontal_est_cru(): void
    {
        config(['app.proxys_de_confiance' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/tests/schema', ['X-Forwarded-Proto' => 'https'])->assertSee('https');
    }

    public function test_une_liste_ne_croit_que_les_siens(): void
    {
        config(['app.proxys_de_confiance' => '10.0.0.9, 10.0.0.8']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/tests/schema', ['X-Forwarded-Proto' => 'https'])->assertDontSee('https');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])
            ->get('/tests/schema', ['X-Forwarded-Proto' => 'https'])->assertSee('https');
    }
}

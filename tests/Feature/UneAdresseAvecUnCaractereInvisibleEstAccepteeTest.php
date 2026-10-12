<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * « t.tou@lartisanauto.com » refusée par le navigateur — 12/10. L'adresse portait un
 * caractère invisible collé avec elle. Le champ ne bloque plus, et l'adresse est nettoyée.
 */
class UneAdresseAvecUnCaractereInvisibleEstAccepteeTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_adresse_est_enregistree_sans_le_caractere_invisible(): void
    {
        $compte = User::create([
            'name' => 'T. Tou', 'email' => "t.tou@lartisan\u{200B}auto.com\u{00A0}",
            'password' => Hash::make('x'), 'est_actif' => true,
        ]);

        $this->assertSame('t.tou@lartisanauto.com', $compte->fresh()->email);
    }

    public function test_le_champ_e_mail_ne_bloque_plus_dans_le_navigateur(): void
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = Blade::render('<x-champ label="Adresse e-mail" model="email" type="email" />');

        $this->assertStringNotContainsString('type="email"', $html);
        $this->assertStringContainsString('inputmode="email"', $html);
    }
}

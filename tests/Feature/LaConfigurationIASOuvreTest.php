<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'écran « Intelligence artificielle » du Super admin s'ouvre — 24/11.
 *
 * En ligne : « Undefined variable $usages ». Les variables du bloc PHP d'un composant Volt ne
 * passent pas à la vue ; elles passent désormais par `with()`.
 */
class LaConfigurationIASOuvreTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_ecran_s_ouvre_et_liste_les_usages_et_les_fournisseurs(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(SuperAdminSeeder::EQUIPE_PLATEFORME);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'entreprise_id' => SuperAdminSeeder::EQUIPE_PLATEFORME]);
        $compte = User::create([
            'entreprise_id' => null, 'name' => 'Fondateur', 'email' => 'fondateur@plateforme.test',
            'password' => Hash::make('mot-de-passe-quelconque'), 'est_actif' => true, 'est_fondateur' => true,
        ]);
        $compte->assignRole('super_admin');

        $this->actingAs($compte)->get(route('super-admin.ia.configuration'))
            ->assertOk()
            ->assertSee('Imputations comptables')
            ->assertSee('Anthropic');
    }
}

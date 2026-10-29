<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La panne du 12/10 sur `/devis/4010` (YAPI CHRISTIAN) — « Class
 * "Modules\Noyau\Tracabilite\Services\PisteDeLaFiche" not found ». La classe vit dans
 * `Exploitation\Services` ; la vue l'importait d'un autre espace de noms, et ne la demandait que
 * pour un devis qui porte un n° de fiche de réception — d'où une page qui s'ouvrait pour les uns et
 * tombait pour les autres. Le test ouvre ce devis-là.
 */
class LeDetailDuDevisSOuvreAvecSaFicheTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_devis_qui_porte_sa_fiche_de_reception_s_ouvre(): void
    {
        $e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($e);
        $ville = Ville::create(['entreprise_id' => $e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $site = Site::create(['entreprise_id' => $e->id, 'ville_id' => $ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($e->id);
        $gerant = User::create([
            'entreprise_id' => $e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $ville->id, 'site_id' => $site->id, 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $gerant->assignRole('gerant');

        $devis = Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $e->id, 'site_id' => $site->id, 'numero' => 'PRO-4010', 'n_fiche_reception' => 'FR-2026-0042',
            'date_emission' => now()->subDays(3), 'date_reception' => now()->subDays(3), 'client' => 'Client repris',
            'activite' => 'Mécanique', 'statut' => 'En attente', 'montant_devis' => 320_000.25,
        ]);

        $this->actingAs($gerant->fresh())->get(route('devis.detail', $devis->id))
            ->assertOk()->assertSee('PRO-4010')->assertSee('Fiche de réception');
    }
}

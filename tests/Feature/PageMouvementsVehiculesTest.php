<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Modeles\MouvementVehicule;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'écran des entrées et des sorties.
 *
 * Ces lignes s'importaient depuis plusieurs sessions et **ne s'affichaient nulle part** :
 * la table se remplissait, aucun écran ne la lisait. C'est le genre d'oubli qui fait douter
 * de tout le reste, puisqu'on ne peut plus vérifier que l'import fait ce qu'il annonce.
 */
class PageMouvementsVehiculesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $siteUn;

    private Ville $abidjan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->siteUn = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $gerant = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant',
            'email' => 'g@alpha.test', 'password' => 'motdepasse', 'est_actif' => true,
        ]);
        $gerant->assignRole('gerant');
        $this->actingAs($gerant->fresh());

        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000001', 'AB-111-CD');
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000002', 'AB-222-CD');
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000003', 'AB-333-CD');
        $this->mouvement(MouvementVehicule::SORTIE, 'FR-KZN° 000001', 'AB-111-CD');
    }

    public function test_la_page_montre_les_deux_sens_et_leur_ecart(): void
    {
        Volt::test('pilotage.mouvements-vehicules')
            ->assertSet('sensFiltre', '')
            ->assertSee('AB-111-CD')
            ->assertSee('AB-333-CD');
    }

    public function test_l_ecart_est_le_nombre_de_vehicules_encore_immobilises(): void
    {
        $page = Volt::test('pilotage.mouvements-vehicules');

        $this->assertSame(3, $page->instance()->entrees);
        $this->assertSame(1, $page->instance()->sorties);
    }

    public function test_le_filtre_de_sens_ne_garde_qu_un_sens(): void
    {
        $page = Volt::test('pilotage.mouvements-vehicules')->set('sensFiltre', MouvementVehicule::SORTIE);

        $this->assertSame(4 - 3, $page->instance()->total);
        $this->assertSame(0, $page->instance()->entrees);
    }

    public function test_la_recherche_porte_sur_l_immatriculation(): void
    {
        Volt::test('pilotage.mouvements-vehicules')
            ->set('recherche', 'AB-333')
            ->assertSee('AB-333-CD')
            ->assertDontSee('AB-222-CD');
    }

    private function mouvement(string $sens, string $fiche, string $immat): void
    {
        MouvementVehicule::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'site_id' => $this->siteUn->id,
            'sens' => $sens,
            'date' => now()->toDateString(),
            'numero_fiche' => $fiche,
            'immatriculation' => $immat,
            'marque' => 'TOYOTA',
            'modele' => 'HILUX',
            'client' => 'LOXEA',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Services\AnnuaireDesClients;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'annuaire des clients de l'entreprise, et ses deux filtres.
 *
 * La demande était explicite : « n'oublie pas le filtre cette fois, arrête-toi à la ville
 * et le site ». Ces tests fixent donc autant le filtre que ce qu'il laisse passer — et
 * notamment la ligne sans atelier, qui disparaît dès qu'on filtre sur un lieu, et dont
 * l'écran doit prévenir plutôt que de laisser croire à un client perdu.
 */
class PageClientsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $siteUn;

    private Site $siteDeux;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->siteUn = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true]);
        $this->siteDeux = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A2', 'nom' => 'Site 2', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true]);
        $siteBouake = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id, 'code' => 'B1', 'nom' => 'Bouaké', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $this->gerant = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant',
            'email' => 'g@alpha.test', 'password' => 'motdepasse', 'est_actif' => true,
        ]);
        $this->gerant->assignRole('gerant');
        $this->actingAs($this->gerant->fresh());

        $this->facture('LOXEA', $this->siteUn->id, 500000, 'AB-001-CD');
        $this->facture('LOXEA', $this->siteUn->id, 300000, 'AB-002-CD');
        $this->facture('NSIA', $this->siteDeux->id, 200000, 'EF-003-GH');
        $this->facture('SOTRA', $siteBouake->id, 100000, 'IJ-004-KL');
        // Une facture importée sans atelier : visible sans filtre, invisible avec.
        $this->facture('CLIENT SANS ATELIER', null, 999000, 'MN-005-OP');
    }

    public function test_sans_filtre_tous_les_clients_sont_la_y_compris_ceux_sans_atelier(): void
    {
        $lignes = (new AnnuaireDesClients($this->entreprise->id))->lignes();

        $this->assertCount(4, $lignes);
        $this->assertContains('CLIENT SANS ATELIER', $lignes->pluck('nom')->all());
    }

    public function test_le_filtre_ville_ne_garde_que_les_clients_de_cette_ville(): void
    {
        $lignes = (new AnnuaireDesClients($this->entreprise->id))->lignes($this->bouake->id);

        $this->assertSame(['SOTRA'], $lignes->pluck('nom')->all());
    }

    public function test_le_filtre_atelier_descend_sous_la_ville(): void
    {
        $lignes = (new AnnuaireDesClients($this->entreprise->id))->lignes($this->abidjan->id, $this->siteDeux->id);

        $this->assertSame(['NSIA'], $lignes->pluck('nom')->all());
    }

    public function test_les_montants_et_les_vehicules_sont_agreges_par_client(): void
    {
        $loxea = (new AnnuaireDesClients($this->entreprise->id))->lignes()->firstWhere('nom', 'LOXEA');

        $this->assertSame(2, $loxea['factures']);
        $this->assertSame(800000.0, $loxea['montant']);
        $this->assertSame(2, $loxea['vehicules']);
    }

    public function test_la_page_repond_et_son_filtre_nom_fonctionne(): void
    {
        Volt::test('pilotage.clients')
            ->assertSee('LOXEA')
            ->assertSee('NSIA')
            ->set('recherche', 'loxea')
            ->assertSee('LOXEA')
            ->assertDontSee('NSIA');
    }

    public function test_le_filtre_ville_de_la_page_remet_la_pagination_au_debut(): void
    {
        Volt::test('pilotage.clients')
            ->set('page', 5)
            ->set('villeFiltre', (string) $this->bouake->id)
            ->assertSet('page', 1)
            ->assertSet('siteFiltre', '')
            ->assertSee('SOTRA')
            ->assertDontSee('LOXEA');
    }

    private function facture(string $client, ?int $siteId, int $montant, string $immat): void
    {
        DB::table('factures')->insert([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $siteId,
            'numero' => random_int(1, 999999),
            'n_facture' => 'F'.random_int(1000, 9999),
            'date' => now()->toDateString(),
            'client' => $client,
            'type' => 'Facture',
            'activite' => 'Carrosserie',
            'immatriculation' => $immat,
            'montant' => $montant,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

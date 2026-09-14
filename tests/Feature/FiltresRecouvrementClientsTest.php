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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les filtres de « Clients & tiers », côté serveur.
 *
 * Ils étaient signalés comme ne fonctionnant pas. Ils fonctionnaient : c'est le navigateur
 * qui n'envoyait rien, faute d'avoir chargé la couche interactive. Ces tests le prouvent, et
 * ils fixent surtout ce qui manquait vraiment — que l'écran **rende l'état qu'il tient**,
 * pour qu'un filtre posé se voie posé, et qu'on ne cherche plus le défaut ailleurs.
 */
class FiltresRecouvrementClientsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $site = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $gerant = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant',
            'email' => 'g@alpha.test', 'password' => 'motdepasse', 'est_actif' => true,
        ]);
        $gerant->assignRole('gerant');
        $this->actingAs($gerant->fresh());

        $this->facture($site->id, 'GARAGE MOUSSA', null, null);
        $this->facture($site->id, 'SOCIETE BAMBA', 'NSIA ASSURANCES', null);
        $this->facture($site->id, 'ENTREPRISE KONE', 'ALLIANZ', 'COURTIER GAGNOA');
    }

    public function test_le_filtre_par_nom_reduit_bien_le_tableau(): void
    {
        Volt::test('recouvrement.clients')
            ->assertSee('GARAGE MOUSSA')
            ->assertSee('SOCIETE BAMBA')
            ->set('recherche', 'moussa')
            ->assertSee('GARAGE MOUSSA')
            ->assertDontSee('SOCIETE BAMBA');
    }

    public function test_le_filtre_par_role_ne_garde_que_ce_role(): void
    {
        Volt::test('recouvrement.clients')
            ->set('role', 'Courtier')
            ->assertSee('COURTIER GAGNOA')
            ->assertDontSee('GARAGE MOUSSA');
    }

    public function test_l_ecran_rend_le_role_choisi_dans_son_html(): void
    {
        // Le défaut d'origine : l'écran affichait « Courtier » sans que le serveur l'ait
        // reçu, et le tableau listait tout le monde sous un filtre qui semblait posé.
        $html = Volt::test('recouvrement.clients')->set('role', 'Courtier')->html();

        $this->assertStringContainsString('value="Courtier" selected', $html);
    }

    public function test_un_role_forge_ne_vide_pas_le_tableau_en_silence(): void
    {
        Volt::test('recouvrement.clients')
            ->set('role', 'Directeur Général')
            ->assertSee('GARAGE MOUSSA');
    }

    public function test_changer_de_filtre_ramene_a_la_premiere_page(): void
    {
        Volt::test('recouvrement.clients')
            ->set('page', 4)
            ->set('recherche', 'kone')
            ->assertSet('page', 1);
    }

    private function facture(int $siteId, string $client, ?string $assureur, ?string $courtier): void
    {
        DB::table('factures')->insert([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $siteId,
            'numero' => random_int(1, 999999),
            'n_facture' => 'F'.random_int(1000, 9999),
            'date' => now()->toDateString(),
            'client' => $client,
            'assureur' => $assureur,
            'courtier' => $courtier,
            'type' => 'Facture',
            'activite' => 'Carrosserie',
            'montant' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

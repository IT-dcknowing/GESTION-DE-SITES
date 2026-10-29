<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\CatalogueDesPages;
use Modules\Noyau\Exploitation\Modeles\ClientCommercial;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\RelanceCommerciale;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les pages des commerciaux, par défaut — 29/10.
 *
 * « Par défaut, les commerciaux et tous ceux qui sont à la base des commerciaux — responsables
 * commerciaux, responsables de site — doivent avoir : Portefeuilles clients des commerciaux,
 * Correspondances (et mes correspondances), Suivi des correspondances. » Et la page des portefeuilles
 * montre ses trois tableaux un à la fois, par trois boutons.
 */
class LesCommerciauxOntLeursPagesParDefautTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_les_trois_pages_sont_cochees_par_defaut_pour_ceux_qui_vendent(): void
    {
        $metier = CatalogueDesPages::rolesDuMetier();

        foreach (['commercial', 'responsable_commercial', 'responsable_site'] as $role) {
            foreach (['portefeuilles-clients', 'correspondances', 'correspondances.suivi'] as $page) {
                $this->assertContains($page, $metier[$role]['pages'], "« $page » devrait être ouverte par défaut au rôle « $role ».");
            }
        }
    }

    public function test_chacun_ouvre_les_trois_pages_et_seul_l_encadrement_affecte(): void
    {
        foreach (['commercial', 'responsable_commercial', 'responsable_site'] as $role) {
            $compte = $this->compte($role);
            Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $compte->id, 'numero' => 'C-'.$role, 'nom' => ucfirst($role)]);

            foreach (['portefeuilles-clients', 'correspondances', 'correspondances.miennes', 'correspondances.suivi'] as $route) {
                $this->actingAs($compte)->get(route($route))->assertOk();
            }

            $portefeuilles = $this->actingAs($compte)->get(route('portefeuilles-clients'));
            $role === 'commercial'
                ? $portefeuilles->assertDontSee('Affecter un client')
                : $portefeuilles->assertSee('Affecter un client');

            // Le lien vers le suivi est sur la page des correspondances.
            $this->actingAs($compte)->get(route('correspondances'))->assertSee('Suivi des correspondances');

            $this->actingAs($compte);
            $libelles = collect(MenuNavigation::pour($compte))
                ->flatMap(fn ($o) => isset($o['groupe']) ? array_column($o['groupe'], 'label') : [$o['label']])->all();
            $this->assertContains('Portefeuilles clients', $libelles, "Le menu du rôle « $role » devrait porter les portefeuilles.");
        }
    }

    public function test_le_responsable_de_site_affecte_un_client(): void
    {
        $responsable = $this->compte('responsable_site');
        $vendeur = $this->compte('commercial');
        $fiche = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $vendeur->id, 'numero' => 'C-1', 'nom' => 'Awa']);

        Volt::actingAs($responsable)->test('pilotage.portefeuilles-clients')
            ->set('commercialChoisi', (string) $fiche->id)->set('client', 'SOTRA')
            ->call('affecter')->assertHasNoErrors()->assertSet('client', '');

        $this->assertSame($fiche->id, (int) ClientCommercial::withoutGlobalScopes()->sole()->commercial_id);
    }

    public function test_trois_boutons_un_tableau_a_la_fois(): void
    {
        $gerant = $this->compte('gerant');
        $vendeur = $this->compte('commercial');
        $fiche = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $vendeur->id, 'numero' => 'C-1', 'nom' => 'Awa']);
        ClientCommercial::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'commercial_id' => $fiche->id, 'client' => 'SOTRA']);
        RelanceCommerciale::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'commercial_id' => $fiche->id, 'responsable' => 'Awa', 'client' => 'SOTRA',
            'date' => now()->toDateString(), 'objet' => 'Fidélisation', 'canal' => 'Téléphone', 'resultat' => 'Rappeler jeudi',
        ]);

        // Par défaut : « Tous les clients » seul.
        $ecran = Volt::actingAs($gerant)->test('pilotage.portefeuilles-clients')
            ->assertSee('Tous les clients (')
            ->assertDontSee('Rappeler jeudi')
            ->assertDontSee('Clients suivis — toute l');

        $ecran->call('choisirVue', 'relances')->assertSet('vue', 'relances')
            ->assertSee('Rappeler jeudi')->assertDontSee('Tous les clients (');

        $ecran->call('choisirVue', 'suivis')->assertSet('vue', 'suivis')
            ->assertSee('Clients suivis — toute l')->assertDontSee('Rappeler jeudi');

        // Une vue inconnue retombe sur « tous ».
        $ecran->call('choisirVue', 'nimporte')->assertSet('vue', 'tous');
        Volt::actingAs($gerant)->test('pilotage.portefeuilles-clients', ['vue' => 'xyz'])->assertSet('vue', 'tous');

        // La vue voyage dans l'adresse.
        $this->actingAs($gerant)->get(route('portefeuilles-clients', ['vue' => 'relances']))->assertOk()->assertSee('Rappeler jeudi');
    }

    // ------------------------------------------------------------------------------ outils

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($role), 'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\RoleConfigure;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\CatalogueDesPages;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le rôle Trésorerie, et les rôles qui se configurent dans la Maintenance — 22/10.
 *
 * « Crée un rôle TRÉSORERIE : accès à tout ce qui concerne la trésorerie, uniquement. » « Au Super
 * admin, une section où je configure les rôles : le nom, les sections et les pages cochées, et un
 * bouton Modifier. »
 */
class UnRoleSeDefinitParSesPagesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_la_tresorerie_n_ouvre_que_la_tresorerie(): void
    {
        $tresorier = $this->compte('tresorerie');

        foreach (['tresorerie', 'caisse', 'banques'] as $route) {
            $this->actingAs($tresorier)->get(route($route))->assertOk();
        }

        foreach (['charges', 'fournisseurs', 'chiffre-affaires', 'impayes', 'tableau-de-bord'] as $route) {
            $this->actingAs($tresorier)->get(route($route))->assertForbidden();
        }
    }

    public function test_son_menu_et_son_atterrissage_suivent_ses_pages(): void
    {
        $tresorier = $this->compte('tresorerie');
        $this->actingAs($tresorier);

        $libelles = collect(MenuNavigation::pour($tresorier))->pluck('label')->all();
        $this->assertSame(['Trésorerie', 'Paramètres'], $libelles);

        $this->get(route('redirection'))->assertRedirect(route('tresorerie'));
    }

    public function test_sa_ville_borne_ce_qu_il_lit(): void
    {
        $tresorier = $this->compte('tresorerie');

        $this->assertSame([$this->ville->id], Site::visiblesPour($tresorier)->pluck('ville_id')->unique()->values()->all());
    }

    public function test_un_role_du_metier_garde_ses_routes(): void
    {
        $caissier = $this->compte('caissier');

        $this->actingAs($caissier)->get(route('charges'))->assertOk();
        $this->assertNull(CatalogueDesPages::roleDe($caissier));
    }

    public function test_le_super_admin_cree_puis_modifie_un_role_dans_la_maintenance(): void
    {
        $admin = $this->superAdmin();

        $section = Volt::actingAs($admin)->test('superadmin.roles-configures')
            ->set('libelle', 'Contrôle de gestion')
            ->call('basculerSection', 'charges')
            ->set('pagesCochees', ['charges', 'fournisseurs', 'page-inventee'])
            ->call('enregistrer')
            ->assertHasNoErrors();

        $role = RoleConfigure::query()->where('nom', 'controle_de_gestion')->sole();
        // Une case inventée par le navigateur n'ouvre rien.
        $this->assertSame(['charges', 'fournisseurs'], $role->pages);

        $section->call('modifier', 'controle_de_gestion')
            ->assertSet('libelle', 'Contrôle de gestion')
            ->set('pagesCochees', ['charges'])
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame(['charges'], $role->fresh()->pages);

        // Il ouvre ses pages à l'accès qui le porte.
        CatalogueDesPages::oublier();
        $controleur = $this->compte('controle_de_gestion');
        $this->actingAs($controleur)->get(route('charges'))->assertOk();
        $this->actingAs($controleur)->get(route('fournisseurs'))->assertForbidden();
    }

    public function test_un_nom_de_role_du_metier_est_refuse(): void
    {
        Volt::actingAs($this->superAdmin())->test('superadmin.roles-configures')
            ->set('libelle', 'Gérant')
            ->set('pagesCochees', ['charges'])
            ->call('enregistrer')
            ->assertHasErrors('libelle');

        $this->assertSame(0, RoleConfigure::query()->count());
    }

    public function test_seul_le_super_admin_configure(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('superadmin.roles-configures')
            ->set('libelle', 'Intrus')
            ->set('pagesCochees', ['charges'])
            ->call('enregistrer')
            ->assertForbidden();
    }

    public function test_le_tresorier_se_cree_comme_un_autre_acces(): void
    {
        $compte = app(\Modules\Noyau\Entreprises\Actions\CreerAcces::class)->executer($this->e, 'tresorerie', [
            'nom' => 'Awa Trésor', 'email' => 'awa@alpha.test', 'mot_de_passe' => 'motdepasse123',
            'ville_id' => (string) $this->ville->id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->assertTrue($compte->fresh()->hasRole('tresorerie'));
        $this->assertSame($this->ville->id, (int) $compte->fresh()->ville_id);
    }

    /** « Même les rôles déjà créés doivent figurer, avec ce qu'ils contiennent » — 23/10. */
    public function test_les_roles_du_metier_figurent_avec_leurs_pages(): void
    {
        $metier = CatalogueDesPages::rolesDuMetier();

        $this->assertContains('charges', $metier['gerant']['pages']);
        $this->assertContains('tableau-de-bord', $metier['gerant']['pages']);
        $this->assertContains('tresorerie', $metier['caissier']['pages']);
        $this->assertNotContains('charges', $metier['commercial']['pages']);
        $this->assertContains('Mes prospections', $metier['commercial']['autres']);

        Volt::actingAs($this->superAdmin())->test('superadmin.roles-configures')
            ->assertSee('Rôles du métier')
            ->assertSee('Superviseur de ville')
            ->assertSee('Comptabilité');
    }

    /** « Ajoute le bouton Modifier » sur les rôles du métier — 23/10. */
    public function test_un_role_du_metier_se_modifie_puis_se_retablit(): void
    {
        $caissier = $this->compte('caissier');
        $this->actingAs($caissier)->get(route('charges'))->assertOk();

        Volt::actingAs($this->superAdmin())->test('superadmin.roles-configures')
            ->call('modifier', 'caissier')
            ->assertSet('libelle', 'Comptabilité')
            ->set('pagesCochees', ['tresorerie', 'caisse', 'banques', 'chiffre-affaires'])
            ->call('enregistrer')
            ->assertHasNoErrors();

        // Le rôle reste un rôle du métier : il n'est pas devenu un rôle configuré.
        $this->assertFalse(CatalogueDesPages::estConfigure('caissier'));
        CatalogueDesPages::oublier();

        $this->actingAs($caissier)->get(route('charges'))->assertForbidden();
        $this->actingAs($caissier)->get(route('chiffre-affaires'))->assertOk();
        // Ses autres écrans ne changent pas.
        $this->actingAs($caissier)->get(route('caissier.tableau-de-bord'))->assertOk();

        $menu = collect(MenuNavigation::pour($caissier))->flatMap(fn ($o) => isset($o['groupe']) ? array_column($o['groupe'], 'label') : [$o['label']])->all();
        $this->assertNotContains('Charges', $menu);
        $this->assertContains("Chiffre d'affaires", $menu);

        Volt::actingAs($this->superAdmin())->test('superadmin.roles-configures')->call('retablir', 'caissier');
        CatalogueDesPages::oublier();
        $this->actingAs($caissier)->get(route('charges'))->assertOk();
    }

    /** « Si un code est déjà enregistré, il ne doit pas s'afficher en haut comme s'il n'avait pas été créé. » */
    public function test_un_code_declare_ne_parait_plus_parmi_les_codes_sans_titulaire(): void
    {
        \Modules\Noyau\Imports\Modeles\CodeAgent::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'code' => 'AB', 'nom' => 'KOUASSI', 'occurrences' => 6554, 'est_actif' => true]);
        \Modules\Noyau\Imports\Modeles\CodeAgent::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'code' => 'DM', 'occurrences' => 345, 'est_actif' => true]);

        $ecran = Volt::actingAs($this->superAdmin())->test('superadmin.codes')->set('entrepriseId', (string) $this->e->id);

        $this->assertSame(['DM'], $ecran->instance()->nonNommes->pluck('code')->all());
        $ecran->assertSee('Codes vus dans les imports, encore sans titulaire')
            ->assertDontSee('6 554 fiche(s)');
    }

    // ------------------------------------------------------------------------------ outils

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'entreprise_id' => $this->e->id]);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($role), 'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }

    private function superAdmin(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        Role::findOrCreate('super_admin', 'web');

        $admin = User::create([
            'name' => 'Fondateur', 'email' => 'fondateur@plateforme.test', 'password' => 'mot-de-passe-de-test',
            'est_actif' => true, 'est_fondateur' => true,
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }
}

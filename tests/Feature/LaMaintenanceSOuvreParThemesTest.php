<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La Maintenance s'ouvre sur un tableau d'entrée, puis un thème à la fois — 25/11.
 *
 * « Ça ne présente pas les éléments clairement, on peut se perdre à l'intérieur. » Sept
 * sections empilées sont devenues cinq cartes ; chaque thème s'ouvre seul, par l'adresse.
 * Ces tests tiennent les trois choses qui ne doivent pas se perdre dans la refonte : chaque
 * thème mène bien à ce qu'il annonce, aucune fonction n'a disparu en route, et l'écran refuse
 * qui n'a pas la section — à l'ouverture comme à chaque clic.
 */
class LaMaintenanceSOuvreParThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'entreprise_id' => 0]);
    }

    public function test_la_page_s_ouvre_sur_les_cinq_themes(): void
    {
        $reponse = $this->actingAs($this->superAdmin('Fondateur', fondateur: true))
            ->get(route('super-admin.maintenance'))
            ->assertOk();

        foreach (['pannes', 'roles', 'habilitations', 'liaisons', 'donnees'] as $theme) {
            $reponse->assertSee(route('super-admin.maintenance', ['theme' => $theme]), false);
        }

        $reponse->assertSee('Pannes et santé')->assertSee('Rôles et pages')->assertSee('Habilitations')
            ->assertSee('COMPTAFLOW et IA')->assertSee('Effacer des données')
            // Le tableau d'entrée ne montre aucun formulaire : c'est ce qui le rend court.
            ->assertDontSee('Vider les ensembles cochés')->assertDontSee("Voir l'erreur", false);
    }

    public function test_chaque_theme_s_ouvre_seul(): void
    {
        $this->actingAs($this->superAdmin('Fondateur', fondateur: true));
        $page = fn (array $parametres) => $this->get(route('super-admin.maintenance', $parametres))->assertOk();

        $page(['theme' => 'pannes'])->assertSee("Voir l'erreur", false)->assertSee('encore ouvertes')
            ->assertSee('← Tous les thèmes')->assertDontSee('Effacer des données</span>', false);

        $page(['theme' => 'roles'])->assertSeeLivewire('superadmin.roles-configures')
            ->assertDontSeeLivewire('superadmin.habilitations');

        $page(['theme' => 'habilitations'])->assertSeeLivewire('superadmin.habilitations')
            ->assertDontSeeLivewire('superadmin.roles-configures');

        // L'écran de l'IA est refait ailleurs : on y mène, sans le reprendre ici.
        $page(['theme' => 'liaisons'])
            ->assertSee(route('super-admin.comptaflow.liaison'), false)
            ->assertSee(route('super-admin.ia.configuration'), false);

        // Les gestes d'effacement se choisissent avant d'afficher leur formulaire.
        $page(['theme' => 'donnees'])
            ->assertSee('Vider une partie')->assertSee('Vider toute une entreprise')->assertSee('Supprimer une entreprise')
            ->assertDontSee('— Choisir une entreprise —');

        $page(['theme' => 'donnees', 'geste' => 'partie'])->assertSee('Seul ce qui est coché part.')
            ->assertDontSee('Entreprise à supprimer');
        $page(['theme' => 'donnees', 'geste' => 'tout'])->assertSee('tout ce qui a été saisi ou importé')
            ->assertDontSee('Entreprise à supprimer');
        $page(['theme' => 'donnees', 'geste' => 'supprimer'])->assertSee('Entreprise à supprimer');

        // Une valeur inventée dans l'adresse n'ouvre rien : retour au tableau d'entrée.
        $page(['theme' => 'inexistant'])->assertSee('Effacer des données')->assertDontSee('← Tous les thèmes');
    }

    /** Les gestes graves passent par la boîte de l'application, plus par celle du navigateur. */
    public function test_aucune_boite_du_navigateur(): void
    {
        $gabarit = file_get_contents(base_path('Modules/SuperAdmin/resources/views/superadmin/maintenance.blade.php'));

        $this->assertStringNotContainsString('wire:confirm', $gabarit);
        $this->assertStringNotContainsString('confirm(', $gabarit);
    }

    public function test_un_compte_sans_la_section_est_refuse(): void
    {
        $secondaire = $this->superAdmin('Secondaire', habilitations: ['entreprises']);

        $this->actingAs($secondaire)->get(route('super-admin.maintenance'))->assertForbidden();
        $this->actingAs($secondaire)->get(route('super-admin.maintenance', ['theme' => 'donnees', 'geste' => 'tout']))
            ->assertForbidden();
    }

    /**
     * Le refus vaut aussi pour un clic, pas seulement pour l'ouverture : la route ne garde que
     * la première requête, les suivantes passent par `/livewire/update`.
     */
    public function test_un_clic_est_refuse_a_qui_n_a_plus_la_section(): void
    {
        $cible = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $ecran = Volt::actingAs($this->superAdmin('Fondateur', fondateur: true))
            ->test('superadmin.maintenance')
            ->set('entrepriseId', $cible->id)
            ->set('confirmation', 'Alpha');

        $this->actingAs($this->superAdmin('Secondaire', habilitations: ['entreprises']));

        $ecran->call('purger')->assertForbidden();
        $this->assertNotNull($cible->fresh());
    }

    private function superAdmin(string $nom, bool $fondateur = false, array $habilitations = []): User
    {
        $compte = User::create([
            'name' => $nom,
            'email' => str($nom)->slug().'@plateforme.test',
            'password' => 'mot-de-passe-de-test',
            'est_actif' => true,
            'est_fondateur' => $fondateur,
            'habilitations' => $habilitations,
        ]);

        $compte->assignRole('super_admin');

        return $compte->fresh();
    }
}

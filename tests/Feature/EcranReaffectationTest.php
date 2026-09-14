<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'écran de mutation — un formulaire qui poste, et un historique qui ne s'édite pas.
 */
class EcranReaffectationTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $sanPedro;

    private Site $siteUn;

    private User $gerant;

    private User $employe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->siteUn = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A2', 'nom' => 'Site 2', 'est_actif' => true]);
        $this->sanPedro = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'SPY', 'nom' => 'San Pedro', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->sanPedro->id, 'code' => 'SP', 'nom' => 'San Pedro', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->gerant = $this->compte('gerant', 'g@alpha.test');
        $this->employe = $this->compte('responsable_site', 'rs@alpha.test', [
            'ville_id' => $this->abidjan->id, 'site_id' => $this->siteUn->id,
        ]);
        $this->siteUn->update(['responsable_id' => $this->employe->id]);
    }

    public function test_un_post_ordinaire_suffit_a_muter_quelqu_un(): void
    {
        $this->actingAs($this->gerant)->post(route('reaffecter'), [
            'employe' => $this->employe->id,
            'ville' => $this->sanPedro->id,
            'site' => '',
            'role' => '',
            'motif' => 'Renfort',
        ])->assertRedirect(route('parametres', ['onglet' => 'reaffectations']));

        $this->assertSame($this->sanPedro->id, $this->employe->fresh()->ville_id);
        $this->assertSame(1, Reaffectation::withoutGlobalScopes()->count());
    }

    public function test_seul_le_gerant_reaffecte(): void
    {
        // Deux barrières se superposent, et ce n'est pas une redondance : le middleware de
        // route ferme l'adresse, le contrôleur revérifie le rôle. La première suffit ici ;
        // la seconde protège le jour où l'adresse serait ouverte plus largement.
        $this->actingAs($this->employe)->post(route('reaffecter'), [
            'employe' => $this->employe->id,
            'ville' => $this->sanPedro->id,
        ])->assertRedirect();

        $this->assertSame(0, Reaffectation::withoutGlobalScopes()->count());
        $this->assertSame($this->abidjan->id, $this->employe->fresh()->ville_id);
    }

    public function test_on_ne_mute_pas_quelqu_un_d_une_autre_entreprise(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $etranger = User::create([
            'entreprise_id' => $autre->id, 'name' => 'Étranger',
            'email' => 'e@beta.test', 'password' => 'motdepasse', 'est_actif' => true,
        ]);

        $this->actingAs($this->gerant)->post(route('reaffecter'), [
            'employe' => $etranger->id,
            'ville' => $this->sanPedro->id,
        ])->assertSessionHasErrors('employe');

        $this->assertSame(0, Reaffectation::withoutGlobalScopes()->count());
    }

    public function test_un_refus_metier_revient_avec_son_motif(): void
    {
        $this->actingAs($this->gerant)->post(route('reaffecter'), [
            'employe' => $this->employe->id,
            'ville' => $this->abidjan->id,
            'site' => $this->siteUn->id,
        ])->assertSessionHasErrors('employe');
    }

    public function test_l_onglet_reaffectations_est_en_lecture_seule(): void
    {
        (new \Modules\Noyau\Entreprises\Services\ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $this->employe, $this->sanPedro->id, null, null, 'Mutation de test', $this->gerant,
        );

        $this->actingAs($this->gerant);

        $html = Volt::test('gerant.parametres')->set('onglet', 'reaffectations')->html();

        $this->assertStringContainsString('Mutation de test', $html);
        $this->assertStringContainsString('Historique des réaffectations', $html);
        $this->assertStringNotContainsString('Réaffecter</button>', $html);
    }

    private function compte(string $role, string $email, array $extra = []): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Compte '.$role,
            'email' => $email, 'password' => 'motdepasse', 'est_actif' => true,
        ] + $extra);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }
}

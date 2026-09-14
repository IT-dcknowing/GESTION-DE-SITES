<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\PeutModifierUnAcces;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reprendre un accès existant, et les trois verrous qu'on avait confondus.
 *
 * **Le raisonnement d'origine et son erreur.** « Cet accès a déjà servi : son rôle et son
 * entreprise ne se reprennent plus, ses écritures y sont rattachées. » La seconde moitié est
 * vraie ; la première en tire une conclusion trop large. **Une écriture n'est pas rattachée à
 * un rôle** : une facture porte un site et un auteur, jamais « responsable de site ». Changer
 * le rôle de quelqu'un ne déplace donc rien — cela change ce qu'il verra demain, pas ce qu'il
 * a écrit hier.
 *
 * Trois verrous distincts, donc, et ces tests les fixent : l'entreprise reste figée, le rôle
 * se reprend sauf vers gérant et sauf pour le dernier gérant, la ville passe par la
 * réaffectation.
 */
class ReprendreUnAccesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['gerant', 'responsable_ville', 'responsable_site', 'commercial', 'caissier',
            'superviseur_recouvrement', 'agent_recouvrement'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'ABJ-1', 'nom' => 'Site 1', 'est_actif' => true]);
    }

    public function test_le_bouton_modifier_mene_a_une_fiche_qui_s_ouvre(): void
    {
        $gerant = $this->compte('gerant', 'gerant@repr.test');
        $commercial = $this->compte('commercial', 'com@repr.test', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($gerant)
            ->get(route('acces.creer'))
            ->assertOk()
            ->assertSee(route('acces.modifier', $commercial->id), false);

        $this->actingAs($gerant)
            ->get(route('acces.modifier', $commercial->id))
            ->assertOk()
            ->assertSee($commercial->email, false);
    }

    public function test_on_corrige_un_nom_et_une_adresse_sans_toucher_au_reste(): void
    {
        $gerant = $this->compte('gerant', 'gerant@repr.test');
        $commercial = $this->compte('commercial', 'com@repr.test', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($gerant)->post(route('acces.modifier.enregistrer', $commercial->id), [
            'nom' => 'Nom Corrigé',
            'email' => 'corrige@repr.test',
            'role' => 'commercial',
        ])->assertRedirect(route('acces.creer'));

        $frais = $commercial->fresh();

        $this->assertSame('Nom Corrigé', $frais->name);
        $this->assertSame('corrige@repr.test', $frais->email);
        $this->assertSame($this->abidjan->id, $frais->ville_id, "La ville ne se change pas par ici : elle passe par la réaffectation.");
    }

    public function test_le_role_se_reprend_dans_les_deux_sens(): void
    {
        $gerant = $this->compte('gerant', 'gerant@repr.test');
        $commercial = $this->compte('commercial', 'com@repr.test', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($gerant)->post(route('acces.modifier.enregistrer', $commercial->id), [
            'nom' => $commercial->name,
            'email' => $commercial->email,
            'role' => 'caissier',
        ])->assertRedirect();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->assertTrue($commercial->fresh()->hasRole('caissier'), "Un rôle n'emporte aucune écriture : il se reprend.");
        $this->assertFalse($commercial->fresh()->hasRole('commercial'));
    }

    public function test_on_ne_devient_pas_gerant_par_une_modification_d_acces(): void
    {
        $gerant = $this->compte('gerant', 'gerant@repr.test');
        $commercial = $this->compte('commercial', 'com@repr.test', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($gerant)->post(route('acces.modifier.enregistrer', $commercial->id), [
            'nom' => $commercial->name,
            'email' => $commercial->email,
            'role' => 'gerant',
        ])->assertSessionHasErrors('role');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $this->assertFalse($commercial->fresh()->hasRole('gerant'), "Le gérant répond de toute l'entreprise : son accès se crée.");
    }

    public function test_le_dernier_gerant_ne_se_demet_pas(): void
    {
        $gerant = $this->compte('gerant', 'gerant@repr.test');

        $permission = new PeutModifierUnAcces($gerant, $gerant);

        $this->assertTrue($permission->estLeDernierGerant());
        $this->assertFalse($permission->roleModifiable(), "Une entreprise sans direction n'a plus personne pour réparer l'erreur.");
    }

    public function test_un_second_gerant_rend_le_premier_reprenable(): void
    {
        $premier = $this->compte('gerant', 'gerant@repr.test');
        $second = $this->compte('gerant', 'gerant2@repr.test');

        $permission = new PeutModifierUnAcces($second, $premier);

        $this->assertFalse($permission->estLeDernierGerant());
        $this->assertTrue($permission->roleModifiable());
    }

    public function test_les_gestes_du_tableau_passent_par_un_vrai_post(): void
    {
        $gerant = $this->compte('gerant', 'gerant@repr.test');
        $commercial = $this->compte('commercial', 'com@repr.test', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($gerant)
            ->post(route('acces.agir', $commercial->id), ['geste' => 'basculer'])
            ->assertRedirect();

        $this->assertFalse((bool) $commercial->fresh()->est_actif, 'Révoquer doit marcher sans une ligne de JavaScript.');
    }

    private function compte(string $role, string $email, array $extra = []): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $email,
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ] + $extra);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le code de deux lettres : attribué par la plateforme, confirmé par son porteur.
 *
 * **Ce qui est en jeu.** Ce code est la seule chose qui, dans les fichiers du logiciel,
 * dise de qui vient une fiche — et donc à quel atelier elle appartient. Abidjan a deux
 * ateliers qu'aucun fichier ne sépare : seul le code tranche. Mal attribué, il ne se voit
 * pas. Les totaux restent plausibles, simplement faux.
 *
 * D'où la question posée à l'intéressé, sur n'importe quel écran, une fois. C'est la seule
 * vérification qui vaille : il lit son code sur chacune de ses fiches.
 */
class CodeAtelierTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_le_super_administrateur_donne_un_code_et_la_personne_doit_le_confirmer(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.codes.enregistrer'), ['utilisateur' => $koffi->id, 'code' => 'kz'])
            ->assertRedirect();

        // Le code est rangé en majuscules, comme le logiciel l'écrit dans ses numéros.
        $this->assertSame('KZ', CodeDeLAtelier::de($koffi->fresh())?->code);
        $this->assertTrue(CodeDeLAtelier::aConfirmer($koffi->fresh()));

        $this->assertDatabaseHas('activity_log', ['description' => 'Code atelier attribué']);
    }

    public function test_la_question_suit_la_personne_sur_n_importe_quel_ecran_puis_se_tait(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());

        // Sur un écran qui n'a rien à voir avec les imports : c'est tout l'intérêt, la
        // question se pose là où la personne se trouve.
        $this->actingAs($koffi->fresh())->get(route('mon-profil'))
            ->assertOk()
            ->assertSee("Votre code dans l'atelier", false)
            ->assertSee('Est-ce bien le vôtre', false);

        $this->actingAs($koffi->fresh())
            ->post(route('code-atelier.confirmer'))
            ->assertRedirect();

        $this->assertNotNull($koffi->fresh()->code_atelier_confirme_le);
        $this->assertDatabaseHas('activity_log', ['description' => 'Code atelier confirmé par son porteur']);

        // Répondue une fois, elle ne revient plus.
        $this->actingAs($koffi->fresh())->get(route('mon-profil'))
            ->assertOk()
            ->assertDontSee("Votre code dans l'atelier", false);
    }

    public function test_on_ne_pose_pas_la_question_a_qui_n_a_pas_de_code(): void
    {
        $sans = $this->compte('Ama Ackah', 'commercial', 'ama@alpha.test');

        $this->assertFalse(CodeDeLAtelier::aConfirmer($sans));

        $this->actingAs($sans)->get(route('mon-profil'))
            ->assertOk()
            ->assertDontSee("Votre code dans l'atelier", false);
    }

    public function test_chacun_corrige_le_sien_depuis_son_profil(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());
        CodeDeLAtelier::confirmer($koffi->fresh());

        $this->actingAs($koffi->fresh())
            ->post(route('mon-profil.liaison'), ['liaison' => 'ky'])
            ->assertRedirect();

        $this->assertSame('KY', CodeDeLAtelier::de($koffi->fresh())?->code);

        // Une personne ne porte qu'un code : l'ancien est détaché, sinon ses fiches se
        // partageraient entre deux ateliers.
        $this->assertNull(CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->value('user_id'));

        // Et la confirmation tombe : elle portait sur KZ, pas sur KY.
        $this->assertNull($koffi->fresh()->code_atelier_confirme_le);
        $this->assertDatabaseHas('activity_log', ['description' => 'Code atelier corrigé par son porteur']);
    }

    public function test_personne_ne_reprend_le_code_d_un_collegue_depuis_son_profil(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        $ama = $this->compte('Ama Ackah', 'commercial', 'ama@alpha.test');

        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());

        $this->actingAs($ama)
            ->post(route('mon-profil.liaison'), ['liaison' => 'KZ'])
            ->assertRedirect()
            ->assertSessionHas('refus-profil');

        // Rien n'a bougé : le code reste à Koffi, et Ama n'en a toujours pas.
        $this->assertSame($koffi->id, (int) CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->value('user_id'));
        $this->assertNull(CodeDeLAtelier::de($ama->fresh()));
    }

    public function test_le_super_administrateur_peut_deplacer_un_code_et_l_annonce(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        $ama = $this->compte('Ama Ackah', 'commercial', 'ama@alpha.test');

        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());
        CodeDeLAtelier::confirmer($koffi->fresh());

        $reponse = $this->actingAs($this->superAdmin())
            ->post(route('super-admin.codes.enregistrer'), ['utilisateur' => $ama->id, 'code' => 'KZ']);

        $reponse->assertRedirect()->assertSessionHas('annonce');
        $this->assertStringContainsString('repris à « Koffi Yao »', (string) session('annonce'));

        $this->assertSame($ama->id, (int) CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->value('user_id'));

        // Celui qui perd le code perd aussi sa confirmation : elle portait sur un code
        // qui n'est plus le sien.
        $this->assertNull($koffi->fresh()->code_atelier_confirme_le);
    }

    public function test_vider_le_champ_retire_le_code(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.codes.enregistrer'), ['utilisateur' => $koffi->id, 'code' => '']);

        $this->assertNull(CodeDeLAtelier::de($koffi->fresh()));
        $this->assertDatabaseHas('activity_log', ['description' => 'Code atelier retiré']);
    }

    public function test_un_code_qui_n_a_pas_deux_lettres_est_refuse(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');

        $this->actingAs($koffi)
            ->post(route('mon-profil.liaison'), ['liaison' => 'K'])
            ->assertSessionHas('refus-profil');

        $this->assertNull(CodeDeLAtelier::de($koffi->fresh()));
    }

    public function test_un_compte_de_la_plateforme_ne_recoit_pas_de_code(): void
    {
        $admin = $this->superAdmin();

        // Les comptes de la plateforme ne travaillent dans aucun atelier : leur donner un
        // code rattacherait des fiches à quelqu'un qui n'en produit pas.
        $this->actingAs($admin)
            ->post(route('super-admin.codes.enregistrer'), ['utilisateur' => $admin->id, 'code' => 'PL'])
            ->assertSessionHas('refus-code');

        $this->assertSame(0, CodeAgent::withoutGlobalScopes()->where('code', 'PL')->count());
    }

    public function test_l_ecran_liste_le_personnel_de_l_entreprise_choisie(): void
    {
        $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.codes', ['entreprise' => $this->entreprise->id]))
            ->assertOk()
            ->assertSee('Koffi Yao')
            ->assertSee("Codes d'atelier");
    }

    private function compte(string $nom, string $role, string $email): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => $nom,
            'email' => $email,
            'password' => Hash::make('password'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }

    private function superAdmin(): User
    {
        $existant = User::withoutGlobalScopes()->where('email', 'sa@exemple.test')->first();

        if ($existant) {
            return $existant;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(SuperAdminSeeder::EQUIPE_PLATEFORME);

        Role::firstOrCreate([
            'name' => 'super_admin', 'guard_name' => 'web',
            'entreprise_id' => SuperAdminSeeder::EQUIPE_PLATEFORME,
        ]);

        $compte = User::create([
            'entreprise_id' => null, 'name' => 'Super Admin',
            'email' => 'sa@exemple.test', 'password' => Hash::make('password'),
            'est_actif' => true, 'est_fondateur' => true,
        ]);
        $compte->assignRole('super_admin');

        return $compte->fresh();
    }
}

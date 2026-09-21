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
use Modules\Noyau\Imports\Services\Rattachement;
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

    /*
    |--------------------------------------------------------------------------
    | « Où vous joindre » — le numéro qu'on n'a pas saisi à l'ouverture du compte
    |--------------------------------------------------------------------------
    */

    public function test_la_boite_demande_son_numero_a_qui_n_en_a_pas(): void
    {
        $sans = $this->compte('Ama Ackah', 'commercial', 'ama@alpha.test');

        $this->actingAs($sans)->get(route('mon-profil'))
            ->assertOk()
            ->assertSee('Où vous joindre', false);

        $this->actingAs($sans->fresh())
            ->post(route('mon-profil.telephone'), ['telephone' => '+225 07 00 00 00 00'])
            ->assertRedirect();

        $this->assertSame('+225 07 00 00 00 00', $sans->fresh()->telephone);
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Numéro de téléphone renseigné par son titulaire',
        ]);

        // Donnée une fois, la question ne revient plus.
        $this->actingAs($sans->fresh())->get(route('mon-profil'))
            ->assertOk()
            ->assertDontSee('Où vous joindre', false);
    }

    public function test_on_ne_demande_rien_a_qui_a_deja_son_numero(): void
    {
        $avec = $this->compte('Yao Konan', 'commercial', 'yao@alpha.test');
        $avec->forceFill(['telephone' => '0700000000'])->save();

        $this->actingAs($avec->fresh())->get(route('mon-profil'))
            ->assertOk()
            ->assertDontSee('Où vous joindre', false);
    }

    public function test_un_numero_trop_court_est_refuse(): void
    {
        $sans = $this->compte('Ama Ackah', 'commercial', 'ama@alpha.test');

        // Huit chiffres au moins : en deçà, ce n'est pas un numéro mais une faute de
        // frappe, et mieux vaut le dire que la composer plus tard.
        $this->actingAs($sans)
            ->from(route('mon-profil'))
            ->post(route('mon-profil.telephone'), ['telephone' => '07 07'])
            ->assertSessionHasErrors('telephone');

        $this->assertNull($sans->fresh()->telephone);
    }

    public function test_le_numero_donne_par_la_personne_se_lit_chez_le_super_administrateur(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');

        $this->actingAs($koffi)->post(route('mon-profil.telephone'), ['telephone' => '0707070707']);

        // Un seul champ en base : ce que la personne écrit est ce que l'administrateur lit.
        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.codes', ['entreprise' => $this->entreprise->id]))
            ->assertOk()
            ->assertSee('0707070707');
    }

    public function test_personne_ne_renseigne_le_numero_d_un_autre(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        $ama = $this->compte('Ama Ackah', 'commercial', 'ama@alpha.test');

        // L'identifiant reçu est ignoré : le numéro se pose sur le compte connecté, et sur
        // lui seul. Sans cela, un formulaire recopié modifierait la fiche d'un collègue.
        $this->actingAs($koffi)->post(route('mon-profil.telephone'), [
            'telephone' => '0707070707',
            'utilisateur' => $ama->id,
        ])->assertRedirect();

        $this->assertSame('0707070707', $koffi->fresh()->telephone);
        $this->assertNull($ama->fresh()->telephone);
    }

    /*
    |--------------------------------------------------------------------------
    | Code-import — ceux qui saisissent dans le logiciel sans avoir de compte
    |--------------------------------------------------------------------------
    */

    public function test_la_section_code_import_liste_les_codes_sans_titulaire(): void
    {
        $this->codeVuALImport('TT', 412);
        $porte = $this->codeVuALImport('KZ', 900);
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        $porte->forceFill(['user_id' => $koffi->id])->save();

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.codes', ['entreprise' => $this->entreprise->id, 'vue' => 'import']))
            ->assertOk()
            ->assertSee('Code-import')
            ->assertSee('TT')
            // Le code déjà rattaché à quelqu'un n'a rien à faire dans cette section : il se
            // corrige sur la ligne de son porteur.
            ->assertDontSee('900 fiche(s)', false);
    }

    public function test_on_nomme_la_personne_derriere_un_code_sans_lui_ouvrir_d_acces(): void
    {
        $code = $this->codeVuALImport('TT', 412);

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.codes.import'), [
                'code_agent' => $code->id,
                'nom' => 'TRAORE',
                'prenom' => 'Ali',
                'fonction' => 'Réceptionnaire',
                'site_id' => $this->site->id,
            ])->assertRedirect();

        $code->refresh();

        $this->assertSame('TRAORE', $code->nom);
        $this->assertSame('Ali', $code->prenom);
        $this->assertSame('Réceptionnaire', $code->fonction);
        $this->assertSame($this->site->id, $code->site_id);
        // L'atelier désigne sa ville : sans cela le rattachement resterait muet.
        $this->assertSame($this->ville->id, $code->ville_id);

        // Et surtout : aucun compte n'a été ouvert, aucun code rattaché à personne.
        $this->assertNull($code->user_id);
        $this->assertSame(0, User::withoutGlobalScopes()->where('name', 'like', '%TRAORE%')->count());
    }

    public function test_le_bouton_creer_le_compte_ouvre_le_formulaire_deja_rempli(): void
    {
        $code = $this->codeVuALImport('TT', 412);
        $code->forceFill([
            'nom' => 'TRAORE', 'prenom' => 'Ali', 'fonction' => 'Réceptionnaire',
            'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
        ])->save();

        // Ce qu'on vient de noter ne doit pas être retapé dans l'écran suivant.
        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.acces.creer', [
                'entreprise' => $this->entreprise->id,
                'code' => 'TT',
                'nom' => 'TRAORE Ali',
                'ville' => $this->ville->id,
                'site' => $this->site->id,
            ]))
            ->assertOk()
            ->assertSee('TRAORE Ali')
            ->assertSee('TT');
    }

    public function test_un_code_deja_rattache_a_un_compte_ne_se_renseigne_pas_par_cette_section(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());
        $code = CodeDeLAtelier::de($koffi->fresh());

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.codes.import'), ['code_agent' => $code->id, 'nom' => 'QUELQU UN'])
            ->assertSessionHas('refus-code');

        $this->assertNull($code->fresh()->nom);
    }

    public function test_un_atelier_d_une_autre_entreprise_n_est_pas_retenu(): void
    {
        $code = $this->codeVuALImport('TT', 412);

        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $villeAilleurs = Ville::create([
            'entreprise_id' => $autre->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $siteAilleurs = Site::create([
            'entreprise_id' => $autre->id, 'ville_id' => $villeAilleurs->id,
            'code' => 'BKE-1', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        // Un identifiant recopié à la main ne doit pas rattacher des fiches à l'atelier
        // d'une autre maison.
        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.codes.import'), [
                'code_agent' => $code->id,
                'nom' => 'TRAORE',
                'ville_id' => $villeAilleurs->id,
                'site_id' => $siteAilleurs->id,
            ])->assertRedirect();

        $this->assertNull($code->fresh()->site_id);
        $this->assertNull($code->fresh()->ville_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Une confirmation qui tarde ne bloque pas les imports
    |--------------------------------------------------------------------------
    */

    public function test_le_rattachement_sert_des_qu_il_est_pose_meme_sans_confirmation(): void
    {
        $koffi = $this->compte('Koffi Yao', 'commercial', 'koffi@alpha.test');
        CodeDeLAtelier::attribuer($koffi, 'KZ', $this->superAdmin());

        $code = CodeDeLAtelier::de($koffi->fresh());
        $code->forceFill(['ville_id' => $this->ville->id, 'site_id' => $this->site->id])->save();

        // La personne n'a rien confirmé — et c'est justement le cas à vérifier.
        $this->assertTrue(CodeDeLAtelier::aConfirmer($koffi->fresh()));

        $ou = (new Rattachement($this->entreprise->id))->resoudre(null, 'FR-KZN° 010669', null);

        // Attendre une réponse pour rattacher les fiches reviendrait à perdre le travail
        // de ceux qui n'ont pas encore ouvert l'application.
        $this->assertSame($this->ville->id, $ou['ville_id']);
        $this->assertSame($this->site->id, $ou['site_id']);
        $this->assertSame('KZ', $ou['code']);
    }

    private function codeVuALImport(string $code, int $occurrences): CodeAgent
    {
        return CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'code' => $code,
            'occurrences' => $occurrences,
            'est_actif' => true,
        ]);
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

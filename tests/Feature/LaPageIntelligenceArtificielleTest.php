<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchemaDisponible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\IA\Services\ClientIA;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La page Intelligence artificielle du Super admin — 25/11.
 *
 * Demandes du propriétaire : une IA pour tous les usages en un geste, les quatre fournisseurs côte
 * à côte, et l'usage de l'IA accordé à un rôle ou à un compte, entreprise par entreprise.
 */
class LaPageIntelligenceArtificielleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Entreprise $alpha;

    private Entreprise $beta;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaDisponible::oublier();
        HabilitationsDActions::oublier();

        $this->alpha = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
        $this->beta = Entreprise::create(['nom' => 'Bêta', 'slug' => 'beta', 'est_active' => true]);
        ProvisionneurEntreprise::creerRoles($this->alpha);
        ProvisionneurEntreprise::creerRoles($this->beta);

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::create([
            'name' => 'Fondateur', 'email' => 'fondateur@plateforme.test', 'password' => Hash::make('mot-de-passe-de-test'),
            'est_actif' => true, 'est_fondateur' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    public function test_la_page_s_ouvre_dans_l_ordre_demande(): void
    {
        $this->actingAs($this->admin)->get(route('super-admin.ia.configuration'))
            ->assertOk()
            ->assertSeeInOrder(['1. Une IA pour tout', '2. Fournisseurs et clés', 'OpenAI', 'Anthropic Claude', 'Google Gemini', 'OpenRouter',
                '3. Affectation par usage', 'Imputations comptables', '4. Qui peut utiliser l', 'Alpha'])
            ->assertSee('le dernier enregistrement l\'emporte', false);
    }

    public function test_la_cle_d_un_fournisseur_reste_chiffree_et_ne_se_reaffiche_pas(): void
    {
        $this->actingAs($this->admin)->post(route('super-admin.ia.enregistrer'), [
            'fournisseur' => 'openrouter', 'modele' => 'mistral/large', 'cle_api' => 'sk-tres-secrete-123', 'actif' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $chiffree = DB::table('configurations_ia')->where('fournisseur', 'openrouter')->value('cle_api_chiffree');
        $this->assertNotSame('sk-tres-secrete-123', $chiffree);
        $this->assertStringNotContainsString('sk-tres-secrete-123', $chiffree);
        $this->assertSame('sk-tres-secrete-123', Crypt::decryptString($chiffree));

        $this->actingAs($this->admin)->get(route('super-admin.ia.configuration'))
            ->assertOk()->assertDontSee('sk-tres-secrete-123')->assertDontSee($chiffree)->assertSee('Clé enregistrée');

        // Le champ laissé vide garde la clé.
        $this->actingAs($this->admin)->post(route('super-admin.ia.enregistrer'), [
            'fournisseur' => 'openrouter', 'modele' => 'mistral/medium', 'cle_api' => '', 'actif' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sk-tres-secrete-123', Crypt::decryptString(DB::table('configurations_ia')->where('fournisseur', 'openrouter')->value('cle_api_chiffree')));
        $this->assertSame('mistral/medium', DB::table('configurations_ia')->where('fournisseur', 'openrouter')->value('modele'));
    }

    public function test_une_ia_pour_tout_affecte_chaque_usage(): void
    {
        $this->configurer('anthropic');
        // Un usage déjà réglé garde ses instructions quand le champ commun est vide.
        DB::table('affectations_ia')->insert(['usage' => 'rapport', 'fournisseur' => 'openai', 'modele' => 'gpt-x',
            'instructions' => 'Ton sobre.', 'actif' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin)->post(route('super-admin.ia.affecter-partout'), [
            'fournisseur' => 'anthropic', 'modele' => 'claude-essai', 'instructions' => '', 'actif' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $affectations = DB::table('affectations_ia')->get()->keyBy('usage');
        $this->assertCount(2, $affectations);

        foreach (['rapport', 'imputations'] as $usage) {
            $this->assertSame('anthropic', $affectations[$usage]->fournisseur);
            $this->assertSame('claude-essai', $affectations[$usage]->modele);
            $this->assertTrue((bool) $affectations[$usage]->actif);
            $this->assertSame('claude-essai', ClientIA::pour($usage)?->modele);
        }

        $this->assertSame('Ton sobre.', $affectations['rapport']->instructions);
        $this->assertNull($affectations['imputations']->instructions);

        // Des instructions communes remplacent celles de chaque usage ; puis un usage se règle seul.
        $this->actingAs($this->admin)->post(route('super-admin.ia.affecter-partout'), [
            'fournisseur' => 'anthropic', 'modele' => '', 'instructions' => 'En français.', 'actif' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['En français.'], DB::table('affectations_ia')->pluck('instructions')->unique()->values()->all());
        $this->assertSame(2, DB::table('affectations_ia')->whereNull('modele')->count());

        $this->configurer('openai');
        $this->actingAs($this->admin)->post(route('super-admin.ia.affecter'), [
            'usage' => 'imputations', 'fournisseur' => 'openai', 'modele' => '', 'instructions' => '', 'actif' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('openai', DB::table('affectations_ia')->where('usage', 'imputations')->value('fournisseur'));
        $this->assertSame('anthropic', DB::table('affectations_ia')->where('usage', 'rapport')->value('fournisseur'));
    }

    public function test_une_ia_pour_tout_refuse_un_fournisseur_sans_cle(): void
    {
        $this->actingAs($this->admin)->post(route('super-admin.ia.affecter-partout'), ['fournisseur' => 'google', 'actif' => '1'])
            ->assertSessionHasErrors('fournisseur');
        $this->assertSame(0, DB::table('affectations_ia')->count());
    }

    public function test_accorder_l_ia_du_rapport_a_un_role_ne_vaut_que_dans_son_entreprise(): void
    {
        $gerantAlpha = $this->compte($this->alpha, 'gerant');
        $gerantBeta = $this->compte($this->beta, 'gerant');
        $this->assertFalse($this->peut($gerantAlpha, 'rapport', 'ia'));

        $this->actingAs($this->admin)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->alpha->id, 'beneficiaire' => 'role', 'role' => 'gerant', 'gestes' => ['rapport.ia'], 'sens' => 'accorder',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($this->peut($gerantAlpha, 'rapport', 'ia'));
        $this->assertFalse($this->peut($gerantBeta, 'rapport', 'ia'));
        $this->assertDatabaseHas('habilitations_actions', ['domaine' => 'rapport', 'action' => 'ia', 'role' => 'gerant', 'user_id' => null, 'entreprise_id' => $this->alpha->id, 'accorde' => true]);

        // Une ligne commune (Maintenance) se retire chez Bêta seulement.
        HabilitationAction::create(['domaine' => 'rapport', 'action' => 'ia', 'role' => 'gerant', 'accorde' => true]);
        $this->actingAs($this->admin)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->beta->id, 'beneficiaire' => 'role', 'role' => 'gerant', 'gestes' => ['rapport.ia'], 'sens' => 'retirer',
        ])->assertRedirect()->assertSessionHasNoErrors();
        HabilitationsDActions::oublier();
        $this->assertFalse($this->peut($gerantBeta, 'rapport', 'ia'));
        $this->assertTrue($this->peut($gerantAlpha, 'rapport', 'ia'));

        $this->actingAs($this->admin)->get(route('super-admin.ia.configuration', ['entreprise' => $this->beta->id]))
            ->assertOk()->assertSee('dans cette entreprise');
    }

    public function test_accorder_l_ia_du_rapport_a_un_compte_puis_l_effacer(): void
    {
        $commercial = $this->compte($this->alpha, 'commercial');
        $collegue = $this->compte($this->alpha, 'commercial', 'b');
        $etranger = $this->compte($this->beta, 'commercial');

        // Un compte d'une autre entreprise est refusé, même si le navigateur l'envoie.
        $this->actingAs($this->admin)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->alpha->id, 'beneficiaire' => 'compte', 'compte' => $etranger->id, 'gestes' => ['rapport.ia'], 'sens' => 'accorder',
        ])->assertSessionHasErrors('compte');

        $this->actingAs($this->admin)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->alpha->id, 'beneficiaire' => 'compte', 'compte' => $commercial->id,
            'gestes' => ['rapport.ia', 'comptabilite.imputer_ia'], 'sens' => 'accorder',
        ])->assertRedirect()->assertSessionHasNoErrors();

        HabilitationsDActions::oublier();
        $this->assertTrue($this->peut($commercial, 'rapport', 'ia'));
        $this->assertTrue($this->peut($commercial, 'comptabilite', 'imputer_ia'));
        $this->assertFalse($this->peut($collegue, 'rapport', 'ia'));
        $this->assertFalse($this->peut($etranger, 'rapport', 'ia'));

        $this->actingAs($this->admin)->get(route('super-admin.ia.configuration', ['entreprise' => $this->alpha->id]))
            ->assertOk()->assertSee($commercial->name.' · accordé');

        $ligne = HabilitationAction::query()->where('user_id', $commercial->id)->where('domaine', 'rapport')->firstOrFail();
        $this->actingAs($this->admin)->post(route('super-admin.ia.habilitations.effacer'), ['ligne' => $ligne->id])->assertRedirect();
        HabilitationsDActions::oublier();
        $this->assertFalse($this->peut($commercial, 'rapport', 'ia'));

        // Une ligne qui n'est pas un geste d'IA ne s'efface pas d'ici.
        $autre = HabilitationAction::create(['domaine' => 'creances', 'action' => 'supprimer', 'user_id' => $commercial->id, 'accorde' => true]);
        $this->actingAs($this->admin)->post(route('super-admin.ia.habilitations.effacer'), ['ligne' => $autre->id])->assertNotFound();
    }

    public function test_l_ia_d_imputation_garde_ses_titulaires_du_depart(): void
    {
        $this->assertTrue($this->peut($this->compte($this->alpha, 'gerant'), 'comptabilite', 'imputer_ia'));
        // Depuis le module Comptabilité (25/11), le gérant seul l'a au départ : la comptabilité
        // la reçoit par la Maintenance ou par cet écran.
        $this->assertFalse($this->peut($this->compte($this->alpha, 'caissier'), 'comptabilite', 'imputer_ia'));
        $this->assertFalse($this->peut($this->compte($this->alpha, 'commercial'), 'comptabilite', 'imputer_ia'));

        // Le gérant ne perd pas un geste qu'il tient du code.
        $this->actingAs($this->admin)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->alpha->id, 'beneficiaire' => 'role', 'role' => 'gerant', 'gestes' => ['comptabilite.imputer_ia'], 'sens' => 'retirer',
        ])->assertSessionHasErrors('role');
    }

    public function test_un_compte_qui_n_est_pas_super_admin_est_refuse(): void
    {
        $gerant = $this->compte($this->alpha, 'gerant');

        // Le refus de la route est un 403 ; un navigateur est renvoyé vers son espace (bootstrap/app.php,
        // « deux onglets, deux comptes »), sans que rien ne soit écrit.
        $json = ['Accept' => 'application/json'];
        $this->actingAs($gerant)->get(route('super-admin.ia.configuration'), $json)->assertForbidden();
        $this->actingAs($gerant)->get(route('super-admin.ia.configuration'))->assertRedirect(route('redirection'));
        $this->actingAs($gerant)->post(route('super-admin.ia.enregistrer'), ['fournisseur' => 'openai', 'modele' => 'x', 'cle_api' => 'k'], $json)->assertForbidden();
        $this->actingAs($gerant)->post(route('super-admin.ia.affecter-partout'), ['fournisseur' => 'openai'], $json)->assertForbidden();
        $this->actingAs($gerant)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->alpha->id, 'beneficiaire' => 'role', 'role' => 'gerant', 'gestes' => ['rapport.ia'], 'sens' => 'accorder',
        ], $json)->assertForbidden();

        // Un Super admin secondaire sans la section Maintenance n'écrit rien — l'action le revérifie
        // elle-même, même si la route perdait son middleware.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $secondaire = User::create([
            'name' => 'Secondaire', 'email' => 'secondaire@plateforme.test', 'password' => Hash::make('mot-de-passe-de-test'),
            'est_actif' => true, 'habilitations' => ['acces'],
        ]);
        $secondaire->assignRole('super_admin');
        $this->withoutMiddleware(\App\Http\Middleware\VerifieHabilitation::class);
        $this->actingAs($secondaire)->post(route('super-admin.ia.habiliter'), [
            'entreprise_id' => $this->alpha->id, 'beneficiaire' => 'role', 'role' => 'gerant', 'gestes' => ['rapport.ia'], 'sens' => 'accorder',
        ])->assertForbidden();
        $this->actingAs($secondaire)->post(route('super-admin.ia.affecter-partout'), ['fournisseur' => 'openai'])->assertForbidden();

        $this->assertSame(0, DB::table('configurations_ia')->count());
        $this->assertSame(0, DB::table('habilitations_actions')->count());
    }

    /**
     * Les rôles se lisent dans l'équipe de l'entreprise du compte (en ligne, `DefinirEquipePermissions`
     * la pose à chaque requête) ; relus à neuf, comme au prochain affichage.
     */
    private function peut(User $compte, string $domaine, string $action): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($compte->entreprise_id);
        HabilitationsDActions::oublier();

        return HabilitationsDActions::accorde($compte->fresh(), $domaine, $action);
    }

    private function configurer(string $fournisseur): void
    {
        DB::table('configurations_ia')->insert([
            'fournisseur' => $fournisseur, 'modele' => 'modele-'.$fournisseur, 'cle_api_chiffree' => Crypt::encryptString('cle-'.$fournisseur),
            'actif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function compte(Entreprise $entreprise, string $role, ?string $suffixe = null): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($entreprise->id);

        $compte = User::create([
            'entreprise_id' => $entreprise->id, 'name' => ucfirst($role).' '.$entreprise->slug.($suffixe ?? ''),
            'email' => $role.($suffixe ? '-'.$suffixe : '').'@'.$entreprise->slug.'.test',
            'password' => Hash::make('motdepasse123'), 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

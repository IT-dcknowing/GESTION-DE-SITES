<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\IA\Modeles\ProductionIA;
use Modules\Noyau\IA\Services\ClientIA;
use Modules\Noyau\Rapport\Services\AgentDuRapport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'assistant IA du rapport — 10/11.
 *
 * « L'IA sera sur habilitation » ; « une IA par usage » ; « un bouton pour revenir à l'état du système » ;
 * « on peut lui demander des documents ». Les appels au fournisseur sont simulés (`Http::fake`).
 */
class L_assistant_du_rapport_Test extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->gerant = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $ville->id, 'site_id' => $site->id, 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();
    }

    public function test_par_defaut_personne_n_a_l_assistant(): void
    {
        $this->assertFalse(HabilitationsDActions::accorde($this->gerant, 'rapport', 'ia'));

        $this->accorder('voir');
        $ecran = Volt::actingAs($this->gerant)->test('pilotage.rapport')->set('exercice', 2026);

        $this->assertArrayNotHasKey('ia', $ecran->instance()->onglets, 'L\'onglet Assistant IA n\'existe que pour qui a rapport.ia.');
        $ecran->call('aller', 'ia')->assertSet('vue', 'presentation');
    }

    public function test_l_onglet_ia_est_le_dernier_pour_qui_l_a_recu(): void
    {
        $this->accorder('voir');
        $this->accorder('ia');

        $onglets = array_keys(Volt::actingAs($this->gerant)->test('pilotage.rapport')->instance()->onglets);

        $this->assertSame('ia', end($onglets));
    }

    public function test_un_usage_sans_affectation_n_a_pas_d_ia_et_avec_affectation_si(): void
    {
        $this->assertNull(ClientIA::pour('rapport'));

        $this->affecter('rapport', 'anthropic');

        $ia = ClientIA::pour('rapport');
        $this->assertNotNull($ia);
        $this->assertSame('anthropic', $ia->fournisseur);
        $this->assertNull(ClientIA::pour('imputations'), 'Chaque usage a son affectation.');
    }

    public function test_l_assistant_enregistre_une_analyse_puis_le_bouton_revient_a_l_etat_du_systeme(): void
    {
        $this->accorder('voir');
        $this->accorder('ia');
        $this->affecter('rapport', 'anthropic');

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'enregistrer_analyse',
                'input' => ['titre' => 'Analyse 2026', 'contenu_markdown' => "## Constat\n- Le CA progresse", 'style' => ['accent' => '#2563EB', 'mise_en_page' => 'sobre']]]]])
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Analyse enregistrée.']]]),
        ]);

        $this->actingAs($this->gerant);
        $analyse = (new AgentDuRapport($this->gerant, 2026))->analyserLeRapport('sobre, accent bleu');

        $this->assertSame('Analyse 2026', $analyse->titre);
        $this->assertSame('#2563EB', $analyse->styleSur()['accent']);
        $this->assertSame('sobre', $analyse->styleSur()['mise_en_page']);

        $ecran = Volt::actingAs($this->gerant)->test('pilotage.rapport')->set('exercice', 2026)->call('aller', 'ia');
        $ecran->assertSee('Analyse 2026')->assertSee('Rédigée par l\'assistant IA');

        $ecran->call('revenirALEtat')->assertSee('Analyse du système')->assertDontSee('Analyse 2026');
        $this->assertSame(0, ProductionIA::query()->where('type', 'analyse')->count());
    }

    public function test_une_couleur_ou_une_mise_en_page_hors_liste_blanche_est_ecartee(): void
    {
        $style = ProductionIA::styleValide(['accent' => 'red; background:url(x)', 'fond' => '#ZZZZZZ', 'mise_en_page' => 'flottante']);

        $this->assertSame(['accent' => '#C8102E', 'fond' => '#F4F3EF', 'mise_en_page' => 'standard'], $style);
    }

    public function test_le_html_glisse_par_l_ia_est_neutralise(): void
    {
        $p = new ProductionIA(['contenu' => "Bonjour <script>alert(1)</script> [lien](javascript:alert(1))"]);

        $this->assertStringNotContainsString('<script>', $p->html());
        $this->assertStringNotContainsString('href="javascript:', $p->html());
    }

    public function test_un_outil_est_refuse_sans_habilitation_ia(): void
    {
        $this->accorder('voir');
        $this->affecter('rapport', 'anthropic');
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'lire_rapport', 'input' => ['section' => 'ca']]]])
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Je ne peux pas.']]]),
        ]);

        $this->actingAs($this->gerant);
        (new AgentDuRapport($this->gerant, 2026))->repondre('Donne-moi le CA');

        Http::assertSent(fn ($requete) => str_contains(json_encode($requete->data()), 'Erreur'));
    }

    public function test_le_super_admin_teste_et_affecte_une_ia_par_usage(): void
    {
        $this->affecter('imputations', 'openai');

        $this->assertNotNull(ClientIA::pour('imputations'));
        $this->assertNull(ClientIA::pour('rapport'));
    }

    // ------------------------------------------------------------------------------ outils

    private function accorder(string $action): void
    {
        HabilitationAction::query()->create(['domaine' => 'rapport', 'action' => $action, 'role' => null, 'user_id' => $this->gerant->id, 'accorde' => true]);
        HabilitationsDActions::oublier();
    }

    private function affecter(string $usage, string $fournisseur): void
    {
        DB::table('configurations_ia')->updateOrInsert(['fournisseur' => $fournisseur], [
            'modele' => 'modele-test', 'cle_api_chiffree' => Crypt::encryptString('cle-secrete'), 'actif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('affectations_ia')->updateOrInsert(['usage' => $usage], ['fournisseur' => $fournisseur, 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}

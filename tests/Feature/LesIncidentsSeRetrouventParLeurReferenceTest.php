<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\JournalDesIncidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Database\Seeders\SuperAdminSeeder;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Une panne se retrouve par la référence lue à l'écran — demandé le 09/10, après
 * « ERR-XEYTWV » sur `/banques`.
 *
 * Ce que le test tient : la panne s'écrit en base sous sa référence ; la fiche de l'incident
 * s'ouvre au super administrateur ; un incident d'avant la table se relit dans le journal du
 * serveur ; la page Maintenance liste les références et ouvre celle qu'on y tape ; et la
 * phrase retirée de la page de panne ne revient pas.
 */
class LesIncidentsSeRetrouventParLeurReferenceTest extends TestCase
{
    use RefreshDatabase;

    private ?string $journalDeTest = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.rendre_la_page_de_panne_en_test' => true]);

        // Un dossier de journal à ce test : celui de `storage/logs` porte les pannes des
        // autres tests, et les compterait ici.
        JournalDesIncidents::$dossierDuJournal = sys_get_temp_dir().'/journal-incidents-'.getmypid();
        @mkdir(JournalDesIncidents::$dossierDuJournal);

        Route::get('/tests/panne-banques', function () {
            throw new RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'gs.libelles_de_banque' doesn't exist");
        })->middleware('web');
    }

    protected function tearDown(): void
    {
        if ($this->journalDeTest !== null) {
            @unlink($this->journalDeTest);
        }

        JournalDesIncidents::$dossierDuJournal = null;

        parent::tearDown();
    }

    public function test_la_panne_s_ecrit_en_base_et_la_phrase_retiree_ne_revient_pas(): void
    {
        $reponse = $this->get('/tests/panne-banques')->assertStatus(500);

        $reponse->assertDontSee("Une opération n'a pas abouti", false);
        $this->assertSame(1, preg_match('/ERR-[A-Z0-9]{6}/', $reponse->getContent(), $trouve));

        $ligne = DB::table('incidents')->where('reference', $trouve[0])->first();
        $this->assertNotNull($ligne);
        $this->assertStringContainsString('libelles_de_banque', $ligne->message);
        $this->assertStringContainsString('/tests/panne-banques', $ligne->url);
    }

    public function test_la_fiche_de_l_incident_dit_la_cause(): void
    {
        $reference = $this->referenceDUnePanne();

        $this->actingAs($this->superAdmin())->get(route('super-admin.incident', $reference))
            ->assertOk()
            ->assertSee($reference)
            ->assertSee('libelles_de_banque')
            ->assertSee('une migration n’a pas été passée', false);
    }

    public function test_un_incident_d_avant_la_table_se_relit_dans_le_journal(): void
    {
        $this->journalDeTest = JournalDesIncidents::$dossierDuJournal.'/zz-test-incidents.log';
        file_put_contents($this->journalDeTest,
            "[2026-10-09 00:42:01] production.ERROR: ERR-XEYTWV — Unknown column 'mouvement_caisse_id' "
            .'{"exception":"Illuminate\\\\Database\\\\QueryException","origine":"/app/Modules/Superviseur/x.php:12","url":"https://gestionsites.test/banques","utilisateur":7} '."\n");

        $incident = JournalDesIncidents::trouver('err-xeytwv');

        $this->assertNotNull($incident);
        $this->assertSame('ERR-XEYTWV', $incident['reference']);
        $this->assertStringContainsString("Unknown column 'mouvement_caisse_id'", $incident['message']);
        $this->assertSame('https://gestionsites.test/banques', $incident['url']);
        $this->assertTrue(JournalDesIncidents::recents()->contains('reference', 'ERR-XEYTWV'));
    }

    public function test_la_maintenance_liste_les_incidents_et_ouvre_celui_qu_on_tape(): void
    {
        $reference = $this->referenceDUnePanne();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('super-admin.maintenance'))
            ->assertOk()
            ->assertSee($reference)
            ->assertSee(route('super-admin.incident', $reference), false);

        // Sans le préfixe : on recopie souvent seulement les six caractères.
        Volt::actingAs($admin)->test('superadmin.maintenance')
            ->set('referenceCherchee', substr($reference, 4))
            ->call('ouvrirLIncident')
            ->assertRedirect(route('super-admin.incident', $reference));
    }

    public function test_un_visiteur_n_ouvre_pas_la_fiche(): void
    {
        $this->get(route('super-admin.incident', $this->referenceDUnePanne()))->assertRedirect();
    }

    /**
     * « Si l'erreur est réglée, la ligne doit disparaître » — 09/10. Les répétitions d'une même
     * panne font une ligne ; « Corrigé » la retire, ses répétitions comprises.
     */
    public function test_une_panne_reglee_quitte_la_liste_avec_ses_repetitions(): void
    {
        $premiere = $this->referenceDUnePanne();
        $this->referenceDUnePanne();

        $ouvertes = JournalDesIncidents::recents();
        $this->assertCount(1, $ouvertes, 'Deux fois la même panne : une seule ligne.');
        $this->assertSame(2, $ouvertes->first()['fois']);

        Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->call('declarerRegle', $premiere);

        $this->assertCount(0, JournalDesIncidents::recents());
        $this->assertSame(0, DB::table('incidents')->count());

        // Elle revient : elle reparaît — elle n'était pas réglée.
        $this->travel(1)->minutes();
        $this->referenceDUnePanne();
        $this->assertCount(1, JournalDesIncidents::recents());
    }

    /** Une panne corrigée dans le code disparaît d'elle-même, journal du serveur compris. */
    public function test_une_panne_corrigee_dans_le_code_ne_parait_plus(): void
    {
        $this->journalDeTest = JournalDesIncidents::$dossierDuJournal.'/zz-test-incidents.log';
        file_put_contents($this->journalDeTest,
            "[2026-10-09 00:42:36] production.ERROR: ERR-XEYTWV — SQLSTATE[42000]: Syntax error or access violation: 1055 'gs.factures.id' isn't in GROUP BY "
            .'{"exception":"Illuminate\\Database\\QueryException","origine":"/vendor/x.php:1","url":"https://gestionsites.test/banques","utilisateur":7} '."\n");

        $this->assertFalse(JournalDesIncidents::recents()->contains('reference', 'ERR-XEYTWV'));
        // La fiche reste lisible, et dit ce qui l'a corrigée.
        $this->assertNotNull(JournalDesIncidents::reglement(JournalDesIncidents::trouver('ERR-XEYTWV')));
    }

    // ------------------------------------------------------------------ le décor

    private function referenceDUnePanne(): string
    {
        preg_match('/ERR-[A-Z0-9]{6}/', $this->get('/tests/panne-banques')->getContent(), $trouve);
        auth()->logout();

        return $trouve[0];
    }

    private function superAdmin(): User
    {
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

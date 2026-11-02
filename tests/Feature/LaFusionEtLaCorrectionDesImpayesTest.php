<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Imports\Formats\Format;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fusion et correction des clients de l'état des impayés — 27/10, rôles inversés le 02/11.
 *
 * « Vérifie si la fusion supprime le nom de départ : normalement, c'est la correction qui doit faire
 * ça. Ajoute les deux des deux côtés, impayés et recouvrement. » La fusion déplace sans rien
 * supprimer ; la correction déplace puis efface le nom faux. Le recouvrement a le même écran :
 * `LaFusionEtLaCorrectionDuRecouvrementTest`.
 */
class LaFusionEtLaCorrectionDesImpayesTest extends TestCase
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

    public function test_la_fusion_deplace_les_factures_et_ne_supprime_rien(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('F-1', 'ORANGE CI');
        $b = $this->creance('F-2', 'ORANGE MONEY');
        $this->relance('ORANGE MONEY');

        $ecran = Volt::actingAs($gerant)->test('pilotage.impayes-fusion')->assertSet('section', 'fusion')
            ->set('depart', 'ORANGE MONEY');
        $this->assertArrayNotHasKey('ORANGE MONEY', $ecran->instance()->optionsArrivee);

        $ecran->call('toutCocher')->set('arrivee', 'ORANGE CI')->call('executer')->assertHasNoErrors();

        $this->assertSame('ORANGE CI', $b->fresh()->client);
        // Rien n'est supprimé : la relance reste au nom de départ.
        $this->assertSame('ORANGE MONEY', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
    }

    public function test_la_correction_deplace_les_factures_et_supprime_le_nom_faux(): void
    {
        $gerant = $this->compte('gerant');
        $a = $this->creance('F-1', 'SANLAM VIE');
        $b = $this->creance('F-2', 'SANLAM VIEE');
        $c = $this->creance('F-3', 'SANLAM VIEE');
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $b->id, 'date' => '2026-03-05',
            'type' => 'Client', 'moyen' => 'Virement', 'montant' => 10_000, 'client' => 'SANLAM VIEE',
        ]);
        $this->relance('SANLAM VIEE');

        Volt::actingAs($gerant)->test('pilotage.impayes-fusion')
            ->call('choisirSection', 'correction')
            ->set('depart', 'SANLAM VIEE')
            ->call('toutCocher')->assertSet('cochees', [(string) $b->id, (string) $c->id])
            ->set('arrivee', 'SANLAM VIE')
            ->call('executer')->assertHasNoErrors()
            ->assertSet('depart', '')->assertSet('cochees', []);

        $this->assertSame(3, Facture::withoutGlobalScopes()->where('client', 'SANLAM VIE')->count());
        $this->assertSame(0, Facture::withoutGlobalScopes()->where('client', 'SANLAM VIEE')->count());
        $this->assertSame('SANLAM VIE', Encaissement::withoutGlobalScopes()->sole()->client);
        $this->assertSame('SANLAM VIE', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers, 'La relance suit le bon nom.');
        $this->assertSame(200_000, montant_de($a->fresh()->montant), 'Seul le nom change.');
    }

    public function test_une_correction_partielle_garde_le_nom_tant_qu_une_facture_le_porte(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('F-1', 'AXA CI');
        $b = $this->creance('F-2', 'AXA C.I');
        $this->creance('F-3', 'AXA C.I');
        $this->relance('AXA C.I');

        Volt::actingAs($gerant)->test('pilotage.impayes-fusion')
            ->call('choisirSection', 'correction')
            ->set('depart', 'AXA C.I')->set('cochees', [(string) $b->id])->set('arrivee', 'AXA CI')
            ->call('executer')->assertHasNoErrors()
            ->assertSet('depart', 'AXA C.I')->assertSet('cochees', [])
            // La panne du 28/10 (« count(): null given ») : la page se rend juste après, cases vides.
            ->assertSee('0 facture(s) cochée(s)')
            ->set('cochees', null)->assertSee('0 facture(s) cochée(s)')
            ->set('colonne', 'assureur')->assertSet('depart', '')->assertSet('cochees', []);

        $this->assertSame('AXA C.I', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers, 'Le nom porte encore F-3 : rien n\'est effacé.');
    }

    public function test_un_client_de_depart_n_est_pas_un_client_d_arrivee(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', 'AXA');

        foreach (['fusion', 'correction'] as $section) {
            Volt::actingAs($gerant)->test('pilotage.impayes-fusion')->call('choisirSection', $section)
                ->set('depart', 'AXA')->set('cochees', [(string) $f->id])->set('arrivee', ' axa ')
                ->call('executer')->assertHasErrors('arrivee');
        }

        $this->assertSame('AXA', $f->fresh()->client);
    }

    public function test_la_page_s_attribue_depuis_la_maintenance_et_les_anciennes_adresses_y_menent(): void
    {
        $caissier = $this->compte('caissier');
        $this->actingAs($caissier)->get(route('impayes-fusion'))->assertForbidden();

        HabilitationAction::query()->create([
            'domaine' => 'clients_impayes', 'action' => 'fusionner', 'user_id' => $caissier->id, 'accorde' => true,
        ]);
        HabilitationsDActions::oublier();

        $this->actingAs($caissier)->get(route('impayes-fusion'))->assertOk()
            ->assertSee('Fusion / correction des clients')->assertSee('Correction');
        $this->assertSame(url('/impayes/fusion-correction'), route('impayes-fusion'));
        $this->actingAs($caissier)->get('/impayes/fusion-des-clients')->assertRedirect('/impayes/fusion-correction');
    }

    /** L'extrait AFG retraité écrit ses dates en toutes lettres — 27/10. */
    public function test_une_date_en_toutes_lettres_se_lit(): void
    {
        $this->assertSame('2026-05-29', Format::date('29 mai 2026')?->toDateString());
        $this->assertSame('2026-06-01', Format::date('1er juin 2026')?->toDateString());
        $this->assertSame('2026-02-03', Format::date('3 févr. 2026')?->toDateString());
        $this->assertNull(Format::date('31 février 2026'));
    }

    // ------------------------------------------------------------------------------ outils

    private function creance(string $numero, string $client): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'IMP-'.$numero, 'n_facture' => $numero,
            'date' => '2026-03-01', 'date_reception' => '2026-03-02', 'exercice_impayes' => 2026,
            'client' => $client, 'montant' => 200_000, 'activite' => 'Sinistre',
        ]);
    }

    private function relance(string $tiers): void
    {
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => $tiers,
            'niveau' => 1, 'canal' => 'E-mail',
        ]);
    }

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

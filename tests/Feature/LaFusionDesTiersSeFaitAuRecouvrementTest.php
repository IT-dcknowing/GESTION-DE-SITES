<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Modeles\ClientCommercial;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La fusion des tiers, dans le module Recouvrement — 28/10.
 *
 * « La fusion concerne le recouvrement, donc les données du recouvrement, alors que la correction
 * concerne les impayés. Mets-la au niveau du recouvrement, dans le module recouvrement ; fais une
 * page pour ça. »
 */
class LaFusionDesTiersSeFaitAuRecouvrementTest extends TestCase
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

    public function test_le_tiers_est_remplace_dans_toutes_ses_colonnes_et_disparait_avec_ce_qui_le_suit(): void
    {
        $gerant = $this->compte('gerant');
        $vendeur = $this->compte('commercial');
        $fiche = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $vendeur->id, 'numero' => 'C-1', 'nom' => 'Awa']);

        $bon = $this->creance('F-1', ['client' => 'M. KONAN', 'assureur' => 'SANLAM VIE']);
        $assureur = $this->creance('F-2', ['client' => 'M. YAO', 'assureur' => 'SANLAM VIEE']);
        $client = $this->creance('F-3', ['client' => 'SANLAM VIEE']);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $assureur->id, 'date' => '2026-03-05',
            'type' => 'Client', 'moyen' => 'Virement', 'montant' => 10_000, 'client' => 'SANLAM VIEE',
        ]);
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => 'SANLAM VIEE',
            'niveau' => 1, 'canal' => 'E-mail',
        ]);
        Referentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'type' => Referentiel::TIERS_RECOUVREMENT, 'valeur' => 'SANLAM VIEE', 'est_actif' => true,
        ]);
        ClientCommercial::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'commercial_id' => $fiche->id, 'client' => 'SANLAM VIEE']);

        $ecran = Volt::actingAs($gerant)->test('recouvrement.fusion')->set('depart', 'SANLAM VIEE');
        $this->assertSame(2, $ecran->instance()->factures->count());
        $this->assertSame(1, $ecran->instance()->relances->count());
        $this->assertArrayNotHasKey('SANLAM VIEE', $ecran->instance()->optionsArrivee);
        $ecran->assertSee('Assureur')->assertSee('Client');

        $ecran->call('toutCocher')->assertSet('cochees', [(string) $assureur->id, (string) $client->id])
            ->set('arrivee', 'SANLAM VIE')
            ->call('fusionner', true)->assertHasNoErrors()
            ->assertSet('depart', '')->assertSet('cochees', []);

        $this->assertSame('SANLAM VIE', $assureur->fresh()->assureur);
        $this->assertSame('M. YAO', $assureur->fresh()->client, 'Seule la colonne qui portait le nom change.');
        $this->assertSame('SANLAM VIE', $client->fresh()->client);
        $this->assertSame('SANLAM VIE', $bon->fresh()->assureur);
        $this->assertSame('SANLAM VIE', Encaissement::withoutGlobalScopes()->sole()->client);
        $this->assertSame('SANLAM VIE', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
        $this->assertSame(0, Referentiel::withoutGlobalScopes()->where('valeur', 'SANLAM VIEE')->count());
        $this->assertSame('SANLAM VIE', ClientCommercial::withoutGlobalScopes()->sole()->client, 'Le commercial suit le bon nom.');
        $this->assertSame(200_000, montant_de($client->fresh()->montant), 'Les montants ne bougent pas.');
    }

    public function test_sans_suppression_seules_les_factures_cochees_passent(): void
    {
        $gerant = $this->compte('gerant');
        $a = $this->creance('F-1', ['client' => 'AXA CI']);
        $this->creance('F-2', ['client' => 'AXA CI']);
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => 'AXA CI',
            'niveau' => 1, 'canal' => 'E-mail',
        ]);

        Volt::actingAs($gerant)->test('recouvrement.fusion')
            ->set('depart', 'AXA CI')->set('cochees', [(string) $a->id])->set('arrivee', 'AXA COTE D\'IVOIRE')
            ->call('fusionner', true)->assertHasNoErrors()
            // Le départ porte encore une facture : il reste, et la page reste sur lui.
            ->assertSet('depart', 'AXA CI')->assertSee('Factures de « AXA CI » (1)');

        $this->assertSame('AXA COTE D\'IVOIRE', $a->fresh()->client);
        $this->assertSame('AXA CI', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
    }

    public function test_un_tiers_ne_se_fusionne_pas_dans_lui_meme(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', ['client' => 'NSIA']);

        Volt::actingAs($gerant)->test('recouvrement.fusion')
            ->set('depart', 'NSIA')->set('cochees', [(string) $f->id])->set('arrivee', ' nsia ')
            ->call('fusionner', false)->assertHasErrors('arrivee');

        $this->assertSame('NSIA', $f->fresh()->client);
    }

    public function test_la_page_s_ouvre_par_l_habilitation_et_se_voit_dans_le_module(): void
    {
        $gerant = $this->compte('gerant');
        $superviseur = $this->compte('superviseur_recouvrement');

        $this->actingAs($superviseur)->get(route('recouvrement.fusion'))->assertForbidden();
        $this->actingAs($superviseur)->get(route('recouvrement.balance'))->assertOk()->assertDontSee('Fusion des tiers');

        HabilitationAction::query()->create([
            'domaine' => 'tiers_recouvrement', 'action' => 'fusionner', 'role' => 'superviseur_recouvrement', 'accorde' => true,
        ]);
        HabilitationsDActions::oublier();

        $this->actingAs($superviseur)->get(route('recouvrement.fusion'))->assertOk()->assertSee('Fusion des tiers');
        $this->actingAs($superviseur)->get(route('recouvrement.balance'))->assertOk()->assertSee('Fusion des tiers');
        $this->actingAs($gerant)->get(route('recouvrement.fusion'))->assertOk();
    }

    // ------------------------------------------------------------------------------ outils

    /** @param  array<string, string>  $tiers */
    private function creance(string $numero, array $tiers): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'IMP-'.$numero, 'n_facture' => $numero,
            'date' => '2026-03-01', 'date_reception' => '2026-03-02', 'exercice_impayes' => 2026,
            'montant' => 200_000, 'activite' => 'Sinistre', ...$tiers,
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

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
 * Fusion et correction des clients, sur les données du recouvrement — 28/10, complétée le 02/11.
 *
 * « Ajoute les deux des deux côtés » ; « c'est la correction qui doit supprimer le nom de départ ».
 */
class LaFusionEtLaCorrectionDuRecouvrementTest extends TestCase
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

    public function test_la_fusion_deplace_dans_la_colonne_ou_le_nom_se_lit_et_ne_supprime_rien(): void
    {
        $gerant = $this->compte('gerant');
        $assuree = $this->creance('F-1', ['client' => 'M. YAO', 'assureur' => 'NSIA']);
        $directe = $this->creance('F-2', ['client' => 'NSIA']);
        $this->creance('F-3', ['client' => 'M. KONAN', 'assureur' => 'SAHAM']);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $assuree->id, 'date' => '2026-03-05',
            'type' => 'Client', 'moyen' => 'Virement', 'montant' => 10_000, 'client' => 'NSIA',
        ]);
        $this->relance('NSIA');
        $this->referentiel('NSIA');

        $ecran = Volt::actingAs($gerant)->test('recouvrement.fusion-correction')->assertSet('section', 'fusion');
        // La liste puise dans le recouvrement : l'assureur y figure, pas seulement les clients.
        $this->assertArrayHasKey('SAHAM', $ecran->instance()->options);

        $ecran->set('depart', 'NSIA');
        $this->assertSame(2, $ecran->instance()->factures->count());

        $ecran->call('toutCocher')->set('arrivee', 'SAHAM')->call('executer')->assertHasNoErrors();

        $this->assertSame('SAHAM', $assuree->fresh()->assureur, 'Le nom change dans la colonne où il se lit.');
        $this->assertSame('M. YAO', $assuree->fresh()->client);
        $this->assertSame('SAHAM', $directe->fresh()->client);
        $this->assertSame('SAHAM', Encaissement::withoutGlobalScopes()->sole()->client);
        // La fusion ne supprime rien : relance et référentiel restent au nom de départ.
        $this->assertSame('NSIA', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
        $this->assertSame(1, Referentiel::withoutGlobalScopes()->where('valeur', 'NSIA')->count());
    }

    public function test_la_correction_supprime_le_nom_faux_et_ce_qui_le_suit(): void
    {
        $gerant = $this->compte('gerant');
        $vendeur = $this->compte('commercial');
        $fiche = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $vendeur->id, 'numero' => 'C-1', 'nom' => 'Awa']);

        $bon = $this->creance('F-1', ['client' => 'M. KONAN', 'assureur' => 'SANLAM VIE']);
        $assureur = $this->creance('F-2', ['client' => 'M. YAO', 'assureur' => 'SANLAM VIEE']);
        $client = $this->creance('F-3', ['client' => 'SANLAM VIEE']);
        $this->relance('SANLAM VIEE');
        $this->referentiel('SANLAM VIEE');
        ClientCommercial::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'commercial_id' => $fiche->id, 'client' => 'SANLAM VIEE']);

        Volt::actingAs($gerant)->test('recouvrement.fusion-correction')
            ->call('choisirSection', 'correction')
            ->set('depart', 'SANLAM VIEE')
            ->call('toutCocher')->assertSet('cochees', [(string) $assureur->id, (string) $client->id])
            ->set('arrivee', 'SANLAM VIE')
            ->call('executer')->assertHasNoErrors()
            ->assertSet('depart', '')->assertSet('cochees', []);

        $this->assertSame('SANLAM VIE', $assureur->fresh()->assureur);
        $this->assertSame('M. YAO', $assureur->fresh()->client);
        $this->assertSame('SANLAM VIE', $client->fresh()->client);
        $this->assertSame('SANLAM VIE', $bon->fresh()->assureur);
        $this->assertSame('SANLAM VIE', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
        $this->assertSame(0, Referentiel::withoutGlobalScopes()->where('valeur', 'SANLAM VIEE')->count());
        $this->assertSame('SANLAM VIE', ClientCommercial::withoutGlobalScopes()->sole()->client, 'Le commercial suit le bon nom.');
        $this->assertSame(200_000, montant_de($client->fresh()->montant), 'Les montants ne bougent pas.');
    }

    public function test_une_correction_partielle_garde_le_nom(): void
    {
        $gerant = $this->compte('gerant');
        $a = $this->creance('F-1', ['client' => 'AXA C.I']);
        $this->creance('F-2', ['client' => 'AXA C.I']);
        $this->relance('AXA C.I');

        Volt::actingAs($gerant)->test('recouvrement.fusion-correction')->call('choisirSection', 'correction')
            ->set('depart', 'AXA C.I')->set('cochees', [(string) $a->id])->set('arrivee', 'AXA CI')
            ->call('executer')->assertHasNoErrors()
            ->assertSet('depart', 'AXA C.I')->assertSee('Factures de « AXA C.I » (1)');

        $this->assertSame('AXA CI', $a->fresh()->client);
        $this->assertSame('AXA C.I', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
    }

    public function test_un_nom_sans_facture_se_corrige_sans_rien_cocher(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('F-1', ['client' => 'NSIA']);
        $this->relance('NSIA VIE');

        Volt::actingAs($gerant)->test('recouvrement.fusion-correction')->call('choisirSection', 'correction')
            ->set('depart', 'NSIA VIE')->set('arrivee', 'NSIA')
            ->call('executer')->assertHasNoErrors();

        $this->assertSame('NSIA', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);

        // La fusion, elle, exige au moins une facture cochée.
        $this->relance('NSIA ASSUR');
        Volt::actingAs($gerant)->test('recouvrement.fusion-correction')
            ->set('depart', 'NSIA ASSUR')->set('arrivee', 'NSIA')
            ->call('executer')->assertHasErrors('arrivee');
    }

    public function test_la_fusion_accepte_deux_noms_distincts_qui_ne_different_que_par_la_ponctuation(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', ['client' => 'BIA -CI']);
        $this->creance('F-2', ['client' => 'BIA-CI']);

        $ecran = Volt::actingAs($gerant)->test('recouvrement.fusion-correction')->set('depart', 'BIA -CI');
        $this->assertArrayNotHasKey('BIA -CI', $ecran->instance()->optionsArrivee);
        $this->assertArrayHasKey('BIA-CI', $ecran->instance()->optionsArrivee);

        $ecran->call('toutCocher')->set('arrivee', 'BIA-CI')->call('executer')->assertHasNoErrors();

        $this->assertSame('BIA-CI', $f->fresh()->client);
    }

    public function test_un_client_ne_se_deplace_pas_vers_lui_meme(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', ['client' => 'NSIA']);

        foreach (['fusion', 'correction'] as $section) {
            Volt::actingAs($gerant)->test('recouvrement.fusion-correction')->call('choisirSection', $section)
                ->set('depart', 'NSIA')->set('cochees', [(string) $f->id])->set('arrivee', 'NSIA')
                ->call('executer')->assertHasErrors('arrivee');
        }

        $this->assertSame('NSIA', $f->fresh()->client);
    }

    public function test_la_page_s_ouvre_au_gerant_et_par_l_habilitation(): void
    {
        $gerant = $this->compte('gerant');
        $superviseur = $this->compte('superviseur_recouvrement');

        // Le superviseur du recouvrement ne l'a pas d'office (le propriétaire, 28/10).
        $this->actingAs($superviseur)->get(route('recouvrement.fusion-correction'))->assertForbidden();
        $this->actingAs($superviseur)->get(route('recouvrement.balance'))->assertOk()->assertDontSee('Fusion / correction');
        $this->actingAs($gerant)->get(route('recouvrement.fusion-correction'))->assertOk()->assertSee('Fusion / correction des clients');
        $this->actingAs($gerant)->get('/recouvrement/correction-des-clients')->assertRedirect('/recouvrement/fusion-correction');

        HabilitationAction::query()->create([
            'domaine' => 'tiers_recouvrement', 'action' => 'corriger', 'user_id' => $superviseur->id, 'accorde' => true,
        ]);
        HabilitationsDActions::oublier();

        $this->actingAs($superviseur)->get(route('recouvrement.fusion-correction'))->assertOk();
        $this->actingAs($superviseur)->get(route('recouvrement.balance'))->assertOk()->assertSee('Fusion / correction');
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

    private function relance(string $tiers): void
    {
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => $tiers,
            'niveau' => 1, 'canal' => 'E-mail',
        ]);
    }

    private function referentiel(string $nom): void
    {
        Referentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'type' => Referentiel::TIERS_RECOUVREMENT, 'valeur' => $nom, 'est_actif' => true,
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

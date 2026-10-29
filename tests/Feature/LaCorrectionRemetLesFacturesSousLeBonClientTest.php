<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Imports\Formats\Format;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Correction des clients de l'état des impayés — 27/10, réduite à la correction le 28/10.
 *
 * « Des clients justes, mais des factures pas sous le bon — correction. Un client de départ ne
 * doit pas être un client d'arrivée. » La fusion est passée au module Recouvrement : voir
 * `LaFusionDesTiersSeFaitAuRecouvrementTest`.
 */
class LaCorrectionRemetLesFacturesSousLeBonClientTest extends TestCase
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

    public function test_corriger_ne_deplace_que_les_factures_cochees_et_ne_supprime_rien(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('F-1', 'ORANGE CI');
        $mal = $this->creance('F-2', 'MTN CI');
        $this->creance('F-3', 'MTN CI');
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $mal->id, 'date' => '2026-03-05',
            'type' => 'Client', 'moyen' => 'Virement', 'montant' => 10_000, 'client' => 'MTN CI',
        ]);
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => 'MTN CI',
            'niveau' => 1, 'canal' => 'E-mail',
        ]);

        $ecran = Volt::actingAs($gerant)->test('pilotage.impayes-correction')
            ->set('depart', 'MTN CI');
        $this->assertSame(2, $ecran->instance()->factures->count());
        // L'arrivée ne propose jamais le départ.
        $this->assertArrayNotHasKey('MTN CI', $ecran->instance()->optionsArrivee);

        $ecran->set('cochees', [(string) $mal->id])->set('arrivee', 'ORANGE CI')
            ->call('corriger')->assertHasNoErrors();

        $this->assertSame('ORANGE CI', $mal->fresh()->client);
        $this->assertSame('ORANGE CI', Encaissement::withoutGlobalScopes()->sole()->client);
        $this->assertSame(1, Facture::withoutGlobalScopes()->where('client', 'MTN CI')->count());
        // Rien n'est supprimé : les relances restent au nom de départ.
        $this->assertSame('MTN CI', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
        $this->assertSame(200_000, montant_de($mal->fresh()->montant), 'Seul le nom change.');
    }

    /**
     * **La panne du 28/10** — « count(): Argument #1 ($value) must be of type Countable|array, null
     * given » (impayes-fusion.blade.php, KOUASSI VANESSA, 09:37 et 09:50). `reset('cochees')` d'un état
     * Volt le remettait à null ; le client de départ gardant une facture, la page se rendait aussitôt
     * avec `count(null)`.
     */
    public function test_la_page_se_rend_apres_une_correction_et_un_changement_de_colonne(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('F-1', 'ORANGE CI');
        $mal = $this->creance('F-2', 'MTN CI');
        $this->creance('F-3', 'MTN CI');

        $ecran = Volt::actingAs($gerant)->test('pilotage.impayes-correction')
            ->set('depart', 'MTN CI')->set('cochees', [(string) $mal->id])->set('arrivee', 'ORANGE CI')
            ->call('corriger')->assertHasNoErrors()
            // Le départ porte encore F-3 : la page se rend avec lui, cases vides.
            ->assertSet('depart', 'MTN CI')->assertSet('cochees', [])
            ->assertSee('0 facture(s) cochée(s)');

        $ecran->call('toutCocher')->assertSee('1 facture(s) cochée(s)')
            ->set('colonne', 'assureur')->assertSet('cochees', [])->assertSet('depart', '')
            ->set('colonne', 'client')->set('depart', 'MTN CI')->assertSee('Factures de « MTN CI » (1)');

        // Une valeur nulle renvoyée par le navigateur ne fait plus tomber la page.
        $ecran->set('cochees', null)->assertSee('0 facture(s) cochée(s)');
    }

    public function test_un_client_de_depart_n_est_pas_un_client_d_arrivee(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', 'AXA');

        Volt::actingAs($gerant)->test('pilotage.impayes-correction')
            ->set('depart', 'AXA')->set('cochees', [(string) $f->id])->set('arrivee', ' axa ')
            ->call('corriger')->assertHasErrors('arrivee');

        $this->assertSame('AXA', $f->fresh()->client);
    }

    public function test_la_page_s_attribue_depuis_la_maintenance_et_l_ancienne_adresse_y_mene(): void
    {
        $caissier = $this->compte('caissier');
        $this->actingAs($caissier)->get(route('impayes-correction'))->assertForbidden();

        \Modules\Noyau\Entreprises\Modeles\HabilitationAction::query()->create([
            'domaine' => 'clients_impayes', 'action' => 'fusionner', 'user_id' => $caissier->id, 'accorde' => true,
        ]);
        \Modules\Noyau\Entreprises\Support\HabilitationsDActions::oublier();

        $this->actingAs($caissier)->get(route('impayes-correction'))->assertOk()
            ->assertSee('Correction des clients')->assertDontSee('Fusionner et supprimer');
        $this->actingAs($caissier)->get('/impayes/fusion-correction')->assertRedirect('/impayes/correction-des-clients');
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

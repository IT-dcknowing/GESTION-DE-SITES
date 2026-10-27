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
 * Fusion / correction des clients de l'état des impayés — 27/10.
 *
 * « Pour un client, deux noms : l'un correct, l'autre une erreur, dont les factures sont vraies et
 * à remettre au premier — fusion. Des clients justes, mais des factures pas sous le bon —
 * correction. Un client de départ ne doit pas être un client d'arrivée. »
 */
class LaFusionRemetLesFacturesSousLeBonClientTest extends TestCase
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

    public function test_fusionner_deplace_les_factures_et_supprime_le_nom_faux(): void
    {
        $gerant = $this->compte('gerant');
        $a = $this->creance('F-1', 'SANLAM VIE');
        $b = $this->creance('F-2', 'SANLAM VIEE');
        $c = $this->creance('F-3', 'SANLAM VIEE');
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $b->id, 'date' => '2026-03-05',
            'type' => 'Client', 'moyen' => 'Virement', 'montant' => 10_000, 'client' => 'SANLAM VIEE',
        ]);
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => 'SANLAM VIEE',
            'niveau' => 1, 'canal' => 'E-mail',
        ]);

        $ecran = Volt::actingAs($gerant)->test('pilotage.impayes-fusion')
            ->set('depart', 'SANLAM VIEE');
        $this->assertSame(2, $ecran->instance()->factures->count());
        // L'arrivée ne propose jamais le départ.
        $this->assertArrayNotHasKey('SANLAM VIEE', $ecran->instance()->optionsArrivee);

        $ecran->call('toutCocher')->assertSet('cochees', [(string) $b->id, (string) $c->id])
            ->set('arrivee', 'SANLAM VIE')
            ->call('executer', true)->assertHasNoErrors();

        $this->assertSame(3, Facture::withoutGlobalScopes()->where('client', 'SANLAM VIE')->count());
        $this->assertSame(0, Facture::withoutGlobalScopes()->where('client', 'SANLAM VIEE')->count());
        $this->assertSame('SANLAM VIE', Encaissement::withoutGlobalScopes()->sole()->client);
        $this->assertSame('SANLAM VIE', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
        $this->assertSame(200_000, montant_de($a->fresh()->montant), 'Seul le nom change.');
    }

    public function test_corriger_ne_deplace_que_les_factures_cochees_et_ne_supprime_rien(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('F-1', 'ORANGE CI');
        $mal = $this->creance('F-2', 'MTN CI');
        $this->creance('F-3', 'MTN CI');

        Volt::actingAs($gerant)->test('pilotage.impayes-fusion')
            ->call('choisirSection', 'correction')
            ->set('depart', 'MTN CI')->set('cochees', [(string) $mal->id])->set('arrivee', 'ORANGE CI')
            ->call('executer', true)->assertHasNoErrors();

        $this->assertSame('ORANGE CI', $mal->fresh()->client);
        $this->assertSame(1, Facture::withoutGlobalScopes()->where('client', 'MTN CI')->count());
    }

    public function test_un_client_de_depart_n_est_pas_un_client_d_arrivee(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', 'AXA');

        Volt::actingAs($gerant)->test('pilotage.impayes-fusion')
            ->set('depart', 'AXA')->set('cochees', [(string) $f->id])->set('arrivee', ' axa ')
            ->call('executer', false)->assertHasErrors('arrivee');

        $this->assertSame('AXA', $f->fresh()->client);
    }

    public function test_la_page_s_attribue_depuis_la_maintenance(): void
    {
        $caissier = $this->compte('caissier');
        $this->actingAs($caissier)->get(route('impayes-fusion'))->assertForbidden();

        \Modules\Noyau\Entreprises\Modeles\HabilitationAction::query()->create([
            'domaine' => 'clients_impayes', 'action' => 'fusionner', 'user_id' => $caissier->id, 'accorde' => true,
        ]);
        \Modules\Noyau\Entreprises\Support\HabilitationsDActions::oublier();

        $this->actingAs($caissier)->get(route('impayes-fusion'))->assertOk();
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

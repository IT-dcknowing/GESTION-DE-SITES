<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\ClientCommercial;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Modeles\RelanceCommerciale;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le portefeuille de clients des commerciaux — 26/10.
 *
 * « Affecter à chaque commercial un ou plusieurs clients — ceux qu'il a prospectés aussi. Ils
 * pourront se faire des relances directement, comme le recouvrement. »
 */
class LesCommerciauxSuiventLeursClientsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private User $gerant;

    private User $vendeurA;

    private User $vendeurB;

    private Commercial $a;

    private Commercial $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        $this->gerant = $this->compte('gerant', 'gerant');
        $this->vendeurA = $this->compte('commercial', 'awa');
        $this->vendeurB = $this->compte('commercial', 'koffi');
        $this->a = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $this->vendeurA->id, 'numero' => 'C-1', 'nom' => 'Awa']);
        $this->b = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $this->vendeurB->id, 'numero' => 'C-2', 'nom' => 'Koffi']);
    }

    public function test_le_gerant_affecte_un_client_et_le_commercial_le_relance(): void
    {
        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'NFA-1', 'n_facture' => 'FA-1',
            'date' => now()->toDateString(), 'client' => 'Sotra Transports', 'activite' => 'Mécanique', 'montant' => 250_000.5,
        ]);

        Volt::actingAs($this->gerant)->test('pilotage.portefeuilles-clients')
            ->set('commercialChoisi', (string) $this->a->id)
            ->set('client', ' SOTRA   transports ')
            ->set('telephone', '0700000000')
            ->call('affecter')->assertHasNoErrors();

        $ligne = ClientCommercial::withoutGlobalScopes()->sole();
        $this->assertSame($this->a->id, (int) $ligne->commercial_id);

        // Son écran le montre, avec le chiffre facturé au même nom (casse et espaces mis à part).
        $ecran = Volt::actingAs($this->vendeurA)->test('commercial.mes-clients')->assertSee('SOTRA transports');
        $client = $ecran->instance()->clients->first();
        $this->assertSame(250_000.5, $client->ca);
        $this->assertSame(1, $ecran->instance()->compteurs['jamais']);

        $ecran->call('ouvrirRelance', $ligne->id)
            ->set('rObjet', 'Relance de devis')->set('rCanal', 'Téléphone')
            ->set('rResultat', 'Rappeler après validation de l\'assureur')
            ->set('rProchaine', now()->toDateString())
            ->call('enregistrerRelance')->assertHasNoErrors();

        $relance = RelanceCommerciale::withoutGlobalScopes()->sole();
        $this->assertSame($this->a->id, (int) $relance->commercial_id);
        $this->assertSame('SOTRA transports', $relance->client);
        $this->assertSame(1, $ecran->instance()->compteurs['a_relancer']);

        // L'autre commercial ne le voit pas, et ne peut pas le relancer à sa place.
        $autre = Volt::actingAs($this->vendeurB)->test('commercial.mes-clients');
        $this->assertSame(0, $autre->instance()->clients->count());
        $autre->call('ouvrirRelance', $ligne->id)->assertSet('relanceDe', null);
    }

    public function test_un_client_prospecte_se_reprend_sans_prendre_celui_d_un_collegue(): void
    {
        $this->prospection($this->a, 'Garage Bon Prix', 'P-1');
        $this->prospection($this->a, 'Transports KTL', 'P-2');
        $this->prospection($this->b, 'Transports KTL', 'P-3');

        // Koffi suit déjà KTL : Awa ne se le voit pas proposer.
        Volt::actingAs($this->gerant)->test('pilotage.portefeuilles-clients')
            ->set('commercialChoisi', (string) $this->b->id)->set('client', 'Transports KTL')->call('affecter');

        $ecran = Volt::actingAs($this->vendeurA)->test('commercial.mes-clients');
        $this->assertSame(['Garage Bon Prix'], $ecran->instance()->prospectes);

        $ecran->call('suivre', 'Transports KTL');
        $this->assertSame($this->b->id, (int) ClientCommercial::withoutGlobalScopes()->where('client', 'Transports KTL')->value('commercial_id'));

        $ecran->call('suivre', 'Garage Bon Prix');
        $repris = ClientCommercial::withoutGlobalScopes()->where('client', 'Garage Bon Prix')->sole();
        $this->assertSame($this->a->id, (int) $repris->commercial_id);
        $this->assertSame('prospection', $repris->origine);
    }

    public function test_reaffecter_deplace_le_client_et_retirer_garde_les_relances(): void
    {
        $ecran = Volt::actingAs($this->gerant)->test('pilotage.portefeuilles-clients')
            ->set('commercialChoisi', (string) $this->a->id)->set('client', 'NSIA')->call('affecter');
        $ligne = ClientCommercial::withoutGlobalScopes()->sole();

        RelanceCommerciale::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'commercial_id' => $this->a->id, 'responsable' => 'Awa', 'client' => 'NSIA',
            'date' => now()->toDateString(), 'objet' => 'Fidélisation', 'canal' => 'Téléphone',
        ]);

        $ecran->call('ouvrirReaffectation', $ligne->id)->set('versCommercial', (string) $this->b->id)->call('reaffecter')->assertHasNoErrors();
        $this->assertSame($this->b->id, (int) $ligne->fresh()->commercial_id);

        $ecran->call('retirer', $ligne->id);
        $this->assertSame(0, ClientCommercial::withoutGlobalScopes()->count());
        $this->assertSame(1, RelanceCommerciale::withoutGlobalScopes()->count());
    }

    public function test_seuls_ceux_qui_encadrent_affectent(): void
    {
        $this->actingAs($this->vendeurA)->get(route('portefeuilles-clients'))->assertForbidden();
        $this->actingAs($this->vendeurA)->get(route('mes-clients'))->assertOk();
        $this->actingAs($this->gerant)->get(route('portefeuilles-clients'))->assertOk();

        $libelles = fn (array $menu) => collect($menu)
            ->flatMap(fn ($o) => isset($o['groupe']) ? array_column($o['groupe'], 'label') : [$o['label']])->all();

        $this->actingAs($this->vendeurA);
        $this->assertContains('Mes clients', $libelles(MenuNavigation::pour($this->vendeurA)));
        $this->actingAs($this->gerant);
        $this->assertContains('Portefeuilles clients', $libelles(MenuNavigation::pour($this->gerant)));
    }

    // ------------------------------------------------------------------------------ outils

    private function prospection(Commercial $c, string $client, string $numero): Prospection
    {
        return Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'commercial_id' => $c->id,
            'numero' => $numero, 'date' => now()->toDateString(), 'client' => $client, 'localisation' => 'Cocody',
            'moyen' => 'RDV', 'activite' => 'Mécanique', 'passage' => false, 'devis_apres_passage' => false,
            'statut_validation' => 'Brouillon',
        ]);
    }

    private function compte(string $role, string $nom): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($nom), 'email' => $nom.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

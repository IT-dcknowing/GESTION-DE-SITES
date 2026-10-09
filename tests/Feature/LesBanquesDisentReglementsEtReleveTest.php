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
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\LibelleDeBanque;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'écran des banques, demandes du 07/10.
 *
 * - **Section 13** : une banque cliquée montre ce que disent les règlements, ce que dit le
 *   relevé, l'écart, et les factures pas encore réglées — et les boutons ne portent plus de
 *   montant, qui se lisait comme le solde du compte.
 * - **Section 14** : un libellé non reconnu s'affecte à une banque déclarée (« Affecter à »),
 *   ou ouvre la création préremplie (« Modifier ») ; dans les deux cas il se range ensuite
 *   sous sa banque, et la créance garde ce qui y était noté.
 */
class LesBanquesDisentReglementsEtReleveTest extends TestCase
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

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_une_banque_cliquee_compare_reglements_releve_et_factures_ouvertes(): void
    {
        $bgfi = $this->banque('BGFI');
        $this->banque('BNI');

        // 400 000 réglés sur une facture BGFI de 600 000 : 200 000 restent attendus.
        $this->reglement('BGFI', 600_000, 400_000);
        // Une facture BGFI pas réglée du tout.
        $this->facture('BGFI', 150_000);
        // Une facture BNI : elle n'entre pas sous BGFI.
        $this->facture('BNI', 90_000);

        $this->mouvement($bgfi, 380_000, 1_200_000);

        $ecran = Volt::actingAs($this->compte())->test('pilotage.banques')
            ->set('supportFiltre', 'b'.$bgfi->id);

        $page = $ecran->instance();
        $this->assertSame(['montant' => 400_000, 'nombre' => 1], $page->reglementsRegardes);
        $this->assertSame(380_000, $page->releveRegarde['credits']);
        $this->assertSame(1_200_000, $page->releveRegarde['solde']);
        $this->assertSame(['nombre' => 2, 'reste' => 350_000], $page->facturesOuvertesRegardees);

        $ecran->assertSee('Selon la banque — crédits du relevé')
            ->assertSee('Factures pas encore réglées');
    }

    public function test_les_boutons_des_banques_ne_portent_plus_de_montant(): void
    {
        $this->banque('BGFI');
        $this->reglement('BGFI', 487_654, 487_654);

        $html = Volt::actingAs($this->compte())->test('pilotage.banques')->html();

        // Le montant paraît dans les KPI et le tableau, jamais entre parenthèses sur un bouton.
        $this->assertStringNotContainsString('(487', $html);
    }

    public function test_affecter_a_range_le_libelle_sans_toucher_la_creance(): void
    {
        $bgfi = $this->banque('BGFI');
        $this->reglement('BGIF', 300_000, 300_000);

        $ecran = Volt::actingAs($this->compte())->test('pilotage.banques');
        $this->assertCount(1, $ecran->instance()->repartition['nonRanges']);

        $ecran->call('ouvrirLAffectation', 'BGIF', null)
            ->set('banqueAffectee', (string) $bgfi->id)
            ->call('affecterLeLibelle')
            ->assertHasNoErrors();

        $this->assertSame([], $ecran->instance()->repartition['nonRanges']);
        $this->assertSame(300_000, (int) $ecran->instance()->comptes['b'.$bgfi->id]['montant']);
        // La créance garde ce qui y a été noté.
        $this->assertSame('BGIF', Facture::withoutGlobalScopes()->first()->banque);

        // Et l'affectation se retire d'un clic.
        $ecran->call('retirerLAffectation', LibelleDeBanque::withoutGlobalScopes()->first()->id);
        $this->assertCount(1, $ecran->instance()->repartition['nonRanges']);
    }

    public function test_modifier_ouvre_la_creation_preremplie_et_le_libelle_suit_la_banque(): void
    {
        $this->reglement('BANQ ATLANTIQ', 250_000, 250_000);

        $ecran = Volt::actingAs($this->compte())->test('pilotage.banques')
            ->call('ouvrirLaModification', 'BANQ ATLANTIQ')
            ->assertSet('formulaireOuvert', true)
            ->assertSet('nom', 'BANQ ATLANTIQ')
            ->set('nom', 'Banque Atlantique')
            ->call('declarerLaBanque')
            ->assertHasNoErrors();

        $banque = Banque::withoutGlobalScopes()->where('nom', 'BANQUE ATLANTIQUE')->firstOrFail();

        $this->assertSame([], $ecran->instance()->repartition['nonRanges']);
        $this->assertSame(250_000, (int) $ecran->instance()->comptes['b'.$banque->id]['montant']);
    }

    public function test_seul_le_gerant_affecte_un_libelle(): void
    {
        $bgfi = $this->banque('BGFI');

        Volt::actingAs($this->compte('responsable_site'))->test('pilotage.banques')
            ->set('libelleAAffecter', 'BGIF')
            ->set('banqueAffectee', (string) $bgfi->id)
            ->call('affecterLeLibelle')
            ->assertForbidden();

        $this->assertSame(0, LibelleDeBanque::withoutGlobalScopes()->count());
    }

    /**
     * **ERR-XEYTWV, le 09/10.** En ligne, `/banques` est tombé : le code lisait des tables que
     * la migration n'avait pas encore créées. L'écran s'ouvre désormais sans elles — sans les
     * affectations ni le relevé —, et la page Maintenance dit quelle migration attend.
     */
    public function test_l_ecran_s_ouvre_meme_si_les_migrations_du_07_10_ne_sont_pas_passees(): void
    {
        $this->banque('BGFI');
        $this->reglement('BGIF', 300_000, 300_000);
        $gerant = $this->compte();

        \Illuminate\Support\Facades\Schema::drop('libelles_de_banque');
        \Illuminate\Support\Facades\Schema::drop('mouvements_bancaires');
        \App\Support\SchemaDisponible::oublier();

        try {
            $this->actingAs($gerant)->get('/banques')->assertOk()->assertDontSee('Affecter à…');
        } finally {
            \App\Support\SchemaDisponible::oublier();
        }
    }

    // ------------------------------------------------------------------ le décor

    private function banque(string $nom): Banque
    {
        return Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => $nom,
            'nom_normalise' => Banque::clePour($nom),
            'type' => Banque::BANQUE,
        ]);
    }

    private function facture(string $banqueNotee, int $montant): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'n_facture' => 'F-'.Facture::withoutGlobalScopes()->count(),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Mécanique',
            'montant' => $montant,
            'banque' => $banqueNotee,
            'exercice_impayes' => now()->year,
        ]);
    }

    private function reglement(string $banqueNotee, int $montantFacture, int $regle): Encaissement
    {
        $facture = $this->facture($banqueNotee, $montantFacture);

        return Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $facture->id,
            'date' => now()->toDateString(),
            'type' => 'Client',
            'moyen' => 'Chèque',
            'montant' => $regle,
            'client' => 'NSIA ASSURANCES',
        ]);
    }

    private function mouvement(Banque $banque, int $credit, int $solde): MouvementBancaire
    {
        return MouvementBancaire::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'banque_id' => $banque->id,
            'date_operation' => now()->toDateString(),
            'libelle' => 'REMISE CHEQUE NSIA',
            'debit' => 0,
            'credit' => $credit,
            'sens' => 'credit',
            'solde_annonce' => $solde,
            'rang' => 1,
            'cle' => 'm-'.$credit,
        ]);
    }

    private function compte(string $role = 'gerant'): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

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
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Services\AchatsFne;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les fournisseurs sont des charges — 18/10.
 *
 * « Le fournisseur est aussi une charge : la page Charges doit être le tableau de bord de toutes
 * les charges, le tableau du bas les montrer toutes avec un filtre sur la provenance, et tous les
 * tableaux de bord prendre en compte les totaux. » Et : « les numéros FNE paraissent dans certaines
 * lignes importées — le système doit faire la correspondance. »
 *
 * Le décor : une charge saisie (10 000), une pièce du suivi importée dont les observations portent
 * le numéro FNE sous un autre nom de fournisseur (118 000), une facture FNE pas encore au suivi
 * (59 000). Chacune compte une fois : 187 000.
 */
class LesFournisseursSontDesChargesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private User $gerant;

    private FactureFournisseur $piece;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        $this->gerant = $this->compte();

        Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'date' => '2026-03-05',
            'type_operation' => 'Charges', 'libelle' => 'Fonctionnement', 'moyen' => 'Espèces', 'montant' => 10_000,
        ]);

        // Un autre nom, un autre numéro de pièce, pas la même date : seul le numéro FNE relie.
        $this->piece = FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id,
            'fournisseur' => 'Ets Kouassi pièces', 'numero_piece' => 'BL-77', 'date_facture' => '2026-03-11',
            'montant' => 118_000, 'montant_regle' => 0, 'reste_a_payer' => 118_000,
            'observations' => 'Facture normalisée 2626A0000002 reçue par mail',
        ]);

        AchatsFne::importer($this->releve(), 'achats.json', $this->e->id);
    }

    public function test_le_numero_fne_lu_dans_une_ligne_importee_suffit_a_rapprocher(): void
    {
        $this->assertSame($this->piece->id, FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));
        $this->assertNull(FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->value('facture_fournisseur_id'));
    }

    public function test_la_page_charges_compte_les_fournisseurs_une_fois(): void
    {
        $ecran = $this->charges();

        $kpis = $ecran->instance()->kpis;
        $this->assertSame(187_000, $kpis['total']);
        $this->assertSame(177_000, $kpis['fournisseurs']);
        $this->assertSame(187_000, $kpis['totalVentile']['nonVentile'], 'Charge saisie sans activité et fournisseurs : à « Autres ».');

        $origines = $ecran->instance()->origines;
        $this->assertSame(177_000, $origines['fne'], 'La pièce rapprochée et la FNE pas encore au suivi.');
        $this->assertSame(10_000, $origines['nonNormalisees']);
        $this->assertSame(1, $origines['nFne']);

        $ecran->assertSee('Charges fournisseurs')->assertSee('Venant de la FNE');
    }

    public function test_le_tableau_montre_toutes_les_charges_et_filtre_la_provenance(): void
    {
        $ecran = $this->charges();

        $this->assertSame(3, $ecran->instance()->nombreCharges);
        $this->assertSame(187_000, $ecran->instance()->montantCharges);
        $ecran->assertSee('Ets Kouassi pièces')->assertSee('2626A0000003');

        $ecran->set('provenanceFiltre', 'fne');
        $this->assertSame(2, $ecran->instance()->nombreCharges, 'FNE : la pièce rapprochée et la FNE seule.');

        $ecran->set('provenanceFiltre', 'saisie');
        $this->assertSame(['charge', 'fournisseur'], $ecran->instance()->pageCharges->pluck('source')->sort()->values()->all());

        $ecran->set('provenanceFiltre', '')->set('typeFiltre', 'charges');
        $this->assertSame(1, $ecran->instance()->nombreCharges);

        $ecran->set('typeFiltre', '')->set('rechercheCharges', 'Kouassi');
        $this->assertSame(1, $ecran->instance()->nombreCharges);
    }

    public function test_un_filtre_d_activite_ecarte_les_fournisseurs(): void
    {
        $ecran = $this->charges()->set('activiteFiltre', 'Mécanique');

        $this->assertSame(0, $ecran->instance()->kpis['fournisseurs']);
        $this->assertSame(0, $ecran->instance()->nombreCharges);
    }

    public function test_le_chiffre_d_affaires_et_l_accueil_du_gerant_comptent_les_fournisseurs(): void
    {
        $ca = Volt::actingAs($this->gerant)->test('pilotage.chiffre-affaires')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31');
        $this->assertSame(187_000, $ca->instance()->chargesPeriode);
        $this->assertSame(-187_000, $ca->instance()->kpis['resultat']);

        $accueil = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31');
        $kpis = $accueil->instance()->kpis;
        $this->assertSame(187_000, $kpis['charges']);
        $this->assertSame(-187_000, $kpis['resultat']);
        $this->assertSame(187_000, $kpis['chargesVentilees']['mecanique'] + $kpis['chargesVentilees']['sinistre'] + $kpis['chargesVentilees']['nonVentile']);
    }

    public function test_voir_montre_les_lignes_de_la_facture_fne(): void
    {
        $id = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->value('id');

        $this->actingAs($this->gerant)->get(route('fournisseurs.fne.detail', $id))
            ->assertOk()
            ->assertSee('Lignes de la facture (2)')
            ->assertSee('Plaquettes de frein')
            ->assertSee('Porter au suivi');
    }

    // ------------------------------------------------------------------------------ outils

    private function charges()
    {
        return Volt::actingAs($this->gerant)->test('pilotage.charges')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31');
    }

    private function releve(): string
    {
        $emetteur = ['id' => 'c1', 'ncc' => '1234567A', 'name' => 'CIE PIECES', 'rccm' => 'CI-ABJ'];

        return json_encode([
            'login' => '9999999Z',
            'source' => '/ws/invoices?listing=received',
            'periode' => ['du' => '2026-01-01', 'au' => '2026-10-12'],
            'factures' => [
                ['reference' => '2626A0000002', 'id' => 'a2', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2026-03-10T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 100000, 'totalTaxes' => 18000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 118000],
                ['reference' => '2626A0000003', 'id' => 'a3', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2026-04-02T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 50000, 'totalTaxes' => 9000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 59000,
                    'items' => [
                        ['description' => 'Plaquettes de frein', 'quantity' => 2, 'amount' => 15000, 'taxes' => [['code' => 'TVA', 'rate' => 18]]],
                        ['description' => 'Disque avant', 'quantity' => 1, 'amount' => 20000, 'taxes' => [['code' => 'TVA', 'rate' => 18]]],
                    ]],
            ],
        ]);
    }

    private function compte(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

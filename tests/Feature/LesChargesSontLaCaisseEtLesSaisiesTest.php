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
use Modules\Noyau\Exploitation\Services\BalanceDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Services\AchatsFne;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ce qu'est une charge — 20/10, et ce qui reste du 18/10.
 *
 * « Toutes les sorties de caisse sont des charges. » Les fournisseurs « ne font que retracer,
 * c'est juste un suivi » : ils se lisent à côté, jamais additionnés. Une facture payée en espèces
 * compte donc une fois, par sa sortie de caisse.
 *
 * Le décor : une charge saisie (10 000), une sortie de caisse « salaire » sans atelier (20 000),
 * une pièce du suivi dont les observations portent le n° FNE sous un autre nom de fournisseur
 * (118 000), une facture FNE pas encore au suivi (59 000). Charges : 30 000.
 */
class LesChargesSontLaCaisseEtLesSaisiesTest extends TestCase
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

        // Le journal ne dit jamais l'atelier : la sortie se place par sa ville.
        MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'site_id' => null,
            'date' => '2026-03-06', 'sens' => MouvementCaisse::SORTIE, 'libelle' => 'AVANCE SALAIRE MARS',
            'montant' => 20_000, 'beneficiaire' => 'KONE Ibrahim', 'numero_piece' => 'PC-1',
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

    public function test_les_charges_sont_les_sorties_de_caisse_et_les_saisies_sans_les_fournisseurs(): void
    {
        $ecran = $this->charges();

        $kpis = $ecran->instance()->kpis;
        $this->assertSame(30_000, $kpis['total']);
        $this->assertSame(20_000, $kpis['salaires'], 'La sortie « avance salaire » est une charge de personnel.');
        $this->assertSame(10_000, $kpis['fonctionnement']);
        $this->assertSame(30_000, $kpis['totalVentile']['nonVentile']);

        $origines = $ecran->instance()->origines;
        $this->assertSame(20_000, $origines['caisse']);
        $this->assertSame(10_000, $origines['saisies']);

        // Le graphique dit enfin quelque chose : la série salaires porte la sortie de caisse.
        $salaires = collect($ecran->instance()->graphique['datasets'])->firstWhere('label', 'Salaires & personnel');
        $this->assertSame(20_000, array_sum($salaires['data']));

        $ecran->assertSee('Suivi fournisseurs — à côté');
    }

    public function test_le_tableau_montre_les_charges_et_le_suivi_a_cote(): void
    {
        $ecran = $this->charges();

        $this->assertSame(2, $ecran->instance()->nombreCharges);
        $this->assertSame(30_000, $ecran->instance()->montantCharges);
        $ecran->assertSee('AVANCE SALAIRE MARS');

        $ecran->set('provenanceFiltre', 'caisse');
        $this->assertSame(1, $ecran->instance()->nombreCharges);

        $ecran->set('provenanceFiltre', '')->set('typeFiltre', 'fournisseurs');
        $this->assertSame(2, $ecran->instance()->nombreCharges, 'La pièce du suivi et la FNE pas encore au suivi.');
        $ecran->assertSee('Ets Kouassi pièces')->assertSee('Détails');

        $ecran->set('typeFiltre', '')->set('rechercheCharges', 'KONE');
        $this->assertSame(1, $ecran->instance()->nombreCharges);
    }

    public function test_un_filtre_d_activite_ecarte_la_caisse(): void
    {
        $ecran = $this->charges()->set('activiteFiltre', 'Mécanique');

        $this->assertSame(0, $ecran->instance()->kpis['total']);
        $this->assertSame(0, $ecran->instance()->nombreCharges);
    }

    public function test_le_chiffre_d_affaires_et_l_accueil_du_gerant_lisent_les_memes_charges(): void
    {
        $ca = Volt::actingAs($this->gerant)->test('pilotage.chiffre-affaires')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31');
        $this->assertSame(30_000, $ca->instance()->chargesPeriode);
        $this->assertSame(-30_000, $ca->instance()->kpis['resultat']);

        $accueil = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31');
        $kpis = $accueil->instance()->kpis;
        $this->assertSame(30_000, $kpis['charges']);
        $this->assertSame(-30_000, $kpis['resultat']);
        $this->assertSame(30_000, $kpis['chargesVentilees']['mecanique'] + $kpis['chargesVentilees']['sinistre'] + $kpis['chargesVentilees']['nonVentile']);
    }

    public function test_details_montre_les_lignes_de_la_facture_fne(): void
    {
        $id = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->value('id');

        $this->actingAs($this->gerant)->get(route('fournisseurs.fne.detail', $id))
            ->assertOk()
            ->assertSee('Lignes de la facture (2)')
            ->assertSee('Plaquettes de frein')
            ->assertSee('Porter au suivi');
    }

    /** « Les champs préremplis doivent être en premier, et ne pas pouvoir être modifiés » — 20/10. */
    public function test_porter_garde_ce_que_dit_la_facture_fne_quoi_qu_on_envoie(): void
    {
        $id = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->value('id');

        \Livewire\Livewire::withQueryParams(['porter' => $id])->actingAs($this->gerant)->test('pilotage.fournisseurs')
            ->assertSee('Repris de la facture FNE')
            ->set('fournisseur', 'Nom trafiqué')->set('montant', '1')->set('montantRegle', '59000')
            ->call('enregistrer')->assertHasNoErrors();

        $piece = FactureFournisseur::withoutGlobalScopes()->where('numero_piece', '2626A0000003')->sole();
        $this->assertSame('CIE PIECES', $piece->fournisseur);
        $this->assertSame(59_000, $piece->montant);
        $this->assertSame(59_000, $piece->reste_a_payer, 'Porter n\'est pas régler.');
        $this->assertSame(['fournisseur', 'numero_piece', 'date_facture', 'montant'], \Modules\Noyau\Exploitation\Services\EtatDesFournisseurs::champsVerrouilles($piece));
    }

    /** « Si la caisse fait un règlement fournisseur et qu'on veut savoir, le suivi peut nous aider » — 20/10. */
    public function test_un_reglement_dit_la_sortie_de_caisse_qui_le_paie_sans_rien_compter_deux_fois(): void
    {
        $sortie = MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'date' => '2026-03-20',
            'sens' => MouvementCaisse::SORTIE, 'libelle' => 'REGLEMENT FACTURE KOUASSI', 'montant' => 118_000,
        ]);

        Volt::actingAs($this->gerant)->test('pilotage.fournisseur-piece', ['piece' => $this->piece->id])
            ->call('ouvrirReglement')
            ->assertSee('Payé par la caisse')
            ->set('reglerSortie', (string) $sortie->id)
            ->call('regler')->assertHasNoErrors();

        $reglement = \Modules\Noyau\Imports\Modeles\ReglementFournisseur::withoutGlobalScopes()->sole();
        $this->assertSame($sortie->id, (int) $reglement->mouvement_caisse_id);
        $this->assertSame(0, $this->piece->fresh()->reste_a_payer);

        // La charge est la sortie de caisse, une fois : 10 000 + 20 000 + 118 000.
        $this->assertSame(148_000, $this->charges()->instance()->kpis['total']);

        $fournisseurs = Volt::actingAs($this->gerant)->test('pilotage.fournisseurs')->set('exercice', 2026);
        $this->assertSame(118_000, $fournisseurs->instance()->normalises['parLaCaisse']);
        $this->assertSame(118_000, $fournisseurs->instance()->normalises['suiviNormalise']);
        $this->assertSame(1, $fournisseurs->instance()->normalises['nHorsSuivi']);
    }

    public function test_la_balance_du_systeme_range_le_reste_par_anciennete(): void
    {
        $balance = BalanceDesFournisseurs::calculer($this->e->id, \Carbon\Carbon::parse('2026-05-20'));
        $kouassi = $balance->firstWhere('fournisseur', 'Ets Kouassi pièces');

        $this->assertSame(118_000, $kouassi['reste']);
        // Facturée le 11/03, sans échéance : 70 jours au 20/05 — tranche 61-90 j.
        $this->assertSame(118_000, $kouassi['tranches'][2]);

        Volt::actingAs($this->gerant)->test('pilotage.balance-fournisseurs')
            ->set('vue', 'systeme')
            ->assertSee('Balance produite par le système')
            ->assertSee('Ets Kouassi pièces');
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

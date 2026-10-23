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
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\ChargesDeLaPeriode;
use Modules\Noyau\Exploitation\Services\ChargesParSource;
use Modules\Noyau\Exploitation\Services\GrandLivreFournisseur;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les retours du 23/10 : versements hors des charges, débits de banque lus par libellé, décaissements
 * fournisseurs suivis et lettrés, grand livre, liste des fournisseurs, avoir à l'état des impayés.
 */
class LesDecaissementsFournisseursSeSuiventTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->gerant = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();
    }

    /** « Versements et transferts ne sont pas des charges : ils peuvent rester, avec un mot. » */
    public function test_les_versements_restent_montres_mais_ne_comptent_pas_dans_les_charges(): void
    {
        $this->sortie('ACHAT PLAQUETTES', 30_000);
        $this->sortie('VERSEMENT BANQUE BGFI', 500_000);

        $charges = ChargesDeLaPeriode::pourLesSites([$this->site->id], '2026-01-01', '2026-12-31');
        $this->assertSame(30_000, $charges->totaux()['caisse']);
        $this->assertSame(['total' => 500_000, 'n' => 1], $charges->versementsEtTransferts());

        $caisse = ChargesParSource::pourLesSites($this->e->id, [$this->site->id], '2026-01-01', '2026-12-31')->caisse();
        $this->assertSame(30_000, $caisse['total']);
        $this->assertSame(500_000, $caisse['versements']);
    }

    /** « Pour les débits, examine le libellé : s'il ne dit ni fournisseur ni charge, ce n'en est pas une. » */
    public function test_un_debit_de_banque_n_est_retenu_que_si_son_libelle_dit_une_charge(): void
    {
        $this->assertTrue(ChargesParSource::debitEstUneCharge('VIR REGLEMENT FOURNISSEUR CFAO'));
        $this->assertTrue(ChargesParSource::debitEstUneCharge('PAIEMENT SALAIRES OCTOBRE'));
        $this->assertFalse(ChargesParSource::debitEstUneCharge('CHEQUE 0012345'));
        $this->assertFalse(ChargesParSource::debitEstUneCharge('TRANSFERT VERS COMPTE AFG'));
    }

    /** « Suivre » : la pièce naît réglée par la sortie, et les deux lignes se répondent. */
    public function test_suivre_un_decaissement_cree_la_piece_reglee_et_lettree(): void
    {
        $sortie = $this->sortie('ACHAT PIECES TOYOTA', 75_000, 'Ets KOUASSI');

        Volt::actingAs($this->gerant)->test('pilotage.decaissements-fournisseurs')
            ->set('du', '2026-01-01')->set('au', '2026-12-31')
            ->assertSee('ACHAT PIECES TOYOTA')
            ->assertSee('à suivre');

        \Livewire\Livewire::withQueryParams(['suivre' => $sortie->id])->actingAs($this->gerant)->test('pilotage.fournisseurs')
            ->assertSet('suivreSortie', $sortie->id)
            ->assertSet('fournisseur', 'Ets KOUASSI')
            ->set('numeroPiece', 'FAC-881')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $piece = FactureFournisseur::withoutGlobalScopes()->where('numero_piece', 'FAC-881')->sole();
        $this->assertSame(75_000, montant_de($piece->montant_regle));
        $this->assertSame(0, montant_de($piece->reste_a_payer));
        $this->assertSame($sortie->id, (int) ReglementFournisseur::withoutGlobalScopes()->sole()->mouvement_caisse_id);

        // La ligne de la caisse dit sa pièce ; elle ne se suit plus une seconde fois.
        Volt::actingAs($this->gerant)->test('pilotage.decaissements-fournisseurs')
            ->set('du', '2026-01-01')->set('au', '2026-12-31')
            ->assertSee('FAC-881')
            ->assertDontSee('suivre='.$sortie->id, false);

        \Livewire\Livewire::withQueryParams(['suivre' => $sortie->id])->actingAs($this->gerant)->test('pilotage.fournisseurs')
            ->assertSet('suivreSortie', null);
    }

    public function test_le_grand_livre_fait_courir_le_solde(): void
    {
        $piece = FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'fournisseur' => 'Ets Kouassi',
            'numero_piece' => 'F1', 'date_facture' => '2026-02-01', 'montant' => 100_000, 'montant_regle' => 40_000,
            'reste_a_payer' => 60_000, 'date_reglement' => '2026-02-10',
        ]);
        FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'fournisseur' => 'ETS KOUASSI.',
            'numero_piece' => 'F2', 'date_facture' => '2026-03-01', 'montant' => 50_000, 'montant_regle' => 0, 'reste_a_payer' => 50_000,
        ]);

        $this->actingAs($this->gerant);
        $compte = GrandLivreFournisseur::pour('Ets Kouassi', [$this->ville->id], '2026-02-05', '2026-12-31');

        // La facture du 01/02 est avant la période : elle fait le report.
        $this->assertSame(100_000, $compte['report']);
        $this->assertSame(40_000, $compte['debit']);
        $this->assertSame(50_000, $compte['credit']);
        $this->assertSame(110_000, $compte['solde']);
        $this->assertSame([60_000, 110_000], $compte['lignes']->pluck('solde')->all());

        Volt::actingAs($this->gerant)->test('pilotage.grand-livre-fournisseurs')
            ->set('fournisseur', 'Ets Kouassi')
            ->assertSee('Réglé selon le suivi')
            ->assertSee('F2');
        $this->assertNotNull($piece);
    }

    /** « Ajouter une créance qui est un avoir : le signe négatif doit être pris en compte. » */
    public function test_un_avoir_se_saisit_en_negatif_a_l_etat_des_impayes(): void
    {
        $this->actingAs($this->gerant);

        Volt::test('pilotage.impayes')
            ->set('fDate', '2026-03-10')
            ->set('fNumero', 'AV-12')
            ->set('fClient', 'NSIA')
            ->set('fSiteId', (string) $this->site->id)
            ->set('fMontant', '-150000.50')
            ->set('fDateReception', '2026-03-11')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $avoir = Facture::withoutGlobalScopes()->where('n_facture', 'AV-12')->sole();
        $this->assertSame(-150000.5, montant_de($avoir->montant));
        $this->assertTrue((bool) $avoir->est_avoir);

        // Un avoir ne se règle pas.
        Volt::test('pilotage.impayes')
            ->set('fDate', '2026-03-10')->set('fNumero', 'AV-13')->set('fClient', 'NSIA')
            ->set('fSiteId', (string) $this->site->id)->set('fMontant', '-1000')
            ->set('fDateReception', '2026-03-11')->set('fRegle', '500')
            ->set('fSupport', 'caisse')->set('fDateReglement', '2026-03-12')
            ->call('enregistrer')
            ->assertHasErrors('fRegle');
    }

    public function test_fournisseurs_quitte_le_menu_et_les_autres_s_expliquent(): void
    {
        $this->actingAs($this->gerant);
        $libelles = collect(MenuNavigation::pour($this->gerant))->flatMap(fn ($o) => isset($o['groupe']) ? array_column($o['groupe'], 'label') : [$o['label']]);
        $this->assertNotContains('Fournisseurs', $libelles->all());

        $this->sortie('ACHAT PLAQUETTES', 30_000);

        Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31')
            ->assertSee('Autres : décaissements de caisse');
    }

    // ------------------------------------------------------------------------------ outils

    private function sortie(string $libelle, int|float $montant, ?string $beneficiaire = null): MouvementCaisse
    {
        return MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'site_id' => null,
            'date' => '2026-03-06', 'sens' => MouvementCaisse::SORTIE, 'libelle' => $libelle,
            'montant' => $montant, 'beneficiaire' => $beneficiaire,
        ]);
    }
}

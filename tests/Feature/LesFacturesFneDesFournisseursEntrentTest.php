<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;
use Modules\Noyau\Imports\Services\AchatsFne;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les factures d'achat FNE — 12/10.
 *
 * « La récupération FNE prend toutes les pièces émises par un tiers sur la plateforme, avoirs
 * compris — elle doit mettre chacune dans son exercice. » Le suivi est la base ; il s'importe
 * avant ou après le relevé, et la correspondance tient dans les deux sens. Une facture que le
 * suivi ne connaît pas se **porte** au suivi ; chaque paiement se **règle** et se lettre.
 *
 * Le relevé a la forme de celui qu'écrit `SCRAPER-PORTAIL-FNE/achats.js` (repris de Selflow).
 */
class LesFacturesFneDesFournisseursEntrentTest extends TestCase
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

    public function test_chaque_piece_va_dans_son_exercice_et_l_avoir_est_negatif(): void
    {
        $bilan = AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);

        $this->assertSame(3, $bilan['factures']);
        $this->assertSame(1, $bilan['avoirs']);

        $decembre = FactureFne::withoutGlobalScopes()->where('reference', '2525A0000001')->first();
        $avoir = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000009')->first();

        $this->assertSame(2025, $decembre->exercice);
        $this->assertSame(2026, $avoir->exercice);
        $this->assertTrue($avoir->est_avoir);
        $this->assertSame(-59000, $avoir->montant_ttc);
        $this->assertSame(-9000, $avoir->montant_tva);
    }

    public function test_la_cle_d_api_de_l_emetteur_n_est_pas_conservee(): void
    {
        AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);

        $contenu = FactureFne::withoutGlobalScopes()->first()->contenu;

        $this->assertArrayNotHasKey('apiKey', $contenu['company']);
        $this->assertSame('CIE PIECES', $contenu['company']['name']);
    }

    public function test_le_meme_releve_ne_s_importe_pas_deux_fois_et_le_lien_survit(): void
    {
        AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);
        $this->assertTrue(AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id)['deja']);

        $facture = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->first();
        $piece = AchatsFne::porter($facture, $this->compte('gerant'));

        // Un relevé suivant, autre fichier, qui reprend la même facture : mise à jour, lien gardé.
        AchatsFne::importer($this->releve(['periode' => ['du' => '2026-01-01', 'au' => '2026-10-12']]), 'achats-2.json', $this->entreprise->id);

        $this->assertSame(3, FactureFne::withoutGlobalScopes()->count());
        $this->assertSame($piece->id, $facture->fresh()->facture_fournisseur_id);
    }

    public function test_le_suivi_importe_avant_le_releve_est_rapproche_par_le_numero(): void
    {
        $ligne = $this->ligne('Cie Pièces', 'FAC 2626A0000002', '2026-03-10', 118000);

        $bilan = AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);

        $this->assertSame(1, $bilan['rapprochees']);
        $this->assertSame($ligne->id, FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));
    }

    public function test_le_suivi_importe_apres_le_releve_est_rapproche_par_date_et_montant(): void
    {
        AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);
        $ligne = $this->ligne('CIE PIECES', 'F-458', '2026-03-10', 118000);

        $this->assertSame(1, AchatsFne::rapprocher($this->entreprise->id));
        $this->assertSame($ligne->id, FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));
    }

    public function test_deux_candidates_ne_tranchent_rien(): void
    {
        $this->ligne('CIE PIECES', 'F-1', '2026-03-10', 118000);
        $this->ligne('CIE PIECES', 'F-2', '2026-03-10', 118000);

        $this->assertSame(0, AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id)['rapprochees']);
    }

    public function test_porter_cree_la_dette_et_regler_la_lettre(): void
    {
        AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);
        $gerant = $this->compte('gerant');

        $piece = AchatsFne::porter(FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->first(), $gerant);

        $this->assertSame(118000, $piece->reste_a_payer);
        $this->assertSame('fne', $piece->source_rattachement);

        AchatsFne::regler($piece, 18000, '2026-04-01', 'Chèque', $gerant);
        $reglement = AchatsFne::regler($piece->fresh(), 100000, '2026-05-01', 'Virement', $gerant);

        $piece->refresh();
        $this->assertSame(0, $piece->reste_a_payer);
        $this->assertSame(118000, $piece->montant_regle);
        $this->assertSame(2, ReglementFournisseur::withoutGlobalScopes()->where('facture_fournisseur_id', $piece->id)->count());
        $this->assertStringStartsWith('RGF', $reglement->code_reglement);

        $this->expectException(\RuntimeException::class);
        AchatsFne::regler($piece, 1, '2026-05-02', null, $gerant);
    }

    public function test_l_avoir_porte_se_regle_en_negatif(): void
    {
        AchatsFne::importer($this->releve(), 'achats.json', $this->entreprise->id);
        $gerant = $this->compte('gerant');

        $piece = AchatsFne::porter(FactureFne::withoutGlobalScopes()->where('reference', '2626A0000009')->first(), $gerant);
        $this->assertSame('Avoir FNE', $piece->nature_piece);
        $this->assertSame(-59000, $piece->reste_a_payer);

        AchatsFne::regler($piece, -59000, '2026-06-01', null, $gerant);
        $this->assertSame(0, $piece->fresh()->reste_a_payer);
    }

    public function test_la_page_montre_les_differences_dans_les_deux_sens(): void
    {
        $this->ligne('Autre fournisseur', 'X-1', '2026-02-01', 5000);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-fne')
            ->set('fichier', UploadedFile::fake()->createWithContent('achats.json', $this->releve()))
            ->call('deposer')
            ->assertHasNoErrors()
            ->set('exercice', 2026)
            ->assertSet('kpi.n', 2)
            ->assertSet('kpi.avoirs', 1)
            ->assertSet('kpi.a_porter', 2)
            ->assertSet('kpi.suivi_sans_fne', 1)
            ->call('porter', FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('id'))
            ->assertSet('kpi.a_porter', 1);
    }

    public function test_le_reglement_se_saisit_depuis_la_piece(): void
    {
        $ligne = $this->ligne('CIE PIECES', 'F-458', '2026-03-10', 118000);

        Volt::actingAs($this->compte('caissier'))->test('pilotage.fournisseur-piece', ['piece' => $ligne->id])
            ->call('ouvrirReglement')
            ->assertSet('reglerMontant', '118000')
            ->set('reglerMontant', '50000')
            ->call('regler')
            ->assertHasNoErrors();

        $this->assertSame(68000, $ligne->fresh()->reste_a_payer);
        $this->assertSame(1, ReglementFournisseur::withoutGlobalScopes()->where('facture_fournisseur_id', $ligne->id)->count());
    }

    public function test_le_commercial_ne_regle_pas(): void
    {
        $ligne = $this->ligne('CIE PIECES', 'F-458', '2026-03-10', 118000);

        $this->actingAs($this->compte('responsable_site'));
        Volt::test('pilotage.fournisseurs-fne')->call('rapprocher')->assertForbidden();
    }

    public function test_la_commande_range_le_releve_du_scraper(): void
    {
        $fichier = tempnam(sys_get_temp_dir(), 'fne').'.json';
        file_put_contents($fichier, $this->releve());

        $this->artisan('fne:releve-achats', ['fichier' => $fichier])->assertSuccessful();
        $this->artisan('fne:releve-achats', ['fichier' => $fichier])->expectsOutputToContain('déjà lu')->assertSuccessful();

        $this->assertSame(3, FactureFne::withoutGlobalScopes()->count());
        @unlink($fichier);
    }

    // ------------------------------------------------------------------------------ outils

    private function releve(array $enveloppe = []): string
    {
        $emetteur = ['id' => 'c1', 'ncc' => '1234567A', 'name' => 'CIE PIECES', 'rccm' => 'CI-ABJ', 'apiKey' => 'secret'];

        return json_encode($enveloppe + [
            'login' => '9999999Z',
            'source' => '/ws/invoices?listing=received',
            'periode' => ['du' => '2025-12-01', 'au' => '2026-10-12'],
            'factures' => [
                ['reference' => '2525A0000001', 'id' => 'a1', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2025-12-30T10:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 10000, 'totalTaxes' => 1800, 'fiscalStamp' => 0, 'totalAfterTaxes' => 11800],
                ['reference' => '2626A0000002', 'id' => 'a2', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2026-03-10T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 100000, 'totalTaxes' => 18000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 118000],
                ['reference' => '2626A0000009', 'id' => 'a9', 'type' => 'invoice', 'subtype' => 'refund', 'status' => 'certified',
                    'date' => '2026-04-02T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 50000, 'totalTaxes' => 9000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 59000],
                ['id' => 'sans-reference'],
            ],
        ]);
    }

    private function ligne(string $fournisseur, string $numero, string $date, int $montant): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'fournisseur' => $fournisseur, 'numero_piece' => $numero, 'date_facture' => $date,
            'montant' => $montant, 'montant_regle' => 0, 'reste_a_payer' => $montant,
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

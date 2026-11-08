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
use Modules\Noyau\Exploitation\Services\SuppressionDUnePieceFournisseur;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Services\AchatsFne;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Services\DepotDeRapport;
use Modules\Noyau\Rapport\Services\DepotEnDoubleDeRapport;
use Modules\Noyau\Rapport\Services\RapportFinancier;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le module Rapport et la FNE comptée directement — 06/11.
 *
 * « Les factures FNE vont directement dans les charges fournisseurs et sont comptées directement » ;
 * « une facture FNE ne doit pas pouvoir être supprimée » ; « un KPI du montant des charges comptées » ;
 * « le dépôt de fichiers ne fait pas de doublons ».
 *
 * Le décor : une sortie de caisse administrative (20 000) et une facture FNE de 59 000 que le suivi ne
 * porte pas. Les charges comptées sont donc de 79 000.
 */
class LeModuleRapportEtLaFneDirecteTest extends TestCase
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
        $this->gerant = $this->compte();

        MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'site_id' => null,
            'date' => '2026-03-06', 'sens' => MouvementCaisse::SORTIE, 'libelle' => 'AVANCE SALAIRE MARS',
            'montant' => 20_000, 'beneficiaire' => 'KONE Ibrahim', 'numero_piece' => 'PC-1',
        ]);

        AchatsFne::importer($this->releve(), 'achats.json', $this->e->id);
    }

    public function test_une_facture_fne_pas_encore_au_suivi_compte_directement_dans_les_charges(): void
    {
        $ecran = $this->charges();

        $this->assertSame(79_000, $ecran->instance()->kpis['total'], 'Caisse 20 000 + FNE directe 59 000.');
        $this->assertSame(59_000, $ecran->instance()->totauxCharges['fne']);
        $this->assertSame(1, $ecran->instance()->totauxCharges['nFne']);
        $ecran->assertSee('Charges comptées')->assertSee('Factures FNE comptées directement');
    }

    public function test_une_fne_portee_ne_compte_qu_une_fois(): void
    {
        $fne = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->sole();
        AchatsFne::porter($fne, $this->gerant);

        $total = $this->charges()->instance()->totauxCharges;

        $this->assertSame(0, $total['fne'], 'La FNE portée compte par sa ligne du suivi.');
        $this->assertSame(59_000, $total['suivi']);
        $this->assertSame(79_000, $total['total']);
    }

    public function test_le_bouton_regle_prerempli_le_montant_regle_comme_porter_prerempli_la_facture(): void
    {
        $id = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->value('id');

        Volt::actingAs($this->gerant)->test('pilotage.fournisseurs-fne')->set('exercice', 2026)->assertSee('Réglé')->assertDontSee('Porter au suivi');

        \Livewire\Livewire::withQueryParams(['porter' => $id, 'regle' => 1])->actingAs($this->gerant)->test('pilotage.fournisseurs')
            ->assertSet('montantRegle', '59000')
            ->call('enregistrer')->assertHasNoErrors();

        $piece = FactureFournisseur::withoutGlobalScopes()->where('numero_piece', '2626A0000003')->sole();
        $this->assertSame(0, $piece->reste_a_payer);
        $this->assertSame('fne', $piece->source_rattachement);
    }

    public function test_une_facture_fne_ne_se_supprime_pas(): void
    {
        $fne = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->sole();

        $this->expectException(\LogicException::class);
        $fne->delete();
    }

    public function test_la_piece_creee_depuis_une_fne_ne_se_supprime_pas(): void
    {
        $fne = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000003')->sole();
        $piece = AchatsFne::porter($fne, $this->gerant);

        $this->assertStringContainsString('FNE', (string) SuppressionDUnePieceFournisseur::refus($this->gerant, $piece->fresh()));
    }

    public function test_le_rapport_s_ouvre_pour_le_gerant_et_lit_les_memes_charges(): void
    {
        $this->actingAs($this->gerant)->get(route('rapport'))->assertOk()->assertSee('RAPPORT FINANCIER');

        $rapport = RapportFinancier::pour($this->gerant, 2026);
        $this->assertSame(79_000, $rapport->charges()['totalCompte']);

        $ecran = Volt::actingAs($this->gerant)->test('pilotage.rapport')->set('exercice', 2026);
        foreach (['ca', 'recouvrement', 'charges', 'conclusion', 'commerciaux', 'precedent', 'depot'] as $vue) {
            $ecran->call('aller', $vue)->assertSet('vue', $vue)->assertOk();
        }
    }

    public function test_un_fichier_depose_deux_fois_est_refuse_et_ses_lignes_ne_se_dupliquent_pas(): void
    {
        $csv = "Date;Libellé;Référence;Montant;Statut\n03/11/2026;Droits simples;AVIS-329;157 941 816;dû\n03/11/2026;Pénalités;AVIS-329;11 826 393;dû\n";

        $premier = DepotDeRapport::deposer(UploadedFile::fake()->createWithContent('impots.csv', $csv), $this->gerant, 'impot', 2026);
        $this->assertSame(2, $premier->nb_nouvelles);
        $this->assertSame(2, PieceRapport::query()->count());

        // Le même contenu, sous un autre nom : refusé d'emblée.
        $this->expectException(DepotEnDoubleDeRapport::class);
        DepotDeRapport::deposer(UploadedFile::fake()->createWithContent('copie.csv', $csv), $this->gerant, 'impot', 2026);
    }

    public function test_un_recap_qui_reprend_des_pieces_connues_n_ajoute_rien(): void
    {
        DepotDeRapport::deposer(UploadedFile::fake()->createWithContent('impots.csv', "Libellé;Référence;Montant\nDroits simples;AVIS-329;157 941 816\n"), $this->gerant, 'impot', 2026);

        // Un autre fichier (autre empreinte) qui répète la même ligne et en ajoute une.
        $recap = DepotDeRapport::deposer(UploadedFile::fake()->createWithContent('recap.csv', "Libellé;Référence;Montant;Date\nDroits simples;avis 329;157 941 816;\nPénalités;AVIS-329;11 826 393;03/11/2026\nTotal;;169 768 209;\n"), $this->gerant, 'impot', 2026);

        $this->assertSame(1, $recap->nb_nouvelles);
        $this->assertSame(1, $recap->nb_doublons);
        $this->assertSame(2, PieceRapport::query()->count());
    }

    public function test_un_paiement_deja_en_caisse_est_garde_sans_etre_recompte(): void
    {
        MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'date' => '2026-10-15',
            'sens' => MouvementCaisse::SORTIE, 'libelle' => 'IMPOTS ACOMPTE', 'montant' => 7_666_666, 'numero_piece' => 'PC-9',
        ]);

        DepotDeRapport::saisir(['nature' => 'impot', 'statut' => 'paye', 'date' => '2026-10-16', 'libelle' => 'Acompte impôts', 'tiers' => null, 'reference' => 'Q-12', 'montant' => 7_666_666], $this->gerant, 2026);

        $piece = PieceRapport::query()->sole();
        $this->assertFalse($piece->compte, 'La caisse administrative le compte déjà dans les charges.');
        $this->assertSame(0, RapportFinancier::pour($this->gerant, 2026)->charges()['impots']['paye']);
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
                ['reference' => '2626A0000003', 'id' => 'a3', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2026-04-02T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 50000, 'totalTaxes' => 9000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 59000,
                    'items' => [['description' => 'Plaquettes de frein', 'quantity' => 2, 'amount' => 15000, 'taxes' => [['code' => 'TVA', 'rate' => 18]]]]],
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

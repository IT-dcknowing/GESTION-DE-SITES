<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Services\SuppressionDUnePieceFournisseur;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;
use Modules\Noyau\Imports\Services\AchatsFne;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Supprimer une pièce fournisseur importée — 24/11.
 *
 * « Dans la gestion des fournisseurs, ajoute un bouton Supprimer qui concerne uniquement les
 * factures déposées par l'importation, et non les FNE normalisées. » Le bouton est sur le tableau
 * des charges fournisseurs ; chaque verrou se revérifie côté serveur, bouton caché ou non.
 */
class UnePieceFournisseurImporteeSeSupprimeTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private LotImport $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        $this->lot = $this->lot($this->e, $this->ville);
    }

    public function test_une_piece_importee_sans_fne_se_supprime_depuis_le_tableau_et_laisse_sa_trace(): void
    {
        $piece = $this->piece(['numero_piece' => 'BL-501', 'montant' => 118_000.5, 'montant_regle' => 18_000, 'reste_a_payer' => 100_000.5]);
        // Une écriture déjà générée : elle reste, la prochaine génération la traitera.
        $operation = $this->operationDuSuivi($piece);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')->set('exercice', '2026')
            ->assertSee('demanderLaSuppression('.$piece->id.')', false)
            ->call('demanderLaSuppression', $piece->id)
            ->assertSee('Oui, effacer')
            ->assertSee('reviendra au prochain dépôt')
            ->call('confirmerLaSuppression')
            ->assertDispatched('annonce');

        $this->assertNull(FactureFournisseur::withoutGlobalScopes()->find($piece->id));
        $this->assertNotNull(OperationComptable::withoutGlobalScopes()->find($operation->id));

        $trace = Activity::query()->where('description', 'Charges fournisseurs — pièce importée supprimée')->sole();
        $this->assertSame($this->lot->id, $trace->properties['lot_import_id']);
        $this->assertSame([$operation->id], $trace->properties['operations_comptables_laissees']);
        // Le montant au centime près, et le réglé lu dans le fichier, gardés au journal.
        $this->assertEquals(118_000.5, $trace->properties['montant']);
        $this->assertEquals(18_000, montant_de($trace->properties['ligne_effacee']['montant_regle']));
    }

    public function test_une_piece_importee_rapprochee_d_une_fne_normalisee_ne_se_supprime_pas(): void
    {
        $piece = $this->piece(['numero_piece' => 'BL-77', 'observations' => 'Facture normalisée 2626A0000002 reçue par mail']);
        AchatsFne::importer($this->releve(), 'achats.json', $this->e->id);
        $this->assertSame($piece->id, (int) FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));

        $gerant = $this->compte('gerant');
        $this->assertStringContainsString('normalisée', (string) SuppressionDUnePieceFournisseur::refus($gerant, $piece));

        // Pas de bouton sur la ligne verte ; et l'action appelée quand même refuse.
        Volt::actingAs($gerant)->test('pilotage.fournisseurs')->set('exercice', '2026')
            ->assertDontSee('demanderLaSuppression('.$piece->id.')', false)
            ->set('aSupprimer', $piece->id)
            ->call('confirmerLaSuppression')
            ->assertDispatched('annonce');

        $this->assertNotNull(FactureFournisseur::withoutGlobalScopes()->find($piece->id));
        $this->assertSame($piece->id, (int) FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));
    }

    public function test_une_piece_importee_creee_d_une_fne_ne_se_supprime_pas(): void
    {
        $piece = $this->piece(['source_rattachement' => 'fne']);

        $this->assertStringContainsString('FNE', (string) SuppressionDUnePieceFournisseur::refus($this->compte('gerant'), $piece));
        $this->expectException(\RuntimeException::class);
        SuppressionDUnePieceFournisseur::effacerUnePieceImportee($this->compte('gerant', 'bis'), $piece);
    }

    public function test_un_reglement_ou_un_avoir_rattache_empeche_la_suppression(): void
    {
        $gerant = $this->compte('gerant');

        $reglee = $this->piece(['numero_piece' => 'BL-R']);
        ReglementFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'fournisseur' => 'Ets Kouassi',
            'code_reglement' => 'RG-1', 'date_reglement' => '2026-03-20', 'montant' => 50_000,
            'facture_fournisseur_id' => $reglee->id,
        ]);
        $this->assertStringContainsString('règlement', (string) SuppressionDUnePieceFournisseur::refus($gerant, $reglee));

        $avoiree = $this->piece(['numero_piece' => 'BL-A']);
        FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'avoir_de_id' => $avoiree->id,
            'fournisseur' => 'Ets Kouassi', 'numero_piece' => 'AV-1', 'date_facture' => '2026-03-15',
            'montant' => -10_000, 'montant_regle' => 0, 'reste_a_payer' => -10_000,
        ]);
        $this->assertStringContainsString('avoir', (string) SuppressionDUnePieceFournisseur::refus($gerant, $avoiree));

        Volt::actingAs($gerant)->test('pilotage.fournisseurs')->set('exercice', '2026')
            ->call('demanderLaSuppression', $reglee->id)->call('confirmerLaSuppression')->assertDispatched('annonce');

        $this->assertNotNull(FactureFournisseur::withoutGlobalScopes()->find($reglee->id));
        $this->assertSame(1, ReglementFournisseur::withoutGlobalScopes()->where('facture_fournisseur_id', $reglee->id)->count());
    }

    public function test_une_piece_saisie_n_est_pas_ouverte_a_ce_bouton_et_garde_ses_regles(): void
    {
        $saisie = $this->piece(['lot_import_id' => null, 'numero_piece' => 'S-1']);
        $gerant = $this->compte('gerant');

        Volt::actingAs($gerant)->test('pilotage.fournisseurs')->set('exercice', '2026')
            ->assertDontSee('demanderLaSuppression('.$saisie->id.')', false)
            ->set('aSupprimer', $saisie->id)
            ->call('confirmerLaSuppression')
            ->assertDispatched('annonce');

        $this->assertNotNull(FactureFournisseur::withoutGlobalScopes()->find($saisie->id));
        // La page de la pièce la supprime toujours, comme avant (sans règlement).
        $this->assertNull(SuppressionDUnePieceFournisseur::refus($gerant, $saisie));
        $saisieReglee = $this->piece(['lot_import_id' => null, 'numero_piece' => 'S-2', 'montant_regle' => 1_000, 'reste_a_payer' => 117_000]);
        $this->assertStringContainsString('règlement', (string) SuppressionDUnePieceFournisseur::refus($gerant, $saisieReglee));
    }

    public function test_sans_habilitation_le_bouton_manque_et_l_action_repond_403(): void
    {
        $piece = $this->piece();

        Volt::actingAs($this->compte('caissier'))->test('pilotage.fournisseurs')->set('exercice', '2026')
            ->assertDontSee('demanderLaSuppression('.$piece->id.')', false)
            ->set('aSupprimer', $piece->id)
            ->call('confirmerLaSuppression')
            ->assertForbidden();

        $this->assertNotNull(FactureFournisseur::withoutGlobalScopes()->find($piece->id));
    }

    public function test_une_piece_d_une_autre_entreprise_n_existe_pas(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        ProvisionneurEntreprise::creerRoles($autre);
        $villeB = Ville::create(['entreprise_id' => $autre->id, 'code' => 'SP', 'nom' => 'San-Pédro', 'est_actif' => true]);
        $pieceB = FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $autre->id, 'ville_id' => $villeB->id, 'lot_import_id' => $this->lot($autre, $villeB)->id,
            'fournisseur' => 'Ets Beta', 'numero_piece' => 'B-1', 'date_facture' => '2026-03-11',
            'montant' => 5_000, 'montant_regle' => 0, 'reste_a_payer' => 5_000,
        ]);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')->set('exercice', '2026')
            ->set('aSupprimer', $pieceB->id)
            ->call('confirmerLaSuppression')
            ->assertNotFound();

        $this->assertNotNull(FactureFournisseur::withoutGlobalScopes()->find($pieceB->id));
    }

    public function test_la_page_de_la_piece_supprime_aussi_une_piece_importee(): void
    {
        $piece = $this->piece(['numero_piece' => 'BL-9']);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->call('demanderLaSuppression')
            ->assertSee('reviendra au prochain dépôt')
            ->call('confirmerLaSuppression')
            ->assertRedirect(route('fournisseurs'));

        $this->assertNull(FactureFournisseur::withoutGlobalScopes()->find($piece->id));
    }

    // ------------------------------------------------------------------------------ outils

    private function piece(array $valeurs = []): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create($valeurs + [
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'lot_import_id' => $this->lot->id,
            'fournisseur' => 'Ets Kouassi pièces', 'numero_piece' => 'BL-1', 'date_facture' => '2026-03-11',
            'montant' => 118_000, 'montant_regle' => 0, 'reste_a_payer' => 118_000,
        ]);
    }

    private function lot(Entreprise $entreprise, Ville $ville): LotImport
    {
        return LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id, 'ville_id' => $ville->id, 'format' => 'fournisseurs',
            'nom_fichier' => 'suivi.xlsx', 'deposant' => 'X', 'empreinte' => hash('sha256', 'suivi'.$entreprise->id), 'etat' => 'termine',
        ]);
    }

    private function operationDuSuivi(FactureFournisseur $piece): OperationComptable
    {
        return OperationComptable::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'numero_saisie' => 1, 'nature' => 'FactureAchat',
            'source' => 'suivi', 'source_id' => $piece->id, 'empreinte' => hash('sha256', 'x'),
            'date_operation' => '2026-03-11', 'code_journal' => 'AC', 'libelle' => 'Achat',
        ]);
    }

    private function compte(string $role, ?string $suffixe = null): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).($suffixe ? '-'.$suffixe : '').'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
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
            ],
        ]);
    }
}

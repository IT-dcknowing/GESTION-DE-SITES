<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La page des devis, revue le 12/10 : « Validés 0 », « En attente 3 171 », « Délai 0 h » à
 * côté d'un taux de 12 %. Un devis importé entre « En attente » avec pour réception la date
 * de la proforma ; la page lit désormais la facture qui le suit, et la fiche du parc.
 */
class LaPageDesDevisLitLesDevisImportesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_devis_importe_suivi_d_une_facture_est_retenu_et_sort_de_l_attente(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($entreprise);
        $ville = Ville::create(['entreprise_id' => $entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $site = Site::create(['entreprise_id' => $entreprise->id, 'ville_id' => $ville->id, 'code' => 'A1', 'nom' => 'Abidjan 1', 'est_actif' => true]);
        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id, 'format' => 'devis', 'nom_fichier' => 'devis.xlsx',
            'deposant' => 'X', 'empreinte' => hash('sha256', 'devis'), 'etat' => 'termine',
        ]);

        $emission = now()->startOfMonth()->addDays(5)->setTime(10, 0);
        $devis = fn (string $numero, ?string $fiche) => Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id, 'site_id' => $site->id, 'lot_import_id' => $lot->id,
            'numero' => $numero, 'n_fiche_reception' => $fiche, 'client' => 'NSIA', 'activite' => 'Mécanique',
            'statut' => 'En attente', 'montant_devis' => 1_000_000,
            // Ce que l'import pose : la date de la proforma, aux deux places.
            'date_emission' => $emission, 'date_reception' => $emission,
        ]);
        $devis('PR-1', 'FR-1');
        $devis('PR-2', 'FR-2');

        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id, 'site_id' => $site->id, 'date' => $emission->copy()->addDays(3),
            'n_facture' => 'FA -1', 'client' => 'NSIA', 'activite' => 'Mécanique', 'montant' => 900_000,
            'reference_devis' => 'FR-1',
        ]);
        // La fiche du parc dit quand le véhicule est arrivé : 48 heures avant la proforma.
        DB::table('dossiers_vehicules')->insert([
            'entreprise_id' => $entreprise->id, 'site_id' => $site->id, 'numero_fiche' => 'FR-1',
            'date_fiche' => $emission->copy()->subHours(48), 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($entreprise->id);
        $gerant = User::create(['entreprise_id' => $entreprise->id, 'name' => 'G', 'email' => 'g@alpha.test',
            'password' => Hash::make('x'), 'ville_id' => $ville->id, 'site_id' => $site->id, 'est_actif' => true]);
        $gerant->assignRole('gerant');

        $kpis = Volt::actingAs($gerant->fresh())->test('pilotage.devis')->get('kpis');

        $this->assertSame(1, $kpis['valides']);
        $this->assertSame(900_000, $kpis['montantValide']);
        $this->assertSame(1, $kpis['attente'], 'Le devis facturé n\'est plus en attente.');
        $this->assertEquals(100_000, $kpis['differenciation']);
        $this->assertSame(48, $kpis['delaiEnvoi'], 'La réception vient de la fiche ; le devis sans fiche connue ne compte pas.');
    }
}

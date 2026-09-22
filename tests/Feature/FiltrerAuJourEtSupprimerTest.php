<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\SuppressionDUneCreance;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le jour 2 du plan : voir une journée précise, et effacer ce qui n'aurait pas dû exister.
 *
 * Trois demandes s'y rejoignent, et chacune se vérifie ici.
 *
 * **Le filtre « du … au … » descend au jour.** Il ne connaissait que le mois : on ne pouvait
 * pas demander « du 3 au 17 mars », alors que c'est exactement l'intervalle qu'on cherche pour
 * rapprocher une caisse. Les anciennes valeurs écrites au mois doivent continuer d'être lues,
 * sans quoi tout lien mis en favori casserait du jour au lendemain.
 *
 * **La suppression est réservée au gérant**, et refusée sur ce qui est réglé ou importé.
 *
 * **L'extrait de compte et les fournisseurs disent ce qu'ils savent** : trois colonnes de plus
 * sur l'un, le déjà payé sur l'autre, à l'écran comme dans le fichier emporté.
 */
class FiltrerAuJourEtSupprimerTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Le filtre au jour
    |--------------------------------------------------------------------------
    */

    public function test_les_bornes_se_lisent_au_jour_pres(): void
    {
        [$debut, $fin] = PeriodeCalculateur::plage('periode', '2026-03-03', '2026-03-17');

        $this->assertSame('2026-03-03', $debut->toDateString());
        $this->assertSame('2026-03-17', $fin->toDateString());
    }

    public function test_les_anciennes_bornes_ecrites_au_mois_restent_lues(): void
    {
        // Ce sont celles des liens mis en favori avant la mise à jour : les refuser
        // casserait ces liens sans que personne comprenne pourquoi.
        [$debut, $fin] = PeriodeCalculateur::plage('periode', '2026-03', '2026-03');

        $this->assertSame('2026-03-01', $debut->toDateString());
        $this->assertSame('2026-03-31', $fin->toDateString());
    }

    public function test_un_intervalle_inverse_se_ramene_au_jour_de_depart(): void
    {
        [$debut, $fin] = PeriodeCalculateur::plage('periode', '2026-03-17', '2026-03-03');

        // Et non au mois entier : élargir au mois ferait revenir des écritures que les
        // dates saisies excluaient.
        $this->assertSame('2026-03-17', $debut->toDateString());
        $this->assertSame('2026-03-17', $fin->toDateString());
    }

    public function test_une_borne_forgee_ne_fait_pas_tomber_la_page(): void
    {
        [$debut, $fin] = PeriodeCalculateur::plage('periode', 'du-3-au-17', '2026-99-99');

        // On retombe sur les bornes par défaut, comme si rien n'avait été demandé.
        $this->assertSame(now()->startOfYear()->toDateString(), $debut->toDateString());
        $this->assertTrue($fin->greaterThanOrEqualTo($debut));
    }

    public function test_un_ecran_filtre_reellement_sur_la_journee_demandee(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $dans = $this->creance('F-DANS', 111_111);
        $dans->update(['date' => now()->startOfMonth()->addDays(4)]);

        $hors = $this->creance('F-HORS', 222_222);
        $hors->update(['date' => now()->startOfMonth()->addDays(20)]);

        $du = now()->startOfMonth()->addDays(3)->toDateString();
        $au = now()->startOfMonth()->addDays(5)->toDateString();

        Volt::actingAs($gerant)->test('pilotage.chiffre-affaires')
            ->set('periode', 'periode')
            ->set('dateDebut', $du)
            ->set('dateFin', $au)
            ->assertSee('F-DANS')
            ->assertDontSee('F-HORS');
    }

    /*
    |--------------------------------------------------------------------------
    | La suppression d'une créance
    |--------------------------------------------------------------------------
    */

    public function test_seul_le_gerant_peut_supprimer_une_creance(): void
    {
        $creance = $this->creance('F-40', 100_000);

        $this->assertNull(SuppressionDUneCreance::refus($this->compte('gerant'), $creance));

        foreach (['responsable_ville', 'responsable_site'] as $role) {
            $refus = SuppressionDUneCreance::refus($this->compte($role), $creance);

            $this->assertNotNull($refus, "Le rôle {$role} ne doit pas pouvoir supprimer.");
            $this->assertStringContainsString('gérant', $refus);
        }
    }

    public function test_une_creance_deja_reglee_ne_se_supprime_pas(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $creance = $this->creance('F-41', 100_000);

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $creance->id,
            'date' => now()->subDay(),
            'montant' => 40_000,
            'type' => 'Client',
            'moyen' => 'Espèces',
            'client' => $creance->tiersPayant(),
            'activite' => $creance->activite,
        ]);

        // Un encaissement sans facture en face, c'est de l'argent reçu qu'on ne sait plus
        // rattacher : c'est exactement ce que le verrou empêche.
        $refus = SuppressionDUneCreance::refus($gerant, $creance->fresh());

        $this->assertNotNull($refus);
        $this->assertStringContainsString('règlement', $refus);
    }

    public function test_une_creance_importee_ne_se_supprime_pas(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $lot = LotImport::create([
            'entreprise_id' => $this->entreprise->id,
            'format' => 'impayes',
            'nom_fichier' => 'impayes.xlsx',
            'empreinte' => 'test-'.uniqid(),
            'user_id' => $gerant->id,
            'deposant' => $gerant->name,
        ]);

        $creance = $this->creance('F-42', 100_000);
        $creance->update(['lot_import_id' => $lot->id]);

        $refus = SuppressionDUneCreance::refus($gerant, $creance->fresh());

        $this->assertNotNull($refus);
        $this->assertStringContainsString('import', $refus);
    }

    public function test_la_suppression_efface_la_ligne_et_garde_sa_trace(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $creance = $this->creance('F-43', 340_000);
        $reference = $creance->numero;

        Volt::actingAs($gerant)->test('pilotage.impayes')
            ->call('demanderLaSuppression', $creance->id)
            ->call('confirmerLaSuppression');

        $this->assertNull(Facture::withoutGlobalScopes()->find($creance->id));

        $trace = Activity::where('description', 'État des impayés — créance supprimée')->first();

        $this->assertNotNull($trace, 'La suppression doit laisser une trace au journal.');
        $this->assertSame($reference, $trace->properties['reference']);
        $this->assertSame(340_000, $trace->properties['montant']);
        // La ligne entière est conservée : on doit pouvoir la retaper à l'identique.
        $this->assertSame('F-43', $trace->properties['ligne_effacee']['n_facture']);
    }

    public function test_l_action_de_suppression_reste_fermee_a_qui_n_est_pas_gerant(): void
    {
        $responsable = $this->compte('responsable_ville');
        $this->actingAs($responsable);

        $creance = $this->creance('F-44', 100_000);

        Volt::actingAs($responsable)->test('pilotage.impayes')
            ->call('demanderLaSuppression', $creance->id)
            ->call('confirmerLaSuppression');

        // Le bouton n'apparaît pas pour lui ; l'action, elle, reste appelable — et refuse.
        $this->assertNotNull(Facture::withoutGlobalScopes()->find($creance->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Ce que les états disent de plus
    |--------------------------------------------------------------------------
    */

    public function test_l_extrait_de_compte_montre_le_sinistre_et_les_deux_dates(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $creance = $this->creance('F-50', 500_000);
        $creance->update(['n_sinistre' => 'SIN-2026-118']);

        Volt::actingAs($gerant)->test('recouvrement.extrait', ['tiers' => $creance->client])
            ->set('tiers', $creance->client)
            ->assertSee('Date de facturation')
            ->assertSee('Date de dépôt')
            ->assertSee('N° sinistre')
            ->assertSee('SIN-2026-118');
    }

    public function test_les_fournisseurs_montrent_le_deja_paye_et_s_emportent(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        FactureFournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'fournisseur' => 'SOCIETE PIECES AUTO',
            'numero_piece' => 'FA-9001',
            'date_facture' => now()->subDays(40),
            'montant' => 1_000_000,
            'montant_regle' => 400_000,
            'reste_a_payer' => 600_000,
        ]);

        Volt::actingAs($gerant)->test('pilotage.fournisseurs')
            ->assertSee('Déjà payé')
            ->assertSee('SOCIETE PIECES AUTO');

        // Le fichier emporté : il n'existait pas du tout sur cet écran.
        $reponse = $this->get(route('fournisseurs.telecharger', [
            'format' => 'excel', 'ville' => '', 'etat' => 'ouvertes', 'recherche' => '',
        ]));

        $reponse->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $reponse->headers->get('Content-Type'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    private function creance(string $numero, int $montant): Facture
    {
        $date = now()->subDays(20);

        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => 'IMP-'.substr(md5($numero), 0, 10),
            'n_facture' => $numero,
            'date' => $date,
            'date_reception' => now()->subDays(10),
            'exercice_impayes' => (int) $date->format('Y'),
            'client' => 'Client '.$numero,
            'montant' => $montant,
            'activite' => 'Sinistre',
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

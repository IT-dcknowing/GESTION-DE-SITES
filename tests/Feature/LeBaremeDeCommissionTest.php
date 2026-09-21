<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\BaremeCommission;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\CommissionCommerciale;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le chantier 10 : le barème de commission.
 *
 * **La source et ce qu'elle tranche.** `Commission_Commerciaux_Artisan (1)_vf6.pdf` se
 * termine par « Commission appliquée sur le chiffre d'affaires **global** mensuel HT », et
 * sa propre colonne de commissions indicatives le confirme par l'arithmétique : 1 % sur la
 * tranche 20–25 M y vaut 200 000 F, soit 1 % de 20 M entiers. Le taux porte donc sur tout
 * le chiffre d'affaires du mois, et non sur la part au-dessus d'un seuil. Ce test le
 * vérifie valeur par valeur, sur les deux grilles.
 *
 * **Ce que le document laisse ouvert** — seuil d'entrée, trous entre tranches, bornes qui se
 * chevauchent — n'est pas tranché dans le code : la grille est une donnée que le gérant
 * modifie. Elle est seulement *proposée*, et jamais posée d'office.
 *
 * **La date d'effet** est la seule chose qui protège le passé : un barème posé en décembre
 * ne doit pas changer la commission d'octobre.
 */
class LeBaremeDeCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    private ?User $vendeur = null;

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
    | Ce que le document dit, vérifié chiffre par chiffre
    |--------------------------------------------------------------------------
    */

    public function test_la_grille_des_commerciaux_redonne_les_commissions_imprimees_dans_le_document(): void
    {
        $bareme = $this->grilleDuDocument('commercial');

        // Colonne « Commission estimée » du PDF, borne basse de chaque tranche. Si l'un de
        // ces couples cesse d'être vrai, c'est que l'assiette a changé de sens.
        $attendu = [
            20_000_000 => 200_000,     // 1 % de 20 M
            25_000_000 => 375_000,     // 1,5 % de 25 M
            30_000_000 => 750_000,     // 2,5 % de 30 M
            40_000_000 => 1_400_000,   // 3,5 % de 40 M
            50_000_000 => 2_000_000,   // 4 % de 50 M
            60_000_000 => 3_000_000,   // 5 % de 60 M
        ];

        foreach ($attendu as $ca => $commission) {
            $this->assertSame($commission, CommissionCommerciale::commission($bareme, $ca),
                'Commission attendue sur '.number_format($ca, 0, ',', ' ').' F.');
        }

        // Ce qui distingue les deux façons de compter : à 20 M pile, l'assiette « globale »
        // donne 200 000 F — le chiffre imprimé — quand l'assiette « par tranche » donnerait
        // zéro, puisque rien ne dépasse le plancher. Le document imprime 200 000.
        $this->assertSame(200_000, CommissionCommerciale::commission($bareme, 20_000_000));
    }

    public function test_la_grille_du_responsable_redonne_elle_aussi_ses_montants(): void
    {
        $bareme = $this->grilleDuDocument('responsable');

        $attendu = [
            30_000_000 => 450_000,     // 1,5 % de 30 M
            40_000_000 => 1_000_000,   // 2,5 % de 40 M
            50_000_000 => 1_750_000,   // 3,5 % de 50 M
            60_000_000 => 2_400_000,   // 4 % de 60 M
            80_000_000 => 4_000_000,   // 5 % de 80 M
        ];

        foreach ($attendu as $ca => $commission) {
            $this->assertSame($commission, CommissionCommerciale::commission($bareme, $ca));
        }

        // Sous le premier palier, rien — et c'est bien zéro, pas « hors tranche ».
        $this->assertSame(0, CommissionCommerciale::commission($bareme, 12_000_000));
    }

    public function test_le_plancher_est_atteint_et_le_plafond_exclu(): void
    {
        $bareme = $this->grilleDuDocument('commercial');

        // 40 M appartient à une seule tranche : celle qui commence à 40 M, pas celle qui
        // s'y arrête. C'est la convention qui départage les bornes que le document fait
        // appartenir à deux tranches à la fois.
        $this->assertSame(3.5, CommissionCommerciale::taux($bareme, 40_000_000));
        $this->assertSame(2.5, CommissionCommerciale::taux($bareme, 39_999_999));
    }

    public function test_l_assiette_par_tranche_reste_possible_et_donne_un_autre_chiffre(): void
    {
        $bareme = $this->grilleDuDocument('commercial');
        $bareme->update(['assiette' => 'tranche']);
        $bareme->refresh()->load('tranches');

        // 2,5 % de la part au-dessus de 30 M, soit 2,5 % de 2 M.
        $this->assertSame(50_000, CommissionCommerciale::commission($bareme, 32_000_000));
    }

    /*
    |--------------------------------------------------------------------------
    | Une grille trouée le dit, elle ne se tait pas
    |--------------------------------------------------------------------------
    */

    public function test_un_chiffre_d_affaires_hors_tranche_rend_null_et_non_zero(): void
    {
        $bareme = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id, 'cible' => 'commercial',
            'libelle' => 'Grille trouée', 'date_effet' => '2026-01-01', 'assiette' => 'global',
        ]);
        $bareme->tranches()->create(['plancher' => 0, 'plafond' => 20_000_000, 'taux' => 0]);
        $bareme->tranches()->create(['plancher' => 26_000_000, 'plafond' => null, 'taux' => 2]);
        $bareme->load('tranches');

        // Entre 20 et 26 M, aucune règle ne dit quoi faire. Répondre « 0 F » ferait croire
        // à une décision ; répondre null laisse l'écran dire qu'il n'en a pas.
        $this->assertNull(CommissionCommerciale::taux($bareme, 23_000_000));
        $this->assertNull(CommissionCommerciale::commission($bareme, 23_000_000));

        $anomalies = CommissionCommerciale::anomalies($bareme);
        $this->assertNotEmpty($anomalies);
        $this->assertStringContainsString('20 M', implode(' ', $anomalies));
        $this->assertStringContainsString('26 M', implode(' ', $anomalies));
    }

    public function test_une_grille_qui_se_chevauche_est_signalee(): void
    {
        $bareme = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id, 'cible' => 'commercial',
            'libelle' => 'Grille qui se chevauche', 'date_effet' => '2026-01-01',
        ]);
        $bareme->tranches()->create(['plancher' => 0, 'plafond' => 50_000_000, 'taux' => 1]);
        $bareme->tranches()->create(['plancher' => 40_000_000, 'plafond' => null, 'taux' => 2]);
        $bareme->load('tranches');

        $this->assertStringContainsString('chevauchent', implode(' ', CommissionCommerciale::anomalies($bareme)));
    }

    public function test_une_grille_sans_tranche_le_dit(): void
    {
        $bareme = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id, 'cible' => 'commercial',
            'libelle' => 'Grille vide', 'date_effet' => '2026-01-01',
        ]);

        $this->assertStringContainsString('aucune tranche', implode(' ', CommissionCommerciale::anomalies($bareme->load('tranches'))));
    }

    /*
    |--------------------------------------------------------------------------
    | La date d'effet protège le passé
    |--------------------------------------------------------------------------
    */

    public function test_un_bareme_pose_en_decembre_ne_change_pas_la_commission_d_octobre(): void
    {
        $ancienne = $this->grille('commercial', '2026-01-01', [[0, null, 2.0]]);
        $nouvelle = $this->grille('commercial', '2026-12-01', [[0, null, 5.0]]);

        $enOctobre = CommissionCommerciale::grilleDeLaCible($this->entreprise->id, 'commercial', Carbon::parse('2026-10-31'));
        $enDecembre = CommissionCommerciale::grilleDeLaCible($this->entreprise->id, 'commercial', Carbon::parse('2026-12-31'));

        $this->assertSame($ancienne->id, $enOctobre->id);
        $this->assertSame($nouvelle->id, $enDecembre->id);
    }

    public function test_la_commission_se_calcule_mois_par_mois_et_non_sur_la_periode_entiere(): void
    {
        $this->grilleDuDocument('commercial');

        // Trois mois à 15 M : aucun n'atteint le seuil, la commission est nulle. Additionner
        // d'abord les trois donnerait 45 M, donc 2,5 % de 45 M — 1 125 000 F qui n'ont
        // jamais été gagnés. C'est l'erreur que ce test interdit.
        $resultat = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), [
            '2026-01' => 15_000_000,
            '2026-02' => 15_000_000,
            '2026-03' => 15_000_000,
        ]);

        $this->assertSame(0, $resultat['commission']);

        // Et un mois qui atteint le palier compte pour lui seul.
        $resultat = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), [
            '2026-01' => 15_000_000,
            '2026-02' => 30_000_000,
        ]);

        $this->assertSame(750_000, $resultat['commission']);
    }

    public function test_un_mois_sans_grille_est_compte_a_part(): void
    {
        $this->grille('commercial', '2026-06-01', [[0, null, 2.0]]);

        $resultat = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), [
            '2026-03' => 30_000_000,
            '2026-07' => 30_000_000,
        ]);

        // Mars n'a pas de grille : il n'est ni compté zéro, ni compté au taux de juillet.
        $this->assertSame(1, $resultat['sansGrille']);
        $this->assertNull($resultat['mois']['2026-03']['commission']);
        $this->assertSame(600_000, $resultat['commission']);
    }

    /*
    |--------------------------------------------------------------------------
    | L'écran
    |--------------------------------------------------------------------------
    */

    public function test_le_gerant_pose_la_grille_du_document_d_un_clic_et_pas_deux_fois(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        // Rien n'existe tant que personne n'a cliqué : aucune migration, aucune commande
        // n'écrit de barème.
        $this->assertSame(0, BaremeCommission::count());

        $ecran = Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('dateEffet', '2026-09-01')
            ->call('poserLaGrilleDuDocument');

        $this->assertSame(1, BaremeCommission::count());
        $this->assertSame(7, BaremeCommission::first()->tranches()->count());
        $this->assertSame('global', BaremeCommission::first()->assiette);

        // Deux grilles qui prennent effet le même jour rendraient indécidable celle qui
        // s'applique : la seconde est refusée, et le refus se dit.
        $ecran->call('poserLaGrilleDuDocument')->assertSee('prend déjà effet');
        $this->assertSame(1, BaremeCommission::count());
    }

    public function test_l_ecran_essaie_un_chiffre_d_affaires_sans_rien_enregistrer(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $bareme = $this->grilleDuDocument('commercial');

        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->call('ouvrir', $bareme->id)
            ->set('simulation', '32000000')
            // 2,5 % de 32 M = 800 000.
            ->assertSee('800 000');
    }

    public function test_le_bareme_est_ferme_a_qui_n_est_pas_gerant(): void
    {
        foreach (['responsable_ville', 'responsable_site', 'commercial', 'caissier'] as $role) {
            $this->actingAs($this->compte($role));
            $this->get(route('bareme-commission'))->assertRedirect();
        }
    }

    public function test_le_classement_montre_la_commission_au_gerant_et_la_cache_aux_autres(): void
    {
        $gerant = $this->compte('gerant');
        $this->grilleDuDocument('commercial');

        $commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'numero' => 'C-001', 'nom' => 'Koffi Yao', 'objectif_mensuel' => 20_000_000,
        ]);

        // 32 M facturés dans le mois : 2,5 %, soit 800 000 F.
        $this->facture($commercial, Carbon::today()->startOfMonth(), 32_000_000);

        $this->actingAs($gerant);
        Volt::actingAs($gerant)->test('pilotage.commerciaux')
            ->set('periode', 'periode')
            ->set('dateDebut', Carbon::today()->startOfMonth()->toDateString())
            ->set('dateFin', Carbon::today()->endOfMonth()->toDateString())
            ->assertSee('800 000')
            ->assertSee('Commission de la période');

        $responsable = $this->compte('responsable_ville');
        $this->actingAs($responsable);
        Volt::actingAs($responsable)->test('pilotage.commerciaux')
            ->set('periode', 'periode')
            ->set('dateDebut', Carbon::today()->startOfMonth()->toDateString())
            ->set('dateFin', Carbon::today()->endOfMonth()->toDateString())
            ->assertDontSee('Commission de la période');
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    private function grilleDuDocument(string $cible): BaremeCommission
    {
        return $this->grille($cible, '2026-01-01', CommissionCommerciale::GRILLE_DU_DOCUMENT[$cible]['tranches']);
    }

    /** @param  list<array{0: int, 1: int|null, 2: float}>  $tranches */
    private function grille(string $cible, string $dateEffet, array $tranches): BaremeCommission
    {
        $bareme = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id,
            'cible' => $cible,
            'libelle' => 'Grille '.$cible.' '.$dateEffet,
            'date_effet' => $dateEffet,
            'assiette' => 'global',
        ]);

        foreach ($tranches as $rang => [$plancher, $plafond, $taux]) {
            $bareme->tranches()->create([
                'plancher' => $plancher, 'plafond' => $plafond, 'taux' => $taux, 'ordre' => $rang,
            ]);
        }

        return $bareme->load('tranches');
    }

    private function facture(Commercial $commercial, Carbon $date, int $montant): Facture
    {
        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $commercial->id,
            'numero' => 'FAC-'.substr(md5($date->toDateString().$montant), 0, 8),
            'n_facture' => 'F-'.$date->format('md'),
            'date' => $date,
            'client' => 'Client',
            'montant' => $montant,
            'activite' => 'Mécanique',
        ]);
    }

    /** Un compte qui vend, mémorisé : la grille se choisit sur son rôle. */
    private function vendeur(): User
    {
        return $this->vendeur ??= $this->compte('commercial');
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

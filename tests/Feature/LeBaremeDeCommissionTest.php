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

    public function test_les_deux_grilles_ont_deux_seuils_distincts(): void
    {
        $commercial = $this->grilleDuDocument('commercial');
        $responsable = $this->grilleDuDocument('responsable');

        /*
         * On avait lu la phrase « aucune commission sous 25 millions » comme une
         * contradiction de la grille des commerciaux, qui commence à 20. Elle ne la visait
         * pas : ce sont deux postes, et deux seuils. Le propriétaire l'a tranché le 24/09,
         * et ce test empêche de les remêler.
         */
        $this->assertSame(200_000, CommissionCommerciale::commission($commercial, 20_000_000));
        $this->assertSame(0, CommissionCommerciale::commission($responsable, 20_000_000));
        $this->assertSame(0, CommissionCommerciale::commission($responsable, 24_999_999));
    }

    public function test_le_trou_du_responsable_entre_25_et_30_millions_est_comble(): void
    {
        $bareme = $this->grilleDuDocument('responsable');

        // Le document annonce une commission dès 25 M et ne disait rien avant 30 : un
        // responsable à 27 M ne touchait rien tout en ayant dépassé le seuil écrit au-dessus
        // de sa propre grille. 1 % y est posé depuis le 24/09.
        $this->assertSame(250_000, CommissionCommerciale::commission($bareme, 25_000_000));
        $this->assertSame(270_000, CommissionCommerciale::commission($bareme, 27_000_000));

        // Et la tranche suivante reprend à 30 M, sans recouvrement.
        $this->assertSame(450_000, CommissionCommerciale::commission($bareme, 30_000_000));
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
    | Un barème court jusqu'à ce qu'un autre le remplace
    |--------------------------------------------------------------------------
    | **Tranché par le propriétaire le 24/09, et cela revient sur le 22/09.** On avait
    | cloisonné le barème par exercice : une grille valait pour son année, et la corriger
    | recalculait l'année entière. Deux conséquences que l'usage a montrées — il fallait
    | reposer une grille chaque 1er janvier, et corriger en novembre recalculait les dix
    | mois déjà annoncés aux commerciaux.
    |
    | La règle est donc : la grille qui répond pour un mois est la dernière posée avant ce
    | mois-là. Elle vaut pour les années suivantes sans action humaine, et une modification
    | ne vaut que pour la suite.
    */

    public function test_une_grille_vaut_pour_les_annees_suivantes_sans_qu_on_la_repose(): void
    {
        $this->grille('commercial', 2026, [[0, null, 2.0]], '2026-01-01');

        // Personne n'a rien fait au 1er janvier 2027, et c'est le but : une grille oubliée
        // ne doit pas faire tomber toutes les commissions à zéro sans prévenir.
        $resultat = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), [
            '2027-03' => 10_000_000,
        ]);

        $this->assertSame(200_000, $resultat['commission']);
    }

    public function test_une_grille_corrigee_ne_touche_pas_aux_mois_deja_arretes(): void
    {
        $ancienne = $this->grille('commercial', 2026, [[0, null, 2.0]], '2026-01-01');

        // Janvier, arrêté sous la grille à 2 %.
        $avant = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), ['2026-01' => 10_000_000]);
        $this->assertSame(200_000, $avant['commission']);

        // Le gérant pose une nouvelle grille au 1er novembre.
        $this->grille('commercial', 2026, [[0, null, 3.0]], '2026-11-01');

        // Janvier ne bouge pas : une rémunération annoncée ne se recalcule pas dix mois
        // plus tard. C'est ce que la règle du 22/09 faisait, et c'est ce qu'on corrige.
        $apres = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), ['2026-01' => 10_000_000]);
        $this->assertSame(200_000, $apres['commission']);

        // Novembre, lui, suit la nouvelle.
        $novembre = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), ['2026-11' => 10_000_000]);
        $this->assertSame(300_000, $novembre['commission']);

        // Et les deux grilles coexistent : remplacer n'est pas écraser.
        $this->assertSame(2, BaremeCommission::where('cible', 'commercial')->count());
        $this->assertNotNull($ancienne->fresh());
    }

    public function test_la_grille_en_vigueur_est_la_derniere_posee_avant_la_date(): void
    {
        $ancienne = $this->grille('commercial', 2025, [[0, null, 2.0]], '2025-01-01');
        $nouvelle = $this->grille('commercial', 2026, [[0, null, 5.0]], '2026-07-01');

        $this->assertSame($ancienne->id, CommissionCommerciale::grilleDeLaCible(
            $this->entreprise->id, 'commercial', Carbon::parse('2026-06-30'),
        )->id);

        $this->assertSame($nouvelle->id, CommissionCommerciale::grilleDeLaCible(
            $this->entreprise->id, 'commercial', Carbon::parse('2026-07-01'),
        )->id);
    }

    public function test_aucune_grille_ne_repond_avant_la_premiere(): void
    {
        $this->grille('commercial', 2026, [[0, null, 2.0]], '2026-01-01');

        // Un mois antérieur à toute grille n'est pas commissionné à zéro : aucune grille ne
        // le couvre, et l'écran doit pouvoir dire « on ne sait pas » plutôt que « rien ».
        $this->assertNull(CommissionCommerciale::grilleDeLaCible(
            $this->entreprise->id, 'commercial', Carbon::parse('2025-12-31'),
        ));
    }

    public function test_enregistrer_deux_fois_le_meme_jour_corrige_la_meme_version(): void
    {
        $gerant = $this->compte('gerant');

        CommissionCommerciale::enregistrer($gerant, 'commercial', 2026, [['plancher' => 0, 'plafond' => null, 'taux' => 2.0]]);
        CommissionCommerciale::enregistrer($gerant, 'commercial', 2026, [['plancher' => 0, 'plafond' => null, 'taux' => 3.0]]);

        // On se reprend en saisissant, et cela ne fait pas deux décisions.
        $this->assertSame(1, BaremeCommission::where('cible', 'commercial')->count());
        $this->assertSame(3.0, (float) BaremeCommission::where('cible', 'commercial')->firstOrFail()
            ->tranches()->value('taux'));
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
        $this->grille('commercial', 2026, [[0, null, 2.0]]);

        $resultat = CommissionCommerciale::surLesMois($this->entreprise->id, $this->vendeur(), [
            // 2025 n'a pas de grille : ce mois-là n'est ni compté zéro, ni compté au taux
            // de 2026. Chaque exercice répond pour lui-même, ou ne répond pas.
            '2025-11' => 30_000_000,
            '2026-07' => 30_000_000,
        ]);

        $this->assertSame(1, $resultat['sansGrille']);
        $this->assertNull($resultat['mois']['2025-11']['commission']);
        $this->assertSame(600_000, $resultat['commission']);
    }

    /*
    |--------------------------------------------------------------------------
    | L'écran
    |--------------------------------------------------------------------------
    */

    public function test_l_ecran_montre_les_deux_grilles_meme_quand_rien_n_est_enregistre(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        // Rien n'existe tant que personne n'a cliqué : aucune migration, aucune commande
        // n'écrit de barème.
        $this->assertSame(0, BaremeCommission::count());

        /*
         * L'écran ne doit pourtant jamais être vide. Les deux grilles sont dans le
         * document, et le propriétaire veut les voir — à défaut d'enregistrement, on montre
         * la grille de référence, en le disant.
         */
        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->assertSee('Commerciaux')
            ->assertSee('Responsable commercial et adjoint')
            ->assertSee('Grille de référence du document')
            // La commission estimée du document, recalculée : 1 % de 20 M à 1 % de 25 M.
            ->assertSee('200 000 – 250 000 FCFA');

        $this->assertSame(0, BaremeCommission::count());
    }

    public function test_le_gerant_enregistre_une_grille_depuis_l_ecran(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', 2026)
            ->call('enregistrerLaGrille', 'commercial')
            ->assertSee('enregistrée');

        $bareme = BaremeCommission::where('cible', 'commercial')->first();

        $this->assertNotNull($bareme);
        $this->assertSame(2026, $bareme->exercice);
        $this->assertSame(7, $bareme->tranches()->count());

        // Enregistrer deux fois ne pose pas deux grilles : c'est la même qu'on remplace.
        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', 2026)
            ->call('enregistrerLaGrille', 'commercial');

        $this->assertSame(1, BaremeCommission::where('cible', 'commercial')->count());
        $this->assertSame(7, BaremeCommission::where('cible', 'commercial')->first()->tranches()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La première activation, et la correction — deux gestes, deux dates
    |--------------------------------------------------------------------------
    | Arrêté par le propriétaire le 24/09. Un barème n'est pas une décision du jour : c'est
    | la règle sur laquelle on se base depuis le début de l'année, et les fichiers qu'on
    | importe couvrent l'année entière. Le poser à la date du jour laisserait les mois déjà
    | importés sans commission — alors que le barème existait avant qu'on le saisisse ici.
    |
    | Une correction, elle, ne doit surtout pas recalculer les mois déjà annoncés.
    */

    public function test_la_premiere_activation_fait_courir_la_grille_depuis_le_1er_janvier(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', 2026)
            ->call('enregistrerLaGrille', 'commercial', true)
            ->assertSee('activée au 1er janvier 2026');

        $bareme = BaremeCommission::where('cible', 'commercial')->firstOrFail();

        $this->assertSame('2026-01-01', $bareme->date_effet->toDateString());

        // Le point qui compte : un import de février trouve une grille, alors que la
        // saisie a eu lieu en septembre.
        $this->assertNotNull(CommissionCommerciale::grilleDeLaCible(
            $this->entreprise->id, 'commercial', Carbon::create(2026, 2, 15),
        ));
    }

    public function test_enregistrer_sans_activer_ne_vaut_qu_a_partir_d_aujourd_hui(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', 2026)
            ->call('enregistrerLaGrille', 'commercial')
            ->assertSee('effet au '.now()->format('d/m/Y'));

        $bareme = BaremeCommission::where('cible', 'commercial')->firstOrFail();

        $this->assertSame(now()->toDateString(), $bareme->date_effet->toDateString());
    }

    public function test_le_bouton_de_premiere_activation_disparait_une_fois_l_annee_couverte(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $ecran = Volt::actingAs($gerant)->test('gerant.bareme-commission')->set('exercice', 2026);

        // Tant que rien ne couvre le 1er janvier, l'écran propose le geste.
        $ecran->assertSee('Première activation');

        $ecran->call('enregistrerLaGrille', 'commercial', true)
            ->call('enregistrerLaGrille', 'responsable', true);

        // Une fois les deux grilles posées, le bouton n'a plus rien à faire — et un bouton
        // qui ne fait rien se clique quand même.
        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', 2026)
            ->assertDontSee('Première activation');
    }

    public function test_une_correction_ne_recalcule_pas_les_mois_deja_arretes(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        // Activée au 1er janvier, puis corrigée aujourd'hui : deux grilles coexistent.
        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', now()->year)
            ->call('enregistrerLaGrille', 'commercial', true)
            ->call('enregistrerLaGrille', 'commercial');

        $this->assertSame(2, BaremeCommission::where('cible', 'commercial')->count());

        // Février lit celle du 1er janvier, aujourd'hui lit la corrigée. C'est toute la
        // raison d'avoir deux boutons plutôt qu'un.
        $enFevrier = CommissionCommerciale::grilleDeLaCible(
            $this->entreprise->id, 'commercial', Carbon::create(now()->year, 2, 15),
        );
        $aujourdhui = CommissionCommerciale::grilleDeLaCible($this->entreprise->id, 'commercial');

        $this->assertNotNull($enFevrier);
        $this->assertNotNull($aujourdhui);
        $this->assertNotSame($enFevrier->id, $aujourdhui->id);
    }

    public function test_une_grille_posee_en_cours_d_annee_le_dit_a_l_ecran(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', now()->year)
            ->call('enregistrerLaGrille', 'commercial');

        // Le cas qui se voyait le moins et qui coûtait le plus : une grille existe, mais le
        // début de l'année ne commissionne rien, et rien ne le disait.
        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', now()->year)
            ->assertSee('ne commissionne rien');
    }

    public function test_une_tranche_ajoutee_sous_le_tableau_n_est_ecrite_qu_a_l_enregistrement(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $ecran = Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->set('exercice', 2026)
            ->call('ouvrirLAjout', 'commercial')
            ->set('nouveauPlancher', '70000000')
            ->set('nouveauPlafond', '')
            ->set('nouveauTaux', '6')
            ->call('validerLAjout');

        // Elle est au tableau, mais rien n'est en base : on valide un ajout, on enregistre
        // une grille, et ce sont deux gestes différents.
        $this->assertSame(0, BaremeCommission::count());

        $ecran->call('enregistrerLaGrille', 'commercial');

        $this->assertSame(8, BaremeCommission::where('cible', 'commercial')->first()->tranches()->count());
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
        return $this->grille($cible, 2026, CommissionCommerciale::GRILLE_DU_DOCUMENT[$cible]['tranches']);
    }

    /** @param  list<array{0: int, 1: int|null, 2: float}>  $tranches */
    private function grille(string $cible, int $exercice, array $tranches, ?string $dateEffet = null): BaremeCommission
    {
        $bareme = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id,
            'cible' => $cible,
            'exercice' => $exercice,
            'libelle' => 'Grille '.$cible.' '.$exercice,
            // La date d'effet redit tout depuis le 24/09 : une grille court jusqu'à ce
            // qu'une autre la remplace, et l'exercice ne cloisonne plus rien.
            'date_effet' => $dateEffet ?? $exercice.'-01-01',
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

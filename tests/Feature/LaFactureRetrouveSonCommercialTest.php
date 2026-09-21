<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\BaremeCommission;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\EcartDevisFacture;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Services\CommissionCommerciale;
use Modules\Noyau\Exploitation\Services\RapprochementDevisFacture;
use Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le second maillon, et ce qui le rendait nécessaire.
 *
 * **Le problème n'était pas le barème, c'était son assiette.** Mesuré le 21/09/2026 : sur
 * les 4 412 factures de 2026, **103 portent un commercial**. La colonne « Commission »
 * restait donc à zéro pour presque tout le monde — non parce que personne n'avait vendu,
 * mais parce que personne ne savait qui. Le barème était juste ; il n'avait rien à quoi
 * s'appliquer.
 *
 * **La chaîne, et ses deux maillons.**
 *
 *     prospection --(plaque + date)--> devis --(fiche, numéro ou plaque)--> facture
 *
 * Le premier donne le commercial, le second le porte jusqu'à la facture. Ce test suit la
 * chaîne entière, de la visite au montant de la commission.
 *
 * **Et rien n'est écrit en dur.** La grille dit elle-même quels rôles elle rémunère, et un
 * taux modifié agit à l'affichage suivant : aucun cache, aucun déploiement.
 */
class LaFactureRetrouveSonCommercialTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    private Commercial $commercial;

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

        $this->commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'numero' => 'C-001', 'nom' => 'Koffi Yao', 'objectif_mensuel' => 20_000_000,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Le second maillon
    |--------------------------------------------------------------------------
    */

    public function test_la_fiche_de_reception_relie_la_facture_a_son_devis(): void
    {
        $devis = $this->devis('2026-03-02', 'FR-AB 010136');

        // La colonne « reference_devis » des factures contient en réalité le numéro de
        // fiche de réception : c'est ce que les fichiers repris y ont mis.
        $facture = $this->facture('2026-03-20', 12_000_000, ['reference_devis' => 'FR-AB 010136']);

        $propositions = RapprochementDevisFacture::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('fiche', $propositions[0]['motif']);
        $this->assertSame($devis->id, $propositions[0]['devis']->id);
        $this->assertSame($facture->id, $propositions[0]['facture']->id);
        $this->assertSame(18, $propositions[0]['ecart']);
    }

    public function test_a_defaut_la_plaque_du_vehicule_les_relie(): void
    {
        $devis = $this->devis('2026-03-02', 'FR-AB 010136');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        // Aucune référence sur la facture : reste l'immatriculation, que le devis emprunte
        // à sa fiche de réception.
        $this->facture('2026-03-20', 12_000_000, ['immatriculation' => '1234-AB-01']);

        $propositions = RapprochementDevisFacture::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('plaque', $propositions[0]['motif']);
        $this->assertSame($devis->id, $propositions[0]['devis']->id);
    }

    public function test_une_facture_anterieure_a_son_devis_n_est_jamais_proposee(): void
    {
        $this->devis('2026-03-20', 'FR-AB 010136');
        $this->facture('2026-03-02', 12_000_000, ['reference_devis' => 'FR-AB 010136']);

        // On ne facture pas des travaux avant de les avoir chiffrés.
        $this->assertCount(0, RapprochementDevisFacture::propositions([$this->site->id]));
    }

    public function test_un_devis_ne_produit_qu_une_facture(): void
    {
        $gerant = $this->compte('gerant');
        $devis = $this->devis('2026-03-02', 'FR-AB 010136');

        $premiere = $this->facture('2026-03-20', 12_000_000, ['reference_devis' => 'FR-AB 010136']);
        $seconde = $this->facture('2026-03-22', 3_000_000, ['reference_devis' => 'FR-AB 010136', 'n_facture' => 'F-002']);

        // Deux factures citent la même fiche : une seule est proposée, car le devis retenu
        // par la première n'est plus disponible pour la seconde.
        $this->assertCount(1, RapprochementDevisFacture::propositions([$this->site->id]));

        RapprochementDevisFacture::confirmer($gerant, $premiere->id, $devis->id, [$this->site->id]);
        $refus = RapprochementDevisFacture::confirmer($gerant, $seconde->id, $devis->id, [$this->site->id]);

        $this->assertSame('Ce devis a déjà produit une facture.', $refus);
        $this->assertNull($seconde->fresh()->devis_id);
    }

    public function test_un_devis_sans_commercial_ne_se_rattache_pas(): void
    {
        $gerant = $this->compte('gerant');

        $devis = $this->devis('2026-03-02', 'FR-AB 010136');
        $devis->forceFill(['commercial_id' => null])->save();

        $facture = $this->facture('2026-03-20', 12_000_000, ['reference_devis' => 'FR-AB 010136']);

        // Rattacher ne rémunérerait personne : on le refuse et l'on dit par où commencer.
        $refus = RapprochementDevisFacture::confirmer($gerant, $facture->id, $devis->id, [$this->site->id]);

        $this->assertStringContainsString('rapprochez-le d\'abord de sa prospection', $refus);
        $this->assertNull($facture->fresh()->devis_id);
    }

    public function test_un_couple_ecarte_ne_revient_plus(): void
    {
        $gerant = $this->compte('gerant');
        $devis = $this->devis('2026-03-02', 'FR-AB 010136');
        $facture = $this->facture('2026-03-20', 12_000_000, ['reference_devis' => 'FR-AB 010136']);

        $this->assertCount(1, RapprochementDevisFacture::propositions([$this->site->id]));
        $this->assertNull(RapprochementDevisFacture::ecarter($gerant, $facture->id, $devis->id, [$this->site->id]));
        $this->assertCount(0, RapprochementDevisFacture::propositions([$this->site->id]));

        $ecart = EcartDevisFacture::first();
        $this->assertSame($gerant->name, $ecart->auteur);
        $this->assertNull($facture->fresh()->devis_id);
    }

    public function test_le_perimetre_vaut_des_deux_cotes(): void
    {
        $gerant = $this->compte('gerant');
        $devis = $this->devis('2026-03-02', 'FR-AB 010136');
        $facture = $this->facture('2026-03-20', 12_000_000, ['reference_devis' => 'FR-AB 010136']);

        $refus = RapprochementDevisFacture::confirmer($gerant, $facture->id, $devis->id, [999]);

        $this->assertSame("Cette facture ou ce devis n'est pas dans votre périmètre.", $refus);
        $this->assertNull($facture->fresh()->devis_id);
    }

    /*
    |--------------------------------------------------------------------------
    | La chaîne entière, de la visite au montant versé
    |--------------------------------------------------------------------------
    */

    public function test_la_chaine_complete_fait_naitre_la_commission(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);
        $this->grilleDuDocument();

        // 1. Une visite, un véhicule.
        $prospection = Prospection::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id, 'numero' => 'P-0001',
            'date' => '2026-03-01', 'client' => 'Client visité', 'activite' => 'Mécanique',
            'moyen' => 'RDV', 'statut_validation' => 'Validée', 'immatriculation' => '1234 AB 01',
        ]);

        // 2. Un devis importé de l'atelier : il ne sait pas qui a décroché l'affaire.
        $devis = $this->devis('2026-03-04', 'FR-AB 010136');
        $devis->forceFill(['commercial_id' => null])->save();
        $this->fiche('FR-AB 010136', '1234 AB 01');

        // 3. Une facture qui ne sait pas non plus.
        $facture = $this->facture('2026-03-25', 32_000_000, ['reference_devis' => 'FR-AB 010136']);

        // Avant les deux clics : la commission est nulle, et ce n'est pas un jugement sur
        // le travail du commercial — c'est qu'on ignore que la facture est la sienne.
        $this->assertSame(0, $this->commissionDuMois($gerant));

        RapprochementProspectionDevis::confirmer($gerant, $prospection->id, $devis->id, [$this->site->id]);
        $this->assertSame($this->commercial->id, $devis->fresh()->commercial_id);

        RapprochementDevisFacture::confirmer($gerant, $facture->id, $devis->id, [$this->site->id]);
        $this->assertSame($this->commercial->id, $facture->fresh()->commercial_id);

        // 32 M sur le mois : tranche 30–40 M, 2,5 % du chiffre entier.
        $this->assertSame(800_000, $this->commissionDuMois($gerant));
    }

    /*
    |--------------------------------------------------------------------------
    | Rien n'est écrit en dur, et un changement agit tout de suite
    |--------------------------------------------------------------------------
    */

    public function test_un_taux_modifie_agit_des_l_affichage_suivant(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $grille = $this->grilleDuDocument();
        $this->factureDuCommercial('2026-03-25', 32_000_000);

        $this->assertSame(800_000, $this->commissionDuMois($gerant, '2026-03'));

        // Le gérant corrige le taux de la tranche 30–40 M : 2,5 % devient 4 %.
        $grille->tranches()->where('plancher', 30_000_000)->first()->update(['taux' => 4.0]);

        // Aucun cache à vider, aucun déploiement : le chiffre suivant est déjà le nouveau.
        $this->assertSame(1_280_000, $this->commissionDuMois($gerant, '2026-03'));
    }

    public function test_la_grille_dit_elle_meme_quels_roles_elle_remunere(): void
    {
        $gerant = $this->compte('gerant');
        $responsable = $this->compte('responsable_site');

        // Une grille généreuse, réservée par le gérant au seul responsable de site : la
        // règle n'est pas dans le code, elle est cochée à l'écran.
        $reservee = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id, 'cible' => 'commercial',
            'libelle' => 'Grille des encadrants', 'date_effet' => '2026-01-01',
            'assiette' => 'global', 'roles' => ['responsable_site'],
        ]);
        $reservee->tranches()->create(['plancher' => 0, 'plafond' => null, 'taux' => 10.0]);

        $ordinaire = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id, 'cible' => 'commercial',
            'libelle' => 'Grille ordinaire', 'date_effet' => '2025-01-01',
            'assiette' => 'global', 'roles' => ['commercial'],
        ]);
        $ordinaire->tranches()->create(['plancher' => 0, 'plafond' => null, 'taux' => 1.0]);

        $this->assertSame(
            $reservee->id,
            CommissionCommerciale::grillePour($this->entreprise->id, $responsable, Carbon::parse('2026-06-30'))->id,
        );

        $vendeur = $this->compte('commercial');
        $this->assertSame(
            $ordinaire->id,
            CommissionCommerciale::grillePour($this->entreprise->id, $vendeur, Carbon::parse('2026-06-30'))->id,
        );

        // Le gérant ne vend pas : aucune de ses grilles ne le reconnaît, et l'on retombe
        // sur celle des commerciaux plutôt que de ne rien rendre.
        $this->assertNotNull(CommissionCommerciale::grillePour($this->entreprise->id, $gerant, Carbon::parse('2026-06-30')));
    }

    public function test_le_gerant_change_les_roles_d_une_grille_depuis_l_ecran(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $grille = $this->grilleDuDocument();

        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->call('ouvrir', $grille->id)
            ->set('rolesChoisis', ['commercial', 'responsable_site'])
            ->call('enregistrerLesRoles')
            ->assertSee('immédiatement');

        $this->assertSame(['commercial', 'responsable_site'], $grille->fresh()->roles);

        // Une grille qui ne rémunère personne n'a pas de sens : le refus se dit.
        Volt::actingAs($gerant)->test('gerant.bareme-commission')
            ->call('ouvrir', $grille->id)
            ->set('rolesChoisis', [])
            ->call('enregistrerLesRoles')
            ->assertSee('au moins un rôle');
    }

    public function test_l_ecran_rattache_une_facture_sur_un_clic(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $devis = $this->devis('2026-03-02', 'FR-AB 010136');
        $facture = $this->facture('2026-03-20', 12_000_000, ['reference_devis' => 'FR-AB 010136']);

        $ecran = Volt::actingAs($gerant)->test('pilotage.rapprochement-prospections-devis')
            ->set('volet', 'factures')
            ->assertSee($devis->numero);

        // Tant qu'on n'a pas cliqué, rien n'est écrit.
        $this->assertNull($facture->fresh()->devis_id);

        $ecran->call('confirmerLaFacture', $facture->id, $devis->id)
            ->assertSee('comptée au commercial du devis');

        $this->assertSame($this->commercial->id, $facture->fresh()->commercial_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Le garde-fou : la liste ne doit pas croître avec le volume
    |--------------------------------------------------------------------------
    */

    public function test_le_rapprochement_interroge_la_base_un_nombre_fixe_de_fois(): void
    {
        $this->actingAs($this->compte('gerant'));

        // Un premier appel sur un jeu minuscule.
        $this->devis('2026-03-02', 'FR-AB 000001');
        $this->facture('2026-03-20', 1_000_000, ['reference_devis' => 'FR-AB 000001']);

        $petit = $this->requetesPour(fn () => RapprochementDevisFacture::propositions([$this->site->id]));

        // Puis sur un jeu cinquante fois plus gros.
        for ($i = 2; $i <= 50; $i++) {
            $fiche = sprintf('FR-AB %06d', $i);
            $this->devis('2026-03-02', $fiche);
            $this->facture('2026-03-20', 1_000_000, ['reference_devis' => $fiche, 'n_facture' => 'F-'.$i]);
        }

        $grand = $this->requetesPour(fn () => RapprochementDevisFacture::propositions([$this->site->id]));

        /*
         * Le 21/09, cet écran retenait le serveur plus de deux minutes et figeait toute
         * l'application — le serveur de développement ne traite qu'une requête à la fois.
         * La cause était une comparaison de chaque facture à chaque devis. Ce test ne
         * mesure pas un temps, qui varierait d'une machine à l'autre : il vérifie que le
         * travail ne croît pas avec le volume, ce qui est la propriété qu'on a perdue.
         */
        $this->assertSame($petit, $grand,
            'Le nombre de requêtes doit rester le même, que la base porte deux lignes ou cent.');
    }

    /** Combien de requêtes une opération déclenche. */
    private function requetesPour(callable $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $operation();

        $nombre = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $nombre;
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    /** La commission lue telle que l'écran des commerciaux la calcule. */
    private function commissionDuMois(User $gerant, string $mois = '2026-03'): int
    {
        $resultat = CommissionCommerciale::surLesMois(
            $this->entreprise->id,
            $this->commercial->fresh()->utilisateur,
            [$mois => (int) Facture::where('commercial_id', $this->commercial->id)
                ->whereBetween('date', [$mois.'-01', Carbon::parse($mois.'-01')->endOfMonth()])
                ->sum('montant')],
        );

        return $resultat['commission'];
    }

    private function grilleDuDocument(): BaremeCommission
    {
        $bareme = BaremeCommission::create([
            'entreprise_id' => $this->entreprise->id, 'cible' => 'commercial',
            'libelle' => 'Grille vf6', 'date_effet' => '2026-01-01', 'assiette' => 'global',
        ]);

        foreach (CommissionCommerciale::GRILLE_DU_DOCUMENT['commercial']['tranches'] as $rang => [$plancher, $plafond, $taux]) {
            $bareme->tranches()->create([
                'plancher' => $plancher, 'plafond' => $plafond, 'taux' => $taux, 'ordre' => $rang,
            ]);
        }

        return $bareme->load('tranches');
    }

    private function devis(string $emission, string $fiche): Devis
    {
        return Devis::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id,
            'numero' => 'D-'.substr(md5($fiche.$emission), 0, 8),
            'n_fiche_reception' => $fiche,
            'date_emission' => $emission,
            'client' => 'Client du devis',
            'activite' => 'Mécanique',
            'statut' => 'Validé',
            'montant_devis' => 12_000_000,
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function facture(string $date, int $montant, array $attributs = []): Facture
    {
        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => 'FAC-'.substr(md5($date.$montant.($attributs['n_facture'] ?? '')), 0, 8),
            'n_facture' => $attributs['n_facture'] ?? 'F-001',
            'date' => $date,
            'client' => 'Client facturé',
            'montant' => $montant,
            'activite' => 'Mécanique',
            'reference_devis' => $attributs['reference_devis'] ?? null,
            'immatriculation' => $attributs['immatriculation'] ?? null,
        ]);
    }

    private function factureDuCommercial(string $date, int $montant): Facture
    {
        $facture = $this->facture($date, $montant);
        $facture->forceFill(['commercial_id' => $this->commercial->id])->save();

        return $facture;
    }

    private function fiche(string $numero, string $plaque): DossierVehicule
    {
        return DossierVehicule::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'numero_fiche' => $numero,
            'immatriculation' => $plaque,
            'client' => 'Propriétaire',
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

        // Le commercial de ce test porte le compte « commercial » : c'est par son rôle que
        // la grille le reconnaît.
        if ($role === 'commercial') {
            $this->commercial->forceFill(['user_id' => $compte->id])->save();
        }

        return $compte->fresh();
    }
}

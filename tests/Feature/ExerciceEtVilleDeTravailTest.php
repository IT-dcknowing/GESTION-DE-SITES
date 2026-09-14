<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Services\VilleDeTravail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'année et la ville qu'on regarde — deux loupes, et rien de plus.
 *
 * Elles répondent à la même demande : consulter autre chose que le contexte du jour, sans
 * modifier quoi que ce soit. C'est la distinction qui manquait : jusqu'ici, revenir sur
 * l'exercice passé obligeait à **le rouvrir**, c'est-à-dire à changer l'état de l'entreprise
 * pour lire une donnée — la manœuvre qu'on oublie ensuite de défaire.
 *
 * Ces tests fixent les trois propriétés qui font qu'une loupe reste une loupe :
 *
 * 1. elle change ce qu'on voit ;
 * 2. elle ne change rien en base ;
 * 3. elle refuse une valeur qui n'existe pas plutôt que de vider l'écran sans le dire.
 */
class ExerciceEtVilleDeTravailTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private User $gerant;

    private Ville $abidjan;

    private Ville $bouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id,
            'code' => 'BOU-1', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->gerant = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant',
            'email' => 'gerant@alpha.test', 'password' => Hash::make('motdepasse123'), 'est_actif' => true,
        ]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();

        $this->actingAs($this->gerant);

        // `Exercice::actuel()` lit un exercice, il n'en crée pas : c'est la bascule qui
        // s'en charge, au premier passage d'année comme au premier jour de l'entreprise.
        (new \Modules\Noyau\Entreprises\Services\BasculeDExercice)->assurer($this->entreprise->id);
    }

    /*
    |--------------------------------------------------------------------------
    | L'exercice regardé
    |--------------------------------------------------------------------------
    */

    public function test_sans_choix_on_regarde_l_exercice_en_cours(): void
    {
        $this->assertSame(now()->year, ExerciceDeTravail::annee());
        $this->assertFalse(ExerciceDeTravail::estUnRetourEnArriere());
    }

    public function test_on_peut_regarder_une_annee_passee_sans_rien_clore(): void
    {
        $courant = Exercice::actuel($this->entreprise->id);
        $passe = Exercice::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'annee' => now()->year - 1,
            'statut' => 'Ouvert',
        ]);

        ExerciceDeTravail::choisir($passe->annee);

        $this->assertSame($passe->annee, ExerciceDeTravail::annee());
        $this->assertTrue(ExerciceDeTravail::estUnRetourEnArriere());

        // Et surtout : rien n'a bougé en base. C'est tout l'intérêt de la manœuvre.
        $this->assertSame('Ouvert', $courant->fresh()->statut);
        $this->assertSame('Ouvert', $passe->fresh()->statut);
        $this->assertSame(
            $courant->annee,
            Exercice::actuel($this->entreprise->id)->annee,
            "L'exercice courant de l'entreprise n'est pas affecté par ce qu'on regarde.",
        );
    }

    public function test_la_periode_calculee_suit_l_annee_regardee(): void
    {
        Exercice::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'annee' => now()->year - 1, 'statut' => 'Ouvert',
        ]);

        ExerciceDeTravail::choisir(now()->year - 1);

        // C'était le défaut : choisir « mars » en consultant l'année passée ramenait mars de
        // l'année en cours, donc un mois vide, sans que rien ne le signale.
        [$debut, $fin] = PeriodeCalculateur::plage('calendrier', null, null, 3);

        $this->assertSame(now()->year - 1, $debut->year);
        $this->assertSame(3, $debut->month);
        $this->assertSame(now()->year - 1, $fin->year);
    }

    public function test_une_annee_inexistante_est_ignoree_plutot_que_subie(): void
    {
        ExerciceDeTravail::choisir(1998);

        // On revient à l'exercice courant plutôt que d'afficher une année vide : un écran
        // qui montre zéro sans dire pourquoi se lit comme une absence d'activité.
        $this->assertSame(now()->year, ExerciceDeTravail::annee());
    }

    /*
    |--------------------------------------------------------------------------
    | La ville regardée
    |--------------------------------------------------------------------------
    */

    public function test_sans_choix_on_regarde_toutes_les_villes(): void
    {
        $this->assertNull(VilleDeTravail::villeId());
        $this->assertNull(
            VilleDeTravail::sites(),
            'Aucune condition posée : les lignes sans atelier restent visibles.',
        );
        $this->assertSame('Toutes les villes', VilleDeTravail::libelle());
    }

    public function test_choisir_une_ville_restreint_aux_sites_de_cette_ville(): void
    {
        VilleDeTravail::choisir($this->bouake->id);

        $this->assertSame($this->bouake->id, VilleDeTravail::villeId());
        $this->assertSame('Bouaké', VilleDeTravail::libelle());

        $sites = VilleDeTravail::sites();
        $this->assertCount(1, $sites);
        $this->assertSame(
            $this->bouake->id,
            Site::find($sites[0])->ville_id,
        );
    }

    public function test_une_ville_etrangere_est_ignoree(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $villeAutre = Ville::withoutGlobalScopes()->create([
            'entreprise_id' => $autre->id, 'code' => 'XXX', 'nom' => 'Ailleurs', 'est_actif' => true,
        ]);

        VilleDeTravail::choisir($villeAutre->id);

        $this->assertNull(VilleDeTravail::villeId(), "La ville d'une autre entreprise n'est jamais retenue.");
    }
}

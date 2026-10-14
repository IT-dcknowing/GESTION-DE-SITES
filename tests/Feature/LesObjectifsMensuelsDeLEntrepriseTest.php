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
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\ObjectifEntreprise;
use Modules\Noyau\Exploitation\Services\ObjectifsDeLEntreprise;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les objectifs mensuels de l'entreprise — 14/10 : des tranches, une période, une portée ; le
 * système applique celui dont la période couvre le jour, au prorata des jours du mois.
 */
class LesObjectifsMensuelsDeLEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $abidjan;

    private Ville $bouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->abidjan = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
    }

    public function test_l_objectif_s_applique_selon_sa_periode_et_au_prorata_des_jours(): void
    {
        $this->objectif('Premier semestre', '2026-01-01', '2026-06-30', null, [100_000_000, 150_000_000]);
        $this->objectif('Second semestre', '2026-07-01', '2026-12-31', null, [120_000_000, 180_000_000]);

        $service = new ObjectifsDeLEntreprise($this->e->id);

        $juin = $service->pourLaPeriode(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), null, null);
        $this->assertSame([100_000_000, 150_000_000], array_column($juin['tranches'], 'montant'));
        $this->assertSame('Seuil', $juin['tranches'][0]['libelle']);

        // Juin et juillet : chaque mois prend l'objectif de sa période.
        $deuxMois = $service->pourLaPeriode(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-31'), null, null);
        $this->assertSame([220_000_000, 330_000_000], array_column($deuxMois['tranches'], 'montant'));
        $this->assertCount(2, $deuxMois['objectifs']);

        // La moitié de juin (15 jours sur 30) : la moitié de l'objectif.
        $quinzaine = $service->pourLaPeriode(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-15'), null, null);
        $this->assertSame([50_000_000, 75_000_000], array_column($quinzaine['tranches'], 'montant'));

        // Hors de toute période : rien.
        $this->assertSame([], $service->pourLaPeriode(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-31'), null, null)['tranches']);
    }

    public function test_sans_objectif_d_ensemble_l_entreprise_additionne_ses_villes(): void
    {
        $this->objectif('Abidjan', '2026-01-01', '2026-12-31', $this->abidjan->id, [80_000_000]);
        $this->objectif('Bouaké', '2026-01-01', '2026-12-31', $this->bouake->id, [20_000_000]);

        $service = new ObjectifsDeLEntreprise($this->e->id);
        $mai = [Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')];

        $this->assertSame([100_000_000], array_column($service->pourLaPeriode(...[...$mai, null, null, [$this->abidjan->id, $this->bouake->id]])['tranches'], 'montant'));
        $this->assertSame([20_000_000], array_column($service->pourLaPeriode(...[...$mai, $this->bouake->id, null])['tranches'], 'montant'));
    }

    public function test_deux_objectifs_de_meme_portee_ne_se_recouvrent_pas_et_les_tranches_croissent(): void
    {
        $this->objectif('Premier semestre', '2026-01-01', '2026-06-30', null, [100_000_000]);
        $service = new ObjectifsDeLEntreprise($this->e->id);

        try {
            $service->verifier(Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'), null, null, [['libelle' => 'A', 'montant' => 1]]);
            $this->fail('Le recouvrement doit être refusé.');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('Premier semestre', $refus->getMessage());
        }

        // Une autre portée (une ville) peut couvrir la même période.
        $service->verifier(Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'), $this->abidjan->id, null, [['libelle' => 'A', 'montant' => 1]]);

        $this->expectException(RuntimeException::class);
        $service->verifier(Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'), null, null,
            [['libelle' => 'Haut', 'montant' => 200], ['libelle' => 'Bas', 'montant' => 100]]);
    }

    public function test_le_gerant_pose_un_objectif_et_le_tableau_de_bord_le_compare_au_ca(): void
    {
        $gerant = $this->compte('gerant');

        Volt::actingAs($gerant)->test('gerant.objectifs-mensuels')
            ->set('libelle', 'Objectifs '.now()->year)
            ->set('dateDebut', now()->startOfYear()->toDateString())
            ->set('dateFin', now()->endOfYear()->toDateString())
            ->set('tranches', [['libelle' => 'Seuil', 'montant' => '1 000 000'], ['libelle' => 'Objectif', 'montant' => '3 000 000']])
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('erreur', '')
            ->assertSee('Objectifs '.now()->year);

        $objectif = ObjectifEntreprise::withoutGlobalScopes()->with('tranches')->sole();
        $this->assertSame([1_000_000, 3_000_000], $objectif->tranches->pluck('montant')->all());

        $site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $site->id, 'ville_id' => $this->abidjan->id, 'numero' => 'F-1', 'n_facture' => 'FA -1',
            'date' => now()->startOfMonth()->toDateString(), 'client' => 'X', 'activite' => 'Mécanique', 'montant' => 2_000_000,
        ]);

        $ecran = Volt::actingAs($gerant)->test('gerant.tableau-de-bord')
            ->set('periode', 'calendrier')
            ->assertSee('Configurer les objectifs');

        $obj = $ecran->instance()->objectifDeLEntreprise;
        $this->assertNotSame([], $obj['tranches']);
        $this->assertSame(2_000_000, $obj['realise']);
    }

    public function test_seul_le_gerant_ouvre_la_page(): void
    {
        $this->actingAs($this->compte('responsable_ville'))->get('/parametres/objectifs')->assertRedirect();
        $this->actingAs($this->compte('gerant', 'g2@alpha.test'))->get('/parametres/objectifs')->assertOk();
    }

    private function objectif(string $libelle, string $debut, string $fin, ?int $villeId, array $montants): ObjectifEntreprise
    {
        $objectif = ObjectifEntreprise::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'libelle' => $libelle, 'date_debut' => $debut, 'date_fin' => $fin, 'ville_id' => $villeId,
        ]);

        foreach ($montants as $i => $montant) {
            $objectif->tranches()->create(['rang' => $i + 1, 'libelle' => ['Seuil', 'Objectif', 'Excellence'][$i], 'montant' => $montant]);
        }

        return $objectif;
    }

    private function compte(string $role, ?string $email = null): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Compte '.$role, 'email' => $email ?? str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->abidjan->id, 'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

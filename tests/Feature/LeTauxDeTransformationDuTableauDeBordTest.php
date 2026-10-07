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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le taux de transformation du tableau de bord, après l'avoir ramené à une requête.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi ce fichier existe.** Mesuré le 02/10 : le tableau de bord du gérant demandait
 * **six comptages de devis** pour afficher trois taux — émis, validés, puis émis et validés
 * pour chacune des deux activités —, tous sur le même ensemble, tous relancés à chaque
 * changement de filtre. Un `group by (activité, statut)` les porte tous.
 *
 * **Et rien ne couvrait ces trois taux.** C'est l'écran d'accueil du gérant, et le taux de
 * transformation est l'un des chiffres sur lesquels il juge ses commerciaux. Ramener six
 * requêtes à une sans test aurait été changer un chiffre à l'aveugle.
 *
 * Ce que le test tient, et qui est exactement ce qu'on risquait de casser :
 *
 * - le taux global compte **tous** les devis, y compris ceux dont l'activité est vide ;
 * - un taux par activité ne voit que son activité ;
 * - **aucun devis émis ne vaut pas « 0 % »** mais « pas de taux » — c'est la distinction
 *   qu'un `count()` remplacé par une somme de groupes perd le plus facilement.
 */
class LeTauxDeTransformationDuTableauDeBordTest extends TestCase
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

    public function test_les_trois_taux_sont_ceux_quon_calculait_devis_par_devis(): void
    {
        // Mécanique : 3 émis, 2 validés → 2/3.
        $this->devis('Mécanique', 'Validé');
        $this->devis('Mécanique', 'Validé');
        $this->devis('Mécanique', 'En attente');
        // Sinistre : 2 émis, 1 validé → 1/2.
        $this->devis('Sinistre', 'Validé');
        $this->devis('Sinistre', 'Refusé');
        // Une troisieme activite : elle compte dans le global et dans aucun des deux taux
        // ventiles. `devis.activite` est NOT NULL, donc le cas « sans activite » n'existe
        // pas en base — verifie en ecrivant ce test, qui le supposait d'abord possible.
        $this->devis('Carrosserie', 'Validé');

        $kpis = $this->kpis();

        // Global : 6 émis, 4 validés.
        $this->assertEquals(4 / 6, $kpis['tauxTransfo']);
        $this->assertEquals(2 / 3, $kpis['tauxTransfoMecanique']);
        $this->assertEquals(1 / 2, $kpis['tauxTransfoSinistre']);
    }

    /**
     * Aucun devis dans une activité : « pas de taux », et non « 0 % ».
     *
     * L'écran affiche un tiret pour `null` et « 0 % » pour zéro. Dire 0 % d'un commercial qui
     * n'a présenté aucun devis serait un reproche qu'on lui adresse à tort.
     */
    public function test_une_activite_sans_devis_na_pas_de_taux_et_non_zero(): void
    {
        $this->devis('Mécanique', 'Validé');

        $kpis = $this->kpis();

        // `1/1` vaut `int(1)` en PHP, et le valait déjà avant : on compare la valeur.
        $this->assertEquals(1, $kpis['tauxTransfoMecanique']);
        $this->assertNull($kpis['tauxTransfoSinistre'], 'Aucun devis sinistre : le taux est indéfini, pas nul.');
    }

    public function test_aucun_devis_du_tout_ne_donne_aucun_taux(): void
    {
        $kpis = $this->kpis();

        $this->assertNull($kpis['tauxTransfo']);
        $this->assertNull($kpis['tauxTransfoMecanique']);
        $this->assertNull($kpis['tauxTransfoSinistre']);
    }

    /** Les six comptages d'avant ne doivent plus être qu'un. */
    public function test_les_trois_taux_ne_coutent_quune_requete_sur_les_devis(): void
    {
        $this->devis('Mécanique', 'Validé');
        $this->devis('Sinistre', 'Refusé');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->kpis();

        /*
         * On ne compte que les requetes portant sur **l'ensemble des ateliers retenus**
         * (`site_id in (…)`), qui est le perimetre des trois taux. L'ecran porte aussi un
         * tableau par atelier : depuis le 07/10 il lit lui aussi tous les ateliers d'un
         * coup, mais regroupe par atelier (`group by "site_id"`) — c'est un autre bloc
         * (`SyntheseParAtelier`, son propre test), et il n'a rien a voir ici.
         */
        $pourLesTaux = collect(DB::getQueryLog())
            ->filter(fn ($entree) => str_contains($entree['query'], '"devis"')
                && str_contains($entree['query'], '"site_id" in (')
                && ! str_contains($entree['query'], 'group by "site_id"'))
            ->pluck('query');

        $this->assertCount(1, $pourLesTaux, 'Les trois taux se lisent dans un seul regroupement.');
        $this->assertStringContainsString('group by', $pourLesTaux->first());
    }

    private function kpis(): array
    {
        return Volt::actingAs($this->gerant())->test('gerant.tableau-de-bord')->get('kpis');
    }

    private function devis(?string $activite, string $statut): Devis
    {
        return Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date_emission' => now()->toDateString(),
            'numero' => 'D-'.Devis::withoutGlobalScopes()->count(),
            'client' => 'NSIA ASSURANCES',
            'activite' => $activite,
            'statut' => $statut,
            'montant_devis' => 500_000,
        ]);
    }

    private function gerant(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

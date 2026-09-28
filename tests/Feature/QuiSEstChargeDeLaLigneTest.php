<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Tracabilite\Services\QuiAAgi;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Qui s'est chargé d'une ligne — le nom dans le tableau, les dates dans le détail.
 *
 * **Demandé le 25/09, et pour toute l'application** : « dès qu'une personne aura à agir
 * sur une ligne des impayés, cela devra être marqué par son identifiant, et aussi le ou
 * les dates où elle a agi sur la ligne — cette information doit apparaître uniquement dans
 * le détail ».
 *
 * La répartition est la consigne, et elle est juste : un tableau répond à « qui suit ce
 * dossier ? », un détail à « que s'est-il passé, et quand ? ». Une colonne de dates dans
 * un tableau de vingt-quatre colonnes n'aiderait personne à décider.
 *
 * **Rien n'est stocké pour cela.** Le journal d'audit consigne déjà chaque écriture avec
 * son auteur et son horodatage ; la ligne porte `cree_par`. Une colonne « traité par »
 * tenue à part aurait fini par diverger du journal — et c'est le journal qui fait foi.
 */
class QuiSEstChargeDeLaLigneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_la_ligne_nomme_ceux_qui_l_ont_touchee(): void
    {
        $agent = $this->compte('agent_recouvrement', 'Aya Koné');
        $gerant = $this->compte('gerant', 'Jean-Baptiste Kouassi');

        $this->actingAs($agent);
        $creance = $this->creance();

        // Un second geste, par quelqu'un d'autre.
        $this->actingAs($gerant);
        $creance->update(['montant' => 900_000]);

        $traitants = QuiAAgi::pour(collect([$creance->fresh()]));

        $noms = collect($traitants[$creance->id])->pluck('nom')->sort()->values()->all();

        $this->assertSame(['Aya Koné', 'Jean-Baptiste Kouassi'], $noms);
    }

    /**
     * Le code de saisie accompagne le nom, et c'est lui qui désigne.
     *
     * Deux homonymes existent dans une maison, et c'est précisément quand on demande des
     * comptes qu'il ne faut pas se tromper de personne.
     */
    public function test_chaque_personne_porte_son_code_et_sa_fonction(): void
    {
        $gerant = $this->compte('gerant', 'Jean-Baptiste Kouassi');

        $this->actingAs($gerant);
        $creance = $this->creance();

        $personne = QuiAAgi::detailDe($creance->fresh())->firstOrFail();

        $this->assertSame('Jean-Baptiste Kouassi', $personne['nom']);
        $this->assertSame('Gérant', $personne['fonction']);
        $this->assertNotNull($personne['code'], 'Le code de saisie désigne sans ambiguïté.');
    }

    /**
     * Les dates sont dans le détail, et dans le détail seulement.
     */
    public function test_le_detail_porte_les_dates_et_le_tableau_ne_les_porte_pas(): void
    {
        $gerant = $this->compte('gerant', 'Jean-Baptiste Kouassi');

        $this->actingAs($gerant);
        $creance = $this->creance();
        $creance->update(['montant' => 900_000]);

        $detail = QuiAAgi::detailDe($creance->fresh())->firstOrFail();

        $this->assertArrayHasKey('dates', $detail);
        $this->assertGreaterThanOrEqual(1, count($detail['dates']));

        // Le lot, lui, ne rend que de quoi nommer : pas de dates à afficher dans une colonne.
        $ligne = QuiAAgi::pour(collect([$creance->fresh()]))[$creance->id][0];

        $this->assertSame(['nom', 'code', 'fonction'], array_keys($ligne));
    }

    /**
     * Une ligne venue d'un import que personne n'a touchée ne nomme personne.
     *
     * C'est le cas le plus fréquent — 8 852 créances reprises — et ce n'est pas une
     * anomalie. Inventer un nom ferait porter à quelqu'un un dossier qu'il n'a pas ouvert.
     */
    public function test_une_ligne_importee_intacte_ne_nomme_personne(): void
    {
        $creance = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->subDays(30),
            'n_facture' => 'F-IMPORT',
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Sinistre',
            'montant' => 500_000,
            'exercice_impayes' => now()->year,
        ]);

        $this->assertSame([], QuiAAgi::pour(collect([$creance]))[$creance->id] ?? []);
        $this->assertTrue(QuiAAgi::detailDe($creance)->isEmpty());
    }

    /** Une seule requête de journal pour toute une page, et non une par ligne. */
    public function test_toute_une_page_se_lit_en_un_nombre_de_requetes_constant(): void
    {
        $gerant = $this->compte('gerant', 'Jean-Baptiste Kouassi');
        $this->actingAs($gerant);

        $lignes = collect(range(1, 15))->map(fn (int $rang) => $this->creance('F-'.$rang));

        // Relues hors du compteur : c'est le service qu'on mesure, pas la préparation.
        $fraiches = $lignes->map->fresh();

        DB::enableQueryLog();
        QuiAAgi::pour($fraiches);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Le journal, les comptes, leurs rôles : une poignée, et le même nombre à
        // cinquante lignes qu'à quinze.
        $this->assertLessThanOrEqual(5, $requetes, 'Le nombre de requêtes doit être constant.');
    }

    // ------------------------------------------------------------------ le décor

    private function creance(string $numero = 'F-001'): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->subDays(30),
            'n_facture' => $numero,
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Sinistre',
            'montant' => 750_000,
            'exercice_impayes' => now()->year,
            'cree_par' => auth()->id(),
        ]);
    }

    private function compte(string $role, string $nom): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => $nom,
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->site->ville_id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

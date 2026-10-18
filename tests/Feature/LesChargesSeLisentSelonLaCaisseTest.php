<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Services\CategorieDeSortie;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les charges selon la caisse — 14/10 : la carte s'ouvre sur les catégories (lues dans le libellé),
 * et le tableau montre les lignes du journal derrière un bouton.
 */
class LesChargesSeLisentSelonLaCaisseTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_la_categorie_se_lit_dans_le_motif_puis_le_libelle(): void
    {
        $this->assertSame('Carburant & transport', CategorieDeSortie::de('Gasoil véhicule de dépannage'));
        $this->assertSame('Achats pièces', CategorieDeSortie::de('Achat filtre à huile FR-KZ 0102'));
        $this->assertSame('Achats pièces', CategorieDeSortie::de('filtre à huile', 'ACHATS DIVERS'), 'Le libellé précis bat le motif fourre-tout.');
        $this->assertSame('Carburant & transport', CategorieDeSortie::de('pour la livraison', 'CARBURANT'));
        $this->assertSame('Salaires & personnel', CategorieDeSortie::de('Avance sur salaire KONE'));
        $this->assertSame('Restauration & réception', CategorieDeSortie::de('Repas du personnel'), 'Un repas du personnel est un repas.');
        $this->assertSame(CategorieDeSortie::PAS_UNE_CHARGE, CategorieDeSortie::de('Versement banque BGFI'));
        $this->assertSame("Main-d'œuvre & sous-traitance", CategorieDeSortie::de("Main-d'œuvre tôlier"));
        $this->assertSame('Fonctionnement', CategorieDeSortie::de('Facture CIE électricité'), 'CIE avant « facture ».');
        $this->assertSame(CategorieDeSortie::AUTRES, CategorieDeSortie::de('XYZ'));
        $this->assertSame(CategorieDeSortie::AUTRES, CategorieDeSortie::de('Fiche de reception 0102'), 'Un mot pris au milieu d\'un autre ne compte pas.');
        $this->assertFalse(CategorieDeSortie::estUneCharge(CategorieDeSortie::PAS_UNE_CHARGE));
    }

    public function test_la_carte_s_ouvre_sur_les_categories_et_le_tableau_montre_les_lignes(): void
    {
        $this->sortie('GASOIL DEPANNEUSE', 20000);
        $this->sortie('CARBURANT GROUPE', 15000.5);
        $this->sortie('ACHAT FILTRE A HUILE', 45000);
        $this->sortie('VERSEMENT BANQUE', 500000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.charges')
            ->assertSee('détail par catégorie')
            ->call('ouvrirLesCategories')
            ->assertSee('Sorties de caisse par catégorie')
            ->assertSee('Carburant &amp; transport', false)
            ->assertSee('35 000,50 F');

        $categories = collect($ecran->instance()->categoriesDeCaisse)->keyBy('categorie');
        $this->assertSame(35000.5, $categories['Carburant & transport']['montant']);
        $this->assertSame(2, $categories['Carburant & transport']['nombre']);
        $this->assertFalse($categories[CategorieDeSortie::PAS_UNE_CHARGE]['charge']);

        // Le bouton du tableau : toutes les lignes de la caisse.
        $ecran->call('voirLaCaisse')
            ->assertSee('Charges selon la caisse — sorties du journal (4')
            ->assertSee('ACHAT FILTRE A HUILE')
            ->assertSee('VERSEMENT BANQUE');

        // Une catégorie cliquée : ses seules lignes.
        $rang = array_search('Achats pièces', CategorieDeSortie::toutes(), true);
        $ecran->call('voirLaCategorie', $rang)
            ->assertSet('categorieCaisse', 'Achats pièces')
            ->assertSee('ACHAT FILTRE A HUILE');
        $this->assertSame(['ACHAT FILTRE A HUILE'], $ecran->instance()->sortiesAffichees->pluck('libelle')->all());

        $ecran->call('voirLesCharges')->assertSee('Toutes les charges');
    }

    private function sortie(string $libelle, int|float $montant): MouvementCaisse
    {
        return MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'sens' => MouvementCaisse::SORTIE, 'libelle' => $libelle, 'montant' => $montant,
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Awa Koné', 'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id, 'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

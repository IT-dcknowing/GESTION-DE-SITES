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
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Services\TableauDesImports;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un fichier qu'on a décidé de ne pas lire le dit, et dit pourquoi.
 *
 * **Le registre avait deux catégories et il en fallait trois.** Il disait ce qu'on sait
 * lire (`DISPONIBLES`) et ce qu'on saura lire (`ANNONCES`) ; il ne disait rien de ce qu'on
 * a regardé puis écarté. Or la décision existait depuis le 18/09 — ne pas écrire d'import
 * pour les fiches de réception, la situation du parc portant les mêmes fiches avec dix-sept
 * colonnes — et elle n'était visible que dans l'état des lieux du dépôt de code.
 *
 * Celui qui tient un export de fiches de réception vient sur l'écran de dépôt. N'y trouvant
 * rien, il ne peut pas savoir si c'est un oubli ou une décision : il redemande, ou il
 * attend. **Une décision qui ne se lit nulle part se reprend tous les trois mois.**
 *
 * **Un écarté n'est ni un retard ni un manque.** La colonne « villes manquantes » le dit
 * « sans objet » plutôt que d'y peindre en rouge trois villes qu'on n'attend pas, et le
 * compteur « en base » affiche un tiret plutôt que « table à créer ».
 *
 * **Deux affirmations périmées sont corrigées au passage** dans `TableauDesImports` : les
 * entrées et sorties avaient un commentaire disant qu'elles n'avaient « aucun lecteur, les
 * fichiers sortent en PDF » — les deux lecteurs existent ; et trois des onze types
 * n'affichaient aucun compteur faute de destination déclarée, alors que la question de cet
 * écran est « qu'est-ce qui manque encore ? ».
 */
class UnFichierEcarteLeDitEtDitPourquoiTest extends TestCase
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
            'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true,
        ]);
    }

    public function test_un_ecarte_n_est_pas_importable(): void
    {
        // La troisième catégorie ne doit pas ouvrir une porte : un fichier écarté ne
        // s'importe pas, et ne figure pas dans la liste déroulante du dépôt.
        $this->assertFalse(Registre::connait('fiches-reception'));
        $this->assertArrayNotHasKey('fiches-reception', Registre::options());
    }

    public function test_un_ecarte_porte_sa_raison(): void
    {
        $meta = Registre::ECARTES['fiches-reception'];

        // Le refus sans la raison se reprend tous les trois mois.
        $this->assertNotSame('', trim($meta['pourquoi']));
        $this->assertStringContainsString('parc', $meta['pourquoi']);
        $this->assertStringContainsString('code client', $meta['pourquoi']);
    }

    public function test_le_registre_sait_nommer_un_ecarte(): void
    {
        // Sans quoi la page afficherait la clé technique « fiches-reception ».
        $this->assertStringContainsString('Fiches de réception', Registre::libelle('fiches-reception'));
    }

    public function test_le_tableau_de_bord_porte_une_ligne_par_ecarte(): void
    {
        $lignes = (new TableauDesImports($this->entreprise->id))->lignes();

        $ecartes = $lignes->where('ecarte', true);

        $this->assertCount(count(Registre::ECARTES), $ecartes);
        $this->assertSame(
            count(Registre::DISPONIBLES) + count(Registre::ANNONCES) + count(Registre::ECARTES),
            $lignes->count(),
        );
    }

    public function test_un_ecarte_n_est_ni_un_retard_ni_un_manque(): void
    {
        $ligne = (new TableauDesImports($this->entreprise->id))->lignes()
            ->firstWhere('cle', 'fiches-reception');

        $this->assertFalse($ligne['disponible']);
        $this->assertTrue($ligne['ecarte']);
        // Aucun dépôt n'est attendu : ni « jamais » en creux, ni des villes en rouge.
        $this->assertSame(0, $ligne['lots']);
    }

    public function test_les_trois_types_sans_compteur_en_ont_un(): void
    {
        $lignes = (new TableauDesImports($this->entreprise->id))->lignes()->keyBy('cle');

        // La question de l'écran est « qu'est-ce qui manque encore ? » : un type sans
        // compteur répond « table à créer » en rouge, ce qui était faux pour ces trois-là.
        foreach (['journal-caisse', 'balance-fournisseurs', 'reglements-fournisseurs'] as $cle) {
            $this->assertNotNull($lignes[$cle]['lignes_en_base'], "{$cle} n'affiche aucun compteur");
        }
    }

    public function test_chaque_format_disponible_sait_ou_il_ecrit(): void
    {
        $lignes = (new TableauDesImports($this->entreprise->id))->lignes();

        foreach ($lignes->where('disponible', true) as $ligne) {
            $this->assertNotNull(
                $ligne['lignes_en_base'],
                "Le format « {$ligne['cle']} » est importable mais n'annonce aucune table : ".
                'il afficherait « table à créer » alors que la table existe.',
            );
        }
    }

    public function test_l_ecran_de_depot_montre_l_ecarte_et_sa_raison(): void
    {
        Volt::actingAs($this->compte())->test('import.depot')
            ->assertSee('Fiches de réception')
            ->assertSee('sans objet')
            ->assertSee('La situation du parc porte les mêmes fiches');
    }

    private function compte(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Gérant',
            'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

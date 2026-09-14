<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'onglet des exercices, après la fin des clôtures.
 *
 * Le moteur avait déjà changé de règle — l'année suivante s'ouvre seule, la précédente ne
 * se ferme jamais — mais l'écran proposait toujours de clôturer. Deux discours contraires
 * dans le même logiciel : c'est l'écran qui avait tort.
 */
class OngletExercicesTest extends TestCase
{
    use RefreshDatabase;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($entreprise);

        $ville = Ville::create(['entreprise_id' => $entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        Site::create(['entreprise_id' => $entreprise->id, 'ville_id' => $ville->id, 'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($entreprise->id);

        $this->gerant = User::create([
            'entreprise_id' => $entreprise->id, 'name' => 'Gérant',
            'email' => 'g@alpha.test', 'password' => 'motdepasse', 'est_actif' => true,
        ]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();
        $this->actingAs($this->gerant);

        (new \Modules\Noyau\Entreprises\Services\BasculeDExercice)->assurer($entreprise->id);
    }

    public function test_l_onglet_ne_propose_plus_aucune_cloture(): void
    {
        $html = Volt::test('gerant.parametres')->set('onglet', 'exercices')->html();

        $this->assertStringNotContainsString('Clôturer', $html);
        $this->assertStringContainsString("Aucun exercice ne se clôture", $html);
    }

    public function test_les_actions_de_cloture_n_existent_plus_du_tout(): void
    {
        // Retirer le bouton ne suffisait pas : une action Livewire reste appelable par une
        // requête forgée, et clôturer bloquerait la saisie de toute une ville.
        $composant = Volt::test('gerant.parametres');

        foreach (['cloreExercice', 'clorePourVille', 'reouvrirExercice', 'reouvrirPourVille'] as $action) {
            $this->assertFalse(
                method_exists($composant->instance(), $action),
                "L'action « {$action} » ne devrait plus exister.",
            );
        }
    }

    public function test_on_peut_toujours_ouvrir_une_annee_anterieure(): void
    {
        Volt::test('gerant.parametres')
            ->set('onglet', 'exercices')
            ->set('exerciceAnnee', now()->year - 2)
            ->call('creerExercice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('exercices', ['annee' => now()->year - 2, 'statut' => 'Ouvert']);
    }
}

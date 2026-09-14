<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\RolesCommerciaux;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Qui porte des objectifs doit pouvoir les regarder.
 *
 * **Le défaut.** Quatre rôles reçoivent une fiche commercial et des objectifs à
 * l'ouverture de leur accès : le commercial, bien sûr, mais aussi le responsable de ville,
 * le responsable de site et le responsable commercial — tous prospectent en plus
 * d'encadrer. L'écran « Ma performance individuelle » n'était pourtant ouvert qu'au
 * premier. Les trois autres portaient des chiffres que rien ne leur montrait.
 *
 * Des objectifs qu'on ne peut pas consulter ne sont pas des objectifs.
 *
 * **Ce que le test tient en plus de l'accès : la frontière.** Le gérant n'a pas de fiche —
 * il répond de l'entreprise entière, pas d'un quota personnel. L'écran doit lui rester
 * fermé, et l'onglet absent : un onglet qui mène à un refus vaut moins qu'un onglet
 * absent.
 */
class MaPerformancePourQuiVendTest extends TestCase
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
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_chaque_role_qui_vend_atteint_sa_performance(): void
    {
        foreach (RolesCommerciaux::TOUS as $role) {
            $compte = $this->ouvrirUnAcces($role);

            // La fiche d'abord : sans elle, l'écran n'aurait rien à montrer, et le test
            // ne prouverait que l'ouverture d'une route vide.
            $this->assertNotNull(
                Commercial::withoutGlobalScopes()->where('user_id', $compte->id)->first(),
                "Le rôle « $role » devrait recevoir une fiche commercial.",
            );

            $this->actingAs($compte)
                ->get(route('ma-performance'))
                ->assertStatus(200, "Le rôle « $role » ne peut pas consulter ses propres objectifs.");

            auth()->guard('web')->logout();
        }
    }

    public function test_l_onglet_suit_la_fiche_et_non_le_seul_role_commercial(): void
    {
        foreach (RolesCommerciaux::TOUS as $role) {
            $compte = $this->ouvrirUnAcces($role);

            $this->actingAs($compte);

            $this->assertContains(
                'Ma performance individuelle',
                $this->etiquettes(MenuNavigation::pour($compte)),
                "Le rôle « $role » porte des objectifs mais aucun onglet ne les lui montre.",
            );

            auth()->guard('web')->logout();
        }
    }

    public function test_le_gerant_n_y_a_pas_sa_place(): void
    {
        /*
         * Il répond de l'entreprise entière et ne porte pas d'objectif individuel : il n'a
         * pas de fiche, et l'écran n'aurait rien à lui dire.
         */
        $gerant = $this->ouvrirUnAcces('gerant');

        $this->assertNull(Commercial::withoutGlobalScopes()->where('user_id', $gerant->id)->first());

        $this->actingAs($gerant)->get(route('ma-performance'))->assertStatus(302);

        $this->assertNotContains(
            'Ma performance individuelle',
            $this->etiquettes(MenuNavigation::pour($gerant)),
        );
    }

    private function ouvrirUnAcces(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $donnees = [
            'nom' => 'Compte '.$role,
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'objectif_mecanique' => 600_000,
            'objectif_sinistre' => 400_000,
            'est_actif' => true,
        ];

        // Chaque rôle se rattache par sa propre clé : un lieu pour le responsable de site,
        // une ville pour les autres, rien pour le gérant.
        $donnees += $role === 'responsable_site'
            ? ['site_id' => (string) $this->site->id]
            : ['ville_id' => (string) $this->ville->id];

        $compte = (new CreerAcces)->executer($this->entreprise, $role, $donnees);

        // Sans cela, le middleware du premier mot de passe détourne toute requête.
        $compte->forceFill(['doit_changer_mot_de_passe' => false])->save();

        return $compte->fresh();
    }

    /** @return array<int, string> */
    private function etiquettes(array $onglets): array
    {
        $labels = [];

        foreach ($onglets as $onglet) {
            $labels[] = $onglet['label'];

            foreach ($onglet['groupe'] ?? [] as $sous) {
                $labels[] = $sous['label'];
            }
        }

        return $labels;
    }
}

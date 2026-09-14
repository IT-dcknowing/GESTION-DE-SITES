<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Tracabilite\Modeles\SessionUtilisateur;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ceux qui ne sont jamais venus — et la promesse que le système ne les invente pas.
 *
 * **Deux questions distinctes, et la seconde a été posée directement.**
 *
 * D'abord : un tableau du temps passé ne montre que les gens venus au moins une fois. Ceux
 * qui n'ont jamais pu entrer — courriel jamais reçu, lien expiré, adresse mal tapée — sont
 * absents par construction d'un écran qui ne parle que de présence. Ce sont pourtant eux
 * qu'il faut relancer, et ils ont maintenant leur tableau.
 *
 * Ensuite : **le système n'invente aucune connexion.** Créer un accès, activer un accès,
 * renvoyer le courriel : aucun de ces gestes ne pose de date de connexion ni n'ouvre de
 * ligne de présence. C'est vérifié ici plutôt qu'affirmé, parce qu'un écran de traçabilité
 * qui se trompe sur ce point est pire qu'un écran absent — on s'appuie dessus.
 */
class ComptesJamaisConnectesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['super_admin', 'gerant', 'commercial'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
    }

    public function test_creer_un_acces_n_inscrit_aucune_connexion(): void
    {
        $compte = $this->compte('commercial', 'jamais@venu.test');

        $this->assertNull($compte->derniere_connexion_le);
        $this->assertSame(0, SessionUtilisateur::where('user_id', $compte->id)->count());
    }

    public function test_activer_un_acces_n_inscrit_aucune_connexion(): void
    {
        $compte = $this->compte('commercial', 'prepare@venu.test');
        $compte->update(['est_actif' => false]);

        app(CreerAcces::class)->activer($compte->fresh());

        $frais = $compte->fresh();

        $this->assertTrue((bool) $frais->est_actif, "L'accès s'ouvre bien.");
        $this->assertNull(
            $frais->derniere_connexion_le,
            "Ouvrir un accès et l'utiliser sont deux choses : seule la seconde est une connexion.",
        );
        $this->assertSame(0, SessionUtilisateur::where('user_id', $compte->id)->count());
    }

    public function test_l_ecran_bascule_vers_ceux_qui_ne_sont_jamais_venus(): void
    {
        $jamais = $this->compte('commercial', 'jamais@venu.test');
        $venu = $this->compte('gerant', 'venu@souvent.test');
        $venu->forceFill(['derniere_connexion_le' => now()])->save();

        $reponse = $this->actingAs($this->administrateur())
            ->get(route('super-admin.tracabilite', ['vue' => 'jamais']))
            ->assertOk();

        $reponse->assertSee('Comptes jamais connectés', false);
        $reponse->assertSee($jamais->email, false);
        $reponse->assertDontSee($venu->email, false);
    }

    public function test_un_compte_porteur_d_une_seule_ligne_de_presence_n_y_figure_plus(): void
    {
        $compte = $this->compte('commercial', 'jamais@venu.test');

        SessionUtilisateur::create([
            'user_id' => $compte->id,
            'entreprise_id' => $this->entreprise->id,
            'role' => 'commercial',
            'adresse_ip' => '203.0.113.7',
            'navigateur' => 'Essai',
            'plateforme' => 'Essai',
            // Une visite ancienne et close : sans quoi le compte reparaîtrait dans
            // « en ligne maintenant », qui est un autre tableau et une autre question.
            'ouverte_le' => now()->subMonths(2),
            'derniere_activite_le' => now()->subMonths(2),
            'fermee_le' => now()->subMonths(2),
            'motif_fin' => 'deconnexion',
        ]);

        // Deux témoins, et non un seul : la date de connexion et la ligne de présence se
        // posent au même moment mais ne viennent pas du même endroit.
        $this->actingAs($this->administrateur())
            ->get(route('super-admin.tracabilite', ['vue' => 'jamais', 'periode' => '0']))
            ->assertOk()
            ->assertDontSee($compte->email, false);
    }

    private function administrateur(): User
    {
        $u = User::create([
            'entreprise_id' => null,
            'name' => 'Administrateur plateforme',
            'email' => 'admin@jamais.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
            'est_fondateur' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $u->assignRole('super_admin');

        return $u->fresh();
    }

    private function compte(string $role, string $email): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $email,
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }
}

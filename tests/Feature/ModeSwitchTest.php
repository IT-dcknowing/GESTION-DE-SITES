<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Tracabilite\Modeles\SessionUtilisateur;
use Modules\SuperAdmin\Services\ModeSwitch;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Assister quelqu'un sous son identité — sans que le système raconte une connexion.
 *
 * **La propriété centrale de ces tests est celle-ci** : entrer dans le compte de quelqu'un
 * ne doit inscrire à son nom ni date de connexion, ni ligne de présence, ni visite d'écran.
 * Sans cette garantie, l'écran de traçabilité — dont l'unique objet est de dire qui est
 * entré — se mettrait à mentir, et l'on s'appuierait dessus. Un journal qui invente une
 * connexion est pire qu'un journal absent.
 *
 * Les autres tests couvrent le reste du contrat : on revient toujours, un accès révoqué
 * reste fermé même pour l'assistance, et personne d'autre qu'un administrateur de la
 * plateforme ne peut entrer.
 */
class ModeSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'gerant', 'commercial'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
    }

    public function test_le_passage_n_inscrit_aucune_connexion_au_compte_assiste(): void
    {
        $administrateur = $this->administrateur();
        $gerant = $this->gerant();

        $this->actingAs($administrateur)
            ->post(route('super-admin.switch.entrer', $gerant->id))
            ->assertRedirect('/');

        $this->assertSame(
            0,
            SessionUtilisateur::where('user_id', $gerant->id)->count(),
            "Une assistance n'est pas une connexion : le compte assisté n'a pas ouvert l'application.",
        );

        $this->assertNull(
            $gerant->fresh()->derniere_connexion_le,
            "La date de dernière connexion appartient à son titulaire, pas à celui qui l'assiste.",
        );
    }

    public function test_on_devient_l_autre_et_l_on_peut_revenir(): void
    {
        $administrateur = $this->administrateur();
        $gerant = $this->gerant();

        $this->actingAs($administrateur)->post(route('super-admin.switch.entrer', $gerant->id));

        $this->assertSame($gerant->id, auth()->id(), 'On voit bien ce que voit la personne assistée.');
        $this->assertTrue(ModeSwitch::enCours());
        $this->assertSame($administrateur->id, ModeSwitch::origine()?->id);

        $this->post(route('super-admin.switch.sortir'))->assertRedirect(route('super-admin.acces.index'));

        $this->assertSame($administrateur->id, auth()->id(), "Sans retour, on resterait enfermé dans le compte.");
        $this->assertFalse(ModeSwitch::enCours());
    }

    public function test_le_bandeau_barre_l_ecran_pendant_le_detour(): void
    {
        $administrateur = $this->administrateur();
        $gerant = $this->gerant();

        // La session est posée sur la requête plutôt qu'héritée du passage : en test, le
        // magasin de session est en mémoire et ne survit pas d'une requête à l'autre.
        // C'est bien le bandeau qu'on éprouve ici, pas la persistance de la session.
        $this->actingAs($gerant)
            ->withSession([
                ModeSwitch::CLE_ORIGINE => $administrateur->id,
                ModeSwitch::CLE_DEPUIS => now()->toIso8601String(),
            ])
            ->followingRedirects()
            ->get('/')
            ->assertOk()
            ->assertSee('Mode switch actif', false)
            ->assertSee($gerant->name, false)
            ->assertSee('Quitter le mode switch', false)
            ->assertSee($administrateur->name, false);
    }

    public function test_un_acces_revoque_reste_ferme_meme_pour_l_assistance(): void
    {
        $ferme = $this->gerant();
        $ferme->update(['est_actif' => false]);

        $this->assertNotNull(
            ModeSwitch::motifDuRefus($this->administrateur(), $ferme->fresh()),
            "Une révocation qui s'ouvre pour l'un ne veut plus rien dire.",
        );
    }

    public function test_seul_un_administrateur_de_la_plateforme_entre_dans_un_compte(): void
    {
        $gerant = $this->gerant();
        $autre = $this->compte('commercial', 'commercial@switch.test');

        // Le filtre de rôle renvoie vers l'accueil plutôt que d'afficher une erreur :
        // la personne n'a rien fait de mal, elle a suivi une adresse qui ne la regarde pas.
        $this->actingAs($gerant)
            ->post(route('super-admin.switch.entrer', $autre->id))
            ->assertRedirect();

        $this->assertSame($gerant->id, auth()->id());
    }

    public function test_la_sortie_ne_fait_rien_quand_personne_n_est_en_detour(): void
    {
        $this->actingAs($this->gerant())
            ->post(route('super-admin.switch.sortir'))
            ->assertRedirect('/');
    }

    private function administrateur(): User
    {
        $u = User::create([
            'entreprise_id' => null,
            'name' => 'Administrateur plateforme',
            'email' => 'admin@switch.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
            'est_fondateur' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $u->assignRole('super_admin');

        return $u->fresh();
    }

    private function gerant(): User
    {
        return $this->compte('gerant', 'gerant@switch.test');
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

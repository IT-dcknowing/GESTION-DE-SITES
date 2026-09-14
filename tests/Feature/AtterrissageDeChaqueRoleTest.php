<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chaque rôle doit atterrir quelque part — et ce test les parcourt tous.
 *
 * **Le défaut qu'il répare.** `RedirectionController` aiguille vers un écran d'accueil
 * selon le rôle, et son `default` **déconnecte** : un compte dont le rôle n'est pas prévu
 * se voit renvoyé vers la page de connexion avec « aucun rôle associé ». C'est le bon
 * comportement pour un compte réellement sans rôle. C'en est un très mauvais pour un rôle
 * qu'on vient d'ajouter et qu'on a oublié d'inscrire ici : la personne ne peut plus se
 * connecter du tout, et rien n'indique pourquoi.
 *
 * C'est arrivé au responsable commercial. Le rôle existait, ses écrans étaient ouverts,
 * son bandeau était prêt — et la connexion se terminait sur la page de connexion.
 *
 * **Pourquoi ce test part de la liste et non d'une énumération écrite à la main.** Une
 * liste recopiée oublierait le prochain rôle exactement comme l'aiguillage l'a oublié. Il
 * part donc de `ProvisionneurEntreprise::ROLES`, la source unique : ajouter un rôle sans
 * lui donner d'atterrissage fait échouer ce test le jour même.
 */
class AtterrissageDeChaqueRoleTest extends TestCase
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

    public function test_aucun_role_n_est_deconnecte_a_l_atterrissage(): void
    {
        foreach (ProvisionneurEntreprise::ROLES as $role) {
            $compte = $this->compte($role);

            $reponse = $this->actingAs($compte)->get(route('redirection'));

            $reponse->assertRedirect();

            /*
             * L'assertion qui compte. Le « default » de l'aiguillage déconnecte : si le
             * rôle n'y est pas prévu, la session tombe ici, et la personne ne peut plus
             * entrer dans l'application quoi qu'elle fasse.
             */
            $this->assertTrue(
                auth()->check(),
                "Le rôle « $role » a été déconnecté à l'atterrissage : son cas n'est pas prévu par l'aiguillage.",
            );

            $this->assertStringNotContainsString(
                'connexion',
                (string) $reponse->headers->get('Location'),
                "Le rôle « $role » retombe sur la page de connexion : son atterrissage n'est pas prévu.",
            );

            auth()->guard('web')->logout();
        }
    }

    public function test_chaque_role_atterrit_sur_un_ecran_qui_lui_est_ouvert(): void
    {
        foreach (ProvisionneurEntreprise::ROLES as $role) {
            $compte = $this->compte($role);

            /*
             * Atterrir n'est pas suffisant : il faut atterrir sur une page ouverte. Un
             * aiguillage qui renvoie vers un écran fermé au rôle donne un refus au premier
             * geste, ce qui se lit comme une panne.
             */
            $this->actingAs($compte)
                ->followingRedirects()
                ->get(route('redirection'))
                ->assertStatus(200, "Le rôle « $role » atterrit sur un écran qui lui est refusé.");

            auth()->guard('web')->logout();
        }
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('password'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            // Sans cela, le middleware du premier mot de passe détourne tout vers son
            // propre écran, et le test ne mesurerait plus l'aiguillage.
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'écran de connexion se rend, et garde toutes ses portes.
 *
 * **Pourquoi ce test manquait, et pourquoi il fallait l'écrire.** Aucun test n'ouvrait
 * `/login`. La page est pourtant la seule que tout le monde traverse, et la seule qu'on ne
 * peut pas contourner : une erreur de vue y ferme l'application entière, pour tout le monde,
 * sans qu'aucune autre suite ne s'en aperçoive.
 *
 * **Ce qu'il verrouille, au-delà du code 200.**
 *
 *   - **Les trois portes d'entrée** : le formulaire vers Fortify, l'entrée par Google, et la
 *     création de compte. Une page de connexion qui perd une de ses portes enferme quelqu'un
 *     dehors — et c'est exactement ce qu'une refonte visuelle fait perdre en premier.
 *   - **Le jeton CSRF**, sans lequel toute connexion échoue en 419 sur un message que
 *     personne ne sait lire.
 *   - **Les images détourées du rendu**. Elles ont été refaites le 29/09 après que le
 *     propriétaire eut relevé que les outils n'étaient pas les bons ; ce test dit que la page
 *     les demande, et le suivant qu'elles existent sur le disque.
 */
class LEcranDeConnexionSeRendEnEntierTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_s_ouvre_avec_ses_trois_portes(): void
    {
        $reponse = $this->get(route('login'));

        $reponse->assertOk()
            ->assertSee('Ravis de vous revoir', false)
            // Le formulaire, et de quoi le poster.
            ->assertSeeHtml('action="'.route('login').'"')
            ->assertSeeHtml('name="_token"')
            ->assertSeeHtml('name="email"')
            ->assertSeeHtml('name="password"')
            // Les deux autres portes.
            ->assertSeeHtml(route('auth.google'))
            ->assertSeeHtml(route('inscription.personnel'))
            ->assertSeeHtml(route('password.request'));
    }

    /**
     * Le libellé du champ promet ce que l'application tient, et rien de plus.
     *
     * Le rendu de la maquette porte « Email ou identifiant ». L'application n'authentifie que
     * sur l'adresse — `config/fortify.php` déclare `'username' => 'email'`, et
     * `FortifyServiceProvider` cherche l'utilisateur par `where('email', …)`. Promettre un
     * identifiant ferait essayer un code d'atelier, qui ne peut qu'échouer sans dire pourquoi.
     */
    public function test_le_champ_ne_promet_pas_un_identifiant_que_fortify_ignore(): void
    {
        $this->assertSame('email', config('fortify.username'));

        $this->get(route('login'))->assertDontSee('Email ou identifiant', false);
    }

    /** Les images de la page existent : un `src` qui pointe dans le vide ne se voit qu'à l'œil. */
    public function test_les_images_de_la_page_sont_sur_le_disque(): void
    {
        $page = $this->get(route('login'))->getContent();

        foreach (['atelier-logo.png', 'atelier-voiture.png', 'atelier-cles.png'] as $image) {
            $this->assertStringContainsString($image, $page, "La page doit demander {$image}.");
            $this->assertFileExists(public_path('logos/'.$image));
        }
    }
}

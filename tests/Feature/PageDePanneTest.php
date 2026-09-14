<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Ce qu'un visiteur voit quand l'application tombe.
 *
 * **L'incident qui a écrit ces tests.** Le serveur de production a affiché pendant des
 * heures la page de diagnostic de Laravel : la trace complète, les chemins du serveur, le
 * nom de la base, l'adresse interne de la machine et les en-têtes de chaque requête. Sur
 * une adresse publique, sans connexion. C'était une carte de reconnaissance offerte à qui
 * passait.
 *
 * Le détail n'a pas disparu pour autant — il est simplement passé derrière un bouton, et
 * derrière une identité. Ce que ces tests tiennent, c'est la frontière : la même page pour
 * tout le monde, le détail pour celui-là seul.
 */
class PageDePanneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le rendu s'efface pendant les tests, sinon chaque régression deviendrait un
        // « 500 » muet. Ici on le rallume : c'est lui qu'on vient éprouver.
        config(['app.rendre_la_page_de_panne_en_test' => true]);

        Route::get('/tests/panne', function () {
            throw new RuntimeException('SQLSTATE[HY000] [1045] Access denied for user');
        });

        Route::post('/tests/validation', function (Request $requete) {
            $requete->validate(['nom' => ['required']]);
        })->middleware('web');
    }

    public function test_un_visiteur_non_connecte_part_vers_la_connexion_et_non_vers_la_panne(): void
    {
        /*
         * Le filet attrape les pannes, pas les refus. Laravel évalue les rappels de rendu
         * **avant** de traiter l'authentification : un filet trop large renverrait une page
         * de panne à tout visiteur non connecté, c'est-à-dire à chaque première visite.
         */
        $reponse = $this->get(route('mon-profil'));

        $reponse->assertRedirect();
        $reponse->assertDontSee('Cette page est en maintenance');
    }

    public function test_un_formulaire_refuse_revient_avec_ses_messages(): void
    {
        // Même piège, autre famille : « ce champ est requis » n'est pas un incident.
        $this->post('/tests/validation', [])
            ->assertRedirect()
            ->assertSessionHasErrors('nom');
    }

    public function test_la_panne_s_affiche_en_page_de_maintenance(): void
    {
        $this->get('/tests/panne')
            ->assertStatus(500)
            ->assertSee('Cette page est en maintenance')
            ->assertSee('Interruption momentanée');
    }

    public function test_un_visiteur_ne_lit_rien_de_la_panne(): void
    {
        $reponse = $this->get('/tests/panne');

        // Ni le bouton, ni ce qu'il cache. C'est exactement ce qui fuyait.
        $reponse->assertDontSee('Voir le détail technique');
        $reponse->assertDontSee('Access denied for user');
        $reponse->assertDontSee('RuntimeException');
    }

    public function test_le_super_administrateur_deplie_le_detail_sur_place(): void
    {
        $reponse = $this->actingAs($this->fondateur())->get('/tests/panne');

        $reponse->assertSee('Voir le détail technique');
        $reponse->assertSee('Access denied for user');

        // Replié, pas affiché : le <details> est fermé tant qu'on ne clique pas, et il
        // n'y a pas une ligne de script derrière ce pliage.
        $this->assertStringContainsString('<details class="detail">', $reponse->getContent());
        $this->assertStringNotContainsString('<details class="detail" open', $reponse->getContent());
    }

    public function test_la_reference_montree_est_celle_ecrite_au_journal(): void
    {
        Log::spy();

        $reponse = $this->get('/tests/panne');

        $this->assertSame(1, preg_match('/ERR-[A-Z0-9]{6}/', $reponse->getContent(), $trouve));

        // Tout l'intérêt de la référence : « j'ai eu ERR-4F2A9C » suffit à retrouver la
        // ligne exacte dans le journal du serveur.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (...$arguments) => str_contains((string) $arguments[0], $trouve[0]));
    }

    public function test_la_page_ne_consulte_pas_la_base_pour_s_afficher(): void
    {
        /*
         * Le cas le plus fréquent est celui où la base est justement injoignable. Une page
         * d'erreur qui l'interroge ne s'afficherait pas du tout — et il ne resterait plus
         * aucune sortie.
         */
        // Les commentaires Blade sont retirés d'abord : l'enveloppe explique justement
        // qu'elle n'appelle ni @vite ni auth(), et cette phrase-là n'est pas un appel.
        $sansCommentaires = fn (string $chemin) => preg_replace(
            '/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path($chemin))
        );

        $gabarits = [
            $sansCommentaires('views/errors/enveloppe.blade.php'),
            $sansCommentaires('views/errors/500.blade.php'),
        ];

        foreach ($gabarits as $gabarit) {
            foreach (['@vite', 'auth()', '@livewire', "@extends('layouts"] as $interdit) {
                $this->assertStringNotContainsString($interdit, $gabarit);
            }
        }
    }

    public function test_une_adresse_inconnue_a_sa_propre_page(): void
    {
        // Une adresse inconnue n'est pas une panne : l'habiller en incident inquiéterait
        // pour rien.
        $this->get('/une-adresse-qui-n-existe-pas')
            ->assertStatus(404)
            ->assertSee("Cette page n'existe pas", false)
            ->assertDontSee('Cette page est en maintenance');
    }

    private function fondateur(): User
    {
        return User::create([
            'entreprise_id' => null,
            'name' => 'Super Admin',
            'email' => 'sa@exemple.test',
            'password' => Hash::make('password'),
            'est_actif' => true,
            'est_fondateur' => true,
        ]);
    }
}

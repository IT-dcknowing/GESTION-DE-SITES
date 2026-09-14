<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Aucun écran ne doit avoir une balise `<style>` pour racine de composant.
 *
 * **Le défaut que ce test empêche de revenir, et ce qu'il a coûté.** Livewire prend le
 * **premier élément** du rendu comme racine du composant : c'est lui qui porte `wire:id`,
 * et c'est à l'intérieur de lui seul que `wire:model`, `wire:click` et `wire:navigate`
 * sont branchés. Les coquilles du Recouvrement et de l'Import posaient leur feuille de
 * style avant leur bloc principal ; cette balise `<style>` devenait donc la racine, et
 * tout l'écran se retrouvait dehors.
 *
 * Quinze écrans étaient dans ce cas — tout le module Recouvrement, tout le module Import.
 * Les conséquences se voyaient à l'usage sans qu'on puisse les nommer : choisir un tiers
 * ne remplissait jamais la liste de ses factures, les boutons d'enregistrement ne
 * déclenchaient rien, les messages qu'on pouvait écarter revenaient. La page s'affichait
 * parfaitement — elle ne répondait simplement pas.
 *
 * **Rien ne le signalait**, et c'est ce qui rend ce test nécessaire plutôt que confortable :
 * ni le serveur, ni le navigateur, ni les tests Volt ne voient la différence, puisque du
 * côté serveur tout fonctionne. Seul le HTML rendu la porte.
 *
 * Le test balaie tous les écrans atteignables, pour tous les rôles, plutôt que d'énumérer
 * ceux qu'on soupçonne : le défaut vient d'une coquille partagée, et une coquille
 * s'applique à des écrans qu'on n'a pas en tête au moment où on l'écrit.
 */
class RacineDesComposantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les balises qui ne peuvent pas servir de racine à un composant.
     *
     * Elles ne contiennent pas de mise en page : soit elles ne portent que du texte brut
     * (`style`, `script`), soit elles sont vides par nature (`input`, `img`, `br`). Dans
     * les deux cas, ce que le composant est censé gérer se retrouve à côté d'elles, et non
     * dedans.
     */
    private const BALISES_INTERDITES = ['style', 'script', 'link', 'meta', 'br', 'hr', 'img', 'input'];

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_aucun_ecran_n_a_une_feuille_de_style_pour_racine(): void
    {
        $fautifs = [];
        $examines = 0;

        foreach (['gerant', 'responsable_ville', 'responsable_site', 'commercial', 'caissier',
            'superviseur_recouvrement', 'agent_recouvrement'] as $role) {
            $compte = $this->compte($role);

            foreach ($this->ecransSansParametre() as $nom) {
                $reponse = $this->actingAs($compte)->get(route($nom));

                if ($reponse->getStatusCode() !== 200) {
                    continue;
                }

                $examines++;

                foreach ($this->racines($reponse->getContent()) as $balise) {
                    if (in_array($balise, self::BALISES_INTERDITES, true)) {
                        $fautifs[$nom] = $balise;
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $examines, 'Le balayage doit couvrir les écrans, pas deux ou trois.');

        $this->assertSame([], $fautifs, "Ces écrans ont un composant dont la racine ne peut rien contenir — "
            ."tout ce qu'il est censé gérer se retrouve dehors, donc inerte : "
            .json_encode($fautifs, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------ utilitaires

    /** Les balises qui portent un `wire:snapshot`, c'est-à-dire les racines de composants. */
    private function racines(string $html): array
    {
        preg_match_all('#<([a-zA-Z0-9-]+)[^>]*\swire:snapshot=#', $html, $trouves);

        return array_map('strtolower', array_unique($trouves[1]));
    }

    /**
     * Les écrans qu'on peut ouvrir sans rien fournir.
     *
     * Ceux qui prennent un paramètre en sont écartés : leur coquille est la même, et
     * fabriquer une facture ou un lot pour chacun n'apprendrait rien de plus.
     */
    private function ecransSansParametre(): array
    {
        $exclus = ['password.confirm', 'logout', 'storage.local', 'entreprise.logo',
            'import.traitements.etat', 'auth.google', 'auth.callback', 'login', 'connexion'];

        return collect(Route::getRoutes())
            ->filter(fn ($route) => $route->getName()
                && in_array('GET', $route->methods(), true)
                && ! $route->parameterNames()
                && ! str_contains($route->uri(), 'livewire')
                && ! str_starts_with($route->uri(), '_')
                && ! in_array($route->getName(), $exclus, true))
            ->map(fn ($route) => $route->getName())
            ->unique()->values()->all();
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@racines.test',
            'password' => Hash::make('motdepasse123'),
            'est_actif' => true,
        ]);
        $compte->assignRole($role);

        if ($role === 'responsable_site') {
            $this->site->forceFill(['responsable_id' => $compte->id])->save();
        }

        return $compte->fresh();
    }
}

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
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'affichage général — 14/10 : chaque commercial voit les prospections de tous, pour se motiver
 * et ne pas refaire celles d'un collègue. Ni les brouillons, ni les notes de travail n'y paraissent.
 */
class LAffichageGeneralMontreLesProspectionsDeTousTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $abidjan;

    private Ville $bouake;

    private int $rang = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->abidjan = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
    }

    public function test_un_commercial_voit_les_prospections_des_autres_villes_sans_leurs_notes_ni_leurs_brouillons(): void
    {
        [$moi, $maFiche] = $this->commercial('Awa Koné', $this->abidjan);
        [, $collegue] = $this->commercial('Yao Kouassi', $this->bouake);

        $this->prospection($collegue, 'SOTRA TRANSPORT', 'AB-123-CD', 'Validée', 'note privée du collègue');
        $this->prospection($collegue, 'CLIENT BROUILLON', null, 'Brouillon');
        $this->prospection($maFiche, 'Sotra Transport', null, 'Transmise');

        Volt::actingAs($moi)->test('commercial.affichage-general')
            ->assertSee('SOTRA TRANSPORT')
            ->assertSee('Yao Kouassi')
            ->assertSee('Oui — 2 fois', false)
            ->assertDontSee('CLIENT BROUILLON')
            ->assertDontSee('note privée du collègue')
            ->assertSee('Le classement');
    }

    public function test_la_recherche_porte_sur_tout_l_historique(): void
    {
        [$moi] = $this->commercial('Awa Koné', $this->abidjan);
        [, $collegue] = $this->commercial('Yao Kouassi', $this->bouake);

        $this->prospection($collegue, 'NSIA ASSURANCES', 'AA-111-BB', 'Validée', null, now()->subMonths(5)->toDateString());

        $ecran = Volt::actingAs($moi)->test('commercial.affichage-general')->assertDontSee('NSIA ASSURANCES');

        $ecran->set('recherche', 'nsia')
            ->assertSee('NSIA ASSURANCES')
            ->assertSee('Déjà prospecté');

        $ecran->set('recherche', 'aa 111 bb')->assertSee('NSIA ASSURANCES');
    }

    public function test_la_page_est_ouverte_a_ceux_qui_vendent_et_au_gerant_seulement(): void
    {
        [$moi] = $this->commercial('Awa Koné', $this->abidjan);
        $this->actingAs($moi)->get('/affichage-general')->assertOk();

        $this->actingAs($this->compte('caissier', 'caisse@alpha.test'))->get('/affichage-general')->assertRedirect();
        $this->actingAs($this->compte('gerant', 'gerant@alpha.test'))->get('/affichage-general')->assertOk();
    }

    /** @return array{0: User, 1: Commercial} */
    private function commercial(string $nom, Ville $ville): array
    {
        $compte = $this->compte('commercial', strtolower(str_replace(' ', '.', \Illuminate\Support\Str::ascii($nom))).'@alpha.test', $ville);
        $fiche = Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $ville->id, 'user_id' => $compte->id,
            'numero' => 'C-'.(++$this->rang), 'nom' => $nom,
        ]);

        return [$compte, $fiche];
    }

    private function prospection(Commercial $fiche, string $client, ?string $plaque, string $statut, ?string $observations = null, ?string $date = null): Prospection
    {
        $site = Site::withoutGlobalScopes()->firstOrCreate(
            ['entreprise_id' => $this->e->id, 'ville_id' => $fiche->ville_id, 'code' => 'S'.$fiche->ville_id],
            ['nom' => 'Site '.$fiche->ville_id, 'est_actif' => true],
        );

        return Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $site->id, 'commercial_id' => $fiche->id,
            'numero' => 'P-'.(++$this->rang), 'date' => $date ?? now()->toDateString(), 'client' => $client,
            'immatriculation' => $plaque, 'activite' => 'Sinistre', 'statut_validation' => $statut, 'observations' => $observations,
        ]);
    }

    private function compte(string $role, string $email, ?Ville $ville = null): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Compte '.$role, 'email' => $email,
            'password' => Hash::make('motdepasse123'), 'ville_id' => ($ville ?? $this->abidjan)->id, 'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

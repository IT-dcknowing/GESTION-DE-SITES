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
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\CompteComptable;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Services\PlanComptable;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le paramétrage des écritures comptables, et la liste de tous les clients à affecter — 27/10.
 */
class LesEcrituresComptablesSeParametrentTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_les_comptes_se_proposent_puis_s_enregistrent(): void
    {
        $gerant = $this->compte('gerant', 'gerant');
        $this->actingAs($gerant);
        Banque::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'nom' => 'BGFI', 'nom_normalise' => Banque::clePour('BGFI')]);

        // Proposés tant que rien n'est enregistré ; la banque déclarée a son compte et son journal.
        $this->assertSame('4111', PlanComptable::numero('clients'));
        $this->assertSame('5211', PlanComptable::numero('banque:'.Banque::query()->value('id')));

        $ecran = Volt::test('comptabilite.ecritures-parametrage')->assertSee('proposé');
        $rang = array_search('clients', $ecran->get('cles'), true);

        $ecran->set("valeurs.{$rang}.numero", '41A')->call('enregistrer')->assertHasErrors("valeurs.{$rang}.numero");

        $ecran->set("valeurs.{$rang}.numero", '411100')->set("valeurs.{$rang}.libelle", 'Clients divers')
            ->call('enregistrer')->assertHasNoErrors();

        $this->assertSame('411100', CompteComptable::query()->where('cle', 'clients')->value('numero'));
        $this->assertSame('411100', PlanComptable::numero('clients'));
    }

    public function test_le_commercial_n_y_entre_pas(): void
    {
        $this->actingAs($this->compte('commercial', 'awa'))->get(route('ecritures.parametrage'))->assertForbidden();
    }

    /** « Tous les clients, avec qui les a prospectés, et on les affecte à qui l'on veut. » */
    public function test_tous_les_clients_s_affectent_a_qui_l_on_veut(): void
    {
        $gerant = $this->compte('gerant', 'gerant');
        $a = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'numero' => 'C-1', 'nom' => 'Awa']);
        $b = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'numero' => 'C-2', 'nom' => 'Koffi']);
        Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'commercial_id' => $a->id, 'numero' => 'P-1',
            'date' => now()->toDateString(), 'client' => 'Garage Bon Prix', 'localisation' => 'Cocody', 'moyen' => 'RDV',
            'activite' => 'Mécanique', 'passage' => false, 'devis_apres_passage' => false, 'statut_validation' => 'Brouillon',
        ]);

        $ecran = Volt::actingAs($gerant)->test('pilotage.portefeuilles-clients');
        $ligne = $ecran->instance()->annuaire['lignes']->firstWhere('nom', 'Garage Bon Prix');
        $this->assertSame(['Awa'], $ligne->prospecte_par);

        // Prospecté par Awa, affecté à Koffi : il reste dans la liste, suivi par Koffi.
        $cle = md5($ligne->cle);
        $ecran->set("choix.{$cle}", (string) $b->id)->call('affecterA', 'Garage Bon Prix', $cle);

        $apres = $ecran->instance()->annuaire['lignes']->firstWhere('nom', 'Garage Bon Prix');
        $this->assertSame('Koffi', $apres->suivi_par);
        $this->assertSame(['Awa'], $apres->prospecte_par);
    }

    private function compte(string $role, string $nom): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($nom), 'email' => $nom.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

<?php

namespace Tests\Feature;

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un devis après passage implique un passage à la même date (impossible de faire un
 * devis sans être passé). Chaque modification d'une prospection doit rester
 * consultable : qui a changé quoi, et quand.
 */
class ProspectionCoherenceEtHistoriqueTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    private User $responsable;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->responsable = User::create([
            'name' => 'Responsable', 'email' => 'resp@exemple.test', 'password' => 'x',
            'entreprise_id' => $this->entreprise->id, 'est_actif' => true,
        ]);
        $this->responsable->assignRole('responsable_ville');

        $ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'responsable_id' => $this->responsable->id]);
        $this->site = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'nom' => 'Abidjan']);

        $this->commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'numero' => 'C-0001', 'nom' => 'Commercial Test',
        ]);
    }

    public function test_devis_apres_passage_force_le_passage_a_la_meme_date(): void
    {
        $this->actingAs($this->responsable);

        Volt::test('saisie.saisie-du-jour')
            ->set('prosClient', 'Garage X')
            ->set('prosCommercialId', $this->commercial->id)
            ->set('prosDevisApres', true)
            ->set('prosDateDevis', '2026-08-10')
            // Depuis le 22/09/2026, déclarer un passage en devis oblige à en donner le
            // numéro : c'est l'instant où le devis existe, et le donner alors évite tout le
            // rapprochement qui suivrait.
            ->set('prosNDevis', 'PR-MT-11434')
            ->call('ajouterProspection')
            ->assertHasNoErrors();

        $p = Prospection::where('client', 'Garage X')->firstOrFail();

        $this->assertTrue($p->passage);
        $this->assertSame('2026-08-10', $p->date_passage->toDateString());
        $this->assertTrue($p->devis_apres_passage);
        $this->assertSame('2026-08-10', $p->date_devis->toDateString());
        $this->assertSame('PR-MT-11434', $p->n_devis);
    }

    public function test_un_passage_en_devis_sans_son_numero_est_refuse(): void
    {
        $this->actingAs($this->responsable);

        Volt::test('saisie.saisie-du-jour')
            ->set('prosClient', 'Garage Y')
            ->set('prosCommercialId', $this->commercial->id)
            ->set('prosDevisApres', true)
            ->set('prosDateDevis', '2026-08-10')
            ->set('prosNDevis', '')
            ->call('ajouterProspection')
            ->assertHasErrors('prosNDevis');

        $this->assertNull(Prospection::where('client', 'Garage Y')->first());
    }

    public function test_la_modification_est_journalisee_avec_l_auteur_et_visible_dans_le_detail(): void
    {
        $p = Prospection::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id, 'numero' => 'P-0001',
            'date' => now()->toDateString(), 'client' => 'Ancien client', 'moyen' => 'RDV',
            'activite' => 'Mécanique', 'passage' => false, 'devis_apres_passage' => false,
            'statut_validation' => 'Validée',
        ]);

        $this->actingAs($this->responsable);

        Volt::test('saisie.saisie-du-jour')
            ->call('modifierProspection', $p->id)
            ->set('editionProsClient', 'Nouveau client')
            ->call('enregistrerEditionProspection')
            ->assertHasNoErrors();

        $p->refresh();
        $this->assertSame('Nouveau client', $p->client);

        $activite = $p->activities()->latest('id')->first();
        $this->assertNotNull($activite, 'La modification aurait dû être journalisée.');
        $this->assertSame($this->responsable->id, $activite->causer_id);
        $this->assertSame('Nouveau client', $activite->properties->get('attributes')['client']);
        $this->assertSame('Ancien client', $activite->properties->get('old')['client']);

        // Le détail — et son historique — vit désormais sur sa propre page de lecture.
        $this->get(route('prospection.voir', $p))
            ->assertOk()
            ->assertSee('Nouveau client')
            ->assertSee($this->responsable->name);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Http\Controllers\DepotController;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le dépôt d'un fichier qui n'a été filtré sur aucune ville.
 *
 * **Ce que ces tests fixent.** Tous les exports du logiciel ne se filtrent pas par lieu, et
 * certains ne se filtreront jamais : ils sortent d'un bloc, les trois villes mêlées. Pour
 * ceux-là, « Toutes les villes » rend la main aux codes employés — la cascade de
 * rattachement était déjà écrite pour ça, la ville du dépôt n'en étant que le dernier
 * recours.
 *
 * Deux propriétés, et la seconde est celle qui protège :
 *
 * - déposer sans déclarer de ville doit aboutir, et le lot ne porter **aucune** ville ni
 *   aucun atelier — sans quoi une déclaration fantôme rangerait des lignes ailleurs ;
 * - l'option n'est ouverte qu'à qui dépose pour plusieurs villes. Un responsable de ville
 *   qui s'en servirait écrirait hors de son périmètre, ce que sa liste déroulante lui
 *   refuse par ailleurs — et une liste déroulante n'a jamais fermé une requête.
 */
class DepotToutesVillesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $siteUn;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['gerant', 'responsable_ville', 'responsable_site', 'caissier'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->siteUn = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'ABJ-1', 'nom' => 'Site 1', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'ABJ-2', 'nom' => 'Site 2', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouake', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id, 'code' => 'BOU', 'nom' => 'Bouake', 'est_actif' => true]);
    }

    public function test_le_gerant_peut_deposer_sans_declarer_de_ville(): void
    {
        Queue::fake();

        $this->actingAs($this->compte('gerant'))->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => DepotController::TOUTES_LES_VILLES,
        ])->assertRedirect();

        $lot = LotImport::withoutGlobalScopes()->first();

        $this->assertNotNull($lot, 'Un fichier non filtré doit pouvoir entrer.');
        $this->assertNull($lot->ville_id, "Rien ne doit être déclaré : c'est le contenu qui tranchera.");
        Queue::assertPushed(TraiterUnLot::class);
    }

    public function test_un_atelier_declare_avec_toutes_les_villes_est_ignore(): void
    {
        Queue::fake();

        $this->actingAs($this->compte('gerant'))->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => DepotController::TOUTES_LES_VILLES,
            'site' => (string) $this->siteUn->id,
        ]);

        $lot = LotImport::withoutGlobalScopes()->first();

        $this->assertNotNull($lot);
        $this->assertNull(
            $lot->site_id,
            "« Toutes les villes » et « Site 1 » se contredisent : c'est la première déclaration qui tient.",
        );
    }

    public function test_un_responsable_d_une_seule_ville_ne_depose_pas_pour_toutes(): void
    {
        $compte = $this->compte('responsable_ville', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($compte)->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => DepotController::TOUTES_LES_VILLES,
        ])->assertSessionHasErrors('ville');

        $this->assertNull(
            LotImport::withoutGlobalScopes()->first(),
            "L'option ne s'ouvre qu'à qui dépose déjà pour plusieurs villes.",
        );
    }

    public function test_l_ecran_propose_l_option_au_gerant_et_la_tait_aux_autres(): void
    {
        $this->actingAs($this->compte('gerant'))
            ->get(route('import.depot'))
            ->assertOk()
            ->assertSee('Toutes les villes', false);

        $this->actingAs($this->compte('responsable_ville', ['ville_id' => $this->abidjan->id]))
            ->get(route('import.depot'))
            ->assertOk()
            ->assertDontSee('Toutes les villes — fichier non filtré', false);
    }

    private function parc(): UploadedFile
    {
        return new UploadedFile(
            base_path('PLAN/MODULE-2/Abidjan_Situation du parc190826.xls'),
            'Abidjan_Situation du parc190826.xls', null, null, true,
        );
    }

    private function compte(string $role, array $extra = []): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $role.'@toutes.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ] + $extra);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }
}

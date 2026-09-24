<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\FormatDeLaBalanceFournisseur;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Formats\FormatDesImpayes;
use Modules\Noyau\Imports\Formats\FormatDuParc;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\ControlePrealable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le dépôt en formulaire HTTP — le chemin dont tout le reste dépend.
 *
 * Ces tests fixent la propriété qui manquait : **le dépôt ne doit rien devoir à la couche
 * interactive.** Une requête POST ordinaire, avec un fichier, suffit à déclencher l'import.
 */
class DepotFormulaireTest extends TestCase
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

    public function test_un_post_ordinaire_suffit_a_deposer(): void
    {
        Queue::fake();

        $reponse = $this->actingAs($this->gerant())->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => (string) $this->abidjan->id,
            'site' => (string) $this->siteUn->id,
        ]);

        $lot = LotImport::withoutGlobalScopes()->first();

        $this->assertNotNull($lot, 'Le dépôt doit aboutir sans la moindre ligne de JavaScript.');
        // On atterrit sur la page des traitements : c'est elle qui suit la lecture depuis
        // le 24/09, et non plus l'écran de dépôt, qui montrait la même barre.
        $reponse->assertRedirect(route('import.traitements', ['lot' => $lot->id]));
        $this->assertSame($this->siteUn->id, $lot->site_id);
        Queue::assertPushed(TraiterUnLot::class);
    }

    public function test_un_mauvais_type_est_signale_avant_toute_ecriture(): void
    {
        Queue::fake();

        $this->actingAs($this->gerant())->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'impayes',
            'ville' => (string) $this->abidjan->id,
        ])->assertRedirect();

        $this->assertNull(
            LotImport::withoutGlobalScopes()->first(),
            "Un fichier annoncé sous le mauvais type ne doit rien écrire tant qu'on n'a pas confirmé.",
        );
        $this->assertNotNull(session('import.controle'));
        Queue::assertNothingPushed();
    }

    public function test_on_peut_confirmer_sans_ressortir_le_fichier(): void
    {
        Queue::fake();
        $gerant = $this->gerant();

        $this->actingAs($gerant)->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'impayes',
            'ville' => (string) $this->abidjan->id,
        ]);

        // Le second envoi ne porte aucun fichier : c'est celui mis de côté qui repart.
        $this->actingAs($gerant)->post(route('import.deposer'), [
            'confirme' => '1',
            'format' => 'impayes',
            'ville' => (string) $this->abidjan->id,
        ])->assertRedirect();

        $this->assertNotNull(LotImport::withoutGlobalScopes()->first());
        $this->assertNull(session('import.controle'), 'La mise de côté doit être relâchée après usage.');
    }

    public function test_on_ne_depose_pas_pour_la_ville_d_un_autre(): void
    {
        $compte = $this->compte('responsable_site', ['site_id' => $this->siteUn->id, 'ville_id' => $this->abidjan->id]);

        $this->actingAs($compte)->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => (string) $this->bouake->id,
        ])->assertSessionHasErrors('ville');

        $this->assertNull(LotImport::withoutGlobalScopes()->first());
    }

    public function test_un_atelier_d_une_autre_ville_est_ecarte_sans_faire_echouer_le_depot(): void
    {
        Queue::fake();

        $this->actingAs($this->gerant())->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => (string) $this->bouake->id,
            'site' => (string) $this->siteUn->id,
        ]);

        $lot = LotImport::withoutGlobalScopes()->first();

        $this->assertNotNull($lot);
        $this->assertNull($lot->site_id, "Un atelier d'Abidjan n'a rien à faire dans un dépôt pour Bouaké.");
    }

    public function test_le_caissier_ne_depose_pas(): void
    {
        $this->actingAs($this->compte('caissier'))->post(route('import.deposer'), [
            'fichier' => $this->parc(),
            'format' => 'parc',
            'ville' => (string) $this->abidjan->id,
        ]);

        $this->assertNull(LotImport::withoutGlobalScopes()->first());
    }

    /**
     * L'exercice déclaré et l'année du fichier doivent concorder.
     *
     * **Demandé par le propriétaire le 24/09** : « toute activité doit être dans
     * l'exercice en cours ; le jour où je fais un import dans un exercice qui ne
     * correspond pas à l'année, le système doit émettre un message ». Le fichier de
     * référence porte 396 lignes de 2026 et 4 de 2025 : déposé sous 2025, il doit poser
     * la question.
     *
     * Il **avertit**, il ne bloque pas : une reprise de fin d'exercice se dépose
     * légitimement en janvier suivant, et refuser sur une ressemblance apprendrait surtout
     * à contourner le refus.
     */
    public function test_un_fichier_d_une_autre_annee_que_l_exercice_declare_pose_la_question(): void
    {
        $rapport = (new ControlePrealable($this->entreprise->id))->examiner(
            base_path('PLAN/MODULE-2/Abidjan_Situation du parc190826.xls'),
            'parc',
            $this->abidjan->id,
            exercice: 2025,
        );

        $this->assertSame(2026, $rapport['annee_du_fichier']);
        $this->assertNotEmpty($rapport['avertissements']);
        $this->assertStringContainsString('datent de 2026', implode(' ', $rapport['avertissements']));
    }

    public function test_le_bon_exercice_ne_provoque_aucune_question(): void
    {
        $rapport = (new ControlePrealable($this->entreprise->id))->examiner(
            base_path('PLAN/MODULE-2/Abidjan_Situation du parc190826.xls'),
            'parc',
            $this->abidjan->id,
            exercice: 2026,
        );

        $this->assertSame(2026, $rapport['annee_du_fichier']);
        $this->assertSame([], array_filter(
            $rapport['avertissements'],
            fn (string $texte) => str_contains($texte, 'exercice'),
        ));
    }

    public function test_l_etat_des_impayes_echappe_au_controle_de_l_exercice(): void
    {
        // Il porte un tableau initial de plusieurs années : lui reprocher de contenir 2024
        // reviendrait à lui reprocher d'être ce qu'il est. Exception posée par le
        // propriétaire lui-même, avec le suivi fournisseur et la balance.
        $this->assertFalse(FormatDesImpayes::porteUnSeulExercice());
        $this->assertFalse(FormatDesFournisseurs::porteUnSeulExercice());
        $this->assertFalse(FormatDeLaBalanceFournisseur::porteUnSeulExercice());

        // Et ceux qui décrivent une période, eux, sont contrôlés.
        $this->assertTrue(FormatDuParc::porteUnSeulExercice());
    }

    private function parc(): UploadedFile
    {
        return new UploadedFile(
            base_path('PLAN/MODULE-2/Abidjan_Situation du parc190826.xls'),
            'Abidjan_Situation du parc190826.xls', null, null, true,
        );
    }

    private function gerant(): User
    {
        return $this->compte('gerant');
    }

    private function compte(string $role, array $extra = []): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $role.'@alpha.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ] + $extra);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }
}

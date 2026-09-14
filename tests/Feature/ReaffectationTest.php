<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Services\ReaffecterUnEmploye;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La mutation d'un employé — ce qui bouge, et ce qui ne bouge surtout pas.
 *
 * Trois propriétés, et elles se tiennent :
 *
 * 1. la personne change de lieu ;
 * 2. son travail passé **reste où il a été fait** — sinon deux ateliers sont faussés d'un
 *    coup, celui qu'on vide et celui qu'on gonfle ;
 * 3. elle continue de **lire** son ancien lieu sans pouvoir y **écrire**.
 */
class ReaffectationTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $sanPedro;

    private Site $siteUn;

    private Site $siteSanPedro;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->siteUn = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A2', 'nom' => 'Site 2', 'est_actif' => true]);
        $this->sanPedro = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'SPY', 'nom' => 'San Pedro', 'est_actif' => true]);
        $this->siteSanPedro = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->sanPedro->id, 'code' => 'SP', 'nom' => 'San Pedro', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->gerant = $this->compte('gerant', 'g@alpha.test');
    }

    public function test_la_personne_change_de_lieu_mais_pas_son_travail(): void
    {
        $employe = $this->responsableDuSiteUn();
        $this->facture($this->siteUn->id, 400000);

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, $this->siteSanPedro->id, null, 'Renfort San Pédro', $this->gerant,
        );

        $employe->refresh();
        $this->assertSame($this->siteSanPedro->id, $employe->site_id);
        $this->assertSame($this->sanPedro->id, $employe->ville_id);

        // La facture n'a pas bougé d'un millimètre : c'est au Site 1 qu'elle a été faite.
        $this->assertSame($this->siteUn->id, (int) DB::table('factures')->value('site_id'));
    }

    public function test_l_ancien_atelier_reste_consultable_jamais_saisissable(): void
    {
        $employe = $this->responsableDuSiteUn();

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, $this->siteSanPedro->id, null, null, $this->gerant,
        );

        $employe = $employe->fresh();

        $consultables = PerimetreSites::sitesConsultables($employe)->pluck('id')->all();
        $this->assertContains($this->siteUn->id, $consultables, 'Son travail passé doit rester lisible.');
        $this->assertContains($this->siteSanPedro->id, $consultables);

        // Le périmètre d'écriture, lui, ne connaît que le poste actuel.
        $saisissables = Site::visiblesPour($employe)->pluck('id')->all();
        $this->assertNotContains($this->siteUn->id, $saisissables, "Il n'écrit plus dans l'atelier qu'il a quitté.");
    }

    public function test_le_code_du_logiciel_suit_la_personne(): void
    {
        $employe = $this->responsableDuSiteUn();

        $code = CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'KZ', 'libelle' => 'Keita Zeinab',
            'user_id' => $employe->id, 'ville_id' => $this->abidjan->id, 'site_id' => $this->siteUn->id,
            'occurrences' => 12, 'est_actif' => true,
        ]);

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, $this->siteSanPedro->id, null, null, $this->gerant,
        );

        $code->refresh();
        $this->assertSame($this->siteSanPedro->id, (int) $code->site_id);
        $this->assertSame($this->sanPedro->id, (int) $code->ville_id);
    }

    public function test_le_role_peut_changer_en_meme_temps_que_le_lieu(): void
    {
        $employe = $this->responsableDuSiteUn();

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, null, 'commercial', 'Passe au commerce', $this->gerant,
        );

        $this->assertTrue($employe->fresh()->hasRole('commercial'));
        $this->assertFalse($employe->fresh()->hasRole('responsable_site'));
    }

    public function test_on_ne_devient_pas_gerant_par_reaffectation(): void
    {
        $employe = $this->responsableDuSiteUn();

        $this->expectExceptionMessage('cet accès se crée');

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, null, 'gerant', null, $this->gerant,
        );
    }

    public function test_on_ne_reaffecte_pas_un_gerant(): void
    {
        $this->expectExceptionMessage('ne se réaffecte pas');

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $this->gerant, $this->sanPedro->id, null, 'commercial', null, $this->gerant,
        );
    }

    public function test_un_atelier_d_une_autre_ville_est_refuse(): void
    {
        $employe = $this->responsableDuSiteUn();

        $this->expectExceptionMessage("n'appartient pas à la ville choisie");

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, $this->siteUn->id, null, null, $this->gerant,
        );
    }

    public function test_l_histoire_est_gardee_entiere(): void
    {
        $employe = $this->responsableDuSiteUn();

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->sanPedro->id, $this->siteSanPedro->id, 'commercial', 'Ouverture du poste', $this->gerant,
        );

        $ligne = Reaffectation::withoutGlobalScopes()->first();

        $this->assertSame($this->siteUn->id, (int) $ligne->site_avant_id);
        $this->assertSame($this->siteSanPedro->id, (int) $ligne->site_apres_id);
        $this->assertSame('responsable_site', $ligne->role_avant);
        $this->assertSame('commercial', $ligne->role_apres);
        $this->assertSame('Ouverture du poste', $ligne->motif);
        $this->assertSame($this->gerant->id, (int) $ligne->decidee_par);
    }

    public function test_reaffecter_au_meme_endroit_est_refuse(): void
    {
        $employe = $this->responsableDuSiteUn();

        $this->expectExceptionMessage('déjà à cet endroit');

        (new ReaffecterUnEmploye($this->entreprise->id))->deplacer(
            $employe, $this->abidjan->id, $this->siteUn->id, null, null, $this->gerant,
        );
    }

    private function responsableDuSiteUn(): User
    {
        $employe = $this->compte('responsable_site', 'rs@alpha.test', [
            'ville_id' => $this->abidjan->id, 'site_id' => $this->siteUn->id,
        ]);

        $this->siteUn->update(['responsable_id' => $employe->id]);

        return $employe->fresh();
    }

    private function compte(string $role, string $email, array $extra = []): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Compte '.$role,
            'email' => $email, 'password' => 'motdepasse', 'est_actif' => true,
        ] + $extra);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }

    private function facture(int $siteId, int $montant): void
    {
        DB::table('factures')->insert([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $siteId,
            'numero' => 1, 'n_facture' => 'F1', 'date' => now()->toDateString(),
            'client' => 'LOXEA', 'type' => 'Facture', 'activite' => 'Carrosserie',
            'montant' => $montant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\ObjectifEntreprise;
use Modules\Noyau\Exploitation\Services\AccesAuxObjectifs;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un accès dit s'il est concerné par les objectifs de l'entreprise, et lesquels — 15/10 : à la
 * création ; et, pour les accès d'avant, au rattrapage du Super admin.
 */
class LesAccesDisentSIlsSontConcernesParLesObjectifsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private ObjectifEntreprise $semestre;

    private ObjectifEntreprise $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        $this->semestre = $this->objectif('Second semestre', [1_000_000, 2_000_000]);
        $this->annee = $this->objectif('Année', [5_000_000]);
    }

    public function test_a_la_creation_on_coche_les_objectifs_qui_concernent_l_acces(): void
    {
        Volt::actingAs($this->compte('gerant', 'g@alpha.test'))->test('pilotage.acces-creer')
            ->call('choisirRole', 'commercial')
            ->set('nom', 'Awa Koné')->set('email', 'awa@alpha.test')->set('motDePasse', 'motdepasse123')
            ->set('villeChoix', (string) $this->ville->id)
            ->set('concerneObjectifs', true)
            ->assertSee('Concerné par les objectifs de l')
            ->set('objectifsTous', '0')
            ->assertSee('Second semestre')
            ->set('objectifsChoisis', [(string) $this->semestre->id])
            ->call('creer')
            ->assertHasNoErrors();

        $awa = User::where('email', 'awa@alpha.test')->sole();
        $this->assertSame(['Second semestre'], AccesAuxObjectifs::objectifsDe($awa)->pluck('libelle')->all());
    }

    public function test_concerne_sans_objectif_coche_est_refuse_avant_de_creer_le_compte(): void
    {
        Volt::actingAs($this->compte('gerant', 'g@alpha.test'))->test('pilotage.acces-creer')
            ->call('choisirRole', 'commercial')
            ->set('nom', 'Awa Koné')->set('email', 'awa@alpha.test')->set('motDePasse', 'motdepasse123')
            ->set('villeChoix', (string) $this->ville->id)
            ->set('concerneObjectifs', true)->set('objectifsTous', '0')
            ->call('creer')
            ->assertHasErrors('objectifsChoisis');

        $this->assertNull(User::where('email', 'awa@alpha.test')->first());
    }

    public function test_un_acces_cree_sans_cocher_est_decide_non_concerne(): void
    {
        Volt::actingAs($this->compte('gerant', 'g@alpha.test'))->test('pilotage.acces-creer')
            ->call('choisirRole', 'commercial')
            ->set('nom', 'Awa Koné')->set('email', 'awa@alpha.test')->set('motDePasse', 'motdepasse123')
            ->set('villeChoix', (string) $this->ville->id)
            ->call('creer')->assertHasNoErrors();

        $awa = User::where('email', 'awa@alpha.test')->sole();
        $this->assertFalse((bool) AccesAuxObjectifs::decisionDe($awa)->concerne);
        $this->assertFalse(AccesAuxObjectifs::aRattraper($this->e->id)->contains('id', $awa->id), 'Décidé : rien à rattraper.');
    }

    public function test_le_rattrapage_du_super_admin_regle_les_acces_d_avant(): void
    {
        $ancien = $this->compte('commercial', 'ancien@alpha.test');
        $autre = $this->compte('caissier', 'caisse@alpha.test');

        $ecran = Volt::actingAs($this->superAdmin())->test('superadmin.acces-rattrapage')
            ->set('entrepriseId', (string) $this->e->id)
            ->assertSee('ancien@alpha.test')
            ->assertSee('À rattraper');

        $ecran->call('regler', $ancien->id)
            ->set('concerneObjectifs', true)->set('objectifsTous', '1')
            ->call('enregistrer')->assertHasNoErrors();

        $ecran->call('nonConcerne', $autre->id);

        $this->assertSame(['Second semestre', 'Année'], AccesAuxObjectifs::objectifsDe($ancien)->pluck('libelle')->sort()->reverse()->values()->all());
        $this->assertFalse((bool) AccesAuxObjectifs::decisionDe($autre)->concerne);
        $this->assertSame(0, AccesAuxObjectifs::aRattraper($this->e->id)->whereIn('id', [$ancien->id, $autre->id])->count());
    }

    public function test_un_objectif_d_une_autre_entreprise_est_refuse(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $etranger = ObjectifEntreprise::withoutGlobalScopes()->create([
            'entreprise_id' => $autre->id, 'libelle' => 'Beta', 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31',
        ]);

        $this->expectException(RuntimeException::class);
        AccesAuxObjectifs::enregistrer($this->compte('commercial', 'c@alpha.test'), true, false, [$etranger->id], null);
    }

    public function test_l_acces_concerne_voit_ses_objectifs_du_mois(): void
    {
        $compte = $this->compte('commercial', 'c@alpha.test');
        AccesAuxObjectifs::enregistrer($compte, true, false, [$this->semestre->id], null);

        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'numero' => 'F-1', 'n_facture' => 'FA -1',
            'date' => now()->startOfMonth()->toDateString(), 'client' => 'X', 'activite' => 'Mécanique', 'montant' => 1_500_000,
        ]);

        $suivi = AccesAuxObjectifs::suiviDuMois($compte);
        $this->assertCount(1, $suivi);
        $this->assertSame(1_500_000, $suivi[0]['realise']);
        $this->assertSame([1_000_000, 2_000_000], array_column($suivi[0]['tranches'], 'montant'));
    }

    private function objectif(string $libelle, array $montants): ObjectifEntreprise
    {
        $objectif = ObjectifEntreprise::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'libelle' => $libelle,
            'date_debut' => now()->startOfYear()->toDateString(), 'date_fin' => now()->endOfYear()->toDateString(),
            'activite' => $libelle === 'Année' ? 'Mécanique' : null,
        ]);

        foreach ($montants as $i => $montant) {
            $objectif->tranches()->create(['rang' => $i + 1, 'libelle' => 'T'.($i + 1), 'montant' => $montant]);
        }

        return $objectif;
    }

    private function compte(string $role, string $email): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Compte '.$role, 'email' => $email,
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }

    private function superAdmin(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(SuperAdminSeeder::EQUIPE_PLATEFORME);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'entreprise_id' => SuperAdminSeeder::EQUIPE_PLATEFORME]);

        $compte = User::create([
            'entreprise_id' => null, 'name' => 'Super Admin', 'email' => 'sa@exemple.test',
            'password' => Hash::make('password'), 'est_actif' => true, 'est_fondateur' => true,
        ]);
        $compte->assignRole('super_admin');

        return $compte->fresh();
    }
}

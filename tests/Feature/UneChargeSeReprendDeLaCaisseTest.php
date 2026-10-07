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
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Services\MouvementsDeTresorerie;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Une sortie du journal de caisse se reprend en charge — demandé le 07/10 (section 16).
 *
 * Ce que le test verrouille : la charge dit « Caisse » dans sa colonne Origine ; une sortie ne
 * se reprend qu'une fois ; une sortie sans atelier demande où la ranger ; et la liste des
 * décaissements de la trésorerie, qui lit le journal **et** les charges, ne la compte qu'une
 * fois.
 */
class UneChargeSeReprendDeLaCaisseTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_une_sortie_reprise_devient_une_charge_d_origine_caisse(): void
    {
        $sortie = $this->sortie('ACHAT FILTRE A HUILE', 45_000, $this->site->id);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.charges')
            ->call('ouvrirLaReprise')
            ->assertSee('ACHAT FILTRE A HUILE')
            ->set('repriseChoix', [(string) $sortie->id])
            ->set('repriseLibelle', 'Achats pièces')
            ->call('reprendreDeLaCaisse')
            ->assertHasNoErrors();

        $charge = Charge::withoutGlobalScopes()->sole();
        $this->assertSame($sortie->id, $charge->mouvement_caisse_id);
        $this->assertSame('Caisse', $charge->origine());
        $this->assertSame(45_000, $charge->montant);
        $this->assertSame('Achats pièces', $charge->libelle);

        // Elle quitte la liste, et ne se reprend pas une seconde fois.
        $this->assertSame(0, $ecran->instance()->nombreSortiesDeCaisse);
        $ecran->set('repriseChoix', [(string) $sortie->id])->call('reprendreDeLaCaisse');
        $this->assertSame(1, Charge::withoutGlobalScopes()->count());

        $ecran->assertSee('Origine');
    }

    public function test_une_sortie_sans_atelier_demande_ou_la_ranger(): void
    {
        $sortie = $this->sortie('AVANCE SUR SALAIRE', 30_000, null);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.charges')
            ->call('ouvrirLaReprise')
            ->set('repriseSiteId', '')
            ->set('repriseChoix', [(string) $sortie->id])
            ->call('reprendreDeLaCaisse')
            ->assertHasErrors('repriseSiteId');

        $this->assertSame(0, Charge::withoutGlobalScopes()->count());

        $ecran->set('repriseSiteId', (string) $this->site->id)->call('reprendreDeLaCaisse')->assertHasNoErrors();
        $this->assertSame($this->site->id, Charge::withoutGlobalScopes()->sole()->site_id);
    }

    public function test_les_decaissements_de_la_tresorerie_ne_la_comptent_qu_une_fois(): void
    {
        $sortie = $this->sortie('ACHAT PNEU', 60_000, $this->site->id);

        $this->actingAs($this->compte('gerant'));
        Charge::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'mouvement_caisse_id' => $sortie->id, 'date' => now()->toDateString(),
            'type_operation' => 'Charges', 'libelle' => 'Achats pièces', 'moyen' => 'Espèces', 'montant' => 60_000,
        ]);

        $union = MouvementsDeTresorerie::sorties(Charge::query()->toBase(), MouvementCaisse::query()->toBase());
        $this->assertSame(1, MouvementsDeTresorerie::compter($union));

        // Sans le journal, la charge paraît : elle est la seule trace de la sortie.
        $this->assertSame(1, MouvementsDeTresorerie::compter(MouvementsDeTresorerie::sorties(Charge::query()->toBase(), null)));
    }

    public function test_le_responsable_d_atelier_ne_reprend_pas(): void
    {
        $sortie = $this->sortie('ACHAT', 10_000, $this->site->id);

        Volt::actingAs($this->compte('responsable_site'))->test('pilotage.charges')
            ->set('repriseChoix', [(string) $sortie->id])
            ->call('reprendreDeLaCaisse')
            ->assertForbidden();

        $this->assertSame(0, Charge::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ le décor

    private function sortie(string $libelle, int $montant, ?int $siteId): MouvementCaisse
    {
        return MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $siteId,
            'date' => now()->toDateString(),
            'sens' => MouvementCaisse::SORTIE,
            'libelle' => $libelle,
            'montant' => $montant,
            'beneficiaire' => 'KONE Ibrahim',
            'numero_piece' => 'PC-'.$montant,
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

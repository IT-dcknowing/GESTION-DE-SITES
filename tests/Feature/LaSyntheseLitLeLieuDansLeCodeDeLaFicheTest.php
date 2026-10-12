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
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\LigneRejeteeImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le code de la fiche de réception dit la ville — 12/10.
 *
 * « Le CA que j'ai importé a toujours la colonne fiche de réception, et le code renvoie à la
 * ville. Je peux concevoir qu'on ne connaisse pas l'atelier, mais la ville si. » Et : « le
 * montant annoncé n'est pas le vrai total du CA ». Le tableau relit donc le lieu par le code,
 * refait le CA entier, et porte une ligne Total.
 */
class LaSyntheseLitLeLieuDansLeCodeDeLaFicheTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $abidjan;

    private Ville $bouake;

    private User $gerant;

    private string $jour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->abidjan = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
        $siteA = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->bouake->id, 'code' => 'B1', 'nom' => 'Bouaké', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->gerant = User::create(['entreprise_id' => $this->e->id, 'name' => 'G', 'email' => 'g@a.t', 'password' => Hash::make('x'),
            'ville_id' => $this->abidjan->id, 'site_id' => $siteA->id, 'est_actif' => true]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();

        $this->jour = now()->startOfMonth()->addDays(2)->toDateString();
    }

    public function test_un_code_declare_range_la_ligne_dans_sa_ville_meme_si_la_ligne_n_a_pas_de_lieu(): void
    {
        CodeAgent::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'code' => 'KK', 'ville_id' => $this->bouake->id, 'est_actif' => true]);
        $this->facture('FR-KKN° 010082', 700_000);

        $ca = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')->instance()->syntheseDuCa
            ->keyBy(fn ($l) => $l['site']->nom);

        $this->assertSame(700_000, $ca['Bouaké — atelier non précisé']['ca'], 'Le code KK est celui de Bouaké : la ligne n\'est plus « Lieu non précisé ».');
        $this->assertFalse($ca->has('Lieu non précisé'));
    }

    public function test_un_code_non_declare_prend_la_ville_que_lui_donnent_les_autres_lignes_du_fichier(): void
    {
        // Vingt-quatre factures du code DH déjà situées à Abidjan, une seule ailleurs : le code est celui d'Abidjan.
        for ($i = 0; $i < 24; $i++) {
            $this->facture('FR-DHN° 0100'.$i, 10_000, ville: $this->abidjan->id);
        }
        $this->facture('FR-DHN° 020000', 5_000, ville: $this->bouake->id);
        $this->facture('FR-DHN° 030000', 800_000);

        $ca = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')->instance()->syntheseDuCa
            ->keyBy(fn ($l) => $l['site']->nom);

        $this->assertSame(24 * 10_000 + 800_000, $ca['Abidjan — atelier non précisé']['ca']);
        $this->assertFalse($ca->has('Lieu non précisé'));
    }

    public function test_un_code_trop_rare_ne_decide_rien_et_l_ecran_le_nomme(): void
    {
        $this->facture('FR-YBN° 000001', 3_000, ville: $this->abidjan->id);
        $this->facture('FR-YBN° 000002', 900_000);
        $this->facture('', 100_000);

        $ecran = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord');
        $inconnu = $ecran->instance()->syntheseDuCa->firstWhere('genre', 'aucun');

        $this->assertSame(1_000_000, $inconnu['ca']);
        $this->assertEqualsCanonicalizing(['YB', ''], array_column($inconnu['codesSansLieu'], 'code'));
        $ecran->assertSee('YB')->assertSee('sans fiche')->assertDontSee('factures:situer');
    }

    public function test_le_total_est_celui_du_ca_meme_quand_une_ligne_est_dans_une_ville_sans_atelier(): void
    {
        // Une ville sans aucun atelier : ses lignes ne sont dans aucune ligne du tableau.
        $daloa = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'DLA', 'nom' => 'Daloa', 'est_actif' => true]);
        $this->facture('FR-EYN° 000003', 250_000, ville: $daloa->id);
        $this->facture('FR-EYN° 000004', 400_000, site: Site::where('code', 'A1')->value('id'));

        $ecran = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord');
        $total = (int) Facture::query()->duChiffreDAffaires()->sum('montant');

        $this->assertSame($total, (int) $ecran->instance()->syntheseDuCa->sum('ca'));
        $this->assertSame($total, (int) $ecran->instance()->kpis['ca']);
        $ecran->assertSee('Autres lignes du CA');
    }

    public function test_les_deux_tableaux_ont_leur_ligne_total(): void
    {
        $this->facture('FR-EYN° 000004', 400_000, site: Site::where('code', 'A1')->value('id'));

        Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')
            ->assertSeeHtml('<td>Total</td>')
            ->assertSee('400 000');
    }

    public function test_les_lignes_refusees_a_l_import_sont_dites_avec_leur_montant(): void
    {
        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'format' => 'factures', 'nom_fichier' => 'ca.xlsx', 'deposant' => 'X',
            'empreinte' => hash('sha256', 'ca'), 'etat' => 'termine',
        ]);
        LigneRejeteeImport::create([
            'lot_import_id' => $lot->id, 'numero_ligne' => 14,
            'motif' => 'La date de la facture est absente ou illisible.', 'valeurs' => ['numero' => 'FA -5725', 'montant' => '9899'],
        ]);

        $ecran = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord');

        $this->assertSame(9_899, $ecran->instance()->rejetsDuCa['montant']);
        $ecran->assertSee('du dernier fichier du CA');
    }

    private function facture(string $fiche, int $montant, ?int $ville = null, ?int $site = null): Facture
    {
        static $n = 0;
        $n++;

        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $site, 'ville_id' => $ville,
            'numero' => "F-{$n}", 'n_facture' => "FA -{$n}", 'date' => $this->jour, 'client' => 'X',
            'activite' => 'Mécanique', 'montant' => $montant, 'reference_devis' => $fiche === '' ? null : $fiche,
        ]);
    }
}

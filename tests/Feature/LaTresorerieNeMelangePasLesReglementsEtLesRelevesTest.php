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
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\RapprochementDeTresorerie;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La trésorerie montre chaque côté sans rien mélanger — demandé le 07/10.
 *
 * « Une section qui montre la situation de la caisse ou de la banque selon ce qui la nourrit,
 * les données de caisse et de banque réellement importées gardées sans y toucher, un KPI de
 * comparaison, et des lignes "rapprochement CA-Banque" et "CA-Caisse" filtrables identique /
 * pas identique. Ni gonfler, ni diminuer. »
 *
 * Le cas qui fonde tout : un chèque de 500 000 F écrit à l'état des impayés **et** crédité au
 * relevé. Additionnés, ils feraient 1 000 000 F d'encaissements pour 500 000 F reçus.
 */
class LaTresorerieNeMelangePasLesReglementsEtLesRelevesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    private Banque $bgfi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        $this->ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan 1', 'est_actif' => true]);
        $this->bgfi = Banque::withoutGlobalScopes()->create(['entreprise_id' => $this->entreprise->id, 'nom' => 'BGFI', 'nom_normalise' => 'BGFI', 'type' => Banque::BANQUE]);
    }

    public function test_un_cheque_n_est_compte_qu_une_fois_de_chaque_cote(): void
    {
        $this->reglement('Chèque', 500_000, '2026-03-02');
        $this->credit(500_000, '2026-03-05', 1_500_000);

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie')
            ->set('dateDebut', '2026-03-01')->set('dateFin', '2026-03-31')->set('periode', 'periode');

        $this->assertSame(500_000, $ecran->instance()->kpis['encaisse'], 'Les règlements, et eux seuls.');
        $this->assertSame(500_000, $ecran->instance()->reels['banque']['credits'], 'Le relevé, et lui seul.');
        $this->assertSame(1_500_000, $ecran->instance()->reels['banque']['solde'], 'Le solde est celui que la banque annonce.');

        $this->assertSame(500_000, $ecran->instance()->reglementsParCote['banque']);
        $this->assertNull($ecran->instance()->rapprochements['banque'], 'Rien n\'est pointé tant qu\'on n\'ouvre pas.');

        $banque = $ecran->call('ouvrirLeRapprochement', 'banque')->instance()->rapprochements['banque'];
        $this->assertSame(1, $banque['identiques']['nombre']);
        $this->assertSame(0, $banque['differents']['nombre']);
    }

    public function test_le_filtre_identique_pas_identique_et_les_orphelines(): void
    {
        $this->reglement('Chèque', 500_000, '2026-03-02');   // retrouvé le 05
        $this->reglement('Virement', 80_000, '2026-03-10');  // jamais crédité
        $this->reglement('Non précisé', 120_000, '2026-03-12'); // moyen non dit, crédité le 13
        $this->reglement('Espèces', 30_000, '2026-03-03');   // côté caisse
        $this->credit(500_000, '2026-03-05', 1_000_000);
        $this->credit(120_000, '2026-03-13', 1_120_000);
        $this->credit(999_999, '2026-03-20', 2_119_999);     // reçu sans règlement
        $this->entreeDeCaisse(30_000, '2026-03-04');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie')
            ->set('dateDebut', '2026-03-01')->set('dateFin', '2026-03-31')->set('periode', 'periode')
            ->call('ouvrirLeRapprochement', 'banque');

        $this->assertCount(3, $ecran->instance()->lignesRapprochement, 'Banque et non précisé, pas les espèces.');

        $ecran->set('rapprochementEtat', 'identique');
        $this->assertEqualsCanonicalizing([500_000, 120_000], $ecran->instance()->lignesRapprochement->pluck('montant')->all());

        $ecran->set('rapprochementEtat', 'different');
        $this->assertSame([80_000], $ecran->instance()->lignesRapprochement->pluck('montant')->all());

        $this->assertSame(['nombre' => 1, 'montant' => 999_999], $ecran->instance()->rapprochements['banque']['orphelines']);

        // Côté caisse, l'espèce est retrouvée au journal.
        $ecran->call('ouvrirLeRapprochement', 'caisse');
        $this->assertSame(1, $ecran->instance()->rapprochements['caisse']['identiques']['nombre']);
        $ecran->assertSee('Lignes rapprochement CA-Banque')->assertSee('Lignes rapprochement CA-Caisse');
    }

    public function test_une_operation_ne_sert_qu_une_fois_et_la_date_la_plus_proche_l_emporte(): void
    {
        $r = fn ($id, $date) => (object) ['id' => $id, 'date' => $date, 'montant' => 50_000];
        $o = fn ($id, $date) => (object) ['id' => $id, 'date' => $date, 'montant' => 50_000];

        $paires = RapprochementDeTresorerie::rapprocher(
            collect([$r(1, '2026-03-02'), $r(2, '2026-03-03')]),
            collect([$o(10, '2026-03-04'), $o(11, '2026-03-30')]),
            RapprochementDeTresorerie::FENETRE_BANQUE,
        );

        $this->assertSame(10, $paires[1]->id);
        $this->assertNull($paires[2], 'Le crédit du 04 a déjà servi ; celui du 30 est hors fenêtre.');
    }

    private function reglement(string $moyen, int $montant, string $date): void
    {
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'date' => $date,
            'type' => 'Client', 'moyen' => $moyen, 'montant' => $montant, 'client' => 'NSIA',
        ]);
    }

    private int $rang = 0;

    private function credit(int $montant, string $date, int $solde): void
    {
        MouvementBancaire::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'banque_id' => $this->bgfi->id, 'date_operation' => $date,
            'libelle' => 'VIR.RECU', 'credit' => $montant, 'sens' => MouvementBancaire::ENTREE,
            'solde_annonce' => $solde, 'rang' => ++$this->rang, 'cle' => 'c'.$this->rang,
        ]);
    }

    private function entreeDeCaisse(int $montant, string $date): void
    {
        MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'date' => $date,
            'sens' => MouvementCaisse::ENTREE, 'libelle' => 'Règlement client', 'montant' => $montant,
        ]);
    }

    private function gerant(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'), 'est_actif' => true,
        ]);
        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

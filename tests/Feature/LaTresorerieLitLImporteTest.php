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
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La trésorerie lit l'importé — 20/10 : « le graphique n'apparaît pas, et ce ne doit pas être
 * uniquement les saisies mais aussi l'importé ».
 *
 * Le décor : au journal de caisse, une entrée de 100 000 et une sortie de 30 000 ; au relevé, un
 * crédit de 50 000 et un débit de 20 000 ; un règlement saisi ici de 10 000 (espèces) ; un règlement
 * importé de l'état des impayés de 70 000 — le même argent que le journal ou le relevé, à côté.
 */
class LaTresorerieLitLImporteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        foreach ([[MouvementCaisse::ENTREE, 100_000], [MouvementCaisse::SORTIE, 30_000]] as [$sens, $montant]) {
            MouvementCaisse::withoutGlobalScopes()->create([
                'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'date' => '2026-03-10',
                'sens' => $sens, 'libelle' => 'JOURNAL '.$montant, 'montant' => $montant,
            ]);
        }

        $banque = Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'nom' => 'BGFI', 'nom_normalise' => Banque::clePour('BGFI'), 'type' => Banque::BANQUE,
        ]);
        foreach ([['entree', 50_000, 0], ['sortie', 0, 20_000]] as $i => [$sens, $credit, $debit]) {
            MouvementBancaire::withoutGlobalScopes()->create([
                'entreprise_id' => $this->e->id, 'banque_id' => $banque->id, 'date_operation' => '2026-03-12',
                'libelle' => 'OPERATION '.$i, 'credit' => $credit, 'debit' => $debit, 'sens' => $sens, 'rang' => $i, 'cle' => 'c'.$i,
            ]);
        }

        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $site->id, 'date' => '2026-03-15', 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 10_000,
        ]);
        $lot = LotImport::create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'deposant' => 'Reprise', 'format' => 'impayes',
            'nom_fichier' => 'impayes.xlsx', 'empreinte' => str_repeat('a', 64), 'etat' => 'termine',
        ]);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $site->id, 'lot_import_id' => $lot->id, 'date' => '2026-03-15', 'type' => 'Client', 'moyen' => 'Chèque', 'montant' => 70_000,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->gerant = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $this->gerant->assignRole('gerant');
    }

    public function test_la_tresorerie_compte_la_caisse_les_banques_et_les_saisies_une_fois(): void
    {
        $ecran = $this->ecran();
        $t = $ecran->instance()->totauxReels;

        $this->assertSame(160_000, $t['entrees'], 'Journal 100 000 + relevé 50 000 + saisie 10 000 ; le règlement importé reste à côté.');
        $this->assertSame(50_000, $t['sorties']);
        $this->assertSame(110_000, $t['net']);

        // Le graphique dit enfin quelque chose.
        $datasets = collect($ecran->instance()->graphique['datasets']);
        $this->assertSame(160_000, array_sum($datasets->firstWhere('label', 'Entrées')['data']));
        $this->assertSame(50_000, array_sum($datasets->firstWhere('label', 'Sorties')['data']));

        // Les listes montrent l'importé : journal et relevés.
        $ecran->assertSee('Relevé bancaire')->assertSee('Journal de caisse');
    }

    public function test_le_support_lit_la_caisse_ou_la_banque(): void
    {
        $caisse = $this->ecran()->set('supportFiltre', 'caisse')->instance()->totauxReels;
        $this->assertSame(110_000, $caisse['entrees'], 'Journal 100 000 + règlement en espèces saisi 10 000.');

        $banque = $this->ecran()->set('supportFiltre', 'banque')->instance()->totauxReels;
        $this->assertSame(50_000, $banque['entrees']);
        $this->assertSame(20_000, $banque['sorties']);
    }

    private function ecran()
    {
        return Volt::actingAs($this->gerant)->test('pilotage.tresorerie')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31');
    }
}

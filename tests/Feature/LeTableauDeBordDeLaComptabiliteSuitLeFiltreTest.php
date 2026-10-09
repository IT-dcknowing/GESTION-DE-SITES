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
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le tableau de bord de la comptabilité, revu le 09/10 : « vérifie comment on peut avoir
 * zéro, et si les KPI suivent le filtre ».
 *
 * Il affichait 0 F partout sur septembre à Abidjan. Les règlements importés n'ont pas
 * d'atelier, et l'écran ne les cherchait que par atelier ; les trois cartes « aujourd'hui »
 * ignoraient la période choisie ; le reste à encaisser ne comptait que les factures tapées à
 * la main.
 */
class LeTableauDeBordDeLaComptabiliteSuitLeFiltreTest extends TestCase
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

    public function test_un_reglement_importe_sans_atelier_compte_et_le_jour_suit_la_periode(): void
    {
        // Une créance de l'état des impayés, sans atelier — comme le fichier les apporte.
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => null, 'ville_id' => $this->ville->id,
            'date' => '2026-09-03', 'n_facture' => '2772', 'client' => 'ANSUT', 'activite' => 'Mécanique',
            'montant' => 900_000, 'exercice_impayes' => 2026,
        ]);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => null, 'facture_id' => $facture->id,
            'date' => '2026-09-30', 'type' => 'Client', 'moyen' => 'Chèque', 'montant' => 400_000,
        ]);
        MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'date' => '2026-09-12', 'sens' => MouvementCaisse::SORTIE, 'libelle' => 'ACHAT', 'montant' => 25_000,
        ]);

        $kpis = Volt::actingAs($this->compte())->test('comptabilite.tableau-de-bord')
            ->set('moisFiltre', '9')
            ->get('kpis');

        $this->assertSame(400_000, $kpis['encaissePeriode']);
        // Le dernier jour de septembre, et non la date du jour.
        $this->assertSame(400_000, $kpis['encaisseJour']);
        $this->assertSame(500_000, $kpis['resteAEncaisser']);
        $this->assertSame(1, $kpis['facturesEnAttente']);
        $this->assertSame(25_000, $kpis['journalSorties']);
    }

    public function test_une_facture_du_seul_cattc_ne_parait_pas_due_en_entier(): void
    {
        // Sans règlement importé ni état des impayés, son reste ne se calcule pas.
        $lot = \Modules\Noyau\Imports\Modeles\LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'format' => 'factures',
            'nom_fichier' => 'cattc.xlsx', 'deposant' => 'X', 'empreinte' => hash('sha256', 'cattc'), 'etat' => 'termine',
        ]);
        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'lot_import_id' => $lot->id,
            'date' => '2026-09-03', 'n_facture' => 'FA-1', 'client' => 'X', 'activite' => 'Mécanique', 'montant' => 700_000,
        ]);

        $kpis = Volt::actingAs($this->compte())->test('comptabilite.tableau-de-bord')->set('moisFiltre', '9')->get('kpis');

        $this->assertSame(0, $kpis['resteAEncaisser']);
    }

    private function compte(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Vanessa Kouassi',
            'email' => 'caissier@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id, 'site_id' => $this->site->id, 'est_actif' => true,
        ]);
        $compte->assignRole('caissier');

        return $compte->fresh();
    }
}

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
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La synthèse par site en deux sections — 12/10 : le CA importé seul, puis l'état des impayés.
 *
 * Les règlements de l'état remplissaient les lignes « non précisé » du tableau du CA. Le CA
 * reste au CA ; la créance et son règlement vont à la seconde section, à leur ville.
 */
class LaSyntheseSepareLeCaEtLesImpayesTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_creance_et_son_reglement_vont_a_la_section_des_impayes(): void
    {
        $e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($e);
        $abidjan = Ville::create(['entreprise_id' => $e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $site = Site::create(['entreprise_id' => $e->id, 'ville_id' => $abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        $lot = fn (string $f) => LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $e->id, 'format' => $f, 'nom_fichier' => "$f.xlsx", 'deposant' => 'X',
            'empreinte' => hash('sha256', $f), 'etat' => 'termine',
        ]);
        $jour = now()->startOfMonth()->addDays(2)->toDateString();

        // Une ligne du CA, à son atelier.
        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $e->id, 'site_id' => $site->id, 'lot_import_id' => $lot('factures')->id,
            'date' => $jour, 'n_facture' => 'FA -1', 'client' => 'X', 'activite' => 'Mécanique', 'montant' => 300_000,
        ]);
        // Une créance de l'état, que le CA n'a pas retrouvée : la ville seule.
        $creance = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $e->id, 'ville_id' => $abidjan->id, 'lot_import_id' => $lot('impayes')->id,
            'exercice_impayes' => now()->year, 'date' => $jour, 'n_facture' => '17', 'client' => 'NSIA',
            'activite' => 'Sinistre', 'montant' => 1_000_000,
        ]);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $e->id, 'facture_id' => $creance->id, 'date' => $jour,
            'type' => 'Client', 'moyen' => 'Chèque', 'montant' => 400_000,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($e->id);
        $g = User::create(['entreprise_id' => $e->id, 'name' => 'G', 'email' => 'g@a.t', 'password' => Hash::make('x'),
            'ville_id' => $abidjan->id, 'site_id' => $site->id, 'est_actif' => true]);
        $g->assignRole('gerant');

        $ecran = Volt::actingAs($g->fresh())->test('gerant.tableau-de-bord');

        $ca = $ecran->instance()->syntheseDuCa->keyBy(fn ($l) => $l['site']->nom);
        $this->assertSame(300_000, $ca['Abidjan — Site 1']['ca']);
        $this->assertFalse($ca->has('Abidjan — atelier non précisé'), 'La créance seule n\'est pas du CA.');

        $imp = $ecran->instance()->syntheseImpayes->keyBy(fn ($l) => $l['site']->nom);
        $ligne = $imp['Abidjan — atelier non précisé'];
        $this->assertSame(1_000_000, $ligne['creances']);
        $this->assertSame(1_000_000, $ligne['horsCa']);
        $this->assertSame(400_000, $ligne['encaisse']);
        $this->assertSame(600_000, $ligne['reste']);

        $ecran->assertSee('Synthèse par site — état des impayés')
            ->assertSee("Vient de l'état des impayés : ville connue, pas l'atelier");
    }
}

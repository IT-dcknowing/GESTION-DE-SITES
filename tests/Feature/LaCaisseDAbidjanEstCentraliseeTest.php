<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La caisse d'Abidjan est une seule caisse pour ses deux ateliers — vérifié le 07/10.
 *
 * « La caisse est centralisée : site 1 et 2 → Abidjan. Vérifie si c'est le cas. » Ce l'était
 * pour le journal importé et l'écran `/caisse`, qui lisent par ville. Ce ne l'était pas pour
 * la caissière dont le compte porte un atelier : son périmètre s'arrêtait à lui, et les
 * espèces rangées sous l'autre atelier lui échappaient.
 */
class LaCaisseDAbidjanEstCentraliseeTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_caissiere_d_abidjan_voit_les_deux_ateliers(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($entreprise->id);

        $abidjan = Ville::create(['entreprise_id' => $entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $bouake = Ville::create(['entreprise_id' => $entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
        $un = Site::create(['entreprise_id' => $entreprise->id, 'ville_id' => $abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan 1', 'est_actif' => true]);
        $deux = Site::create(['entreprise_id' => $entreprise->id, 'ville_id' => $abidjan->id, 'code' => 'A2', 'nom' => 'Abidjan 2', 'est_actif' => true]);
        Site::create(['entreprise_id' => $entreprise->id, 'ville_id' => $bouake->id, 'code' => 'B1', 'nom' => 'Bouaké', 'est_actif' => true]);

        // Son compte porte « Abidjan 1 » — c'est le cas qui coupait la caisse en deux.
        $caissiere = User::create([
            'entreprise_id' => $entreprise->id, 'name' => 'Caissière', 'email' => 'caisse@alpha.test',
            'password' => 'mot-de-passe-de-test', 'est_actif' => true, 'site_id' => $un->id,
        ]);
        $caissiere->assignRole('caissier');
        $caissiere = $caissiere->fresh();

        $this->assertEqualsCanonicalizing([$un->id, $deux->id], Site::visiblesPour($caissiere)->pluck('id')->all(), 'Les deux ateliers d\'Abidjan, pas Bouaké.');
        $this->assertEqualsCanonicalizing([$un->id, $deux->id], PerimetreSites::idsRetenus($caissiere, null));

        // Une sortie d'espèces rangée sous l'autre atelier paraît sur son tableau de bord.
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id, 'site_id' => $deux->id, 'date' => now()->toDateString(),
            'type' => 'Caisse', 'moyen' => 'ESPÈCES', 'montant' => 75_000,
        ]);

        $this->assertSame(75_000, (int) Encaissement::whereIn('site_id', PerimetreSites::idsRetenus($caissiere, null))->sum('montant'));

        // Et la saisie d'un décaissement propose son atelier par défaut.
        $this->assertSame($un->id, (int) Volt::actingAs($caissiere)->test('comptabilite.decaissements')->get('siteId'));
    }
}

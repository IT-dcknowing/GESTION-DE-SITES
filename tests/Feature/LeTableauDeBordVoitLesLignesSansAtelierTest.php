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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'accueil du gérant voit ce qui a été importé — capture du propriétaire du 05/10.
 *
 * Après l'import de l'état des impayés et de données de trésorerie, l'écran affichait 0 F
 * partout : CA, charges, résultat, encaissé, trésorerie, et « Aucune donnée » sous les deux
 * graphiques. Les lignes importées portent leur **ville** — le fichier ne dit jamais le
 * lieu — et l'écran ne les cherchait que par **atelier**. C'est le piège n° 1 de
 * `REPRENDRE-ICI.md`, pour la cinquième fois.
 */
class LeTableauDeBordVoitLesLignesSansAtelierTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);

        foreach (['Abidjan 1' => $this->abidjan, 'Abidjan 2' => $this->abidjan, 'Bouaké' => $this->bouake] as $nom => $ville) {
            Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'code' => $nom, 'nom' => $nom, 'est_actif' => true]);
        }

        // Ce qu'un import laisse : une ville, aucun atelier, et le règlement sans atelier non plus.
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => null, 'ville_id' => $this->abidjan->id,
            'numero' => 'F-1', 'n_facture' => 'FA-1', 'date' => now()->toDateString(),
            'client' => 'NSIA', 'activite' => 'Sinistre', 'montant' => 1_250_000,
        ]);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => null, 'facture_id' => $facture->id,
            'date' => now()->toDateString(), 'type' => 'Client', 'moyen' => 'Virement', 'activite' => 'Sinistre', 'montant' => 400_000,
        ]);
    }

    public function test_les_indicateurs_ne_sont_plus_a_zero(): void
    {
        $ecran = Volt::actingAs($this->gerant())->test('gerant.tableau-de-bord');
        $kpis = $ecran->get('kpis');

        $this->assertSame(1_250_000, $kpis['ca']);
        $this->assertSame(1_250_000, $kpis['caSinistre'], 'La ventilation lit le même périmètre que le total.');
        $this->assertSame(400_000, $kpis['encaisse']);
        $this->assertSame(400_000, $kpis['treso']);

        $lignes = collect($ecran->get('synthese'))->keyBy(fn ($l) => $l['site']->nom);
        $this->assertSame(1_250_000, $lignes['Abidjan — atelier non précisé']['ca']);
        $this->assertSame(0, $lignes['Abidjan 1']['ca'], 'Rien n\'est attribué au jugé à un atelier.');

        // La courbe des flux voit le règlement elle aussi.
        $flux = $ecran->get('graphiqueFlux');
        $this->assertSame(400_000, array_sum($flux['datasets'][0]['data']));
    }

    public function test_le_filtre_ville_ecarte_les_lignes_d_une_autre_ville(): void
    {
        $ecran = Volt::actingAs($this->gerant())->test('gerant.tableau-de-bord')
            ->set('villeFiltre', (string) $this->bouake->id);

        $this->assertSame(0, $ecran->get('kpis')['ca'], 'La facture d\'Abidjan n\'a rien à faire dans Bouaké.');
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

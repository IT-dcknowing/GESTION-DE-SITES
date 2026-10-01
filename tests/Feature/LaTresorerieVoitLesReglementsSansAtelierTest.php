<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La trésorerie voit les règlements qui n'ont pas d'atelier.
 *
 * **Le défaut mesuré le 30/09, et il était grave.** L'écran lisait ses trois sources par
 * `whereIn('site_id', $idsSites)`. Un `site_id` nul n'entre dans aucun `whereIn` — et sur
 * les encaissements c'est la quasi-totalité :
 *
 * | | |
 * |---|---|
 * | encaissements en base | 7 714 |
 * | sans atelier | 7 627 |
 * | ce que l'écran montrait | **87** |
 *
 * La page annonçait lire « les règlements clients » et en affichait **un pour cent**. Ce
 * n'était pas un filtre trop serré : c'était un total faux d'un facteur cent, sur l'écran qui
 * sert à savoir ce qu'on a encaissé. Troisième écran mordu par ce même `whereIn`, après les
 * encaissements du recouvrement et le chiffre d'affaires — d'où un test, et non une
 * correction de plus.
 *
 * **Ce qu'il verrouille** : un règlement sans atelier doit **compter**, et il doit compter
 * **là où sa facture a été émise**. Les deux à la fois : ne garder que le premier rangerait
 * un règlement d'Abidjan dans le total de San-Pédro, ce qui est une autre façon de mentir.
 */
class LaTresorerieVoitLesReglementsSansAtelierTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $sanPedro;

    private Site $siteAbidjan;

    private Site $siteSanPedro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->sanPedro = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'SPD', 'nom' => 'San Pédro', 'est_actif' => true,
        ]);

        $this->siteAbidjan = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        $this->siteSanPedro = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->sanPedro->id,
            'code' => 'SPD-1', 'nom' => 'San Pédro — Site 1', 'est_actif' => true,
        ]);
    }

    /**
     * Un règlement sans atelier compte quand même.
     *
     * C'est le cœur du défaut : sans cette règle, l'écran affichait 87 lignes sur 7 714.
     */
    public function test_un_reglement_sans_atelier_entre_dans_le_total(): void
    {
        // Celui que l'ancien code voyait : il porte son atelier.
        $this->encaissement(300_000, site: $this->siteAbidjan);

        // Celui qu'il perdait : pas d'atelier, mais une facture qui dit sa ville. C'est la
        // forme que prennent les 7 627 règlements repris de l'état des impayés.
        $this->encaissement(500_000, facture: $this->facture(ville: $this->abidjan));

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.tresorerie');

        $this->assertSame(800_000, (int) $ecran->instance()->kpis['encaisse'],
            'Un règlement sans atelier doit compter : il est entré, et la caisse le sait.');
    }

    /**
     * Et il compte **là où sa facture a été émise**, pas partout.
     *
     * La seconde moitié de la règle. Faire paraître tout règlement sans atelier dans toutes
     * les villes réparerait le total consolidé en faussant chaque total de ville — un
     * responsable de San-Pédro lirait l'argent d'Abidjan dans le sien.
     */
    public function test_un_reglement_sans_atelier_suit_la_ville_de_sa_facture(): void
    {
        $this->encaissement(500_000, facture: $this->facture(ville: $this->abidjan));
        $this->encaissement(700_000, facture: $this->facture(ville: $this->sanPedro));

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.tresorerie');

        $this->assertSame(1_200_000, (int) $ecran->instance()->kpis['encaisse'], 'Consolidé : les deux.');

        $ecran->set('villeFiltre', (string) $this->sanPedro->id);

        $this->assertSame(700_000, (int) $ecran->instance()->kpis['encaisse'],
            "San-Pédro ne doit pas lire l'argent d'Abidjan.");
    }

    /**
     * Un règlement qu'on ne sait pas placer paraît partout plutôt que nulle part.
     *
     * 3 539 des règlements repris sont dans ce cas : leur facture n'a ni atelier ni ville.
     * Les cacher reviendrait à les perdre — c'est précisément ce qui venait d'arriver.
     */
    public function test_un_reglement_qu_on_ne_sait_pas_placer_parait_quand_meme(): void
    {
        $this->encaissement(400_000, facture: $this->facture(ville: null));

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.tresorerie');

        $this->assertSame(400_000, (int) $ecran->instance()->kpis['encaisse']);

        $ecran->set('villeFiltre', (string) $this->abidjan->id);

        $this->assertSame(400_000, (int) $ecran->instance()->kpis['encaisse'],
            'Une ligne dont on ignore le lieu doit rester visible sous chaque filtre.');
    }

    /**
     * Les charges, elles, se lisent par le seul atelier — et c'est la base qui l'impose.
     *
     * J'avais écrit ici un repli par symétrie avec les encaissements. Ce test l'a refusé sur
     * -le-champ : `charges.site_id` est déclaré `NOT NULL` avec clé étrangère. Une charge sans
     * atelier ne peut pas exister, et un `orWhereNull` sur cette colonne aurait été une
     * condition qui ne se vérifie jamais — du code mort racontant une histoire fausse.
     *
     * Ce test garde donc les deux moitiés de la vérité : les charges placées se comptent, et
     * la contrainte qui rend le repli inutile est vérifiée plutôt que supposée. Le jour où un
     * import de charges demandera de relâcher la colonne, il tombera ici.
     */
    public function test_une_charge_ne_peut_pas_exister_sans_atelier(): void
    {
        Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->siteAbidjan->id,
            'date' => now()->toDateString(),
            'libelle' => 'Fonctionnement',
            'montant' => 100_000,
        ]);

        Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->siteSanPedro->id,
            'date' => now()->toDateString(),
            'libelle' => 'Achats pièces',
            'montant' => 250_000,
        ]);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.tresorerie');

        $this->assertSame(350_000, (int) $ecran->instance()->kpis['decaisse'], 'Consolidé : les deux ateliers.');

        $ecran->set('villeFiltre', (string) $this->sanPedro->id);
        $this->assertSame(250_000, (int) $ecran->instance()->kpis['decaisse']);

        // Et la colonne refuse le vide : c'est ce qui rend tout repli inutile.
        $this->expectException(QueryException::class);

        Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => null,
            'date' => now()->toDateString(),
            'libelle' => 'Sans atelier',
            'montant' => 1_000,
        ]);
    }

    // ------------------------------------------------------------------ le décor

    private function facture(?Ville $ville): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            // Aucun atelier : c'est l'état des 8 848 factures portées, dont la colonne SITE
            // du fichier dit « ABIDJAN » — une ville, et Abidjan en a deux.
            'site_id' => null,
            'ville_id' => $ville?->id,
            'date' => now()->toDateString(),
            'n_facture' => 'F-'.Facture::withoutGlobalScopes()->count(),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Mécanique',
            'montant' => 1_000_000,
            'exercice_impayes' => now()->year,
        ]);
    }

    private function encaissement(int $montant, ?Site $site = null, ?Facture $facture = null): Encaissement
    {
        return Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $site?->id,
            'facture_id' => $facture?->id,
            'date' => now()->toDateString(),
            'type' => 'Client',
            'moyen' => 'Chèque',
            'montant' => $montant,
            'client' => 'NSIA ASSURANCES',
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
            'ville_id' => $this->abidjan->id,
            'site_id' => $this->siteAbidjan->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

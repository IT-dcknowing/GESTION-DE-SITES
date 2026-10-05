<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\CorrespondanceFacture;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\CorrespondancesDeFactures;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les correspondances : chaque commercial coche les factures nées de ses prospections.
 *
 * Demandé le 05/10. Avant le 22/09, rien ne reliait une prospection à la facture qui l'a
 * suivie ; mesuré en local, 11 229 factures sur 11 332 ne sont comptées à personne. Le
 * commercial coche et valide ; la ligne disparaît chez les autres commerciaux et reste chez
 * les responsables, qui seuls peuvent annuler.
 */
class LesCommerciauxCochentLeursFacturesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $atelierAbidjan;

    private Site $atelierBouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);

        $this->atelierAbidjan = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        $this->atelierBouake = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id,
            'code' => 'BKE-1', 'nom' => 'Bouaké — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_un_commercial_voit_les_factures_sans_commercial_de_sa_ville_et_celles_sans_lieu(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);

        $sienne = $this->facture('F-1', $this->atelierAbidjan);
        $ailleurs = $this->facture('F-2', $this->atelierBouake);
        // Sans atelier, rangée à Abidjan par sa seule ville : elle doit rester visible — un
        // `whereIn('site_id', …)` l'aurait perdue.
        $parSaVille = $this->facture('F-3', null, ['ville_id' => $this->abidjan->id]);
        // Ni atelier ni ville : on ne sait pas où elle est, la cacher serait la perdre.
        $sansLieu = $this->facture('F-4', null);
        $avoir = $this->facture('F-5', $this->atelierAbidjan, ['montant' => -500_000, 'est_avoir' => true]);
        $dejaComptee = $this->facture('F-6', $this->atelierAbidjan, ['commercial_id' => $this->ficheDe($koffi)->id]);

        $ids = CorrespondancesDeFactures::requete($koffi)->pluck('factures.id')->all();

        $this->assertEqualsCanonicalizing([$sienne->id, $parSaVille->id, $sansLieu->id], $ids);
        $this->assertNotContains($ailleurs->id, $ids);
        $this->assertNotContains($avoir->id, $ids);
        $this->assertNotContains($dejaComptee->id, $ids);
    }

    public function test_cocher_puis_valider_compte_la_facture_au_commercial_et_la_retire_aux_autres(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $awa = $this->vendeur('Awa Traoré', $this->abidjan);
        $gerant = $this->compte('gerant', $this->abidjan);
        $facture = $this->facture('F-100', $this->atelierAbidjan);

        $this->actingAs($koffi);
        Volt::test('pilotage.correspondances')
            ->assertSee('F-100')
            ->set('selection', [(string) $facture->id])
            ->call('valider')
            ->assertHasNoErrors()
            ->assertSet('erreur', '')
            ->assertDontSee('F-100');

        $this->assertSame($this->ficheDe($koffi)->id, $facture->fresh()->commercial_id);

        $correspondance = CorrespondanceFacture::withoutGlobalScopes()->sole();
        $this->assertSame($koffi->id, $correspondance->coche_par);
        $this->assertNull($correspondance->annulee_le);

        // Disparue chez l'autre commercial…
        $this->actingAs($awa);
        Volt::test('pilotage.correspondances')->assertDontSee('F-100');

        // … présente chez le gérant, avec le nom de celui qui l'a cochée.
        $this->actingAs($gerant);
        Volt::test('pilotage.correspondances')->assertSee('F-100')->assertSee('Koffi Yao');
    }

    public function test_deux_commerciaux_ne_peuvent_pas_compter_la_meme_facture(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $awa = $this->vendeur('Awa Traoré', $this->abidjan);
        $facture = $this->facture('F-200', $this->atelierAbidjan);

        $this->actingAs($koffi);
        $this->assertSame(1, CorrespondancesDeFactures::cocher($koffi, [$facture->id])['faits']);

        // Awa avait la ligne encore affichée et valide une seconde plus tard : la base tranche,
        // et le refus nomme celui qui l'a déjà.
        $this->actingAs($awa);
        $bilan = CorrespondancesDeFactures::cocher($awa, [$facture->id]);

        $this->assertSame(0, $bilan['faits']);
        $this->assertStringContainsString('Koffi Yao', $bilan['refus'][0]);
        $this->assertSame($this->ficheDe($koffi)->id, $facture->fresh()->commercial_id);
        $this->assertSame(1, CorrespondanceFacture::withoutGlobalScopes()->count());
    }

    public function test_un_commercial_ne_coche_pas_une_facture_hors_de_sa_ville(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $facture = $this->facture('F-300', $this->atelierBouake);

        $this->actingAs($koffi);
        // Un identifiant envoyé à la main, hors de ce que l'écran lui montre.
        $bilan = CorrespondancesDeFactures::cocher($koffi, [$facture->id]);

        $this->assertSame(0, $bilan['faits']);
        $this->assertNull($facture->fresh()->commercial_id);
    }

    public function test_seul_un_responsable_annule_et_la_facture_revient_chez_tous(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $awa = $this->vendeur('Awa Traoré', $this->abidjan);
        $gerant = $this->compte('gerant', $this->abidjan);
        $facture = $this->facture('F-400', $this->atelierAbidjan);

        $this->actingAs($koffi);
        CorrespondancesDeFactures::cocher($koffi, [$facture->id]);
        $correspondance = CorrespondanceFacture::withoutGlobalScopes()->sole();

        // Le commercial ne défait pas ce qu'il a coché — ni le sien, ni celui d'un autre.
        $this->assertNotNull(CorrespondancesDeFactures::annuler($koffi, $correspondance->id));
        $this->actingAs($awa);
        $this->assertNotNull(CorrespondancesDeFactures::annuler($awa, $correspondance->id));
        $this->assertNotNull($facture->fresh()->commercial_id);

        $this->actingAs($gerant);
        Volt::test('pilotage.correspondances')->call('annuler', $correspondance->id)->assertSet('erreur', '');

        $this->assertNull($facture->fresh()->commercial_id);

        // La trace reste : qui avait coché, qui a annulé.
        $correspondance->refresh();
        $this->assertNotNull($correspondance->annulee_le);
        $this->assertSame($gerant->id, $correspondance->annulee_par);

        $this->actingAs($awa);
        Volt::test('pilotage.correspondances')->assertSee('F-400');
    }

    public function test_mes_correspondances_ne_montre_que_les_miennes_et_dit_les_annulations(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $awa = $this->vendeur('Awa Traoré', $this->abidjan);
        $gerant = $this->compte('gerant', $this->abidjan);
        $mienne = $this->facture('F-500', $this->atelierAbidjan);
        $annulee = $this->facture('F-501', $this->atelierAbidjan);
        $celleDAwa = $this->facture('F-502', $this->atelierAbidjan);

        $this->actingAs($koffi);
        CorrespondancesDeFactures::cocher($koffi, [$mienne->id, $annulee->id]);
        $this->actingAs($awa);
        CorrespondancesDeFactures::cocher($awa, [$celleDAwa->id]);

        $this->actingAs($gerant);
        CorrespondancesDeFactures::annuler(
            $gerant,
            CorrespondanceFacture::withoutGlobalScopes()->where('facture_id', $annulee->id)->value('id'),
        );

        $this->actingAs($koffi);
        Volt::test('pilotage.mes-correspondances')
            ->assertSee('F-500')
            ->assertDontSee('F-501')
            ->assertDontSee('F-502')
            ->set('etatFiltre', 'toutes')
            ->assertSee('F-501')
            ->assertSee('Annulée')
            ->assertDontSee('F-502');
    }

    public function test_les_correspondances_sont_fermees_au_commercial_et_filtrent_par_commercial(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $awa = $this->vendeur('Awa Traoré', $this->abidjan);
        $gerant = $this->compte('gerant', $this->abidjan);
        $f1 = $this->facture('F-600', $this->atelierAbidjan);
        $f2 = $this->facture('F-601', $this->atelierAbidjan);

        $this->actingAs($koffi);
        CorrespondancesDeFactures::cocher($koffi, [$f1->id]);
        // Refusé par le rôle : l'application renvoie vers l'aiguillage, comme partout.
        $this->get(route('correspondances.suivi'))->assertRedirect();
        $this->get(route('correspondances'))->assertOk()->assertSee('Mes correspondances')->assertDontSee('Les correspondances');

        $this->actingAs($awa);
        CorrespondancesDeFactures::cocher($awa, [$f2->id]);

        $this->actingAs($gerant);
        $this->get(route('correspondances'))->assertOk()->assertSee('Les correspondances');
        $this->get(route('correspondances.suivi'))->assertOk();

        Volt::test('pilotage.correspondances-suivi')
            ->set('periode', 'periode')
            ->set('dateDebut', '2026-01-01')
            ->set('dateFin', '2026-12-31')
            ->assertSee('F-600')
            ->assertSee('F-601')
            ->set('villeFiltre', (string) $this->abidjan->id)
            ->set('commercialFiltre', (string) $this->ficheDe($koffi)->id)
            ->assertSee('F-600')
            ->assertDontSee('F-601');
    }

    public function test_le_saisisseur_se_lit_dans_le_numero_de_fiche(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        CodeAgent::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'site_id' => $this->atelierAbidjan->id,
            'code' => 'KZ', 'nom' => 'Kouassi', 'prenom' => 'Zoé', 'est_actif' => true,
        ]);
        $parKz = $this->facture('F-700', $this->atelierAbidjan, ['reference_devis' => 'FR-KZN° 010669']);
        $parYb = $this->facture('F-701', $this->atelierAbidjan, ['reference_devis' => 'FR-YBN° 010153']);

        $this->actingAs($koffi);
        $saisis = CorrespondancesDeFactures::saisisseurs([$parKz, $parYb]);

        $this->assertSame(['code' => 'KZ', 'nom' => 'Kouassi Zoé', 'lieu' => 'Abidjan — Site 1'], $saisis[$parKz->id]);
        // Un code que personne n'a encore nommé s'affiche seul.
        $this->assertSame(['code' => 'YB', 'nom' => '', 'lieu' => ''], $saisis[$parYb->id]);

        Volt::test('pilotage.correspondances')
            ->set('saisisseurFiltre', 'KZ')
            ->assertSee('F-700')
            ->assertDontSee('F-701')
            ->set('saisisseurFiltre', '')
            ->set('lieuSaisisseurFiltre', (string) $this->abidjan->id)
            ->assertSee('F-700')
            ->assertDontSee('F-701');
    }

    public function test_l_onglet_paraît_au_menu_du_commercial_et_du_gerant(): void
    {
        $koffi = $this->vendeur('Koffi Yao', $this->abidjan);
        $gerant = $this->compte('gerant', $this->abidjan);

        $this->actingAs($koffi);
        $this->assertContains('Correspondances', array_column(MenuNavigation::pour($koffi), 'label'));

        $this->actingAs($gerant);
        $indicateurs = collect(MenuNavigation::pour($gerant))->firstWhere('label', 'Indicateurs');
        $this->assertContains('Correspondances', array_column($indicateurs['groupe'], 'label'));
    }

    /*
    |--------------------------------------------------------------------------
    | Outils
    |--------------------------------------------------------------------------
    */

    private function facture(string $numero, ?Site $site, array $attributs = []): Facture
    {
        $facture = Facture::withoutGlobalScopes()->create(array_merge([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $site?->id,
            'numero' => $numero,
            'n_facture' => $numero,
            'date' => '2026-03-10',
            'client' => 'Client '.$numero,
            'montant' => 1_000_000,
            'activite' => 'Mécanique',
        ], array_diff_key($attributs, ['commercial_id' => true])));

        if (isset($attributs['commercial_id'])) {
            $facture->forceFill(['commercial_id' => $attributs['commercial_id']])->save();
        }

        return $facture;
    }

    /** Un compte commercial, et sa fiche — c'est à la fiche que la facture est comptée. */
    private function vendeur(string $nom, Ville $ville): User
    {
        $compte = $this->compte('commercial', $ville, $nom);

        Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'user_id' => $compte->id,
            'numero' => 'C-'.$compte->id, 'nom' => $nom, 'objectif_mecanique' => 14_000_000, 'objectif_sinistre' => 6_000_000,
        ]);

        return $compte;
    }

    private function ficheDe(User $compte): Commercial
    {
        return Commercial::withoutGlobalScopes()->where('user_id', $compte->id)->sole();
    }

    private function compte(string $role, Ville $ville, ?string $nom = null): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => $nom ?? ucfirst(str_replace('_', ' ', $role)),
            'email' => uniqid($role.'-').'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $ville->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

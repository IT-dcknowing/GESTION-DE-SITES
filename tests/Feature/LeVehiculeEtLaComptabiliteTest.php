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
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\NoteVehicule;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le jour 3 du plan : une plaque, un mouvement, et les écrans du comptable.
 *
 * **Caisse par véhicule.** La question du comptoir — « cette plaque, on a payé quoi dessus,
 * et reste-t-il quelque chose ? » — n'avait de réponse nulle part : il fallait ouvrir la
 * caisse, chercher la plaque, puis ouvrir l'état des impayés et recommencer. Une page la
 * réunit, sans période, avec de quoi laisser une note qui survit à celui qui la laisse.
 *
 * **Trésorerie.** « Autres » est une valeur du référentiel : commode à la saisie, muette à
 * la lecture. Le détail existait en base, il n'était affiché nulle part — pas plus que
 * l'origine d'un mouvement, qu'il fallait aller chercher dans le journal des lots.
 *
 * **Comptabilité.** Le comptable tenait la caisse sans pouvoir lire l'état de cette caisse.
 * Ses écrans lui sont ouverts ; ceux de l'exploitation restent fermés.
 */
class LeVehiculeEtLaComptabiliteTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Caisse par véhicule
    |--------------------------------------------------------------------------
    */

    public function test_une_plaque_ramene_sa_caisse_et_ses_factures(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $this->mouvement('1234 AB 01', MouvementCaisse::SORTIE, 75_000, 'Achat plaquettes FR-DM 015479');
        $this->mouvement('1234 AB 01', MouvementCaisse::ENTREE, 120_000, 'Acompte client');
        $this->mouvement('9999 ZZ 99', MouvementCaisse::SORTIE, 500_000, 'Une autre voiture');

        $facture = $this->facture('1234 AB 01', 'F-700', 400_000);

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $facture->id,
            'date' => now()->subDay(),
            'montant' => 150_000,
            'type' => 'Client',
            'moyen' => 'Espèces',
            'client' => $facture->tiersPayant(),
            'activite' => $facture->activite,
        ]);

        Volt::actingAs($gerant)->test('pilotage.caisse-vehicule')
            ->set('plaque', '1234 AB 01')
            ->assertSee('Achat plaquettes FR-DM 015479')
            ->assertSee('Acompte client')
            ->assertSee('F-700')
            // Le reste à payer : 400 000 − 150 000. C'est la réponse attendue au comptoir.
            ->assertSee('250 000')
            ->assertDontSee('Une autre voiture');
    }

    public function test_la_plaque_se_compare_sans_se_soucier_de_la_casse(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $this->mouvement('1234 AB 01', MouvementCaisse::SORTIE, 75_000, 'Pièce commandée');

        // Tapée en minuscules et avec deux espaces : c'est le même véhicule, et il doit
        // ouvrir le même dossier.
        Volt::actingAs($gerant)->test('pilotage.caisse-vehicule')
            ->set('plaque', ' 1234  ab 01 ')
            ->assertSee('Pièce commandée');
    }

    public function test_une_note_de_vehicule_garde_son_auteur_et_ne_s_ecrase_pas(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $ecran = Volt::actingAs($gerant)->test('pilotage.caisse-vehicule')
            ->set('plaque', '1234 AB 01');

        $ecran->set('note', 'Pièce commandée le 21/09.')->call('enregistrerLaNote')->assertHasNoErrors();
        $ecran->set('note', 'Pièce reçue le 28/09.')->call('enregistrerLaNote')->assertHasNoErrors();

        $notes = NoteVehicule::where('immatriculation', '1234 AB 01')->orderBy('id')->get();

        // Les deux faits coexistent : le second ne rend pas le premier faux.
        $this->assertCount(2, $notes);
        $this->assertSame('Pièce commandée le 21/09.', $notes[0]->texte);
        $this->assertSame($gerant->name, $notes[0]->auteur);
        $this->assertSame($gerant->id, $notes[0]->user_id);

        $ecran->assertSee('Pièce reçue le 28/09.');
    }

    public function test_une_note_vide_ou_sans_vehicule_est_refusee(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        Volt::actingAs($gerant)->test('pilotage.caisse-vehicule')
            ->set('plaque', '1234 AB 01')
            ->set('note', '   ')
            ->call('enregistrerLaNote')
            ->assertHasErrors('note');

        Volt::actingAs($gerant)->test('pilotage.caisse-vehicule')
            ->set('plaque', 'AB')
            ->set('note', 'Une note sans véhicule.')
            ->call('enregistrerLaNote')
            ->assertHasErrors('note');

        $this->assertSame(0, NoteVehicule::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Trésorerie : « Autres », et l'origine d'un mouvement
    |--------------------------------------------------------------------------
    */

    public function test_la_tresorerie_dit_ce_que_autres_recouvre(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->startOfMonth(),
            'montant' => 90_000,
            'type' => 'Autres',
            'moyen' => 'Espèces',
            'autres_tiers' => 'LOCATION DU HANGAR',
        ]);

        Charge::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->startOfMonth(),
            'montant' => 45_000,
            'type_operation' => 'Charges',
            'libelle' => 'Autres décaissements',
            'moyen' => 'Espèces',
            'tiers' => 'AMENDE STATIONNEMENT',
        ]);

        Volt::actingAs($gerant)->test('pilotage.tresorerie')
            ->assertSee('Ce que « Autres » recouvre')
            // Le poste réel, et non plus le mot « Autres » tout seul.
            ->assertSee('LOCATION DU HANGAR')
            ->assertSee('AMENDE STATIONNEMENT');
    }

    public function test_le_detail_d_un_mouvement_dit_d_ou_il_vient(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $facture = $this->facture('5678 CD 01', 'F-800', 200_000);

        $encaissement = Encaissement::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $facture->id,
            'date' => now()->startOfMonth(),
            'montant' => 200_000,
            'type' => 'Client',
            'moyen' => 'Virement',
            'client' => $facture->tiersPayant(),
        ]);

        Volt::actingAs($gerant)->test('pilotage.tresorerie')
            ->call('voirEncaissement', $encaissement->id)
            ->assertSee('Saisie dans l\'application')
            ->assertSee('F-800')
            ->assertSee('5678 CD 01');
    }

    /*
    |--------------------------------------------------------------------------
    | Les écrans du comptable
    |--------------------------------------------------------------------------
    */

    public function test_le_comptable_lit_la_caisse_la_tresorerie_les_charges_et_les_fournisseurs(): void
    {
        $this->actingAs($this->compte('caissier'));

        foreach (['caisse', 'caisse.vehicule', 'tresorerie', 'charges', 'fournisseurs'] as $page) {
            $this->get(route($page))->assertOk();
        }
    }

    public function test_le_comptable_n_entre_pas_dans_les_ecrans_de_l_exploitation(): void
    {
        $this->actingAs($this->compte('caissier'));

        // L'état des impayés est un écran de saisie, le parc et les clients sont ceux de
        // l'exploitation : ouvrir la comptabilité n'était pas ouvrir tout le pilotage.
        // Le refus se fait par renvoi, comme partout dans l'application : une page qu'on n'a
        // pas le droit de voir ne s'annonce pas, elle ne s'ouvre pas.
        foreach (['impayes', 'parc-vehicules', 'clients', 'chiffre-affaires'] as $page) {
            $this->get(route($page))->assertRedirect();
        }
    }

    public function test_le_responsable_commercial_reste_dehors_des_indicateurs_d_argent(): void
    {
        $this->actingAs($this->compte('responsable_commercial'));

        foreach (['caisse', 'tresorerie', 'charges', 'fournisseurs'] as $page) {
            $this->get(route($page))->assertRedirect();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    private function mouvement(string $plaque, string $sens, int $montant, string $libelle): MouvementCaisse
    {
        return MouvementCaisse::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'date' => now()->subDays(5),
            'sens' => $sens,
            'libelle' => $libelle,
            'montant' => $montant,
            'immatriculation' => $plaque,
        ]);
    }

    private function facture(string $plaque, string $numero, int $montant): Facture
    {
        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => 'FAC-'.substr(md5($numero), 0, 8),
            'n_facture' => $numero,
            'date' => now()->startOfMonth(),
            'client' => 'Client '.$numero,
            'immatriculation' => $plaque,
            'montant' => $montant,
            'activite' => 'Mécanique',
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

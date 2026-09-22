<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Modeles\NotificationApp;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le caissier encaisse et décaisse pour son site. Une facture ne peut jamais être
 * encaissée au-delà de son montant, que ce soit par le caissier seul ou en double
 * saisie avec le responsable — c'est le cœur du dispositif anti-double-saisie.
 */
class CaissierEncaissementTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    private User $responsable;

    private User $caissier;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->responsable = $this->membre('responsable_ville', 'resp@exemple.test');

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'ABJ',
            'nom' => 'Abidjan',
            'responsable_id' => $this->responsable->id,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'nom' => 'Abidjan',
        ]);

        $this->caissier = $this->membre('caissier', 'caissier@exemple.test');
        $this->caissier->update(['site_id' => $this->site->id]);

        $this->commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'numero' => 'C-0001',
            'nom' => 'Commercial Test',
        ]);
    }

    private function membre(string $role, string $email): User
    {
        $utilisateur = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'mot-de-passe-de-test',
            'entreprise_id' => $this->entreprise->id,
            'est_actif' => true,
        ]);

        $utilisateur->assignRole($role);

        return $utilisateur;
    }

    private function facture(int $montant = 500_000): Facture
    {
        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id,
            'numero' => 'F-'.random_int(1000, 9999),
            'n_facture' => 'FAC-'.random_int(1000, 9999),
            'date' => now()->toDateString(),
            'client' => 'Garage Test',
            'type' => 'FNE',
            'activite' => 'Mécanique',
            'montant' => $montant,
        ]);
    }

    public function test_seules_les_factures_non_soldees_apparaissent_au_caissier(): void
    {
        $soldee = $this->facture(200_000);
        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'facture_id' => $soldee->id, 'date' => now()->toDateString(),
            'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 200_000,
        ]);

        $enAttente = $this->facture(300_000);

        $this->actingAs($this->caissier);

        Volt::test('comptabilite.encaissements')
            ->assertSee($enAttente->n_facture)
            ->assertDontSee($soldee->n_facture);
    }

    public function test_le_caissier_encaisse_partiellement_une_facture(): void
    {
        $facture = $this->facture(500_000);

        $this->actingAs($this->caissier);

        Volt::test('comptabilite.encaissements')
            ->call('choisirFacture', $facture->id)
            ->set('montant', 300_000)
            ->set('moyen', 'Espèces')
            // Le motif est obligatoire depuis le 24/09 : le n° de facture dit sur quoi
            // porte le règlement, le motif dit pourquoi il arrive aujourd'hui.
            ->set('motif', 'Acompte convenu')
            ->call('encaisser')
            ->assertHasNoErrors();

        $this->assertSame(300_000, (int) Encaissement::where('facture_id', $facture->id)->sum('montant'));
        $this->assertSame('Acompte convenu', Encaissement::where('facture_id', $facture->id)->value('motif'));
        $this->assertSame(200_000, $facture->fresh()->resteAEncaisser());

        // Le responsable du site est notifié de l'encaissement du caissier.
        $this->assertTrue(NotificationApp::where('user_id', $this->responsable->id)->exists());
    }

    public function test_impossible_d_encaisser_au_dela_du_reste(): void
    {
        $facture = $this->facture(500_000);

        $this->actingAs($this->caissier);

        Volt::test('comptabilite.encaissements')
            ->call('choisirFacture', $facture->id)
            ->set('montant', 600_000)
            ->set('motif', 'Solde de la facture')
            ->call('encaisser')
            ->assertHasErrors(['montant']);

        $this->assertSame(0, Encaissement::where('facture_id', $facture->id)->count());
    }

    public function test_le_caissier_ne_peut_pas_encaisser_une_facture_deja_soldee_par_le_responsable(): void
    {
        $facture = $this->facture(400_000);

        // Le responsable règle la facture entre le chargement de la liste et la
        // validation du caissier : le verrou en base doit rattraper ce cas.
        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'facture_id' => $facture->id, 'date' => now()->toDateString(),
            'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 400_000,
            'cree_par' => $this->responsable->id,
        ]);

        $this->actingAs($this->caissier);

        Volt::test('comptabilite.encaissements')
            ->set('factureId', $facture->id)
            ->set('montant', 100_000)
            ->set('motif', 'Solde de la facture')
            ->call('encaisser')
            ->assertHasErrors(['montant']);

        $this->assertSame(400_000, (int) Encaissement::where('facture_id', $facture->id)->sum('montant'));
    }

    public function test_un_encaissement_sans_motif_est_refuse(): void
    {
        $facture = $this->facture(500_000);

        $this->actingAs($this->caissier);

        // « Obligatoire » veut dire refusé, pas suggéré : un champ qu'on peut laisser vide
        // finit vide sur la moitié des lignes, et la colonne n'apprend plus rien.
        Volt::test('comptabilite.encaissements')
            ->call('choisirFacture', $facture->id)
            ->set('montant', 100_000)
            ->call('encaisser')
            ->assertHasErrors(['motif']);

        $this->assertSame(0, Encaissement::where('facture_id', $facture->id)->count());
    }

    public function test_un_decaissement_sans_motif_est_refuse(): void
    {
        $this->actingAs($this->caissier);

        Volt::test('comptabilite.decaissements')
            ->set('chgTypeOp', 'Charges')
            ->set('chgLibelle', 'Achats pièces')
            ->set('chgMontant', 75_000)
            ->call('ajouterCharge')
            ->assertHasErrors(['chgMotif']);

        $this->assertSame(0, Charge::where('site_id', $this->site->id)->count());
    }

    public function test_le_caissier_enregistre_un_decaissement_sur_son_site(): void
    {
        $this->actingAs($this->caissier);

        Volt::test('comptabilite.decaissements')
            ->set('chgTypeOp', 'Charges')
            ->set('chgLibelle', 'Achats pièces')
            ->set('chgMontant', 75_000)
            ->set('chgMotif', 'Commande de plaquettes')
            ->set('chgTiers', 'Fournisseur X')
            ->call('ajouterCharge')
            ->assertHasNoErrors();

        $charge = Charge::where('site_id', $this->site->id)->firstOrFail();
        $this->assertSame(75_000, $charge->montant);
        $this->assertSame('Commande de plaquettes', $charge->motif);
        $this->assertSame($this->caissier->id, $charge->cree_par);
    }
}

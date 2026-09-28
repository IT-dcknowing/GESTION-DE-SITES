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
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Tiers;
use Modules\Noyau\Exploitation\Services\CodeDuTiers;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un code par tiers, un code par fournisseur — et pourquoi le nom ne suffisait pas.
 *
 * **La demande, du 28/09**, tient les deux bouts d'une même corde :
 *
 *   - « on doit avoir un contrôle pour éviter de saisir les mêmes clients plusieurs fois
 *     par faute d'orthographe » ;
 *   - « mais ne doit pas bloquer la création si le tiers est différent ; et s'il porte le
 *     même nom, ils doivent aussi être créés ».
 *
 * Un nom ne peut pas faire les deux. Le doublon d'orthographe coupe l'encours en deux — on
 * relance deux fois la moitié de la dette — et l'interdiction pure force à saisir un
 * homonyme sous un nom faux, ce qui est le même doublon dans l'autre sens.
 *
 * D'où la règle : **on avertit, on ne bloque pas**, et c'est le code qui distingue.
 */
class UnCodeDistingueLesHomonymesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------ la ressemblance

    public function test_une_faute_d_orthographe_est_signalee(): void
    {
        $this->creance('NSIA ASSURANCES');

        $proches = CodeDuTiers::ressemblants($this->entreprise->id, 'Nsia Assurance');

        $this->assertSame('NSIA ASSURANCES', $proches->first()['nom']);
    }

    public function test_deux_raisons_sociales_sans_rapport_ne_se_signalent_pas(): void
    {
        $this->creance('NSIA ASSURANCES');

        $this->assertTrue(
            CodeDuTiers::ressemblants($this->entreprise->id, 'ZORGHO TRANSIT')->isEmpty(),
            'Une liste d’avertissements où neuf sur dix n’en sont pas ne se lit plus.',
        );
    }

    /**
     * Le code ne se recalcule jamais.
     *
     * Un identifiant qui change n'identifie plus rien : il ne se retient pas et ne se
     * dicte pas au téléphone. Même règle que pour le code d'auteur.
     */
    public function test_un_tiers_garde_son_code(): void
    {
        $premier = CodeDuTiers::attribuer($this->entreprise->id, 'NSIA ASSURANCES');
        $second = CodeDuTiers::attribuer($this->entreprise->id, 'nsia  assurances');

        $this->assertSame($premier->id, $second->id, 'La casse et les espaces ne font pas deux tiers.');
        $this->assertSame($premier->code, $second->code);
        $this->assertSame(1, Tiers::withoutGlobalScopes()->count());
    }

    /**
     * Deux homonymes volontaires coexistent, et c'est le code qui les sépare.
     *
     * C'est la moitié de la demande que l'ancienne règle interdisait.
     */
    public function test_deux_homonymes_volontaires_recoivent_deux_codes(): void
    {
        $gerant = $this->compte('gerant');
        $this->creance('SOCIDA');

        // Le premier clic avertit, le second crée.
        Volt::actingAs($gerant)->test('recouvrement.saisie')
            ->set('nouveauTiers', 'SOCIDA')
            ->call('creerTiers')
            ->call('creerTiers')
            ->assertHasNoErrors();

        $this->assertSame(1, Tiers::withoutGlobalScopes()->count());
        $this->assertStringStartsWith('T-', Tiers::withoutGlobalScopes()->firstOrFail()->code);
    }

    // ------------------------------------------------------------------ les fournisseurs

    public function test_la_liste_des_fournisseurs_reunit_les_pieces_et_les_fiches(): void
    {
        $this->pieceFournisseur('SOCIDA', 500_000, 200_000);

        FournisseurReferentiel::consigner($this->entreprise->id, 'KALEOS', [
            'delai_reglement' => '30 jours',
        ]);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-liste');

        $noms = $ecran->instance()->annuaire->pluck('nom')->sort()->values()->all();

        // SOCIDA n'a que des pièces, KALEOS n'a qu'une fiche : les deux doivent paraître.
        $this->assertSame(['KALEOS', 'SOCIDA'], $noms);
        $ecran->assertSee('30 jours');
    }

    public function test_un_fournisseur_ajoute_a_l_ecran_recoit_son_code(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-liste')
            ->set('nom', 'RIMCO SETACI')
            ->set('delai', '45 jours fin de mois')
            ->call('ajouter');

        $fiche = FournisseurReferentiel::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('RIMCO SETACI', $fiche->nom);
        $this->assertSame('45 jours fin de mois', $fiche->delai_reglement);
        $this->assertStringStartsWith('FRS-F-', (string) $fiche->code);
    }

    public function test_un_nom_voisin_de_fournisseur_demande_confirmation(): void
    {
        $this->pieceFournisseur('SOCIDA', 500_000, 0);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-liste')
            ->set('nom', 'SOCIDA SARL')
            ->call('ajouter');

        // Rien n'est écrit au premier clic.
        $ecran->assertSet('aConfirmer', 'SOCIDA SARL');
        $this->assertSame(0, FournisseurReferentiel::withoutGlobalScopes()->count());

        $ecran->call('ajouter');

        $this->assertSame('SOCIDA SARL', FournisseurReferentiel::withoutGlobalScopes()->firstOrFail()->nom);
    }

    public function test_les_codes_manquants_s_attribuent_par_un_geste(): void
    {
        $this->pieceFournisseur('SOCIDA', 500_000, 0);
        $this->pieceFournisseur('KALEOS', 300_000, 0);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-liste');

        $this->assertSame(2, $ecran->instance()->sansCode);

        $ecran->call('coderTout');

        $this->assertSame(
            2,
            FournisseurReferentiel::withoutGlobalScopes()->whereNotNull('code')->count(),
            'Les codes se posent par un geste, jamais d’office.',
        );
    }

    /**
     * Le code naît avec la fiche, quel que soit le chemin.
     *
     * **Demandé le 28/09** : « le code doit être attribué en même temps, pas par
     * l'utilisateur ; à l'import ou à la création, le code doit être présent ».
     *
     * Trois chemins créent une fiche — le dépôt du classeur, la saisie à l'écran, le
     * bouton « coder ». Un code posé par l'appelant est un code que l'un des trois
     * oubliera, et l'oubli ne se verrait pas. Posé par le modèle, aucune fiche ne peut
     * naître sans.
     */
    public function test_une_fiche_ne_peut_pas_naitre_sans_code(): void
    {
        // Le chemin de l'import : `consigner()` sans code dans les valeurs.
        $parImport = FournisseurReferentiel::consigner($this->entreprise->id, 'KALEOS', [
            'delai_reglement' => '30 jours',
        ]);

        $this->assertStringStartsWith('FRS-F-', (string) $parImport->code);

        // Le chemin direct, sans passer par `consigner()`.
        $direct = FournisseurReferentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'SOCIDA',
            'nom_normalise' => FournisseurReferentiel::clePour('SOCIDA'),
        ]);

        $this->assertStringStartsWith('FRS-F-', (string) $direct->code);
        $this->assertNotSame($parImport->code, $direct->code, 'Deux fiches, deux codes.');
    }

    /** Et il ne se recalcule jamais : un identifiant qui change n'identifie plus rien. */
    public function test_le_code_d_une_fiche_ne_change_pas(): void
    {
        $fiche = FournisseurReferentiel::consigner($this->entreprise->id, 'KALEOS', []);
        $code = $fiche->code;

        FournisseurReferentiel::consigner($this->entreprise->id, 'kaleos', ['delai_reglement' => '45 jours']);

        $this->assertSame($code, $fiche->fresh()->code);
        $this->assertSame(1, FournisseurReferentiel::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ le décor

    private function creance(string $client): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->subDays(20),
            'n_facture' => 'F-'.mb_substr(md5($client), 0, 6),
            'client' => $client,
            'activite' => 'Mécanique',
            'montant' => 500_000,
            'exercice_impayes' => now()->year,
        ]);
    }

    private function pieceFournisseur(string $fournisseur, int $montant, int $regle): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->site->ville_id,
            'fournisseur' => $fournisseur,
            'numero_piece' => mb_substr(md5($fournisseur), 0, 6),
            'date_facture' => now()->subDays(30),
            'montant' => $montant,
            'montant_regle' => $regle,
            'reste_a_payer' => $montant - $regle,
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
            'ville_id' => $this->site->ville_id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

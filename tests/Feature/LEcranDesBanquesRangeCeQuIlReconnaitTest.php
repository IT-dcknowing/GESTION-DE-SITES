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
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'écran des banques range ce qu'il reconnaît, et montre le reste.
 *
 * **Demandé le 30/09** : « on fera pareillement pour les banques ; lorsque le bouton sera
 * cliqué, on aura différents boutons en dessous qui seront les banques, sur une même ligne,
 * et les KPI vont devoir changer en fonction de la banque sélectionnée ».
 *
 * **Ce que ce test verrouille en premier, et qui n'était pas dans la demande** : que la part
 * non reconnue ne disparaisse pas. Le nom de la banque est un champ libre depuis le début —
 * quatre banques pour quatorze orthographes, dont `BGFIU`, `BGFI+BNI` et `234665`. Un écran
 * qui ne montrerait que ce qu'il a su classer serait juste pour ce qu'il affiche et faux pour
 * ce qu'il prétend. La liste des libellés non rangés **est** le travail à faire.
 *
 * **Et que les espèces n'entrent pas dans une banque.** Une facture réglée en espèces peut
 * porter une banque — celle du chèque de la fois d'avant. Ranger ces règlements sous elle
 * gonflerait son total de ce qui n'y est jamais passé.
 */
class LEcranDesBanquesRangeCeQuIlReconnaitTest extends TestCase
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

    public function test_les_reglements_se_rangent_sous_la_banque_declaree(): void
    {
        $bgfi = $this->banque('BGFI');
        $bni = $this->banque('BNI');

        $this->reglement('BGFI', 400_000);
        $this->reglement('BGFI', 100_000);
        $this->reglement('BNI', 250_000);

        $comptes = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques')->instance()->comptes;

        $this->assertSame(500_000, (int) $comptes['b'.$bgfi->id]['montant']);
        $this->assertSame(250_000, (int) $comptes['b'.$bni->id]['montant']);
    }

    /**
     * Une banque déclarée paraît dans la ligne **même sans une seule écriture**.
     *
     * Demandé le 01/10 : « d'abord les banques seront créées manuellement dans la page banque
     * à travers le formulaire, et elle devra se mettre directement sur la ligne des banques,
     * même si elle est sans donnée ». La raison tient : on la crée *avant* d'y encaisser, et
     * un bouton qui n'apparaîtrait qu'à la première écriture laisserait croire que la
     * déclaration n'a pas pris.
     */
    public function test_une_banque_declaree_parait_meme_sans_ecriture(): void
    {
        $vide = $this->banque('ECOBANK');

        $comptes = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques')->instance()->comptes;

        $this->assertArrayHasKey('b'.$vide->id, $comptes);
        $this->assertTrue($comptes['b'.$vide->id]['vide']);
        $this->assertSame('ECOBANK', $comptes['b'.$vide->id]['libelle']);
    }

    /**
     * Les portefeuilles mobiles employés ont leur bouton, et le reliquat aussi.
     *
     * Demandé le 01/10 : « si on a ORANGE MONEY mets-le, si on a MTN money mets-le ; et pour
     * le dernier, ceux dont le mode n'a pas été déclaré, mets Moyen non précisé ». Ils sont
     * lus dans les écritures et non écrits à la main : un quatrième opérateur paraîtra sans
     * qu'on y touche, et celui qu'on cesse d'employer disparaîtra.
     */
    public function test_les_portefeuilles_mobiles_et_le_reliquat_ont_leur_bouton(): void
    {
        $this->reglement('BGFI', 400_000, moyen: 'MOBILE MONEY — WAVE');
        $this->reglement('BGFI', 60_000, moyen: 'Non précisé');

        $comptes = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques')->instance()->comptes;

        $libelles = collect($comptes)->pluck('libelle')->all();

        $this->assertContains('MOBILE MONEY — WAVE', $libelles);
        $this->assertContains('Moyen non précisé', $libelles);
    }

    /** Les indicateurs suivent le compte choisi — c'est le cœur de la demande. */
    public function test_les_indicateurs_suivent_le_compte_choisi(): void
    {
        $bgfi = $this->banque('BGFI');
        $this->banque('BNI');

        $this->reglement('BGFI', 400_000, client: 'NSIA');
        $this->reglement('BNI', 250_000, client: 'ALLIANZ');

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques');

        $this->assertSame(650_000, $ecran->instance()->kpis['montant'], 'Tous les comptes : les deux.');

        $ecran->set('supportFiltre', 'b'.$bgfi->id);

        $this->assertSame(400_000, $ecran->instance()->kpis['montant']);
        $this->assertSame(1, $ecran->instance()->kpis['nombre']);
    }

    /**
     * L'origine se filtre, et ne fait pas de boutons.
     *
     * Demandé le 01/10 : « au niveau de chacune des banques ajoute un filtre pour pouvoir
     * trier ce qui est saisi dans l'application, ce qui est importé, et les deux à la fois —
     * au lieu de venir mettre des boutons ». Les boutons disent *où* est l'argent ; l'origine
     * dit *d'où vient la ligne*.
     */
    public function test_l_origine_se_filtre_sur_chaque_compte(): void
    {
        $this->banque('BGFI');

        $this->reglement('BGFI', 400_000);                      // saisi ici
        $this->reglement('BGFI', 150_000, importe: true);       // venu d'un fichier

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques');

        $this->assertSame(550_000, $ecran->instance()->kpis['montant']);

        $ecran->set('origineFiltre', 'saisie');
        $this->assertSame(400_000, $ecran->instance()->kpis['montant']);

        $ecran->set('origineFiltre', 'import');
        $this->assertSame(150_000, $ecran->instance()->kpis['montant']);
    }

    /**
     * Un libellé qu'on ne sait pas ranger est montré, pas tu.
     *
     * Et chacun porte la raison du refus : une faute de frappe ne se corrige pas comme deux
     * banques notées dans la même case.
     */
    public function test_les_libelles_non_reconnus_sont_montres_avec_leur_raison(): void
    {
        $this->banque('BGFI');
        $this->banque('BNI');

        $this->reglement('BGFI', 400_000);
        $this->reglement('BGFIU', 90_000);      // une faute de frappe
        $this->reglement('BGFI+BNI', 70_000);   // deux banques à la fois
        $this->reglement('234665', 30_000);     // un numéro

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques');

        $nonRanges = collect($ecran->instance()->repartition['nonRanges']);

        $this->assertCount(3, $nonRanges);
        $this->assertStringContainsString('ressemble', $nonRanges->firstWhere('libelle', 'BGFIU')['raison']);
        $this->assertStringContainsString('plusieurs banques', $nonRanges->firstWhere('libelle', 'BGFI+BNI')['raison']);
        $this->assertStringContainsString('aucune lettre', $nonRanges->firstWhere('libelle', '234665')['raison']);

        // Et ils ne gonflent aucun bouton : seuls les 400 000 reconnus y sont.
        $this->assertSame(400_000, (int) collect($ecran->instance()->repartition['parBanque'])->sum('montant'));
    }

    /**
     * Un règlement en espèces n'entre dans aucune banque, même si sa facture en porte une.
     *
     * C'est le cas ordinaire : la colonne `banque` de la créance garde la banque du chèque
     * reçu la fois d'avant. Le support fait donc le tri avant la banque.
     */
    public function test_les_especes_n_entrent_dans_aucune_banque(): void
    {
        $this->banque('BGFI');

        $this->reglement('BGFI', 400_000, moyen: 'Chèque');
        $this->reglement('BGFI', 999_000, moyen: 'Espèces');

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques');

        $this->assertSame(400_000, $ecran->instance()->kpis['montant'],
            'Les espèces ne passent par aucune banque.');
    }

    /** Déclarer une banque est réservé au gérant, et la règle est revérifiée dans l'action. */
    public function test_seul_le_gerant_declare_une_banque(): void
    {
        $ecran = Volt::actingAs($this->compte('responsable_site'))->test('pilotage.banques');

        $this->assertFalse($ecran->instance()->peutDeclarer);

        $ecran->call('declarerLaBanque')->assertForbidden();

        $this->assertSame(0, Banque::withoutGlobalScopes()->count());
    }

    /** Deux écritures du même établissement ne font pas deux fiches. */
    public function test_une_banque_deja_declaree_est_refusee_sous_une_autre_orthographe(): void
    {
        $this->banque('BGFI');

        Volt::actingAs($this->compte('gerant'))->test('pilotage.banques')
            ->call('ouvrirLaDeclaration')
            ->set('nom', 'B.G.F.I')
            ->call('declarerLaBanque')
            ->assertHasErrors('nom');

        $this->assertSame(1, Banque::withoutGlobalScopes()->count());
    }

    /** Et une banque déclarée range aussitôt ses règlements. */
    public function test_declarer_une_banque_range_ses_reglements(): void
    {
        $this->reglement('BGFI', 400_000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques');

        $this->assertCount(1, $ecran->instance()->repartition['nonRanges']);

        $ecran->call('ouvrirLaDeclaration')
            ->set('nom', 'bgfi')
            ->call('declarerLaBanque')
            ->assertHasNoErrors();

        $banque = Banque::withoutGlobalScopes()->first();
        $this->assertSame('BGFI', $banque->nom, 'Le nom est enregistré en capitales.');

        $apres = Volt::actingAs($this->compte('gerant'))->test('pilotage.banques');
        $this->assertSame([], $apres->instance()->repartition['nonRanges']);
        $this->assertSame(400_000, (int) $apres->instance()->comptes['b'.$banque->id]['montant']);
    }

    // ------------------------------------------------------------------ le décor

    private function banque(string $nom): Banque
    {
        return Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => $nom,
            'nom_normalise' => Banque::clePour($nom),
        ]);
    }

    private function reglement(
        string $banqueNotee,
        int $montant,
        string $moyen = 'Chèque',
        string $client = 'NSIA ASSURANCES',
        bool $importe = false,
    ): Encaissement {
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'n_facture' => 'F-'.Facture::withoutGlobalScopes()->count(),
            'client' => $client,
            'activite' => 'Mécanique',
            'montant' => $montant,
            'banque' => $banqueNotee,
            'exercice_impayes' => now()->year,
        ]);

        return Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $facture->id,
            // L'origine ne se lit qu'à cela : un lot d'import, ou son absence.
            'lot_import_id' => $importe ? $this->lotDImport()->id : null,
            'date' => now()->toDateString(),
            'type' => 'Client',
            'moyen' => $moyen,
            'montant' => $montant,
            'client' => $client,
        ]);
    }

    private ?LotImport $lot = null;

    /** Un lot d'import, posé une fois : l'origine d'une ligne ne se lit qu'à sa présence. */
    private function lotDImport(): LotImport
    {
        return $this->lot ??= LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'format' => 'impayes',
            'nom_fichier' => 'etat-des-impayes.xlsx',
            'deposant' => 'KOFFI Désirée',
            // L'empreinte est obligatoire : c'est elle qui reconnaît un fichier redéposé.
            'empreinte' => hash('sha256', 'etat-des-impayes.xlsx'),
            'etat' => 'termine',
        ]);
    }

    /** @var array<string, User> */
    private array $comptes = [];

    /** Mémorisé : un même rôle demandé deux fois dans un test est la même personne. */
    private function compte(string $role): User
    {
        if (isset($this->comptes[$role])) {
            return $this->comptes[$role];
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $this->comptes[$role] = $compte->fresh();
    }
}

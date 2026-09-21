<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Qui paie, et qui n'a plus rien à payer.
 *
 * Deux règles se tiennent ici, et toutes deux décident où part une relance.
 *
 * **1. Le payeur.** Une facture a quatre payeurs possibles, dans cet ordre : celui chez qui
 * elle a été déposée, le courtier qui a apporté le dossier, la compagnie d'assurance, le
 * client. La règle est écrite deux fois — en PHP dans {@see Facture::tiersPayant()} et en
 * SQL dans {@see Recouvrement::EXPRESSION_TIERS_PAYANT}, parce que déduire le payeur de
 * mille trois cents factures en mémoire pour en tirer cent noms coûte une seconde. Deux
 * écritures d'une même règle divergent tôt ou tard ; ces tests sont ce qui les en empêche.
 *
 * **2. Une facture réglée n'est pas une créance.** L'état des impayés garde les créances
 * soldées — il tient le facturé, l'encaissé et le reste de l'année, et les retirer ferait
 * perdre les totaux. Mais rien ne doit proposer d'en ouvrir une de plus : « Porter à
 * l'état » n'a pas à s'afficher sur une facture qui ne doit plus rien.
 */
class LeDepotDesigneLePayeurTest extends TestCase
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
    | La règle du payeur
    |--------------------------------------------------------------------------
    */

    public function test_le_depositaire_passe_devant_le_courtier_l_assureur_et_le_client(): void
    {
        $this->actingAs($this->compte('gerant'));

        $client = $this->creance('F-1', 100_000);
        $assure = $this->creance('F-2', 100_000, assureur: 'NSIA');
        $courte = $this->creance('F-3', 100_000, assureur: 'NSIA', courtier: 'CABINET KOFFI');
        $deposee = $this->creance('F-4', 100_000, assureur: 'NSIA', courtier: 'CABINET KOFFI', deposeChez: 'SOCIETE FLOTTE');

        $this->assertSame('Client F-1', $client->tiersPayant());
        $this->assertSame('NSIA', $assure->tiersPayant());
        $this->assertSame('CABINET KOFFI', $courte->tiersPayant());

        // Le dépôt est un fait postérieur à l'édition, et il tranche : la facture est chez
        // la société, c'est elle qui règle — pas le courtier inscrit dessus.
        $this->assertSame('SOCIETE FLOTTE', $deposee->tiersPayant());
    }

    public function test_une_colonne_remplie_d_espaces_ne_designe_personne(): void
    {
        $this->actingAs($this->compte('gerant'));

        $facture = $this->creance('F-5', 100_000, courtier: 'CABINET KOFFI', deposeChez: '   ');

        // Une case blanche n'est pas un payeur. Sans ce garde-fou, une cellule copiée d'un
        // tableur avec son espace enverrait la relance à personne.
        $this->assertSame('CABINET KOFFI', $facture->tiersPayant());
    }

    public function test_la_regle_ecrite_en_sql_dit_la_meme_chose_que_celle_ecrite_en_php(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->creance('F-10', 100_000);
        $this->creance('F-11', 100_000, assureur: 'NSIA');
        $this->creance('F-12', 100_000, assureur: 'NSIA', courtier: 'CABINET KOFFI');
        $this->creance('F-13', 100_000, assureur: 'NSIA', courtier: 'CABINET KOFFI', deposeChez: 'SOCIETE FLOTTE');

        $enBase = array_keys(Recouvrement::tiersDebiteurs());

        $enMemoire = Recouvrement::facturesOuvertes()
            ->map(fn (Facture $f) => $f->tiersPayant())
            ->unique()->values()->all();

        sort($enBase);
        sort($enMemoire);

        $this->assertSame($enMemoire, $enBase);
        $this->assertContains('SOCIETE FLOTTE', $enBase);

        // Et ceux que le dépôt a dessaisis n'y sont plus : c'est tout l'objet de la règle.
        $this->assertNotContains('Client F-13', $enBase);
    }

    public function test_la_creance_deposee_se_compte_chez_le_depositaire_et_chez_lui_seul(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->creance('F-20', 300_000, assureur: 'NSIA', courtier: 'CABINET KOFFI', deposeChez: 'SOCIETE FLOTTE');
        $this->creance('F-21', 200_000, courtier: 'CABINET KOFFI');

        $arrete = now()->startOfDay();
        $parTiers = Recouvrement::parTiers(Recouvrement::facturesOuvertes($arrete), $arrete)
            ->keyBy('tiers');

        $this->assertSame(300_000, (int) $parTiers['SOCIETE FLOTTE']['reste']);
        // Le courtier ne porte plus que la créance qui n'a pas été déposée ailleurs : sans
        // cela, la même dette serait réclamée à deux tiers à la fois.
        $this->assertSame(200_000, (int) $parTiers['CABINET KOFFI']['reste']);

        $duDepositaire = Recouvrement::facturesOuvertesDuTiers('SOCIETE FLOTTE');

        $this->assertCount(1, $duDepositaire);
        $this->assertSame('F-20', $duDepositaire->first()->n_facture);
        $this->assertCount(1, Recouvrement::facturesOuvertesDuTiers('CABINET KOFFI'));
    }

    /*
    |--------------------------------------------------------------------------
    | La saisie dit chez qui la facture est déposée
    |--------------------------------------------------------------------------
    */

    public function test_une_creance_saisie_retient_son_depositaire_et_le_relance_lui(): void
    {
        Referentiel::create([
            'entreprise_id' => $this->entreprise->id,
            'type' => Referentiel::MODE_RECOUVREMENT, 'valeur' => 'Chèque', 'est_actif' => true,
        ]);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.impayes')
            ->set('fDate', now()->subDays(20)->toDateString())
            ->set('fDateReception', now()->subDays(10)->toDateString())
            ->set('fNumero', '77')
            ->set('fClient', 'ASSURE MARTIN')
            ->set('fCourtier', 'CABINET KOFFI')
            ->set('fDeposeChez', 'SOCIETE FLOTTE')
            ->set('fSiteId', $this->site->id)
            ->set('fMontant', 450_000)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $creance = Facture::withoutGlobalScopes()->where('n_facture', '77')->firstOrFail();

        $this->assertSame('SOCIETE FLOTTE', $creance->depose_chez);
        $this->assertSame('ASSURE MARTIN', $creance->client);
        $this->assertSame('SOCIETE FLOTTE', $creance->tiersPayant());
    }

    public function test_une_facture_creee_au_recouvrement_peut_etre_deposee_ailleurs(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        foreach (['ASSURE MARTIN', 'SOCIETE FLOTTE'] as $nom) {
            Referentiel::create([
                'entreprise_id' => $this->entreprise->id,
                'type' => Recouvrement::REFERENTIEL_TIERS, 'valeur' => $nom, 'est_actif' => true,
            ]);
        }

        Volt::actingAs($gerant)->test('recouvrement.saisie')
            ->set('facTiers', 'ASSURE MARTIN')
            ->set('facDeposeChez', 'SOCIETE FLOTTE')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facNumero', 'F-900')
            ->set('facMontant', 600_000)
            ->call('creerFacture')
            ->assertHasNoErrors();

        $facture = Facture::withoutGlobalScopes()->where('n_facture', 'F-900')->firstOrFail();

        $this->assertSame('SOCIETE FLOTTE', $facture->depose_chez);
        $this->assertSame('SOCIETE FLOTTE', $facture->tiersPayant());

        // Le dépositaire doit être sélectionnable comme tiers, sinon son extrait de compte
        // se refuse à s'éditer alors que c'est à lui qu'on réclame l'argent.
        $this->assertArrayHasKey('SOCIETE FLOTTE', Recouvrement::tiers());
    }

    /*
    |--------------------------------------------------------------------------
    | Une facture réglée ne se porte plus à l'état
    |--------------------------------------------------------------------------
    */

    public function test_porter_a_l_etat_n_est_pas_propose_sur_une_facture_deja_reglee(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $ouverte = $this->creance('F-30', 400_000, exercice: null);
        $soldee = $this->creance('F-31', 250_000, exercice: null);

        // Le chiffre d'affaires s'ouvre sur le mois courant : les deux factures doivent y
        // tomber, sans quoi le tableau serait vide et le test ne prouverait rien.
        $ouverte->update(['date' => now()->startOfMonth()]);
        $soldee->update(['date' => now()->startOfMonth()]);

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $soldee->id,
            'date' => now()->subDay(),
            'montant' => 250_000,
            'type' => 'Client',
            'moyen' => 'Espèces',
            'client' => $soldee->tiersPayant(),
            'activite' => $soldee->activite,
        ]);

        // La facture ouverte garde son bouton ; celle qui ne doit plus rien porte « Réglée ».
        Volt::actingAs($gerant)->test('pilotage.chiffre-affaires')
            ->assertSee(route('impayes', ['porter' => $ouverte->id]), false)
            ->assertDontSee(route('impayes', ['porter' => $soldee->id]), false)
            ->assertSee('Réglée', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    private function creance(
        string $numero,
        int $montant,
        ?string $assureur = null,
        ?string $courtier = null,
        ?string $deposeChez = null,
        mixed $exercice = 'defaut',
    ): Facture {
        $date = now()->subDays(20);

        $facture = Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => 'IMP-'.substr(md5($numero), 0, 10),
            'n_facture' => $numero,
            'date' => $date,
            'date_reception' => now()->subDays(10),
            'exercice_impayes' => $exercice === 'defaut' ? (int) $date->format('Y') : $exercice,
            'client' => 'Client '.$numero,
            'assureur' => $assureur,
            'courtier' => $courtier,
            'depose_chez' => $deposeChez,
            'montant' => $montant,
            'activite' => 'Sinistre',
        ]);

        return Facture::withoutGlobalScopes()->withSum('encaissements', 'montant')->findOrFail($facture->id);
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

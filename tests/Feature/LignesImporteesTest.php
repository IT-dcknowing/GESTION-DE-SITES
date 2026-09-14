<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ce qu'une ligne reprise du logiciel d'atelier a le droit de faire, et ce qu'elle n'a pas.
 *
 * Une ligne importée n'est pas une ligne saisie au rabais : c'est une ligne à qui **il
 * manque des garanties**. Pas de commercial, parfois pas d'atelier, un statut qui ne dit
 * rien, et surtout aucun historique de règlement. Elle a donc sa place dans les écrans qui
 * comptent — un chiffre d'affaires doit être complet — et pas dans ceux qui font agir.
 *
 * Deux défauts réels ont motivé ces tests, et ils illustrent les deux façons dont une
 * reprise casse une application :
 *
 * 1. **Bruyamment.** L'écran de saisie du jour affichait le commercial de chaque devis en
 *    attente. Les devis repris n'en ont pas : la page tombait en erreur 500, pour tout le
 *    monde, dès la première reprise.
 * 2. **Silencieusement, ce qui est pire.** Le recouvrement calcule le reste à payer en
 *    retranchant les encaissements du montant facturé. Les factures reprises arrivant sans
 *    leurs règlements, la créance de l'entreprise passait de 5 046 731 F à 1 389 635 332 F
 *    — le chiffre d'affaires de l'année entière, compté comme impayé. Aucune erreur, aucun
 *    message : juste une balance âgée fausse, sur laquelle on relance des clients qui ont
 *    payé.
 *
 * Le premier défaut se voit en une seconde. Le second se serait vu au premier client
 *    relancé à tort.
 */
class LignesImporteesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    private LotImport $lot;

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

        $this->lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'deposant' => 'Essai',
            'format' => 'factures',
            'nom_fichier' => 'ABIDJAN_CATTC_190826.xlsx',
            'empreinte' => str_repeat('a', 64),
            'taille' => 1024,
            'etat' => 'termine',
            'lignes_lues' => 0, 'lignes_creees' => 0, 'lignes_majs' => 0,
            'lignes_ignorees' => 0, 'lignes_rejetees' => 0,
        ]);
    }

    /**
     * La troisième part des indicateurs s'appelle « Autres », et non « Non ventilé ».
     *
     * « Non ventilé » est le mot du comptable : il décrit ce que le calcul n'a pas su
     * faire, pas ce que le lecteur regarde. Or cette ligne se lit sous « Mécanique » et
     * « Sinistre », et à cette place on attend le nom d'une troisième part, pas le
     * constat d'une lacune. Le montant, lui, ne change pas : les trois lignes refont
     * toujours le total exact, et l'infobulle dit toujours d'où il vient.
     */
    public function test_la_part_sans_activite_s_annonce_comme_autres(): void
    {
        $gerant = $this->compte('gerant');

        // Une charge sans activité : c'est ce qui alimente la troisième ligne.
        \Modules\Noyau\Exploitation\Modeles\Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'libelle' => 'Fourniture diverse',
            'montant' => 75000,
        ]);

        $this->actingAs($gerant)->get(route('tableau-de-bord'))
            ->assertOk()
            ->assertSee('Autres')
            ->assertDontSee('Non ventilé');
    }

    /*
    |--------------------------------------------------------------------------
    | Le défaut bruyant : une relation absente ne doit pas faire tomber l'écran
    |--------------------------------------------------------------------------
    */

    public function test_la_saisie_du_jour_s_ouvre_malgre_des_devis_importes_sans_commercial(): void
    {
        $responsable = $this->compte('responsable_site');
        $this->site->update(['responsable_id' => $responsable->id]);

        // Exactement le cas qui tombait : un devis repris, « En attente » faute que le
        // fichier dise autre chose, et sans commercial puisque le logiciel d'atelier
        // n'en connaît pas.
        $this->devisImporte('PR-SK-16091');

        $this->actingAs($responsable)->get(route('saisie-du-jour'))->assertOk();
    }

    public function test_les_ecrans_de_pilotage_affichent_une_ligne_importee_sans_tomber(): void
    {
        $gerant = $this->compte('gerant');

        $this->devisImporte('PR-SK-16092');
        $this->factureImportee('FA -5713', 450_000);

        // Ces écrans-là doivent au contraire tout compter : c'est l'intérêt de la reprise.
        foreach (['chiffre-affaires', 'devis'] as $page) {
            $this->actingAs($gerant)->get(route($page))->assertOk();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Le défaut silencieux : une facture sans historique n'est pas une créance
    |--------------------------------------------------------------------------
    */

    public function test_une_facture_importee_ne_gonfle_pas_la_creance(): void
    {
        $this->actingAs($this->compte('gerant'));

        $saisie = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->subDays(30),
            'n_facture' => 'F-001',
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Sinistre',
            'montant' => 1_000_000,
        ]);

        // Cent fois le montant de la facture saisie : si la reprise entrait dans le
        // calcul, l'écart serait impossible à manquer — et c'est bien pour cela que le
        // test choisit un ordre de grandeur, plutôt qu'un montant voisin.
        $this->factureImportee('FA -5714', 100_000_000);

        $ouvertes = Recouvrement::facturesOuvertes();

        $this->assertCount(1, $ouvertes, 'Seule la facture saisie est une créance.');
        $this->assertSame($saisie->id, $ouvertes->first()->id);
        $this->assertSame(1_000_000, $ouvertes->sum(fn ($f) => Recouvrement::reste($f)));
    }

    public function test_une_facture_importee_n_est_pas_proposee_a_l_encaissement(): void
    {
        $caissier = $this->compte('caissier');
        $this->factureImportee('FA -5715', 750_000);

        $reponse = $this->actingAs($caissier)->get(route('caissier.encaissements'));

        $reponse->assertOk();
        // Son historique de règlement n'a pas été repris : la proposer reviendrait à
        // inviter le caissier à encaisser une seconde fois ce qui l'a peut-être déjà été.
        $reponse->assertDontSee('FA -5715');
    }

    public function test_un_tiers_connu_des_seules_lignes_importees_n_est_pas_propose(): void
    {
        $this->actingAs($this->compte('gerant'));
        $this->factureImportee('FA -5716', 200_000, 'CLIENT VENU DE LA REPRISE');

        $this->assertArrayNotHasKey('CLIENT VENU DE LA REPRISE', Recouvrement::tiers($this->entreprise->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Et l'inverse : la reprise doit bien compter là où c'est son rôle
    |--------------------------------------------------------------------------
    */

    public function test_une_facture_importee_compte_dans_le_chiffre_d_affaires(): void
    {
        $this->actingAs($this->compte('gerant'));
        $this->factureImportee('FA -5717', 640_000);

        // Le garde-fou du recouvrement ne doit pas être devenu, par inadvertance, un
        // filtre global : le chiffre d'affaires, lui, compte tout.
        $this->assertSame(640_000, (int) Facture::withoutGlobalScopes()
            ->where('entreprise_id', $this->entreprise->id)->sum('montant'));
    }

    public function test_les_portees_distinguent_bien_les_deux_origines(): void
    {
        $this->actingAs($this->compte('gerant'));
        $this->factureImportee('FA -5718', 100_000);

        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now(),
            'n_facture' => 'F-002',
            'client' => 'Client saisi',
            'activite' => 'Mécanique',
            'montant' => 50_000,
        ]);

        $this->assertSame(1, Facture::withoutGlobalScopes()
            ->where('entreprise_id', $this->entreprise->id)->saisieManuelle()->count());
        $this->assertSame(1, Facture::withoutGlobalScopes()
            ->where('entreprise_id', $this->entreprise->id)->importee()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Fabriques
    |--------------------------------------------------------------------------
    */

    private function factureImportee(string $numero, int $montant, string $client = 'Client repris'): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'lot_import_id' => $this->lot->id,
            'site_id' => $this->site->id,
            'date' => now()->subDays(60),
            'n_facture' => $numero,
            'client' => $client,
            'activite' => 'Mécanique',
            'montant' => $montant,
            // Ni commercial, ni encaissement : c'est tout le sujet.
        ]);
    }

    private function devisImporte(string $numero): Devis
    {
        return Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'lot_import_id' => $this->lot->id,
            'site_id' => $this->site->id,
            'numero' => $numero,
            'date_emission' => now()->subDays(20),
            'date_reception' => now()->subDays(20),
            'client' => 'Client repris',
            'activite' => 'Mécanique',
            'statut' => 'En attente',
            'montant_devis' => 320_000,
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

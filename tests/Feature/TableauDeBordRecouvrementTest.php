<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Recouvrement\Support\PortefeuilleDeRecouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le tableau de bord du recouvrement — et surtout ce qu'il ne montre pas.
 *
 * L'écran répond à une question que le module ne traitait pas : non pas « combien nous
 * doit-on », mais « par quel dossier commencer, et qui s'en occupe ». Deux choses
 * s'y vérifient plus que les autres.
 *
 * **Le partage de la vue.** Le superviseur voit tout le portefeuille et peut l'examiner
 * agent par agent ; l'agent ne voit que le sien. Une liste déroulante ne ferme rien : le
 * test écrit l'identifiant d'un collègue dans l'adresse, comme on le ferait à la main.
 *
 * **Ce qui n'est confié à personne.** C'est la liste par laquelle un superviseur commence,
 * et elle se déduit d'un fait — l'absence de relance — jamais d'une table d'affectation
 * qui n'existe pas.
 */
class TableauDeBordRecouvrementTest extends TestCase
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

    public function test_le_tableau_de_bord_est_la_page_d_arrivee_des_trois_roles(): void
    {
        foreach (['gerant', 'superviseur_recouvrement', 'agent_recouvrement'] as $role) {
            $this->actingAs($this->compte($role, $role.'@essai.test'))
                ->get(route('recouvrement.tableau-de-bord'))
                ->assertOk();
        }
    }

    public function test_le_superviseur_voit_tout_le_portefeuille_et_l_agent_le_sien(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement', 'sup@essai.test');
        $agent = $this->compte('agent_recouvrement', 'agent@essai.test');

        $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(70));
        $this->facture('GNA ASSURANCES', 'F-002', 2_000_000, now()->subDays(40));
        $this->facture('ALLIANZ', 'F-003', 3_000_000, now()->subDays(10));

        // Chacun prend un dossier en le relançant : c'est ce geste, et lui seul, qui fait
        // d'eux les responsables — il n'existe pas de table d'affectation.
        $this->relance($agent, 'NSIA ASSURANCES', 2);
        $this->relance($superviseur, 'GNA ASSURANCES', 2);

        Volt::actingAs($superviseur)->test('recouvrement.tableau-de-bord')
            ->assertSee('NSIA ASSURANCES')
            ->assertSee('GNA ASSURANCES')
            ->assertSee('ALLIANZ');

        // L'agent n'a que le sien. Les deux autres ne lui sont pas cachés par pudeur :
        // ils ne sont pas son travail.
        Volt::actingAs($agent)->test('recouvrement.tableau-de-bord')
            ->assertSee('NSIA ASSURANCES')
            ->assertDontSee('GNA ASSURANCES');
    }

    public function test_un_agent_qui_force_l_adresse_retombe_sur_son_portefeuille(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement', 'sup@essai.test');
        $agent = $this->compte('agent_recouvrement', 'agent@essai.test');

        $this->facture('GNA ASSURANCES', 'F-002', 2_000_000, now()->subDays(40));
        $this->relance($superviseur, 'GNA ASSURANCES', 2);

        // L'identifiant d'un collègue écrit à la main dans l'adresse : une liste
        // déroulante n'a jamais fermé une URL.
        Volt::actingAs($agent)->test('recouvrement.tableau-de-bord', ['vue' => (string) $superviseur->id])
            ->assertDontSee('GNA ASSURANCES');
    }

    public function test_la_reserve_des_dossiers_a_confier_est_ouverte_a_tous(): void
    {
        $agent = $this->compte('agent_recouvrement', 'agent@essai.test');

        $this->facture('ALLIANZ', 'F-003', 3_000_000, now()->subDays(10));

        // Personne ne l'a relancé : c'est un dossier à prendre, et c'est précisément la
        // liste par laquelle on commence.
        Volt::actingAs($agent)->test('recouvrement.tableau-de-bord', ['vue' => 'libres'])
            ->assertSee('ALLIANZ')
            ->assertSee('À confier');
    }

    public function test_le_dossier_d_un_tiers_rassemble_ses_factures_ses_relances_et_ses_reglements(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement', 'sup@essai.test');

        $this->facture('NSIA ASSURANCES', 'F-777', 1_000_000, now()->subDays(70));
        $this->relance($superviseur, 'NSIA ASSURANCES', 3);

        $this->actingAs($superviseur)
            ->get(route('recouvrement.dossier', ['tiers' => 'NSIA ASSURANCES']))
            ->assertOk()
            ->assertSee('F-777')
            ->assertSee('Relances')
            // Le responsable affiché vient de la relance, seul fait daté et signé.
            ->assertSee($superviseur->name);
    }

    public function test_un_tiers_sans_dette_ne_produit_pas_une_page_en_erreur(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        // Un tiers qui ne doit rien est un cas ordinaire — c'est même le but. La page
        // doit le dire, pas tomber.
        $this->actingAs($gerant)
            ->get(route('recouvrement.dossier', ['tiers' => 'TIERS INCONNU']))
            ->assertOk()
            ->assertSee('ne doit rien');
    }

    public function test_le_portefeuille_s_emporte_dans_les_trois_formats(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');
        $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(70));

        foreach (['pdf', 'excel', 'word'] as $format) {
            $this->actingAs($gerant)
                ->get(route('recouvrement.telecharger', ['document' => 'portefeuille', 'format' => $format]))
                ->assertOk();
        }
    }

    /**
     * Le tableau de bord se lit en pleine page, et ne figure pas parmi les neuf écrans.
     *
     * Il n'est pas l'une des pages de travail du module, c'est celle d'où l'on part. Rangé
     * à côté des autres dans la barre latérale, il passait pour une dixième page et lui
     * volait deux cent trente-six points de large — ceux qui manquaient au tableau.
     */
    public function test_le_tableau_de_bord_n_a_pas_la_barre_laterale_du_module(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        $this->actingAs($gerant)->get(route('recouvrement.tableau-de-bord'))
            ->assertOk()
            ->assertDontSee('<aside class="rec-side">', escape: false)
            ->assertSee('rec-pleine');

        // Les écrans de travail, eux, la gardent : on y passe de l'un à l'autre.
        $this->actingAs($gerant)->get(route('recouvrement.saisie'))
            ->assertOk()
            ->assertSee('<aside class="rec-side">', escape: false);
    }

    public function test_le_portefeuille_se_pagine_par_numeros_sans_recharger(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        // De quoi dépasser la page : PAR_PAGE tiers, plus un.
        foreach (range(1, PortefeuilleDeRecouvrement::PAR_PAGE + 3) as $rang) {
            $this->facture('TIERS '.str_pad((string) $rang, 3, '0', STR_PAD_LEFT),
                'F-'.$rang, 100_000 * $rang, now()->subDays(40));
        }

        $reponse = $this->actingAs($gerant)->get(route('recouvrement.tableau-de-bord'))->assertOk();

        // Des numéros, et non deux flèches : atteindre la page douze ne doit pas demander
        // onze clics, et l'on doit savoir où l'on est.
        $reponse->assertSee('aria-current="page"', escape: false);

        // Tourner la page ne renvoie que le tableau : ni page rechargée, ni retour en
        // haut de l'écran. Le `href` reste à côté, pour le lecteur sans script.
        $reponse->assertSee("wire:click.prevent=\"\$set('page'", escape: false);
        $reponse->assertSee('href="'.route('recouvrement.tableau-de-bord', ['page' => 2]).'"', escape: false);
    }

    /**
     * Les filtres agissent, et sans recharger la page.
     *
     * **Ce qui a été corrigé le 24/09.** Ils étaient les champs d'un formulaire GET : le
     * moindre changement redemandait la page entière — feuilles de style, scripts, menu,
     * bandeau — pour ne changer qu'une ligne du tableau. Le propriétaire l'a mesuré à
     * l'usage : « ils font recharger la page et on a un temps de latence ». Ils sont
     * désormais liés au composant, et le test les actionne un par un — parce que la
     * seconde moitié de la demande était « vérifie ceux qui ne sont pas fonctionnels ».
     */
    public function test_chaque_filtre_du_tableau_de_bord_agit_sur_le_tableau(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement', 'sup@essai.test');
        $agent = $this->compte('agent_recouvrement', 'agent@essai.test');

        // Deux dossiers d'âges différents, donc de niveaux de relance différents.
        $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(120));
        $this->facture('ALLIANZ', 'F-002', 2_000_000, now()->subDays(5));
        $this->relance($agent, 'NSIA ASSURANCES', 5);

        $ecran = Volt::actingAs($superviseur)->test('recouvrement.tableau-de-bord');

        // Le portefeuille regardé.
        $ecran->set('vue', (string) $agent->id)
            ->assertSee('NSIA ASSURANCES')
            ->assertDontSee('ALLIANZ');

        $ecran->set('vue', 'libres')
            ->assertSee('ALLIANZ')
            ->assertDontSee('NSIA ASSURANCES');

        // Le niveau de relance.
        $ecran->set('vue', '')->set('niveauFiltre', '5')
            ->assertSee('NSIA ASSURANCES')
            ->assertDontSee('ALLIANZ');

        // La recherche par nom.
        $ecran->set('niveauFiltre', '')->set('recherche', 'allianz')
            ->assertSee('ALLIANZ')
            ->assertDontSee('NSIA ASSURANCES');

        // La période : le mois choisi change l'arrêté, qui est écrit en toutes lettres.
        $ecran->set('recherche', '')->set('moisFiltre', '1')
            ->assertSee('Encours arrêté au 31/01/'.now()->year);
    }

    public function test_un_filtre_ramene_a_la_premiere_page(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        foreach (range(1, PortefeuilleDeRecouvrement::PAR_PAGE + 3) as $rang) {
            $this->facture('TIERS '.str_pad((string) $rang, 3, '0', STR_PAD_LEFT),
                'F-'.$rang, 100_000 * $rang, now()->subDays(40));
        }

        // Filtrer depuis la page deux rendait une page deux qui n'existe plus, donc un
        // tableau vide — qu'on lit « aucun dossier », ce qui est faux.
        Volt::actingAs($gerant)->test('recouvrement.tableau-de-bord')
            ->set('page', '2')
            ->set('recherche', 'TIERS 001')
            ->assertSet('page', '1')
            ->assertSee('TIERS 001');
    }

    /**
     * Les filtres agissent sur **tout** l'écran, et pas seulement sur le tableau.
     *
     * **Ce que le propriétaire a vu le 24/09.** Il cherche « SAAR » : le tableau des tiers
     * tombe à une ligne, « Reste à recouvrer » passe à 860 466 F — mais « Encaissé sur la
     * période » reste à 1 141 472 574 F, « Charge par niveau » annonce toujours 100 tiers
     * en contentieux, et « Forme de la créance » affiche les 795 millions de l'entreprise.
     *
     * Un écran où un chiffre sur deux répond au filtre est pire qu'un écran qui n'en tient
     * aucun compte : on ne sait plus lequel des deux lire, et l'on compare des totaux qui
     * ne parlent pas du même périmètre.
     */
    public function test_la_recherche_agit_sur_tous_les_chiffres_et_pas_seulement_sur_le_tableau(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        $nsia = $this->facture('NSIA ASSURANCES', 'F-001', 4_000_000, now()->subDays(120));
        $allianz = $this->facture('ALLIANZ', 'F-002', 6_000_000, now()->subDays(120));

        $this->encaissement($nsia, 1_000_000);
        $this->encaissement($allianz, 2_000_000);

        $ecran = Volt::actingAs($gerant)->test('recouvrement.tableau-de-bord');

        // Sans filtre, les chiffres sont ceux de l'entreprise. L'encours est le reste dû :
        // 4 M et 6 M facturés, 1 M et 2 M encaissés.
        $this->assertSame(7_000_000, $ecran->instance()->reperes['encours']);
        $this->assertSame(3_000_000, $ecran->instance()->reperes['encaisse']);

        $ecran->set('recherche', 'NSIA');
        $composant = $ecran->instance();

        // L'encours suivait déjà le filtre. Ce qui ne le suivait pas :
        $this->assertSame(3_000_000, $composant->reperes['encours'], 'le reste à recouvrer');
        $this->assertSame(1_000_000, $composant->reperes['encaisse'], 'l’encaissé sur la période');
        $this->assertSame(3_000_000, array_sum(array_column($composant->tranches, 'montant')),
            'la forme de la créance');
        $this->assertSame(1, array_sum(array_column($composant->niveaux, 'tiers')),
            'la charge par niveau');
        $this->assertSame(1_000_000, array_sum(array_column($composant->mois, 'encaisse')),
            'la courbe des mois');
    }

    public function test_le_filtre_par_niveau_emporte_les_chiffres_avec_lui(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        // Deux âges, donc deux niveaux de relance : l'un passe le filtre, l'autre non.
        $vieille = $this->facture('NSIA ASSURANCES', 'F-001', 4_000_000, now()->subDays(200));
        $recente = $this->facture('ALLIANZ', 'F-002', 6_000_000, now()->subDays(40));

        $this->encaissement($vieille, 1_000_000);
        $this->encaissement($recente, 2_000_000);

        $ecran = Volt::actingAs($gerant)->test('recouvrement.tableau-de-bord')
            ->set('niveauFiltre', '5');

        $composant = $ecran->instance();

        $this->assertSame(1, $composant->reperes['tiers']);
        $this->assertSame(3_000_000, $composant->reperes['encours']);
        // Le règlement de la facture récente n'entre plus : son tiers n'est pas au niveau 5.
        $this->assertSame(1_000_000, $composant->reperes['encaisse']);
    }

    public function test_sans_filtre_les_chiffres_restent_ceux_de_l_entreprise(): void
    {
        $gerant = $this->compte('gerant', 'gerant@essai.test');

        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 4_000_000, now()->subDays(120));
        $this->encaissement($facture, 1_000_000);

        // Un règlement dont la facture est soldée n'a plus de ligne au tableau, et il doit
        // pourtant continuer de compter dans le total encaissé tant que rien n'est filtré.
        $soldee = $this->facture('CLIENT SOLDE', 'F-999', 500_000, now()->subDays(60));
        $this->encaissement($soldee, 500_000);

        $composant = Volt::actingAs($gerant)->test('recouvrement.tableau-de-bord')->instance();

        $this->assertSame(1_500_000, $composant->reperes['encaisse']);
        $this->assertSame(3_000_000, $composant->reperes['encours']);
    }

    // ------------------------------------------------------------------ utilitaires

    private function compte(string $role, string $email): User
    {
        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $compte->assignRole($role);

        return $compte->fresh();
    }

    private function facture(string $tiers, string $numero, int $montant, $date): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'client' => $tiers,
            'assureur' => $tiers,
            'n_facture' => $numero,
            'date' => $date,
            'montant' => $montant,
            'activite' => 'Sinistre',
            'type' => 'Facture',
        ]);
    }

    private function encaissement(Facture $facture, int $montant): void
    {
        DB::table('encaissements')->insert([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $facture->id,
            'date' => now()->subDays(5)->toDateString(),
            'type' => 'Client',
            'moyen' => 'Virement',
            'montant' => $montant,
            'client' => $facture->client,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function relance(User $auteur, string $tiers, int $niveau): RelanceRecouvrement
    {
        return RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'user_id' => $auteur->id,
            'responsable' => $auteur->name,
            'date' => now(),
            'tiers' => $tiers,
            'factures_visees' => 'Situation globale',
            'niveau' => $niveau,
            'canal' => 'Téléphone',
            'statut' => 'En cours',
        ]);
    }
}

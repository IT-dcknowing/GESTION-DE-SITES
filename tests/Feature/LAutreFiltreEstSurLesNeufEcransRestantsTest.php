<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;
use Modules\Noyau\Imports\Modeles\SoldeFournisseur;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * « Autre filtre » est sur les neuf écrans qui l'attendaient encore.
 *
 * **La demande, répétée trois fois.** Le 28/09 : « pour toutes ces pages, en plus des filtres
 * disponibles, fais un filtre spécial pour les colonnes présentes […] un bouton "autre
 * filtre" ». Le 29/09, l'écran à la main : « j'ai demandé d'ajouter autre filtre dans toutes
 * ces pages présentes », puis, nommément : « mets le filtre dans ces pages aussi : Tableau
 * initial / Liste des fournisseurs / Conditions de règlement / Balance fournisseurs /
 * Règlements fournisseurs ».
 *
 * **Ce que ce test verrouille, et pourquoi il ne regarde que le bouton.** Le comportement du
 * filtre — les quatre types, la garde sur les colonnes non déclarées, les deux chemins SQL et
 * mémoire — est vérifié par `UnAutreFiltreCouvreLesColonnesOublieesTest`. Ce qui manquait
 * n'était pas le mécanisme mais sa **présence** : neuf écrans ne le proposaient pas, et rien
 * ne l'aurait dit. Un écran ajouté demain sans son bouton passera par la même liste.
 *
 * **Cinq de ces neuf écrans ne filtrent pas en SQL**, et c'est la raison de la seconde moitié
 * de ce fichier : leurs tableaux sont des rapprochements calculés en mémoire, où « pièces »,
 * « taux de réalisation » ou « écart en jours » n'existent dans aucune table. Le test vérifie
 * donc que leur déclaration n'est pas vide — une déclaration vide rendrait un bouton qui
 * s'ouvre sur rien, ce qui est pire que pas de bouton.
 */
class LAutreFiltreEstSurLesNeufEcransRestantsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    /**
     * Les neuf écrans, et le composant Volt de chacun.
     *
     * Écrits ici et non devinés : un écran qui disparaît fait tomber ce test, ce qui est le
     * bon moment pour se demander où son filtre est parti.
     */
    private const ECRANS = [
        'Tableau initial — fournisseurs' => 'pilotage.fournisseurs-tableau-initial',
        'Liste des fournisseurs' => 'pilotage.fournisseurs-liste',
        'Conditions de règlement' => 'pilotage.referentiel-fournisseurs',
        'Balance fournisseurs' => 'pilotage.balance-fournisseurs',
        'Règlements fournisseurs' => 'pilotage.reglements-fournisseurs',
        'Clients de l’entreprise' => 'pilotage.clients',
        'Commerciaux' => 'pilotage.commerciaux',
        'Rapprochement prospections / devis' => 'pilotage.rapprochement-prospections-devis',
        'Rapprochement CA / impayés' => 'pilotage.rapprochement-ca-impayes',
    ];

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

    // ------------------------------------------------------------------ la présence

    public function test_les_neuf_ecrans_proposent_le_bouton(): void
    {
        $this->decor();
        $compte = $this->compte('gerant');

        foreach (self::ECRANS as $titre => $composant) {
            Volt::actingAs($compte)->test($composant)
                ->assertOk()
                ->assertSee('Autre filtre', false);
        }
    }

    /**
     * Et chacun déclare des colonnes : un bouton qui s'ouvre sur rien ne vaut rien.
     *
     * C'est la moitié du travail qu'on peut oublier — poser le composant et ne pas lui donner
     * de colonnes rend un panneau qui dit « toutes les colonnes filtrables sont déjà posées »
     * alors qu'aucune ne l'est.
     */
    public function test_chaque_ecran_declare_au_moins_trois_colonnes(): void
    {
        $this->decor();
        $compte = $this->compte('gerant');

        foreach (self::ECRANS as $titre => $composant) {
            $colonnes = Volt::actingAs($compte)->test($composant)->instance()->colonnesFiltrables;

            $this->assertGreaterThanOrEqual(3, count($colonnes),
                "L'écran « {$titre} » doit déclarer des colonnes filtrables.");

            foreach ($colonnes as $cle => $colonne) {
                $this->assertArrayHasKey('type', $colonne, "Colonne mal déclarée sur « {$titre} » : {$cle}.");
                $this->assertArrayHasKey($colonne['type'], FiltreLibre::TYPES,
                    "Type inconnu sur « {$titre} » : {$colonne['type']}.");
            }
        }
    }

    // ------------------------------------------------------- les écrans qui filtrent en mémoire

    /**
     * L'annuaire des fournisseurs se filtre sur son nombre de pièces.
     *
     * Cet écran n'est pas une table : il réunit les fiches déclarées et les fournisseurs que
     * seules les pièces connaissent. « Pièces » est un comptage fait en mémoire, et c'est la
     * question qu'on pose à un annuaire — « ceux qu'on n'a facturés qu'une fois ».
     */
    public function test_l_annuaire_des_fournisseurs_se_filtre_sur_le_nombre_de_pieces(): void
    {
        $this->piece('CFAO MOTORS', 400_000);
        $this->piece('CFAO MOTORS', 250_000);
        $this->piece('TOTAL', 90_000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-liste');

        $this->assertSame(['CFAO MOTORS', 'TOTAL'], $ecran->instance()->filtrees->pluck('nom')->sort()->values()->all());

        $ecran->set('filtresLibres', ['pieces' => ['de' => '2']]);

        $this->assertSame(['CFAO MOTORS'], $ecran->instance()->filtrees->pluck('nom')->all(),
            'Seul le fournisseur à deux pièces doit rester.');
    }

    /** Et la balance, qui est une vraie table, se filtre par tranche de montants. */
    public function test_la_balance_se_filtre_sur_une_tranche_de_solde(): void
    {
        $this->solde('CFAO MOTORS', 2_000_000);
        $this->solde('TOTAL', 50_000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.balance-fournisseurs');

        $this->assertCount(2, $ecran->instance()->lignes);

        $ecran->set('filtresLibres', ['soldes_fournisseur__solde' => ['de' => '1000000']]);

        $this->assertSame(['CFAO MOTORS'], $ecran->instance()->lignes->pluck('ligne.fournisseur')->all());
    }

    /** Les règlements se filtrent sur leur mode, qui est une liste de valeurs fixes. */
    public function test_les_reglements_se_filtrent_sur_le_mode(): void
    {
        $this->reglement('CFAO MOTORS', 'VIREMENT', 300_000);
        $this->reglement('TOTAL', 'CHEQUE', 80_000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.reglements-fournisseurs');

        // La liste déroulante ne propose que les modes réellement présents dans le fichier.
        $this->assertSame(['CHEQUE' => 'CHEQUE', 'VIREMENT' => 'VIREMENT'], $ecran->instance()->modesDeReglement);

        $ecran->set('filtresLibres', ['reglements_fournisseur__mode_reglement' => ['valeur' => 'CHEQUE']]);

        $this->assertSame(['TOTAL'], $ecran->instance()->lignes->pluck('fournisseur')->all());
    }

    /**
     * Le classement des commerciaux ne propose la commission qu'à qui la voit.
     *
     * **Le piège que cela ferme.** Les colonnes de commission se cachent déjà au non-gérant ;
     * le panneau de filtres, lui, se rendait en entier. Deux fuites, et la seconde est la
     * vraie : le filtre aurait **fonctionné**. « Commission d'au moins 300 000 » ne montre
     * aucun montant, mais il désigne exactement les personnes qui les touchent. Un droit qui
     * ne porte que sur l'affichage n'est pas un droit.
     */
    public function test_la_commission_n_est_proposee_en_filtre_qu_au_gerant(): void
    {
        $colonnesDuGerant = Volt::actingAs($this->compte('gerant'))
            ->test('pilotage.commerciaux')->instance()->colonnesFiltrables;

        $this->assertArrayHasKey('commission', $colonnesDuGerant);

        $colonnesDuResponsable = Volt::actingAs($this->compte('responsable_site'))
            ->test('pilotage.commerciaux')->instance()->colonnesFiltrables;

        $this->assertArrayNotHasKey('commission', $colonnesDuResponsable,
            'Un compte qui ne voit pas les commissions ne doit pas pouvoir filtrer dessus.');
    }

    /**
     * Le rapprochement prospections / devis déclare les colonnes du volet ouvert, et non des deux.
     *
     * Les deux volets ne portent pas les mêmes pièces : le premier propose un couple
     * prospection + devis, le second un couple facture + devis. Offrir les deux jeux à la fois
     * ferait proposer des colonnes absentes du tableau qu'on regarde, et un filtre posé dessus
     * ne rendrait rien sans dire pourquoi.
     */
    public function test_le_rapprochement_declare_les_colonnes_du_volet_ouvert(): void
    {
        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.rapprochement-prospections-devis');

        $this->assertArrayHasKey('prospection.client', $ecran->instance()->colonnesFiltrables);
        $this->assertArrayNotHasKey('facture.client', $ecran->instance()->colonnesFiltrables);

        $ecran->set('volet', 'factures');

        $this->assertArrayHasKey('facture.client', $ecran->instance()->colonnesFiltrables);
        $this->assertArrayNotHasKey('prospection.client', $ecran->instance()->colonnesFiltrables);
    }

    /**
     * Le rapprochement CA / impayés réduit ses deux listes, et laisse ses indicateurs entiers.
     *
     * Les deux listes décrivent les deux moitiés du même écart et portent les mêmes colonnes :
     * un seul panneau les gouverne. Les indicateurs, eux, comptent l'année — un taux de suivi
     * ne se lit pas sur une sélection, et l'écran le dit en clair dès qu'un filtre est posé.
     */
    public function test_le_rapprochement_ca_impayes_reduit_ses_listes_sans_toucher_aux_indicateurs(): void
    {
        $this->facture('F-001', 'NSIA ASSURANCES', 'AA-111-AA', 500_000, portee: false);
        $this->facture('F-002', 'ALLIANZ', 'BB-222-BB', 700_000, portee: false);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.rapprochement-ca-impayes');

        $this->assertCount(2, $ecran->instance()->absentes);
        $caAvant = $ecran->instance()->indicateurs['ca'];

        $ecran->set('filtresLibres', ['client' => ['valeur' => 'NSIA']]);

        $this->assertSame(['F-001'], $ecran->instance()->absentes->pluck('n_facture')->all());
        $this->assertSame($caAvant, $ecran->instance()->indicateurs['ca'],
            "Le chiffre d'affaires de l'année ne doit pas suivre un filtre de colonne.");
    }

    // ------------------------------------------------------------------ le décor

    /** Une ligne de chaque source, pour que les neuf écrans aient quelque chose à montrer. */
    private function decor(): void
    {
        $this->piece('CFAO MOTORS', 400_000);
        $this->solde('CFAO MOTORS', 2_000_000);
        $this->reglement('CFAO MOTORS', 'VIREMENT', 300_000);
        $this->facture('F-001', 'NSIA ASSURANCES', 'AA-111-AA', 500_000, portee: false);

        FournisseurReferentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'CFAO MOTORS',
            'nom_normalise' => FournisseurReferentiel::clePour('CFAO MOTORS'),
            'delai_reglement' => '30 jours',
            'jours_reglement' => 30,
        ]);
    }

    private function piece(string $fournisseur, int $montant): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'fournisseur' => $fournisseur,
            'numero_piece' => 'P-'.FactureFournisseur::withoutGlobalScopes()->count(),
            'date_facture' => now()->subDays(20)->toDateString(),
            'montant' => $montant,
            'montant_regle' => 0,
            'reste_a_payer' => $montant,
        ]);
    }

    private function solde(string $fournisseur, int $solde): SoldeFournisseur
    {
        return SoldeFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'fournisseur' => $fournisseur,
            'debit' => 0,
            'credit' => $solde,
            'solde' => $solde,
        ]);
    }

    private function reglement(string $fournisseur, string $mode, int $montant): ReglementFournisseur
    {
        return ReglementFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'fournisseur' => $fournisseur,
            'mode_reglement' => $mode,
            'code_reglement' => 'RG-'.ReglementFournisseur::withoutGlobalScopes()->count(),
            'date_reglement' => now()->subDays(5)->toDateString(),
            'montant' => $montant,
        ]);
    }

    private function facture(
        string $numero,
        string $client,
        string $plaque,
        int $montant,
        bool $portee = true,
    ): Facture {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'n_facture' => $numero,
            'client' => $client,
            'immatriculation' => $plaque,
            'activite' => 'Mécanique',
            'montant' => $montant,
            'exercice_impayes' => $portee ? now()->year : null,
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
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\Format;
use Modules\Noyau\Imports\Formats\FormatDeLaBalanceFournisseur;
use Modules\Noyau\Imports\Formats\FormatDesReglementsFournisseurs;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;
use Modules\Noyau\Imports\Modeles\SoldeFournisseur;
use Modules\Noyau\Imports\Services\Executeur;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Les deux exports fournisseurs du logiciel comptable.
 *
 * **Ce qui manquait.** Le suivi fournisseur tenu à la main était lu depuis longtemps : il
 * dit ce que l'atelier croit devoir. Les deux exports du logiciel disent ce que la
 * comptabilité a enregistré — la balance par fournisseur, et chaque paiement avec son code.
 * C'est l'écart entre les deux qu'on cherche quand un fournisseur réclame, et il n'y avait
 * aucun moyen de le voir.
 *
 * **Les en-têtes sont ceux des fichiers réels**, relevés le 21/09/2026 sur
 * `BALANCE-FOURNISSEUR(…)-010126-170926.xlsx` (118 lignes) et
 * `REGLEMENT-FOURNISSEUR(…)010126-170926.xlsx` (293 lignes), tous deux lus en simulation
 * sans un seul rejet.
 *
 * Les cas couverts ici sont ceux que ces fichiers présentent réellement : une cellule vide
 * au milieu, une date en numéro de série de tableur, une ligne de total en fin de balance,
 * et le redépôt du même fichier.
 */
class ImportBalanceEtReglementsFournisseursTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_les_deux_formats_sont_declares_au_registre(): void
    {
        // Sans cette déclaration, l'écran de dépôt ne les proposerait pas et le fichier
        // n'aurait aucun moyen d'entrer.
        $this->assertTrue(Registre::connait('balance-fournisseurs'));
        $this->assertTrue(Registre::connait('reglements-fournisseurs'));
    }

    /*
    |--------------------------------------------------------------------------
    | La balance
    |--------------------------------------------------------------------------
    */

    public function test_la_balance_entre_avec_ses_trois_colonnes(): void
    {
        $this->importer(FormatDeLaBalanceFournisseur::class, [
            ['FOURNISSEURS', 'DEBIT', 'CREDIT', 'SOLDE'],
            ["AFRICA NEGOCES COTE D'IVOIRE", 20000, 210000, 190000],
            // Telle quelle dans le fichier réel : le débit est vide.
            ['AGROCI', null, 179369, 179369],
            // Et un solde négatif, qui existe aussi : le fournisseur a été payé d'avance.
            ['C2CI', 2000000, 33601, -1966399],
        ]);

        $this->assertSame(3, SoldeFournisseur::count());

        $agroci = SoldeFournisseur::where('fournisseur', 'AGROCI')->first();
        $this->assertSame(0, $agroci->debit);
        $this->assertSame(179369, $agroci->credit);
        $this->assertSame(179369, $agroci->solde);

        // Un solde négatif n'est pas ramené à zéro : il dit quelque chose.
        $this->assertSame(-1966399, SoldeFournisseur::where('fournisseur', 'C2CI')->value('solde'));
    }

    public function test_le_solde_est_recopie_et_non_recalcule(): void
    {
        // Débit moins crédit donnerait 10 000 ; le logiciel annonce 25 000. L'écart vient
        // d'un lettrage ou d'un report, et c'est une information comptable : la recalculer
        // l'effacerait.
        $this->importer(FormatDeLaBalanceFournisseur::class, [
            ['FOURNISSEURS', 'DEBIT', 'CREDIT', 'SOLDE'],
            ['BERNABE CI', 100000, 110000, 25000],
        ]);

        $this->assertSame(25000, SoldeFournisseur::where('fournisseur', 'BERNABE CI')->value('solde'));
    }

    public function test_une_ligne_de_total_n_entre_pas_comme_un_fournisseur(): void
    {
        $this->importer(FormatDeLaBalanceFournisseur::class, [
            ['FOURNISSEURS', 'DEBIT', 'CREDIT', 'SOLDE'],
            ['AGROCI', 0, 179369, 179369],
            ['TOTAL', 0, 179369, 179369],
        ]);

        // Sinon le total se glisserait dans chaque somme que l'application calcule ensuite,
        // et doublerait le montant dû.
        $this->assertSame(1, SoldeFournisseur::count());
        $this->assertNull(SoldeFournisseur::where('fournisseur', 'TOTAL')->first());
    }

    public function test_redeposer_la_balance_met_a_jour_le_solde_au_lieu_de_l_ajouter(): void
    {
        $this->importer(FormatDeLaBalanceFournisseur::class, [
            ['FOURNISSEURS', 'DEBIT', 'CREDIT', 'SOLDE'],
            ['AGROCI', 0, 179369, 179369],
        ]);

        $this->importer(FormatDeLaBalanceFournisseur::class, [
            ['FOURNISSEURS', 'DEBIT', 'CREDIT', 'SOLDE'],
            ['AGROCI', 100000, 179369, 79369],
        ], 'balance-du-lendemain.xlsx');

        // Une balance est une photographie : la dernière remplace la précédente.
        $this->assertSame(1, SoldeFournisseur::count());
        $this->assertSame(79369, SoldeFournisseur::where('fournisseur', 'AGROCI')->value('solde'));
    }

    /*
    |--------------------------------------------------------------------------
    | Les règlements
    |--------------------------------------------------------------------------
    */

    public function test_les_reglements_entrent_avec_leur_code_et_leur_date(): void
    {
        $this->importer(FormatDesReglementsFournisseurs::class, [
            ['DATE REGLEMENT', 'CODE REGLEMENT', 'FOURNISSEURS', 'MODE REGLEMENT', 'MONTANT CFA'],
            // Les dates arrivent en numéro de série de tableur, comme dans le fichier réel.
            [46297, 'F-REG N°000300', 'SAS CI', 'T BANCAIRE', 9000000],
            [46283, 'F-REG N°000308', 'LA BOUTIQUE SARL (LBS)', 'T BANCAIRE', 2000000],
        ]);

        $this->assertSame(2, ReglementFournisseur::count());

        $premier = ReglementFournisseur::where('code_reglement', 'F-REG N°000300')->first();
        $this->assertSame('SAS CI', $premier->fournisseur);
        $this->assertSame(9000000, $premier->montant);
        $this->assertSame('T BANCAIRE', $premier->mode_reglement);

        // Le numéro de série est bien devenu une date, et non un entier recopié.
        $this->assertSame('2026-10-02', $premier->date_reglement->format('Y-m-d'));
        $this->assertSame(
            '2026-09-18',
            ReglementFournisseur::where('code_reglement', 'F-REG N°000308')->value('date_reglement')->format('Y-m-d'),
        );

        /*
         * Relevé en passant sur le fichier réel, et laissé tel quel : son nom annonce une
         * période qui s'arrête au 17/09/2026, mais il contient des règlements datés
         * d'octobre. On n'écarte rien pour autant — c'est au comptable de dire si ce sont
         * des paiements postdatés ou une erreur de saisie, et un import qui filtrerait de
         * lui-même sur le nom du fichier effacerait la question.
         */
    }

    public function test_un_montant_vide_devient_zero_et_non_une_ligne_refusee(): void
    {
        $this->importer(FormatDesReglementsFournisseurs::class, [
            ['DATE REGLEMENT', 'CODE REGLEMENT', 'FOURNISSEURS', 'MODE REGLEMENT', 'MONTANT CFA'],
            [46273, 'F-REG N°000400', 'FOURNISSEUR SANS MONTANT', 'T BANCAIRE', null],
        ]);

        // Un règlement à zéro est bizarre, mais il existe et il porte un code : le cacher
        // rendrait le total juste et la liste fausse.
        $this->assertSame(0, ReglementFournisseur::where('code_reglement', 'F-REG N°000400')->value('montant'));
    }

    public function test_deux_paiements_identiques_du_meme_jour_restent_deux_lignes(): void
    {
        // Le fichier réel en contient : même jour, même mode, même fournisseur. Seul le
        // code les distingue — c'est pour cela qu'il est la clé.
        $this->importer(FormatDesReglementsFournisseurs::class, [
            ['DATE REGLEMENT', 'CODE REGLEMENT', 'FOURNISSEURS', 'MODE REGLEMENT', 'MONTANT CFA'],
            [46273, 'F-REG N°000311', 'QUICK SERVICES', 'T BANCAIRE', 2000000],
            [46273, 'F-REG N°000306', 'QUICK SERVICES', 'T BANCAIRE', 2000000],
        ]);

        $this->assertSame(2, ReglementFournisseur::count());
    }

    public function test_redeposer_les_reglements_ne_double_aucune_ligne(): void
    {
        $lignes = [
            ['DATE REGLEMENT', 'CODE REGLEMENT', 'FOURNISSEURS', 'MODE REGLEMENT', 'MONTANT CFA'],
            [46297, 'F-REG N°000300', 'SAS CI', 'T BANCAIRE', 9000000],
        ];

        $this->importer(FormatDesReglementsFournisseurs::class, $lignes);
        $this->importer(FormatDesReglementsFournisseurs::class, $lignes, 'reglements-bis.xlsx');

        $this->assertSame(1, ReglementFournisseur::count());
    }

    public function test_un_reglement_sans_code_est_rejete_et_non_invente(): void
    {
        $this->importer(FormatDesReglementsFournisseurs::class, [
            ['DATE REGLEMENT', 'CODE REGLEMENT', 'FOURNISSEURS', 'MODE REGLEMENT', 'MONTANT CFA'],
            [46297, '', 'SAS CI', 'T BANCAIRE', 9000000],
        ]);

        // Sans code, on ne saurait ni le créer ni le retrouver : mieux vaut le dire que de
        // fabriquer une clé qui n'existe nulle part ailleurs.
        $this->assertSame(0, ReglementFournisseur::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    /**
     * Dépose un classeur et le fait traiter, comme le ferait l'écran d'import.
     *
     * @param  class-string<Format>  $format
     * @param  array<int, array<int, mixed>>  $lignes
     */
    private function importer(string $format, array $lignes, string $nom = 'export.xlsx'): void
    {
        $chemin = $this->classeurXlsx(['A' => $lignes]);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => null,
            'deposant' => 'K. Désirée',
            'format' => $format::cle(),
            'nom_fichier' => $nom,
            // Le lot ne porte pas de chemin : il le déduit de son empreinte, ce qui rend
            // impossible de lui faire lire un fichier qu'il n'a pas reçu.
            'empreinte' => hash('sha256', $nom.serialize($lignes)),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        (new Executeur($this->entreprise->id))->traiter($lot, $format);
    }
}

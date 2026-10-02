<?php

namespace Tests\Unit;

use Modules\Noyau\Exploitation\Services\NatureDeLaCreance;
use PHPUnit\Framework\TestCase;

/**
 * Trois natures de négatif, et elles ne se confondent pas.
 *
 * **La règle vient du propriétaire, le 02/10**, après dépouillement du classeur des impayés.
 * Cent vingt-neuf cellules y sont négatives, de trois natures qui n'appellent pas le même
 * traitement :
 *
 * - **44 avoirs** (−47 489 460 F) : le montant TTC lui-même est négatif ;
 * - **29 trop-perçus** (−4 446 771 F) : TTC positif, mais plus encaissé que facturé ;
 * - **12 arrondis** (−1,49 F en tout) : des centimes nés de montants décimaux.
 *
 * Les confondre coûterait cher dans les deux sens : compter un trop-perçu comme un avoir
 * annulerait une facture bien due, et compter un avoir comme un trop-perçu laisserait une
 * dette au client alors qu'elle a été annulée.
 *
 * **Et le texte ne décide de rien.** Quarante-sept lignes portent « facture d'avoir à
 * établir » : ce sont des avoirs annoncés, pas des avoirs. Leur reste est déjà à zéro, et les
 * ramasser avec les vrais doublerait la correction.
 */
class TroisNaturesDeNegatifNeSeConfondentPasTest extends TestCase
{
    /**
     * Un avoir soldé : TTC et réglé égaux et négatifs, reste à zéro.
     *
     * Quarante des quarante-quatre avoirs sont dans ce cas. C'est le piège : leur reste vaut
     * zéro, donc un classement qui regarderait le reste les prendrait pour des créances
     * ordinaires. C'est le **montant facturé** qui les dit.
     */
    public function test_un_avoir_solde_reste_un_avoir(): void
    {
        $this->assertSame(
            NatureDeLaCreance::AVOIR,
            NatureDeLaCreance::de(montant: -253_311, reste: 0),
        );
    }

    /** Les quatre avoirs non soldés : réglé vide, reste négatif. */
    public function test_un_avoir_non_solde_est_un_avoir_et_non_un_trop_percu(): void
    {
        $this->assertSame(
            NatureDeLaCreance::AVOIR,
            NatureDeLaCreance::de(montant: -2_151_640, reste: -2_151_640),
        );
    }

    /** Le plus gros avoir du classeur : SOLIBRA, facture 2439. */
    public function test_le_plus_gros_avoir_du_classeur(): void
    {
        $this->assertSame(
            NatureDeLaCreance::AVOIR,
            NatureDeLaCreance::de(montant: -19_819_280, reste: 0),
        );
    }

    /**
     * Un trop-perçu : la facture est bien due, le client a payé davantage.
     *
     * Le plus gros du classeur est CEI, facture 1372. Les commentaires le disent eux-mêmes :
     * « Trop perçu », « Facture complémentaire à faire ». Ce n'est pas un avoir.
     */
    public function test_un_trop_percu_nest_pas_un_avoir(): void
    {
        $this->assertSame(
            NatureDeLaCreance::TROP_PERCU,
            NatureDeLaCreance::de(montant: 1_000_000, reste: -2_829_585),
        );

        $this->assertTrue(NatureDeLaCreance::estUneAlerte(NatureDeLaCreance::TROP_PERCU));
    }

    /**
     * Un arrondi n'est la dette de personne, et n'est pas non plus un trop-perçu.
     *
     * Douze lignes, −1,49 F au total. Les signaler comme trop-perçus noierait les
     * vingt-neuf vrais dans du bruit.
     */
    public function test_des_centimes_ne_sont_pas_un_trop_percu(): void
    {
        // En francs entiers, il ne reste du classeur qu'un franc de part et d'autre : le
        // dernier franc d'un montant qui portait des centimes.
        $this->assertSame(
            NatureDeLaCreance::ARRONDI,
            NatureDeLaCreance::de(montant: 450_000, reste: -1),
        );
    }

    /**
     * La frontière entre l'arrondi et le trop-perçu est à un franc, des deux côtés de zéro.
     *
     * C'est le même seuil que `Recouvrement::SEUIL_SOLDE`, et délibérément le même : deux
     * tolérances différentes sur la même grandeur finiraient par se contredire.
     *
     * **Et en francs entiers, la bande des arrondis se réduit à −1.** La règle du
     * propriétaire dit « −1 < ResteàPayer < 0 », ce qui vise les centimes du classeur ;
     * aucun entier ne tient là-dedans. Le trop-perçu commence donc à −2. Trouvé en écrivant
     * ce test, qui attendait d'abord autre chose.
     */
    public function test_la_frontiere_est_a_un_franc(): void
    {
        $this->assertSame(NatureDeLaCreance::NORMALE, NatureDeLaCreance::de(900_000, 0));
        $this->assertSame(NatureDeLaCreance::ARRONDI, NatureDeLaCreance::de(900_000, -1));
        $this->assertSame(NatureDeLaCreance::TROP_PERCU, NatureDeLaCreance::de(900_000, -2));
    }

    public function test_une_creance_ordinaire_na_pas_de_nature_particuliere(): void
    {
        $this->assertSame(NatureDeLaCreance::NORMALE, NatureDeLaCreance::de(1_500_000, 1_500_000));
        $this->assertSame(NatureDeLaCreance::NORMALE, NatureDeLaCreance::de(1_500_000, 0));
        $this->assertFalse(NatureDeLaCreance::estUneAlerte(NatureDeLaCreance::NORMALE));
    }

    /**
     * Le reste sans plancher est ce qui rend les deux derniers cas visibles.
     *
     * `Recouvrement::resteDe()` écrase tout négatif à zéro, et c'est juste pour une dette :
     * un trop-perçu n'est pas une somme à relancer. Mais pour **dire** qu'il y a un
     * trop-perçu, il faut le voir — et le plancher l'efface.
     */
    public function test_le_reste_sans_plancher_laisse_voir_le_negatif(): void
    {
        $this->assertSame(-2_829_585, NatureDeLaCreance::resteSansPlancher(1_000_000, 3_829_585));
        $this->assertSame(500_000, NatureDeLaCreance::resteSansPlancher(1_000_000, 500_000));

        // Un encaissement absent vaut zéro, jamais une erreur.
        $this->assertSame(1_000_000, NatureDeLaCreance::resteSansPlancher(1_000_000, null));
    }

    /**
     * Le texte ne décide de rien : c'est le montant qui dit.
     *
     * Une facture dont les observations annoncent un avoir **à établir** reste une créance
     * ordinaire tant que l'avoir n'est pas écrit. Quarante-sept lignes du classeur sont dans
     * ce cas, et les prendre pour des avoirs doublerait la correction.
     */
    public function test_une_facture_qui_annonce_un_avoir_nen_est_pas_un(): void
    {
        // « FACTURE D'AVOIR À ÉTABLIR », montant positif, reste déjà à zéro.
        $this->assertSame(NatureDeLaCreance::NORMALE, NatureDeLaCreance::de(898_000, 0));
    }

    public function test_chaque_nature_a_un_libelle_sauf_la_normale(): void
    {
        $this->assertSame('Avoir', NatureDeLaCreance::libelle(NatureDeLaCreance::AVOIR));
        $this->assertSame('Trop-perçu', NatureDeLaCreance::libelle(NatureDeLaCreance::TROP_PERCU));
        $this->assertSame('Arrondi', NatureDeLaCreance::libelle(NatureDeLaCreance::ARRONDI));
        $this->assertSame('', NatureDeLaCreance::libelle(NatureDeLaCreance::NORMALE));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Services\ChaineDeSolde;
use Tests\TestCase;

/**
 * Le solde de caisse est un cumul, ligne à ligne, dans l'ordre du fichier.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Ce que ce fichier existe pour empêcher — relevé le 02/10 par le propriétaire.** L'écran
 * annonçait un écart de 652 917 F sur un classeur dont le solde de 696 075 F est juste.
 * « Le système annonce une anomalie qui n'en est pas une. » C'était exact.
 *
 * Deux règles manquaient, et chacune a ses tests ici :
 *
 * 1. **l'ordre est celui du fichier, jamais celui de la date** — des dizaines de lignes
 *    partagent une même date, et certaines dates n'ont pas été lues du tout ;
 * 2. **une chaîne ne traverse pas deux feuillets** — chaque feuillet repart de son propre
 *    fonds de caisse.
 *
 * S'y ajoutent les cas que le propriétaire a fait relever dans son classeur, et qui sont
 * ceux où une relecture se trompe : les annulations en montant négatif, dont deux écrites
 * **avant** la pièce qu'elles annulent, et les soldes négatifs en cours de journée.
 */
class LeSoldeDeCaisseEstUnCumulDansLOrdreDuFichierTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    /**
     * Les cinq premières lignes du classeur du propriétaire, reprises telles quelles.
     *
     * Elles viennent de la capture du 02/10 : une caisse à zéro, puis 1 000, 990 000,
     * 766 000 et 112 000 en entrée, avec les soldes que le classeur affiche lui-même.
     */
    public function test_le_cumul_est_celui_que_le_classeur_annonce(): void
    {
        $this->ligne('2026-03-17', MouvementCaisse::ENTREE, 1_000, 1_000);
        $this->ligne('2026-03-17', MouvementCaisse::ENTREE, 990_000, 991_000);
        $this->ligne('2026-03-17', MouvementCaisse::ENTREE, 766_000, 1_757_000);
        $this->ligne('2026-03-17', MouvementCaisse::ENTREE, 112_000, 1_869_000);

        $lignes = MouvementCaisse::orderBy('id')->get();

        $this->assertSame(
            $lignes->pluck('solde_annonce', 'id')->map(fn ($s) => (int) $s)->all(),
            ChaineDeSolde::soldes($lignes),
            'Notre cumul doit retrouver, ligne à ligne, ce que le classeur écrit.',
        );

        $this->assertNull(
            ChaineDeSolde::rapprochement($lignes),
            'Rien à signaler quand les deux coïncident : un indicateur qui répète « tout va bien » cesse d’être lu.',
        );
    }

    /**
     * Une annulation est un montant négatif dans la colonne de la pièce annulée.
     *
     * C'est le point que le propriétaire a fait relever et qui fait trébucher une relecture :
     * il ne faut **ni** l'ignorer, **ni** inverser son signe, **ni** la ranger dans l'autre
     * colonne. Lire « ANNULATION SORTIE » et ajouter le montant en entrée le compterait deux
     * fois, puisqu'il est déjà négatif.
     */
    public function test_une_annulation_se_traite_telle_quelle(): void
    {
        $this->ligne('2026-03-17', MouvementCaisse::SORTIE, 150_000, -150_000);
        // Annulation de la sortie : le solde remonte, car on retranche un nombre négatif.
        $annulation = $this->ligne('2026-03-17', MouvementCaisse::SORTIE, -150_000, 0);

        $this->assertSame(150_000, ChaineDeSolde::mouvement($annulation));

        $lignes = MouvementCaisse::orderBy('id')->get();
        $soldes = ChaineDeSolde::soldes($lignes);

        $this->assertSame(0, end($soldes), "L'annulation ramène la caisse à son solde d'avant.");
        $this->assertNull(ChaineDeSolde::rapprochement($lignes));
    }

    public function test_une_annulation_dentree_fait_baisser_le_solde(): void
    {
        $this->ligne('2026-04-17', MouvementCaisse::ENTREE, 103_500, 103_500);
        $annulation = $this->ligne('2026-04-17', MouvementCaisse::ENTREE, -103_500, 0);

        $this->assertSame(-103_500, ChaineDeSolde::mouvement($annulation));

        $soldes = ChaineDeSolde::soldes(MouvementCaisse::orderBy('id')->get());

        $this->assertSame(0, end($soldes));
    }

    /**
     * Une annulation écrite avant la pièce qu'elle annule ne change rien au cumul.
     *
     * Deux des huit annulations du classeur sont dans ce cas. Elles ne gênent que **la
     * recherche de la pièce d'origine**, qu'il faut chercher sur tout le fichier et non
     * seulement au-dessus. Le cumul, lui, se moque de l'ordre des deux.
     */
    public function test_une_annulation_peut_preceder_sa_piece(): void
    {
        $this->ligne('2026-07-17', MouvementCaisse::ENTREE, -440_000, -440_000);
        $this->ligne('2026-07-17', MouvementCaisse::ENTREE, 440_000, 0);

        $this->assertSame([-440_000, 0], array_values(ChaineDeSolde::soldes(MouvementCaisse::orderBy('id')->get())));
        $this->assertNull(ChaineDeSolde::rapprochement(MouvementCaisse::orderBy('id')->get()));
    }

    /**
     * L'ordre est celui du fichier, et une date mal lue ne doit pas déplacer sa ligne.
     *
     * C'est le défaut mesuré : trié par date, le rapprochement partait d'une ligne datée du
     * 31/12/1899 — le zéro d'Excel — et finissait sur une ligne datée d'octobre appartenant
     * au feuillet de janvier. Deux lignes du milieu, prises pour les bornes du classeur.
     */
    public function test_une_date_mal_lue_ne_deplace_pas_la_ligne_dans_la_chaine(): void
    {
        $this->ligne('2026-03-17', MouvementCaisse::ENTREE, 100_000, 100_000);
        // La date n'a pas été lue : Excel rend son zéro. La ligne reste à sa place.
        $perdue = $this->ligne('1899-12-31', MouvementCaisse::SORTIE, 40_000, 60_000);
        $this->ligne('2026-03-18', MouvementCaisse::ENTREE, 10_000, 70_000);

        $lignes = MouvementCaisse::orderBy('id')->get();

        $this->assertSame([100_000, 60_000, 70_000], array_values(ChaineDeSolde::soldes($lignes)));
        $this->assertNull(ChaineDeSolde::rapprochement($lignes), 'La chaîne tient malgré la date aberrante.');

        // Et la preuve que le tri par date l'aurait cassée : elle passerait en tête.
        $this->assertSame(
            $perdue->id,
            $lignes->sortBy(fn ($l) => $l->date->timestamp)->first()->id,
            'Par date, cette ligne du milieu devient la première — c’est ce qui fabriquait l’anomalie.',
        );
    }

    /**
     * Deux feuillets sont deux chaînes, et leurs cumuls ne se mettent pas bout à bout.
     *
     * Mesuré sur les 1 155 mouvements en base : suivie d'un bout à l'autre, la chaîne
     * annonçait un écart ; reprise feuillet par feuillet, un feuillet se rapproche
     * exactement et les autres donnent trois questions bornées.
     */
    public function test_deux_feuillets_sont_deux_chaines(): void
    {
        $this->ligne('2026-01-05', MouvementCaisse::ENTREE, 500_000, 500_000, 'JANV 26');
        $this->ligne('2026-01-06', MouvementCaisse::SORTIE, 500_000, 0, 'JANV 26');
        // Février repart de son propre fonds de caisse : 300 000, qui ne vient pas de janvier.
        $this->ligne('2026-02-02', MouvementCaisse::ENTREE, 20_000, 320_000, 'FEV 26');
        $this->ligne('2026-02-03', MouvementCaisse::SORTIE, 20_000, 300_000, 'FEV 26');

        $chaines = ChaineDeSolde::chaines(MouvementCaisse::orderBy('id')->get());

        $this->assertSame(['JANV 26', 'FEV 26'], $chaines->keys()->all());

        foreach ($chaines as $feuillet => $chaine) {
            $this->assertNull(
                ChaineDeSolde::rapprochement($chaine),
                "Le feuillet « {$feuillet} » se rapproche tout seul.",
            );
        }

        // Mises bout à bout, les deux chaînes annoncent un écart qui n'existe pas : le cumul
        // de janvier finit à 0, et février prétend partir de 320 000.
        $ensemble = ChaineDeSolde::rapprochement(MouvementCaisse::orderBy('id')->get());

        $this->assertNotNull($ensemble, 'Mélanger les feuillets fabrique une anomalie.');
        $this->assertSame(300_000, $ensemble['ecart']);
    }

    /**
     * Un solde négatif en cours de journée n'est pas une erreur.
     *
     * Il vient de l'ordre de saisie : une sortie écrite avant les entrées du même jour.
     * On le montre, on ne le « corrige » pas — et c'est là que la colonne « D / C » sert.
     */
    public function test_un_solde_negatif_est_montre_et_non_corrige(): void
    {
        $this->ligne('2026-03-19', MouvementCaisse::ENTREE, 155_075, 155_075);
        $this->ligne('2026-03-19', MouvementCaisse::SORTIE, 559_000, -403_925);
        $this->ligne('2026-03-19', MouvementCaisse::ENTREE, 450_000, 46_075);
        $this->ligne('2026-03-19', MouvementCaisse::ENTREE, 550_000, 596_075);

        $soldes = array_values(ChaineDeSolde::soldes(MouvementCaisse::orderBy('id')->get()));

        $this->assertSame([155_075, -403_925, 46_075, 596_075], $soldes);
        $this->assertSame(
            ['D', 'C', 'D', 'D'],
            array_map(fn ($s) => ChaineDeSolde::sens($s), $soldes),
            'Le sens suit le solde : débit quand la caisse tient de l’argent, crédit sous zéro.',
        );
    }

    public function test_le_sens_dun_solde_nul_est_le_debit(): void
    {
        // Une caisse vide ne doit rien : elle est à zéro, pas au crédit.
        $this->assertSame('D', ChaineDeSolde::sens(0));
    }

    /**
     * Sans aucun solde annoncé, le cumul part de zéro et reste exploitable.
     *
     * C'est le cas des mouvements saisis dans l'application : ils n'ont pas de colonne
     * « solde » puisqu'ils ne viennent d'aucun classeur. Les écarts d'une ligne à l'autre
     * restent justes ; seule leur origine est inconnue, et il n'y a rien à rapprocher.
     */
    public function test_sans_solde_annonce_le_cumul_part_du_premier_mouvement(): void
    {
        $this->ligne('2026-03-17', MouvementCaisse::ENTREE, 80_000, null);
        $this->ligne('2026-03-18', MouvementCaisse::SORTIE, 30_000, null);

        $lignes = MouvementCaisse::orderBy('id')->get();

        $this->assertSame([80_000, 50_000], array_values(ChaineDeSolde::soldes($lignes)));
        $this->assertNull(ChaineDeSolde::rapprochement($lignes), 'Rien d’annoncé, rien à rapprocher.');
    }

    public function test_une_chaine_vide_ne_fait_rien_tomber(): void
    {
        $this->assertSame([], ChaineDeSolde::soldes(collect()));
        $this->assertNull(ChaineDeSolde::rapprochement(collect()));
    }

    private function ligne(string $date, string $sens, int $montant, ?int $solde, string $feuille = 'A'): MouvementCaisse
    {
        return MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'sens' => $sens,
            'montant' => $montant,
            'solde_annonce' => $solde,
            'libelle' => 'Mouvement de caisse',
            'caisse' => '',
            'feuille' => $feuille,
        ]);
    }
}

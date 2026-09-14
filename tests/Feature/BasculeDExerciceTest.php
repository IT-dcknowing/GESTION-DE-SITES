<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Services\BasculeDExercice;
use Tests\TestCase;

/**
 * Le passage d'une année à l'autre, sans clôture et sans clic.
 *
 * La règle qu'on vérifie ici est celle qui a été posée : **les années ne se clôturent
 * pas.** Au 1er janvier, l'exercice suivant s'ouvre tout seul et le précédent reste
 * ouvert derrière lui. C'est ce qui permet à une facture de décembre arrivée le 8 janvier
 * de se saisir à sa date, sans rouvrir quoi que ce soit.
 */
class BasculeDExerciceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
    }

    public function test_l_annee_suivante_s_ouvre_toute_seule(): void
    {
        Carbon::setTestNow('2026-12-31 23:00');

        $exercice2026 = Exercice::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'annee' => 2026, 'statut' => 'Ouvert', 'est_defaut' => true,
        ]);

        // Minuit passe. Personne n'a rien fait.
        Carbon::setTestNow('2027-01-01 08:12');

        $actuel = Exercice::actuel($this->entreprise->id);

        $this->assertSame(2027, (int) $actuel->annee);
        $this->assertTrue((bool) $actuel->est_defaut);

        // Et surtout : 2026 est toujours là, et toujours ouverte.
        $exercice2026->refresh();
        $this->assertSame('Ouvert', $exercice2026->statut);
        $this->assertFalse((bool) $exercice2026->est_defaut);
    }

    public function test_l_annee_precedente_ne_se_ferme_jamais(): void
    {
        Carbon::setTestNow('2027-03-15');

        Exercice::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'annee' => 2026, 'statut' => 'Ouvert', 'est_defaut' => true,
        ]);

        (new BasculeDExercice)->assurer($this->entreprise->id);

        // Une facture de décembre 2026 qui arrive en mars 2027 doit pouvoir se saisir.
        $this->assertFalse(Exercice::estFerme($this->entreprise->id, 1, '2026-12-28'));
    }

    public function test_la_bascule_se_relance_sans_effet_de_bord(): void
    {
        Carbon::setTestNow('2027-01-02');

        Exercice::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'annee' => 2026, 'statut' => 'Ouvert', 'est_defaut' => true,
        ]);

        $service = new BasculeDExercice;

        $premier = $service->assurer($this->entreprise->id);
        $second = $service->assurer($this->entreprise->id);
        $troisieme = $service->assurer($this->entreprise->id);

        // La première fois bascule, les suivantes ne trouvent rien à faire.
        $this->assertTrue($premier['bascule']);
        $this->assertFalse($second['bascule']);
        $this->assertFalse($troisieme['bascule']);

        // Et il n'y a toujours que deux exercices, pas quatre.
        $this->assertSame(2, Exercice::withoutGlobalScopes()
            ->where('entreprise_id', $this->entreprise->id)->count());

        // Un seul porte le repère : deux exercices « par défaut » rendraient l'en-tête
        // dépendant de l'ordre de lecture, donc imprévisible.
        $this->assertSame(1, Exercice::withoutGlobalScopes()
            ->where('entreprise_id', $this->entreprise->id)->where('est_defaut', true)->count());
    }

    public function test_une_entreprise_qui_demarre_n_est_pas_une_bascule(): void
    {
        Carbon::setTestNow('2026-05-01');

        $resultat = (new BasculeDExercice)->assurer($this->entreprise->id);

        $this->assertSame(2026, (int) $resultat['exercice']->annee);
        // Créer le tout premier exercice n'est pas un changement d'année : rien à raconter
        // sur une année précédente qui n'a jamais existé.
        $this->assertFalse($resultat['bascule']);
        $this->assertNull($resultat['precedent']);
    }

    public function test_le_bilan_de_l_annee_ecoulee_se_lit_dans_les_tables(): void
    {
        Carbon::setTestNow('2027-01-01');

        $site = $this->siteDEssai();

        \Modules\Noyau\Exploitation\Modeles\Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $site,
            'numero' => 'FA-001', 'n_facture' => 'FA -001', 'date' => '2026-06-10', 'client' => 'LOXEA CI',
            'type' => 'FNE', 'activite' => 'Mécanique', 'montant' => 1_000_000,
        ]);

        \Modules\Noyau\Exploitation\Modeles\Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $site,
            'numero' => 'CH-001', 'date' => '2026-07-01', 'type_operation' => 'Charges',
            'activite' => 'Mécanique', 'libelle' => 'Pièces', 'montant' => 300_000,
        ]);

        // Une écriture de l'année suivante ne doit pas remonter dans le bilan de 2026.
        \Modules\Noyau\Exploitation\Modeles\Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $site,
            'numero' => 'FA-002', 'n_facture' => 'FA -002', 'date' => '2027-01-05', 'client' => 'LOXEA CI',
            'type' => 'FNE', 'activite' => 'Mécanique', 'montant' => 9_000_000,
        ]);

        $bilan = (new BasculeDExercice)->bilanDe($this->entreprise->id, 2026);

        $this->assertSame(1_000_000, $bilan['chiffre_affaires']);
        $this->assertSame(300_000, $bilan['charges']);
        $this->assertSame(700_000, $bilan['resultat']);
        $this->assertSame(1, $bilan['factures']);
    }

    private function siteDEssai(): int
    {
        $ville = \Modules\Noyau\Entreprises\Modeles\Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        return \Modules\Noyau\Entreprises\Modeles\Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Commun\Services\SerieParPoint;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Tests\TestCase;

/**
 * Une série de graphique coûte une requête, et dit exactement ce qu'elle disait.
 *
 * **Pourquoi ce fichier existe.** Mesuré le 02/10 sur `/tresorerie` : un changement de
 * filtre coûtait cinquante-trois requêtes, dont vingt-six pour la seule courbe — une somme
 * par point, deux fois. Six écrans portaient la même boucle.
 *
 * `SerieParPoint` les ramène à une requête par courbe. Mais ces courbes portent des
 * **montants**, sur une base qui porte des écritures réelles : la vitesse ne vaut rien si
 * le chiffre bouge. Ce test ne vérifie donc pas que c'est rapide — il vérifie que c'est
 * **identique**, point par point, à la boucle qu'on remplace.
 *
 * Les cas qui font tomber une réécriture de ce genre, et qui ont chacun leur test :
 *
 * - un point vide doit rendre `0`, et non rien ni `null` ;
 * - les bornes sont inclusives des deux côtés ;
 * - une ligne hors plage ne doit entrer dans aucun point ;
 * - les filtres déjà posés sur la requête doivent tenir ;
 * - la requête reçue ne doit pas ressortir modifiée.
 */
class UneSerieDeGraphiqueCoutUneRequeteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Abidjan', 'code' => 'ABJ', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'nom' => 'Atelier',
        ]);
    }

    /** La boucle d'avant, gardée ici telle quelle : c'est la référence du test. */
    private function boucleDAvant(array $points, string $activite = ''): array
    {
        $series = [];

        foreach ($points as $point) {
            $series[] = (int) $this->requete($activite)
                ->whereBetween('date', [$point['debut'], $point['fin']])
                ->sum('montant');
        }

        return $series;
    }

    private function requete(string $activite = '')
    {
        return Encaissement::query()
            ->where('entreprise_id', $this->entreprise->id)
            ->when($activite !== '', fn ($q) => $q->where('activite', $activite));
    }

    private function encaissement(string $jour, int $montant, string $activite = 'Mécanique'): void
    {
        Encaissement::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $jour,
            'montant' => $montant,
            'activite' => $activite,
            'moyen' => 'Espèces',

        ]);
    }

    public function test_la_serie_dit_exactement_ce_que_disait_la_boucle(): void
    {
        // Des montants qui ne se confondent pas : si un point prenait la somme d'un autre,
        // le total le dirait au lieu de se compenser.
        $this->encaissement('2026-03-01', 100_000);
        $this->encaissement('2026-03-01', 7_000);
        $this->encaissement('2026-03-05', 1_300_000);
        // Rien du tout entre le 6 et le 14 : ces points doivent rendre 0.
        $this->encaissement('2026-03-15', 42);
        // Dernier jour de la plage : la borne de fin est inclusive.
        $this->encaissement('2026-03-20', 999_999);
        // Hors plage, d'un jour : ne doit entrer dans aucun point.
        $this->encaissement('2026-03-21', 500_000_000);
        $this->encaissement('2026-02-28', 500_000_000);

        $points = PeriodeCalculateur::points(
            now()->parse('2026-03-01')->startOfDay(),
            now()->parse('2026-03-20')->endOfDay(),
        );

        $this->assertGreaterThan(5, count($points), 'La plage doit donner plusieurs points, sinon le test ne prouve rien.');

        $attendu = $this->boucleDAvant($points);
        $obtenu = SerieParPoint::sommes($this->requete(), $points, 'date', 'montant');

        $this->assertSame($attendu, $obtenu);
        $this->assertSame(2_407_041, array_sum($obtenu), "Le total de la série doit être celui des lignes dans la plage, et rien d'autre.");
    }

    public function test_un_filtre_deja_pose_sur_la_requete_tient(): void
    {
        $this->encaissement('2026-03-02', 800_000, 'Mécanique');
        $this->encaissement('2026-03-02', 600_000, 'Sinistre');

        $points = PeriodeCalculateur::points(
            now()->parse('2026-03-01')->startOfDay(),
            now()->parse('2026-03-20')->endOfDay(),
        );

        $this->assertSame(
            $this->boucleDAvant($points, 'Mécanique'),
            SerieParPoint::sommes($this->requete('Mécanique'), $points, 'date', 'montant'),
        );

        $this->assertSame(
            800_000,
            array_sum(SerieParPoint::sommes($this->requete('Mécanique'), $points, 'date', 'montant')),
            'Le filtre activité doit écarter le sinistre.',
        );
    }

    public function test_la_serie_ne_coute_quune_requete(): void
    {
        $this->encaissement('2026-03-02', 800_000);

        $points = PeriodeCalculateur::points(
            now()->parse('2026-03-01')->startOfDay(),
            now()->parse('2026-03-20')->endOfDay(),
        );

        DB::enableQueryLog();
        DB::flushQueryLog();

        SerieParPoint::sommes($this->requete(), $points, 'date', 'montant');

        $this->assertCount(1, DB::getQueryLog(), 'Une courbe, une requête : c\'est tout l\'objet du service.');
    }

    public function test_la_requete_recue_ressort_intacte(): void
    {
        $this->encaissement('2026-03-02', 800_000);

        $points = PeriodeCalculateur::points(
            now()->parse('2026-03-01')->startOfDay(),
            now()->parse('2026-03-20')->endOfDay(),
        );

        $requete = $this->requete();

        SerieParPoint::sommes($requete, $points, 'date', 'montant');

        // L'appelant s'en sert ensuite pour le total et pour la liste : si on lui avait
        // ajouté nos colonnes calculées, il récupérerait vingt colonnes `p0`… au lieu de
        // ses lignes.
        $this->assertSame(800_000, (int) $requete->sum('montant'));
        $this->assertSame(1, $requete->count());
    }

    public function test_les_nombres_de_lignes_se_comptent_aussi_en_une_requete(): void
    {
        $this->encaissement('2026-03-02', 10);
        $this->encaissement('2026-03-02', 20);
        $this->encaissement('2026-03-09', 30);

        $points = PeriodeCalculateur::points(
            now()->parse('2026-03-01')->startOfDay(),
            now()->parse('2026-03-20')->endOfDay(),
        );

        $attendu = [];

        foreach ($points as $point) {
            $attendu[] = $this->requete()->whereBetween('date', [$point['debut'], $point['fin']])->count();
        }

        $this->assertSame($attendu, SerieParPoint::nombres($this->requete(), $points, 'date'));
        $this->assertSame(3, array_sum(SerieParPoint::nombres($this->requete(), $points, 'date')));
    }

    /**
     * Un point hebdomadaire commence au lundi, donc parfois avant la plage demandée.
     *
     * **Le piège.** Pour que l'index sur la date serve, la requête doit être bornée. La
     * borne évidente — la plage demandée — serait fausse : `pointsHebdomadaires` fait partir
     * le premier point du lundi précédent, et la boucle d'avant comptait bel et bien ces
     * jours-là. Les borner au début de la plage ferait baisser le premier point sans que
     * rien ne le signale.
     *
     * Une plage de trois mois donne des points hebdomadaires ; elle commence un jeudi, donc
     * le premier point remonte au lundi d'avant. Une ligne posée ce lundi doit compter.
     */
    public function test_un_point_hebdomadaire_qui_depasse_la_plage_compte_quand_meme(): void
    {
        // Le 1er avril 2026 est un mercredi ; le lundi d'avant est le 30 mars.
        $debut = now()->parse('2026-04-01')->startOfDay();
        $fin = now()->parse('2026-06-30')->endOfDay();

        $points = PeriodeCalculateur::points($debut, $fin);

        $this->assertTrue(
            $points[0]['debut']->lessThan($debut),
            'Ce test ne vaut que si le premier point commence avant la plage ; sinon le piège a disparu.',
        );

        $this->encaissement('2026-03-30', 777_000);
        $this->encaissement('2026-04-02', 1_000);

        $this->assertSame(
            $this->boucleDAvant($points),
            SerieParPoint::sommes($this->requete(), $points, 'date', 'montant'),
        );

        $this->assertSame(
            778_000,
            array_sum(SerieParPoint::sommes($this->requete(), $points, 'date', 'montant')),
            'Le lundi qui précède la plage appartient au premier point : il doit compter.',
        );
    }

    /**
     * Un nom de colonne qui n'en est pas est refusé, et non exécuté.
     *
     * Les colonnes entrent dans du SQL brut — un `case when` ne se construit pas autrement.
     * Elles sont aujourd'hui écrites en dur dans les six écrans appelants ; ce test est là
     * pour le jour où un écran laissera choisir la colonne à tracer.
     */
    public function test_un_nom_de_colonne_qui_nen_est_pas_est_refuse(): void
    {
        $points = PeriodeCalculateur::points(
            now()->parse('2026-03-01')->startOfDay(),
            now()->parse('2026-03-20')->endOfDay(),
        );

        foreach (['date; drop table encaissements', 'montant) --', '1=1', 'date, montant', ''] as $tentative) {
            try {
                SerieParPoint::sommes($this->requete(), $points, $tentative, 'montant');
                $this->fail("« {$tentative} » a été accepté comme nom de colonne.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            try {
                SerieParPoint::sommes($this->requete(), $points, 'date', $tentative);
                $this->fail("« {$tentative} » a été accepté comme colonne à sommer.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Une colonne qualifiée reste permise : c'est la forme qu'il faut dès qu'il y a une
        // jointure, et il y en aura.
        $this->assertNotEmpty(
            SerieParPoint::sommes($this->requete(), $points, 'encaissements.date', 'montant'),
        );
    }

    public function test_une_plage_sans_point_ne_lance_aucune_requete(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([], SerieParPoint::sommes($this->requete(), [], 'date', 'montant'));
        $this->assertCount(0, DB::getQueryLog());
    }
}

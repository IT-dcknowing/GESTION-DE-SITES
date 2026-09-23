<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Tracabilite\Services\JournalLisible;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Le journal des modifications, tel qu'on le lit à l'écran.
 *
 * Ce qui est éprouvé ici n'est pas que le journal écrit — Spatie s'en charge — mais qu'il
 * se **lit** : qu'un identifiant y devienne un nom, qu'une date y prenne le format d'ici,
 * et surtout qu'une création n'y passe pas pour une modification.
 *
 * Ce dernier point est une correction du 24/09. La page de détail d'une créance montrait
 * « Ville : vide → Abidjan » sur la ligne de création, ce qui décrit un geste que personne
 * n'a fait : la fiche est née avec Abidjan, elle n'a pas remplacé un vide. Une création
 * n'a pas d'avant, elle *est* l'avant.
 */
class LeJournalSeLitSansLaBaseTest extends TestCase
{
    use RefreshDatabase;

    /** Une trace en mémoire : rien n'est écrit, seule la lecture est en cause. */
    private function trace(?string $evenement, array $avant, array $apres): Activity
    {
        $trace = new Activity;
        $trace->event = $evenement;
        $trace->properties = collect(array_filter([
            'old' => $avant,
            'attributes' => $apres,
        ], fn ($jeu) => $jeu !== null));

        return $trace;
    }

    // ------------------------------------------------------------------ la création

    public function test_une_creation_ne_se_lit_pas_comme_une_modification(): void
    {
        $changements = JournalLisible::changements(
            $this->trace('created', [], ['n_facture' => 'F-1', 'montant' => 120000])
        );

        foreach ($changements as $changement) {
            $this->assertTrue($changement['pose'], 'une création pose des valeurs, elle n’en remplace pas');
            $this->assertSame('', $changement['avant'], 'une création n’a pas d’avant');
        }

        $this->assertSame(['N° de facture', 'Montant'], array_column($changements, 'champ'));
    }

    public function test_une_creation_ne_liste_pas_les_champs_restes_vides(): void
    {
        $changements = JournalLisible::changements(
            $this->trace('created', [], ['n_facture' => 'F-1', 'n_sinistre' => null, 'banque' => ''])
        );

        $this->assertSame(['N° de facture'], array_column($changements, 'champ'));
    }

    public function test_sans_evenement_l_absence_d_avant_vaut_creation(): void
    {
        $this->assertTrue(JournalLisible::estUneCreation($this->trace(null, [], ['client' => 'Kouassi'])));
        $this->assertFalse(JournalLisible::estUneCreation($this->trace(null, ['client' => 'K'], ['client' => 'L'])));
    }

    // ------------------------------------------------------------------ la modification

    public function test_une_modification_se_lit_en_avant_puis_apres(): void
    {
        $changements = JournalLisible::changements(
            $this->trace('updated', ['montant' => 100000], ['montant' => 150000])
        );

        $this->assertSame([[
            'champ' => 'Montant',
            'avant' => '100000',
            'apres' => '150000',
            'pose' => false,
        ]], $changements);
    }

    public function test_un_champ_present_du_seul_cote_apres_n_est_pas_perdu(): void
    {
        // `logOnlyDirty` peut rendre un « après » sans « avant » correspondant : la lecture
        // prenait jusqu'ici les clés de l'« avant », et laissait tomber ce champ-là.
        $changements = JournalLisible::changements(
            $this->trace('updated', ['montant' => 100000], ['montant' => 100000, 'banque' => 'SGBCI'])
        );

        $this->assertSame(['Banque'], array_column($changements, 'champ'));
        $this->assertSame('vide', $changements[0]['avant']);
    }

    public function test_une_trace_sans_changement_visible_ne_rend_aucune_ligne(): void
    {
        $changements = JournalLisible::changements(
            $this->trace('updated', ['montant' => 100000], ['montant' => 100000])
        );

        $this->assertSame([], $changements, 'l’écran dira « rien n’a été modifié »');
    }

    // ------------------------------------------------------------------ les traductions

    public function test_un_identifiant_de_commercial_devient_un_nom(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $villeId = DB::table('villes')->insertGetId([
            'entreprise_id' => $entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $commercialId = DB::table('commerciaux')->insertGetId([
            'entreprise_id' => $entreprise->id, 'ville_id' => $villeId,
            'numero' => 'C1', 'nom' => 'Aya Koffi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $traces = [$this->trace('updated', ['commercial_id' => null], ['commercial_id' => $commercialId])];
        $noms = JournalLisible::noms($traces);

        $changements = JournalLisible::changements($traces[0], $noms);

        $this->assertSame('Commercial', $changements[0]['champ'], 'et non « Commercial id »');
        $this->assertSame('Aya Koffi', $changements[0]['apres']);
    }

    public function test_un_identifiant_introuvable_reste_affiche(): void
    {
        // Un blanc ferait croire à un champ vidé ; un numéro se retrouve encore en base.
        $this->assertSame('#404', JournalLisible::valeur('commercial_id', 404));
    }

    public function test_une_date_prend_le_format_d_ici(): void
    {
        $this->assertSame('17/08/2026', JournalLisible::valeur('date_reception', '2026-08-17T00:00:00.000000Z'));
    }

    public function test_le_geste_se_dit_en_francais(): void
    {
        $trace = $this->trace('created', [], []);
        $trace->description = 'created';

        $this->assertSame('Créée', JournalLisible::geste($trace));
    }

    public function test_un_champ_sans_traduction_garde_son_nom_de_colonne(): void
    {
        // Inventer un libellé pour un champ imprévu le rendrait introuvable en base.
        $this->assertSame('Courtier', JournalLisible::champ('courtier'));
    }
}

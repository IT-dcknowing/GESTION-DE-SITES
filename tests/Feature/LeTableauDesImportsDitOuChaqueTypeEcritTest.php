<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Services\TableauDesImports;
use Tests\TestCase;

/**
 * Le tableau de bord des imports sait où chaque type écrit.
 *
 * **Ce que ce test a trouvé.** La question de cet écran est « qu'est-ce qui manque
 * encore ? ». Or **trois des onze types n'affichaient aucun compteur** — le journal de
 * caisse, la balance et les règlements fournisseurs — parce qu'aucune destination n'était
 * déclarée pour eux : l'écran annonçait donc « table à créer » en rouge alors que leurs
 * tables sont pleines. Une fausse alerte permanente sur le seul écran qui doit dire vrai
 * sur ce qui reste à faire.
 *
 * La liste des destinations est écrite à la main à côté de la liste des formats : c'est une
 * seconde liste, donc une liste qui s'oublie. Ce test la confronte à la première plutôt que
 * de compter sur la vigilance — le jour où un douzième format s'écrit, il échouera tant que
 * personne n'aura dit où il range ses lignes.
 */
class LeTableauDesImportsDitOuChaqueTypeEcritTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
    }

    public function test_chaque_format_importable_sait_ou_il_ecrit(): void
    {
        foreach ((new TableauDesImports($this->entreprise->id))->lignes()->where('disponible', true) as $ligne) {
            $this->assertNotNull(
                $ligne['lignes_en_base'],
                "Le format « {$ligne['cle']} » est importable mais n'annonce aucune table : ".
                "l'écran afficherait « table à créer » alors que la table existe.",
            );
        }
    }

    public function test_les_trois_types_qui_n_avaient_pas_de_compteur_en_ont_un(): void
    {
        $lignes = (new TableauDesImports($this->entreprise->id))->lignes()->keyBy('cle');

        foreach (['journal-caisse', 'balance-fournisseurs', 'reglements-fournisseurs'] as $cle) {
            $this->assertNotNull($lignes[$cle]['lignes_en_base'], "{$cle} n'affiche aucun compteur");
        }
    }

    public function test_le_tableau_porte_une_ligne_par_format(): void
    {
        $lignes = (new TableauDesImports($this->entreprise->id))->lignes();

        // Ni plus ni moins : l'écran de dépôt répond à « qu'est-ce que je dépose ? », il
        // n'énumère pas ce qu'on ne déposera jamais.
        $this->assertSame(
            count(Registre::DISPONIBLES) + count(Registre::ANNONCES),
            $lignes->count(),
        );
    }
}

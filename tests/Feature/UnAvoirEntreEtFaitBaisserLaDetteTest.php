<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\NatureDeLaCreance;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Imports\Formats\FormatDesImpayes;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * Un avoir entre en base, et il fait baisser la dette.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Décidé le 02/10 par le propriétaire**, après dépouillement du classeur : *« montantTTC
 * < 0 : c'est un avoir. Il faut le garder en négatif et appliquer le même calcul que pour la
 * caisse. »*
 *
 * Quarante-trois lignes étaient refusées depuis le premier dépôt, pour −46 808 080 F. Deux
 * choses les bloquaient, et l'on n'en voyait qu'une :
 *
 * 1. l'import les refusait explicitement, « à traiter à part » ;
 * 2. **`factures.montant` était `bigint unsigned`** — la base ne pouvait pas écrire un
 *    négatif. Aucun code n'aurait pu accepter un avoir tant que la colonne le refusait.
 *
 * Ce fichier tient les deux bouts : qu'un avoir entre, et qu'il compte dans le bon sens.
 */
class UnAvoirEntreEtFaitBaisserLaDetteTest extends TestCase
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
     * La base peut écrire un avoir — c'était le vrai blocage.
     *
     * Sans la migration du 02/10, cette écriture lève une contrainte MySQL. Le test est donc
     * aussi celui de la colonne, et non seulement du code.
     */
    public function test_la_base_accepte_un_montant_negatif(): void
    {
        $avoir = $this->facture(-253_311, estAvoir: true);

        $this->assertSame(-253_311, $avoir->fresh()->montant);
        $this->assertTrue($avoir->fresh()->est_avoir);
    }

    /**
     * Une ligne négative n'est plus refusée à l'import, et se marque comme avoir.
     *
     * C'est la ligne exacte d'un des quarante-trois rejets, reprise de la base : LOXEA,
     * « AVOIR FN°1980 », −253 311 F, réglée pour le même montant négatif.
     */
    public function test_une_ligne_negative_entre_et_se_marque(): void
    {
        $lot = LotImport::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'deposant' => 'Reprise',
            'format' => 'impayes', 'nom_fichier' => 'Etats.xlsx', 'empreinte' => str_repeat('c', 64), 'etat' => 'termine',
        ]);

        $format = new class($this->entreprise->id, new Rattachement($this->entreprise->id)) extends FormatDesImpayes
        {
            public function motifDeRefus(array $ligne): ?string
            {
                return $this->refuser($ligne);
            }

            public function ecrireLaLigne(array $ligne, array $rattachement, int $lot): void
            {
                $this->ecrire($ligne, $rattachement, $lot);
            }
        };

        $ligne = [
            'numero' => '1980', 'date' => '2024-09-16', 'client' => 'LOXEA',
            'montant' => '-253311', 'vehicule' => 'KIA NEW SPORTAGE', 'immatriculation' => 'AA480EC',
        ];

        $this->assertNull(
            $format->motifDeRefus($ligne),
            "Un avoir n'est plus refusé : c'est la décision du 02/10.",
        );

        $format->ecrireLaLigne($ligne, [
            'ville_id' => $this->ville->id, 'site_id' => null, 'code' => null, 'source' => 'colonne', 'presumee' => false,
        ], $lot->id);

        $avoir = Facture::withoutGlobalScopes()->firstWhere('n_facture', '1980');

        $this->assertNotNull($avoir, "L'avoir doit être en base, et non dans les rejets.");
        $this->assertSame(-253_311, (int) $avoir->montant);
        $this->assertTrue($avoir->est_avoir);
    }

    /**
     * Un avoir diminue ce que le client doit, et ne s'ajoute pas à sa dette.
     *
     * C'est tout l'objet de la décision : une facture de 1 000 000 F et un avoir de
     * −253 311 F laissent 746 689 F dus, et non 1 253 311 F.
     */
    public function test_un_avoir_diminue_ce_que_le_client_doit(): void
    {
        $this->facture(1_000_000);
        $this->facture(-253_311, estAvoir: true);

        $du = Facture::withoutGlobalScopes()->sum('montant');

        $this->assertSame(746_689, (int) $du);
    }

    /**
     * Un avoir soldé reste un avoir, même quand son reste à payer vaut zéro.
     *
     * Quarante des quarante-quatre avoirs du classeur sont dans ce cas : TTC et montant réglé
     * égaux et négatifs. Un classement qui regarderait le reste les prendrait pour des
     * créances ordinaires.
     */
    public function test_un_avoir_solde_est_reconnu_par_son_montant_et_non_par_son_reste(): void
    {
        $avoir = $this->facture(-253_311, estAvoir: true);

        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $avoir->id,
            'date' => '2024-09-16',
            'type' => 'Client',
            'moyen' => 'Virement',
            'montant' => -253_311,
            'client' => 'LOXEA',
        ]);

        $reste = NatureDeLaCreance::resteSansPlancher($avoir->montant, $avoir->montantEncaisse());

        $this->assertSame(0, $reste);
        $this->assertSame(NatureDeLaCreance::AVOIR, NatureDeLaCreance::de((int) $avoir->montant, $reste));
    }

    /**
     * Un avoir n'est pas une créance à relancer.
     *
     * `Recouvrement::resteDe()` plafonne à zéro, et c'est juste : on ne relance personne sur
     * un avoir. Le plancher reste donc en place — ce que la nature apporte, c'est de pouvoir
     * **dire** ce qu'est la ligne, pas de changer ce qu'on réclame.
     */
    public function test_un_avoir_nest_pas_une_creance_a_relancer(): void
    {
        $avoir = $this->facture(-253_311, estAvoir: true);

        $this->assertSame(0, Recouvrement::reste($avoir));
    }

    /**
     * Un trop-perçu se distingue d'un avoir, et la facture n'est pas marquée.
     *
     * Vingt-neuf lignes du classeur sont dans ce cas, pour −4 446 771 F. Les confondre
     * annulerait des factures bien dues.
     */
    public function test_un_trop_percu_nest_pas_marque_comme_avoir(): void
    {
        $facture = $this->facture(1_000_000);

        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'facture_id' => $facture->id,
            'date' => '2026-01-10',
            'type' => 'Client',
            'moyen' => 'Virement',
            'montant' => 3_829_585,
            'client' => 'CEI',
        ]);

        $reste = NatureDeLaCreance::resteSansPlancher($facture->montant, $facture->montantEncaisse());

        $this->assertFalse($facture->fresh()->est_avoir);
        $this->assertSame(-2_829_585, $reste);
        $this->assertSame(NatureDeLaCreance::TROP_PERCU, NatureDeLaCreance::de((int) $facture->montant, $reste));
    }

    private function facture(int $montant, bool $estAvoir = false): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'ville_id' => $this->ville->id,
            'date' => '2024-09-16',
            'n_facture' => 'F-'.Facture::withoutGlobalScopes()->count(),
            'client' => 'LOXEA',
            'activite' => 'Mécanique',
            'montant' => $montant,
            'est_avoir' => $estAvoir,
            'exercice_impayes' => 2024,
        ]);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * L'atelier déclaré au dépôt : ce qu'il tranche, et ce qu'il n'a pas le droit de trancher.
 *
 * Le logiciel d'atelier sait filtrer son extraction par site avant de l'exporter. Déclarer
 * ce site au dépôt résout d'un coup le problème le plus dur du module — Abidjan compte deux
 * ateliers, et aucune colonne des fichiers ne les distingue.
 *
 * Mais une déclaration est une parole humaine, et la première version lui faisait une
 * confiance dangereuse. Deux défauts ont été trouvés en l'éprouvant sur les vrais fichiers,
 * et ces tests existent pour qu'ils ne reviennent pas :
 *
 * 1. **Elle déplaçait des lignes de ville.** Déclarer « Abidjan — Site 1 » envoyait à
 *    Abidjan des fiches de San Pédro. La déclaration précise désormais l'atelier à
 *    l'intérieur de sa ville ; elle ne change jamais la ville d'une ligne.
 * 2. **Elle apprenait sur un passage.** Le code YB, qui est celui de San Pédro, apparaît
 *    3 fois sur 2 204 dans le fichier d'Abidjan. Ces trois lignes suffisaient à inscrire
 *    « YB = Abidjan » dans le référentiel, et les 801 fiches de San Pédro suivaient au
 *    dépôt d'après. Trois lignes de bruit en déplaçaient huit cents.
 */
class ImportAtelierDeclareTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $sanPedro;

    private Site $site1;

    private Site $site2;

    private Site $siteSanPedro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->sanPedro = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'SPD', 'nom' => 'San Pedro', 'est_actif' => true,
        ]);

        $this->site1 = $this->site($this->abidjan, 'ABJ-1', 'Abidjan — Site 1');
        $this->site2 = $this->site($this->abidjan, 'ABJ-2', 'Abidjan — Site 2');
        $this->siteSanPedro = $this->site($this->sanPedro, 'SPD-1', 'San Pedro');
    }

    /*
    |--------------------------------------------------------------------------
    | Ce que la déclaration tranche
    |--------------------------------------------------------------------------
    */

    public function test_l_atelier_declare_departage_les_deux_ateliers_d_une_meme_ville(): void
    {
        $rattachement = new Rattachement($this->entreprise->id);

        // Le cas pour lequel la déclaration existe : la colonne dit « ABIDJAN », qui compte
        // deux ateliers, et rien d'autre ne sait lequel.
        $ou = $rattachement->resoudre(
            colonneSite: 'ABIDJAN',
            reference: 'FR-TT N° 010669',
            villeDuDepot: $this->abidjan->id,
            siteDuDepot: $this->site2->id,
        );

        $this->assertSame($this->abidjan->id, $ou['ville_id']);
        $this->assertSame($this->site2->id, $ou['site_id']);
        $this->assertSame('atelier', $ou['source']);
        $this->assertFalse($ou['presumee'], 'Une déclaration explicite n\'est pas une présomption.');
    }

    public function test_l_atelier_declare_repare_un_rattachement_anterieur_dans_la_meme_ville(): void
    {
        $rattachement = new Rattachement($this->entreprise->id);

        // La fiche était rangée dans l'atelier 1 ; le fichier filtré sur l'atelier 2 le
        // corrige. C'est le seul moyen de réparer un import ancien sans toucher la base.
        $ou = $rattachement->resoudre(
            reference: 'FR-KZN° 010669',
            villeDuDepot: $this->abidjan->id,
            siteDuDossier: $this->site1->id,
            siteDuDepot: $this->site2->id,
        );

        $this->assertSame($this->site2->id, $ou['site_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Ce qu'elle n'a pas le droit de faire
    |--------------------------------------------------------------------------
    */

    public function test_l_atelier_declare_ne_deplace_jamais_une_ligne_d_une_autre_ville(): void
    {
        // YB est établi à San Pédro.
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'YB',
            'ville_id' => $this->sanPedro->id,
            'site_id' => $this->siteSanPedro->id,
            'occurrences' => 800,
            'est_actif' => true,
        ]);

        $rattachement = new Rattachement($this->entreprise->id);

        $ou = $rattachement->resoudre(
            reference: 'FR-YBN° 014792',
            villeDuDepot: $this->abidjan->id,
            siteDuDepot: $this->site1->id,
        );

        $this->assertSame($this->sanPedro->id, $ou['ville_id'], 'La ligne reste à San Pédro.');
        $this->assertSame($this->siteSanPedro->id, $ou['site_id']);
        $this->assertSame(1, $rattachement->desaccords(), 'Le désaccord est compté, pas tu.');
    }

    public function test_un_code_croise_trois_fois_n_est_pas_rattache_a_l_atelier_declare(): void
    {
        $rattachement = new Rattachement($this->entreprise->id);

        // Trente lignes du code de la maison, trois lignes d'un code de passage : très
        // exactement la proportion mesurée sur le fichier d'Abidjan.
        for ($i = 0; $i < 30; $i++) {
            $rattachement->resoudre(
                reference: 'FR-KZN° 0106'.$i,
                villeDuDepot: $this->abidjan->id,
                siteDuDepot: $this->site1->id,
            );
        }

        for ($i = 0; $i < 3; $i++) {
            $rattachement->resoudre(
                reference: 'FR-YBN° 0147'.$i,
                villeDuDepot: $this->abidjan->id,
                siteDuDepot: $this->site1->id,
            );
        }

        $rattachement->terminer();

        $kz = CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->first();
        $yb = CodeAgent::withoutGlobalScopes()->where('code', 'YB')->first();

        $this->assertSame($this->site1->id, $kz->site_id, 'Le code installé dans le fichier est appris.');
        $this->assertNull($yb->site_id, 'Le code de passage ne l\'est pas : trois lignes ne prouvent rien.');
        $this->assertNull($yb->ville_id);
    }

    public function test_un_rattachement_pose_a_la_main_resiste_a_la_declaration(): void
    {
        // Le gérant a tranché : KZ travaille à l'atelier 2.
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'KZ',
            'ville_id' => $this->abidjan->id,
            'site_id' => $this->site2->id,
            'occurrences' => 0,
            'est_actif' => true,
        ]);

        $rattachement = new Rattachement($this->entreprise->id);

        for ($i = 0; $i < 40; $i++) {
            $rattachement->resoudre(
                reference: 'FR-KZN° 0106'.$i,
                villeDuDepot: $this->abidjan->id,
                siteDuDepot: $this->site1->id,
            );
        }

        $rattachement->terminer();

        $this->assertSame(
            $this->site2->id,
            CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->first()->site_id,
            'Un import ne défait pas une décision prise à la main.',
        );
    }

    public function test_sans_declaration_la_cascade_habituelle_reste_intacte(): void
    {
        $rattachement = new Rattachement($this->entreprise->id);

        $ou = $rattachement->resoudre(
            colonneSite: 'SAN PEDRO',
            reference: 'FR-YBN° 014792',
            villeDuDepot: $this->abidjan->id,
        );

        $this->assertSame($this->sanPedro->id, $ou['ville_id']);
        $this->assertSame('colonne', $ou['source']);
        $this->assertSame(0, $rattachement->desaccords());
    }

    private function site(Ville $ville, string $code, string $nom): Site
    {
        return Site::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'code' => $code,
            'nom' => $nom,
            'est_actif' => true,
        ]);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * Le rattachement d'une ligne importée à sa ville et à son site.
 *
 * C'est la question centrale du module, et elle est piégeuse : dans le logiciel WinDev,
 * les trois villes vivent dans la même base. Un export n'est pas une ville, c'est une
 * extraction filtrée puis renommée à la main — on l'a mesuré sur les vrais fichiers, le
 * « SanPdro_Devis » partage 216 de ses 217 proformas avec le « Abidjan_Devis », et ses
 * fiches renvoient au parc d'Abidjan.
 *
 * Une erreur ici ne se voit pas : elle produit un chiffre d'affaires plausible attribué à
 * la mauvaise ville. C'est pour cela que ces tests couvrent chaque source de décision
 * séparément, et surtout le cas où **on ne sait pas** — qui doit rester un non-choix
 * explicite, jamais un choix par défaut.
 */
class ImportRattachementTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Site $abidjanUn;

    private Site $abidjanDeux;

    private Ville $bouake;

    private Site $siteBouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        // Abidjan a deux ateliers, Bouaké un seul : c'est exactement la configuration
        // réelle, et c'est elle qui rend le rattachement au site non trivial.
        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->abidjanUn = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        $this->abidjanDeux = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-2', 'nom' => 'Abidjan — Site 2', 'est_actif' => true,
        ]);

        $this->bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $this->siteBouake = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id,
            'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
    }

    private function rattachement(): Rattachement
    {
        return new Rattachement($this->entreprise->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Le code agent, lu dans la référence
    |--------------------------------------------------------------------------
    */

    public function test_le_code_se_lit_dans_la_fiche_et_dans_la_proforma(): void
    {
        $this->assertSame('KZ', CodeAgent::extraire('FR-KZN° 010669'));
        $this->assertSame('YB', CodeAgent::extraire('FR-YBN°010153'));
        $this->assertSame('SK', CodeAgent::extraire('PR-SK-16091'));

        // Bouaké sort ses proformas sans initiales — 36 devis bien réels. Ce n'est pas
        // une anomalie à rejeter : la lecture doit l'admettre et rendre null.
        $this->assertNull(CodeAgent::extraire('PR--13699'));
        $this->assertNull(CodeAgent::extraire(''));
        $this->assertNull(CodeAgent::extraire(null));
    }

    public function test_un_code_inconnu_ne_bloque_rien_et_se_compte(): void
    {
        // Refuser la ligne reviendrait à perdre une facture réelle parce qu'on ne sait pas
        // encore qui est « TT ». Le code entre sans ville, et remonte à l'écran.
        $rattachement = $this->rattachement();
        $resultat = $rattachement->resoudre(reference: 'FR-TTN° 000123');

        $this->assertSame('TT', $resultat['code']);
        $this->assertNull($resultat['ville_id']);
        $this->assertSame('inconnue', $resultat['source']);

        // Les compteurs sont accumulés puis écrits d'un bloc en fin de parcours : sur un
        // fichier de neuf mille lignes, incrémenter la base à chaque rencontre coûtait
        // plus cher que tout le reste de l'import réuni.
        $rattachement->terminer();

        $agent = CodeAgent::withoutGlobalScopes()->where('code', 'TT')->first();
        $this->assertNotNull($agent);
        $this->assertSame(1, $agent->occurrences);
        $this->assertFalse($agent->estRenseigne());

        // Deuxième rencontre : le compteur monte, la fiche ne se duplique pas.
        $suivant = $this->rattachement();
        $suivant->resoudre(reference: 'FR-TTN° 000124');
        $suivant->terminer();
        $this->assertSame(1, CodeAgent::withoutGlobalScopes()->where('code', 'TT')->count());
        $this->assertSame(2, CodeAgent::withoutGlobalScopes()->where('code', 'TT')->first()->occurrences);
    }

    /*
    |--------------------------------------------------------------------------
    | Abidjan et ses deux ateliers
    |--------------------------------------------------------------------------
    | Le cœur de la demande : aucun fichier ne distingue Site 1 de Site 2. La colonne
    | SITE dit « ABIDJAN », un point c'est tout. Seul le code de la personne qui a
    | rédigé la fiche peut trancher.
    */

    public function test_le_code_rattache_a_un_site_place_la_ligne_sur_ce_site(): void
    {
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'site_id' => $this->abidjanDeux->id,
            'code' => 'KZ', 'libelle' => 'K. Zoumana', 'est_actif' => true,
        ]);

        $resultat = $this->rattachement()->resoudre(colonneSite: 'ABIDJAN', reference: 'FR-KZN° 010669');

        $this->assertSame($this->abidjan->id, $resultat['ville_id']);
        $this->assertSame($this->abidjanDeux->id, $resultat['site_id']);
        $this->assertFalse($resultat['presumee']);
    }

    public function test_sans_site_sur_le_code_la_ligne_s_arrete_a_la_ville(): void
    {
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'site_id' => null,
            'code' => 'AB', 'est_actif' => true,
        ]);

        $resultat = $this->rattachement()->resoudre(colonneSite: 'ABIDJAN', reference: 'FR-ABN° 011664');

        // La ville est sûre, l'atelier ne l'est pas. Ranger la ligne au hasard entre deux
        // ateliers produirait un chiffre d'affaires attribué au mauvais site — pire qu'un
        // chiffre en attente d'affectation.
        $this->assertSame($this->abidjan->id, $resultat['ville_id']);
        $this->assertNull($resultat['site_id']);
    }

    public function test_une_ville_a_site_unique_n_a_pas_besoin_de_code(): void
    {
        // Bouaké n'a qu'un atelier : il n'y a pas de choix à faire, donc pas de question
        // à poser. C'est ce qui limite la répartition manuelle à Abidjan.
        $resultat = $this->rattachement()->resoudre(colonneSite: 'BOUAKE');

        $this->assertSame($this->bouake->id, $resultat['ville_id']);
        $this->assertSame($this->siteBouake->id, $resultat['site_id']);
    }

    public function test_un_code_dont_le_site_est_ailleurs_n_est_pas_impose(): void
    {
        // Un agent d'Abidjan qui dépanne à Bouaké : la ville du fichier fait foi, mais on
        // ne lui colle pas son atelier habituel, qui est dans une autre ville.
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'site_id' => $this->abidjanUn->id,
            'code' => 'KZ', 'est_actif' => true,
        ]);

        $resultat = $this->rattachement()->resoudre(colonneSite: 'BOUAKE', reference: 'FR-KZN° 010669');

        $this->assertSame($this->bouake->id, $resultat['ville_id']);
        $this->assertSame($this->siteBouake->id, $resultat['site_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | L'ordre d'autorité des sources
    |--------------------------------------------------------------------------
    */

    public function test_le_dossier_deja_rattache_prime_sur_tout(): void
    {
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->bouake->id,
            'code' => 'YK', 'est_actif' => true,
        ]);

        // La colonne dit Bouaké, le code dit Bouaké — mais la fiche est déjà connue et
        // rattachée à Abidjan. Une fiche ne change pas de ville entre deux imports.
        $resultat = $this->rattachement()->resoudre(
            colonneSite: 'BOUAKE',
            reference: 'FR-YKN° 012182',
            siteDuDossier: $this->abidjanUn->id,
        );

        $this->assertSame($this->abidjan->id, $resultat['ville_id']);
        $this->assertSame($this->abidjanUn->id, $resultat['site_id']);
        $this->assertSame('dossier', $resultat['source']);
    }

    public function test_la_colonne_site_prime_sur_le_code(): void
    {
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'code' => 'AB', 'est_actif' => true,
        ]);

        $resultat = $this->rattachement()->resoudre(colonneSite: 'BOUAKE', reference: 'FR-ABN° 011664');

        // Une colonne SITE est une donnée du fichier ; un code est une déduction. La
        // donnée l'emporte sur la déduction.
        $this->assertSame($this->bouake->id, $resultat['ville_id']);
        $this->assertSame('colonne', $resultat['source']);
    }

    public function test_la_ville_du_depot_ne_sert_qu_en_dernier_recours_et_se_signale(): void
    {
        $resultat = $this->rattachement()->resoudre(villeDuDepot: $this->bouake->id);

        $this->assertSame($this->bouake->id, $resultat['ville_id']);
        $this->assertSame('depot', $resultat['source']);
        // C'est cette mention que l'écran met en évidence : la ligne est entrée, mais
        // personne n'a prouvé qu'elle appartient à cette ville.
        $this->assertTrue($resultat['presumee']);
    }

    /*
    |--------------------------------------------------------------------------
    | Les fautes de frappe des fichiers
    |--------------------------------------------------------------------------
    */

    public function test_les_orthographes_fantaisistes_du_site_sont_rattrapees(): void
    {
        // Trois valeurs relevées dans les vrais exports, pour deux villes.
        foreach (['ABIDJAN', 'ÄBIDJAN', 'abidjan', 'ABIDJAN '] as $ecriture) {
            $resultat = $this->rattachement()->resoudre(colonneSite: $ecriture);

            $this->assertSame(
                $this->abidjan->id,
                $resultat['ville_id'],
                "L'écriture « $ecriture » doit rejoindre Abidjan.",
            );
        }

        $this->assertSame('SAN PEDRO', CorrespondanceImport::normaliser('SAN-PEDRO'));

        // La normalisation efface la casse, les accents et la ponctuation — elle
        // n'invente pas de correction. « ABIIDJAN » reste « ABIIDJAN » : une lettre en
        // trop n'est pas une variante d'écriture, c'est une faute, et c'est la table des
        // correspondances qui la tranche (test suivant). Une normalisation qui devinerait
        // rapprocherait aussi des noms qui n'ont rien à voir.
        $this->assertSame('ABIIDJAN', CorrespondanceImport::normaliser('ABIIDJAN'));
    }

    public function test_un_site_inconnu_pose_une_question_au_lieu_de_bloquer(): void
    {
        $rattachement = $this->rattachement();
        $resultat = $rattachement->resoudre(colonneSite: 'ABIIDJAN');

        // « ABIIDJAN » — un i de trop, une seule ligne dans le vrai fichier. On ne devine
        // pas : la ville reste vide et la correspondance attend d'être tranchée.
        $this->assertNull($resultat['ville_id']);

        $rattachement->terminer();

        $question = CorrespondanceImport::withoutGlobalScopes()
            ->where('domaine', 'site')->where('valeur_source', 'ABIIDJAN')->first();

        $this->assertNotNull($question);
        $this->assertFalse($question->est_resolue);

        // Une fois la correspondance posée, l'import suivant sait.
        $question->update(['valeur_cible' => 'Abidjan', 'est_resolue' => true]);

        $this->assertSame(
            $this->abidjan->id,
            $this->rattachement()->resoudre(colonneSite: 'ABIIDJAN')['ville_id'],
        );
    }

    public function test_le_nom_d_un_site_designe_sa_ville(): void
    {
        // Les états d'entrées et sorties annoncent « SITE 1 » en en-tête, pas « ABIDJAN ».
        $resultat = $this->rattachement()->resoudre(colonneSite: 'ABJ-1');

        $this->assertSame($this->abidjan->id, $resultat['ville_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Le cloisonnement entre entreprises
    |--------------------------------------------------------------------------
    */

    public function test_le_rattachement_ne_traverse_pas_les_entreprises(): void
    {
        $voisine = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $villeVoisine = Ville::create([
            'entreprise_id' => $voisine->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $voisine->id, 'ville_id' => $villeVoisine->id,
            'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        // Deux entreprises peuvent avoir une ville du même nom : le rattachement doit
        // rester dans la sienne, sinon l'encours d'un client atterrit chez un autre.
        $resultat = $this->rattachement()->resoudre(colonneSite: 'ABIDJAN');

        $this->assertSame($this->abidjan->id, $resultat['ville_id']);
        $this->assertNotSame($villeVoisine->id, $resultat['ville_id']);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Services\ReconnaissanceDeBanque as R;
use Tests\TestCase;

/**
 * Une banque se reconnaît, se propose, ou se refuse — jamais ne se devine.
 *
 * **Ce que ce test éprouve, ce sont les vraies valeurs.** Elles sont relevées dans
 * `factures.banque` le 01/10, où le nom de la banque est un champ libre depuis le début :
 *
 * | Libellé | Occurrences | Ce que c'est |
 * |---|---|---|
 * | `BGFI` | 5 472 | une banque |
 * | `BNI` · `BDA` · `AFG` | 287 · 106 · 77 | trois autres |
 * | `BGFIU` | 1 | une faute de frappe |
 * | `BGFI+BNI` · `BNI/BGFI` · `BGFI/AFG` · `BNI+BGFI` | 1 chacune | deux banques à la fois |
 * | `BGFI/BGFI` | 1 | la même deux fois |
 * | `234665` | 1 | un numéro tombé dans la mauvaise case |
 * | `CAISSE` · `wave` | 1 chacune | des supports qui ne sont pas des banques |
 *
 * **Le cas qui compte le plus est `BGFI+BNI`.** Il dit qu'un règlement a transité par deux
 * comptes, ou qu'on a noté deux choses dans une case prévue pour une. Dans les deux cas,
 * choisir l'une des deux fausserait les deux totaux — et c'est précisément la question à
 * laquelle l'écran des banques sert à répondre.
 */
class UneBanqueSeReconnaitOuSeRefuseTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        foreach (['BGFI', 'BNI', 'BDA', 'AFG'] as $nom) {
            Banque::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entreprise->id,
                'nom' => $nom,
                'nom_normalise' => Banque::clePour($nom),
            ]);
        }
    }

    public function test_un_nom_exact_est_reconnu_sans_hesitation(): void
    {
        foreach (['BGFI', 'bgfi', 'B.G.F.I', ' BGFI '] as $saisi) {
            $verdict = R::pour($saisi, $this->banques());

            $this->assertSame(R::CERTAINE, $verdict['verdict'], "« {$saisi} » doit être reconnu.");
            $this->assertSame('BGFI', $verdict['banque']->nom);
        }
    }

    /** La même banque citée deux fois reste une seule banque. */
    public function test_la_meme_banque_citee_deux_fois_est_reconnue(): void
    {
        $verdict = R::pour('BGFI/BGFI', $this->banques());

        $this->assertSame(R::CERTAINE, $verdict['verdict']);
        $this->assertSame('BGFI', $verdict['banque']->nom);
    }

    /**
     * Une faute de frappe est **proposée**, pas posée.
     *
     * Entre « c'est la BGFI » et « je ne sais pas », il y a « cela ressemble à la BGFI ».
     * Trancher à la place de quelqu'un rangerait des écritures sous une banque qui n'est pas
     * la bonne, et rien ne le dirait ensuite.
     */
    public function test_une_faute_de_frappe_est_proposee_et_non_posee(): void
    {
        $verdict = R::pour('BGFIU', $this->banques());

        $this->assertSame(R::PROPOSEE, $verdict['verdict']);
        $this->assertSame('BGFI', $verdict['banque']->nom);
        $this->assertStringContainsString('ressemble', $verdict['raison']);
    }

    /**
     * Deux banques dans un libellé font un refus — le cas le plus important.
     *
     * Choisir l'une des deux fausserait les deux totaux, et rien ne le signalerait.
     */
    public function test_deux_banques_dans_un_libelle_sont_refusees(): void
    {
        foreach (['BGFI+BNI', 'BNI/BGFI', 'BGFI/AFG', 'BNI+BGFI'] as $saisi) {
            $verdict = R::pour($saisi, $this->banques());

            $this->assertSame(R::INCONNUE, $verdict['verdict'], "« {$saisi} » doit être refusé.");
            $this->assertNull($verdict['banque']);
            $this->assertStringContainsString('plusieurs banques', $verdict['raison']);
        }
    }

    /** Un numéro tombé dans la mauvaise case n'est pas un nom. */
    public function test_un_libelle_sans_lettre_est_refuse(): void
    {
        $verdict = R::pour('234665', $this->banques());

        $this->assertSame(R::INCONNUE, $verdict['verdict']);
        $this->assertStringContainsString('aucune lettre', $verdict['raison']);
    }

    /**
     * « CAISSE » et « wave » ne sont pas des banques, et aucune ressemblance ne doit les y faire entrer.
     *
     * C'est ce que le seuil haut protège : sur des noms de quatre lettres, un seuil
     * permissif ferait tomber n'importe quel mot court sur n'importe quelle banque.
     */
    public function test_un_support_qui_n_est_pas_une_banque_est_refuse(): void
    {
        foreach (['CAISSE', 'wave'] as $saisi) {
            $verdict = R::pour($saisi, $this->banques());

            $this->assertSame(R::INCONNUE, $verdict['verdict'], "« {$saisi} » n'est pas une banque.");
        }
    }

    /**
     * Et deux banques réelles ne se ressemblent pas assez pour se confondre.
     *
     * `BDA` et `BNI` partagent une lettre sur trois. Un seuil à 55 %, comme chez les
     * fournisseurs, les rapprocherait — d'où 78 % ici : un nom de fournisseur fait vingt
     * lettres, un nom de banque en fait quatre.
     */
    public function test_deux_banques_courtes_ne_se_confondent_pas(): void
    {
        $verdict = R::pour('BDA', $this->banques());

        $this->assertSame(R::CERTAINE, $verdict['verdict']);
        $this->assertSame('BDA', $verdict['banque']->nom);
    }

    /** @return Collection<int, Banque> */
    private function banques()
    {
        return Banque::withoutGlobalScopes()->get();
    }
}

<?php

namespace Tests\Feature;

use Modules\Noyau\Imports\Services\NumeroDePiece;
use Tests\TestCase;

/**
 * Deux classeurs, deux façons d'écrire le même numéro — une seule facture.
 *
 * **La question qui a trouvé le défaut**, posée par le propriétaire le 25/09 : la clé de
 * rapprochement — fournisseur, n° de pièce, date, montant — est-elle infaillible, « les
 * deux fichiers n'ayant pas la même formalisation du numéro » ? Elle ne l'était pas.
 *
 * Les cas ci-dessous sont **relevés dans les deux fichiers réels**, pas inventés. Chaque
 * paire est une facture unique que la comparaison littérale voyait comme deux, et qui
 * serait entrée deux fois au dépôt du second classeur : 562 au total.
 */
class LeNumeroDePieceSeCompareParSonNoyauTest extends TestCase
{
    /**
     * Les cinq formes rencontrées, et ce qu'elles ont en commun.
     */
    public function test_les_deux_classeurs_designent_la_meme_piece(): void
    {
        $paires = [
            // Le préfixe est le code du bon de commande du fournisseur. San Pédro le
            // recopie, Abidjan ne le recopie pas.
            ['0001827', '22319I091/0001827'],
            ['000 1876', '22319I091/0001876'],
            ['00000 46', '23319O0119/0000046'],
            ['00 32165', '23005S023/0032165'],
            // Un espace de frappe, et rien d'autre.
            ['02015', '0 2015'],
            ['02096', '0 2096'],
            // Un tiret dans le même classeur, sur deux lignes voisines.
            ['FC26-00278', 'FC2600278'],
        ];

        foreach ($paires as [$abidjan, $sanPedro]) {
            $this->assertTrue(
                NumeroDePiece::memePiece($abidjan, $sanPedro),
                "« $abidjan » et « $sanPedro » désignent la même pièce des deux classeurs.",
            );
        }
    }

    /**
     * Et deux pièces différentes le restent.
     *
     * C'est la moitié qui compte : une règle qui rapproche tout ne coûte rien à écrire et
     * fait disparaître des dettes.
     */
    public function test_deux_pieces_differentes_ne_se_confondent_pas(): void
    {
        $distinctes = [
            ['0001827', '0001828'],
            ['22319I091/0001877', '22319I091/0001878'],
            ['FC26-00278', 'FC26-00279'],
            // Un numéro et le même précédé d'un autre nombre : le noyau ne garde que ce
            // qui suit le dernier « / », donc ces deux-là diffèrent bien.
            ['23319O0119/0000046', '23319O0119/0000047'],
        ];

        foreach ($distinctes as [$a, $b]) {
            $this->assertFalse(
                NumeroDePiece::memePiece($a, $b),
                "« $a » et « $b » sont deux pièces, et doivent le rester.",
            );
        }
    }

    /**
     * Un numéro absent ne rapproche rien.
     *
     * 183 lignes sur 10 147 n'ont pas de numéro de pièce. Les rapprocher entre elles sur
     * un noyau vide ferait d'une facture de mars et d'une facture de juin la même dette
     * dès qu'elles porteraient le même montant.
     */
    public function test_un_numero_absent_ne_rapproche_rien(): void
    {
        $this->assertFalse(NumeroDePiece::memePiece('', ''));
        $this->assertFalse(NumeroDePiece::memePiece(null, null));
        $this->assertFalse(NumeroDePiece::memePiece('   ', '/'));
        $this->assertSame('', NumeroDePiece::noyau('   '));
    }

    /**
     * Un numéro fait de zéros reste lui-même.
     *
     * Retirer les zéros de tête sans garde-fou transformerait « 0000 » en chaîne vide, et
     * la pièce cesserait de se retrouver elle-même au dépôt suivant.
     */
    public function test_un_numero_de_zeros_ne_disparait_pas(): void
    {
        $this->assertSame('0000', NumeroDePiece::noyau('0000'));
        $this->assertTrue(NumeroDePiece::memePiece('0000', '0000'));
    }
}

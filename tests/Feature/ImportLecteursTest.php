<?php

namespace Tests\Feature;

use Modules\Noyau\Imports\Formats\Format;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Lecteurs\LecteurXls;
use Modules\Noyau\Imports\Lecteurs\LecteurXlsx;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * La lecture des fichiers, avant toute question métier.
 *
 * Ces tests portent sur la mécanique la plus ingrate du module et la plus dangereuse : une
 * erreur ici ne lève pas d'exception, elle décale une colonne. Un montant lu dans la case
 * d'à côté produit un chiffre d'affaires parfaitement plausible et parfaitement faux.
 *
 * Les valeurs de contrôle ne sont pas inventées : elles sont relevées dans les vrais
 * exports. Le numéro de série 46024 s'affiche « 02/01/2026 » dans la situation du parc de
 * San Pédro, 45996 s'affiche « 05/12/2025 » dans celle d'Abidjan. Un test qui se contente
 * de vérifier que le code fait ce que le code fait ne prouve rien ; ceux-ci vérifient que
 * le code lit ce que le tableur affiche.
 */
class ImportLecteursTest extends TestCase
{
    use ConstruitDesClasseurs;

    public function test_une_reference_de_cellule_donne_sa_position_de_colonne(): void
    {
        $this->assertSame(0, LecteurXlsx::colonneDe('A1'));
        $this->assertSame(25, LecteurXlsx::colonneDe('Z9'));

        // Le passage à deux lettres est l'endroit où l'on se trompe : après Z vient AA,
        // qui vaut 26 et non 52.
        $this->assertSame(26, LecteurXlsx::colonneDe('AA1'));
        $this->assertSame(61, LecteurXlsx::colonneDe('BJ12'));

        // Le suivi fournisseurs va jusqu'à la colonne AN.
        $this->assertSame(39, LecteurXlsx::colonneDe('AN7436'));
    }

    public function test_un_numero_de_serie_donne_la_date_que_le_tableur_affiche(): void
    {
        // Trois relevés dans les vrais fichiers, colonne par colonne.
        $this->assertSame('2026-01-02', LecteurXlsx::dateDeSerie(46024)?->format('Y-m-d'));
        $this->assertSame('2025-12-05', LecteurXlsx::dateDeSerie(45996)?->format('Y-m-d'));
        $this->assertSame('2026-04-27', LecteurXlsx::dateDeSerie(46139)?->format('Y-m-d'));
    }

    public function test_un_nombre_qui_ne_peut_pas_etre_une_date_est_refuse(): void
    {
        $this->assertNull(LecteurXlsx::dateDeSerie(0));
        $this->assertNull(LecteurXlsx::dateDeSerie(-5));

        // Un montant mis en forme « jj/mm/aaaa » par mégarde : au-delà de l'an 9999 le
        // tableur lui-même ne sait plus l'afficher, et nous non plus.
        $this->assertNull(LecteurXlsx::dateDeSerie(50000000));
    }

    public function test_le_format_d_affichage_dit_si_une_cellule_porte_une_date(): void
    {
        $this->assertTrue(LecteurXlsx::formatEstUneDate(14, null));
        $this->assertTrue(LecteurXlsx::formatEstUneDate(46, null));
        $this->assertTrue(LecteurXlsx::formatEstUneDate(165, 'dd/mm/yyyy'));
        $this->assertTrue(LecteurXlsx::formatEstUneDate(166, 'jj/mm/aaaa'));

        $this->assertFalse(LecteurXlsx::formatEstUneDate(0, null));
        $this->assertFalse(LecteurXlsx::formatEstUneDate(4, '#,##0.00'));

        // Le piège : un format de nombre dont le suffixe contient une lettre de date. Sans
        // le retrait de ce qui est entre guillemets, « 0" jours" » passerait pour une date
        // et tous les délais du fichier deviendraient des dates de 1900.
        $this->assertFalse(LecteurXlsx::formatEstUneDate(167, '0" jours"'));
        $this->assertFalse(LecteurXlsx::formatEstUneDate(168, '#,##0" F CFA"'));

        // La couleur et la langue entre crochets ne font pas non plus une date.
        $this->assertFalse(LecteurXlsx::formatEstUneDate(169, '[Red]#,##0'));
    }

    public function test_le_nombre_comprime_des_anciens_classeurs_se_relit(): void
    {
        // Un RK porte soit un entier décalé de deux bits, soit un flottant tronqué, et le
        // bit de poids faible dit s'il faut encore diviser par cent.
        $this->assertSame(30.0, LecteurXls::nombreRk((30 << 2) | 2));
        $this->assertSame(123.45, LecteurXls::nombreRk((12345 << 2) | 3));

        // Le champ entier est signé sur 30 bits : au-delà de la moitié, la valeur repasse
        // en négatif. Un avoir mal relu deviendrait un encaissement d'un milliard.
        $this->assertSame(-1.0, LecteurXls::nombreRk(((-1 & 0x3FFFFFFF) << 2) | 2));
    }

    public function test_le_format_reel_est_reconnu_au_contenu_et_non_a_l_extension(): void
    {
        $classeur = $this->classeurXlsx(['A' => [['DATE DE LA FICHE', 'N° FICHE RECEPTION']]]);

        // Le même fichier, renommé en `.txt` : il reste un classeur, et il s'ouvre.
        $renomme = $classeur.'.txt';
        copy($classeur, $renomme);

        $this->assertSame('xlsx', Classeur::format($renomme));

        // L'inverse compte davantage : un fichier quelconque déguisé en classeur est
        // refusé avant qu'on en lise une seule ligne. L'extension est choisie par celui
        // qui dépose, elle ne prouve rien.
        $intrus = $this->fichierTemporaire('<?php echo "bonjour"; ?>', '.xlsx');

        $this->assertNull(Classeur::format($intrus));
        $this->expectExceptionMessage("Ce fichier n'est ni un classeur Excel récent");
        Classeur::ouvrir($intrus);
    }

    public function test_un_fichier_vide_est_refuse(): void
    {
        $vide = $this->fichierTemporaire('', '.xlsx');

        $this->expectExceptionMessage('Le fichier est vide.');
        Classeur::ouvrir($vide);
    }

    public function test_les_macros_sont_signalees_mais_jamais_ouvertes(): void
    {
        $sans = $this->classeurXlsx(['A' => [['x']]]);
        $avec = $this->classeurXlsx(['A' => [['x']]], macros: true);

        $this->assertFalse(Classeur::porteDesMacros($sans));
        $this->assertTrue(Classeur::porteDesMacros($avec));

        // Le point qui compte : la présence de macros ne change rien à la lecture. Le
        // module ne sait pas ce qu'est une macro, donc il ne peut pas en exécuter une.
        $lecteur = Classeur::ouvrir($avec);
        $this->assertSame(['A'], $lecteur->feuilles());
        $lecteur->fermer();
    }

    public function test_une_cellule_calculee_rend_son_resultat_et_jamais_sa_formule(): void
    {
        // Sur le suivi fournisseurs, 73 031 cellules sont des formules : toutes portent un
        // résultat en cache, et c'est lui qu'on lit. Rien n'est recalculé.
        $chemin = $this->classeurXlsx(['A' => [
            ['MONTANT'],
            [['formule' => 'SUM(B1:B9)', 'valeur' => 568816.0]],
        ]]);

        $lecteur = Classeur::ouvrir($chemin);
        $lignes = iterator_to_array($lecteur->lignes('A'));
        $lecteur->fermer();

        $this->assertSame(568816.0, $lignes[2][0]);
    }

    public function test_les_lignes_gardent_le_numero_que_l_utilisateur_voit_dans_le_tableur(): void
    {
        // Le tableur saute les lignes vides. Si l'import renumérotait, « ligne 4212
        // rejetée » désignerait une autre ligne que celle qu'on ouvre pour la corriger.
        $chemin = $this->classeurXlsx(['A' => [
            1 => ['en-tête'],
            7 => ['contenu'],
        ]]);

        $lecteur = Classeur::ouvrir($chemin);
        $numeros = array_keys(iterator_to_array($lecteur->lignes('A')));
        $lecteur->fermer();

        $this->assertSame([1, 7], $numeros);
    }

    public function test_les_montants_ecrits_a_la_main_se_relisent(): void
    {
        $this->assertSame(250000.0, Format::montant('250 000 F CFA'));
        $this->assertSame(34205673.0, Format::montant('34,205,673'));
        $this->assertSame(1384588525.53, Format::montant('1,384,588,525.53'));
        $this->assertSame(792400942.82, Format::montant(792400942.82));
        $this->assertSame(-1500.0, Format::montant('(1 500)'));

        // Ce qui n'est pas un montant n'en devient pas un. Rendre 0 serait pire que rendre
        // null : un zéro entre dans une somme sans qu'on le remarque.
        $this->assertNull(Format::montant(''));
        $this->assertNull(Format::montant('NEANT'));
        $this->assertNull(Format::montant(null));
    }

    public function test_les_dates_se_relisent_qu_elles_soient_serie_ou_texte(): void
    {
        // Le cas le plus fréquent dans les exports : la colonne n'est pas mise en forme,
        // la date arrive donc en nombre nu. Les dates de proforma sont toutes ainsi.
        $this->assertSame('2026-01-02', Format::date(46024.0)?->format('Y-m-d'));
        $this->assertSame('2026-01-02', Format::date('46024')?->format('Y-m-d'));
        $this->assertSame('2026-08-20', Format::date('20/08/2026')?->format('Y-m-d'));
        $this->assertSame('2026-08-20', Format::date('2026-08-20')?->format('Y-m-d'));

        $this->assertNull(Format::date(''));
        $this->assertNull(Format::date('à venir'));

        // Une date qui déborde ne doit pas se replier en silence sur un jour valide :
        // « 45/13/2026 » n'est pas le 14 janvier 2027.
        $this->assertNull(Format::date('45/13/2026'));

        // Un montant rangé dans une colonne de date ne devient pas une date de l'an 8000.
        $this->assertNull(Format::date(2500000.0));
    }

    public function test_un_texte_importe_est_nettoye_sans_perdre_son_sens(): void
    {
        // Les champs de travaux tiennent sur plusieurs lignes dans les vrais fichiers :
        // les retours à la ligne sont du contenu, pas du bruit.
        $this->assertSame(
            "REVISION MOTEUR\nBRUIT AU DEMARRAGE",
            Format::texte("  REVISION MOTEUR\nBRUIT AU DEMARRAGE  ")
        );

        // Un code client lu comme nombre ne doit pas devenir « 190.0 ».
        $this->assertSame('190', Format::texte(190.0));

        $this->assertNull(Format::texte(''));
        $this->assertNull(Format::texte(null));
        $this->assertSame('abc', Format::texte('abcdef', 3));
    }
}

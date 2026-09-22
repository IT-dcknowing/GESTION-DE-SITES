<?php

namespace Tests;

/**
 * Fabrique de vrais documents PDF pour les tests.
 *
 * Même parti pris que pour les classeurs : on construit le document plutôt que de poser un
 * fichier d'exemple à côté du test. Un fichier figé ne dit pas ce qu'il contient, et le
 * journal de caisse réel porte les noms et les dépenses d'une entreprise — ce n'est pas une
 * chose à déposer dans un dépôt Git.
 *
 * **Les deux écritures sont reproduites, parce que les deux fichiers réels les emploient.**
 * Le journal de San-Pédro pose son texte avec `Td` et des chaînes littérales, l'axe vertical
 * dans le sens ordinaire du PDF. Celui de Bouaké pose le sien avec `Tm`, dans une police à
 * index — chaque lettre écrite par un code hexadécimal que seule la table `ToUnicode`
 * traduit — et commence par renverser l'axe vertical. Un lecteur qui ne saurait lire que
 * l'une des deux écritures laisserait une ville entière dehors.
 *
 * Les documents produits passent par le même `Classeur::ouvrir()` que les fichiers reçus,
 * sans chemin de faveur.
 */
trait ConstruitDesDocumentsImprimes
{
    /**
     * Un PDF dont les pages sont décrites en « hauteur de lecture » : y croît vers le bas.
     *
     * @param  list<list<array{float, float, string}>>  $pages  y, x, texte
     */
    protected function documentPdf(array $pages, string $ecriture = 'litterale'): string
    {
        $objets = [];
        $flux = [];

        foreach ($pages as $page) {
            $flux[] = $ecriture === 'index'
                ? $this->fluxEnPoliceAIndex($page)
                : $this->fluxLitteral($page);
        }

        $contenu = "%PDF-1.4\n";
        $numero = 1;

        // Un catalogue et des pages sommaires : le lecteur ne s'y intéresse pas, mais un
        // document qui n'en aurait aucun ne serait pas un PDF.
        $objets[] = $numero++.' 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objets[] = $numero++.' 0 obj << /Type /Pages /Count '.count($pages).' >> endobj';

        foreach ($flux as $morceau) {
            $objets[] = $numero++.' 0 obj << /Length '.strlen($morceau)." /Filter /FlateDecode >>\nstream\n".$morceau."\nendstream endobj";
        }

        if ($ecriture === 'index') {
            $table = $this->tableDeCaracteres($pages);
            $objets[] = $numero++.' 0 obj << /Length '.strlen($table)." >>\nstream\n".$table."\nendstream endobj";
        }

        $contenu .= implode("\n", $objets)."\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return $this->documentTemporaire($contenu);
    }

    /** L'écriture de San-Pédro : `Td`, chaînes littérales, axe vertical ordinaire. */
    private function fluxLitteral(array $morceaux): string
    {
        $hauteur = 842.0;
        $flux = "2 J 0.45 w\n";

        foreach ($morceaux as [$y, $x, $texte]) {
            $flux .= sprintf(
                "0.000 g BT %.2f %.2f Td (%s)' ET\n",
                $x,
                $hauteur - $y,
                $this->echapper($texte),
            );
        }

        return gzcompress($flux);
    }

    /** L'écriture de Bouaké : axe renversé d'emblée, `Tm`, police à index. */
    private function fluxEnPoliceAIndex(array $morceaux): string
    {
        $flux = "0.750000 0.000000 0.000000 -0.750000 0.000000 595.320007 cm\n";

        foreach ($morceaux as [$y, $x, $texte]) {
            $flux .= sprintf(
                "BT /F1 9 Tf 1 0 0 1 %.2f %.2f Tm <%s> Tj ET\n",
                $x,
                $y,
                $this->enIndex($texte),
            );
        }

        return gzcompress($flux);
    }

    /** Chaque caractère reçoit un code, arbitraire mais stable. */
    private function codeDe(string $caractere): string
    {
        return strtoupper(str_pad(dechex(0x0100 + mb_ord($caractere, 'UTF-8') % 0xF000), 4, '0', STR_PAD_LEFT));
    }

    private function enIndex(string $texte): string
    {
        $codes = '';

        foreach (preg_split('//u', $texte, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caractere) {
            $codes .= $this->codeDe($caractere);
        }

        return $codes;
    }

    private function tableDeCaracteres(array $pages): string
    {
        $vus = [];

        foreach ($pages as $page) {
            foreach ($page as [$y, $x, $texte]) {
                foreach (preg_split('//u', $texte, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caractere) {
                    $vus[$this->codeDe($caractere)] = mb_ord($caractere, 'UTF-8');
                }
            }
        }

        $paires = '';

        foreach ($vus as $code => $point) {
            $paires .= '<'.$code.'> <'.strtoupper(str_pad(dechex($point), 4, '0', STR_PAD_LEFT)).'> ';
        }

        return '/CIDInit /ProcSet findresource begin 12 dict begin begincmap '
            .'1 begincodespacerange <0000> <FFFF> endcodespacerange '
            .count($vus).' beginbfchar '.$paires.'endbfchar endcmap end end';
    }

    private function echapper(string $texte): string
    {
        $latin = (string) mb_convert_encoding($texte, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin);
    }

    /**
     * Le fichier se supprime à la fin du test, sans passer par `tearDown()`.
     *
     * Un test qui construit à la fois des classeurs et des documents imprimés emploie deux
     * traits ; s'ils définissaient tous deux `tearDown()`, PHP refuserait la classe. Le
     * crochet de Laravel fait la même chose sans se disputer le nom.
     */
    protected function documentTemporaire(string $contenu): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'doc').'.pdf';
        file_put_contents($chemin, $contenu);
        $this->beforeApplicationDestroyed(fn () => @unlink($chemin));

        return $chemin;
    }
}

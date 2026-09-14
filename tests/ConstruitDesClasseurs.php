<?php

namespace Tests;

use ZipArchive;

/**
 * Fabrique de vrais classeurs `.xlsx` pour les tests.
 *
 * On aurait pu poser des fichiers d'exemple à côté des tests. On construit à la place,
 * pour deux raisons. La première est qu'un fichier figé ne dit pas ce qu'il contient : le
 * jour où un test échoue, il faut ouvrir le classeur pour comprendre, alors qu'ici le cas
 * est écrit en toutes lettres dans le test. La seconde est qu'un export réel porte les
 * noms, les immatriculations et les montants dus de vrais clients — ce n'est pas une chose
 * à déposer dans un dépôt Git.
 *
 * Les classeurs produits sont de vrais fichiers OOXML : archive ZIP, relations, styles.
 * Ils traversent le même lecteur que les fichiers du logiciel, sans chemin de faveur.
 */
trait ConstruitDesClasseurs
{
    /** @var list<string> */
    private array $fichiersTemporaires = [];

    /**
     * Un classeur `.xlsx` à partir de feuilles décrites en PHP.
     *
     * Chaque feuille est un tableau de lignes ; les clés donnent le numéro de ligne du
     * tableur quand on veut ménager des trous, sinon la numérotation part de 1. Une cellule
     * peut être une chaîne, un nombre, `['date' => 'Y-m-d']` pour une date mise en forme,
     * ou `['formule' => '…', 'valeur' => …]` pour une cellule calculée.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $feuilles
     */
    protected function classeurXlsx(array $feuilles, bool $macros = false): string
    {
        $chemin = $this->cheminTemporaire('.xlsx');

        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $noms = array_keys($feuilles);
        $types = '';
        $onglets = '';
        $relations = '';

        foreach ($noms as $rang => $nom) {
            $numero = $rang + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet'.$numero.'.xml" '
                .'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $onglets .= '<sheet name="'.htmlspecialchars($nom, ENT_QUOTES | ENT_XML1)
                .'" sheetId="'.$numero.'" r:id="rId'.$numero.'"/>';
            $relations .= '<Relationship Id="rId'.$numero.'" '
                .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                .'Target="worksheets/sheet'.$numero.'.xml"/>';

            $zip->addFromString('xl/worksheets/sheet'.$numero.'.xml', $this->feuilleXml($feuilles[$nom]));
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="bin" ContentType="application/vnd.ms-office.vbaProject"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$types.'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdWb" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$onglets.'</sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relations.'</Relationships>');

        // Deux styles seulement : le style 0 n'affiche rien de particulier, le style 1
        // porte le format de date intégré n° 14. C'est lui qui fait qu'un nombre est lu
        // comme une date.
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cellStyleXfs count="1"><xf numFmtId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>');

        if ($macros) {
            // Le seul élément qui distingue un `.xlsm` d'un `.xlsx` : le code des macros.
            // Il est déposé ici pour vérifier qu'on sait le voir — et qu'on n'y touche pas.
            $zip->addFromString('xl/vbaProject.bin', "\x00CB\x00 macro inerte");
        }

        $zip->close();

        return $chemin;
    }

    /** @param  array<int, array<int, mixed>>  $lignes */
    private function feuilleXml(array $lignes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        // Un tableau ordinaire se numérote de 1 à n ; un tableau à clés explicites laisse
        // choisir les numéros, ce qui permet de ménager les trous que le tableur produit.
        $sequentiel = array_is_list($lignes);
        $numero = 0;

        foreach ($lignes as $cle => $cellules) {
            $numero = $sequentiel ? $numero + 1 : (int) $cle;
            $xml .= '<row r="'.$numero.'">';

            foreach (array_values($cellules) as $position => $valeur) {
                $xml .= $this->celluleXml($this->referenceDe($position, $numero), $valeur);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function celluleXml(string $reference, mixed $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return '';
        }

        if (is_array($valeur) && isset($valeur['date'])) {
            $serie = (new \DateTimeImmutable('1899-12-30'))
                ->diff(new \DateTimeImmutable($valeur['date']))->days;

            return '<c r="'.$reference.'" s="1"><v>'.$serie.'</v></c>';
        }

        if (is_array($valeur) && isset($valeur['formule'])) {
            return '<c r="'.$reference.'"><f>'.htmlspecialchars($valeur['formule'], ENT_QUOTES | ENT_XML1).'</f>'
                .'<v>'.$valeur['valeur'].'</v></c>';
        }

        if (is_int($valeur) || is_float($valeur)) {
            return '<c r="'.$reference.'"><v>'.$valeur.'</v></c>';
        }

        return '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'
            .htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_XML1).'</t></is></c>';
    }

    private function referenceDe(int $colonne, int $ligne): string
    {
        $lettres = '';

        for ($n = $colonne + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $lettres = chr(65 + ($n - 1) % 26).$lettres;
        }

        return $lettres.$ligne;
    }

    protected function fichierTemporaire(string $contenu, string $extension = '.xlsx'): string
    {
        $chemin = $this->cheminTemporaire($extension);
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    private function cheminTemporaire(string $extension): string
    {
        $chemin = sys_get_temp_dir().DIRECTORY_SEPARATOR.'import-'.bin2hex(random_bytes(8)).$extension;
        $this->fichiersTemporaires[] = $chemin;

        return $chemin;
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }

            if (is_file($chemin.'.txt')) {
                @unlink($chemin.'.txt');
            }
        }

        parent::tearDown();
    }
}

<?php

namespace Modules\Noyau\Commun\Services;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Un tableau, trois formats de sortie — et aucune dépendance nouvelle.
 *
 * **Ce qui était demandé** : que « Imprimer » devienne « Télécharger », et que le
 * téléchargement propose PDF, Word et Excel — pour ce document-ci comme pour tous les
 * suivants. D'où une classe qui ne connaît rien au recouvrement : on lui donne un titre,
 * des en-têtes, des lignes, et elle rend un fichier.
 *
 * **Comment chaque format est produit, et pourquoi.**
 *
 * - **Excel** : un vrai `.xlsx`, écrit à la main. Un OOXML minimal est une archive ZIP de
 *   cinq fichiers XML — c'est peu, c'est stable depuis quinze ans, et cela évite d'ajouter
 *   une bibliothèque de plusieurs mégaoctets pour écrire des nombres dans des cases. Les
 *   montants sortent en **nombres**, pas en texte : un tableau qu'on ne peut pas
 *   additionner à l'arrivée n'a pas rendu service.
 *
 * - **Word** : un document HTML servi sous le type MIME de Word. C'est le format que Word
 *   ouvre sans broncher depuis toujours, et il garde les tableaux, les titres et les
 *   alignements. Le `.docx` demanderait la même mécanique ZIP pour un résultat que personne
 *   ne distinguerait à l'ouverture.
 *
 * - **PDF** : par {@see DocumentPdf}, l'écrivain maison déjà en place pour l'annuaire. Il
 *   pose du texte en coordonnées absolues avec les polices standard du format — rien à
 *   embarquer, rien à installer, et un fichier que tout lecteur ouvre hors ligne. C'est le
 *   même raisonnement que pour le `.xlsx` : le besoin est un tableau, pas un moteur de mise
 *   en page.
 */
class Exportateur
{
    /**
     * Un classeur `.xlsx` d'une seule feuille.
     *
     * @param  list<string>  $entetes
     * @param  list<list<string|int|float|null>>  $lignes
     */
    public static function excel(
        string $nomDeFichier,
        string $feuille,
        array $entetes,
        array $lignes,
        array $options = [],
    ): StreamedResponse {
        $contenu = self::classeur(
            $feuille,
            $entetes,
            $lignes,
            $options['total'] ?? null,
            $options['enTete'] ?? EnTeteDeDocument::pour($feuille, (string) ($options['chapeau'] ?? '')),
        );

        return response()->streamDownload(
            fn () => print $contenu,
            self::assainir($nomDeFichier).'.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Length' => (string) strlen($contenu),
            ],
        );
    }

    /**
     * Un document Word — du HTML, que Word ouvre et met en page.
     *
     * @param  list<string>  $entetes
     * @param  list<list<string|int|float|null>>  $lignes
     */
    public static function word(
        string $nomDeFichier,
        string $titre,
        array $entetes,
        array $lignes,
        ?string $chapeau = null,
        array $options = [],
    ): Response {
        $html = self::documentHtml($titre, $entetes, $lignes, $chapeau, $options);

        return response($html, 200, [
            'Content-Type' => 'application/msword; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.self::assainir($nomDeFichier).'.doc"',
        ]);
    }

    /**
     * Un PDF, mis en page pour être lu sur papier.
     *
     * Les colonnes se partagent la largeur utile au prorata de la longueur de ce qu'elles
     * contiennent : une colonne de dates n'a pas besoin d'autant de place qu'une colonne de
     * raisons sociales, et les répartir également ferait déborder l'une pendant que l'autre
     * reste vide.
     *
     * @param  list<string>  $entetes
     * @param  list<list<string|int|float|null>>  $lignes
     */
    public static function pdf(
        string $nomDeFichier,
        string $titre,
        array $entetes,
        array $lignes,
        ?string $chapeau = null,
        array $options = [],
    ): Response {
        /*
         * L'orientation suit le tableau. Au-delà de six colonnes, une page debout laisse
         * moins de cinquante points par colonne : « 697 027 » n'y tient pas et sort
         * tronqué avec des points de suspension — c'est ce que montraient les documents
         * signalés. Couchée, la même page en offre huit cents.
         */
        $document = new DocumentPdf($titre, paysage: count($entetes) > 6);

        $enTete = EnTeteDeDocument::pour($titre, (string) $chapeau);

        $document->enTete([
            'maison' => $enTete->maison,
            'logo' => $enTete->logo,
            'titre' => $enTete->titre,
            'message' => $enTete->message,
            'mention' => $enTete->mention,
        ]);

        $enClair = fn (array $ligne) => array_map(
            fn ($valeur) => is_int($valeur) || is_float($valeur)
                ? number_format((float) $valeur, 0, ',', ' ')
                : (string) ($valeur ?? ''),
            array_values($ligne),
        );

        $texte = array_map($enClair, $lignes);
        $total = isset($options['total']) ? $enClair($options['total']) : null;

        // Les largeurs tiennent compte du total : c'est souvent la ligne la plus large,
        // et l'oublier ferait tronquer la seule dont personne n'accepte l'à-peu-près.
        $contenu = $document
            ->tableau($entetes, self::largeurs(
                $entetes,
                $total === null ? $texte : [...$texte, $total],
                $document->largeurDisponible(),
            ), $texte, [
                'alignements' => self::alignements($lignes, $options['total'] ?? null),
                'etiquettes' => $options['etiquettes'] ?? [],
                'total' => $total,
            ])
            ->rendu();

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.self::assainir($nomDeFichier).'.pdf"',
            'Content-Length' => (string) strlen($contenu),
        ]);
    }

    /**
     * La largeur de chaque colonne, au prorata de ce qu'elle porte.
     *
     * @param  list<string>  $entetes
     * @param  list<list<string>>  $lignes
     * @return list<float>
     */
    private static function largeurs(array $entetes, array $lignes, float $utile): array
    {
        $poids = [];

        foreach ($entetes as $rang => $entete) {
            $maximum = mb_strlen($entete);

            // Cent lignes suffisent à connaître la forme du tableau ; les lire toutes pour
            // calculer une largeur coûterait plus que le gain.
            foreach (array_slice($lignes, 0, 100) as $ligne) {
                $maximum = max($maximum, mb_strlen((string) ($ligne[$rang] ?? '')));
            }

            $poids[$rang] = max(6, min(38, $maximum));
            $plancher[$rang] = $maximum;
        }

        $total = array_sum($poids) ?: 1;
        $largeurs = array_map(fn (int $p) => round($utile * $p / $total, 2), $poids);

        /*
         * Le plancher des colonnes courtes. Une colonne dont le contenu le plus long fait
         * onze signes n'a pas besoin de plus, mais elle a besoin d'au moins cela : sans
         * garde-fou, la répartition au prorata écrase les colonnes de montants au profit
         * d'une colonne de raisons sociales, et les nombres sortent tronqués. On leur rend
         * ce qu'il leur faut, et le surplus se reprend sur la colonne la plus large — la
         * seule dont un raccourci reste lisible.
         */
        foreach ($largeurs as $rang => $largeur) {
            $besoin = $plancher[$rang] * 5.1 + 8;

            if ($plancher[$rang] <= 16 && $largeur < $besoin) {
                $manque = $besoin - $largeur;
                $plusLarge = array_search(max($largeurs), $largeurs, true);

                if ($plusLarge !== $rang && $largeurs[$plusLarge] - $manque > 40) {
                    $largeurs[$rang] = round($besoin, 2);
                    $largeurs[$plusLarge] = round($largeurs[$plusLarge] - $manque, 2);
                }
            }
        }

        return $largeurs;
    }

    /**
     * Quelles colonnes se cadrent à droite — déduit du contenu, jamais déclaré.
     *
     * Une colonne dont **toutes** les valeurs sont des nombres est une colonne de montants.
     * Le « toutes » compte : une colonne qui mêle des nombres et des tirets reste du texte,
     * et l'aligner à droite ferait danser les tirets au bout des lignes.
     *
     * @param  list<list<mixed>>  $lignes
     * @return array<int, string>
     */
    private static function alignements(array $lignes, ?array $total): array
    {
        $toutes = $total === null ? $lignes : [...$lignes, $total];
        $alignements = [];

        foreach ($toutes as $ligne) {
            foreach (array_values($ligne) as $rang => $valeur) {
                if ($valeur === null || $valeur === '') {
                    continue;
                }

                $alignements[$rang] = (is_int($valeur) || is_float($valeur))
                    ? ($alignements[$rang] ?? 'droite')
                    : 'gauche';
            }
        }

        return $alignements;
    }

    /** @return array<string, string> les formats proposés, dans l'ordre où on les propose */
    public const FORMATS = ['pdf' => 'PDF', 'excel' => 'Excel (.xlsx)', 'word' => 'Word (.doc)'];

    // ------------------------------------------------------------------ la fabrique OOXML

    /**
     * @param  list<string>  $entetes
     * @param  list<list<string|int|float|null>>  $lignes
     */
    private static function classeur(
        string $feuille,
        array $entetes,
        array $lignes,
        ?array $total,
        EnTeteDeDocument $enTete,
    ): string {
        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::OVERWRITE);

        // Le logo n'entre dans l'archive que s'il est réellement lisible : déclarer une
        // image absente produirait un classeur que le tableur refuse d'ouvrir.
        $logo = $enTete->logo !== null ? @getimagesize($enTete->logo) : false;

        $zip->addFromString('[Content_Types].xml', self::typesDeContenu($logo !== false));
        $zip->addFromString('_rels/.rels', self::relationsRacine());
        $zip->addFromString('xl/workbook.xml', self::classeurXml($feuille));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::relationsClasseur());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            self::feuilleXml($entetes, $lignes, $total, $enTete, $logo !== false),
        );

        if ($logo !== false) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', self::dessinXml((int) $logo[0], (int) $logo[1]));
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>'
                .'</Relationships>');
            $zip->addFromString('xl/media/logo.png', (string) file_get_contents((string) $enTete->logo));
        }

        $zip->close();

        $contenu = (string) file_get_contents($chemin);
        @unlink($chemin);

        return $contenu;
    }

    private static function typesDeContenu(bool $avecImage): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .($avecImage ? '<Default Extension="png" ContentType="image/png"/>' : '')
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .($avecImage ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            .'</Types>';
    }

    private static function relationsRacine(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function relationsClasseur(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function classeurXml(string $feuille): string
    {
        // Excel refuse un nom de feuille de plus de 31 caractères, et quelques signes.
        $nom = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $feuille), 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::echapper($nom).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    /**
     * Les styles du classeur.
     *
     * **Pourquoi ils ont grossi.** Le classeur sortait en deux styles : ordinaire et gras.
     * Il ouvrait donc sur un tableau nu, sans en-tête, sans couleurs, sans séparation
     * lisible entre les intitulés et les données — et surtout, les montants n'avaient pas
     * de format : neuf chiffres collés, qu'il fallait relire deux fois. Les styles posés
     * ici reprennent l'écran : bandeau noir sur les intitulés, total noir en pied, fond
     * clair encadré pour l'en-tête, séparateur de milliers sur les nombres.
     */
    private static function stylesXml(): string
    {
        $bordure = '<border><left style="thin"><color rgb="FFD9D5CA"/></left>'
            .'<right style="thin"><color rgb="FFD9D5CA"/></right>'
            .'<top style="thin"><color rgb="FFD9D5CA"/></top>'
            .'<bottom style="thin"><color rgb="FFD9D5CA"/></bottom></border>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
            .'<fonts count="6">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="20"/><color rgb="FF191B20"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFC8102E"/><name val="Calibri"/></font>'
            .'<font><sz val="10"/><color rgb="FF5A6472"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF7F5EF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF191B20"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border/>'.$bordure.'</borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="9">'
            // 0 ordinaire · 1 gras
            .'<xf xfId="0"/>'
            .'<xf xfId="0" fontId="1" applyFont="1"/>'
            // 2 titre du document · 3 nom de la maison · 4 message — tous sur le fond clair
            .'<xf xfId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'<xf xfId="0" fontId="3" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'<xf xfId="0" fontId="4" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            // 5 intitulés de colonnes · 6 total en pied
            .'<xf xfId="0" fontId="5" fillId="3" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf xfId="0" numFmtId="164" fontId="5" fillId="3" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            // 7 un nombre ordinaire · 8 une case vide du bandeau
            .'<xf xfId="0" numFmtId="164" applyNumberFormat="1"/>'
            .'<xf xfId="0" fillId="2" borderId="1" applyFill="1" applyBorder="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /** Le dessin qui porte le logo, ancré en haut à gauche de la feuille. */
    private static function dessinXml(int $largeur, int $hauteur): string
    {
        // Le logo occupe soixante points de haut : visible sans écraser l'en-tête, et la
        // largeur suit la proportion d'origine — un logo déformé fait plus de mal
        // qu'un logo absent.
        $hauteurVoulue = 62;
        $largeurVoulue = (int) round($hauteurVoulue * max(1, $largeur) / max(1, $hauteur));
        $emu = 9525;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<xdr:oneCellAnchor>'
            .'<xdr:from><xdr:col>0</xdr:col><xdr:colOff>95250</xdr:colOff>'
            .'<xdr:row>0</xdr:row><xdr:rowOff>76200</xdr:rowOff></xdr:from>'
            .'<xdr:ext cx="'.($largeurVoulue * $emu).'" cy="'.($hauteurVoulue * $emu).'"/>'
            .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="Logo" descr="Logo"/>'
            .'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            .'<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/>'
            .'<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/>'
            .'<a:ext cx="'.($largeurVoulue * $emu).'" cy="'.($hauteurVoulue * $emu).'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
            .'<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }

    /**
     * La feuille : le bandeau d'en-tête, puis le tableau.
     *
     * Les trois premières lignes sont laissées au logo — une image flotte au-dessus des
     * cases, et lui superposer du texte le rendrait illisible.
     *
     * @param  list<string>  $entetes
     * @param  list<list<string|int|float|null>>  $lignes
     */
    private static function feuilleXml(
        array $entetes,
        array $lignes,
        ?array $total,
        EnTeteDeDocument $enTete,
        bool $avecLogo,
    ): string {
        $colonnes = max(1, count($entetes));
        $derniere = self::colonne($colonnes);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .self::vuesXml($enTete, $avecLogo)
            .self::colonnesXml($entetes, $lignes, $total)
            .'<sheetData>';

        // ------------------------------------------------- le bandeau
        $bloc = [];

        // Les trois premières lignes sont la place du logo, et n'ont de raison d'être que
        // s'il y en a un : sans image, elles ne feraient qu'un grand vide au-dessus du
        // titre, et le nom de la maison s'écrirait deux fois.
        if ($avecLogo) {
            $bloc[] = ['texte' => '', 'style' => 8, 'hauteur' => 22];
            $bloc[] = ['texte' => '', 'style' => 8, 'hauteur' => 22];
            $bloc[] = ['texte' => '', 'style' => 8, 'hauteur' => 22];
        }

        $bloc[] = ['texte' => $enTete->maison, 'style' => 3, 'hauteur' => 18];
        $bloc[] = ['texte' => $enTete->titre, 'style' => 2, 'hauteur' => 28];

        foreach ($enTete->lignesDuMessage() as $ligne) {
            $bloc[] = ['texte' => $ligne, 'style' => 4, 'hauteur' => 15];
        }

        $bloc[] = ['texte' => $enTete->mention, 'style' => 4, 'hauteur' => 15];

        $numero = 1;
        $fusions = [];

        foreach ($bloc as $entree) {
            $xml .= '<row r="'.$numero.'" ht="'.$entree['hauteur'].'" customHeight="1">';

            for ($colonne = 1; $colonne <= $colonnes; $colonne++) {
                $reference = self::colonne($colonne).$numero;

                $xml .= $colonne === 1 && $entree['texte'] !== ''
                    ? '<c r="'.$reference.'" s="'.$entree['style'].'" t="inlineStr"><is><t xml:space="preserve">'
                        .self::echapper((string) $entree['texte']).'</t></is></c>'
                    : '<c r="'.$reference.'" s="'.$entree['style'].'"/>';
            }

            $xml .= '</row>';

            if ($colonnes > 1) {
                $fusions[] = 'A'.$numero.':'.$derniere.$numero;
            }

            $numero++;
        }

        // Une ligne de respiration entre le bandeau et le tableau.
        $xml .= '<row r="'.$numero.'" ht="8" customHeight="1"/>';
        $numero++;

        // ------------------------------------------------- le tableau
        $xml .= self::ligneXml($entetes, $numero++, 5);

        foreach ($lignes as $ligne) {
            $xml .= self::ligneXml(array_values($ligne), $numero++, null);
        }

        if ($total !== null) {
            $xml .= self::ligneXml(array_values($total), $numero, 6);
        }

        $xml .= '</sheetData>';

        if ($fusions !== []) {
            $xml .= '<mergeCells count="'.count($fusions).'">';

            foreach ($fusions as $plage) {
                $xml .= '<mergeCell ref="'.$plage.'"/>';
            }

            $xml .= '</mergeCells>';
        }

        if ($avecLogo) {
            $xml .= '<drawing r:id="rId1"/>';
        }

        return $xml.'</worksheet>';
    }

    /**
     * Les vues de la feuille — et le gel des intitulés.
     *
     * Sur mille trois cents lignes, une colonne qui a perdu son titre ne se lit plus : on
     * compte les colonnes avec le doigt. Le nombre de lignes gelées se déduit du bandeau,
     * qui n'a pas toujours la même hauteur — un message de deux lignes en ajoute une.
     */
    private static function vuesXml(EnTeteDeDocument $enTete, bool $avecLogo): string
    {
        $ligneDesIntitules = self::hauteurDuBandeau($enTete, $avecLogo) + 1;

        return '<sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="'.$ligneDesIntitules.'" topLeftCell="A'.($ligneDesIntitules + 1).'" '
            .'activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    }

    /** Le nombre de lignes qu'occupe le bandeau, respiration comprise. */
    private static function hauteurDuBandeau(EnTeteDeDocument $enTete, bool $avecLogo): int
    {
        // Les trois lignes du logo s'il y en a un, puis la maison, le titre, les lignes
        // du message, la mention, et la respiration.
        return ($avecLogo ? 3 : 0) + 1 + 1 + count($enTete->lignesDuMessage()) + 1 + 1;
    }

    /**
     * La largeur de chaque colonne, en caractères.
     *
     * Sans elle, tout sort à la largeur par défaut : les raisons sociales sont coupées et
     * les montants remplacés par des dièses. C'est le même travail que pour le PDF, avec
     * l'unité du tableur.
     */
    private static function colonnesXml(array $entetes, array $lignes, ?array $total): string
    {
        if ($entetes === []) {
            return '';
        }

        $xml = '<cols>';

        foreach ($entetes as $rang => $entete) {
            $maximum = mb_strlen((string) $entete);

            foreach (array_slice($lignes, 0, 200) as $ligne) {
                $valeur = array_values($ligne)[$rang] ?? '';
                $maximum = max($maximum, mb_strlen(
                    is_int($valeur) || is_float($valeur)
                        ? number_format((float) $valeur, 0, ',', ' ')
                        : (string) $valeur
                ));
            }

            if ($total !== null) {
                $valeur = array_values($total)[$rang] ?? '';
                $maximum = max($maximum, mb_strlen(
                    is_int($valeur) || is_float($valeur)
                        ? number_format((float) $valeur, 0, ',', ' ')
                        : (string) $valeur
                ));
            }

            $xml .= '<col min="'.($rang + 1).'" max="'.($rang + 1).'" '
                .'width="'.min(52, max(10, $maximum + 3)).'" customWidth="1"/>';
        }

        return $xml.'</cols>';
    }

    /** Une ligne de cases. `$style` force un style ; sinon les nombres prennent le leur. */
    private static function ligneXml(array $cellules, int $numero, ?int $style): string
    {
        $xml = '<row r="'.$numero.'">';

        foreach (array_values($cellules) as $rang => $valeur) {
            $reference = self::colonne($rang + 1).$numero;
            $nombre = is_int($valeur) || is_float($valeur);

            // Un nombre garde son format même dans la ligne de total : c'est là qu'on
            // relit le chiffre le plus souvent.
            $applique = $style ?? ($nombre ? 7 : null);

            if ($style === 6 && ! $nombre) {
                $applique = 5;
            }

            $attribut = $applique === null ? '' : ' s="'.$applique.'"';

            if ($valeur === null || $valeur === '') {
                $xml .= '<c r="'.$reference.'"'.$attribut.'/>';

                continue;
            }

            // Un montant écrit en texte ne s'additionne pas dans le tableur, et c'est la
            // première chose que quelqu'un tente en ouvrant le fichier.
            if ($nombre) {
                $xml .= '<c r="'.$reference.'"'.$attribut.'><v>'.$valeur.'</v></c>';

                continue;
            }

            $xml .= '<c r="'.$reference.'"'.$attribut.' t="inlineStr"><is><t xml:space="preserve">'
                .self::echapper((string) $valeur).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    private static function colonne(int $rang): string
    {
        $nom = '';

        while ($rang > 0) {
            $reste = ($rang - 1) % 26;
            $nom = chr(65 + $reste).$nom;
            $rang = (int) (($rang - $reste) / 26);
        }

        return $nom;
    }

    // -------------------------------------------------------------------------- le Word

    /**
     * Le document Word — du HTML, que Word met en page.
     *
     * **Deux défauts corrigés ici.** Les nombres se cassaient en morceaux les uns sous les
     * autres : « 697 027 » sortait sur trois lignes. C'est que rien n'interdisait à Word de
     * couper une cellule sur ses espaces, et un montant coupé ne se lit plus, il se devine.
     * Les colonnes de nombres sont donc insécables, et le tableau se met en page sur une
     * largeur fixe plutôt que de comprimer ce qui dépasse.
     *
     * Et le document n'avait pas d'en-tête : il sort désormais avec la marque de la maison,
     * son nom, le titre, le message et la date d'édition, dans un cadre — la même identité
     * que le PDF et le classeur.
     */
    private static function documentHtml(
        string $titre,
        array $entetes,
        array $lignes,
        ?string $chapeau,
        array $options = [],
    ): string {
        $enTete = EnTeteDeDocument::pour($titre, (string) $chapeau);

        /*
         * Paysage au-delà de six colonnes, par la section Word : c'est le même arbitrage
         * que pour le PDF, et sans lui un tableau de dix colonnes se replie sur lui-même.
         */
        $paysage = count($entetes) > 6;

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
            .'xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
            .'<head><meta charset="utf-8">'
            .'<style>'
            .'@page{size:'.($paysage ? 'A4 landscape' : 'A4 portrait').';margin:1.4cm;}'
            .'body{font-family:Calibri,Arial,sans-serif;font-size:11pt;}'
            .'table.ent{width:100%;border-collapse:collapse;border:1px solid #D9D5CA;'
            .'background:#F7F5EF;margin-bottom:14pt;}'
            .'table.ent td{border:0;padding:9pt 11pt;vertical-align:middle;}'
            .'td.marque{width:90pt;border-left:4px solid #C8102E !important;}'
            .'td.marque img{height:46pt;}'
            .'p.maison{margin:0;color:#C8102E;font-weight:bold;font-size:11pt;'
            .'letter-spacing:.4pt;text-transform:uppercase;}'
            .'p.doc{margin:2pt 0 0;font-size:19pt;font-weight:bold;color:#191B20;}'
            .'p.msg{margin:3pt 0 0;color:#5A6472;font-size:9.5pt;}'
            .'p.mention{margin:3pt 0 0;color:#25272D;font-size:9pt;font-weight:bold;}'
            .'table.donnees{border-collapse:collapse;width:100%;table-layout:fixed;}'
            .'table.donnees th{background:#191B20;color:#fff;text-align:left;padding:5px 7px;'
            .'font-size:9.5pt;border:1px solid #191B20;}'
            .'table.donnees td{border-bottom:1px solid #DDD;padding:4px 7px;font-size:9.5pt;'
            .'word-wrap:break-word;}'
            // Le cœur du correctif : une cellule de montant ne se coupe jamais.
            .'table.donnees td.n,table.donnees th.n{text-align:right;white-space:nowrap;'
            .'mso-number-format:"\#\,\#\#0";}'
            .'table.donnees tr.tot td{background:#191B20;color:#fff;font-weight:bold;border:0;'
            .'font-size:10pt;}'
            .'span.pill{display:inline-block;padding:1px 7px;font-size:8.5pt;font-weight:bold;}'
            .'</style></head><body>';

        // ------------------------------------------------------------------ l'en-tête
        $image = $enTete->logoEnLigne();

        $html .= '<table class="ent"><tr><td class="marque">';
        $html .= $image === null
            ? '<p class="maison">'.self::echapperHtml(mb_substr($enTete->maison, 0, 3)).'</p>'
            : '<img src="'.$image.'" alt="'.self::echapperHtml($enTete->maison).'">';
        $html .= '</td><td>';
        $html .= '<p class="maison">'.self::echapperHtml($enTete->maison).'</p>';
        $html .= '<p class="doc">'.self::echapperHtml($enTete->titre).'</p>';

        foreach ($enTete->lignesDuMessage() as $ligne) {
            $html .= '<p class="msg">'.self::echapperHtml($ligne).'</p>';
        }

        $html .= '<p class="mention">'.self::echapperHtml($enTete->mention).'</p>';
        $html .= '</td></tr></table>';

        // ------------------------------------------------------------------ le tableau
        $colonnesNumeriques = self::alignements($lignes, $options['total'] ?? null);

        $html .= '<table class="donnees"><thead><tr>';

        foreach ($entetes as $rang => $entete) {
            $classe = ($colonnesNumeriques[$rang] ?? '') === 'droite' ? ' class="n"' : '';
            $html .= '<th'.$classe.'>'.self::echapperHtml($entete).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        $etiquettes = $options['etiquettes'] ?? [];

        foreach ($lignes as $ligne) {
            $html .= self::ligneHtml(array_values($ligne), $etiquettes, false);
        }

        $html .= '</tbody>';

        if (isset($options['total'])) {
            $html .= '<tfoot>'.self::ligneHtml(array_values($options['total']), [], true).'</tfoot>';
        }

        return $html.'</table></body></html>';
    }

    /** Une ligne de tableau Word, avec ses montants à droite et ses pastilles en couleur. */
    private static function ligneHtml(array $cellules, array $etiquettes, bool $total): string
    {
        $html = $total ? '<tr class="tot">' : '<tr>';

        foreach ($cellules as $rang => $valeur) {
            $nombre = is_int($valeur) || is_float($valeur);
            $affiche = $nombre ? number_format((float) $valeur, 0, ',', ' ') : (string) $valeur;
            $teinte = $etiquettes[$rang][$affiche] ?? null;

            $contenu = $teinte === null
                ? self::echapperHtml($affiche)
                : '<span class="pill" style="background:'.$teinte['fond'].';color:'.$teinte['texte'].';">'
                    .self::echapperHtml($affiche).'</span>';

            $html .= '<td'.($nombre ? ' class="n"' : '').'>'.$contenu.'</td>';
        }

        return $html.'</tr>';
    }

    // ------------------------------------------------------------------------- utilitaires

    /**
     * Pour le XML du classeur — `&apos;` y est une entité valide.
     */
    private static function echapper(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Pour le HTML du document Word — et il ne s'échappe pas comme du XML.
     *
     * `ENT_XML1` rend l'apostrophe sous la forme `&apos;`. C'est une entité XML parfaitement
     * valide, que le lecteur HTML de Word ne connaît pas : le document affichait
     * « Encours par tiers et par tranche d&apos;ancienneté » en toutes lettres. `ENT_HTML5`
     * ne corrige rien — la spécification HTML5 définit `&apos;` elle aussi, et Word ne la
     * lit pas davantage. Seul `ENT_HTML401` rend la forme numérique `&#039;`, que tous les
     * lecteurs comprennent depuis toujours. Vérifié sur le fichier produit.
     */
    private static function echapperHtml(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    /** Un nom de fichier qui ne casse ni sous Windows, ni dans un en-tête HTTP. */
    private static function assainir(string $nom): string
    {
        $nom = preg_replace('/[^\p{L}\p{N}\-_ ]+/u', ' ', $nom) ?? 'export';
        $nom = trim(preg_replace('/\s+/', ' ', $nom) ?? 'export');

        return mb_substr($nom === '' ? 'export' : $nom, 0, 80);
    }
}

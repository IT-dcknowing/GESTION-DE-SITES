<?php

namespace Modules\Noyau\Imports\Lecteurs;

use RuntimeException;

/**
 * Ouvre un fichier déposé et rend le lecteur qui sait le lire.
 *
 * Le choix se fait sur **le contenu, jamais sur le nom**. C'est un principe de sécurité
 * autant qu'une nécessité pratique : l'extension d'un fichier téléversé est choisie par
 * celui qui le dépose, elle ne prouve rien. Ici en plus les noms mentent déjà pour de
 * bonnes raisons — les exports sont filtrés puis renommés à la main — et on a mesuré qu'un
 * fichier « San Pédro » pouvait contenir les données d'Abidjan.
 *
 * Les trois signatures suffisent à trancher : `PK` ouvre toute archive ZIP, donc tout
 * `.xlsx` et tout `.xlsm` ; la séquence `D0CF11E0` ouvre un conteneur OLE2, donc les
 * `.xls` ; `%PDF-` ouvre un document imprimé. Un fichier qui n'a aucune des trois est
 * refusé avant que la moindre ligne n'ait été lue.
 *
 * **Le PDF est entré le 23/09**, et à contre-cœur : le journal de caisse est le seul état
 * que le logiciel d'atelier ne sait pas sortir en tableur, et Bouaké comme San-Pédro n'ont
 * que lui. Voir `LecteurPdf` pour ce que la lecture d'un document imprimé suppose, et ce
 * qu'elle ne fait pas.
 */
class Classeur
{
    /** Ce qu'on accepte de recevoir, et sous quel poids. */
    public const EXTENSIONS = ['xls', 'xlsx', 'xlsm', 'pdf'];

    public const POIDS_MAXIMAL = 40 * 1024 * 1024;

    public static function ouvrir(string $chemin): Lecteur
    {
        if (! is_file($chemin) || ! is_readable($chemin)) {
            throw new RuntimeException('Le fichier est introuvable ou illisible.');
        }

        $taille = filesize($chemin);

        if ($taille === false || $taille === 0) {
            throw new RuntimeException('Le fichier est vide.');
        }

        if ($taille > self::POIDS_MAXIMAL) {
            throw new RuntimeException('Le fichier dépasse '.(self::POIDS_MAXIMAL / 1024 / 1024).' Mo.');
        }

        return match (self::format($chemin)) {
            'xlsx' => new LecteurXlsx($chemin),
            'xls' => new LecteurXls($chemin),
            'pdf' => new LecteurPdf($chemin),
            default => throw new RuntimeException(
                "Ce fichier n'est ni un classeur Excel (.xlsx, .xlsm, .xls) ni un document PDF."
            ),
        };
    }

    /**
     * Ce qu'est réellement le fichier : `xlsx`, `xls`, `pdf`, ou null.
     *
     * Huit octets suffisent, et on ne lit que ceux-là — inutile de charger quarante méga-
     * octets pour découvrir qu'on a affaire à une image renommée.
     */
    public static function format(string $chemin): ?string
    {
        $poignee = @fopen($chemin, 'rb');

        if ($poignee === false) {
            return null;
        }

        $entete = (string) fread($poignee, 8);
        fclose($poignee);

        if (str_starts_with($entete, LecteurXlsx::SIGNATURE)) {
            return 'xlsx';
        }

        if (str_starts_with($entete, LecteurXls::SIGNATURE)) {
            return 'xls';
        }

        if (str_starts_with($entete, LecteurPdf::SIGNATURE)) {
            return 'pdf';
        }

        return null;
    }

    /**
     * Vrai quand le classeur contient des macros.
     *
     * L'information n'est pas un avertissement de danger — rien ici n'exécute quoi que ce
     * soit — mais un renseignement à afficher : un `.xlsm` vient d'un fichier de travail
     * vivant, souvent enrichi à la main, et son contenu mérite qu'on le regarde de plus
     * près que celui d'un export brut.
     */
    public static function porteDesMacros(string $chemin): bool
    {
        if (self::format($chemin) !== 'xlsx') {
            return false;
        }

        $archive = new \ZipArchive;

        if ($archive->open($chemin) !== true) {
            return false;
        }

        $presente = $archive->locateName('xl/vbaProject.bin') !== false;
        $archive->close();

        return $presente;
    }
}

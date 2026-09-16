<?php

namespace Modules\Noyau\Imports\Lecteurs;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lecture en flux des classeurs `.xlsx` et `.xlsm`.
 *
 * Les deux formats sont **le même** : une archive ZIP de fichiers XML. Un `.xlsm` ne s'en
 * distingue que par la présence de `xl/vbaProject.bin`, le code des macros. On ne l'ouvre
 * pas, on ne l'exécute pas, on l'ignore — et c'est la réponse à la question posée plus tôt
 * sur les fichiers à macros : elles sont inertes ici, parce que rien dans cette classe ne
 * sait ce qu'est une macro. Le tableur, lui, les exécuterait à l'ouverture.
 *
 * Même remarque pour les **formules** : chaque cellule calculée porte à la fois sa formule
 * et son dernier résultat. On lit le résultat, jamais la formule. Sur le suivi
 * fournisseurs, cela concerne 73 031 cellules — toutes avec une valeur en cache, on l'a
 * vérifié fichier en main.
 *
 * La lecture est en flux d'un bout à l'autre : XMLReader avance dans le XML sans jamais en
 * construire l'arbre. Une feuille de 9 004 lignes se lit à mémoire constante.
 */
class LecteurXlsx implements Lecteur
{
    /** Les 4 premiers octets d'une archive ZIP, donc de tout classeur moderne. */
    public const SIGNATURE = "PK\x03\x04";

    private ZipArchive $archive;

    /** @var array<string, string> nom de feuille => chemin de l'entrée XML */
    private array $feuilles = [];

    /** @var list<string> */
    private array $chaines = [];

    /** @var array<int, bool> index de style => la cellule porte-t-elle une date */
    private array $stylesDates = [];

    public function __construct(private string $chemin)
    {
        $this->archive = new ZipArchive;

        if ($this->archive->open($chemin) !== true) {
            throw new RuntimeException("Le fichier ne s'ouvre pas comme un classeur.");
        }

        $this->lireLesFeuilles();
        $this->lireLesChaines();
        $this->lireLesStyles();
    }

    public function feuilles(): array
    {
        return array_keys($this->feuilles);
    }

    public function lignes(?string $feuille = null): Generator
    {
        $entree = $this->entreeDe($feuille);

        $lecteur = new XMLReader;

        if (! @$lecteur->open($this->uri($entree))) {
            // Certaines configurations n'exposent pas le flux `zip://`. On se rabat sur la
            // lecture en mémoire, qui reste correcte pour les feuilles ordinaires.
            $xml = $this->archive->getFromName($entree);

            if ($xml === false || ! $lecteur->XML($xml)) {
                throw new RuntimeException('La feuille est illisible.');
            }
        }

        try {
            $ligne = 0;

            while ($lecteur->read()) {
                if ($lecteur->nodeType !== XMLReader::ELEMENT || $lecteur->name !== 'row') {
                    continue;
                }

                // Le tableur saute les lignes vides : l'attribut `r` fait autorité, et le
                // compteur ne sert que si le fichier ne le renseigne pas.
                $ligne = (int) ($lecteur->getAttribute('r') ?: $ligne + 1);

                // Une ligne déclarée vide n'a pas de contenu à parcourir.
                if ($lecteur->isEmptyElement) {
                    continue;
                }

                yield $ligne => $this->lireLaLigne($lecteur);
            }
        } finally {
            $lecteur->close();
        }
    }

    public function fermer(): void
    {
        $this->archive->close();
    }

    /** Les cellules d'une ligne, indexées par position de colonne à partir de 0. */
    private function lireLaLigne(XMLReader $lecteur): array
    {
        $valeurs = [];
        $profondeur = $lecteur->depth;

        while ($lecteur->read()) {
            // On s'arrête à la fermeture de `<row>`, sans jamais déborder sur la suivante.
            if ($lecteur->nodeType === XMLReader::END_ELEMENT
                && $lecteur->name === 'row'
                && $lecteur->depth === $profondeur) {
                break;
            }

            if ($lecteur->nodeType !== XMLReader::ELEMENT || $lecteur->name !== 'c') {
                continue;
            }

            $colonne = self::colonneDe((string) $lecteur->getAttribute('r'));
            $type = (string) $lecteur->getAttribute('t');
            $style = $lecteur->getAttribute('s');

            if ($lecteur->isEmptyElement) {
                continue;
            }

            $brut = null;
            $inline = null;
            $cellule = $lecteur->depth;

            while ($lecteur->read()) {
                if ($lecteur->nodeType === XMLReader::END_ELEMENT
                    && $lecteur->name === 'c'
                    && $lecteur->depth === $cellule) {
                    break;
                }

                if ($lecteur->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                // `<v>` porte la valeur — y compris le résultat en cache d'une formule,
                // qui est précisément ce qu'on veut. `<f>` n'est jamais lue.
                if ($lecteur->name === 'v') {
                    $brut = $lecteur->readString();
                } elseif ($lecteur->name === 't') {
                    // Chaîne écrite dans la cellule plutôt que mise en commun.
                    $inline .= $lecteur->readString();
                }
            }

            $valeur = $this->interpreter($type, $brut, $inline, $style === null ? null : (int) $style);

            if ($valeur !== null && $valeur !== '') {
                $valeurs[$colonne] = $valeur;
            }
        }

        ksort($valeurs);

        return $valeurs;
    }

    /** Ce que vaut une cellule, selon son type déclaré et son format d'affichage. */
    private function interpreter(string $type, ?string $brut, ?string $inline, ?int $style): string|float|DateTimeImmutable|null
    {
        if ($type === 'inlineStr') {
            return $inline === null ? null : trim($inline);
        }

        if ($brut === null) {
            return $inline === null ? null : trim($inline);
        }

        return match ($type) {
            's' => trim($this->chaines[(int) $brut] ?? ''),
            'str' => trim($brut),
            'b' => $brut === '1' ? 'VRAI' : 'FAUX',
            // Une cellule en erreur (#N/A, #REF!) est une valeur du fichier, pas une
            // panne : elle est rendue telle quelle et c'est le contrôle qui tranchera.
            'e' => trim($brut),
            default => $this->nombre($brut, $style),
        };
    }

    /** Un nombre, ou la date qu'il représente quand le format le dit. */
    private function nombre(string $brut, ?int $style): string|float|DateTimeImmutable|null
    {
        if (! is_numeric($brut)) {
            return trim($brut) === '' ? null : trim($brut);
        }

        $valeur = (float) $brut;

        if ($style !== null && ($this->stylesDates[$style] ?? false)) {
            return self::dateDeSerie($valeur) ?? $valeur;
        }

        return $valeur;
    }

    /**
     * La date que désigne un numéro de série Excel.
     *
     * L'origine est le 30/12/1899 et non le 31/12 : le tableur reproduit un bug de 1900,
     * une année bissextile qui ne l'était pas, et décaler l'origine d'un jour est la façon
     * habituelle de retomber sur ses pieds.
     *
     * La borne haute écarte les nombres qui ne sont des dates que par accident de format —
     * un montant mis en forme « jj/mm/aaaa » par erreur donnerait l'an 12 000.
     */
    public static function dateDeSerie(float $serie): ?DateTimeImmutable
    {
        if ($serie < 1 || $serie > 2958465) {
            return null;
        }

        $origine = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        $jours = (int) floor($serie);
        $secondes = (int) round(($serie - $jours) * 86400);

        return $origine->modify(sprintf('+%d days +%d seconds', $jours, $secondes)) ?: null;
    }

    /** « BJ12 » → 61. La référence porte la colonne en lettres et la ligne en chiffres. */
    public static function colonneDe(string $reference): int
    {
        $index = 0;

        for ($i = 0, $n = strlen($reference); $i < $n; $i++) {
            $c = $reference[$i];

            if ($c < 'A' || $c > 'Z') {
                break;
            }

            $index = $index * 26 + (ord($c) - 64);
        }

        return max(0, $index - 1);
    }

    /**
     * Vrai quand un format d'affichage désigne une date ou une heure.
     *
     * Les codes 14 à 22 et 45 à 47 sont les formats de date intégrés au tableur. Au-delà,
     * ce sont des formats définis par l'utilisateur, qu'il faut lire : on y cherche un
     * marqueur de date en ignorant ce qui est entre guillemets — « 0" jours" » n'est pas
     * une date malgré son « j » — et entre crochets, qui portent la couleur et la langue.
     */
    public static function formatEstUneDate(int $code, ?string $motif): bool
    {
        if (($code >= 14 && $code <= 22) || ($code >= 45 && $code <= 47)) {
            return true;
        }

        if ($motif === null || $motif === '') {
            return false;
        }

        $nettoye = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./u', '', $motif);

        return (bool) preg_match('/[yamjdhs]/i', (string) $nettoye);
    }

    /**
     * Combien de lignes compte la plus longue feuille, lu dans l'en-tête et non en parcourant.
     *
     * Chaque feuille annonce son étendue dès ses premiers octets : `<dimension ref="A1:T9108"/>`.
     * On ne lit donc que le début de chaque entrée — quelques kilo-octets, là où la feuille des
     * impayés en pèse plusieurs mégas. C'est ce qui donne à la barre de progression une
     * longueur vers laquelle avancer.
     *
     * **Une estimation, et rien de plus.** Le format choisit sa feuille après coup, et
     * certains logiciels écrivent une étendue approximative ou « A1 » tout court. On retient la
     * plus longue, et null quand rien d'exploitable n'est annoncé : une barre sans longueur
     * vaut mieux qu'une barre qui ment.
     */
    public function estimerLignes(): ?int
    {
        $plusLongue = 0;

        foreach ($this->feuilles as $entree) {
            $flux = $this->archive->getStream($entree);

            if ($flux === false) {
                continue;
            }

            $debut = (string) fread($flux, 4096);
            fclose($flux);

            if (preg_match('/<dimension\s+ref="[A-Z]+\d+:[A-Z]+(\d+)"/', $debut, $trouve)) {
                $plusLongue = max($plusLongue, (int) $trouve[1]);
            }
        }

        return $plusLongue > 1 ? $plusLongue : null;
    }

    private function lireLesFeuilles(): void
    {
        $workbook = $this->archive->getFromName('xl/workbook.xml');
        $rels = $this->archive->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false) {
            throw new RuntimeException("Le classeur n'a pas de feuille lisible.");
        }

        $cibles = [];

        if ($rels !== false && preg_match_all('/Id="([^"]+)"[^>]*Target="([^"]+)"/', $rels, $trouves, PREG_SET_ORDER)) {
            foreach ($trouves as $t) {
                $cibles[$t[1]] = ltrim($t[2], '/');
            }
        }

        if (preg_match_all('/<sheet\b[^>]*>/', $workbook, $balises)) {
            $rang = 0;

            foreach ($balises[0] as $balise) {
                $rang++;
                preg_match('/\bname="([^"]*)"/', $balise, $nom);
                preg_match('/\br:id="([^"]*)"/', $balise, $id);

                $cible = $cibles[$id[1] ?? ''] ?? "worksheets/sheet{$rang}.xml";
                $entree = str_starts_with($cible, 'xl/') ? $cible : 'xl/'.$cible;

                $libelle = html_entity_decode($nom[1] ?? "Feuille{$rang}", ENT_QUOTES | ENT_XML1, 'UTF-8');

                $this->feuilles[$libelle] = $entree;
            }
        }

        if ($this->feuilles === []) {
            throw new RuntimeException("Le classeur n'a pas de feuille lisible.");
        }
    }

    /**
     * Le dictionnaire des chaînes mises en commun.
     *
     * Le tableur ne réécrit pas « TOYOTA » deux mille fois : il l'écrit une fois ici, et
     * les cellules y renvoient par leur rang. Une chaîne peut être découpée en morceaux de
     * mise en forme — un mot en gras au milieu d'une phrase — qu'il faut recoller.
     */
    private function lireLesChaines(): void
    {
        if ($this->archive->locateName('xl/sharedStrings.xml') === false) {
            return;
        }

        $lecteur = new XMLReader;

        if (! @$lecteur->open($this->uri('xl/sharedStrings.xml'))) {
            $xml = $this->archive->getFromName('xl/sharedStrings.xml');

            if ($xml === false || ! $lecteur->XML($xml)) {
                return;
            }
        }

        try {
            while ($lecteur->read()) {
                if ($lecteur->nodeType !== XMLReader::ELEMENT || $lecteur->name !== 'si') {
                    continue;
                }

                if ($lecteur->isEmptyElement) {
                    $this->chaines[] = '';

                    continue;
                }

                $morceaux = '';
                $profondeur = $lecteur->depth;

                while ($lecteur->read()) {
                    if ($lecteur->nodeType === XMLReader::END_ELEMENT
                        && $lecteur->name === 'si'
                        && $lecteur->depth === $profondeur) {
                        break;
                    }

                    if ($lecteur->nodeType === XMLReader::ELEMENT && $lecteur->name === 't') {
                        $morceaux .= $lecteur->readString();
                    }
                }

                $this->chaines[] = $morceaux;
            }
        } finally {
            $lecteur->close();
        }
    }

    /** Quels styles affichent une date : c'est la seule façon de le savoir. */
    private function lireLesStyles(): void
    {
        $xml = $this->archive->getFromName('xl/styles.xml');

        if ($xml === false) {
            return;
        }

        $motifs = [];

        if (preg_match_all('/<numFmt\b[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"/', $xml, $trouves, PREG_SET_ORDER)) {
            foreach ($trouves as $t) {
                $motifs[(int) $t[1]] = html_entity_decode($t[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        // Seul le bloc `cellXfs` est indexé par l'attribut `s` des cellules ; `cellStyleXfs`
        // lui ressemble comme un jumeau et le confondre décale tous les formats.
        if (! preg_match('/<cellXfs\b.*?<\/cellXfs>/s', $xml, $bloc)) {
            return;
        }

        if (preg_match_all('/<xf\b[^>]*>/', $bloc[0], $balises)) {
            foreach ($balises[0] as $index => $balise) {
                preg_match('/\bnumFmtId="(\d+)"/', $balise, $code);
                $numFmtId = (int) ($code[1] ?? 0);

                $this->stylesDates[$index] = self::formatEstUneDate($numFmtId, $motifs[$numFmtId] ?? null);
            }
        }
    }

    private function entreeDe(?string $feuille): string
    {
        if ($feuille === null) {
            return reset($this->feuilles);
        }

        if (! isset($this->feuilles[$feuille])) {
            throw new RuntimeException("La feuille « {$feuille} » n'existe pas dans ce fichier.");
        }

        return $this->feuilles[$feuille];
    }

    private function uri(string $entree): string
    {
        return 'zip://'.str_replace('\\', '/', $this->chemin).'#'.$entree;
    }
}

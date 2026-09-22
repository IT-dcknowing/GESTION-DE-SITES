<?php

namespace Modules\Noyau\Imports\Lecteurs;

use Generator;
use RuntimeException;

/**
 * Lecture d'un état imprimé en PDF, colonne par colonne.
 *
 * **Pourquoi un lecteur de PDF alors qu'on n'en voulait pas.** Le journal de caisse est le
 * seul état que le logiciel d'atelier ne sait pas exporter en tableur. Bouaké et San-Pédro
 * n'ont que lui : mille cent quatre mouvements qui n'existaient nulle part dans
 * l'application, et un écran de caisse qui ne montrait que le classeur d'Abidjan. Le choix
 * n'était donc pas « PDF ou tableur », mais « ces deux villes ou rien ».
 *
 * **Ce qu'un PDF contient, et ce qu'il ne contient pas.** Il ne porte ni lignes ni colonnes
 * : il porte des morceaux de texte, chacun avec sa position sur la page. Un tableau n'y
 * existe que parce que les morceaux sont alignés. On reconstitue donc les rangées en
 * regroupant ce qui partage une même hauteur, et les colonnes en cherchant la rangée
 * d'intitulés — celle dont toutes les cellules sont des mots en capitales sans un chiffre.
 * Chaque intitulé ouvre une colonne, qui court jusqu'au suivant. C'est exactement la
 * lecture que fait un œil humain, et elle ne suppose aucune mise en page connue d'avance.
 *
 * **Deux fabricants, deux écritures, le même état.** Le fichier de Bouaké pose son texte
 * avec `Tm` et des chaînes hexadécimales dans une police à index (il faut sa table
 * `ToUnicode` pour savoir quelle lettre chaque code désigne) ; celui de San-Pédro avec `Td`
 * et des chaînes littérales. Les deux impriment le même journal. Le lecteur accepte les
 * deux écritures, et retourne l'axe vertical quand le document a posé une transformation
 * qui le renverse — sans quoi la première ligne serait la dernière.
 *
 * **Ce que ce lecteur ne fait pas.** Il n'exécute rien : aucun JavaScript, aucune action,
 * aucune police n'est installée — on ne lit que des positions et des caractères. Il ne
 * rend pas la mise en page, ne lit pas les images et ne déchiffre pas un document protégé
 * : dans ce dernier cas les flux ne se décompressent pas, aucune page n'est trouvée, et le
 * format le dit au lieu de deviner.
 *
 * **La mémoire.** Le fichier est lu une fois, et chaque flux est décompressé seul puis
 * relâché : on ne tient jamais plus d'une page décompressée à la fois. Un flux
 * anormalement gros est écarté — un état imprimé n'en produit pas, une image oui.
 */
class LecteurPdf implements Lecteur
{
    /** Les cinq premiers octets de tout PDF. */
    public const SIGNATURE = '%PDF-';

    /** Au-delà, un flux n'est pas du texte de page : c'est une image ou une police. */
    private const FLUX_MAXIMAL = 4 * 1024 * 1024;

    /** Deux morceaux séparés de moins que cela sont sur la même rangée. */
    private const TOLERANCE_RANGEE = 4.0;

    /**
     * Le jeu que laisse le bord gauche d'une colonne.
     *
     * Les nombres sont alignés à droite : « 1 000 » commence plus loin que « 270 000 »
     * dans la même colonne. Sans ce jeu, un petit montant basculerait dans la colonne
     * suivante — le défaut qui, sur ces deux fichiers, aurait fait passer des entrées pour
     * des sorties.
     */
    private const JEU_DE_COLONNE = 10.0;

    private string $contenu = '';

    /** @var list<array{int, int}> décalage et longueur de chaque flux de page */
    private array $pages = [];

    /** @var array<string, string> code hexadécimal => caractère, toutes polices confondues */
    private array $caracteres = [];

    /**
     * La dernière page décompressée, et elle seule.
     *
     * La recherche de feuille relit les premières rangées de chaque page avant que le
     * parcours ne les relise toutes : sans mémoire, chaque page serait décompressée deux
     * fois. Avec une mémoire de toutes les pages, un document de cinq cents pages tiendrait
     * entier en mémoire. Une seule page suffit à couvrir les deux lectures de suite.
     */
    private ?int $pageEnMemoire = null;

    /** @var list<array{float, float, string}> */
    private array $morceaux = [];

    public function __construct(private string $chemin)
    {
        $contenu = @file_get_contents($chemin);

        if ($contenu === false || ! str_starts_with($contenu, self::SIGNATURE)) {
            throw new RuntimeException("Le fichier ne s'ouvre pas comme un document PDF.");
        }

        $this->contenu = $contenu;
        $this->repererLesFlux();
    }

    public function feuilles(): array
    {
        return array_map(fn (int $rang) => 'p. '.($rang + 1), array_keys($this->pages));
    }

    /**
     * Les rangées d'une page, distribuées dans les colonnes de son tableau.
     *
     * La clé est le rang de la rangée dans la page, à partir de 1 : c'est ce qu'il faut
     * pour retrouver une ligne rejetée, page en main.
     */
    public function lignes(?string $feuille = null): Generator
    {
        $page = $this->pageDe($feuille);
        $rangees = $this->rangees($page);
        $bornes = $this->bornesDesColonnes($rangees);

        foreach ($rangees as $rang => $rangee) {
            $cellules = [];

            foreach ($rangee as [$x, $texte]) {
                $colonne = $this->colonneDe($x, $bornes);
                $cellules[$colonne] = isset($cellules[$colonne])
                    ? $cellules[$colonne].' '.$texte
                    : $texte;
            }

            ksort($cellules);

            yield $rang + 1 => $cellules;
        }
    }

    public function fermer(): void
    {
        $this->contenu = '';
        $this->morceaux = [];
        $this->pageEnMemoire = null;
    }

    private function pageDe(?string $feuille): int
    {
        if ($feuille === null) {
            return 0;
        }

        $rang = (int) filter_var($feuille, FILTER_SANITIZE_NUMBER_INT) - 1;

        if (! isset($this->pages[$rang])) {
            throw new RuntimeException("La page « {$feuille} » n'existe pas dans ce document.");
        }

        return $rang;
    }

    /**
     * Repère les flux du document, et retient ceux qui portent du texte de page.
     *
     * Chaque flux est décompressé pour être jugé, puis relâché aussitôt : c'est le seul
     * moyen de distinguer une page d'une police, les deux se présentant de la même façon
     * dans le fichier. Les tables `ToUnicode` sont retenues au passage — ce sont elles qui
     * disent quelle lettre porte chaque code d'une police à index.
     */
    private function repererLesFlux(): void
    {
        $position = 0;
        $longueur = strlen($this->contenu);

        while ($position < $longueur) {
            $debut = strpos($this->contenu, 'stream', $position);

            if ($debut === false) {
                break;
            }

            $apres = $debut + 6;

            // Le mot « stream » est suivi d'un saut de ligne, précédé ou non d'un retour
            // chariot : les deux écritures existent, et la première octet compte.
            if (substr($this->contenu, $apres, 2) === "\r\n") {
                $apres += 2;
            } elseif (substr($this->contenu, $apres, 1) === "\n") {
                $apres += 1;
            } else {
                $position = $apres;

                continue;
            }

            $taille = $this->longueurDeclaree($debut, $apres)
                ?? $this->longueurJusquAuMotDeFin($apres);

            if ($taille === null) {
                break;
            }

            // On reprend la lecture **après** le « endstream » qui suit le flux, et non à
            // la fin du flux : le mot « endstream » contient lui-même « stream », et
            // repartir dessus ferait découvrir un flux imaginaire à chaque objet.
            $suivant = strpos($this->contenu, 'endstream', $apres + $taille);
            $position = $suivant === false ? $apres + $taille : $suivant + 9;

            if ($taille <= 0 || $taille > self::FLUX_MAXIMAL) {
                continue;
            }

            $brut = substr($this->contenu, $apres, $taille);

            if (str_starts_with(ltrim($brut), '/CIDInit')) {
                $this->lireUneTableDeCaracteres($brut);

                continue;
            }

            $clair = @gzuncompress($brut);

            if ($clair === false) {
                continue;
            }

            if (str_contains($clair, 'BT') && ! str_contains(substr($clair, 0, 200), 'cmap')) {
                $this->pages[] = [$apres, $taille];
            } elseif (str_starts_with(ltrim($clair), '/CIDInit')) {
                $this->lireUneTableDeCaracteres($clair);
            }

            unset($clair);
        }

        $this->pages = array_values($this->pages);
    }

    /**
     * La longueur que l'objet annonce pour son flux, quand elle est utilisable.
     *
     * **Chercher « endstream » ne suffit pas.** Un flux compressé est une suite d'octets
     * quelconques : rien n'interdit que ces neuf lettres s'y trouvent par hasard, et le flux
     * serait alors coupé en plein milieu — il ne se décompresserait pas, et la page
     * disparaîtrait sans un mot. L'objet déclare sa longueur juste avant ; on la croit
     * seulement si « endstream » suit bien là où elle le promet, ce qui écarte du même coup
     * les longueurs écrites dans un autre objet (`/Length 12 0 R`).
     */
    private function longueurDeclaree(int $debut, int $apres): ?int
    {
        $dictionnaire = substr($this->contenu, max(0, $debut - 400), min(400, $debut));

        // L'espace après « Length » compte : sans lui, on lirait « /Length1 542232 », qui
        // est la taille de la police **avant** compression et n'a rien à voir.
        if (preg_match_all('/\/Length\s+(\d+)(?![0-9])/', $dictionnaire, $trouvailles) < 1) {
            return null;
        }

        foreach (array_reverse($trouvailles[1]) as $valeur) {
            $taille = (int) $valeur;
            $suite = ltrim(substr($this->contenu, $apres + $taille, 20));

            if ($taille > 0 && str_starts_with($suite, 'endstream')) {
                return $taille;
            }
        }

        return null;
    }

    /** Ce qui sépare le flux du mot qui le termine, quand l'objet n'a pas dit sa longueur. */
    private function longueurJusquAuMotDeFin(int $apres): ?int
    {
        $fin = strpos($this->contenu, 'endstream', $apres);

        return $fin === false ? null : $fin - $apres;
    }

    /**
     * La table qui dit, pour une police à index, quel caractère porte chaque code.
     *
     * Les tables de toutes les polices sont fondues en une seule. C'est une simplification
     * assumée : elle vaut tant que deux polices du même document n'attribuent pas le même
     * code à deux lettres différentes. Vérifié sur les deux journaux réels — aucune
     * collision sur les soixante-dix-neuf codes du fichier de Bouaké. En cas de conflit, la
     * première lecture l'emporte plutôt que la dernière, pour que le résultat ne dépende
     * pas de l'ordre des objets dans le fichier.
     */
    private function lireUneTableDeCaracteres(string $table): void
    {
        $position = strpos($table, 'beginbfchar');

        if ($position !== false) {
            $table = substr($table, $position);
        }

        if (preg_match_all('/<([0-9A-Fa-f]{4})>\s*<([0-9A-Fa-f]{4,})>/', $table, $trouvailles, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($trouvailles as $trouvaille) {
            $code = strtoupper($trouvaille[1]);

            if (isset($this->caracteres[$code])) {
                continue;
            }

            $this->caracteres[$code] = $this->depuisDesPointsDeCode($trouvaille[2]);
        }
    }

    private function depuisDesPointsDeCode(string $hexadecimal): string
    {
        $texte = '';

        foreach (str_split($hexadecimal, 4) as $point) {
            if (strlen($point) === 4) {
                $texte .= mb_chr((int) hexdec($point), 'UTF-8');
            }
        }

        return $texte;
    }

    /**
     * Les morceaux de texte d'une page : leur hauteur, leur abscisse, ce qu'ils disent.
     *
     * L'axe vertical d'un PDF monte, mais un document peut poser d'emblée une
     * transformation qui le renverse — c'est le cas du journal de Bouaké. On lit cette
     * transformation quand elle est là et on oriente la lecture en conséquence, pour que
     * l'ordre des rangées soit toujours celui de la lecture.
     *
     * @return list<array{float, float, string}>
     */
    private function morceauxDe(int $page): array
    {
        if ($this->pageEnMemoire === $page) {
            return $this->morceaux;
        }

        $this->pageEnMemoire = $page;
        [$decalage, $taille] = $this->pages[$page];
        $flux = @gzuncompress(substr($this->contenu, $decalage, $taille));

        if ($flux === false) {
            return $this->morceaux = [];
        }

        $sens = $this->axeRenverse($flux) ? 1.0 : -1.0;
        $morceaux = [];

        /*
         * On suit les opérateurs un à un, au lieu de découper le flux en blocs BT…ET.
         *
         * Le découpage paraissait naturel et il était faux : le mot « REMETTANT » contient
         * les deux lettres ET, et un bloc coupé là perdait tout son texte. Le fichier de
         * San-Pédro écrit ses chaînes en clair — c'est donc **tous ses remettants** qui
         * disparaissaient, sans qu'aucun compteur ne bouge, puisque la date et le montant
         * sont posés par d'autres opérateurs.
         *
         * Le modèle réel est simple : une position se pose (`Td`, `TD`, `Tm`), une ou
         * plusieurs chaînes s'accumulent, un opérateur les montre (`Tj`, `TJ`, `'`, `"`).
         * Les chaînes sont reconnues en premier dans l'alternative, ce qui met à l'abri de
         * tout ce qu'elles peuvent contenir — y compris une apostrophe, qui est elle-même
         * un opérateur.
         */
        $nombre = '[\d.+-]+';
        $motif = '/(?P<chaine>\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f]{2,}>)'
            ."|(?P<tm>{$nombre}\s+{$nombre}\s+{$nombre}\s+{$nombre}\s+({$nombre})\s+({$nombre})\s+Tm)"
            ."|(?P<td>({$nombre})\s+({$nombre})\s+T[dD])"
            ."|(?P<montre>Tj|TJ|'|\")/s";

        $x = null;
        $y = null;
        $texte = '';

        if (preg_match_all($motif, $flux, $trouvailles, PREG_SET_ORDER) !== false) {
            foreach ($trouvailles as $trouvaille) {
                if (($trouvaille['chaine'] ?? '') !== '') {
                    $chaine = $trouvaille['chaine'];
                    $texte .= $chaine[0] === '<'
                        ? $this->depuisLIndex(substr($chaine, 1, -1))
                        : $this->depuisLesParentheses(substr($chaine, 1, -1));

                    continue;
                }

                if (($trouvaille['tm'] ?? '') !== '') {
                    $x = (float) $trouvaille[3];
                    $y = (float) $trouvaille[4];

                    continue;
                }

                if (($trouvaille['td'] ?? '') !== '') {
                    $x = (float) $trouvaille[6];
                    $y = (float) $trouvaille[7];

                    continue;
                }

                if (trim($texte) !== '' && $x !== null && $y !== null) {
                    $morceaux[] = [$sens * $y, $x, trim($texte)];
                }

                $texte = '';
            }
        }

        usort($morceaux, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $this->morceaux = $morceaux;
    }

    private function depuisLIndex(string $hexadecimal): string
    {
        $texte = '';

        foreach (str_split($hexadecimal, 4) as $code) {
            $texte .= $this->caracteres[strtoupper($code)] ?? '';
        }

        return $texte;
    }

    /**
     * Une chaîne littérale, ramenée en UTF-8.
     *
     * Les caractères y sont écrits dans l'encodage de la police — Windows-1252 dans les
     * faits, le seul qui donne « N° » là où le document imprime « N° ». Les antislashs
     * protègent les parenthèses et écrivent les caractères par leur code en octal.
     */
    private function depuisLesParentheses(string $chaine): string
    {
        $chaine = preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|(.))/s',
            fn (array $trouvaille) => isset($trouvaille[1]) && $trouvaille[1] !== ''
                ? chr((int) octdec($trouvaille[1]) % 256)
                : match ($trouvaille[2]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $trouvaille[2],
                },
            $chaine,
        ) ?? $chaine;

        return (string) mb_convert_encoding($chaine, 'UTF-8', 'Windows-1252');
    }

    /** Vrai quand le document a renversé son axe vertical avant d'écrire la première lettre. */
    private function axeRenverse(string $flux): bool
    {
        $tete = strstr($flux, 'BT', true);

        if ($tete === false) {
            return false;
        }

        $nombre = '([\d.+-]+)';
        $motif = "/\bq\b|\bQ\b|{$nombre}\s+{$nombre}\s+{$nombre}\s+{$nombre}\s+{$nombre}\s+{$nombre}\s+cm/";

        if (preg_match_all($motif, $tete, $trouvailles, PREG_SET_ORDER) === false) {
            return false;
        }

        $profondeur = 0;

        foreach ($trouvailles as $trouvaille) {
            $texte = trim($trouvaille[0]);

            if ($texte === 'q') {
                $profondeur++;
            } elseif ($texte === 'Q') {
                $profondeur--;
            } elseif ($profondeur === 0) {
                // Le quatrième terme d'une transformation est l'échelle verticale : elle
                // est négative quand le document se dessine de haut en bas.
                return (float) $trouvaille[4] < 0;
            }
        }

        return false;
    }

    /**
     * Les rangées d'une page : ce qui partage une hauteur se lit sur une même ligne.
     *
     * @return list<list<array{float, string}>>
     */
    private function rangees(int $page): array
    {
        $rangees = [];
        $hauteurs = [];

        foreach ($this->morceauxDe($page) as [$y, $x, $texte]) {
            $derniere = count($rangees) - 1;

            if ($derniere >= 0 && abs($y - $hauteurs[$derniere]) <= self::TOLERANCE_RANGEE) {
                $rangees[$derniere][] = [$x, $texte];

                continue;
            }

            $rangees[] = [[$x, $texte]];
            $hauteurs[] = $y;
        }

        foreach ($rangees as &$rangee) {
            usort($rangee, fn ($a, $b) => $a[0] <=> $b[0]);
        }

        return $rangees;
    }

    /**
     * Les abscisses où commencent les colonnes, lues sur la rangée d'intitulés.
     *
     * La rangée d'intitulés est la première, dans les quinze premières, qui aligne au
     * moins trois cellules dont aucune ne porte de chiffre. Un titre isolé n'en compte
     * qu'une ; la ligne qui annonce la période porte des dates ; celle du solde d'ouverture
     * porte un montant. Seule la rangée des colonnes satisfait les trois conditions à la
     * fois, sur les deux journaux comme sur le bon sens d'un état imprimé.
     *
     * Sans rangée d'intitulés, une seule colonne : on rend le texte dans l'ordre de lecture
     * plutôt que de découper au hasard.
     *
     * @param  list<list<array{float, string}>>  $rangees
     * @return list<float>
     */
    private function bornesDesColonnes(array $rangees): array
    {
        foreach (array_slice($rangees, 0, 15) as $rangee) {
            if (count($rangee) < 3) {
                continue;
            }

            foreach ($rangee as [$x, $texte]) {
                if (preg_match('/\d/', $texte) === 1 || mb_strtoupper($texte) !== $texte) {
                    continue 2;
                }
            }

            return array_map(fn (array $cellule) => (float) $cellule[0], $rangee);
        }

        return [0.0];
    }

    /** @param  list<float>  $bornes */
    private function colonneDe(float $x, array $bornes): int
    {
        $colonne = 0;

        foreach ($bornes as $rang => $borne) {
            if ($x >= $borne - self::JEU_DE_COLONNE) {
                $colonne = $rang;
            }
        }

        return $colonne;
    }
}

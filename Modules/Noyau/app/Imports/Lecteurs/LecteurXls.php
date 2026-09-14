<?php

namespace Modules\Noyau\Imports\Lecteurs;

use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Lecture des classeurs `.xls` — le format d'avant 2007.
 *
 * La situation du parc sort du logiciel dans ce format, et lui seul : ce n'est pas du XML
 * compressé mais un conteneur OLE2, un système de fichiers miniature à l'intérieur d'un
 * fichier, dans lequel un flux nommé « Workbook » contient une suite d'enregistrements
 * binaires. Rien dans PHP ne sait le lire, d'où cette classe.
 *
 * **Le piège du dictionnaire de chaînes.** Les textes du classeur sont mis en commun dans
 * un enregistrement SST qui déborde sur des enregistrements CONTINUE. Une chaîne peut être
 * coupée en plein milieu — et le morceau qui suit recommence par son propre octet de
 * drapeau, qui peut changer d'encodage au passage. Recoller les blocs bout à bout avant de
 * lire casse donc la première chaîne coupée, puis toutes les suivantes. Sur le parc
 * d'Abidjan, la version naïve lisait 45 fiches sur 2 204. Le flux ci-dessous respecte les
 * frontières de blocs, et c'est la seule raison pour laquelle il est écrit ainsi.
 *
 * Contrairement au lecteur `.xlsx`, celui-ci rassemble une feuille entière avant de la
 * rendre : les cellules d'un `.xls` ne sont pas garanties d'arriver dans l'ordre des
 * lignes. Le format plafonne à 65 536 lignes, la mémoire est donc bornée par construction.
 */
class LecteurXls implements Lecteur
{
    /** L'en-tête d'un conteneur OLE2. Les `.doc` et `.ppt` d'époque le partagent. */
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private string $donnees;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $miniFlux = '';

    private int $tailleSecteur;

    private int $tailleMiniSecteur;

    private int $seuilMini;

    /** @var array<string, int> nom de feuille => rang du sous-flux */
    private array $feuilles = [];

    /** @var list<string> */
    private array $chaines = [];

    /** @var array<int, int> index XF => code de format */
    private array $formatsDesStyles = [];

    /** @var array<int, string> code de format => motif d'affichage */
    private array $motifs = [];

    /** @var array<int, array<int, array<int, string|float|DateTimeImmutable|null>>> */
    private array $grilles = [];

    private bool $luEnEntier = false;

    public function __construct(private string $chemin)
    {
        $contenu = @file_get_contents($chemin);

        if ($contenu === false || ! str_starts_with($contenu, self::SIGNATURE)) {
            throw new RuntimeException("Le fichier ne s'ouvre pas comme un classeur.");
        }

        $this->donnees = $contenu;
        $this->ouvrirLeConteneur();
    }

    public function feuilles(): array
    {
        $this->lireLeClasseur();

        return array_keys($this->feuilles);
    }

    public function lignes(?string $feuille = null): Generator
    {
        $this->lireLeClasseur();

        if ($feuille === null) {
            $rang = 0;
        } elseif (isset($this->feuilles[$feuille])) {
            $rang = $this->feuilles[$feuille];
        } else {
            throw new RuntimeException("La feuille « {$feuille} » n'existe pas dans ce fichier.");
        }

        $grille = $this->grilles[$rang] ?? [];
        ksort($grille);

        foreach ($grille as $ligne => $cellules) {
            ksort($cellules);

            // Le numéro rendu est celui du tableur, à partir de 1 : c'est le seul qui
            // permette à l'utilisateur de retrouver une ligne rejetée dans son fichier.
            yield $ligne + 1 => $cellules;
        }
    }

    public function fermer(): void
    {
        $this->donnees = '';
        $this->miniFlux = '';
        $this->grilles = [];
        $this->chaines = [];
    }

    // ---------------------------------------------------------------- conteneur OLE2

    private function ouvrirLeConteneur(): void
    {
        $this->tailleSecteur = 1 << $this->u16(30);
        $this->tailleMiniSecteur = 1 << $this->u16(32);
        $this->seuilMini = $this->u32(56);

        $nbFat = $this->u32(44);
        $debutRepertoire = $this->u32(48);
        $debutMiniFat = $this->u32(60);
        $debutDifat = $this->u32(68);
        $nbDifat = $this->u32(72);

        // La DIFAT dit où sont les secteurs de la FAT. Les 109 premiers tiennent dans
        // l'en-tête ; au-delà, elle se poursuit en chaîne dans le fichier.
        $secteursFat = [];

        for ($i = 0; $i < 109; $i++) {
            $secteursFat[] = $this->u32(76 + $i * 4);
        }

        $secteur = $debutDifat;

        for ($i = 0; $i < $nbDifat && $secteur < 0xFFFFFFFE; $i++) {
            $bloc = $this->secteur($secteur);
            $parBloc = intdiv($this->tailleSecteur, 4) - 1;

            for ($j = 0; $j < $parBloc; $j++) {
                $secteursFat[] = unpack('V', substr($bloc, $j * 4, 4))[1];
            }

            $secteur = unpack('V', substr($bloc, $this->tailleSecteur - 4, 4))[1];
        }

        $secteursFat = array_slice(array_filter($secteursFat, fn ($s) => $s < 0xFFFFFFF0), 0, max($nbFat, 1) * 1000);

        foreach ($secteursFat as $fs) {
            $bloc = $this->secteur($fs);

            foreach (unpack('V*', $bloc) as $entree) {
                $this->fat[] = $entree;
            }
        }

        $entrees = $this->repertoire($this->chaine($debutRepertoire));

        // L'entrée racine sert de conteneur aux petits flux : ils ne vivent pas dans des
        // secteurs à eux mais découpés dans celui-là.
        if ($debutMiniFat < 0xFFFFFFFE) {
            foreach (unpack('V*', $this->chaine($debutMiniFat)) as $entree) {
                $this->miniFat[] = $entree;
            }
        }

        $racine = $entrees[0] ?? null;

        if ($racine !== null) {
            $this->miniFlux = $this->chaine($racine['secteur']);
        }

        $this->entrees = $entrees;
    }

    /** @var list<array{nom: string, type: int, secteur: int, taille: int}> */
    private array $entrees = [];

    /** @return list<array{nom: string, type: int, secteur: int, taille: int}> */
    private function repertoire(string $donnees): array
    {
        $entrees = [];

        for ($p = 0; $p + 128 <= strlen($donnees); $p += 128) {
            $longueur = unpack('v', substr($donnees, $p + 64, 2))[1];
            $type = ord($donnees[$p + 66]);

            if ($type === 0) {
                continue;
            }

            $nom = $longueur > 2
                ? (string) mb_convert_encoding(substr($donnees, $p, $longueur - 2), 'UTF-8', 'UTF-16LE')
                : '';

            $entrees[] = [
                'nom' => $nom,
                'type' => $type,
                'secteur' => unpack('V', substr($donnees, $p + 116, 4))[1],
                'taille' => unpack('V', substr($donnees, $p + 120, 4))[1],
            ];
        }

        return $entrees;
    }

    private function flux(string $nom): ?string
    {
        foreach ($this->entrees as $entree) {
            if ($entree['nom'] !== $nom || $entree['type'] !== 2) {
                continue;
            }

            $contenu = $entree['taille'] < $this->seuilMini
                ? $this->chaineMini($entree['secteur'])
                : $this->chaine($entree['secteur']);

            return substr($contenu, 0, $entree['taille']);
        }

        return null;
    }

    private function secteur(int $index): string
    {
        return substr($this->donnees, 512 + $index * $this->tailleSecteur, $this->tailleSecteur);
    }

    private function chaine(int $depart): string
    {
        $morceaux = [];
        $vus = [];
        $s = $depart;

        while ($s < 0xFFFFFFFE && ! isset($vus[$s])) {
            $vus[$s] = true;
            $morceaux[] = $this->secteur($s);
            $s = $this->fat[$s] ?? 0xFFFFFFFE;
        }

        return implode('', $morceaux);
    }

    private function chaineMini(int $depart): string
    {
        $morceaux = [];
        $vus = [];
        $s = $depart;

        while ($s < 0xFFFFFFFE && ! isset($vus[$s])) {
            $vus[$s] = true;
            $morceaux[] = substr($this->miniFlux, $s * $this->tailleMiniSecteur, $this->tailleMiniSecteur);
            $s = $this->miniFat[$s] ?? 0xFFFFFFFE;
        }

        return implode('', $morceaux);
    }

    private function u16(int $offset): int
    {
        return unpack('v', substr($this->donnees, $offset, 2))[1];
    }

    private function u32(int $offset): int
    {
        return unpack('V', substr($this->donnees, $offset, 4))[1];
    }

    // ------------------------------------------------------------------- flux BIFF8

    private function lireLeClasseur(): void
    {
        if ($this->luEnEntier) {
            return;
        }

        $this->luEnEntier = true;

        $flux = $this->flux('Workbook') ?? $this->flux('Book');

        if ($flux === null) {
            throw new RuntimeException("Le classeur n'a pas de feuille lisible.");
        }

        $enregistrements = $this->enregistrements($flux);

        $this->lireLeDictionnaire($enregistrements);
        $this->lireLesFormats($enregistrements);
        $this->lireLesCellules($enregistrements);
    }

    /** @return list<array{0: int, 1: string}> */
    private function enregistrements(string $flux): array
    {
        $liste = [];
        $p = 0;
        $n = strlen($flux);

        while ($p + 4 <= $n) {
            $entete = unpack('vtype/vlongueur', substr($flux, $p, 4));
            $liste[] = [$entete['type'], substr($flux, $p + 4, $entete['longueur'])];
            $p += 4 + $entete['longueur'];
        }

        return $liste;
    }

    /**
     * Le dictionnaire des chaînes, en respectant les frontières de blocs.
     *
     * C'est ici que se joue la correction décrite en tête de classe : les blocs sont
     * passés tels quels au flux de lecture, qui sait qu'un changement de bloc au milieu
     * d'une chaîne s'accompagne d'un nouvel octet de drapeau.
     */
    private function lireLeDictionnaire(array $enregistrements): void
    {
        foreach ($enregistrements as $index => [$type, $donnees]) {
            if ($type !== 0x00FC) {
                continue;
            }

            $blocs = [substr($donnees, 8)];
            $total = unpack('Vunique', substr($donnees, 4, 4))['unique'];

            for ($j = $index + 1; $j < count($enregistrements) && $enregistrements[$j][0] === 0x003C; $j++) {
                $blocs[] = $enregistrements[$j][1];
            }

            $flux = new FluxDeChaines($blocs);

            for ($i = 0; $i < $total && ! $flux->epuise(); $i++) {
                $this->chaines[] = $flux->chaine();
            }

            break;
        }
    }

    /** Les formats d'affichage, et quel style porte lequel. */
    private function lireLesFormats(array $enregistrements): void
    {
        foreach ($enregistrements as [$type, $donnees]) {
            if ($type === 0x041E && strlen($donnees) >= 3) {
                $code = unpack('v', substr($donnees, 0, 2))[1];
                $this->motifs[$code] = self::chaineCourte($donnees, 2);
            } elseif ($type === 0x00E0 && strlen($donnees) >= 4) {
                // Les XF se suivent dans l'ordre ; l'index d'une cellule pointe dans cette
                // même suite, styles et cellules confondus.
                $this->formatsDesStyles[] = unpack('v', substr($donnees, 2, 2))[1];
            }
        }
    }

    private function lireLesCellules(array $enregistrements): void
    {
        $rang = -1;
        $nomsBoundsheet = [];
        $enTete = true;
        $derniereFormule = null;

        foreach ($enregistrements as [$type, $donnees]) {
            // BOUNDSHEET, dans le sous-flux d'en-tête : les noms des feuilles, en ordre.
            if ($type === 0x0085 && $enTete && strlen($donnees) >= 8) {
                $nomsBoundsheet[] = self::chaineCourte($donnees, 6, true);

                continue;
            }

            if ($type === 0x0809) {
                $nature = strlen($donnees) >= 4 ? unpack('v', substr($donnees, 2, 2))[1] : 0;

                if ($nature === 0x0010) {
                    $enTete = false;
                    $rang++;
                    $this->grilles[$rang] = [];
                }

                continue;
            }

            if ($rang < 0) {
                continue;
            }

            switch ($type) {
                case 0x00FD: // LABELSST — texte pris dans le dictionnaire
                    if (strlen($donnees) >= 10) {
                        [$l, $c, $xf] = self::adresse($donnees);
                        $index = unpack('V', substr($donnees, 6, 4))[1];
                        $this->poser($rang, $l, $c, $this->chaines[$index] ?? null);
                    }
                    break;

                case 0x0204: // LABEL — texte écrit dans la cellule
                case 0x00D6: // RSTRING — idem, avec de la mise en forme
                    if (strlen($donnees) >= 8) {
                        [$l, $c] = self::adresse($donnees);
                        $this->poser($rang, $l, $c, self::chaineLongue($donnees, 6));
                    }
                    break;

                case 0x0203: // NUMBER
                    if (strlen($donnees) >= 14) {
                        [$l, $c, $xf] = self::adresse($donnees);
                        $valeur = unpack('e', substr($donnees, 6, 8))[1];
                        $this->poser($rang, $l, $c, $this->selonLeFormat($valeur, $xf));
                    }
                    break;

                case 0x027E: // RK — un nombre comprimé sur quatre octets
                    if (strlen($donnees) >= 10) {
                        [$l, $c, $xf] = self::adresse($donnees);
                        $valeur = self::nombreRk(unpack('V', substr($donnees, 6, 4))[1]);
                        $this->poser($rang, $l, $c, $this->selonLeFormat($valeur, $xf));
                    }
                    break;

                case 0x00BD: // MULRK — plusieurs RK d'affilée sur la même ligne
                    if (strlen($donnees) >= 6) {
                        $l = unpack('v', substr($donnees, 0, 2))[1];
                        $c0 = unpack('v', substr($donnees, 2, 2))[1];
                        $combien = intdiv(strlen($donnees) - 6, 6);

                        for ($k = 0; $k < $combien; $k++) {
                            $base = 4 + $k * 6;
                            $xf = unpack('v', substr($donnees, $base, 2))[1];
                            $valeur = self::nombreRk(unpack('V', substr($donnees, $base + 2, 4))[1]);
                            $this->poser($rang, $l, $c0 + $k, $this->selonLeFormat($valeur, $xf));
                        }
                    }
                    break;

                case 0x0006: // FORMULA — on lit le résultat en cache, jamais la formule
                    if (strlen($donnees) >= 20) {
                        [$l, $c, $xf] = self::adresse($donnees);
                        $cache = substr($donnees, 6, 8);

                        if (substr($cache, 6, 2) === "\xFF\xFF") {
                            // Résultat non numérique : un texte suit dans un enregistrement
                            // STRING, un booléen ou une erreur tiennent dans le cache.
                            $derniereFormule = ord($cache[0]) === 0 ? [$l, $c] : null;

                            if (ord($cache[0]) === 1) {
                                $this->poser($rang, $l, $c, ord($cache[2]) ? 'VRAI' : 'FAUX');
                            }
                        } else {
                            $valeur = unpack('e', $cache)[1];
                            $this->poser($rang, $l, $c, $this->selonLeFormat($valeur, $xf));
                        }
                    }
                    break;

                case 0x0207: // STRING — le texte que la formule précédente a produit
                    if ($derniereFormule !== null && strlen($donnees) >= 3) {
                        [$l, $c] = $derniereFormule;
                        $this->poser($rang, $l, $c, self::chaineLongue($donnees, 0));
                        $derniereFormule = null;
                    }
                    break;

                case 0x0205: // BOOLERR
                    if (strlen($donnees) >= 8) {
                        [$l, $c] = self::adresse($donnees);
                        $this->poser($rang, $l, $c, ord($donnees[7]) ? null : (ord($donnees[6]) ? 'VRAI' : 'FAUX'));
                    }
                    break;
            }
        }

        foreach ($nomsBoundsheet as $index => $nom) {
            $this->feuilles[$nom !== '' ? $nom : 'Feuille'.($index + 1)] = $index;
        }

        // Un classeur dont les noms n'ont pas été lus reste exploitable par son rang.
        foreach (array_keys($this->grilles) as $index) {
            if (! in_array($index, $this->feuilles, true)) {
                $this->feuilles['Feuille'.($index + 1)] = $index;
            }
        }
    }

    private function poser(int $rang, int $ligne, int $colonne, string|float|DateTimeImmutable|null $valeur): void
    {
        if ($valeur === null || $valeur === '') {
            return;
        }

        if (is_string($valeur)) {
            $valeur = trim($valeur);

            if ($valeur === '') {
                return;
            }
        }

        $this->grilles[$rang][$ligne][$colonne] = $valeur;
    }

    /** Un nombre, ou la date qu'il désigne si son style l'affiche comme telle. */
    private function selonLeFormat(float $valeur, int $xf): string|float|DateTimeImmutable
    {
        $code = $this->formatsDesStyles[$xf] ?? 0;

        if (LecteurXlsx::formatEstUneDate($code, $this->motifs[$code] ?? null)) {
            return LecteurXlsx::dateDeSerie($valeur) ?? $valeur;
        }

        return $valeur;
    }

    /** @return array{0: int, 1: int, 2: int} ligne, colonne, index de style */
    private static function adresse(string $donnees): array
    {
        $champs = unpack('vligne/vcolonne/vxf', substr($donnees, 0, 6));

        return [$champs['ligne'], $champs['colonne'], $champs['xf']];
    }

    /**
     * Le nombre qu'encode un RK.
     *
     * Quatre octets au lieu de huit : le bit 1 dit que la valeur est un entier plutôt
     * qu'un flottant tronqué, le bit 0 qu'il faut encore la diviser par cent. C'est ainsi
     * que le tableur range les montants sans gaspiller de place.
     */
    public static function nombreRk(int $brut): float
    {
        if ($brut & 2) {
            $entier = $brut >> 2;

            // Le champ est signé sur 30 bits : au-delà de la moitié, on repasse en négatif.
            $valeur = (float) ($entier & 0x20000000 ? $entier - 0x40000000 : $entier);
        } else {
            $valeur = unpack('e', pack('VV', 0, $brut & 0xFFFFFFFC))[1];
        }

        return $brut & 1 ? $valeur / 100 : $valeur;
    }

    /** Chaîne dont la longueur tient sur un octet — les noms de feuilles. */
    private static function chaineCourte(string $donnees, int $offset, bool $unOctet = false): string
    {
        if ($unOctet) {
            $longueur = ord($donnees[$offset]);
            $drapeau = ord($donnees[$offset + 1]);
            $debut = $offset + 2;
        } else {
            $longueur = unpack('v', substr($donnees, $offset, 2))[1];
            $drapeau = ord($donnees[$offset + 2] ?? "\0");
            $debut = $offset + 3;
        }

        return self::decoder($donnees, $debut, $longueur, ($drapeau & 1) !== 0);
    }

    /** Chaîne dont la longueur tient sur deux octets — le contenu des cellules. */
    private static function chaineLongue(string $donnees, int $offset): string
    {
        if (strlen($donnees) < $offset + 3) {
            return '';
        }

        $longueur = unpack('v', substr($donnees, $offset, 2))[1];
        $drapeau = ord($donnees[$offset + 2]);
        $debut = $offset + 3;

        if ($drapeau & 8) {
            $debut += 2;
        }

        if ($drapeau & 4) {
            $debut += 4;
        }

        return self::decoder($donnees, $debut, $longueur, ($drapeau & 1) !== 0);
    }

    private static function decoder(string $donnees, int $debut, int $longueur, bool $seize): string
    {
        $brut = substr($donnees, $debut, $longueur * ($seize ? 2 : 1));

        return (string) mb_convert_encoding($brut, 'UTF-8', $seize ? 'UTF-16LE' : 'Windows-1252');
    }
}

<?php

namespace Modules\Noyau\Commun\Services;

/**
 * Écrit un PDF, sans rien installer.
 *
 * Le besoin est celui d'un annuaire : un titre, des sections, des tableaux de texte.
 * Une bibliothèque de mise en page complète (dompdf, mPDF) apporterait un moteur HTML,
 * des polices embarquées et une quinzaine de paquets — pour un document qui n'en
 * demande aucun. Or l'hébergement de production se met à jour par un `git pull`, et
 * `vendor/` n'y est pas versionné : toute dépendance nouvelle devient une manipulation
 * de plus à réussir sur le serveur, le jour où l'on veut simplement imprimer une liste.
 *
 * Ce format se prête à l'écriture directe. Les quatorze polices « standard » — dont
 * Helvetica — sont présentes dans tout lecteur, il n'y a donc rien à embarquer, et le
 * texte se pose en coordonnées absolues. On garde ainsi un fichier que n'importe quel
 * lecteur ouvre, y compris hors ligne.
 *
 * Le texte est converti en Windows-1252, l'encodage que déclare /WinAnsiEncoding : il
 * couvre les accents français. Ce qu'il ne couvre pas est translittéré plutôt que rendu
 * illisible — un nom mal orthographié vaut mieux qu'un carré noir.
 */
class DocumentPdf
{
    /** A4 portrait, en points typographiques (72 par pouce). */
    public const LARGEUR = 595.28;

    public const HAUTEUR = 841.89;

    private const MARGE = 42.0;

    private const BAS_DE_PAGE = 56.0;

    /** Largeur et hauteur réelles de la page, orientation appliquée. */
    private float $largeur;

    private float $hauteur;

    /**
     * Les images à embarquer, dans l'ordre où elles ont été posées.
     *
     * @var array<int, array{donnees: string, largeur: int, hauteur: int}>
     */
    private array $images = [];

    /** @var array<int, string> flux de contenu déjà clos, un par page */
    private array $pages = [];

    private string $contenu = '';

    private float $y;

    /** Rappelé en haut de chaque nouvelle page : sans lui, un tableau qui déborde perd son titre. */
    private ?\Closure $enTeteDePage = null;

    /**
     * @param  bool  $paysage  A4 couché — pour les tableaux larges
     *
     * **Pourquoi l'orientation est un choix et non un réglage de confort.** Un tableau de
     * dix colonnes sur une page debout laisse moins de cinquante points par colonne :
     * « 697 027 » n'y tient pas, il sort tronqué avec des points de suspension, et c'est
     * exactement ce qu'on ne peut pas accepter d'un montant. Couché, la même page offre
     * huit cents points utiles. L'orientation suit donc le tableau, pas l'habitude.
     */
    public function __construct(private string $titre = 'Document', private bool $paysage = false)
    {
        $this->largeur = $paysage ? self::HAUTEUR : self::LARGEUR;
        $this->hauteur = $paysage ? self::LARGEUR : self::HAUTEUR;
        $this->y = $this->hauteur - self::MARGE;
    }

    /** La largeur utile d'une page, pour qui doit répartir des colonnes dessus. */
    public function largeurDisponible(): float
    {
        return $this->largeur - 2 * self::MARGE;
    }

    /* ------------------------------------------------------------------ Texte */

    /** Titre principal du document. */
    public function titre(string $texte): self
    {
        $this->reserver(30);
        $this->texte($texte, self::MARGE, 17, true, '#191B20');
        $this->y -= 22;

        return $this;
    }

    public function sousTitre(string $texte): self
    {
        $this->reserver(18);
        $this->texte($texte, self::MARGE, 9.5, false, '#6B6E76');
        $this->y -= 16;

        return $this;
    }

    /** Intertitre d'une section, souligné sur toute la largeur utile. */
    public function section(string $texte): self
    {
        // Un intertitre seul en bas de page annonce un tableau qui commence à la
        // suivante : on réserve de quoi poser au moins l'en-tête et une ligne.
        $this->reserver(62);
        $this->y -= 8;
        $this->texte($texte, self::MARGE, 11.5, true, '#C8102E');
        $this->y -= 5;
        $this->trait($this->y, '#C8102E', 0.8);
        $this->y -= 12;

        return $this;
    }

    public function paragraphe(string $texte, float $taille = 9.5): self
    {
        foreach ($this->couper($texte, $taille, false, $this->largeurUtile()) as $ligne) {
            $this->reserver($taille + 4);
            $this->texte($ligne, self::MARGE, $taille, false, '#4B4E55');
            $this->y -= $taille + 3;
        }

        $this->y -= 5;

        return $this;
    }

    /* ------------------------------------------------------- En-tete du document */

    /**
     * Le bandeau d'en-tête : logo, maison, titre, message.
     *
     * **Ce qui manquait.** Les documents sortaient avec un titre et deux lignes grises.
     * Reçus par un assureur, ils ne disaient pas d'où ils venaient — ni la maison qui les
     * émet, ni sa marque. Un état de créance sans en-tête se classe mal et se conteste
     * facilement&nbsp;: on ne sait pas qui réclame.
     *
     * Le bloc est **encadré** et posé sur un aplat clair, avec un filet rouge à gauche :
     * c'est la même grammaire que les cartes de l'application, et cela sépare d'un regard
     * l'identification du contenu.
     *
     * @param  array{maison?: string, titre?: string, message?: string, mention?: string, logo?: ?string}  $infos
     */
    public function enTete(array $infos): self
    {
        $hauteur = 72.0;
        $gauche = self::MARGE;
        $haut = $this->y;
        $bas = $haut - $hauteur + 14;

        // Le fond, puis le cadre, puis le filet rouge : dans cet ordre, sans quoi l'aplat
        // recouvrirait le trait qu'on vient de poser.
        $this->contenu .= sprintf(
            "%s\n%.2f %.2f %.2f %.2f re f\n",
            $this->opCouleur('#F7F5EF', 'rg'), $gauche, $bas, $this->largeurUtile(), $hauteur,
        );
        $this->contenu .= sprintf(
            "%s\n0.7 w %.2f %.2f %.2f %.2f re S\n",
            $this->opCouleur('#D9D5CA', 'RG'), $gauche, $bas, $this->largeurUtile(), $hauteur,
        );
        $this->contenu .= sprintf(
            "%s\n%.2f %.2f %.2f %.2f re f\n",
            $this->opCouleur('#C8102E', 'rg'), $gauche, $bas, 3.5, $hauteur,
        );

        // Le logo, à gauche, dans une hauteur bornée : une marque doit être visible, pas
        // envahissante, et surtout elle ne doit jamais être déformée.
        $x = $gauche + 14;

        if (! empty($infos['logo'])) {
            $pose = $this->image($infos['logo'], $x, $bas + 16, 48.0);

            if ($pose > 0.0) {
                $x += $pose + 16;
            }
        }

        $this->y = $haut + $hauteur - 76;

        if (! empty($infos['maison'])) {
            $this->texte($infos['maison'], $x, 10, true, '#C8102E');
            $this->y -= 17;
        }

        $this->texte((string) ($infos['titre'] ?? $this->titre), $x, 17, true, '#191B20');
        $this->y -= 15;

        if (! empty($infos['message'])) {
            foreach (array_slice($this->couper((string) $infos['message'], 9, false,
                $this->largeurUtile() - ($x - $gauche) - 20), 0, 2) as $bout) {
                $this->texte($bout, $x, 9, false, '#5A6472');
                $this->y -= 11;
            }
        }

        if (! empty($infos['mention'])) {
            $this->texte((string) $infos['mention'], $x, 9, true, '#25272D');
        }

        // On repart sous le cadre, quel que soit ce qu'on a écrit dedans.
        $this->y = $bas - 18;

        return $this;
    }

    /**
     * Un en-tête à deux marques : un logo à chaque extrémité, le titre au milieu.
     *
     * **Pourquoi celui-ci existe à côté de l'autre.** {@see enTete()} sert les documents
     * qu'une maison adresse à ses clients : une marque à gauche, tout le texte à sa suite.
     * Un courrier écrit par un cabinet pour le compte d'une entreprise en met deux en jeu,
     * et les tasser du même côté produit un bandeau touffu où l'œil ne sait plus qui écrit
     * à qui. Les marques prennent donc chacune un bord, le titre tient le milieu, et ce
     * qu'il faut lire ensuite — destinataire, objet, lieu et date — descend sous le cadre,
     * là où on le cherche dans une lettre.
     *
     * @param  array{titre?: string, sousTitre?: string, logoGauche?: ?string, logoDroite?: ?string}  $infos
     */
    public function enTeteADeuxMarques(array $infos): self
    {
        $hauteur = 62.0;
        $gauche = self::MARGE;
        $haut = $this->y;
        $bas = $haut - $hauteur + 14;

        $this->contenu .= sprintf(
            "%s\n%.2f %.2f %.2f %.2f re f\n",
            $this->opCouleur('#F7F5EF', 'rg'), $gauche, $bas, $this->largeurUtile(), $hauteur,
        );
        $this->contenu .= sprintf(
            "%s\n0.7 w %.2f %.2f %.2f %.2f re S\n",
            $this->opCouleur('#D9D5CA', 'RG'), $gauche, $bas, $this->largeurUtile(), $hauteur,
        );

        // Les deux logos d'abord : le titre se centre sur ce qu'ils laissent entre eux.
        $hauteurLogo = 34.0;
        $milieuLogo = $bas + ($hauteur - $hauteurLogo) / 2;
        $prisGauche = 0.0;
        $prisDroite = 0.0;

        if (! empty($infos['logoGauche'])) {
            $prisGauche = $this->image((string) $infos['logoGauche'], $gauche + 12, $milieuLogo, $hauteurLogo);
        }

        if (! empty($infos['logoDroite'])) {
            // Posé par son bord droit : on mesure d'abord ce qu'il occupera, faute de quoi
            // un logo large sortirait du cadre par la droite.
            $largeur = $this->mesurerImage((string) $infos['logoDroite'], $hauteurLogo);

            if ($largeur > 0.0) {
                $prisDroite = $this->image(
                    (string) $infos['logoDroite'],
                    $gauche + $this->largeurUtile() - 12 - $largeur,
                    $milieuLogo,
                    $hauteurLogo,
                );
            }
        }

        $depart = $gauche + 12 + $prisGauche + 14;
        $fin = $gauche + $this->largeurUtile() - 12 - $prisDroite - 14;
        $place = max(80.0, $fin - $depart);

        $titre = $this->tronquer((string) ($infos['titre'] ?? $this->titre), 14, true, $place);
        $this->y = $bas + $hauteur - (empty($infos['sousTitre']) ? 36 : 30);
        $this->texte($titre, $depart + ($place - $this->largeurDe($titre, 14, true)) / 2, 14, true, '#191B20');

        if (! empty($infos['sousTitre'])) {
            $this->y -= 15;
            $sous = $this->tronquer((string) $infos['sousTitre'], 9, false, $place);
            $this->texte($sous, $depart + ($place - $this->largeurDe($sous, 9, false)) / 2, 9, false, '#5A6472');
        }

        $this->y = $bas - 20;

        return $this;
    }

    /** La largeur qu'occupera une image posée à cette hauteur — sans l'embarquer. */
    private function mesurerImage(string $chemin, float $hauteurVoulue): float
    {
        $taille = is_file($chemin) ? @getimagesize($chemin) : false;

        return $taille === false || (int) $taille[1] < 1
            ? 0.0
            : $hauteurVoulue * (int) $taille[0] / (int) $taille[1];
    }

    /**
     * Embarque une image et la dessine. Rend la largeur occupée, en points.
     *
     * **Comment elle est embarquée.** Le fichier est relu par GD, aplati sur du blanc —
     * un logo transparent posé sans fond ressortirait sur du noir chez certains lecteurs —
     * puis écrit en pixels bruts comprimés. C'est le procédé le plus simple qui marche
     * partout : pas de décodeur PNG à réécrire, pas de perte de qualité, et aucune
     * bibliothèque de plus.
     *
     * Une image illisible ne fait pas échouer le document : elle est simplement absente.
     * Un état de créance qu'on ne peut pas éditer parce qu'un logo a été supprimé serait
     * une panne inventée.
     */
    private function image(string $chemin, float $x, float $y, float $hauteurVoulue): float
    {
        if (! is_file($chemin) || ! function_exists('imagecreatefromstring')) {
            return 0.0;
        }

        $source = @imagecreatefromstring((string) file_get_contents($chemin));

        if ($source === false) {
            return 0.0;
        }

        $l = imagesx($source);
        $h = imagesy($source);

        if ($l < 1 || $h < 1) {
            imagedestroy($source);

            return 0.0;
        }

        // Aplati sur blanc : la transparence n'existe pas dans le flux qu'on écrit, et
        // sans ce fond les parties transparentes sortiraient en noir.
        $plat = imagecreatetruecolor($l, $h);
        imagefill($plat, 0, 0, imagecolorallocate($plat, 255, 255, 255));
        imagealphablending($plat, true);
        imagecopy($plat, $source, 0, 0, 0, 0, $l, $h);
        imagedestroy($source);

        $pixels = '';

        for ($ligne = 0; $ligne < $h; $ligne++) {
            for ($colonne = 0; $colonne < $l; $colonne++) {
                $couleur = imagecolorat($plat, $colonne, $ligne);
                $pixels .= chr(($couleur >> 16) & 0xFF).chr(($couleur >> 8) & 0xFF).chr($couleur & 0xFF);
            }
        }

        imagedestroy($plat);

        $this->images[] = [
            'donnees' => (string) gzcompress($pixels, 6),
            'largeur' => $l,
            'hauteur' => $h,
        ];

        $rang = count($this->images);
        $largeurVoulue = $hauteurVoulue * $l / $h;

        // `cm` pose la matrice de placement : largeur, hauteur, position. Encadré par
        // q/Q pour que le reste de la page n'en hérite pas.
        $this->contenu .= sprintf(
            "q %.2f 0 0 %.2f %.2f %.2f cm /Im%d Do Q\n",
            $largeurVoulue, $hauteurVoulue, $x, $y, $rang,
        );

        return $largeurVoulue;
    }

    /* --------------------------------------------------------------- Tableaux */

    /**
     * Un tableau à en-tête répété.
     *
     * @param  array<int, string>  $colonnes  intitulés
     * @param  array<int, float>  $largeurs  en points, additionnées à la largeur utile
     * @param  array<int, array<int, string>>  $lignes
     */
    public function tableau(array $colonnes, array $largeurs, array $lignes, array $options = []): self
    {
        // Les colonnes de montants s'alignent par la droite. Sans cela, les unités et les
        // milliers ne tombent pas sur la même verticale, et une colonne de nombres cesse
        // d'être comparable d'un coup d'œil — ce qui est pourtant sa seule raison d'être.
        $alignements = $options['alignements'] ?? [];
        $etiquettes = $options['etiquettes'] ?? [];
        $total = $options['total'] ?? null;

        $enTete = function () use ($colonnes, $largeurs, $alignements) {
            // L'en-tête est posé sur un bandeau noir, comme à l'écran : c'est ce qui
            // sépare les intitulés des données sans avoir à les lire.
            $this->bandeau('#191B20', 15);
            $this->ligneDeTableau($colonnes, $largeurs, true, '#FFFFFF', $alignements);
            $this->y -= 3;
        };

        // Mémorisé pour être redessiné en haut de chaque page suivante : une page de
        // noms sans ses intitulés de colonnes ne se lit plus.
        $this->enTeteDePage = $enTete;
        $this->reserver(34);
        $enTete();

        foreach ($lignes as $ligne) {
            if ($options['multiligne'] ?? false) {
                $this->ligneQuiRevientALaLigne($ligne, $largeurs, $options['gras'] ?? [], $options['taille'] ?? 8.6);

                continue;
            }

            $this->reserver(16);
            $this->ligneDeTableau($ligne, $largeurs, false, '#25272D', $alignements, $etiquettes);
            $this->trait($this->y + 11, '#EBE9E2', 0.4);
        }

        if ($total !== null) {
            // Le total ne se sépare pas de son tableau : on lui réserve la place d'une
            // ligne entière, faute de quoi il pourrait ouvrir seul la page suivante.
            $this->reserver(26);
            $this->bandeau('#191B20', 16);
            $this->ligneDeTableau($total, $largeurs, true, '#FFFFFF', $alignements);
        }

        $this->enTeteDePage = null;
        $this->y -= 10;

        return $this;
    }

    /** Un aplat de couleur derrière la ligne qu'on s'apprête à écrire. */
    private function bandeau(string $couleur, float $hauteur): void
    {
        $this->contenu .= sprintf(
            "%s\n%.2f %.2f %.2f %.2f re f\n",
            $this->opCouleur($couleur, 'rg'),
            self::MARGE - 3,
            $this->y - 4,
            $this->largeurUtile() + 6,
            $hauteur,
        );
    }

    /**
     * @param  array<int, string>  $cellules
     * @param  array<int, float>  $largeurs
     */
    /**
     * @param  array<int, string>  $cellules
     * @param  array<int, float>  $largeurs
     * @param  array<int, string>  $alignements  'droite' pour les colonnes de montants
     * @param  array<int, array<string, array{fond: string, texte: string}>>  $etiquettes
     */
    private function ligneDeTableau(
        array $cellules,
        array $largeurs,
        bool $gras,
        string $couleur,
        array $alignements = [],
        array $etiquettes = [],
    ): void {
        $x = self::MARGE;
        $taille = $gras ? 9 : 9.5;

        foreach ($cellules as $i => $cellule) {
            $largeur = $largeurs[$i] ?? 100;
            // La troncature vaut mieux qu'un débordement : deux colonnes qui se
            // chevauchent rendent les deux illisibles, une seule tronquée reste lue.
            $texte = $this->tronquer((string) $cellule, $taille, $gras, $largeur - 6);

            $teinte = $etiquettes[$i][$texte] ?? null;

            if ($teinte !== null) {
                // Une pastille : le fond dit le niveau avant qu'on ait lu le mot, comme
                // la pastille rouge « N5 · Contentieux » de l'écran.
                $this->pastille($texte, $x, $taille, $teinte['fond'], $teinte['texte']);
                $x += $largeur;

                continue;
            }

            $depart = ($alignements[$i] ?? 'gauche') === 'droite'
                ? $x + $largeur - 6 - $this->largeurDe($texte, $taille, $gras)
                : $x;

            $this->texte($texte, $depart, $taille, $gras, $couleur);
            $x += $largeur;
        }

        $this->y -= 15;
    }

    /**
     * Une ligne dont les cellules reviennent à la ligne au lieu d'être coupées.
     *
     * **Pourquoi elle existe.** La ligne ordinaire tronque : entre deux colonnes qui se
     * chevauchent et une seule raccourcie, le raccourci est le moindre mal, et pour un
     * tableau de montants c'est le bon choix. Mais un tableau dont une colonne porte une
     * phrase — une demande, une observation — perd alors précisément ce qu'on voulait
     * dire : la phrase sort avec des points de suspension, et le lecteur ne saura jamais
     * ce qu'il y avait après. Ici la cellule s'étale donc sur autant de lignes qu'il en
     * faut, et c'est la hauteur de la rangée qui cède, pas le texte.
     *
     * @param  array<int, string>  $cellules
     * @param  array<int, float>  $largeurs
     * @param  array<int, int>  $gras  rangs des colonnes à écrire en gras
     */
    private function ligneQuiRevientALaLigne(array $cellules, array $largeurs, array $gras, float $taille): void
    {
        $blocs = [];
        $hauteur = 1;

        foreach (array_values($cellules) as $rang => $cellule) {
            $blocs[$rang] = $this->couper(
                (string) $cellule, $taille, in_array($rang, $gras, true), ($largeurs[$rang] ?? 100) - 8,
            );
            $hauteur = max($hauteur, count($blocs[$rang]));
        }

        $interligne = $taille + 2.6;

        // La rangée entière tient sur une page ou passe à la suivante : la couper en deux
        // rendrait une phrase orpheline de sa colonne de gauche.
        $this->reserver($hauteur * $interligne + 8);
        $sommet = $this->y;

        foreach ($blocs as $rang => $lignes) {
            $x = self::MARGE + array_sum(array_slice($largeurs, 0, $rang));
            $this->y = $sommet;

            foreach ($lignes as $ligne) {
                $this->texte($ligne, $x, $taille, in_array($rang, $gras, true), '#25272D');
                $this->y -= $interligne;
            }
        }

        $this->y = $sommet - $hauteur * $interligne - 3;
        $this->trait($this->y + 7, '#EBE9E2', 0.4);
    }

    /** Un libellé sur fond plein, arrondi par le seul effet de la marge. */
    private function pastille(string $texte, float $x, float $taille, string $fond, string $encre): void
    {
        $largeur = $this->largeurDe($texte, $taille, true) + 8;

        $this->contenu .= sprintf(
            "%s\n%.2f %.2f %.2f %.2f re f\n",
            $this->opCouleur($fond, 'rg'), $x - 2, $this->y - 3, $largeur, 13.0,
        );

        $this->texte($texte, $x + 2, $taille, true, $encre);
    }

    /* ----------------------------------------------------------------- Rendu */

    /** Le fichier complet, prêt à être renvoyé au navigateur. */
    public function rendu(): string
    {
        $this->numeroterEtClore();

        $objets = [];

        // 1 catalogue, 2 arbre des pages, 3 et 4 les polices : numéros fixes, pour que
        // les pages puissent y renvoyer avant même d'exister.
        $premierePage = 5;
        $refsPages = [];

        foreach (array_keys($this->pages) as $i) {
            $refsPages[] = ($premierePage + $i * 2).' 0 R';
        }

        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objets[2] = '<< /Type /Pages /Kids ['.implode(' ', $refsPages).'] /Count '.count($this->pages).' >>';
        $objets[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objets[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        // Les images viennent après les pages : elles sont déclarées dans les ressources
        // de chaque page, et le renvoi doit donc être connu avant de les écrire.
        $premiereImage = $premierePage + count($this->pages) * 2;
        $ressourceImages = '';

        foreach (array_keys($this->images) as $rang) {
            $ressourceImages .= '/Im'.($rang + 1).' '.($premiereImage + $rang).' 0 R ';
        }

        $xobjets = $ressourceImages === '' ? '' : '/XObject << '.$ressourceImages.'>> ';

        foreach ($this->pages as $i => $flux) {
            $numeroPage = $premierePage + $i * 2;
            $numeroFlux = $numeroPage + 1;

            $objets[$numeroPage] = '<< /Type /Page /Parent 2 0 R '
                .'/MediaBox [0 0 '.round($this->largeur, 2).' '.round($this->hauteur, 2).'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> '.$xobjets.'>> '
                .'/Contents '.$numeroFlux.' 0 R >>';

            $objets[$numeroFlux] = '<< /Length '.strlen($flux).' >>'."\nstream\n".$flux."\nendstream";
        }

        foreach ($this->images as $rang => $image) {
            $objets[$premiereImage + $rang] = '<< /Type /XObject /Subtype /Image '
                .'/Width '.$image['largeur'].' /Height '.$image['hauteur'].' '
                .'/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode '
                .'/Length '.strlen($image['donnees']).' >>'
                ."\nstream\n".$image['donnees']."\nendstream";
        }

        $numeroInfo = max(array_keys($objets)) + 1;
        $objets[$numeroInfo] = '<< /Title ('.$this->echapper($this->titre).') '
            .'/Producer (Gestion de sites) '
            .'/CreationDate (D:'.now()->format('YmdHis').'+00\'00\') >>';

        return $this->assembler($objets, $numeroInfo);
    }

    /**
     * @param  array<int, string>  $objets
     */
    private function assembler(array $objets, int $numeroInfo): string
    {
        ksort($objets);

        $pdf = "%PDF-1.4\n";
        $positions = [];

        foreach ($objets as $numero => $corps) {
            $positions[$numero] = strlen($pdf);
            $pdf .= $numero." 0 obj\n".$corps."\nendobj\n";
        }

        $depart = strlen($pdf);
        $total = max(array_keys($objets)) + 1;

        $pdf .= "xref\n0 $total\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i < $total; $i++) {
            $pdf .= isset($positions[$i])
                ? sprintf("%010d 00000 n \n", $positions[$i])
                : "0000000000 65535 f \n";
        }

        $pdf .= "trailer\n<< /Size $total /Root 1 0 R /Info $numeroInfo 0 R >>\n";
        $pdf .= "startxref\n$depart\n%%EOF";

        return $pdf;
    }

    /* ------------------------------------------------------------- Mécanique */

    /** Ferme la page en cours et pose le numéro de page sur chacune. */
    private function numeroterEtClore(): void
    {
        $this->finirLaPage();

        $total = count($this->pages);

        foreach ($this->pages as $i => $flux) {
            $mention = 'Page '.($i + 1).' sur '.$total;
            $largeur = $this->largeurDe($mention, 8.5, false);

            $this->pages[$i] = $flux
                .$this->opTrait(self::MARGE, 46, $this->largeur - self::MARGE, 46, '#E2E0D8', 0.6)
                .$this->opTexte($mention, $this->largeur - self::MARGE - $largeur, 32, 8.5, false, '#9A9DA5')
                .$this->opTexte($this->titre, self::MARGE, 32, 8.5, false, '#9A9DA5');
        }
    }

    /** Ouvre une page neuve dès que la place manque, en y rappelant l'en-tête du tableau. */
    private function reserver(float $hauteur): void
    {
        if ($this->y - $hauteur >= self::BAS_DE_PAGE) {
            return;
        }

        $this->finirLaPage();
        $this->y = $this->hauteur - self::MARGE;

        if ($this->enTeteDePage) {
            ($this->enTeteDePage)();
        }
    }

    private function finirLaPage(): void
    {
        $this->pages[] = $this->contenu;
        $this->contenu = '';
    }

    private function texte(string $texte, float $x, float $taille, bool $gras, string $couleur): void
    {
        $this->contenu .= $this->opTexte($texte, $x, $this->y, $taille, $gras, $couleur);
    }

    private function trait(float $y, string $couleur, float $epaisseur): void
    {
        $this->contenu .= $this->opTrait(self::MARGE, $y, $this->largeur - self::MARGE, $y, $couleur, $epaisseur);
    }

    private function opTexte(string $texte, float $x, float $y, float $taille, bool $gras, string $couleur): string
    {
        if (trim($texte) === '') {
            return '';
        }

        return sprintf(
            "%s\nBT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            $this->opCouleur($couleur, 'rg'),
            $gras ? 'F2' : 'F1',
            $taille, $x, $y,
            $this->echapper($texte),
        );
    }

    private function opTrait(float $x1, float $y1, float $x2, float $y2, string $couleur, float $epaisseur): string
    {
        return sprintf(
            "%s\n%.2f w %.2f %.2f m %.2f %.2f l S\n",
            $this->opCouleur($couleur, 'RG'), $epaisseur, $x1, $y1, $x2, $y2,
        );
    }

    private function opCouleur(string $hexa, string $operateur): string
    {
        [$r, $v, $b] = sscanf(ltrim($hexa, '#'), '%2x%2x%2x');

        return sprintf('%.3f %.3f %.3f %s', $r / 255, $v / 255, $b / 255, $operateur);
    }

    /** Windows-1252, puis échappement des trois caractères que la syntaxe se réserve. */
    private function echapper(string $texte): string
    {
        $converti = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texte);

        if ($converti === false) {
            $converti = preg_replace('/[^\x20-\x7E]/', '?', $texte) ?? '';
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $converti);
    }

    /* --------------------------------------------------- Largeur des glyphes */

    private function largeurUtile(): float
    {
        return $this->largeur - 2 * self::MARGE;
    }

    private function tronquer(string $texte, float $taille, bool $gras, float $largeurMax): string
    {
        if ($this->largeurDe($texte, $taille, $gras) <= $largeurMax) {
            return $texte;
        }

        while ($texte !== '' && $this->largeurDe($texte.'…', $taille, $gras) > $largeurMax) {
            $texte = mb_substr($texte, 0, mb_strlen($texte) - 1);
        }

        return $texte.'…';
    }

    /** @return array<int, string> */
    private function couper(string $texte, float $taille, bool $gras, float $largeurMax): array
    {
        $lignes = [];
        $courante = '';

        foreach (preg_split('/\s+/', trim($texte)) ?: [] as $mot) {
            $essai = $courante === '' ? $mot : $courante.' '.$mot;

            if ($this->largeurDe($essai, $taille, $gras) > $largeurMax && $courante !== '') {
                $lignes[] = $courante;
                $courante = $mot;

                continue;
            }

            $courante = $essai;
        }

        if ($courante !== '') {
            $lignes[] = $courante;
        }

        return $lignes ?: [''];
    }

    /**
     * Largeur réelle d'une chaîne, d'après les métriques Helvetica.
     *
     * Sans elles on ne saurait pas où couper : une largeur moyenne ferait déborder
     * « MM » et laisserait un blanc après « lli ». Les valeurs sont celles des
     * métriques Adobe, en millièmes de cadratin, pour les caractères 32 à 126 ;
     * au-delà (accents), la largeur d'un « o » suffit — l'écart est invisible.
     */
    private function largeurDe(string $texte, float $taille, bool $gras): float
    {
        $metriques = $gras ? self::LARGEURS_GRAS : self::LARGEURS_NORMAL;
        $total = 0;

        foreach (str_split($this->echapper($texte)) as $caractere) {
            $code = ord($caractere);
            $total += $metriques[$code - 32] ?? ($gras ? 611 : 556);
        }

        return $total * $taille / 1000;
    }

    /** Helvetica, caractères 32 à 126, en millièmes de cadratin. */
    private const LARGEURS_NORMAL = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** Helvetica-Bold, mêmes caractères. */
    private const LARGEURS_GRAS = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];
}

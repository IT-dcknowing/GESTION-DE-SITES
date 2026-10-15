<?php

namespace Modules\Noyau\Imports\Formats;

/**
 * Ce qu'un import a fait, ligne par ligne, en un seul objet.
 *
 * Les compteurs se lisent ensemble et doivent toujours se recouper : lues = créées +
 * mises à jour + ignorées + rejetées. Un import qui ne boucle pas cache quelque chose, et un
 * test le vérifie plutôt que de croire les chiffres sur parole.
 *
 * Les rejets sont conservés en mémoire dans une limite volontaire. Un fichier entièrement
 * décalé — mauvaise feuille, mauvais export — rejetterait ses neuf mille lignes, et écrire
 * neuf mille explications identiques n'apprend rien de plus que les cinquante premières.
 * Le compteur, lui, reste exact.
 */
class Resultat
{
    public const REJETS_CONSERVES = 500;

    public int $lues = 0;

    public int $creees = 0;

    public int $majs = 0;

    public int $ignorees = 0;

    public int $rejetees = 0;

    /**
     * Lignes qui répètent, dans le même fichier, une ligne déjà écrite — 14/10. Elles sont
     * écrites comme les autres (comptées dans créées ou inchangées) et, en plus, montrées comme
     * doublons supposés à trancher (15/10). Hors de l'invariant : ce n'est pas une issue de plus.
     */
    public int $doublons = 0;

    /** @var list<array{ligne: int, motif: string, valeurs: array}> */
    public array $rejets = [];

    /** @var array<string, int> ce qui a fait rejeter, et combien de fois */
    public array $motifs = [];

    public ?string $feuille = null;

    public ?int $ligneDEnTete = null;

    /** Lignes qui contredisaient l'atelier déclaré au dépôt. */
    public int $desaccords = 0;

    /**
     * Factures fournisseurs d'une autre ville que celle du dépôt — 13/10. Ce n'est pas une
     * erreur : la ville a commandé, une autre a reçu la facture. Dit à part, jamais en alerte.
     */
    public int $pourUneAutreVille = 0;

    /**
     * Fiches posées par une feuille annexe, hors des cinq compteurs.
     *
     * Le classeur du suivi fournisseurs porte une seconde feuille qui ne décrit pas des
     * opérations mais les fournisseurs eux-mêmes. Ses lignes n'entrent pas dans `lues` :
     * l'invariant « lues = créées + mises à jour + ignorées + rejetées » porte sur les
     * pièces, et y verser 287 fiches ferait mentir le seul chiffre qu'on vérifie.
     */
    public int $fiches = 0;

    public function compter(string $issue): void
    {
        match ($issue) {
            'cree' => $this->creees++,
            'maj' => $this->majs++,
            default => $this->ignorees++,
        };
    }

    public function rejeter(int $ligne, string $motif, array $valeurs): void
    {
        $this->rejetees++;
        $this->motifs[$motif] = ($this->motifs[$motif] ?? 0) + 1;

        if (count($this->rejets) < self::REJETS_CONSERVES) {
            $this->rejets[] = ['ligne' => $ligne, 'motif' => $motif, 'valeurs' => $valeurs];
        }
    }

    /** Vrai quand les compteurs se recoupent — l'invariant du module. */
    public function coherent(): bool
    {
        return $this->lues === $this->creees + $this->majs + $this->ignorees + $this->rejetees;
    }

    /** Une phrase pour l'écran, sans jargon. */
    public function resume(): string
    {
        $morceaux = [];

        if ($this->creees > 0) {
            $morceaux[] = $this->creees.' créée'.($this->creees > 1 ? 's' : '');
        }

        if ($this->majs > 0) {
            $morceaux[] = $this->majs.' mise'.($this->majs > 1 ? 's' : '').' à jour';
        }

        if ($this->ignorees > 0) {
            $morceaux[] = $this->ignorees.' inchangée'.($this->ignorees > 1 ? 's' : '');
        }

        if ($this->rejetees > 0) {
            $morceaux[] = $this->rejetees.' rejetée'.($this->rejetees > 1 ? 's' : '');
        }

        if ($this->doublons > 0) {
            $morceaux[] = 'dont '.$this->doublons.' supposée'.($this->doublons > 1 ? 's' : '').' doublon'
                .($this->doublons > 1 ? 's' : '').' (importée'.($this->doublons > 1 ? 's' : '').', à trancher sur la page des données)';
        }

        if ($morceaux === []) {
            return 'Aucune ligne exploitable.';
        }

        return $this->lues.' ligne'.($this->lues > 1 ? 's' : '').' lue'.($this->lues > 1 ? 's' : '')
            .' : '.implode(', ', $morceaux).'.'.$this->fichesPosees().$this->factureRecuePourUneAutreVille().$this->avertissementDAtelier();
    }

    /**
     * Ce qu'une feuille annexe a appris, dit à part.
     *
     * À part, parce que ce ne sont pas des lignes du même genre : mélanger « 7 350 lignes
     * lues » et « 287 fiches » dans la même phrase laisserait croire que le total des
     * factures a changé.
     */
    private function fichesPosees(): string
    {
        if ($this->fiches === 0) {
            return '';
        }

        return ' '.$this->fiches.' fiche'.($this->fiches > 1 ? 's' : '')
            .' fournisseur'.($this->fiches > 1 ? 's' : '').' à jour (délai de règlement, TVA).';
    }

    private function factureRecuePourUneAutreVille(): string
    {
        if ($this->pourUneAutreVille === 0) {
            return '';
        }

        return ' '.$this->pourUneAutreVille.' facture'.($this->pourUneAutreVille > 1 ? 's' : '')
            .' reçue'.($this->pourUneAutreVille > 1 ? 's' : '').' pour le compte d\'une autre ville : '
            .($this->pourUneAutreVille > 1 ? 'rangées' : 'rangée').' à la ville qui a commandé, avec la mention « reçue par ».';
    }

    /**
     * L'avertissement qui dit qu'un fichier n'a pas été filtré.
     *
     * Il compte parce qu'il est la seule trace d'une erreur qui, sans lui, passerait
     * inaperçue : déclarer un atelier sur une extraction non filtrée. Les lignes concernées
     * ne sont pas perdues — elles sont rangées par leur code employé, comme avant la
     * déclaration — mais le déposant doit savoir que sa déclaration n'a pas porté sur tout
     * le fichier, et pourquoi.
     */
    private function avertissementDAtelier(): string
    {
        if ($this->desaccords === 0) {
            return '';
        }

        return ' Attention : '.$this->desaccords.' ligne'.($this->desaccords > 1 ? 's' : '')
            .' relève'.($this->desaccords > 1 ? 'nt' : '').' d\'une autre ville que l\'atelier déclaré — '
            .'l\'extraction n\'a probablement pas été filtrée. '
            .'Ces lignes ont été rangées par leur code employé, pas dans l\'atelier déclaré.';
    }
}

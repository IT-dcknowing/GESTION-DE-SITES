<?php

namespace Modules\Noyau\Imports\Lecteurs;

use Generator;

/**
 * Ce qu'un classeur sait faire, quel que soit son format.
 *
 * Deux formats coexistent dans les exports du logiciel, et rien dans leur nom ne les
 * distingue de façon fiable : la situation du parc sort en `.xls` (OLE2/BIFF8, un format
 * de 1997), les devis en `.xlsx`, le suivi fournisseurs en `.xlsm`. Le reste de la chaîne
 * n'a pas à le savoir — elle demande des feuilles et des lignes.
 *
 * `lignes()` est un générateur, et ce n'est pas un détail de style : le fichier des
 * impayés fait 9 004 lignes, celui des fournisseurs 492 415 cellules. Charger un classeur
 * entier en mémoire sur un hébergement mutualisé, c'est la panne assurée le jour où le
 * fichier grossit. On lit en flux, une ligne à la fois, et la mémoire ne bouge pas.
 */
interface Lecteur
{
    /**
     * Les noms des feuilles, dans l'ordre du classeur.
     *
     * Un fichier peut en avoir plusieurs — le suivi fournisseurs en a douze, une par mois
     * — et la bonne n'est pas toujours la première.
     *
     * @return list<string>
     */
    public function feuilles(): array;

    /**
     * Les lignes d'une feuille, du haut vers le bas.
     *
     * La clé est le **numéro de ligne du tableur**, à partir de 1 : c'est celui que
     * l'utilisateur voit dans Excel, donc le seul qui lui permette de retrouver une ligne
     * rejetée. La valeur est un tableau indexé par position de colonne, à partir de 0.
     *
     * Les lignes vides sautées par le tableur le restent : la numérotation présente des
     * trous, et c'est voulu.
     *
     * @return Generator<int, array<int, string|float|\DateTimeImmutable|null>>
     */
    public function lignes(?string $feuille = null): Generator;

    /** Libère le fichier. Appelé même quand la lecture s'est mal passée. */
    public function fermer(): void;
}

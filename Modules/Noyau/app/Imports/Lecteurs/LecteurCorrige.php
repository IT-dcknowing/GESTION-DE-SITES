<?php

namespace Modules\Noyau\Imports\Lecteurs;

use Generator;

/**
 * Le classeur d'origine, vu à travers les corrections faites depuis.
 *
 * **Pourquoi une surcouche plutôt qu'une réécriture du fichier.** Le fichier déposé est la
 * pièce justificative : son empreinte est calculée à la réception, elle sert à refuser les
 * doublons, et elle prouve qu'on n'y a pas touché. Le réécrire pour y porter trois
 * corrections aurait changé cette empreinte — donc perdu la preuve, et fait repasser le
 * fichier corrigé pour un fichier neuf sans lien avec l'original.
 *
 * On garde donc le fichier intact et **on lit par-dessus**. L'import voit les valeurs
 * corrigées, le disque garde les valeurs d'origine, et la table des corrections dit
 * exactement ce qui sépare les deux. C'est la seule disposition où les trois questions —
 * qu'a-t-on importé, qu'y avait-il dans le fichier, qui a changé quoi — ont chacune une
 * réponse.
 *
 * La substitution se fait **par position de colonne**, sur le numéro de ligne du tableur, et
 * seulement sur la feuille où la ligne a été refusée : deux feuilles d'un même classeur ont
 * chacune leur ligne 1 722, et corriger l'une n'a aucune raison de toucher l'autre.
 */
class LecteurCorrige implements Lecteur
{
    /**
     * @param  array<string, array{action: string, valeurs: array<int, string>}>  $corrections
     *                          indexées par "feuille|numéro" — voir cle()
     */
    public function __construct(
        private Lecteur $source,
        private array $corrections,
    ) {}

    /** Ce qui n'a rien à corriger se lit tel quel : pas d'enveloppe inutile. */
    public static function envelopper(Lecteur $source, array $corrections): Lecteur
    {
        return $corrections === [] ? $source : new self($source, $corrections);
    }

    public static function cle(?string $feuille, int $numero): string
    {
        return trim((string) $feuille).'|'.$numero;
    }

    public function feuilles(): array
    {
        return $this->source->feuilles();
    }

    public function lignes(?string $feuille = null): Generator
    {
        foreach ($this->source->lignes($feuille) as $numero => $cellules) {
            // La feuille nommée d'abord, la clé sans feuille ensuite : un rejet enregistré
            // avant qu'on ne retienne le nom de la feuille doit rester corrigeable.
            $correction = $this->corrections[self::cle($feuille, (int) $numero)]
                ?? $this->corrections[self::cle(null, (int) $numero)]
                ?? null;

            if ($correction === null) {
                yield $numero => $cellules;

                continue;
            }

            // Une ligne retirée disparaît de la lecture. Elle n'est ni rejetée ni comptée :
            // quelqu'un a décidé qu'elle n'avait pas à entrer, et c'est une décision, pas
            // un incident.
            if ($correction['action'] === 'retiree') {
                continue;
            }

            foreach ($correction['valeurs'] as $position => $valeur) {
                // Une correction vidée efface la cellule : c'est un cas réel — une date
                // fantaisiste qu'on préfère laisser vide plutôt que d'inventer.
                $cellules[(int) $position] = $valeur === '' ? null : $valeur;
            }

            yield $numero => $cellules;
        }
    }

    public function fermer(): void
    {
        $this->source->fermer();
    }
}

<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Collection;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;

/**
 * Le solde d'une caisse : un cumul, ligne à ligne, dans l'ordre du fichier.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **La règle, en trois lignes.**
 *
 *     solde(1) = solde_annoncé(1)                       // le fichier le dit lui-même
 *     solde(n) = solde(n-1) + entrée(n) - sortie(n)
 *     solde_final = solde(1) + Σ entrées - Σ sorties     // sur les lignes 2..n
 *
 * Une cellule vide vaut zéro, jamais une erreur. Une annulation s'écrit en **montant
 * négatif dans la colonne de la pièce qu'elle annule** : la formule la traite telle quelle,
 * sans l'ignorer, sans inverser son signe, sans la ranger dans l'autre colonne.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Les deux règles qu'on avait manquées, et qui fabriquaient une fausse anomalie.**
 * Relevé le 02/10 : l'écran annonçait un écart de 652 917 F sur un classeur dont le solde
 * de 696 075 F est juste.
 *
 * **1. L'ordre est celui du fichier, jamais celui de la date.** Un cumul dépend de l'ordre
 * dans lequel les lignes sont écrites, et des dizaines de lignes partagent la même date :
 * trier par date les rebat au hasard. Pire, une date mal lue déplace sa ligne d'un bout à
 * l'autre du classeur. Mesuré sur les 1 155 mouvements en base : trié par date, la chaîne
 * partait d'une ligne datée du **31/12/1899** — le zéro d'Excel, une date que l'import n'a
 * pas su lire sur un feuillet « DEC 25 » — et finissait sur une ligne datée du **15/10/2026**
 * appartenant au feuillet « JANV 26 ». Les deux bornes du rapprochement étaient des lignes
 * du milieu, choisies par une date fausse. Dans l'ordre du fichier, les bornes redeviennent
 * la première et la dernière ligne.
 *
 * **2. Une chaîne ne traverse pas deux feuillets.** Chaque feuillet d'un classeur de caisse
 * repart de son propre fonds de caisse ; mis bout à bout, deux cumuls indépendants ne
 * s'additionnent pas. La même mesure, reprise feuillet par feuillet :
 *
 *     DEC 25            441 lignes    écart        1 000
 *     JANV 26-CONGES    332 lignes    écart     -271 425
 *     FEV 26            212 lignes    écart   -2 000 000
 *     MARS 26           170 lignes    écart        AUCUN
 *
 * Un feuillet se rapproche **exactement**, et les trois autres deviennent trois questions
 * précises, chacune bornée à deux cents lignes. Le chiffre global, lui, ne disait rien.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Un solde négatif n'est pas une erreur de calcul.** Il vient de l'ordre de saisie : une
 * sortie écrite avant les entrées de la même journée fait passer le solde sous zéro
 * quelques lignes durant. On le signale, on ne le « corrige » pas.
 *
 * @see LE-SOLDE-DE-CAISSE.md pour la règle écrite à l'usage de qui relit un classeur.
 */
class ChaineDeSolde
{
    /** Le solde d'une caisse est au débit quand elle tient de l'argent. */
    public const DEBIT = 'D';

    /** Au crédit quand elle en doit — c'est-à-dire quand le cumul est négatif. */
    public const CREDIT = 'C';

    /**
     * Le mouvement signé d'une ligne : une entrée s'ajoute, une sortie se retranche.
     *
     * Le montant est pris **tel qu'il est écrit**, signe compris. Une annulation de sortie
     * de −150 000 fait donc `- (-150 000)`, soit +150 000 : le solde remonte, ce qui est
     * exactement ce qu'une annulation doit faire.
     */
    public static function mouvement(MouvementCaisse $ligne): int
    {
        return $ligne->sens === MouvementCaisse::ENTREE
            ? (int) $ligne->montant
            : -(int) $ligne->montant;
    }

    /** « D » si la caisse tient de l'argent, « C » si le cumul est passé sous zéro. */
    public static function sens(int $solde): string
    {
        return $solde < 0 ? self::CREDIT : self::DEBIT;
    }

    /**
     * Ce que chaque ligne devrait annoncer, dans l'ordre du fichier.
     *
     * La collection reçue est **remise dans l'ordre du fichier** quoi qu'il arrive : l'écran
     * l'affiche du plus récent au plus ancien, et un cumul calculé à l'envers ne voudrait
     * rien dire.
     *
     * Le départ est le solde que le fichier annonce sur sa première ligne. Il **comprend
     * déjà** le mouvement de cette ligne — le classeur l'écrit ainsi, une entrée de 1 000 sur
     * une caisse à zéro affiche 1 000 — donc on ne le rajoute pas, sous peine de le compter
     * deux fois. À défaut d'annonce, on part de zéro et l'on suit les mouvements seuls : les
     * écarts d'une ligne à l'autre restent justes, seule leur origine est inconnue.
     *
     * @param  Collection<int, MouvementCaisse>  $lignes  une seule chaîne
     * @return array<int|string, int> le solde calculé, par identifiant de ligne
     */
    public static function soldes(Collection $lignes): array
    {
        $ordonnees = self::dansLOrdreDuFichier($lignes);

        if ($ordonnees->isEmpty()) {
            return [];
        }

        $premiere = $ordonnees->first();
        $solde = $premiere->solde_annonce !== null
            ? (int) $premiere->solde_annonce
            : self::mouvement($premiere);

        $soldes = [$premiere->getKey() => $solde];

        foreach ($ordonnees->skip(1) as $ligne) {
            $solde += self::mouvement($ligne);
            $soldes[$ligne->getKey()] = $solde;
        }

        return $soldes;
    }

    /**
     * Le rapprochement d'une chaîne : ce que le fichier annonce à la fin, contre notre cumul.
     *
     * Rend `null` quand il n'y a rien à dire — moins de deux lignes annoncées, ou les deux
     * nombres d'accord. Un indicateur qui répète « tout va bien » cesse d'être lu.
     *
     * @param  Collection<int, MouvementCaisse>  $lignes  une seule chaîne
     * @return array{depart: MouvementCaisse, arrivee: MouvementCaisse, attendu: int, annonce: int, ecart: int}|null
     */
    public static function rapprochement(Collection $lignes): ?array
    {
        $annoncees = self::dansLOrdreDuFichier($lignes)
            ->filter(fn (MouvementCaisse $ligne) => $ligne->solde_annonce !== null)
            ->values();

        if ($annoncees->count() < 2) {
            return null;
        }

        $depart = $annoncees->first();
        $arrivee = $annoncees->last();

        /*
         * On suit **toutes** les lignes entre les deux bornes, annoncées ou non, parce que
         * c'est le cumul qu'on vérifie et qu'une ligne sans solde annoncé bouge la caisse
         * autant qu'une autre. Et l'on exclut la ligne de départ, dont le solde annoncé
         * comprend déjà son propre mouvement.
         */
        $entreDeux = self::dansLOrdreDuFichier($lignes)
            ->skipUntil(fn (MouvementCaisse $ligne) => $ligne->is($depart))
            ->skip(1)
            ->takeUntil(fn (MouvementCaisse $ligne) => $ligne->is($arrivee))
            ->concat([$arrivee]);

        $attendu = (int) $depart->solde_annonce
            + $entreDeux->sum(fn (MouvementCaisse $ligne) => self::mouvement($ligne));

        $annonce = (int) $arrivee->solde_annonce;

        if ($annonce === $attendu) {
            return null;
        }

        return compact('depart', 'arrivee', 'attendu', 'annonce') + ['ecart' => $annonce - $attendu];
    }

    /**
     * L'ordre du fichier, et non celui des dates.
     *
     * L'identifiant suit l'ordre d'insertion, donc l'ordre de lecture du classeur. C'est le
     * seul repère fiable : les dates se répètent sur des dizaines de lignes d'affilée, et
     * certaines n'ont pas été lues du tout.
     *
     * @param  Collection<int, MouvementCaisse>  $lignes
     * @return Collection<int, MouvementCaisse>
     */
    public static function dansLOrdreDuFichier(Collection $lignes): Collection
    {
        return $lignes->sortBy(fn (MouvementCaisse $ligne) => $ligne->getKey())->values();
    }

    /**
     * Les chaînes contenues dans un lot de lignes : une par caisse et par feuillet.
     *
     * La clé est affichable telle quelle — c'est le nom du feuillet, celui que le classeur
     * porte en onglet, et qui dit à la personne où aller regarder.
     *
     * @param  Collection<int, MouvementCaisse>  $lignes
     * @return Collection<string, Collection<int, MouvementCaisse>>
     */
    public static function chaines(Collection $lignes): Collection
    {
        return $lignes
            ->groupBy(fn (MouvementCaisse $ligne) => trim(($ligne->caisse ?? '').' '.($ligne->feuille ?? '')) ?: 'Sans feuillet')
            ->map(fn (Collection $chaine) => self::dansLOrdreDuFichier($chaine));
    }
}

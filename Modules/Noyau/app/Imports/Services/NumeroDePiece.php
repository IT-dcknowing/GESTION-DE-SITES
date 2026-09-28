<?php

namespace Modules\Noyau\Imports\Services;

/**
 * Le noyau d'un numéro de pièce : ce qu'il en reste quand on retire la façon de l'écrire.
 *
 * **Le défaut mesuré le 25/09, et la question qui l'a trouvé.** Le propriétaire a demandé
 * si la clé de rapprochement des factures fournisseurs — fournisseur, n° de pièce, date,
 * montant — était infaillible, « les deux fichiers n'ayant pas la même formalisation du
 * numéro ». Elle ne l'était pas, et c'est exactement par là.
 *
 * Les deux classeurs de suivi désignent la même facture de deux façons :
 *
 *      Abidjan écrit  « 0001827 »        San Pédro écrit  « 22319I091/0001827 »
 *      Abidjan écrit  « 02015 »          San Pédro écrit  « 0 2015 »
 *      Abidjan écrit  « 00 32165 »       San Pédro écrit  « 23005S023/0032165 »
 *
 * Le préfixe est le code du bon de commande du fournisseur ; l'espace et les zéros de tête
 * sont de la frappe. Ce qui identifie la pièce est **ce qui suit le dernier « / »**, une
 * fois retirés les séparateurs et les zéros qui ne servent qu'à aligner les colonnes.
 *
 * **Ce que la correction rattrape, compté sur les deux fichiers réels :** la clé littérale
 * reconnaissait 2 648 lignes comme déjà vues ; celle-ci en reconnaît 3 210. **562 pièces
 * seraient donc entrées deux fois** le jour où le second classeur serait déposé, et la
 * dette aurait été comptée double sans qu'une seule ligne ne le signale.
 *
 * **Et elle ne confond rien.** Les neuf seuls cas où ce noyau rapproche deux lignes qu'un
 * numéro littéral séparait ont été ouverts un par un : ce sont neuf fois la même pièce
 * écrite deux fois dans le même classeur — « FC26-00278 » et « FC2600278 », « 0 23982 » et
 * « 23982 ». Aucune facture distincte n'y perd son identité.
 *
 * **Sur la base en ligne, au 25/09 : zéro groupe concerné.** Seul l'un des deux classeurs a
 * été déposé (1 848 pièces), si bien que la correction ne déplace rien de ce qui existe —
 * elle empêche ce qui serait arrivé au prochain dépôt.
 */
class NumeroDePiece
{
    /**
     * Le noyau comparable d'un numéro de pièce.
     *
     * Rend la chaîne vide quand il n'en reste rien de comparable : l'appelant doit alors
     * retomber sur les trois autres champs de la clé plutôt que de rapprocher deux pièces
     * sur du vide.
     */
    public static function noyau(?string $numero): string
    {
        $numero = trim((string) $numero);

        if ($numero === '') {
            return '';
        }

        // Le code du bon de commande précède le numéro chez plusieurs fournisseurs, et un
        // seul des deux classeurs le recopie. Ce qui identifie la pièce est ce qui suit.
        if (str_contains($numero, '/')) {
            $numero = substr($numero, strrpos($numero, '/') + 1);
        }

        $numero = preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($numero));

        // Les zéros de tête alignent une colonne, ils ne numérotent rien : « 0001827 » et
        // « 1827 » sont la même pièce. On ne les retire que s'il reste quelque chose —
        // « 0000 » doit rester « 0000 » plutôt que de devenir la chaîne vide.
        return ltrim((string) $numero, '0') ?: (string) $numero;
    }

    /** Deux numéros désignent-ils la même pièce, quelle que soit la façon de les écrire ? */
    public static function memePiece(?string $a, ?string $b): bool
    {
        $noyauA = self::noyau($a);

        return $noyauA !== '' && $noyauA === self::noyau($b);
    }
}

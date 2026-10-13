<?php

namespace App\Support;

/**
 * Un montant se lit tel qu'il est donné — jamais arrondi à l'unité.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Le propriétaire, le 13/10** : « ne jamais arrondir des montants ; récupère tel que donné. »
 * Le fichier du CA porte des centimes (FA -5725 : 2 137 270,28) ; l'import les arrondissait à la
 * ligne, et le total de l'écran s'écartait de celui du classeur de 101 F. Les colonnes d'argent
 * sont désormais des décimaux (`decimal(18,4)` : quatre décimales, que le portail comme le classeur
 * n'atteignent pas), et ce service est le **seul endroit** qui dit comment on les lit en PHP.
 *
 * **Entier quand le montant est entier, décimal sinon.** Une facture de 300 000 F reste l'entier
 * 300000 : tout le code d'avant — comparaisons strictes, tableaux de totaux, tests — continue de
 * vivre avec des entiers, et seuls les montants qui portent des centimes deviennent des flottants.
 * C'est ce qui rend ce changement faisable sans réécrire l'application.
 *
 * **Pourquoi les chaînes** : MySQL rend un `decimal` — et la `sum()` d'un décimal — sous forme de
 * chaîne (« 300000.0000 »). Un `(int)` jeté dessus coupait les centimes sans rien dire : c'est
 * exactement l'arrondi qu'on chasse. Tout ce qui lit un montant ou un total passe par `de()`.
 */
final class Montant
{
    /** Le nombre de décimales conservées — celui de la colonne. */
    public const DECIMALES = 4;

    /**
     * Le montant sous sa forme native : `int` s'il est entier, `float` sinon, 0 s'il est vide ou
     * illisible. Les bruits de virgule flottante (0,1 + 0,2) sont écartés à la quatrième décimale.
     */
    public static function de(mixed $valeur): int|float
    {
        if (is_int($valeur)) {
            return $valeur;
        }

        if ($valeur === null || $valeur === '' || is_bool($valeur) || (! is_numeric($valeur))) {
            return 0;
        }

        $nombre = round((float) $valeur, self::DECIMALES);

        return floor($nombre) === $nombre && abs($nombre) < PHP_INT_MAX ? (int) $nombre : $nombre;
    }

    /** Comme `de()`, mais `null` reste `null` — pour les colonnes qui peuvent être vides. */
    public static function ouNul(mixed $valeur): int|float|null
    {
        return $valeur === null || $valeur === '' ? null : self::de($valeur);
    }

    /**
     * Le montant écrit : « 1 867 767 452,25 » — sans décimale quand il est entier, avec au moins
     * deux (et jusqu'à quatre, sans zéro inutile) quand il ne l'est pas.
     */
    public static function nombre(mixed $valeur): string
    {
        $m = self::de($valeur);

        if (is_int($m)) {
            return number_format($m, 0, ',', ' ');
        }

        $texte = rtrim(number_format($m, self::DECIMALES, ',', ' '), '0');

        // Au moins deux décimales dès qu'il y en a : « 0,5 » se lit mal pour de l'argent.
        $apresLaVirgule = strlen($texte) - (int) strrpos($texte, ',') - 1;

        return $apresLaVirgule < 2 ? $texte.str_repeat('0', 2 - $apresLaVirgule) : $texte;
    }
}

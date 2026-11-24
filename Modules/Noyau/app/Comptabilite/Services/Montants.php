<?php

namespace Modules\Noyau\Comptabilite\Services;

/**
 * L'arithmétique exacte des écritures — 24/11.
 *
 * « Pourquoi arrondir un montant dont l'opération a déjà été faite ? On écrit tel que c'est. »
 * Les montants sont en `decimal(18,4)` ; ils sont comptés ici en dix-millièmes entiers, lus depuis
 * leur écriture décimale, jamais par un flottant : l'équilibre d'une opération se vérifie au
 * dix-millième près, sans tolérance qui cacherait un écart.
 */
final class Montants
{
    public const ECHELLE = 10000;

    /** Le montant en dix-millièmes entiers (« 1250000.25 » → 12500002500). */
    public static function unites(mixed $montant): int
    {
        if ($montant === null || $montant === '') {
            return 0;
        }

        $texte = is_float($montant) ? rtrim(rtrim(sprintf('%.6F', $montant), '0'), '.') : trim((string) $montant);
        $negatif = str_starts_with($texte, '-');
        $texte = ltrim($texte, '+-');
        [$entier, $decimales] = array_pad(explode('.', $texte, 2), 2, '');

        if (strlen(rtrim($decimales, '0')) > 4) {
            throw new \InvalidArgumentException("Montant à plus de quatre décimales : {$montant}");
        }

        $unites = ((int) ($entier === '' ? '0' : $entier)) * self::ECHELLE + (int) str_pad(substr($decimales, 0, 4), 4, '0');

        return $negatif ? -$unites : $unites;
    }

    /** L'écriture décimale exacte d'un nombre de dix-millièmes (12500002500 → « 1250000.25 »). */
    public static function texte(int $unites): string
    {
        $signe = $unites < 0 ? '-' : '';
        $unites = abs($unites);
        $entier = intdiv($unites, self::ECHELLE);
        $reste = rtrim(str_pad((string) ($unites % self::ECHELLE), 4, '0', STR_PAD_LEFT), '0');

        return $signe.$entier.($reste !== '' ? '.'.$reste : '');
    }

    /** Le montant pour le JSON de COMPTAFLOW : un entier quand il l'est, sinon ses décimales exactes. */
    public static function pourEnvoi(int $unites): int|float
    {
        return $unites % self::ECHELLE === 0 ? intdiv($unites, self::ECHELLE) : (float) self::texte($unites);
    }

    /** Vrai quand le montant a plus de deux décimales : COMPTAFLOW n'en garde que deux. */
    public static function auDelaDuCentime(int $unites): bool
    {
        return $unites % 100 !== 0;
    }
}

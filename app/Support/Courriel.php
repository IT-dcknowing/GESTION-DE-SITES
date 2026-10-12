<?php

namespace App\Support;

/**
 * Une adresse e-mail telle qu'on la tape — ou qu'on la colle.
 *
 * **Le 12/10, deux fois de suite** : « t.tou@lartisanauto.com » refusée, d'abord par le
 * navigateur, puis — le navigateur ne bloquant plus — par le serveur (« doit être une adresse
 * e-mail valide »). L'adresse était juste à l'œil ; elle portait un caractère que l'œil ne voit
 * pas, venu d'un copier-coller. Le premier nettoyage ne retirait que les espaces invisibles ;
 * il en existe d'autres : le trait d'union conditionnel (U+00AD), les marques de sens
 * d'écriture, les caractères de mise en forme. On les retire **tous** — la catégorie Unicode
 * entière (`\p{Cf}`), plus les séparateurs et les caractères de contrôle.
 *
 * Et si l'adresse reste refusée, on dit **lequel** : une lettre cyrillique qui ressemble à
 * une lettre latine ne se voit pas davantage, et « adresse invalide » ne dit pas où chercher.
 */
final class Courriel
{
    public static function nettoyer(?string $valeur): string
    {
        $texte = (string) $valeur;

        if (class_exists(\Normalizer::class)) {
            $texte = \Normalizer::normalize($texte, \Normalizer::FORM_KC) ?: $texte;
        }

        return (string) preg_replace('/[\p{Cf}\p{Z}\p{Cc}\s]+/u', '', $texte);
    }

    /**
     * Les caractères qui ne sont pas de l'ASCII ordinaire, avec leur position (1 = premier).
     *
     * @return array<int, string> « position 9 : « а » (U+0430) »
     */
    public static function caracteresSuspects(string $valeur): array
    {
        $suspects = [];

        foreach (mb_str_split($valeur) as $rang => $caractere) {
            if (preg_match('/^[\x21-\x7E]$/', $caractere) !== 1) {
                $suspects[] = 'position '.($rang + 1).' : « '.$caractere.' » (U+'.strtoupper(str_pad(dechex(mb_ord($caractere)), 4, '0', STR_PAD_LEFT)).')';
            }
        }

        return $suspects;
    }
}

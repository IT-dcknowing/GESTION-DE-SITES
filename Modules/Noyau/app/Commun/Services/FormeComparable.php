<?php

namespace Modules\Noyau\Commun\Services;

/**
 * Ramener une plaque ou un nom à la forme sous laquelle ils se comparent.
 *
 * **Pourquoi ce service existe.** Rien dans les fichiers ne porte d'identifiant commun :
 * le devis vient du logiciel de l'atelier, la prospection du téléphone d'un commercial, la
 * fiche de réception d'un troisième classeur. Ce qu'ils partagent, ce sont des chaînes
 * tapées à la main — une immatriculation, un nom de client — et elles ne se ressemblent
 * jamais tout à fait : « 1234 ab 01 », « 1234AB01 », « Bernabé », « BERNABE SA ».
 *
 * **Décidé le 18/09/2026 : on n'attend pas le code client.** L'obtenir suppose que
 * l'éditeur l'ajoute ou ouvre ses API. D'ici là, le rapprochement se fait sur ces deux
 * formes-là. Le jour où le code arrivera, il se posera par-dessus sans rien défaire : ces
 * règles ne sont écrites nulle part ailleurs, et c'est ici qu'on les retirera.
 *
 * **Ce service ne décide de rien.** Il rend une forme comparable, pas un verdict : deux
 * chaînes réduites à la même forme sont un *rapprochement possible*, qu'un humain confirme.
 */
class FormeComparable
{
    /**
     * Une immatriculation, ramenée à ses seules lettres et chiffres, en majuscules.
     *
     * Les séparateurs sautent : ni l'espace ni le tiret ne distinguent deux véhicules, et
     * les deux graphies « 1234 AB 01 » et « 1234-AB-01 » désignent la même voiture sur le
     * même parking. Les accents n'ont rien à faire dans une plaque, mais y arrivent parfois
     * par un copier-coller : ils sont ramenés à leur lettre.
     */
    public static function plaque(?string $valeur): string
    {
        $valeur = self::sansAccents((string) $valeur);

        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($valeur)) ?? '';
    }

    /**
     * Une plaque telle qu'on l'affiche : majuscules, espaces simples, bords nets.
     *
     * Différente de `plaque()` à dessein. Celle-ci sert à **écrire** la valeur (dans une
     * note, dans un champ, dans une adresse) ; l'autre sert à **comparer**. Confondre les
     * deux reviendrait à stocker « 1234AB01 » et à ne plus jamais pouvoir le relire comme
     * le conducteur l'a sur sa carte grise.
     */
    public static function plaqueLisible(?string $valeur): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $valeur) ?? ''));
    }

    /**
     * Un nom de client, ramené à ce qui l'identifie.
     *
     * Les accents tombent, la casse aussi, la ponctuation et les espaces avec — « Bernabé »
     * et « BERNABE » deviennent le même mot. Les formes juridiques finales (SA, SARL, CI,
     * SAS…) sont retirées : le même client s'écrit « NESTLE » un jour et « NESTLE CI »
     * l'autre, et refuser le rapprochement pour deux lettres serait perdre la seule piste
     * qu'on ait.
     *
     * C'est volontairement une comparaison **large** : elle propose, elle ne conclut pas.
     * Un rapprochement par le nom est d'ailleurs rendu comme le plus faible des trois, et
     * n'est jamais confirmé tout seul.
     */
    public static function nom(?string $valeur): string
    {
        $valeur = self::sansAccents((string) $valeur);

        // Le point d'un sigle n'est pas un séparateur : « S.A. » est un mot, et le couper
        // en deux lettres ferait échouer le retrait de la forme juridique juste en dessous.
        $valeur = str_replace('.', '', $valeur);

        $valeur = preg_replace('/[^A-Za-z0-9]+/', ' ', $valeur) ?? '';
        $valeur = mb_strtoupper(trim($valeur));

        // Retirées seulement **en fin de nom** : « CI » au milieu peut être un mot, et
        // « SA » au début est probablement le début d'un nom propre.
        $valeur = preg_replace('/\s+(SA|SAS|SARL|SARLU|SCI|CI|SUARL|ETS|EURL|GIE)$/', '', $valeur) ?? $valeur;

        return preg_replace('/\s+/', ' ', trim($valeur)) ?? '';
    }

    /** Les lettres accentuées ramenées à leur lettre, sans dépendre de l'extension intl. */
    private static function sansAccents(string $valeur): string
    {
        return strtr($valeur, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);
    }
}

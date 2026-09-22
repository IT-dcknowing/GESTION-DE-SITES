<?php

namespace Modules\Noyau\Commun\Services;

use DateTimeInterface;

/**
 * Compter des jours entre deux dates, sans passer par Carbon.
 *
 * **Pourquoi ce fichier existe.** Les écrans du recouvrement demandaient l'ancienneté de
 * chaque créance, et l'ancienneté s'écrivait ainsi :
 *
 *     $depart->copy()->startOfDay()->diffInDays($arrete->copy()->startOfDay(), absolute: false)
 *
 * C'est juste, et c'est lent. Mesuré sur la base au 21/09/2026 : **2 147 ms pour les 8 955
 * factures** du périmètre — deux copies d'objet, deux remises à minuit et une soustraction
 * de calendrier par ligne. Écrite en entiers, la même boucle tient en **5 ms**. Les quatre
 * écrans qui lisent tout le portefeuille — clients, synthèse, courtiers, tableau de bord —
 * y passaient chacun deux secondes à ne rien faire d'autre que soustraire des dates.
 *
 * **Ce que la mesure ne dit pas, et qui compte autant.** La lenteur ne restait pas dans la
 * page qui la produisait : `php artisan serve` ne sert qu'une requête à la fois, si bien
 * qu'une page à trois secondes retient aussi la feuille de style et le script de la
 * suivante. C'est ce qui donnait l'impression que « plus aucun clic ne passe ».
 *
 * **La règle, en un endroit.** Un jour civil porte un numéro : celui du nombre de jours
 * écoulés depuis le 1ᵉʳ janvier 1970, lu à l'heure locale de la date. Deux instants du même
 * jour rendent le même numéro, et la différence de deux numéros est le nombre de jours qui
 * les sépare — exactement ce que disait la remise à minuit, sans les objets.
 *
 * `getOffset()` est interrogé sur la date elle-même plutôt que déduit une fois pour toutes :
 * c'est lui qui rend le résultat indépendant du fuseau, et il ne coûte rien (5 ms pour neuf
 * mille appels). Un calcul qui supposerait UTC serait juste ici et faux ailleurs.
 */
class NombreDeJours
{
    /** Le numéro du jour civil d'une date, compté depuis le 1ᵉʳ janvier 1970. */
    public static function jour(DateTimeInterface $instant): int
    {
        // `floor` et non `intdiv` : la division entière de PHP tronque vers zéro, et
        // rendrait le mauvais jour pour une date antérieure à 1970. Aucune n'apparaît ici,
        // mais une règle qui se trompe sur un cas qu'on n'a pas encore rencontré est une
        // règle qu'on ne peut pas réemployer.
        return (int) floor(($instant->getTimestamp() + $instant->getOffset()) / 86400);
    }

    /**
     * Le numéro du même jour, lu cette fois dans une date écrite « AAAA-MM-JJ… ».
     *
     * Les écrans qui consolident tout le portefeuille lisent les lignes telles que la base
     * les rend, sans construire de Carbon : c'est précisément là qu'elles gagnent leurs
     * deux secondes. Il leur faut donc la même règle à partir d'une chaîne.
     *
     * `gmmktime` et non `mktime` : le fuseau du serveur n'a rien à dire sur un jour civil
     * déjà écrit. Rendu null quand la chaîne n'en est pas une — une facture sans date n'a
     * pas d'âge, et n'en invente pas un.
     */
    public static function jourDeLIso(?string $date): ?int
    {
        $jour = substr(trim((string) $date), 0, 10);

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $jour, $morceaux)) {
            return null;
        }

        return (int) floor(
            gmmktime(0, 0, 0, (int) $morceaux[2], (int) $morceaux[3], (int) $morceaux[1]) / 86400,
        );
    }

    /**
     * Le nombre de jours de `$depuis` à `$jusqua`, négatif si l'ordre est inversé.
     *
     * Deux dates du même jour rendent 0, la veille rend −1, le lendemain 1.
     */
    public static function entre(DateTimeInterface $depuis, DateTimeInterface $jusqua): int
    {
        return self::jour($jusqua) - self::jour($depuis);
    }
}

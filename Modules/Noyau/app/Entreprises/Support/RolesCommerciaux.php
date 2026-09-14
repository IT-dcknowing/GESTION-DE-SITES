<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;

/**
 * Les rôles dont le titulaire vend — et porte donc une fiche commercial et des objectifs.
 *
 * **Pourquoi cette liste existe.** Un responsable de ville, un responsable de site et un
 * responsable commercial prospectent en plus d'encadrer. Sans fiche, ni leurs prospections
 * ni leur chiffre d'affaires ne seraient rattachables à quiconque : le travail existerait
 * en base sans personne derrière.
 *
 * **Pourquoi elle est ici et pas dans chaque fichier.** Elle était recopiée à l'identique
 * dans `CreerAcces` et `ModifierAcces`, et une troisième copie dormait en creux dans le
 * bandeau de navigation — sous la forme d'un onglet qu'on n'avait donné qu'au commercial.
 * C'est ainsi qu'un responsable de site s'est retrouvé avec des objectifs qu'aucun écran
 * ne lui montrait. Une liste recopiée finit toujours par diverger, et c'est la copie qu'on
 * oublie qui reste sous les yeux de l'utilisateur.
 *
 * Le gérant n'en fait pas partie : il répond de l'entreprise entière et ne porte pas
 * d'objectif individuel.
 */
class RolesCommerciaux
{
    /** @var array<int, string> */
    public const TOUS = [
        'responsable_ville',
        'responsable_site',
        'responsable_commercial',
        'commercial',
    ];

    /** Cette personne vend-elle, en plus de ce qu'elle fait par ailleurs ? */
    public static function enFaitPartie(?User $personne): bool
    {
        if (! $personne) {
            return false;
        }

        foreach (self::TOUS as $role) {
            if ($personne->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La liste sous la forme qu'attend le middleware `role:` — « a|b|c ».
     *
     * Les routes sont du PHP : les y écrire à la main serait une copie de plus, et c'est
     * précisément celle qui déciderait qui reçoit un refus.
     */
    public static function pourMiddleware(): string
    {
        return implode('|', self::TOUS);
    }
}

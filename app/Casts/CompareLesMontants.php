<?php

namespace App\Casts;

use App\Casts\Montant as Colonne;
use App\Support\Montant;

/**
 * Un modèle à colonnes d'argent décimales sait qu'un montant n'a pas changé quand il vaut la même
 * chose — pas quand il s'écrit de la même façon.
 *
 * MySQL rend « 300000.0000 » ; le fichier donne 300000. Pour Eloquent ce sont deux chaînes, donc un
 * changement : chaque redépôt d'un fichier inchangé réécrivait toutes ses lignes et les comptait
 * « mises à jour » au lieu de « inchangées ». On compare ici les valeurs lues par `Montant`.
 */
trait CompareLesMontants
{
    public function originalIsEquivalent($key)
    {
        if (($this->getCasts()[$key] ?? null) === Colonne::class) {
            if (! array_key_exists($key, $this->original)) {
                return false;
            }

            return Montant::ouNul($this->attributes[$key] ?? null) === Montant::ouNul($this->original[$key]);
        }

        return parent::originalIsEquivalent($key);
    }
}

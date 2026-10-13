<?php

namespace App\Casts;

use App\Support\Montant as Lecture;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * La colonne d'argent d'un modèle : un décimal en base, un entier ou un flottant en PHP — voir
 * `App\Support\Montant`. Une colonne qui peut être vide reste `null`.
 *
 * @implements CastsAttributes<int|float|null, int|float|string|null>
 */
class Montant implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return Lecture::ouNul($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int|float|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Écrit tel quel : l'arrondi aux quatre décimales est celui de la colonne, pas le nôtre.
        return is_numeric($value) ? $value : 0;
    }
}

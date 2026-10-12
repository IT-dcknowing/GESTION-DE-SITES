<?php

namespace App\Rules;

use App\Support\Courriel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * La règle « email », appliquée à l'adresse **nettoyée** — voir `Courriel`.
 *
 * Remplace `'email'` dans les formulaires de comptes : une adresse juste ne doit plus être
 * refusée pour un caractère invisible. Le modèle `User` retire les mêmes caractères à
 * l'enregistrement. Si l'adresse reste invalide, le message nomme le caractère en cause.
 */
class AdresseEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $propre = Courriel::nettoyer((string) $value);

        // Des caractères ordinaires seulement : la règle « email » de Laravel accepte les
        // adresses internationales, donc aussi une lettre cyrillique qui imite une lettre
        // latine — l'adresse passerait, et personne ne pourrait se connecter en la tapant.
        $suspects = Courriel::caracteresSuspects($propre);

        if ($suspects === [] && Validator::make(['v' => $propre], ['v' => 'email'])->passes()) {
            return;
        }

        $fail($suspects === []
            ? 'Le champ :attribute doit être une adresse e-mail valide.'
            : 'Le champ :attribute contient un caractère qui n’est pas une lettre ordinaire — '
                .implode(' ; ', $suspects).'. Retapez l’adresse au clavier plutôt que de la coller.');
    }
}

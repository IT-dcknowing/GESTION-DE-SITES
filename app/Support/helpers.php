<?php

if (! function_exists('ae')) {
    /** Formate un montant en FCFA, tel qu'il est — ex : 1 250 000 F, ou 1 250 000,25 F. */
    function ae(int|float|string|null $montant): string
    {
        if ($montant === null) {
            return '—';
        }

        return \App\Support\Montant::nombre($montant).' F';
    }
}

if (! function_exists('montant_de')) {
    /**
     * Un montant ou un total lu en base, sans jamais couper ses centimes — l'entier quand il est
     * entier. À la place de `(int)`, qui arrondissait l'argent. Voir `App\Support\Montant`.
     */
    function montant_de(mixed $valeur): int|float
    {
        return \App\Support\Montant::de($valeur);
    }
}

if (! function_exists('montant_nombre')) {
    /** Un montant écrit sans l'unité : 1 250 000 ou 1 250 000,25. À la place de `number_format(…, 0)`. */
    function montant_nombre(mixed $valeur): string
    {
        return \App\Support\Montant::nombre($valeur);
    }
}

if (! function_exists('an')) {
    /** Formate un taux en pourcentage — ex : 42,3 %. */
    function an(?float $taux): string
    {
        if ($taux === null || is_nan($taux)) {
            return '—';
        }

        return number_format($taux * 100, 1, ',', ' ').' %';
    }
}

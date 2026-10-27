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

if (! function_exists('texte_d_attribut')) {
    /**
     * Un texte reçu d'un attribut de composant, rendu à sa forme lisible — 27/10 (« Encaissé
     * aujourd&#039;hui » sur le tableau de bord de la comptabilité).
     *
     * `label="Encaissé {{ $jour }}"` échappe déjà `$jour` en passant l'attribut ; le composant
     * l'échappait une seconde fois en l'affichant, et l'apostrophe devenait `&#039;`. Le texte est
     * ramené ici à ses caractères, puis le composant l'échappe une fois, normalement : rien
     * n'entre jamais non échappé dans la page.
     */
    function texte_d_attribut(mixed $texte): string
    {
        return html_entity_decode((string) $texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

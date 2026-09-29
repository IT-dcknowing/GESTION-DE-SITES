<?php

/*
|--------------------------------------------------------------------------
| À qui s'adresser quand l'application ne répond pas
|--------------------------------------------------------------------------
| **Pourquoi un fichier de configuration et non la base.** Ces coordonnées paraissent sur
| les pages d'erreur, et une page d'erreur s'affiche précisément quand l'application ne va
| pas bien — parfois parce que la base est injoignable. Aller les y chercher reviendrait à
| rejouer la panne au moment où l'on demande de l'aide.
|
| Elles sont donc lues d'ici, et surchargeables par l'environnement : une entreprise qui
| change de numéro n'a pas à redéployer du code.
|
| **Ce qu'il faut renseigner au déploiement**, dans le `.env` du serveur :
|
|     ASSISTANCE_COURRIEL="support@artisan-automobile.ci"
|     ASSISTANCE_TELEPHONES="+225 07 00 00 00 00, +225 01 00 00 00 00"
|     ASSISTANCE_HORAIRES="du lundi au samedi, 8 h – 18 h"
|
| Tant qu'elles ne le sont pas, la page d'erreur dit simplement de s'adresser au
| responsable de l'application plutôt que d'afficher un numéro inventé — un numéro faux sur
| une page d'erreur fait perdre un appel au moment où il compte le plus.
*/

return [

    'courriel' => env('ASSISTANCE_COURRIEL'),

    /*
     * Un ou plusieurs numéros, séparés par des virgules. Deux plutôt qu'un : le jour où
     * l'application tombe est souvent le jour où la première ligne est occupée.
     */
    'telephones' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ASSISTANCE_TELEPHONES', '')),
    ))),

    'horaires' => env('ASSISTANCE_HORAIRES'),

];

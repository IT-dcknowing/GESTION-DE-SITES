<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Le PHP en ligne de commande qui lit les fichiers déposés
    |--------------------------------------------------------------------------
    | Au dépôt, la lecture démarre dans un processus à part. Il faut pour cela le PHP
    | « ligne de commande », qui n'est pas celui qui sert les pages sous PHP-FPM ou LiteSpeed.
    | Laissé vide, il est cherché aux emplacements habituels (/usr/local/bin/php, /usr/bin/php).
    | Voir LanceurDeTraitement.
    */

    'php_cli' => env('IMPORT_PHP_CLI'),

];

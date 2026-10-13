<?php

/*
|--------------------------------------------------------------------------
| Le relevé des achats FNE — réglages du scraper (SCRAPER-PORTAIL-FNE/)
|--------------------------------------------------------------------------
| L'application ne va pas chercher les factures elle-même : un script Node ouvre une session
| sur le portail de la DGI avec le compte de l'entreprise et dépose un fichier JSON dans
| `storage/app/portail-fne/achats/`. Ces réglages ne servent qu'à le **lancer** — à l'heure par
| le planificateur, à la demande par le bouton « Actualiser ».
|
| `actif` est faux par défaut, et c'est délibéré : tant que le .env du scraper n'est pas rempli
| (adresse du portail, NCC, mot de passe), chaque passage échouerait et ne remplirait que le
| journal. On l'allume quand `node achats.js` a réussi une fois à la main.
*/
return [
    'actif' => filter_var(env('FNE_SCRAPER_ACTIF', false), FILTER_VALIDATE_BOOL),

    // Chemin absolu de préférence : le cron d'un hébergeur n'a pas le PATH d'un terminal.
    'node' => env('FNE_NODE', 'node'),

    'script' => env('FNE_SCRAPER_SCRIPT', base_path('SCRAPER-PORTAIL-FNE/achats.js')),

    // Ce que le planificateur relève : toutes les heures, aux heures ouvrées, du lundi au samedi.
    // Une facture de fournisseur n'arrive pas la nuit ; chaque passage est une connexion avec le
    // mot de passe de l'entreprise sur le portail de l'État, et on n'en multiplie pas le nombre
    // pour rien. Le relevé COMPLET (depuis 2023) passe une fois, la nuit.
    'heure_debut' => env('FNE_HEURE_DEBUT', '07:00'),
    'heure_fin' => env('FNE_HEURE_FIN', '19:00'),
    'heure_complet' => env('FNE_HEURE_COMPLET', '02:30'),

    // Un relevé courant ne reprend que ce que le portail a reçu récemment — il met à jour ce qui
    // existe, il ne supprime rien. Le complet, lui, repasse tout l'historique.
    'depuis_jours' => (int) env('FNE_DEPUIS_JOURS', 35),

    // Au-delà, le bouton « Actualiser » renonce à attendre et dit où lire la cause.
    'delai_secondes' => (int) env('FNE_DELAI_SECONDES', 600),
];

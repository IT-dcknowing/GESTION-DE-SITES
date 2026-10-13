<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Entretien du journal de traçabilité. Déclaré ici pour l'hébergement qui dispose d'une
 * tâche planifiée ; là où il n'y en a pas, l'écran reste juste sans elle — une session
 * silencieuse n'y est pas comptée comme présente, et les durées sont tenues à jour à
 * chaque requête. La commande range l'historique, elle ne le fabrique pas.
 */
Schedule::command('tracabilite:entretenir')->hourly()->withoutOverlapping();

/*
 * Le relevé des achats FNE (12/10) : toutes les heures, aux heures ouvrées, du lundi au samedi, et
 * l'historique entier une fois la nuit. Sans effet tant que FNE_SCRAPER_ACTIF est faux — voir
 * `config/fne.php` pour le pourquoi de ce rythme.
 */
Schedule::command('fne:actualiser')->hourly()
    ->between(config('fne.heure_debut', '07:00'), config('fne.heure_fin', '19:00'))
    ->days([1, 2, 3, 4, 5, 6])
    ->withoutOverlapping(15)->runInBackground();
Schedule::command('fne:actualiser --complet')->dailyAt(config('fne.heure_complet', '02:30'))
    ->withoutOverlapping(30)->runInBackground();

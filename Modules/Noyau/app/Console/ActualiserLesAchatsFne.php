<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Modules\Noyau\Imports\Services\LanceurDeReleveFne;

/**
 * Relève le portail FNE puis range ce qui est arrivé — la tâche planifiée du relevé des achats.
 *
 * Toutes les heures aux heures ouvrées (courant : 35 jours), et une fois la nuit (`--complet` :
 * tout l'historique). Voir `config/fne.php`. Sans effet tant que `FNE_SCRAPER_ACTIF` est faux.
 *
 * Elle n'écrit que dans `releves_fne` / `factures_fne` (via `fne:releve-achats`) : jamais une
 * dette ni un règlement. Elle ne s'inscrit pas dans `app:deployer` (règle 1).
 */
class ActualiserLesAchatsFne extends Command
{
    protected $signature = 'fne:actualiser {--complet : relève tout l’historique, pas seulement les derniers jours}';

    protected $description = 'Relève les achats du portail FNE puis les range (relevé horaire, ou complet avec --complet)';

    public function handle(): int
    {
        if (! LanceurDeReleveFne::actif()) {
            $this->warn('Le relevé automatique est éteint (pas de SCRAPER-PORTAIL-FNE/.env, ou FNE_SCRAPER_ACTIF=false) : rien lancé.');

            return self::SUCCESS;
        }

        // Le verrou du bouton « Actualiser » : un relevé en route n'est pas doublé.
        if (! LanceurDeReleveFne::verrouiller()) {
            $this->line('Un relevé est déjà en route : rien lancé.');

            return self::SUCCESS;
        }

        try {
            [$reussi, $sortie] = LanceurDeReleveFne::executer((bool) $this->option('complet'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            LanceurDeReleveFne::deverrouiller();
        }

        if (! $reussi) {
            $this->error("Le relevé a échoué :\n".$sortie);

            return self::FAILURE;
        }

        Artisan::call('fne:releve-achats');
        $this->line(trim(Artisan::output()));

        return self::SUCCESS;
    }
}

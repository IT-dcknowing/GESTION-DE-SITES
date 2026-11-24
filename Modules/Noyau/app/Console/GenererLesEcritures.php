<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Modules\Noyau\Comptabilite\Services\DeversementComptable;
use Modules\Noyau\Comptabilite\Services\GenerateurDEcritures;

/**
 * `php artisan comptabilite:generer {entreprise} --du= --au= [--deverser]` — 24/11.
 *
 * Le même travail que le bouton de l'écran Écritures comptables, pour le serveur : générer
 * l'exercice d'une entreprise, puis le déverser vers COMPTAFLOW si demandé. N'écrit que dans les
 * tables comptables ; ne touche aucune donnée source.
 */
class GenererLesEcritures extends Command
{
    protected $signature = 'comptabilite:generer {entreprise : identifiant de l\'entreprise}
        {--du= : premier jour (AAAA-MM-JJ), par défaut le 1er janvier de l\'année}
        {--au= : dernier jour, par défaut le 31 décembre}
        {--deverser : envoyer ensuite vers COMPTAFLOW}';

    protected $description = 'Génère les écritures comptables d\'une période et, au besoin, les déverse vers COMPTAFLOW';

    public function handle(): int
    {
        if (! GenerateurDEcritures::disponible()) {
            $this->error('La migration du 24/11 (écritures comptables) n’est pas encore jouée.');

            return self::FAILURE;
        }

        $entreprise = (int) $this->argument('entreprise');
        $du = $this->option('du') ?: now()->startOfYear()->toDateString();
        $au = $this->option('au') ?: now()->endOfYear()->toDateString();

        $bilan = (new GenerateurDEcritures($entreprise))->generer($du, $au);
        $this->table(array_keys($bilan), [array_values($bilan)]);

        if ($this->option('deverser')) {
            $d = (new DeversementComptable($entreprise))->deverser(null);
            $this->info($d['message'] ?? ($d['acceptees'].' acceptée(s), '.$d['refusees'].' refusée(s) sur '.$d['envoyees']));
        }

        return self::SUCCESS;
    }
}

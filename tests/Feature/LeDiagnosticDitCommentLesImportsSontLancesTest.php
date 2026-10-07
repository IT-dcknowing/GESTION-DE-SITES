<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Imports\Services\LanceurDeTraitement;
use Tests\TestCase;

/**
 * Le diagnostic dit comment le PHP web a lancé le dernier import — ajouté après la 503 du 05/10.
 *
 * Si la lecture d'un fichier tourne dans un processus qui sert les pages, elle l'occupe
 * pendant des minutes : cause plausible d'un « Backend fetch failed » sur un hébergement
 * mutualisé. Seul le PHP web le sait ; le lanceur le note, et le diagnostic le lit.
 */
class LeDiagnosticDitCommentLesImportsSontLancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_lancement_dans_la_requete_web_est_signale(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(LanceurDeTraitement::TEMOIN, json_encode([
            'mode' => 'requete-web', 'quand' => '2026-10-05T13:30:00+00:00', 'php_cli' => null, 'exec_permis' => false,
        ]));

        $this->artisan('app:diagnostic')
            ->expectsOutputToContain('dans un processus web')
            ->expectsOutputToContain('IMPORT_PHP_CLI');
    }

    public function test_sans_temoin_le_diagnostic_le_dit_sans_tomber(): void
    {
        Storage::fake('local');

        $this->artisan('app:diagnostic')
            ->expectsOutputToContain('aucun dépôt depuis la mise à jour du 07/10');
    }
}

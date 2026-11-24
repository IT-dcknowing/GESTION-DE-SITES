<?php

namespace Modules\Noyau\Comptabilite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Noyau\Comptabilite\Services\DeversementComptable;
use Modules\Noyau\Comptabilite\Services\GenerateurDEcritures;

/**
 * Génère les écritures d'une période puis, si demandé, les déverse — 24/11.
 *
 * En tâche de fond : une génération d'exercice relit des milliers de lignes, et un déversement
 * attend COMPTAFLOW. Une seule à la fois par entreprise (`ShouldBeUnique`). Le bilan est gardé une
 * heure pour l'écran, qui l'affiche à son retour.
 */
class GenererEtDeverser implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $entrepriseId,
        public readonly ?string $debut,
        public readonly ?string $fin,
        public readonly bool $deverser = false,
    ) {}

    public function uniqueId(): string
    {
        return 'comptabilite-'.$this->entrepriseId;
    }

    public static function cleDuBilan(int $entrepriseId): string
    {
        return 'comptabilite:bilan:'.$entrepriseId;
    }

    public function handle(): void
    {
        $bilan = ['commence_le' => now()->toDateTimeString()];

        if ($this->debut && $this->fin) {
            $bilan['generation'] = (new GenerateurDEcritures($this->entrepriseId))->generer($this->debut, $this->fin);
        }
        if ($this->deverser) {
            $bilan['deversement'] = (new DeversementComptable($this->entrepriseId))->deverser();
        }

        $bilan['termine_le'] = now()->toDateTimeString();
        Cache::put(self::cleDuBilan($this->entrepriseId), $bilan, now()->addHour());
    }
}

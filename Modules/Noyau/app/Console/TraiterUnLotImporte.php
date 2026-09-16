<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Modeles\LotImport;
use Throwable;

/**
 * Lit un fichier déposé, dans son propre processus.
 *
 * Ce n'est pas une commande qu'on tape : c'est celle que le dépôt lance lui-même, détachée de
 * la requête, pour que la lecture démarre à l'instant où l'on clique « Lancer l'import » —
 * voir LanceurDeTraitement. Elle fait exactement ce que ferait la file d'attente, et le lot ne
 * peut être pris qu'une fois : la lancer à la main sur un lot déjà lu ne fait rien.
 */
class TraiterUnLotImporte extends Command
{
    protected $signature = 'import:traiter-lot
        {lot : identifiant du dépôt}
        {--controle : lire et compter sans rien écrire}';

    protected $description = "Lit un fichier déposé à l'import (lancé automatiquement au dépôt)";

    public function handle(): int
    {
        $lot = LotImport::withoutGlobalScopes()->find((int) $this->argument('lot'));

        if ($lot === null) {
            $this->error('Aucun dépôt ne porte cet identifiant.');

            return self::FAILURE;
        }

        try {
            (new TraiterUnLot($lot, ! $this->option('controle')))->handle();
        } catch (Throwable) {
            // Le motif est déjà écrit sur le lot par l'exécuteur, en des termes lisibles.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

<?php

namespace Modules\Noyau\Imports\Services;

use RuntimeException;

/**
 * L'arrêt demandé pendant une lecture.
 *
 * Une exception et non un drapeau qu'on testerait au retour : c'est la seule sortie qui
 * traverse le parcours d'un format sans qu'aucun chemin puisse l'oublier, et qui force la
 * transaction à tout défaire.
 */
final class ImportArrete extends RuntimeException
{
    public function __construct(
        public readonly int $lignesLues,
        public readonly int $parUserId,
        public readonly string $parNom,
    ) {
        parent::__construct('Import arrêté à la demande.');
    }
}

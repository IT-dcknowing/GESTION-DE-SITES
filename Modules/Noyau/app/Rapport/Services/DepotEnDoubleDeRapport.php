<?php

namespace Modules\Noyau\Rapport\Services;

use Modules\Noyau\Rapport\Modeles\DepotRapport;
use RuntimeException;

/** Ce fichier est déjà passé — le dépôt existant est porté par l'exception, pour que l'écran dise quand et par qui. */
class DepotEnDoubleDeRapport extends RuntimeException
{
    public function __construct(public readonly DepotRapport $depotExistant)
    {
        parent::__construct(sprintf(
            'Ce fichier est déjà passé le %s%s — %d pièce(s) lue(s), rien n\'est recréé.',
            $depotExistant->created_at?->format('d/m/Y à H\hi') ?? 'une date inconnue',
            $depotExistant->deposant?->name ? ', déposé par '.$depotExistant->deposant->name : '',
            $depotExistant->nb_nouvelles,
        ));
    }
}

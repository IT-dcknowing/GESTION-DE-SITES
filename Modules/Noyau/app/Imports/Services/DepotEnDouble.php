<?php

namespace Modules\Noyau\Imports\Services;

use Modules\Noyau\Imports\Modeles\LotImport;
use RuntimeException;

/**
 * Ce fichier est déjà passé.
 *
 * Une exception à part, et non un message parmi d'autres, parce que l'écran doit pouvoir en
 * dire beaucoup plus qu'un refus : **qui** l'a déposé, **quand**, et **ce que ça avait
 * donné**. « Ce fichier est déjà passé le 20/08 à 14h12, déposé par K. Désirée : 2 203
 * fiches créées » répond à la question que la personne se pose vraiment — dois-je
 * m'inquiéter, ou est-ce simplement déjà fait ?
 *
 * Le lot est donc porté par l'exception, pas seulement son identifiant.
 */
class DepotEnDouble extends RuntimeException
{
    public function __construct(public readonly LotImport $lotExistant)
    {
        parent::__construct(sprintf(
            'Ce fichier est déjà passé le %s, déposé par %s — %s',
            $lotExistant->created_at?->format('d/m/Y à H\hi') ?? 'une date inconnue',
            $lotExistant->deposant,
            $lotExistant->message ?: $lotExistant->etatLisible(),
        ));
    }
}

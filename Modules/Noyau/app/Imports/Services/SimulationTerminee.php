<?php

namespace Modules\Noyau\Imports\Services;

use RuntimeException;

/**
 * Le signal qui annule proprement la transaction d'une simulation.
 *
 * Ce n'est pas une panne : c'est la façon dont le mode contrôle tient sa promesse. Faire
 * remonter une exception jusqu'à la transaction garantit l'annulation quel que soit le
 * chemin de sortie, là où un `rollBack` posé à la main s'oublie le jour où quelqu'un
 * ajoute un `return` au milieu.
 */
class SimulationTerminee extends RuntimeException {}

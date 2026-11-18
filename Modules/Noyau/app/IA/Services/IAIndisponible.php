<?php

namespace Modules\Noyau\IA\Services;

use RuntimeException;

/** L'IA d'un usage n'a pas pu répondre — clé absente, fournisseur en erreur, délai dépassé. Le message se lit à l'écran. */
class IAIndisponible extends RuntimeException {}

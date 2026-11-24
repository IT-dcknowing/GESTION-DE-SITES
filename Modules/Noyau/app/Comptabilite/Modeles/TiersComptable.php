<?php

namespace Modules\Noyau\Comptabilite\Modeles;

use Illuminate\Database\Eloquent\Model;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * Le numéro auxiliaire d'un client ou d'un fournisseur — 24/11.
 *
 * « Le compte général reste 411000, mais chaque tiers a un numéro de tiers. » Le numéro
 * (410001, 400001…) ne remplace jamais le compte collectif ; le tiers divers (410000, 400000)
 * reçoit ce qui n'a ni nom ni NCC exploitable.
 */
class TiersComptable extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'tiers_comptables';

    protected $guarded = ['id'];

    protected $casts = ['est_divers' => 'boolean'];
}

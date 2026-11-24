<?php

namespace Modules\Noyau\Comptabilite\Modeles;

use Illuminate\Database\Eloquent\Model;

/** Une ligne d'écriture : un seul compte, au débit **ou** au crédit, le tiers à part — 24/11. */
class LigneComptable extends Model
{
    protected $table = 'lignes_comptables';

    protected $guarded = ['id'];
}

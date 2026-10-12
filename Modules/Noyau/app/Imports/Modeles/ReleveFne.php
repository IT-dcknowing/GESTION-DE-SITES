<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/** Un fichier de relevé du portail FNE, lu une fois — son empreinte le reconnaît. */
#[Fillable(['entreprise_id', 'login', 'fichier', 'empreinte', 'periode_du', 'periode_au', 'factures', 'nouvelles', 'depose_par'])]
class ReleveFne extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'releves_fne';

    protected function casts(): array
    {
        return ['periode_du' => 'date', 'periode_au' => 'date'];
    }
}

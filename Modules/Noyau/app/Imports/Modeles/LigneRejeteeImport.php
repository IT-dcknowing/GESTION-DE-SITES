<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne qu'on n'a pas su lire, mise de côté avec son motif et ses valeurs d'origine.
 *
 * Les valeurs brutes sont conservées telles quelles, et c'est tout l'intérêt : « ligne
 * 4212 rejetée » n'aide personne à corriger quoi que ce soit. « ligne 4212 — date
 * illisible : 31/12/1899 » se répare dans le fichier source en trente secondes.
 *
 * Le rejet n'est pas un échec de l'import : c'est ce qui lui permet de continuer. Une
 * ligne douteuse écrite quand même est bien pire qu'une ligne mise de côté — la première
 * fausse un total sans prévenir, la seconde attend qu'on s'en occupe.
 */
#[Fillable(['lot_import_id', 'feuille', 'numero_ligne', 'motif', 'valeurs'])]
class LigneRejeteeImport extends Model
{
    protected $table = 'lignes_rejetees_import';

    protected function casts(): array
    {
        return ['valeurs' => 'array', 'numero_ligne' => 'integer'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }
}

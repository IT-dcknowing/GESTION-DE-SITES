<?php

namespace Modules\Noyau\Entreprises\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un geste (modifier, supprimer) accordé ou retiré à un rôle ou à un compte — 26/10.
 *
 * Défini depuis la Maintenance du Super admin, comme les rôles configurés : un rôle vaut pour toute
 * la plateforme, un compte pour lui seul. Voir `HabilitationsDActions`.
 */
#[Fillable(['domaine', 'action', 'role', 'user_id', 'accorde', 'accorde_par'])]
class HabilitationAction extends Model
{
    protected $table = 'habilitations_actions';

    protected function casts(): array
    {
        return ['accorde' => 'boolean'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }
}

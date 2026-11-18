<?php

namespace Modules\Noyau\IA\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/** La conversation d'un compte avec l'assistant d'un usage — `[['role' => 'user'|'assistant', 'content' => '…', 'at' => '…']]`. */
#[Fillable(['entreprise_id', 'user_id', 'usage', 'messages'])]
class ConversationIA extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'conversations_ia';

    protected function casts(): array
    {
        return ['messages' => 'array'];
    }

    /** @return list<array{role: string, content: string, at: string}> */
    public function tours(): array
    {
        return array_values($this->messages ?? []);
    }

    public function ajouter(string $role, string $contenu): void
    {
        $tours = $this->tours();
        $tours[] = ['role' => $role, 'content' => $contenu, 'at' => now()->toDateTimeString()];
        // On garde les 60 derniers tours : la conversation reste lisible et le coût borné.
        $this->messages = array_slice($tours, -60);
        $this->save();
    }
}

<?php

namespace Modules\Noyau\Imports\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * Ce qu'une personne a changé sur une ligne refusée, et tout ce qui permet de le lui dire.
 *
 * Cette table est d'abord une pièce de traçabilité, et son contenu le montre : les valeurs
 * d'avant y sont conservées entières, à côté de celles d'après. Ne garder que le résultat
 * aurait suffi à faire tourner l'import et n'aurait servi à rien le jour où quelqu'un
 * demande pourquoi un montant a changé.
 *
 * **L'adresse IP et le poste sont relevés, et il faut dire pourquoi.** Une correction
 * d'import n'est pas une saisie parmi d'autres : elle réécrit ce qu'un fichier officiel
 * disait. Savoir depuis quel poste elle a été faite est ce qui distingue une erreur de
 * frappe d'un contournement. Ce sont des données personnelles au sens propre : elles ne
 * sont montrées qu'aux rôles qui répondent d'un import, jamais exportées, et elles
 * disparaissent avec l'entreprise.
 */
#[Fillable([
    'entreprise_id', 'lot_import_id', 'ligne_rejetee_id', 'numero_ligne', 'feuille',
    'action', 'valeurs_avant', 'valeurs_apres', 'motif',
    'user_id', 'auteur', 'adresse_ip', 'poste',
])]
class CorrectionImport extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'corrections_import';

    /** Ce qu'on a fait de la ligne. */
    public const ACTIONS = [
        'corrigee' => 'Corrigée',
        'retiree' => 'Retirée de l\'import',
    ];

    protected function casts(): array
    {
        return [
            'valeurs_avant' => 'array',
            'valeurs_apres' => 'array',
            'numero_ligne' => 'integer',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actionLisible(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /**
     * Les colonnes réellement modifiées : position => [avant, après].
     *
     * C'est ce qu'on affiche, et non les quarante valeurs de la ligne : une correction qui
     * noie son unique changement dans trente-neuf colonnes inchangées ne se relit pas.
     *
     * @return array<int, array{avant: string, apres: string}>
     */
    public function ecarts(): array
    {
        $avant = $this->valeurs_avant ?? [];
        $apres = $this->valeurs_apres ?? [];
        $ecarts = [];

        foreach ($apres as $position => $valeur) {
            $ancienne = (string) ($avant[$position] ?? '');

            if ((string) $valeur !== $ancienne) {
                $ecarts[(int) $position] = ['avant' => $ancienne, 'apres' => (string) $valeur];
            }
        }

        return $ecarts;
    }
}

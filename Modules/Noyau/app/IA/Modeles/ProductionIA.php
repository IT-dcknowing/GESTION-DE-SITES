<?php

namespace Modules\Noyau\IA\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * Ce que l'assistant a produit : une `analyse` globale du rapport, ou un `document` (imprimable en PDF).
 * Le style est une liste blanche — une couleur d'accent, une couleur de fond, une mise en page : l'IA
 * choisit dans ce cadre, elle n'injecte ni CSS ni HTML.
 */
#[Fillable(['entreprise_id', 'user_id', 'usage', 'type', 'exercice', 'titre', 'contenu', 'style'])]
class ProductionIA extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'productions_ia';

    public const MISES_EN_PAGE = ['standard', 'colonnes', 'sobre'];

    /** @return array{accent: string, fond: string, mise_en_page: string} */
    public function styleSur(): array
    {
        return self::styleValide(json_decode((string) $this->style, true) ?: []);
    }

    /**
     * @param  array<string, mixed>  $brut
     * @return array{accent: string, fond: string, mise_en_page: string}
     */
    public static function styleValide(array $brut): array
    {
        $couleur = fn ($v, string $defaut) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : $defaut;

        return [
            'accent' => $couleur($brut['accent'] ?? null, '#C8102E'),
            'fond' => $couleur($brut['fond'] ?? null, '#F4F3EF'),
            'mise_en_page' => in_array($brut['mise_en_page'] ?? null, self::MISES_EN_PAGE, true) ? $brut['mise_en_page'] : 'standard',
        ];
    }

    /** Le Markdown de l'IA devient du HTML : le HTML qu'elle aurait glissé est neutralisé. */
    public function html(): string
    {
        return (string) Str::markdown($this->contenu, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}

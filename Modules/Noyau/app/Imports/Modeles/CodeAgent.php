<?php

namespace Modules\Noyau\Imports\Modeles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Le code de deux lettres qu'une personne laisse sur tout ce qu'elle saisit.
 *
 * Dans le logiciel WinDev, les trois villes vivent dans la même base : rien, dans une
 * ligne de facture ou de devis, ne dit d'où elle vient. Rien sauf ceci — le numéro de
 * fiche porte les initiales de qui l'a rédigée :
 *
 *     FR-KZN° 010669       →  code KZ
 *     PR-SK-16091          →  code SK
 *     PR--13699            →  aucun code (Bouaké laisse le champ vide)
 *
 * Ce référentiel est donc la pièce maîtresse du rattachement. Il descend jusqu'au
 * **site**, et pas seulement à la ville, parce qu'Abidjan en compte deux et qu'aucun
 * fichier ne les distingue : la colonne SITE des exports dit « ABIDJAN », un point c'est
 * tout. Seul le code de la personne peut trancher.
 *
 * `site_id` reste donc facultatif, et c'est volontaire : tant que la répartition n'a pas
 * été faite à la main, la ligne s'arrête à la ville. Une donnée qu'on ne sait pas placer
 * se déclare inconnue ; elle ne se répartit pas au hasard entre deux ateliers.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'user_id',
    'code', 'libelle', 'nom', 'prenom', 'fonction', 'occurrences', 'est_actif',
])]
class CodeAgent extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'codes_agents';

    protected function casts(): array
    {
        return ['est_actif' => 'boolean', 'occurrences' => 'integer'];
    }

    /**
     * Le code lu dans un numéro de fiche ou de proforma, ou null.
     *
     * Les deux formes du logiciel, et une troisième qui n'en est pas une : à Bouaké, les
     * proformas sortent en « PR--13699 », sans initiales. Ce n'est pas une anomalie à
     * rejeter — ce sont 36 devis bien réels. La lecture doit l'admettre et rendre null,
     * charge au reste de la chaîne de trouver la ville autrement.
     */
    public static function extraire(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        // FR-KZN° 010669 · FR-YBN°010153 — la fiche de réception.
        if (preg_match('/^FR-([A-Z]{2})\s*N/iu', $reference, $trouve)) {
            return mb_strtoupper($trouve[1]);
        }

        // PR-SK-16091 — la proforma. Le tiret double de Bouaké ne correspond pas, et c'est
        // exactement ce qu'on veut.
        if (preg_match('/^PR-([A-Z]{2})-/iu', $reference, $trouve)) {
            return mb_strtoupper($trouve[1]);
        }

        return null;
    }

    /**
     * Le code déjà connu, ou une fiche neuve prête à être nommée.
     *
     * Un code inconnu **ne bloque jamais un import**. Il est enregistré sans ville ni
     * site, son compteur s'incrémente, et il remonte en tête de l'écran des codes à
     * nommer. Refuser la ligne reviendrait à perdre une facture réelle parce qu'on ne
     * sait pas encore qui est « TT » — le mauvais échange.
     */
    public static function rencontrer(int $entrepriseId, string $code): self
    {
        $agent = static::withoutGlobalScopes()->firstOrCreate(
            ['entreprise_id' => $entrepriseId, 'code' => mb_strtoupper($code)],
            ['est_actif' => true, 'occurrences' => 0],
        );

        $agent->increment('occurrences');

        return $agent;
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Vrai quand le code sait dire où il travaille — c'est ce qui reste à remplir. */
    public function estRenseigne(): bool
    {
        return $this->ville_id !== null;
    }

    /**
     * Le nom de celui qui porte le code, tel qu'on l'écrirait sur une liste.
     *
     * Rendu vide plutôt que « null null » quand on ne le connaît pas encore : un code sans
     * nom est le cas de départ, pas une anomalie.
     */
    public function nomComplet(): string
    {
        return trim(trim((string) $this->nom).' '.trim((string) $this->prenom));
    }
}

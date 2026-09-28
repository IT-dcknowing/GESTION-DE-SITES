<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Facades\DB;

/**
 * Quel commercial se cache derrière un code de deux lettres du logiciel d'atelier.
 *
 * **Le trou que cette classe rend visible**, mesuré le 25/09 après une remarque du
 * propriétaire — « tous les devis ne sont pas liés à un commercial, j'espère que tu l'as
 * pris en compte » :
 *
 *   - 2 673 devis en base, dont **2 432 venus de l'import** ;
 *   - **2 432 sans commercial, et 2 432 sans code** — c'est-à-dire la totalité ;
 *   - alors que le numéro de chacun le dit : « PR-MT-11434 » porte MT.
 *
 * Deux causes, pas une. L'import extrayait bien le code — il s'en sert pour ranger la
 * ligne dans sa ville — mais ne l'écrivait pas sur le devis. C'est réparé. Reste la
 * seconde, qui n'est pas un défaut de code : **aucun des 39 codes de l'atelier n'est relié
 * à un compte**, donc à un commercial. Tant que ce lien n'est pas fait, il n'y a personne
 * à désigner, et inventer un rattachement serait attribuer un chiffre d'affaires à
 * quelqu'un sur une ressemblance.
 *
 * Le lien se fait à l'écran — Import → Employés & codes → la fiche d'un code —, une fois
 * par personne. C'est une décision humaine, et elle doit le rester.
 *
 * **La chaîne est celle qui existait déjà** : `codes_agents.user_id` → `commerciaux.user_id`.
 * Elle est écrite une seule fois, ici, et {@see CommercialDeLaFiche} s'en sert comme
 * l'import des devis : deux lectures d'une même chaîne finissent par diverger.
 */
class CodesDesCommerciaux
{
    /** @var array<int, self> une instance par entreprise, pour ne lire la table qu'une fois */
    private static array $instances = [];

    /** @var array<string, int>|null code de deux lettres => identifiant du commercial */
    private ?array $table = null;

    private function __construct(private int $entrepriseId) {}

    public static function pour(int $entrepriseId): self
    {
        return self::$instances[$entrepriseId] ??= new self($entrepriseId);
    }

    /** À oublier entre deux imports d'une même exécution : un lien peut avoir été posé. */
    public static function oublier(): void
    {
        self::$instances = [];
    }

    /** Le commercial que ce code désigne, ou null tant que personne ne l'a relié. */
    public function commercialDuCode(?string $code): ?int
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return $this->table()[$code] ?? null;
    }

    /**
     * Combien de codes désignent quelqu'un, sur combien.
     *
     * Sert à l'écran des codes pour dire la vérité plutôt que la promesse : « 0 code sur
     * 39 est relié à un commercial » se lit autrement que « c'est par elles que les devis
     * rejoignent leur commercial ».
     *
     * @return array{relies: int, total: int}
     */
    public function couverture(): array
    {
        return [
            'relies' => count($this->table()),
            'total' => (int) DB::table('codes_agents')->where('entreprise_id', $this->entrepriseId)->count(),
        ];
    }

    /** @return array<string, int> */
    private function table(): array
    {
        return $this->table ??= DB::table('codes_agents')
            ->join('commerciaux', function ($jointure) {
                $jointure->on('commerciaux.user_id', '=', 'codes_agents.user_id')
                    ->on('commerciaux.entreprise_id', '=', 'codes_agents.entreprise_id');
            })
            ->where('codes_agents.entreprise_id', $this->entrepriseId)
            ->whereNotNull('codes_agents.user_id')
            ->pluck('commerciaux.id', 'codes_agents.code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (int) $id])
            ->all();
    }
}

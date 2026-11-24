<?php

namespace Modules\Noyau\Comptabilite\Services;

use App\Support\SchemaDisponible;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Comptabilite\Modeles\TiersComptable;
use Modules\Noyau\Exploitation\Modeles\Tiers;

/**
 * La numérotation des tiers comptables — 24/11, sur la règle de SELFLOW (§ 3).
 *
 * - Le compte général reste le collectif : 411000 pour un client (assureurs compris), 401000 pour
 *   un fournisseur. Le numéro de tiers est à part, sur 6 chiffres : 410001, 410002… et 400001…
 * - Il est donné une fois, par le serveur, jamais par un formulaire ni un import ; il ne change
 *   plus. Deux orthographes d'un même nom (`Tiers::normaliser`) donnent le même tiers.
 * - Un fournisseur se reconnaît d'abord à son NCC (FNE), puis à son nom.
 * - Sans nom exploitable — sortie de caisse sans bénéficiaire, petite vente au comptant — c'est le
 *   tiers divers de sa nature : 410000 « Client divers », 400000 « Fournisseur divers ».
 * - Un tiers déjà associé à COMPTAFLOW garde le numéro de COMPTAFLOW, qui est maître du
 *   référentiel : on ne le renumérote pas.
 */
final class TiersComptables
{
    public const CLIENT = 'client';

    public const FOURNISSEUR = 'fournisseur';

    private const COLLECTIF = [self::CLIENT => '411000', self::FOURNISSEUR => '401000'];

    private const PREFIXE = [self::CLIENT => '41', self::FOURNISSEUR => '40'];

    private const DIVERS = [self::CLIENT => 'Client divers', self::FOURNISSEUR => 'Fournisseur divers'];

    /** @var array<string, TiersComptable> cache par entreprise|nature|nom */
    private array $cache = [];

    public function __construct(private readonly int $entrepriseId) {}

    /** Le compte collectif de la nature, tel que paramétré (411000 / 401000 par défaut). */
    public static function collectif(string $nature, array $plan = []): string
    {
        $cle = $nature === self::CLIENT ? 'clients' : 'fournisseurs';

        return $plan[$cle]['numero'] ?? self::COLLECTIF[$nature];
    }

    /** Le tiers d'un nom (et d'un NCC pour un fournisseur) — créé au besoin, numéroté une fois. */
    public function de(string $nature, ?string $nom, ?string $ncc = null): TiersComptable
    {
        $ncc = $ncc !== null ? strtoupper(trim($ncc)) : null;
        $ncc = $ncc === '' ? null : $ncc;
        $normalise = Tiers::normaliser((string) $nom);

        if ($normalise === '' && $ncc === null) {
            return $this->divers($nature);
        }

        if ($ncc !== null) {
            $parNcc = $this->requete()->where('nature', $nature)->where('ncc', $ncc)->first();
            if ($parNcc) {
                return $parNcc;
            }
        }

        $cle = $nature.'|'.$normalise;
        if (isset($this->cache[$cle])) {
            return $this->cache[$cle];
        }

        $existant = $normalise !== '' ? $this->requete()->where('nature', $nature)->where('nom_normalise', $normalise)->first() : null;
        if ($existant) {
            if ($ncc !== null && $existant->ncc === null) {
                $existant->forceFill(['ncc' => $ncc])->save();
            }

            return $this->cache[$cle] = $existant;
        }

        if ($normalise === '') {
            return $this->divers($nature);
        }

        return $this->cache[$cle] = DB::transaction(function () use ($nature, $nom, $normalise, $ncc) {
            // Le compteur se lit sous verrou : deux générations simultanées ne prennent pas le même numéro.
            $prefixe = self::PREFIXE[$nature];
            $dernier = $this->requete()->where('nature', $nature)->where('est_divers', false)
                ->where('numero', 'like', $prefixe.'%')->lockForUpdate()->max('numero');
            $suivant = $dernier ? ((int) $dernier) + 1 : (int) ($prefixe.'0001');

            return TiersComptable::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entrepriseId,
                'nature' => $nature,
                'numero' => (string) $suivant,
                'compte_general' => self::COLLECTIF[$nature],
                'nom' => mb_substr(trim((string) $nom), 0, 191),
                'nom_normalise' => mb_substr($normalise, 0, 191),
                'ncc' => $ncc,
                'est_divers' => false,
            ]);
        });
    }

    /** Le tiers divers de la nature : unique par entreprise. */
    public function divers(string $nature): TiersComptable
    {
        $cle = $nature.'|#divers';

        return $this->cache[$cle] ??= TiersComptable::withoutGlobalScopes()->firstOrCreate(
            ['entreprise_id' => $this->entrepriseId, 'nature' => $nature, 'est_divers' => true],
            [
                'numero' => self::PREFIXE[$nature].'0000',
                'compte_general' => self::COLLECTIF[$nature],
                'nom' => self::DIVERS[$nature],
                'nom_normalise' => '#DIVERS',
            ],
        );
    }

    /**
     * Le numéro à porter dans l'écriture : celui de COMPTAFLOW quand le tiers de l'application lui
     * est déjà associé (écran de liaison), sinon le nôtre.
     */
    public function numeroPourComptaflow(TiersComptable $tiers): string
    {
        if ($tiers->est_divers || ! SchemaDisponible::table('correspondances_tiers_comptaflow')) {
            return $tiers->numero;
        }

        $associe = DB::table('correspondances_tiers_comptaflow as c')
            ->join('tiers as t', 't.id', '=', 'c.tiers_id')
            ->where('c.entreprise_id', $this->entrepriseId)
            ->where('t.nom_normalise', $tiers->nom_normalise)
            ->value('c.numero_de_tiers');

        return $associe ?: $tiers->numero;
    }

    private function requete()
    {
        return TiersComptable::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId);
    }
}

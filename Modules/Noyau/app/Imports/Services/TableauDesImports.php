<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;

/**
 * Tous les imports vus ensemble, dans un seul tableau.
 *
 * L'écran de dépôt montrait d'un côté ce qu'on peut importer, de l'autre ce qui a été
 * importé, et il fallait tenir les deux en tête pour savoir où l'on en était. C'est
 * exactement ce qu'il ne faut pas faire d'un tableau de bord : la question que se pose la
 * personne devant l'écran n'est pas « que sait faire le logiciel ? » mais **« qu'est-ce
 * qui manque encore ? »**.
 *
 * Une ligne par type de fichier, donc, et sur chaque ligne tout ce qu'il faut pour
 * répondre : est-ce disponible, l'a-t-on déjà fait, quand, pour quelles villes, et combien
 * de lignes cela a produit en base.
 *
 * La colonne « villes » est celle qui rend service sans qu'on l'ait demandée : elle montre
 * qu'Abidjan a été importée et pas Bouaké, ce qu'aucun compteur global ne dirait.
 */
class TableauDesImports
{
    public function __construct(private int $entrepriseId) {}

    /**
     * Où en est chaque type d'import.
     *
     * @return Collection<int, array{
     *     cle: string, libelle: string, disponible: bool, source: string|null, note: string|null,
     *     table: string|null, lignes_en_base: int|null,
     *     dernier: LotImport|null, lots: int, villes: list<string>, manquantes: list<string>
     * }>
     */
    public function lignes(): Collection
    {
        $lots = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->with('ville')
            ->latest()
            ->get()
            ->groupBy('format');

        $toutesLesVilles = \Modules\Noyau\Entreprises\Modeles\Ville::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('est_actif', true)
            ->orderBy('nom')
            ->pluck('nom')
            ->all();

        $lignes = collect();

        foreach (Registre::DISPONIBLES as $cle => $classe) {
            $lignes->push($this->ligne($cle, $classe::libelle(), true, $lots, $toutesLesVilles));
        }

        foreach (Registre::ANNONCES as $cle => $meta) {
            $lignes->push($this->ligne($cle, $meta['libelle'], false, $lots, $toutesLesVilles, $meta));
        }

        return $lignes;
    }

    /** Ce que chaque type alimente comme table, et combien de lignes s'y trouvent déjà. */
    public const DESTINATIONS = [
        'parc' => 'dossiers_vehicules',
        'devis' => 'devis',
        'factures' => 'factures',
        'impayes' => 'factures',
        'fournisseurs' => 'factures_fournisseurs',
        'caisse' => 'mouvements_caisse',
        // Les entrées et sorties ont bien leur table, mais aucun lecteur : les fichiers
        // sortent du logiciel en PDF. Rien à compter tant que l'export Excel manque.
        'entrees' => 'mouvements_vehicules',
        'sorties' => 'mouvements_vehicules',
    ];

    private function ligne(
        string $cle,
        string $libelle,
        bool $disponible,
        Collection $lots,
        array $toutesLesVilles,
        ?array $meta = null,
    ): array {
        $siens = $lots->get($cle, collect())->filter(fn (LotImport $l) => $l->etat === 'termine');
        $villes = $siens->pluck('ville.nom')->filter()->unique()->values()->all();
        $table = self::DESTINATIONS[$cle] ?? null;

        return [
            'cle' => $cle,
            'libelle' => $libelle,
            'disponible' => $disponible,
            'source' => $meta['source'] ?? null,
            'note' => $meta['note'] ?? null,
            'table' => $table,
            // La table peut ne pas exister encore : c'est justement ce qu'on veut voir.
            'lignes_en_base' => $table !== null && Schema::hasTable($table)
                ? (int) DB::table($table)->where('entreprise_id', $this->entrepriseId)->count()
                : null,
            'dernier' => $siens->first(),
            'lots' => $siens->count(),
            'villes' => $villes,
            'manquantes' => array_values(array_diff($toutesLesVilles, $villes)),
        ];
    }
}

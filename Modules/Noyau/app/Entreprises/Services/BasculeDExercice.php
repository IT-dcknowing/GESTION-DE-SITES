<?php

namespace Modules\Noyau\Entreprises\Services;

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Exercice;

/**
 * Le passage d'une année à la suivante, sans clôture et sans intervention.
 *
 * **La règle a changé, et pour de bonnes raisons.** Auparavant un exercice se clôturait —
 * ville par ville, puis globalement, sur décision du gérant. Le défaut de ce modèle se voit
 * le 1er janvier : tant que personne n'a cliqué, la saisie du jour tombe dans une année qui
 * n'existe pas encore. On ouvrait donc l'année en retard, et les premières écritures
 * partaient au mauvais endroit ou nulle part.
 *
 * Désormais : **l'année suivante s'ouvre toute seule, et la précédente ne se ferme jamais.**
 * Elle reste consultable, corrigeable, comparable. Une facture de décembre qui arrive le
 * 8 janvier se saisit à sa date, sans qu'on ait à rouvrir quoi que ce soit.
 *
 * Ce que la bascule ne fait pas — et c'est délibéré :
 *
 * - elle **ne déplace aucune donnée** : une écriture appartient à l'année de sa date, point ;
 * - elle **ne clôture rien**, donc elle n'interdit rien ;
 * - elle **ne supprime rien**.
 *
 * Elle se contente de créer l'exercice manquant et de déplacer le repère « année courante ».
 * C'est une opération que l'on peut relancer autant de fois qu'on veut sans effet de bord :
 * la deuxième fois, elle ne trouve rien à faire.
 */
class BasculeDExercice
{
    /**
     * S'assure que l'année en cours existe et qu'elle est l'exercice par défaut.
     *
     * Appelée au fil de l'eau, à chaque fois qu'on a besoin de savoir dans quelle année on
     * travaille. C'est le moment le plus sûr : il n'y a pas de tâche planifiée à surveiller,
     * et la première personne qui se connecte le 1er janvier ouvre l'année pour tout le monde.
     *
     * @return array{exercice: Exercice, bascule: bool, precedent: Exercice|null}
     */
    public function assurer(int $entrepriseId, ?int $annee = null): array
    {
        $annee ??= (int) now()->year;

        $precedent = Exercice::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('est_defaut', true)
            ->first();

        // Rien à faire dans l'immense majorité des appels : c'est le cas courant, et il ne
        // doit rien coûter de plus qu'une lecture.
        if ($precedent && (int) $precedent->annee === $annee) {
            return ['exercice' => $precedent, 'bascule' => false, 'precedent' => null];
        }

        return DB::transaction(function () use ($entrepriseId, $annee, $precedent) {
            $exercice = Exercice::withoutGlobalScopes()->firstOrCreate(
                ['entreprise_id' => $entrepriseId, 'annee' => $annee],
                // « Ouvert » et pas autre chose : une année neuve n'a aucune raison d'être
                // fermée, et l'ancienne n'en a aucune de le devenir.
                ['statut' => 'Ouvert', 'est_defaut' => false],
            );

            // Le repère se déplace ; l'année précédente reste ouverte derrière lui.
            Exercice::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)
                ->where('id', '!=', $exercice->id)
                ->update(['est_defaut' => false]);

            $exercice->forceFill(['est_defaut' => true, 'statut' => 'Ouvert'])->save();

            return [
                'exercice' => $exercice,
                // Une vraie bascule, c'est le passage d'une année à une autre — pas la
                // création du tout premier exercice d'une entreprise qui démarre.
                'bascule' => $precedent !== null && (int) $precedent->annee !== $annee,
                'precedent' => $precedent,
            ];
        });
    }

    /**
     * Ce qu'il y a à dire de l'année qui vient de passer.
     *
     * Le module de rapport n'est pas prioritaire, et cette méthode ne prétend pas le
     * remplacer : elle rend les quelques totaux qu'on veut voir en haut d'un écran le jour
     * de la bascule, lus directement dans les tables. Le jour où un vrai rapport existera,
     * il prendra la suite — mais on n'attendra pas ce jour-là pour savoir ce qu'a fait
     * l'année écoulée.
     *
     * @return array<string, int>
     */
    public function bilanDe(int $entrepriseId, int $annee): array
    {
        $somme = fn (string $table, string $colonne = 'montant') => (int) DB::table($table)
            ->where('entreprise_id', $entrepriseId)
            ->whereYear('date', $annee)
            ->sum($colonne);

        $compte = fn (string $table) => (int) DB::table($table)
            ->where('entreprise_id', $entrepriseId)
            ->whereYear('date', $annee)
            ->count();

        $ca = $somme('factures');
        $charges = $somme('charges');

        return [
            'annee' => $annee,
            'chiffre_affaires' => $ca,
            'charges' => $charges,
            'resultat' => $ca - $charges,
            'encaissements' => $somme('encaissements'),
            'factures' => $compte('factures'),
            'devis' => (int) DB::table('devis')
                ->where('entreprise_id', $entrepriseId)
                ->whereYear('date_emission', $annee)
                ->count(),
            // La créance qui traverse l'année : ce qui reste dû sur les factures émises.
            'reste_du' => max(0, $ca - $somme('encaissements')),
        ];
    }
}

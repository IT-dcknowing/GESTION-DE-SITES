<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ce que la base du serveur porte déjà — pour qu'un écran ne tombe pas parce qu'une migration
 * n'a pas encore été passée.
 *
 * **Le constat du 09/10.** `/banques` est tombé en ligne (ERR-XEYTWV) alors qu'il passait
 * partout en local. Le code mis à jour lisait des tables neuves (`libelles_de_banque`,
 * `mouvements_bancaires`) et une colonne neuve (`charges.mouvement_caisse_id`) ; tant que
 * `php artisan app:deployer` n'a pas tourné, la page plante au lieu d'attendre. Les écrans
 * qui dépendent d'une migration récente demandent donc ici si elle est passée, et se
 * contentent de ne pas montrer la nouveauté si elle ne l'est pas. La page Maintenance, elle,
 * liste les migrations en attente.
 *
 * Mémorisé pour la requête : une seule question à la base par table.
 */
final class SchemaDisponible
{
    /** @var array<string, bool> */
    private static array $connus = [];

    public static function table(string $table): bool
    {
        return self::$connus['t:'.$table] ??= self::demander(fn () => Schema::hasTable($table));
    }

    public static function colonne(string $table, string $colonne): bool
    {
        return self::$connus['c:'.$table.'.'.$colonne] ??= self::demander(fn () => Schema::hasColumn($table, $colonne));
    }

    /** Pour les tests, qui créent et défont le schéma d'un test à l'autre. */
    public static function oublier(): void
    {
        self::$connus = [];
    }

    private static function demander(callable $question): bool
    {
        try {
            return (bool) $question();
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un dépôt se souvient d'avoir été demandé « pour vérifier » — additive, aucune donnée touchée.
 *
 * L'intention ne vivait que le temps de la requête : « contrôler » ou « importer » était un
 * argument passé à la tâche, et rien n'en restait sur le lot. Tant que la lecture démarrait dans
 * la foulée, cela suffisait. Ce n'est plus vrai depuis qu'un dépôt endormi peut être relancé
 * plus tard, par la tâche planifiée ou par l'écran qui le regarde : sans cette colonne, une
 * relance transformerait en écriture ce qui avait été demandé comme une simulation.
 *
 * Les lots déjà en base reçoivent `false` : c'est le cas le plus fréquent, et c'est aussi le
 * seul que leur état permette de relancer sans surprise — un contrôle déjà passé est terminé.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lots_import', 'controle')) {
            return;
        }

        Schema::table('lots_import', function (Blueprint $table) {
            $table->boolean('controle')->default(false)->after('format');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lots_import', 'controle')) {
            return;
        }

        Schema::table('lots_import', fn (Blueprint $table) => $table->dropColumn('controle'));
    }
};

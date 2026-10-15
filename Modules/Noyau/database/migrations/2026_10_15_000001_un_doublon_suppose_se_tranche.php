<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un doublon supposé dit pourquoi, et garde la décision qu'on a prise — 15/10.
 *
 * « À un premier import, on n'écarte rien : s'il y a des doublons, un tableau dit que ces éléments
 * sont supposés doublons à preuve du contraire, à cause de tel ou tel élément (une colonne), et on
 * demande de choisir par un bouton et de retirer l'autre. »
 *
 * `raison` : ce que les deux lignes ont en commun (et ce qui les distingue). `decision` : laquelle on
 * a gardée — ou les deux. La décision sert au dépôt suivant : une ligne retirée ne revient pas.
 * Additive : quatre colonnes nullables, aucune ligne lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('doublons_import') || Schema::hasColumn('doublons_import', 'raison')) {
            return;
        }

        Schema::table('doublons_import', function (Blueprint $table) {
            $table->text('raison')->nullable()->after('libelle');
            $table->string('decision', 30)->nullable()->after('statut');
            $table->foreignId('decide_par')->nullable()->after('decision')->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable()->after('decide_par');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('doublons_import', 'raison')) {
            Schema::table('doublons_import', function (Blueprint $table) {
                $table->dropConstrainedForeignId('decide_par');
                $table->dropColumn(['raison', 'decision', 'decide_le']);
            });
        }
    }
};

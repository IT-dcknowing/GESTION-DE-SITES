<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'annulation d'un import terminé.
 *
 * La barre latérale promettait qu'« aucun import n'écrit à moitié ». C'était vrai de
 * l'import qui *échoue* — la transaction s'en charge — et muet sur le cas fréquent : celui
 * qui *réussit* avec le mauvais fichier ou la mauvaise ville. Il n'existait alors aucun
 * moyen de revenir en arrière.
 *
 * Deux colonnes, purement additives : qui a annulé, et quand. Le motif, lui, rejoint le
 * message du lot — il se lit à l'endroit où l'on regarde déjà ce qui s'est passé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            if (! Schema::hasColumn('lots_import', 'annule_le')) {
                $table->timestamp('annule_le')->nullable()->after('termine_le');
            }

            if (! Schema::hasColumn('lots_import', 'annule_par')) {
                $table->foreignId('annule_par')->nullable()->after('annule_le')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            if (Schema::hasColumn('lots_import', 'annule_par')) {
                $table->dropConstrainedForeignId('annule_par');
            }

            if (Schema::hasColumn('lots_import', 'annule_le')) {
                $table->dropColumn('annule_le');
            }
        });
    }
};

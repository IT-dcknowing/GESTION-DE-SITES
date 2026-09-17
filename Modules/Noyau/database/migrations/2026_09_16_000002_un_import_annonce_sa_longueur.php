<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un import annonce combien de lignes il va lire.
 *
 * La barre de progression « ne visait rien » : la longueur d'un fichier n'était connue qu'une
 * fois lu, et l'écran montrait une jauge figée aux deux tiers. Un classeur `.xlsx` l'écrit
 * pourtant dès son en-tête — `<dimension ref="A1:T9108"/>` — et on peut la lire sans ouvrir
 * une seule ligne. Elle est rangée ici au démarrage du traitement, et la barre avance enfin
 * vers quelque chose.
 *
 * Nullable : un `.xls` ne la donne pas aussi simplement, et un lot d'avant cette colonne n'en
 * a pas. La barre retombe alors sur un compteur sans pourcentage, et le dit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            $table->unsignedInteger('lignes_estimees')->nullable()->after('lignes_lues');
        });
    }

    public function down(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            $table->dropColumn('lignes_estimees');
        });
    }
};

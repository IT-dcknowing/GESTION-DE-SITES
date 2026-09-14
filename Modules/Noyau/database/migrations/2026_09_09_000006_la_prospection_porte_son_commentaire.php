<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le commentaire que le commercial adresse à son responsable.
 *
 * **Le champ existait à l'écran et nulle part ailleurs.** Le formulaire de prospection
 * portait, depuis le début, une zone intitulée « Commentaire à l'attention de votre
 * responsable ». On y écrivait, on enregistrait, et le texte disparaissait : rien ne le
 * recevait en base. Le responsable voyait donc arriver des prospections nues, et le
 * commercial croyait avoir expliqué son terrain.
 *
 * C'est le genre de défaut qui ne se voit pas : personne ne réclame un commentaire qu'il
 * ne sait pas manquant, et celui qui l'écrit n'a aucun moyen de savoir qu'il se perd.
 *
 * Deux colonnes, et non une, parce que ce sont deux choses différentes :
 *
 * - `observations` décrit la **prospection** — ce qui s'est passé sur le terrain. Elle
 *   existait déjà et ne change pas.
 * - `commentaire` s'adresse à **quelqu'un** — c'est un message, daté, qui accompagne la
 *   transmission. Le confondre avec le premier ferait disparaître l'un des deux à chaque
 *   fois qu'on écrit l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospections', function (Blueprint $table) {
            if (! Schema::hasColumn('prospections', 'commentaire')) {
                $table->text('commentaire')->nullable()->after('observations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prospections', function (Blueprint $table) {
            if (Schema::hasColumn('prospections', 'commentaire')) {
                $table->dropColumn('commentaire');
            }
        });
    }
};

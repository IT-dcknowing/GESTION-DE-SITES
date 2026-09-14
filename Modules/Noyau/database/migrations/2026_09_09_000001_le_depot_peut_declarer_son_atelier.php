<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un dépôt peut désormais déclarer l'atelier dont son fichier a été extrait.
 *
 * **Ce que change cette colonne, et pourquoi elle vaut mieux que tout le reste du
 * rattachement.**
 *
 * Jusqu'ici, ranger une ligne d'Abidjan dans le bon atelier était le problème le plus dur
 * du module : la colonne SITE du logiciel dit « ABIDJAN » et s'arrête là, alors qu'Abidjan
 * compte deux ateliers. Le seul discriminant restant était le code de deux lettres de la
 * personne qui avait rédigé la fiche — un indice indirect, incomplet, et qu'il fallait
 * rattacher à la main.
 *
 * Il se trouve que le logiciel d'atelier sait filtrer son extraction **par site avant de
 * l'exporter**. Le fichier déposé peut donc ne contenir qu'un seul atelier, et celui qui
 * l'a extrait le sait avec certitude. Cette colonne recueille cette certitude.
 *
 * Elle devient la source d'autorité la plus haute du rattachement, au-dessus même de la
 * colonne SITE : une déclaration humaine sur l'origine d'un fichier entier bat une colonne
 * qui ne descend pas jusqu'à l'atelier.
 *
 * **Elle reste facultative**, et c'est délibéré : Bouaké et San Pédro n'ont qu'un site, la
 * ville suffit à les désigner ; et un fichier ancien, extrait sans filtre, doit continuer
 * de s'importer par la cascade habituelle.
 *
 * Effet de bord recherché : quand l'atelier est déclaré, les codes rencontrés dans ce
 * fichier appartiennent forcément à cet atelier. Le module en profite pour les rattacher
 * tout seul, ce qui répond enfin à la question restée ouverte — quel code travaille où.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('ville_id')
                ->constrained('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};

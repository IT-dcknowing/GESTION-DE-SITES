<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux décisions du propriétaire, prises le 22/09/2026.
 *
 * **1. Le numéro du devis se saisit au moment où l'on déclare le passage en devis.**
 * Jusqu'ici, le rapprochement prospection / devis se faisait après coup, par la plaque et la
 * date : c'était la seule voie, puisque rien ne reliait les deux. Le propriétaire tranche
 * autrement — quand le commercial coche « devis après passage », il tient le devis, et il
 * peut en donner le numéro. Le champ devient donc **obligatoire à cet instant-là**, et le
 * rapprochement n'a plus rien à deviner.
 *
 * **Pourquoi le numéro du devis plutôt que celui de la fiche de réception.** Les deux
 * existent et les deux sont réels : un devis importé porte « PR-MT-11434 » et cite la fiche
 * « FR-MTN° 010136 ». Mais le numéro de devis **désigne un devis et un seul**, tandis qu'une
 * fiche de réception peut en porter plusieurs — c'est le dossier du véhicule, pas la pièce.
 * Le champ s'appelle donc « n° de devis » ; le rapprochement accepte néanmoins qu'on y ait
 * écrit un numéro de fiche, parce qu'un commercial qui n'a que la fiche sous les yeux ne doit
 * pas être bloqué.
 *
 * **2. Le barème tient à son exercice, et non à une date d'effet.** « La commission est
 * appliquée par exercice, donc elle doit être cloisonnée dans son exercice et s'appliquer
 * directement même sur les anciens exercices. » La date d'effet répondait à une autre
 * question — « depuis quand ? » — là où la vraie est « pour quelle année ? ». Une grille
 * corrigée s'applique aussitôt à tout son exercice, passé compris, et ne touche pas aux
 * autres.
 *
 * `date_effet` reste en place, tenue au 1er janvier de l'exercice : elle ne sert plus à
 * choisir la grille, seulement à honorer l'index d'unicité posé la veille.
 *
 * **Migration additive** : deux colonnes nullables, aucune ligne réécrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospections', function (Blueprint $table) {
            // Le numéro du devis issu de la visite. Nullable en base : une prospection sans
            // devis n'en a pas. C'est le formulaire qui l'exige, et seulement quand le
            // passage en devis est déclaré — une contrainte de base l'imposerait aussi aux
            // prospections qui n'ont rien produit.
            $table->string('n_devis', 60)->nullable()->after('n_fiche_reception');
            $table->index(['entreprise_id', 'n_devis'], 'prospections_devis_idx');
        });

        Schema::table('baremes_commission', function (Blueprint $table) {
            // L'année de l'exercice que cette grille rémunère.
            $table->unsignedSmallInteger('exercice')->nullable()->after('cible');
            $table->unique(['entreprise_id', 'cible', 'exercice'], 'bareme_cible_exercice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('baremes_commission', function (Blueprint $table) {
            $table->dropUnique('bareme_cible_exercice_unique');
            $table->dropColumn('exercice');
        });

        Schema::table('prospections', function (Blueprint $table) {
            $table->dropIndex('prospections_devis_idx');
            $table->dropColumn('n_devis');
        });
    }
};

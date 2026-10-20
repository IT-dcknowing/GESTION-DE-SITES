<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un règlement fournisseur dit la sortie de caisse qui le paie — 20/10.
 *
 * « Les fournisseurs ne font que retracer, c'est juste un suivi : chaque règlement est enregistré
 * comme un suivi ; si la caisse fait un règlement fournisseur et qu'on veut savoir, le suivi
 * fournisseur peut nous aider. » La sortie de caisse est la charge ; le règlement au suivi dit
 * quelle pièce elle a payée. Rien ne compte deux fois.
 *
 * Additive : une colonne nullable, aucune ligne lue ni réécrite. Pas d'unicité — une sortie peut
 * régler plusieurs pièces d'un même fournisseur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reglements_fournisseur') || Schema::hasColumn('reglements_fournisseur', 'mouvement_caisse_id')) {
            return;
        }

        Schema::table('reglements_fournisseur', function (Blueprint $table) {
            $table->foreignId('mouvement_caisse_id')->nullable()->constrained('mouvements_caisse')->nullOnDelete();
            $table->index(['entreprise_id', 'mouvement_caisse_id'], 'reglements_fournisseur_caisse');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('reglements_fournisseur', 'mouvement_caisse_id')) {
            Schema::table('reglements_fournisseur', function (Blueprint $table) {
                $table->dropIndex('reglements_fournisseur_caisse');
                $table->dropConstrainedForeignId('mouvement_caisse_id');
            });
        }
    }
};

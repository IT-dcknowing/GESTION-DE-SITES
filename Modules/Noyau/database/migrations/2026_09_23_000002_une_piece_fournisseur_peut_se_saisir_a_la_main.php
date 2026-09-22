<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une pièce fournisseur peut désormais naître ailleurs que dans un fichier.
 *
 * **Ce qui manquait.** Le suivi fournisseur n'entrait que par l'import. Une facture reçue
 * entre deux dépôts n'avait nulle part où aller, et la seule façon de la faire exister
 * était d'attendre qu'elle paraisse dans le classeur du mois suivant. Le plan demandait un
 * bouton d'ajout à la main ; il suppose qu'on sache **qui** a saisi.
 *
 * `lot_import_id` disait déjà d'où vient une ligne : un lot pour ce qui a été importé, rien
 * pour ce qui ne l'a pas été. La colonne ajoutée ici complète la seconde moitié de la
 * réponse — et seulement elle. On ne la remplit pas pour les lignes importées : leur auteur
 * n'est pas une personne, c'est un fichier, et le dire autrement serait attribuer à
 * quelqu'un une saisie qu'il n'a pas faite.
 *
 * Additive et réversible : une colonne nullable sur une table qui existe. Les 1 848 lignes
 * déjà reprises ne sont pas touchées et ne le seront jamais par cette colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('factures_fournisseurs', 'user_id')) {
            return;
        }

        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            // `nullOnDelete` et non `cascade` : le départ d'un comptable ne doit pas
            // emporter les dettes qu'il a consignées. La ligne reste, son auteur s'efface.
            $table->foreignId('user_id')->nullable()->after('lot_import_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('factures_fournisseurs', 'user_id')) {
            return;
        }

        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

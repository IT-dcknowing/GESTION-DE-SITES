<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un avoir dit la facture qu'il corrige — 27/10.
 *
 * « L'avoir se fait sur une facture initiale : un bouton Avoir, on choisit le client, puis la ou
 * les factures, et l'on saisit le montant — jamais supérieur au montant des factures choisies. »
 * Pour tenir ce plafond, l'avoir doit savoir sur quelle facture il porte.
 *
 * Additive : une colonne nullable ; les avoirs d'avant restent sans facture, rien n'est réécrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('factures', 'avoir_de_id')) {
            return;
        }

        Schema::table('factures', function (Blueprint $table) {
            $table->foreignId('avoir_de_id')->nullable()->after('est_avoir')
                ->constrained('factures')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('factures', 'avoir_de_id')) {
            return;
        }

        Schema::table('factures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avoir_de_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un commercial reconnaît lui-même les factures nées de ses prospections.
 *
 * **Le trou que cela comble, mesuré le 05/10 en local** : 11 229 factures sur 11 332 ne
 * portent aucun commercial. Avant le 22/09, une prospection passée en devis ne disait pas
 * quel devis elle avait produit ; aucune des pistes du rapprochement (fiche, plaque, nom)
 * ne peut donc retrouver ces affaires-là avec certitude. Le seul à savoir est celui qui a
 * fait la visite : l'écran « Correspondances » le lui demande, facture par facture.
 *
 * **Pourquoi une table, et pas seulement `factures.commercial_id`.** La colonne dit à qui
 * la facture est comptée aujourd'hui ; elle ne dit ni qui l'a cochée, ni quand, ni qu'une
 * affectation a été annulée puis refaite. Or c'est précisément ce qu'un responsable doit
 * pouvoir lire avant d'annuler. Une ligne par geste, jamais effacée : l'annulation se pose
 * par-dessus.
 *
 * Additive : une table neuve, aucune colonne touchée, aucune ligne existante lue ni réécrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('correspondances_factures')) {
            return;
        }

        Schema::create('correspondances_factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignId('commercial_id')->constrained('commerciaux')->cascadeOnDelete();

            // Le compte qui a coché, et son nom au moment du geste : un compte supprimé
            // depuis ne doit pas rendre la ligne muette.
            $table->foreignId('coche_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auteur', 160)->nullable();

            // L'annulation, réservée aux responsables. Nulle tant que l'affectation tient.
            $table->timestamp('annulee_le')->nullable();
            $table->foreignId('annulee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('annulee_par_nom', 160)->nullable();
            $table->string('motif_annulation', 255)->nullable();

            $table->timestamps();

            $table->index(['entreprise_id', 'facture_id'], 'correspondances_factures_facture_index');
            $table->index(['entreprise_id', 'commercial_id'], 'correspondances_factures_commercial_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondances_factures');
    }
};

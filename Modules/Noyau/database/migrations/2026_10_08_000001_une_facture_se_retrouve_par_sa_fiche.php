<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un index sur la fiche de réception que porte la facture.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Le 08/10**, le taux de transformation des devis se met à lire si une facture suit chaque
 * devis — notamment par **la même fiche de réception** (`factures.reference_devis`, qui tient
 * la fiche dans le CATTC). Voir `TransformationDesDevis`. Sans index, chaque changement de
 * filtre de l'accueil du gérant parcourrait les onze mille factures pour chaque paquet de
 * fiches.
 *
 * **Additive** : un index. Aucune ligne n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->index(['entreprise_id', 'reference_devis'], 'factures_fiche_idx');
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_fiche_idx');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une facture fournisseur peut être reçue par une ville et commandée par une autre — 13/10.
 *
 * « Une facture d'une autre ville dans le fichier d'une ville, ce n'est pas une erreur : la ville
 * a passé une commande, mais c'est l'autre ville qui a reçu la facture, et elle ne fait que
 * l'enregistrer au nom de celle qui a commandé. Si tu vois une ligne de Bouaké dans le fichier
 * d'Abidjan, enregistre-la à Bouaké, et marque qu'elle vient d'Abidjan. »
 *
 * `ville_id` reste la ville qui a commandé — c'est à elle que la dette appartient. Cette colonne
 * dit **qui l'a reçue** quand ce n'est pas la même ville. Additive : une colonne nullable, rien
 * n'est lu ni écrit sur les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('factures_fournisseurs', 'recue_par_ville_id')) {
            return;
        }

        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            $table->foreignId('recue_par_ville_id')->nullable()->after('site_id')
                ->constrained('villes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('factures_fournisseurs', 'recue_par_ville_id')) {
            Schema::table('factures_fournisseurs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('recue_par_ville_id');
            });
        }
    }
};

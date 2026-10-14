<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une ligne qu'un import a reconnue comme doublon se garde, au lieu de disparaître — 14/10.
 *
 * « Lors de l'import, on importe tout ; dans la page, un KPI fait la différence entre ce qui a été
 * importé et le montant correct, et un tableau montre les lignes considérées comme doublons, avec
 * un bouton pour les intégrer si en réalité ce n'en sont pas. »
 *
 * Jusqu'ici, une ligne répétée dans le même fichier (même numéro de facture, même mouvement de
 * caisse…) **réécrivait** la première : elle n'était plus nulle part, et le total de l'écran
 * s'écartait de celui du fichier sans le dire — 10 000 F sur le CA du 12/10, le doublon
 * FA -8730. Elle est désormais écrite ici, avec ses valeurs, et attend qu'on décide.
 *
 * Additive : une table neuve, rien n'est lu ni écrit ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('doublons_import')) {
            return;
        }

        Schema::create('doublons_import', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();
            // La clé du format (`factures`, `caisse`…) : c'est elle qui sait réintégrer la ligne.
            $table->string('format', 40);
            // Ce qui identifie la ligne d'un dépôt à l'autre : redéposer le fichier met la ligne
            // à jour, il ne la recopie pas.
            $table->string('empreinte', 64);
            $table->unsignedInteger('numero_ligne')->nullable();
            $table->string('feuille', 60)->nullable();

            // Ce qu'on en montre à l'écran, sans relire le fichier.
            $table->string('reference', 120)->nullable();
            $table->date('date')->nullable();
            $table->string('libelle', 255)->nullable();
            $table->decimal('montant', 18, 4)->nullable();
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            // La ligne gardée à sa place : sa table et son identifiant.
            $table->string('retenue_table', 60)->nullable();
            $table->unsignedBigInteger('retenue_id')->nullable();

            // La ligne telle que l'import l'a lue, et où il l'aurait rangée : de quoi l'écrire
            // à l'identique le jour où l'on décide que ce n'était pas un doublon.
            $table->json('ligne');
            $table->json('rattachement')->nullable();

            // `ecarte` tant que personne n'a décidé ; `integre` une fois écrite à part entière.
            $table->string('statut', 20)->default('ecarte');
            $table->unsignedBigInteger('integre_id')->nullable();
            $table->foreignId('integre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('integre_le')->nullable();

            $table->timestamps();

            $table->unique(['entreprise_id', 'format', 'empreinte'], 'doublons_import_empreinte');
            $table->index(['entreprise_id', 'format', 'statut'], 'doublons_import_format_statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doublons_import');
    }
};

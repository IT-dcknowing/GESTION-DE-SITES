<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le dépôt du module Rapport — 06/11.
 *
 * « Prévoir l'espace de dépôt des autres fichiers » : l'avis des impôts, la CNPS, les récapitulatifs
 * que le gérant ajoute à la main. Le système les lit **sans doublon**, même si un récapitulatif
 * reprend ce qui est déjà là.
 *
 * | Table | Ce qu'elle tient |
 * |---|---|
 * | `depots_rapport` | chaque fichier déposé, avec son empreinte (SHA-256) : le même contenu ne passe pas deux fois |
 * | `pieces_rapport` | chaque ligne lue (impôt, CNPS, salaire, récap…), avec son empreinte : une pièce déjà connue ne se recrée pas |
 *
 * **Additive** : deux tables neuves. Aucune ligne existante n'est lue ni écrite. Une pièce de nature
 * « récap » ne compte jamais : elle ne sert qu'à contrôler les chiffres de l'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depots_rapport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nom_original', 255);
            $table->string('chemin', 500);
            $table->string('empreinte_fichier', 64);
            $table->string('nature_declaree', 20)->nullable();
            $table->string('statut', 20)->default('lu'); // lu | a_completer | erreur
            $table->unsignedInteger('nb_lignes')->default(0);
            $table->unsignedInteger('nb_nouvelles')->default(0);
            $table->unsignedInteger('nb_doublons')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'empreinte_fichier'], 'depots_rapport_empreinte');
        });

        Schema::create('pieces_rapport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('depot_rapport_id')->nullable()->constrained('depots_rapport')->nullOnDelete();
            $table->string('nature', 20);   // impot | cnps | salaire | recap | autre
            $table->string('statut', 10);   // du | paye | info
            $table->unsignedSmallInteger('exercice');
            $table->date('date_piece')->nullable();
            $table->string('libelle', 200);
            $table->string('tiers', 200)->nullable();
            $table->string('reference', 80)->nullable();
            $table->bigInteger('montant');
            $table->boolean('compte')->default(true);
            $table->boolean('saisie_manuelle')->default(false);
            $table->string('empreinte', 40);
            $table->timestamps();

            $table->unique(['entreprise_id', 'empreinte'], 'pieces_rapport_empreinte');
            $table->index(['entreprise_id', 'exercice', 'nature'], 'pieces_rapport_lecture');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_rapport');
        Schema::dropIfExists('depots_rapport');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'IA de l'application — 10/11. Trois tables neuves, rien de modifié.
 *
 * | Table | Ce qu'elle tient |
 * |---|---|
 * | `affectations_ia` | pour chaque **usage** (`rapport`, `imputations`), le fournisseur qui le sert (parmi ceux de `configurations_ia`, qui gardent les clés chiffrées), un modèle facultatif et les instructions de l'usage |
 * | `conversations_ia` | la conversation d'un compte avec l'assistant d'un usage |
 * | `productions_ia` | ce que l'assistant a produit : une analyse globale (`analyse`, qu'on peut retirer d'un clic pour « revenir à l'état du système ») ou un document (`document`, qui s'imprime en PDF) |
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectations_ia', function (Blueprint $table) {
            $table->id();
            $table->string('usage', 30)->unique();
            $table->string('fournisseur', 40);
            $table->string('modele', 150)->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('conversations_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('usage', 30);
            $table->longText('messages')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'user_id', 'usage'], 'conversations_ia_compte');
        });

        Schema::create('productions_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('usage', 30)->default('rapport');
            $table->string('type', 12);            // analyse | document
            $table->unsignedSmallInteger('exercice')->nullable();
            $table->string('titre', 200);
            $table->longText('contenu');           // Markdown
            $table->text('style')->nullable();     // JSON : accent, fond, mise_en_page (liste blanche)
            $table->timestamps();

            $table->index(['entreprise_id', 'usage', 'type', 'exercice'], 'productions_ia_lecture');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productions_ia');
        Schema::dropIfExists('conversations_ia');
        Schema::dropIfExists('affectations_ia');
    }
};

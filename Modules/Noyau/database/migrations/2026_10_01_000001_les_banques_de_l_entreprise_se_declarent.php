<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les banques de l'entreprise se déclarent.
 *
 * **Pourquoi une table, et pas la colonne de texte qui existe déjà.** `factures.banque` porte
 * aujourd'hui le nom de la banque, en toutes lettres, saisi à la main. Relevé le 01/10 :
 *
 * | Ce qui est propre | Ce qui ne l'est pas |
 * |---|---|
 * | BGFI 5 472 · BNI 287 · BDA 106 · AFG 77 | `BGFI+BNI`, `BGFI/BGFI`, `BGFIU`, `BNI/BGFI`, `BNI+BGFI`, `BGFI/AFG`, `BGFI/BNI`, `234665`, `CAISSE`, `wave` |
 *
 * Dix valeurs sur quatorze ne désignent pas une banque, ou en désignent deux. `BGFIU` est une
 * faute de frappe, `234665` un numéro tombé dans la mauvaise case, `CAISSE` et `wave` des
 * supports qui ne sont pas des banques. Tant que le nom est un champ libre, la question
 * « combien ai-je à la BGFI » n'a pas de réponse calculable.
 *
 * **Une banque appartient à l'entreprise, pas à un atelier**, et le logiciel comptable le
 * confirme : son écran de pièces demande le compte bancaire **sans** demander de site. La
 * ville reste donc facultative — elle sert à proposer d'abord les bonnes banques à qui
 * travaille dans une ville, jamais à écarter une écriture.
 *
 * **Additive, et elle n'écrit aucune donnée.** Les banques se déclarent par un geste, à
 * l'écran ou au dépôt d'un relevé. Les poser d'office depuis `factures.banque` reviendrait à
 * créer `BGFIU` et `234665` comme banques de l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();

            // Le nom tel qu'on l'écrit sur les documents, et le nom réduit qui sert à
            // reconnaître une saisie — même mécanique que `referentiel_fournisseurs`.
            $table->string('nom');
            $table->string('nom_normalise')->index();

            // Un code court, pour les colonnes étroites et les exports.
            $table->string('code', 16)->nullable();

            // Facultative : une banque peut servir toutes les villes, et c'est le cas
            // ordinaire. Elle sert à proposer, pas à restreindre.
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();

            $table->boolean('est_active')->default(true);
            $table->text('note')->nullable();

            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Deux banques de même nom dans une entreprise n'ont aucun sens : c'est le même
            // établissement, et leurs totaux doivent se rejoindre.
            $table->unique(['entreprise_id', 'nom_normalise']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banques');
    }
};

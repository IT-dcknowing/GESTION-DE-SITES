<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le relevé bancaire tel que la caissière le tient — une table à lui.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Le constat du 07/10, fait avec celle qui tient la caisse.** On avait préparé l'import des
 * banques sur les champs du logiciel WinDev (`pieces_bancaires`). Mais elle n'est pas à jour
 * dans les banques du logiciel : elle ne l'est que dans la caisse. Pour suivre les banques,
 * elle exporte les transactions de la banque et les recopie dans un classeur où elle ajoute
 * ses colonnes. **Ce classeur est le modèle à importer** ; l'import du logiciel reste, au cas
 * où.
 *
 * **Pourquoi une table et non des colonnes de plus sur `pieces_bancaires`.** Ce ne sont pas
 * les mêmes choses : une pièce du logiciel est une écriture comptable, avec son code ; une
 * ligne de relevé est une opération de la banque, avec le **solde** qu'elle annonce après
 * elle. Les mêler ferait additionner deux fois le même argent le jour où les deux sources
 * seront importées.
 *
 * Mesuré sur les quatre classeurs BGFI de 2023 à 2026 (6 829 opérations) : en prenant pour
 * opération toute ligne qui porte un montant, et pour suite du libellé toute ligne qui n'en
 * porte pas, **la chaîne des soldes tient sans une rupture**, et chaque année repart du solde
 * où la précédente s'arrête. D'où `solde_annonce` et `rang` : c'est ce qui permet de le
 * vérifier encore après l'import.
 *
 * **Additive** : une table neuve. Aucune ligne existante n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_bancaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            // Le compte — déclaré au dépôt : le classeur ne le nomme que dans son en-tête.
            $table->foreignId('banque_id')->constrained('banques')->cascadeOnDelete();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            $table->date('date_operation');
            $table->string('libelle', 255);
            // Les lignes « MOTIF : … », « Échéance N° … », « TVA / Int. … » qui suivent une
            // opération et la précisent.
            $table->text('motif')->nullable();
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            $table->string('sens', 10);
            // Signé : le relevé 2026 finit à −3 001 551 F. Un découvert n'est pas une erreur.
            $table->bigInteger('solde_annonce')->nullable();

            // Les colonnes ajoutées par la caissière : F depuis 2025, G en 2026.
            $table->string('contrepartie', 160)->nullable();
            $table->string('nature', 160)->nullable();

            // L'ordre dans le fichier, pour suivre la chaîne des soldes d'un même jour.
            $table->unsignedInteger('rang')->default(0);

            // Ce qui identifie l'opération d'un dépôt à l'autre — voir le format.
            $table->string('cle', 64);

            $table->timestamps();

            $table->unique(['banque_id', 'cle'], 'mouvements_bancaires_cle');
            $table->index(['entreprise_id', 'date_operation'], 'mouvements_bancaires_date');
            $table->index(['banque_id', 'date_operation', 'rang'], 'mouvements_bancaires_chaine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_bancaires');
    }
};

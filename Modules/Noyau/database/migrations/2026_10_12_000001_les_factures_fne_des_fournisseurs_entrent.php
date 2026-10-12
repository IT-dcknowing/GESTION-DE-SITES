<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les factures d'achat normalisées (FNE), relevées au portail de la DGI.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Le module des fournisseurs, décidé le 12/10.** Le logiciel d'atelier n'exporte pas les
 * factures fournisseurs ; la DGI, si : chaque facture émise par un fournisseur porte le NCC de
 * L'Artisan, et le portail FNE les rend toutes, **avoirs compris**. Le relevé est fait par le
 * script de Selflow (`SCRAPER-PORTAIL-FNE/achats.js`), repris tel quel : il dépose un fichier
 * JSON, l'application le lit.
 *
 * Trois tables :
 *
 * | Table | Ce qu'elle tient |
 * |---|---|
 * | `releves_fne` | chaque fichier lu — son empreinte : un fichier relu ne s'importe pas deux fois |
 * | `factures_fne` | une facture du portail par ligne, unique par son numéro FNE, **rangée dans son exercice**, liée à la ligne du suivi qu'elle confirme |
 * | `reglements_fournisseur.facture_fournisseur_id` | le **lettrage** : un règlement saisi par « Régler » désigne la facture qu'il solde |
 *
 * **Additive** : deux tables neuves et une colonne nullable. Aucune ligne existante n'est lue
 * ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releves_fne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->string('login', 60);
            $table->string('fichier', 255);
            $table->string('empreinte', 64);
            $table->date('periode_du')->nullable();
            $table->date('periode_au')->nullable();
            $table->unsignedInteger('factures')->default(0);
            $table->unsignedInteger('nouvelles')->default(0);
            $table->unsignedBigInteger('depose_par')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'empreinte'], 'releves_fne_empreinte');
        });

        Schema::create('factures_fne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('releve_fne_id')->nullable()->constrained('releves_fne')->nullOnDelete();
            // La ligne du suivi fournisseurs que cette facture confirme — posée par le
            // rapprochement ou par « Porter au suivi ».
            $table->foreignId('facture_fournisseur_id')->nullable()->constrained('factures_fournisseurs')->nullOnDelete();

            $table->string('reference', 80);          // le numéro FNE
            $table->string('fne_id', 80)->nullable();
            $table->string('type', 40)->nullable();
            $table->string('sous_type', 40)->nullable();
            $table->boolean('est_avoir')->default(false);
            $table->string('statut_portail', 40)->nullable();

            $table->date('date_facture')->nullable();
            // L'exercice de la facture : l'année de sa date, comme à l'état des impayés.
            $table->unsignedSmallInteger('exercice')->nullable();

            $table->string('emetteur_ncc', 40)->nullable();
            $table->string('emetteur_nom', 200)->nullable();

            // Signés : un avoir porte des montants négatifs.
            $table->bigInteger('montant_ht')->default(0);
            $table->bigInteger('montant_tva')->default(0);
            $table->bigInteger('timbre')->default(0);
            $table->bigInteger('montant_ttc')->default(0);
            $table->string('moyen_paiement', 60)->nullable();

            $table->json('contenu')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'reference'], 'factures_fne_reference');
            $table->index(['entreprise_id', 'exercice'], 'factures_fne_exercice');
        });

        Schema::table('reglements_fournisseur', function (Blueprint $table) {
            $table->foreignId('facture_fournisseur_id')->nullable()->after('lot_import_id')
                ->constrained('factures_fournisseurs')->nullOnDelete();
            $table->unsignedBigInteger('saisi_par')->nullable()->after('facture_fournisseur_id');
        });
    }

    public function down(): void
    {
        Schema::table('reglements_fournisseur', function (Blueprint $table) {
            $table->dropForeign(['facture_fournisseur_id']);
            $table->dropColumn(['facture_fournisseur_id', 'saisi_par']);
        });
        Schema::dropIfExists('factures_fne');
        Schema::dropIfExists('releves_fne');
    }
};

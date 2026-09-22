<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les deux exports fournisseurs du logiciel comptable entrent dans l'application.
 *
 * **Ce qui manquait.** Le suivi fournisseur tenu à la main était lu depuis longtemps, mais
 * il est tenu par quelqu'un : il dit ce que l'atelier croit devoir. Les deux exports du
 * logiciel disent ce que la comptabilité a enregistré — la **balance** (débit, crédit,
 * solde par fournisseur) et les **règlements** (chaque paiement, avec son code et son mode).
 * Sans eux, rien ne permettait de confronter les deux, et c'est précisément l'écart entre
 * les deux qui se cherche quand un fournisseur réclame.
 *
 * **Deux tables, et non des colonnes de plus sur `factures_fournisseurs`.** Le fichier reçu
 * ne se réécrit jamais : mêler l'export du logiciel aux lignes du classeur tenu à la main
 * rendrait impossible de dire, plus tard, d'où vient un chiffre. Ce sont deux sources, elles
 * ont deux tables.
 *
 * **La balance est une photographie, pas un journal.** Elle donne l'état des comptes au jour
 * où on l'exporte. Réimporter le même fichier met donc à jour le solde du fournisseur au
 * lieu d'en ajouter un second — l'historique, lui, reste dans le lot d'import, qui garde le
 * fichier tel qu'il est arrivé.
 *
 * **Migration additive** : deux tables neuves, aucune ligne existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soldes_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();

            // Le fichier est global : son nom le dit — « pas de filtre date ni de site ».
            // Les deux colonnes restent, parce qu'un export filtré arrivera peut-être un
            // jour, et qu'on saura alors où le ranger.
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            $table->string('fournisseur', 160);

            // Les trois colonnes sont reprises telles que le logiciel les donne, y compris
            // le solde : le recalculer effacerait l'écart qu'on voudrait pouvoir constater.
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->bigInteger('solde')->default(0);

            $table->string('code_agent', 20)->nullable();
            $table->string('source_rattachement', 40)->nullable();
            $table->boolean('rattachement_presume')->default(false);
            $table->timestamps();

            // Un fournisseur, un solde : la balance est une photographie.
            $table->unique(['entreprise_id', 'fournisseur'], 'solde_fournisseur_unique');
        });

        Schema::create('reglements_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            $table->date('date_reglement')->nullable();

            // « F-REG N°000300 » : le numéro que la comptabilité donne au paiement. C'est
            // lui qui permet de réimporter le fichier sans doubler ses lignes.
            $table->string('code_reglement', 60);

            $table->string('fournisseur', 160);
            $table->string('mode_reglement', 80)->nullable();
            $table->bigInteger('montant')->default(0);

            $table->string('code_agent', 20)->nullable();
            $table->string('source_rattachement', 40)->nullable();
            $table->boolean('rattachement_presume')->default(false);
            $table->timestamps();

            $table->unique(['entreprise_id', 'code_reglement'], 'reglement_fournisseur_unique');
            $table->index(['entreprise_id', 'fournisseur'], 'reglement_fournisseur_nom_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglements_fournisseur');
        Schema::dropIfExists('soldes_fournisseur');
    }
};

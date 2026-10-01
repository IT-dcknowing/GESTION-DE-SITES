<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les pièces bancaires entrent, et le dépôt dit par quel compte.
 *
 * **Les huit colonnes sont relevées sur l'écran du logiciel comptable** — « Liste de toutes
 * les pièces comptables », montré le 30/09 :
 *
 *   DATE PIÈCE · CODE PIÈCE · RÉFÉRENCE PIÈCE · BANQUE ÉMETTRICE ·
 *   TYPE DE PIÈCES · MODÈLE DE RÈGLEMENT · BÉNÉFICIAIRES / REMETTANT · MONTANT PIÈCE
 *
 * **Et il en manque une, la plus importante : le compte.** Sur cet écran, le compte bancaire
 * se choisit **hors de la grille**, dans une liste au-dessus — AFG BANK, BGFI BANK, BNI.
 * L'export ne portera donc pas le nom de *notre* banque, et « BANQUE ÉMETTRICE » est celle du
 * chèque **reçu**, ce qui n'est pas du tout la même chose : confondre les deux rangerait sous
 * la BGFI tout règlement reçu par chèque BGFI, quel que soit le compte où il a été déposé.
 *
 * D'où `lots_import.banque_id` : **le compte se déclare au dépôt**, une fois pour tout le
 * fichier, comme la ville. C'est ce que le propriétaire a demandé le 30/09 — « dès que le
 * type banque sera sélectionné, un champ doit s'ouvrir pour choisir la banque ».
 *
 * **Le sens n'est pas dans le fichier non plus.** Une pièce bancaire est une entrée ou une
 * sortie selon son type, et les libellés ne sont pas connus d'avance. La colonne `sens` reste
 * donc **nulle** tant que le type n'est pas reconnu : une écriture qu'on ne sait pas orienter
 * doit se voir, pas se deviner.
 *
 * **Additive, et elle n'écrit aucune donnée.**
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_bancaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();

            // Le compte sur lequel la pièce est passée — déclaré au dépôt, jamais lu dans
            // le fichier, qui ne le porte pas.
            $table->foreignId('banque_id')->constrained('banques')->cascadeOnDelete();

            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            // Les huit colonnes du fichier, sous les mots du logiciel.
            $table->date('date_piece')->nullable();
            $table->string('code_piece', 60)->nullable();
            $table->string('reference_piece', 120)->nullable();
            $table->string('banque_emettrice', 120)->nullable();
            $table->string('type_piece', 80)->nullable();
            $table->string('modele_reglement', 80)->nullable();
            $table->string('beneficiaire', 180)->nullable();
            $table->unsignedBigInteger('montant')->default(0);

            /*
             * **Nul tant qu'on ne sait pas**, et c'est délibéré. Le sens se déduit du type de
             * pièce, dont on ne connaît pas encore les libellés réels — le fichier n'est pas
             * arrivé. Poser « entrée » par défaut ferait des sorties des entrées, et le total
             * serait faux du double de leur montant sans que rien ne le signale.
             */
            $table->string('sens', 10)->nullable();

            $table->string('code_agent', 40)->nullable();
            $table->string('source_rattachement', 30)->nullable();
            $table->boolean('rattachement_presume')->default(false);

            $table->timestamps();

            // Le code de pièce identifie l'écriture chez le comptable : c'est lui qui permet
            // de redéposer le fichier sans doubler ses lignes. Par compte, parce que deux
            // banques peuvent numéroter pareil.
            $table->unique(['banque_id', 'code_piece'], 'pieces_bancaires_compte_code');
            $table->index(['entreprise_id', 'date_piece']);
        });

        Schema::table('lots_import', function (Blueprint $table) {
            // Facultatif : seuls les dépôts de relevé bancaire en portent un.
            $table->foreignId('banque_id')->nullable()->after('site_id')
                ->constrained('banques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lots_import', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banque_id');
        });

        Schema::dropIfExists('pieces_bancaires');
    }
};

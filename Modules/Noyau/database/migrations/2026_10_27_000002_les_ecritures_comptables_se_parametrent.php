<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le paramétrage des écritures comptables — 27/10.
 *
 * « On doit avoir un module Écriture comptable ; dans cette partie, on va paramétrer les numéros
 * de compte, faire la configuration. » Une ligne par rôle de compte (clients, ventes, caisse,
 * chaque banque, chaque nature de charge…) ou par journal : le numéro et l'intitulé que
 * l'entreprise a retenus. Voir `PlanComptable`.
 *
 * Additive : une table neuve, rien n'est lu ni écrit ailleurs. Vide, l'écran propose les
 * comptes du SYSCOHADA, à valider.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comptes_comptables')) {
            return;
        }

        Schema::create('comptes_comptables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            // Le rôle du compte : « clients », « ventes_mecanique », « banque:3 », « journal:ventes »…
            $table->string('cle', 80);
            // « compte » ou « journal ».
            $table->string('type', 10)->default('compte');
            $table->string('numero', 20);
            $table->string('libelle', 160)->nullable();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entreprise_id', 'cle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_comptables');
    }
};

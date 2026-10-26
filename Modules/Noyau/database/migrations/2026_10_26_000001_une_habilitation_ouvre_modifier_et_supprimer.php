<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les habilitations de modification et de suppression — 26/10.
 *
 * « Au niveau de la maintenance, une section me permettant de donner des habilitations de
 * suppression et de modification à certains rôles déjà créés, ou à certains utilisateurs
 * précisément ; les boutons apparaissent chez ceux qui les ont. » Une ligne par geste accordé
 * (ou retiré) à un rôle ou à un compte. Voir `HabilitationsDActions`.
 *
 * Additive : une table neuve, rien n'est lu ni écrit ailleurs. Vide, elle laisse chaque écran tel
 * que le code le prévoit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('habilitations_actions')) {
            return;
        }

        Schema::create('habilitations_actions', function (Blueprint $table) {
            $table->id();
            // Ce sur quoi porte le geste (`creances`, `pieces_fournisseurs`) et le geste lui-même.
            $table->string('domaine', 40);
            $table->string('action', 20);
            // L'un ou l'autre : un rôle (nom technique, pour toutes les entreprises) ou un compte.
            $table->string('role', 60)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Faux : le geste est retiré à un rôle qui l'avait par le code.
            $table->boolean('accorde')->default(true);
            $table->foreignId('accorde_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['domaine', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habilitations_actions');
    }
};

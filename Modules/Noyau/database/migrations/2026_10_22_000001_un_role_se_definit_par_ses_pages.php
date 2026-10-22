<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un rôle se définit par les pages qu'il ouvre — 22/10.
 *
 * « Au Super admin, une section où je configure les rôles : je mets le nom du rôle, je coche les
 * sections et les pages qui doivent apparaître ; un bouton Modifier. » Une ligne par rôle, pour
 * toute la plateforme : le Super admin le définit une fois, chaque entreprise le reçoit quand un
 * accès le porte (le rôle Spatie de son équipe se crée à ce moment-là). Voir `CatalogueDesPages`.
 *
 * Additive : une table neuve, rien n'est lu ni écrit ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles_configures')) {
            return;
        }

        Schema::create('roles_configures', function (Blueprint $table) {
            $table->id();
            // Le nom technique, celui de la table `roles` : jamais celui d'un rôle du métier.
            $table->string('nom', 60)->unique();
            $table->string('libelle', 120);
            // Les clés des pages cochées (`CatalogueDesPages::SECTIONS`).
            $table->json('pages')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_configures');
    }
};

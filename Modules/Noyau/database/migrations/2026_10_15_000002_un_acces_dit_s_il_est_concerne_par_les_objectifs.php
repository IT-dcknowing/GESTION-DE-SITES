<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un accès dit s'il est concerné par les objectifs de l'entreprise, et lesquels — 15/10.
 *
 * « Lors de la création d'un accès, on coche s'il est concerné par les objectifs ; si oui, on coche
 * ceux dont il est concerné (un ou tous). Pour ceux déjà créés sans ça : une section Rattrapage. »
 *
 * Une ligne par accès : son absence veut dire « pas encore décidé » — c'est ce que la page de
 * rattrapage liste. `tous` vaut pour les objectifs présents et à venir ; sinon `objectifs` porte
 * la liste choisie. Additive : une table neuve, rien n'est lu ni écrit ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('objectifs_des_acces')) {
            return;
        }

        Schema::create('objectifs_des_acces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('concerne')->default(false);
            $table->boolean('tous')->default(false);
            $table->json('objectifs')->nullable();
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entreprise_id', 'concerne'], 'objectifs_des_acces_concerne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objectifs_des_acces');
    }
};

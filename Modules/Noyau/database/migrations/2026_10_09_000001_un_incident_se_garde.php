<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque panne garde sa trace, sous la référence que l'utilisateur a lue à l'écran.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Demandé le 09/10**, après « ERR-XEYTWV » sur `/banques` : « où voir l'erreur ? Dans la
 * page maintenance, une section avec un champ : dès que je dépose le code, je dois voir
 * l'erreur ; et tous les codes qui surviennent, listés et cliquables ». La référence n'allait
 * jusqu'ici qu'au journal du serveur, qu'on ne lit qu'en se connectant en SSH.
 *
 * **Pas de clé étrangère, et c'est voulu** : cette table s'écrit au moment même où quelque
 * chose casse. Une contrainte qui refuserait la ligne — un compte supprimé, une entreprise
 * purgée — ferait perdre la trace de la panne qu'on cherche.
 *
 * **Additive** : une table neuve. Aucune ligne existante n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->unsignedBigInteger('entreprise_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            // Le compte réellement aux commandes quand un super administrateur assistait.
            $table->unsignedBigInteger('assistant_id')->nullable();
            $table->string('methode', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('exception', 255);
            $table->text('message')->nullable();
            $table->string('origine', 500)->nullable();
            $table->text('trace')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};

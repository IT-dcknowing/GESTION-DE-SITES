<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une panne réglée quitte la liste des incidents.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Demandé le 09/10** : « si l'erreur est réglée, la ligne doit disparaître ; on ne garde que
 * ce qui est encore une erreur, pour éviter les confusions ». Une panne se reconnaît à sa
 * **signature** — sa nature et l'endroit du code —, pas à sa référence : cinq références pour
 * la même faute sont une seule faute.
 *
 * Cette table retient qu'une signature a été déclarée réglée, et quand. Les incidents de cette
 * signature antérieurs à la date disparaissent — y compris ceux qui ne vivent que dans le
 * journal du serveur, qu'on ne réécrit pas. Si la panne revient **après**, elle reparaît :
 * elle n'était pas réglée.
 *
 * **Additive** : une table neuve. Aucune ligne existante n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents_regles', function (Blueprint $table) {
            $table->id();
            $table->string('signature', 64)->unique();
            $table->text('libelle')->nullable();
            $table->timestamp('regle_le');
            $table->unsignedBigInteger('regle_par')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents_regles');
    }
};

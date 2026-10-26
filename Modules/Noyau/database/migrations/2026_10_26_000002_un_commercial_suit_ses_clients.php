<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le portefeuille de clients d'un commercial, et ses relances — 26/10.
 *
 * « Affecter à chaque commercial un ou plusieurs clients — ceux qu'il a prospectés aussi. Ils
 * pourront se faire des relances directement, comme le recouvrement : une nouvelle page où ils
 * voient les clients qu'ils suivent, et font leurs relances. »
 *
 * Deux tables neuves. Un client n'a qu'un commercial à la fois (réaffecter le déplace) : deux
 * vendeurs qui relancent le même client sans le savoir, c'est ce que l'affectation doit éviter.
 * Le nom reste la clé, comme partout ailleurs (factures, relances du recouvrement) ; sa forme
 * normalisée sert à reconnaître « NSIA » et « Nsia » comme un seul client affecté.
 *
 * Additive : rien n'est lu ni écrit ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients_commerciaux')) {
            Schema::create('clients_commerciaux', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commercial_id')->constrained('commerciaux')->cascadeOnDelete();
                $table->string('client', 160);
                $table->string('client_normalise', 160);
                // « affectation » (par un responsable) ou « prospection » (repris de ses prospections).
                $table->string('origine', 20)->default('affectation');
                $table->string('telephone', 40)->nullable();
                $table->string('note', 500)->nullable();
                $table->foreignId('affecte_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['entreprise_id', 'client_normalise']);
                $table->index(['entreprise_id', 'commercial_id']);
            });
        }

        if (! Schema::hasTable('relances_commerciales')) {
            Schema::create('relances_commerciales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commercial_id')->nullable()->constrained('commerciaux')->nullOnDelete();
                // L'auteur peut partir ; ce qu'il a fait reste, sous son nom recopié.
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('responsable', 120);
                $table->string('client', 160)->index();
                $table->date('date');
                $table->string('objet', 60);
                $table->string('canal', 60);
                $table->string('interlocuteur', 120)->nullable();
                $table->text('resultat')->nullable();
                $table->date('prochaine_relance')->nullable();
                $table->string('statut', 60)->default('À suivre');
                $table->timestamps();

                $table->index(['entreprise_id', 'commercial_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('relances_commerciales');
        Schema::dropIfExists('clients_commerciaux');
    }
};

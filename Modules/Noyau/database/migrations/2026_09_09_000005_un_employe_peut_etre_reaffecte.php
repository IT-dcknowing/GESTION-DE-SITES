<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La réaffectation d'un employé d'une ville ou d'un atelier à un autre.
 *
 * **Ce que le logiciel ne savait pas dire.** Une personne travaille au Site 1 d'Abidjan
 * pendant huit mois, puis part à San Pédro. Ses fiches d'Abidjan appartiennent à Abidjan et
 * doivent y rester — c'est là que le chiffre d'affaires a été fait. Mais elle-même change
 * de lieu, et son écran doit suivre. Jusqu'ici on n'avait que deux mauvaises réponses :
 * changer son site et lui faire perdre de vue tout son travail passé, ou ne rien changer et
 * la laisser saisir dans une ville où elle n'est plus.
 *
 * Une réaffectation est donc **un fait daté**, pas une simple mise à jour de colonne. On
 * garde d'où l'on vient, où l'on va, avec quel rôle, décidé par qui et pourquoi. Trois
 * conséquences en découlent :
 *
 * - les **données ne bougent pas** : aucune facture, aucune fiche ne change de ville ;
 * - la personne **continue de consulter** son ancien lieu — en lecture seule, par le
 *   périmètre de consultation, jamais par celui de saisie ;
 * - le gérant dispose d'un **historique** qu'il peut relire, plutôt que d'une colonne dont
 *   la valeur d'avant a disparu.
 *
 * Le code du logiciel d'atelier suit le même chemin quand il en existe un : c'est la même
 * personne, et laisser son code derrière elle rattacherait ses prochaines fiches à son
 * ancien atelier.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reaffectations')) {
            return;
        }

        Schema::create('reaffectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();

            // L'un, l'autre, ou les deux : une réaffectation peut porter sur un compte de
            // la plateforme, sur un code du logiciel d'atelier, ou sur la même personne
            // connue des deux côtés.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('code_agent_id')->nullable()->constrained('codes_agents')->nullOnDelete();

            $table->foreignId('ville_avant_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_avant_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('role_avant', 60)->nullable();

            $table->foreignId('ville_apres_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_apres_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('role_apres', 60)->nullable();

            $table->string('motif', 255)->nullable();
            $table->foreignId('decidee_par')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // On interroge cette table dans deux sens : « où cette personne a-t-elle
            // travaillé » à chaque calcul de périmètre, et « qui a bougé » sur l'écran
            // d'historique.
            $table->index(['entreprise_id', 'user_id']);
            $table->index(['entreprise_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reaffectations');
    }
};

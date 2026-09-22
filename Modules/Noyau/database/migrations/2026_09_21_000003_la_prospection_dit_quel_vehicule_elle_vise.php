<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La prospection dit quel véhicule elle vise, et l'on garde trace des rapprochements écartés.
 *
 * **Le constat, fait le 21/09/2026 sur la base locale.** 2 673 devis, dont 241 seulement
 * rattachés à une prospection — 9 %. Les 2 432 autres viennent de l'import : ils portent un
 * n° de fiche (2 670 sur 2 673 en ont un), mais rien ne les relie au commercial qui a
 * décroché l'affaire. La prospection, elle, ne connaît ni plaque ni fiche : elle n'a que le
 * nom du client et une date. Il manquait donc, des deux côtés, la seule chose qu'ils
 * auraient pu avoir en commun.
 *
 * **Pourquoi la plaque plutôt que le n° de fiche.** Décidé par le propriétaire le
 * 18/09/2026 : « le devis ne se fait pas toujours au même moment que la prospection, il
 * sera difficile de revenir mettre le numéro de fiche de réception sur une prospection qui
 * a eu lieu 3 ou 5 jours après ». Le n° de fiche est donc **facultatif** — quand il est là
 * il emporte la décision, quand il manque la plaque et la date suffisent.
 *
 * **Migration additive.** Deux colonnes nullables sur une table existante et une table
 * neuve : aucune ligne n'est touchée, aucune valeur n'est réécrite, et l'application
 * fonctionne exactement comme avant tant que personne ne remplit ces colonnes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospections', function (Blueprint $table) {
            // Le véhicule visé. Facultatif : une prospection peut viser une flotte entière
            // ou un client sans véhicule identifié, et la refuser pour autant serait
            // empêcher le commercial de faire son travail.
            $table->string('immatriculation', 32)->nullable()->after('localisation');

            // La fiche de réception, quand le commercial la connaît déjà — cas du véhicule
            // déjà entré à l'atelier au moment de la visite.
            $table->string('n_fiche_reception', 60)->nullable()->after('immatriculation');

            // L'index sert le rapprochement, qui cherche les prospections par plaque dans
            // le périmètre d'une entreprise.
            $table->index(['entreprise_id', 'immatriculation'], 'prospections_plaque_idx');
        });

        // Un rapprochement refusé doit le rester.
        //
        // Sans cette table, l'écran reproposerait indéfiniment le couple qu'on vient
        // d'écarter : au bout d'une semaine la liste ne contiendrait plus que des refus et
        // personne ne l'ouvrirait. Refuser est une décision ; elle se garde, avec son
        // auteur, comme se garde une confirmation.
        Schema::create('rapprochements_ecartes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('prospection_id')->constrained('prospections')->cascadeOnDelete();
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Le nom est recopié : l'accès peut disparaître, la décision reste attribuée.
            $table->string('auteur', 120)->nullable();
            $table->timestamps();

            $table->unique(['prospection_id', 'devis_id'], 'rapprochement_ecarte_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapprochements_ecartes');

        Schema::table('prospections', function (Blueprint $table) {
            $table->dropIndex('prospections_plaque_idx');
            $table->dropColumn(['immatriculation', 'n_fiche_reception']);
        });
    }
};

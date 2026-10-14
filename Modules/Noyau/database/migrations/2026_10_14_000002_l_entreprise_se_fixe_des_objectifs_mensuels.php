<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les objectifs mensuels de l'entreprise, par tranches et par période — 14/10.
 *
 * « L'entreprise a plusieurs tranches d'objectif. Le but est de configurer les objectifs mensuels
 * qui ont été établis ; le système applique les objectifs en fonction de leur période définie
 * (date de début et de fin). »
 *
 * Un objectif couvre une période (`date_debut` → `date_fin`) et porte ses tranches : des montants
 * **mensuels** croissants, chacun nommé (« Seuil », « Objectif », « Excellence »…). Il vaut pour
 * toute l'entreprise, ou pour une ville ; pour toutes les activités, ou pour l'une. Les
 * objectifs individuels des commerciaux (`commerciaux.objectif_mensuel`) restent ce qu'ils sont :
 * ceci est l'objectif de l'entreprise.
 *
 * Additive : deux tables neuves, rien n'est lu ni écrit ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('objectifs_entreprise')) {
            Schema::create('objectifs_entreprise', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $table->string('libelle', 120);
                $table->date('date_debut');
                $table->date('date_fin');
                // Vide : toute l'entreprise. Sinon, la seule ville nommée.
                $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
                // Vide : toutes les activités. Sinon « Mécanique » ou « Sinistre ».
                $table->string('activite', 20)->nullable();
                $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['entreprise_id', 'date_debut', 'date_fin'], 'objectifs_entreprise_periode');
            });
        }

        if (! Schema::hasTable('tranches_objectif')) {
            Schema::create('tranches_objectif', function (Blueprint $table) {
                $table->id();
                $table->foreignId('objectif_entreprise_id')->constrained('objectifs_entreprise')->cascadeOnDelete();
                $table->unsignedSmallInteger('rang');
                $table->string('libelle', 80);
                // Un montant par mois, tel que donné (piège 17 : jamais arrondi).
                $table->decimal('montant', 18, 4);
                $table->timestamps();

                $table->index(['objectif_entreprise_id', 'rang'], 'tranches_objectif_rang');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tranches_objectif');
        Schema::dropIfExists('objectifs_entreprise');
    }
};

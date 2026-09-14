<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une prospection validée dit désormais par qui, quand, d'où et depuis quel poste.
 *
 * **Ce qui manquait.** La table portait `statut_validation` et rien d'autre : on savait
 * qu'une prospection était validée, jamais qui l'avait validée. Or c'est un acte d'encadrement
 * — valider une prospection qui annonce un devis, c'est engager l'atelier à l'établir. Le
 * commercial qui voit sa ligne passer au vert n'avait aucun moyen de savoir à qui s'adresser,
 * et personne ne pouvait vérifier après coup qui avait engagé quoi.
 *
 * **Le nom est recopié en clair, en plus de l'identifiant.** Un accès fermé six mois plus
 * tard ne doit pas rendre anonyme une décision prise sous ce nom-là. C'est la même règle que
 * pour les relances de recouvrement, et pour la même raison : la trace vaut par ce qu'elle
 * garde, pas par ce qu'elle pointe.
 *
 * **Un refus remplit les mêmes colonnes.** Refuser est une décision autant que valider, et
 * c'est même celle qu'on conteste. Deux jeux de colonnes pour un seul geste auraient fini
 * par diverger.
 *
 * Additif et réversible : rien n'est réécrit, les lignes existantes gardent leurs colonnes
 * à vide — elles ont été validées avant que la trace existe, et le prétendre serait mentir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospections', function (Blueprint $table) {
            $table->foreignId('valide_par')->nullable()->after('motif_refus')
                ->constrained('users')->nullOnDelete();

            // Recopié : l'identifiant peut disparaître, le nom sous lequel on a décidé, non.
            $table->string('validateur')->nullable()->after('valide_par');
            $table->timestamp('valide_le')->nullable()->after('validateur');
            $table->string('validation_ip', 45)->nullable()->after('valide_le');
            $table->string('validation_poste')->nullable()->after('validation_ip');
            $table->string('validation_ecran')->nullable()->after('validation_poste');
        });
    }

    public function down(): void
    {
        Schema::table('prospections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn([
                'validateur', 'valide_le', 'validation_ip', 'validation_poste', 'validation_ecran',
            ]);
        });
    }
};

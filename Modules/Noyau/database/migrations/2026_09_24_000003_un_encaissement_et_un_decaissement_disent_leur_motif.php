<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un encaissement et un décaissement disent pourquoi.
 *
 * **Ce qui manquait.** Un encaissement portait la facture qu'il solde, son moyen et son
 * montant — jamais la raison. Or une facture se règle en plusieurs fois, et savoir
 * *pourquoi* celui-ci arrive aujourd'hui — un acompte convenu, un déblocage d'assurance,
 * une remise de chèque — est ce qu'on cherche trois mois plus tard devant un relevé. Le
 * décaissement, lui, portait un libellé choisi dans une liste fermée et des observations
 * facultatives : la liste dit la catégorie comptable, pas le motif de ce paiement-là.
 *
 * **Obligatoire à la saisie, nullable en base**, et les deux vont ensemble. Les lignes déjà
 * enregistrées n'ont pas de motif et ne peuvent pas en recevoir un : le leur inventer
 * serait écrire à leur place, et poser la colonne en `NOT NULL` obligerait à le faire. La
 * contrainte se tient donc à l'endroit où quelqu'un peut répondre — le formulaire — et non
 * en base, où seule une valeur fausse pourrait satisfaire la règle.
 *
 * **Migration additive** : une colonne nullable sur chacune des deux tables, aucune ligne
 * existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encaissements', function (Blueprint $table) {
            $table->string('motif', 190)->nullable()->after('moyen');
        });

        Schema::table('charges', function (Blueprint $table) {
            // Le motif est à côté du libellé, et non à sa place : le libellé range la
            // dépense dans une catégorie, le motif dit ce qui s'est passé ce jour-là.
            $table->string('motif', 190)->nullable()->after('libelle');
        });
    }

    public function down(): void
    {
        Schema::table('encaissements', function (Blueprint $table) {
            $table->dropColumn('motif');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('motif');
        });
    }
};

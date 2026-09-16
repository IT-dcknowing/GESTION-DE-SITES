<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une facture connaît sa ville, même quand on ignore son atelier.
 *
 * **Ce qui se perdait.** La colonne SITE du classeur des impayés et du CATTC ne nomme pas un
 * atelier : elle nomme une ville — « ABIDJAN », « SAN PEDRO », « BOUAKE ». L'import le sait,
 * `Rattachement` résout bien la ville de chaque ligne ; mais Abidjan compte deux ateliers et
 * aucun fichier ne les distingue, si bien que le site reste vide. Or la table ne gardait que
 * le site : la ville, établie par la donnée, était jetée à l'écriture.
 *
 * Mesuré sur la base locale : 8 848 des 8 852 lignes de l'état des impayés sans atelier,
 * donc sans ville, alors que la colonne SITE du classeur dit « ABIDJAN » sur 5 097 d'entre
 * elles. Filtrer le recouvrement sur Abidjan en passant par l'atelier les aurait toutes
 * laissées de côté, précisément la ville qui porte l'essentiel de la créance.
 *
 * **Additive et nullable.** Aucune ligne existante n'est modifiée ici. Le modèle déduit la
 * ville de l'atelier à chaque écriture ; les lignes déjà en base se complètent par la commande
 * `factures:poser-la-ville`, en constat d'abord — voir MISE-A-JOUR-SERVEUR.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->unsignedBigInteger('ville_id')->nullable()->after('site_id');
            $table->index(['entreprise_id', 'ville_id'], 'factures_ville_idx');
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_ville_idx');
            $table->dropColumn('ville_id');
        });
    }
};

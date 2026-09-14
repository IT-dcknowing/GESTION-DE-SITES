<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une pièce fournisseur peut ne pas porter de numéro.
 *
 * La colonne était obligatoire par principe — une facture a un numéro. Le fichier réel dit
 * autre chose : la colonne « NATURE PIECE » y vaut aussi bien « Facture » que « Bon de
 * commande », « Avoir » ou « Autre », et ces dernières n'ont pas toujours de numéro. Une
 * dépense réglée sur simple reçu existe et se paie.
 *
 * Le refus n'aurait rien protégé : il aurait écarté de la dette des sommes réellement dues,
 * ce qui est exactement l'erreur qu'un suivi fournisseurs doit éviter. Le rapprochement au
 * réimport retombe alors sur le fournisseur, la date et le montant — moins net qu'un numéro,
 * mais suffisant, et surtout appliqué à une ligne présente plutôt qu'à une ligne perdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            $table->string('numero_piece', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            $table->string('numero_piece', 60)->nullable(false)->change();
        });
    }
};

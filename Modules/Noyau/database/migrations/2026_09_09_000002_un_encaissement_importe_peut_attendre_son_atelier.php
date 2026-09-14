<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un encaissement repris de l'état des impayés peut attendre son atelier, comme sa facture.
 *
 * **Encore une contrainte relâchée, pas ajoutée.** `site_id` passe de « obligatoire » à
 * « facultatif » sur `encaissements`. Aucune ligne existante n'est touchée, et les écrans
 * de saisie continuent de fournir le site.
 *
 * La raison suit exactement celle des factures et des devis : 42 % des lignes de l'état des
 * impayés n'ont pas de colonne SITE renseignée. Sans ce changement, l'import du règlement
 * échouerait précisément sur les factures dont on ignore l'atelier — c'est-à-dire sur celles
 * qui ont déjà le plus besoin d'être suivies.
 *
 * Et le refuser aurait un effet pervers : la facture entrerait sans son règlement, donc
 * paraîtrait entièrement due. On aurait fabriqué une créance imaginaire pour ne pas écrire
 * un `null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encaissements', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('encaissements', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable(false)->change();
        });
    }
};

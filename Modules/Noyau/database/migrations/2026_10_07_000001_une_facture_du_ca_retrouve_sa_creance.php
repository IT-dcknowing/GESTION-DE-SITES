<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une facture du chiffre d'affaires retrouve sa créance, au lieu d'en faire une seconde.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **La question du propriétaire, le 07/10** : « lorsque j'ai importé l'état des impayés,
 * automatiquement la page chiffre d'affaires s'est remplie ; il ne faudrait pas que j'aie des
 * doublons avec les factures que je vais importer ».
 *
 * Il avait raison de s'en inquiéter. Les deux fichiers décrivent les mêmes factures **sans
 * partager de numéro** — « FA -5713 » au CATTC, « 17 » à l'état des impayés, mesuré le 15/09 —
 * et chaque import ne retrouvait une facture que par son propre numéro. Importer le CATTC
 * après l'état aurait donc écrit chaque facture une seconde fois.
 *
 * Les imports se reconnaissent désormais par une clé forte — même date, même montant, même
 * plaque — et seulement quand un seul candidat répond (voir `PontDesFactures`). Cette colonne
 * retient le numéro CATTC de la facture ainsi retrouvée : sans elle, le dépôt suivant du CATTC
 * ne la reconnaîtrait pas, et la fusion serait à refaire à chaque fois.
 *
 * **Additive** : une colonne neuve, nullable, et son index. Aucune ligne n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->string('n_facture_cattc', 60)->nullable()->after('n_facture');
            $table->index(['entreprise_id', 'n_facture_cattc'], 'factures_cattc_idx');
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_cattc_idx');
            $table->dropColumn('n_facture_cattc');
        });
    }
};

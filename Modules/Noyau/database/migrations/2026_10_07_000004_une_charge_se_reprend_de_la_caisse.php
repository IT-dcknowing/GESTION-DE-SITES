<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une charge reprise d'une sortie du journal de caisse retient de quelle sortie elle vient.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Demandé le 07/10** : « pour certaines charges, fais en sorte qu'on puisse les récupérer
 * sur la caisse et les mettre dans les charges ; ajoute une colonne origine pour dire que cela
 * vient de la caisse ». Le journal de caisse porte des sorties — un achat de pièces payé en
 * espèces, une avance sur salaire — qui sont des charges et n'apparaissaient pas comme telles.
 *
 * **Pourquoi un lien et non une copie libre.** Sans lui, la même sortie se compterait deux
 * fois partout où le journal et les charges sont lus ensemble — la caisse « consolidée », la
 * liste des décaissements de la trésorerie — et rien n'empêcherait de la reprendre une
 * seconde fois. Avec lui, ces écrans écartent la charge quand ils lisent déjà sa sortie, et
 * l'index unique refuse la seconde reprise. C'est aussi ce lien qui fait dire « Caisse » à la
 * colonne Origine.
 *
 * `nullOnDelete` : si le dépôt du journal est purgé, la charge reste — elle a été décidée par
 * quelqu'un — et redevient une écriture ordinaire.
 *
 * **Additive** : une colonne neuve, nullable, son index unique. Aucune ligne n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('mouvement_caisse_id')->nullable()->after('lot_import_id')
                ->constrained('mouvements_caisse')->nullOnDelete();
            $table->unique('mouvement_caisse_id', 'charges_mouvement_caisse_unique');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropForeign(['mouvement_caisse_id']);
            $table->dropUnique('charges_mouvement_caisse_unique');
            $table->dropColumn('mouvement_caisse_id');
        });
    }
};

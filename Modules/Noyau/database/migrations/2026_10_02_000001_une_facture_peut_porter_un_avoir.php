<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une facture peut porter un avoir, et un règlement peut être négatif.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Le vrai blocage était là, et non à l'import.** Quarante-trois lignes du classeur sont
 * refusées depuis le premier dépôt, avec le motif « Montant négatif : cette ligne ressemble
 * à un avoir, à traiter à part ». On a longtemps cru que c'était un choix de l'import. C'en
 * était la conséquence : `factures.montant` et `encaissements.montant` sont déclarés
 * `bigint unsigned`, et **la base ne peut tout simplement pas écrire un nombre négatif**.
 * Aucun code n'aurait pu accepter un avoir tant que la colonne le refusait.
 *
 * Le propriétaire a tranché le 02/10, après dépouillement : *« montantTTC < 0 : c'est un
 * avoir. Il faut le garder en négatif et appliquer le même calcul que pour la caisse. »*
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi c'est additif, sur une base qui porte des écritures réelles.** Passer de
 * `bigint unsigned` à `bigint` est un **élargissement** : le domaine s'étend vers le négatif
 * et ne se rétrécit nulle part. Un `bigint` signé monte à 9,2 × 10¹⁸, soit neuf milliards de
 * milliards de francs — aucune valeur existante ne peut en sortir. Aucune ligne n'est lue,
 * modifiée ni supprimée ; seule la déclaration de la colonne change.
 *
 * **Le retour en arrière est écrit, et il est honnête sur ce qu'il ferait.** Reserrer la
 * colonne sur les positifs ferait échouer la migration s'il existe un seul avoir en base, et
 * c'est la bonne issue : mieux vaut un `down` qui refuse qu'un `down` qui efface des avoirs
 * sans le dire.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **`est_avoir` est posé en plus du signe, et ce n'est pas une redondance.** Le signe dit ce
 * qu'il en est aujourd'hui ; la colonne dit ce que la pièce **est**. Une facture d'avoir
 * ramenée à zéro par une correction resterait un avoir, et une requête qui cherche les
 * avoirs par `montant < 0` la manquerait. C'est aussi ce qui permet d'indexer la recherche,
 * ce qu'un signe ne permet pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            // `change()` sur une colonne déjà `NOT NULL` : on la redéclare à l'identique,
            // signe excepté, sinon Laravel la rendrait nullable par omission.
            $table->bigInteger('montant')->default(0)->change();

            $table->boolean('est_avoir')->default(false)->after('montant');
        });

        Schema::table('factures', function (Blueprint $table) {
            // L'état des impayés sépare les avoirs des créances à chaque affichage.
            $table->index(['entreprise_id', 'est_avoir'], 'factures_avoirs_idx');
        });

        Schema::table('encaissements', function (Blueprint $table) {
            // Le règlement d'un avoir est négatif lui aussi : quarante des quarante-quatre
            // avoirs du classeur portent un montant réglé égal au TTC, et négatif.
            $table->bigInteger('montant')->default(0)->change();
        });
    }

    public function down(): void
    {
        foreach ([['factures', 'une facture d\'avoir'], ['encaissements', 'un règlement négatif']] as [$table, $quoi]) {
            $negatifs = \Illuminate\Support\Facades\DB::table($table)->where('montant', '<', 0)->count();

            if ($negatifs > 0) {
                throw new RuntimeException(
                    "Refus de reserrer {$table}.montant sur les positifs : {$negatifs} ligne(s) portent {$quoi}. "
                    .'Les reserrer les écraserait sans le dire. Traitez-les d\'abord.'
                );
            }
        }

        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_avoirs_idx');
            $table->dropColumn('est_avoir');
            $table->unsignedBigInteger('montant')->default(0)->change();
        });

        Schema::table('encaissements', function (Blueprint $table) {
            $table->unsignedBigInteger('montant')->default(0)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un règlement dit par où il est passé, et non plus seulement comment.
 *
 * **Le défaut que cela ferme, relevé le 01/10.** La saisie posait deux champs sans rapport
 * l'un avec l'autre : un « mode » libre et une « banque » libre. Le résultat se mesure dans
 * `factures.banque` — quatorze orthographes pour quatre banques, dont `BGFIU`, `BGFI+BNI`,
 * `234665`, et jusqu'à `CAISSE` et `wave`. Ces deux derniers disent tout : quelqu'un avait
 * besoin d'une colonne « support » et l'a écrite dans celle de la banque, faute de mieux.
 *
 * **Trois colonnes, et elles répondent à trois questions différentes :**
 *
 * | Question | Où elle se range |
 * |---|---|
 * | par quel support ? | `banques.type` — caisse exceptée, qui n'a pas de compte |
 * | sur quel compte ? | `encaissements.banque_id`, `charges.banque_id` |
 * | de quelle manière ? | `moyen`, qui existait déjà |
 *
 * **Les portefeuilles mobiles sont des comptes, pas un moyen.** *« Les deux sont des banques,
 * mais on veut préciser si réellement cela a été dit, ou rester sur MOBILE MONEY. »* Un
 * portefeuille Orange Money reçoit de l'argent, le garde et le rend : c'est un compte. Le
 * ranger dans le moyen obligerait à écrire « MOBILE MONEY — ORANGE » dans une case prévue
 * pour « chèque », ce qui est exactement ce que l'ancien référentiel faisait, et pourquoi
 * deux encaissements portent encore « VIREMENT — BGFI ».
 *
 * `type` distingue donc les deux sortes de comptes, sans les séparer en deux tables : ils
 * reçoivent, ils se totalisent, et l'écran des banques les montre côte à côte.
 *
 * **Additive, et sans écriture de données.** Les colonnes arrivent nulles ; les 7 714
 * encaissements existants gardent leur `moyen`, qui continue de désigner leur support —
 * voir `SupportDeReglement`. Rien n'est réinterprété rétroactivement : une reprise écrirait
 * dans une base réelle une donnée que personne n'a saisie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banques', function (Blueprint $table) {
            /*
             * « banque » ou « mobile ». Une chaîne et non un `enum` : le jour où un troisième
             * support apparaît — une caisse d'épargne, un compte de monnaie électronique
             * d'un autre genre —, une chaîne s'étend sans migration bloquante, là où un
             * `enum` demande un `ALTER` sur une table en service.
             */
            $table->string('type', 20)->default('banque')->after('nom_normalise');
        });

        Schema::table('encaissements', function (Blueprint $table) {
            // Le compte qui a reçu. Nul pour les espèces : la caisse n'est pas un compte.
            $table->foreignId('banque_id')->nullable()->after('site_id')
                ->constrained('banques')->nullOnDelete();
        });

        Schema::table('charges', function (Blueprint $table) {
            // Le compte qui a payé. Nul pour les espèces, pour la même raison.
            $table->foreignId('banque_id')->nullable()->after('site_id')
                ->constrained('banques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banque_id');
        });

        Schema::table('encaissements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banque_id');
        });

        Schema::table('banques', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};

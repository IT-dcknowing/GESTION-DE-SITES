<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une habilitation donnée à un rôle peut ne valoir que pour une entreprise — 25/11.
 *
 * Le propriétaire, sur la page Intelligence artificielle : « accorder l'usage de l'IA à un rôle ou
 * à un compte précis, par entreprise ». Un rôle est commun à toute la plateforme (le gérant d'Alpha
 * et celui de Bêta portent le même) : sans cette colonne, donner l'IA au gérant d'une entreprise la
 * donnait à tous les gérants, et chaque appel coûte au compte du fournisseur.
 *
 * Additive : une colonne nullable. Vide, la ligne vaut pour toutes les entreprises, comme avant ;
 * tant que la migration n'est pas passée, `HabilitationsDActions` lit chaque ligne comme commune.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('habilitations_actions') || Schema::hasColumn('habilitations_actions', 'entreprise_id')) {
            return;
        }

        Schema::table('habilitations_actions', function (Blueprint $table) {
            // Pas de clé étrangère : une entreprise ne se supprime pas en ligne, et une contrainte
            // de plus sur une table déjà remplie est un risque sans contrepartie.
            $table->unsignedBigInteger('entreprise_id')->nullable()->after('user_id');
            $table->index('entreprise_id', 'habilitations_actions_entreprise');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('habilitations_actions', 'entreprise_id')) {
            Schema::table('habilitations_actions', function (Blueprint $table) {
                $table->dropIndex('habilitations_actions_entreprise');
                $table->dropColumn('entreprise_id');
            });
        }
    }
};

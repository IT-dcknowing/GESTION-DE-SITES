<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les avoirs d'une facture se lisent par index — 25/11.
 *
 * Depuis le 24/11, le reste dû d'une facture retranche ses avoirs rattachés : une sous-requête
 * `factures where avoir_de_id = factures.id`, jouée pour chaque facture de l'état des impayés.
 * En ligne, le rapport est tombé sur « 2006 MySQL server has gone away » : sans index sur
 * `avoir_de_id`, chaque facture relit toute la table, et l'hébergeur coupe la requête.
 *
 * Additive et rejouable : l'index n'est posé que si aucun index ne commence déjà par
 * `avoir_de_id` (la clé étrangère du 27/10 en crée un quand elle a pu être posée). Il couvre le
 * montant, que la sous-requête additionne sans relire la ligne. Même chose pour les encaissements
 * par facture, au cas où l'index du 17/09 manquerait.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->indexSiAbsent('factures', 'factures_avoirs_index', ['avoir_de_id', 'montant']);
        $this->indexSiAbsent('encaissements', 'encaissements_facture_montant_index', ['facture_id', 'montant']);
    }

    public function down(): void
    {
        foreach (['factures' => 'factures_avoirs_index', 'encaissements' => 'encaissements_facture_montant_index'] as $table => $nom) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $nom)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($nom));
            }
        }
    }

    /** @param  list<string>  $colonnes */
    private function indexSiAbsent(string $table, string $nom, array $colonnes): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $colonnes[0])) {
            return;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $colonnes[0]) {
                return;   // un index commence déjà par cette colonne : il suffit
            }
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($colonnes, $nom));
    }
};

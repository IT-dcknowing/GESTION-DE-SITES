<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un index couvrant pour la somme des règlements d'une facture — et rien d'autre : aucune
 * ligne n'est lue ni écrite.
 *
 * **Le constat, mesuré en local** : l'état des impayés, la balance âgée et le recouvrement
 * calculent le reste à payer de milliers de factures, chacune par la somme de ses règlements.
 * Sans index qui porte le montant, la base relit chaque règlement entier pour n'en garder que
 * ce montant. Avec `encaissements (facture_id, montant)`, elle le lit dans l'index seul
 * (« Using index » à l'EXPLAIN) ; la somme de tous les règlements passe de 43 à 22 ms.
 *
 * Un second index, couvrant l'état côté factures, a été essayé et écarté : MySQL ne le
 * choisissait pas, et il aurait alourdi chaque écriture pour rien.
 *
 * **Le piège du retour arrière.** MySQL retire de lui-même l'index qu'il avait créé pour la
 * clé étrangère `facture_id` dès qu'un autre index commence par cette colonne. Retirer le
 * nouvel index sans reposer l'ancien est alors refusé (« needed in a foreign key
 * constraint ») : `down()` repose donc l'ancien d'abord.
 */
return new class extends Migration
{
    private const INDEX = 'encaissements_facture_montant_idx';

    private const INDEX_DE_LA_CLE = 'encaissements_facture_id_foreign';

    public function up(): void
    {
        if (! $this->indexExiste('encaissements', self::INDEX)) {
            Schema::table('encaissements', fn (Blueprint $table) => $table->index(['facture_id', 'montant'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (! $this->indexExiste('encaissements', self::INDEX)) {
            return;
        }

        if (! $this->indexExiste('encaissements', self::INDEX_DE_LA_CLE)) {
            Schema::table('encaissements', fn (Blueprint $table) => $table->index('facture_id', self::INDEX_DE_LA_CLE));
        }

        Schema::table('encaissements', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }

    private function indexExiste(string $table, string $nom): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $nom);
    }
};

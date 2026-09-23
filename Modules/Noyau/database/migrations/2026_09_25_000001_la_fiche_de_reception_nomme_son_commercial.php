<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fiche de réception peut nommer le commercial qui a décroché l'affaire.
 *
 * **D'où vient cette colonne.** Le propriétaire a arbitré avec la direction le moyen de
 * relier les devis du logiciel d'atelier aux prospections. La fiche de réception porte déjà
 * une colonne libre — « INFORMATIONS SUR LA SITUATION » — qui remonte telle quelle dans
 * l'export de la situation du parc. Quand la venue du client fait suite à une prospection,
 * le saisisseur y met **en première position** le nom du commercial, son code de deux
 * lettres, ou son code de l'application, séparé du reste par une virgule, un point-virgule,
 * un point, un tiret ou un blanc souligné. Le reste de la colonne continue de servir à ce
 * qu'il servait : elle n'est pas réquisitionnée, on lit seulement son début.
 *
 * **Trois colonnes, et pas une de plus.**
 *
 * - `commercial_saisi` garde **ce que le fichier disait**, mot pour mot. C'est la règle de
 *   la maison : le fichier reçu ne se réécrit jamais, les lectures se posent par-dessus. Si
 *   la reconnaissance se trompe, on peut toujours revenir à ce qui était écrit.
 * - `commercial_id` porte ce qu'on en a conclu, ou rien du tout.
 * - `commercial_source` dit **par quel chemin** on a conclu — code d'atelier, code de
 *   l'application, nom exact, ou correspondance décidée par quelqu'un. Un rattachement dont
 *   on ne sait plus s'il a été lu ou deviné ne se défend pas.
 *
 * **Additive et réversible.** Trois colonnes nullables sur une table existante, aucune
 * donnée touchée : les 3 323 fiches déjà en base gardent un `commercial_id` vide jusqu'au
 * prochain dépôt, et un vide se lit « on ne sait pas », jamais « personne ».
 *
 * **Mesuré avant d'être écrit** : sur les 3 323 fiches en base au 24/09, **3 seulement**
 * portent quelque chose dans cette colonne, et aucune ne nomme un commercial. La
 * reconnaissance ne rendra donc rien tant que les saisisseurs n'auront pas pris l'habitude.
 * C'est attendu, et c'est précisément la raison d'être de cette colonne : on prépare la
 * lecture pour que l'habitude ait un effet dès le premier fichier.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $colonnes = ['commercial_saisi', 'commercial_id', 'commercial_source'];

    public function up(): void
    {
        if (! Schema::hasTable('dossiers_vehicules')) {
            return;
        }

        Schema::table('dossiers_vehicules', function (Blueprint $table) {
            if (! Schema::hasColumn('dossiers_vehicules', 'commercial_saisi')) {
                $table->string('commercial_saisi', 120)->nullable()->after('informations');
            }

            if (! Schema::hasColumn('dossiers_vehicules', 'commercial_id')) {
                $table->foreignId('commercial_id')->nullable()->after('commercial_saisi')
                    ->constrained('commerciaux')->nullOnDelete();
            }

            if (! Schema::hasColumn('dossiers_vehicules', 'commercial_source')) {
                $table->string('commercial_source', 20)->nullable()->after('commercial_id');
            }
        });

        // Les devis se rattachent par le numéro de fiche : l'index sert la jointure qui
        // remonte de la fiche au commercial, faite une fois par écran de rapprochement.
        Schema::table('dossiers_vehicules', function (Blueprint $table) {
            if (! $this->aLIndex('dossiers_vehicules', 'dossiers_vehicules_commercial_index')) {
                $table->index(['entreprise_id', 'commercial_id'], 'dossiers_vehicules_commercial_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dossiers_vehicules')) {
            return;
        }

        if ($this->aLIndex('dossiers_vehicules', 'dossiers_vehicules_commercial_index')) {
            Schema::table('dossiers_vehicules', fn (Blueprint $table) => $table
                ->dropIndex('dossiers_vehicules_commercial_index'));
        }

        if (Schema::hasColumn('dossiers_vehicules', 'commercial_id')) {
            Schema::table('dossiers_vehicules', function (Blueprint $table) {
                // La contrainte avant la colonne : SQLite (les tests) et MySQL ne
                // réagissent pas pareil quand on retire l'une sans l'autre.
                try {
                    $table->dropForeign(['commercial_id']);
                } catch (Throwable) {
                    // SQLite n'a pas de contrainte nommée à retirer : il n'y a rien à faire.
                }
            });
        }

        $presentes = array_values(array_filter(
            $this->colonnes,
            fn (string $colonne) => Schema::hasColumn('dossiers_vehicules', $colonne),
        ));

        if ($presentes !== []) {
            Schema::table('dossiers_vehicules', fn (Blueprint $table) => $table->dropColumn($presentes));
        }
    }

    /** L'index existe-t-il déjà ? Une migration ne doit pas échouer sur ce qu'elle a déjà fait. */
    private function aLIndex(string $table, string $nom): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $nom) {
                return true;
            }
        }

        return false;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le code vu dans les imports peut désormais nommer celui qui saisit.
 *
 * **Le trou que cela bouche.** Les fichiers du logiciel d'atelier portent, dans chaque
 * numéro de fiche, les deux lettres de la personne qui l'a rédigée. Certaines de ces
 * personnes ont un accès à l'application : leur code leur est attribué, et tout est dit.
 * D'autres n'en ont pas — elles saisissent dans le logiciel et nulle part ailleurs. Leur
 * code arrive quand même par l'import, et jusqu'ici il restait une énigme de deux lettres :
 * un volume de fiches, aucun nom, et personne à appeler pour lever un doute.
 *
 * Trois colonnes, et l'énigme devient un renseignement : le nom, le prénom, et la fonction
 * de celui qui porte le code. La fonction reste facultative — on ne la connaît pas toujours,
 * et ne pas la connaître ne doit pas empêcher de noter le nom.
 *
 * **Le lieu précis, lui, existait déjà** : `ville_id` et `site_id` sont sur cette table
 * depuis le premier jour, et c'est par eux que le rattachement des fiches se fait. On ne
 * les double pas d'un champ de texte qui finirait par les contredire.
 *
 * Additive et réversible : trois colonnes nullables sur `codes_agents`, rien d'autre.
 * Aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $colonnes = ['nom', 'prenom', 'fonction'];

    public function up(): void
    {
        Schema::table('codes_agents', function (Blueprint $table) {
            foreach ($this->colonnes as $colonne) {
                if (! Schema::hasColumn('codes_agents', $colonne)) {
                    $table->string($colonne, 120)->nullable()->after('libelle');
                }
            }
        });
    }

    public function down(): void
    {
        $presentes = array_values(array_filter(
            $this->colonnes,
            fn (string $colonne) => Schema::hasColumn('codes_agents', $colonne),
        ));

        if ($presentes === []) {
            return;
        }

        Schema::table('codes_agents', fn (Blueprint $table) => $table->dropColumn($presentes));
    }
};

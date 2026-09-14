<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le code d'atelier d'une personne, confirmé par elle.
 *
 * **Le problème qu'on règle.** Le code de deux lettres est la seule chose qui, dans les
 * fichiers du logiciel, dise de qui vient une fiche et à quel atelier elle appartient.
 * Il était jusqu'ici déduit des données et arbitré par le gérant : personne n'avait jamais
 * demandé à l'intéressé si c'était bien le sien. Or c'est lui qui le sait — il le voit sur
 * chacune de ses fiches, « FR-**KZ**N° 010669 ».
 *
 * Un code mal attribué ne se voit pas : les fiches partent dans le mauvais atelier, le
 * chiffre d'affaires du Site 1 gonfle de celui du Site 2, et rien ne signale l'erreur. La
 * seule vérification qui vaille est celle de la personne concernée, une fois, à l'écran.
 *
 * **Une colonne, et ce qu'elle veut dire.** `code_atelier_confirme_le` porte le moment où
 * la personne a dit « oui, c'est bien mon code ». Vide, la question lui est posée à chaque
 * ouverture d'écran. Et elle se remet à vide dès que quelqu'un change le code : une
 * confirmation porte sur un code précis, pas sur l'idée d'en avoir un.
 *
 * Additive et réversible : une colonne nullable sur `users`, rien d'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'code_atelier_confirme_le')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('code_atelier_confirme_le')->nullable()->after('rang_auteur');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'code_atelier_confirme_le')) {
            return;
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('code_atelier_confirme_le'));
    }
};

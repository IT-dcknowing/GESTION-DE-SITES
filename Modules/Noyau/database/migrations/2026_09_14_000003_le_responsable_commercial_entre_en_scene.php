<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;

/**
 * Le responsable commercial : il encadre les commerciaux d'une ville, et vend lui-même.
 *
 * **Ce qu'aucun rôle ne disait.** Entre le superviseur de ville, qui répond de tout ce
 * qui s'y passe, et le commercial, qui répond de ses propres affaires, il manquait celui
 * qui anime l'équipe de vente. Il était jusqu'ici ouvert en « commercial » — et se
 * retrouvait sans aucune vue sur les collègues qu'il encadre — ou en « superviseur de
 * ville », ce qui lui ouvrait la trésorerie, les charges et la gestion des accès dont il
 * n'a pas la charge. Les deux réponses étaient fausses, l'une par défaut, l'autre par
 * excès.
 *
 * **Il vend aussi.** C'est ce qui le distingue d'un pur encadrant : il porte ses propres
 * objectifs et sa propre fiche commercial, comme le superviseur de ville et le responsable
 * de site. Ses prospections lui sont rattachées et comptent dans le chiffre de sa ville.
 *
 * **Ce qu'il ne touche pas.** Ni le recouvrement, ni les imports. Encadrer des vendeurs
 * n'a aucun rapport avec la poursuite d'une créance ou le versement d'un fichier dans la
 * base ; lui ouvrir ces modules parce qu'il est « un responsable » reviendrait à traiter
 * la hiérarchie comme une hauteur alors qu'elle est aussi une branche.
 *
 * **La colonne qui accompagne le rôle.** Certains groupes n'ont qu'un animateur pour
 * toutes leurs villes. `couvre_toutes_les_villes` le dit explicitement, plutôt que de
 * laisser `ville_id` à nul — qui signifie déjà « pas encore rattaché ». Deux sens pour une
 * même absence de valeur, c'est la garantie qu'un jour l'un sera pris pour l'autre.
 */
return new class extends Migration
{
    private const ROLE = 'responsable_commercial';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'couvre_toutes_les_villes')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('couvre_toutes_les_villes')->default(false)->after('site_id');
            });
        }

        // Le rôle est créé pour chaque entreprise existante. Les entreprises ouvertes
        // ensuite le reçoivent par ProvisionneurEntreprise, qui porte la même liste.
        foreach (DB::table('entreprises')->pluck('id') as $entrepriseId) {
            $existe = DB::table('roles')
                ->where('name', self::ROLE)
                ->where('entreprise_id', $entrepriseId)
                ->exists();

            if ($existe) {
                continue;
            }

            DB::table('roles')->insert([
                'name' => self::ROLE,
                'guard_name' => 'web',
                'entreprise_id' => $entrepriseId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Rien ne bascule : personne ne porte ce rôle tant qu'on ne le lui attribue pas.
        // Une migration qui devinerait qui « est sans doute » responsable commercial
        // distribuerait des droits que personne n'a demandés.
        assert(in_array(self::ROLE, ProvisionneurEntreprise::ROLES, true));
    }

    public function down(): void
    {
        $roles = DB::table('roles')->where('name', self::ROLE)->pluck('id');

        if ($roles->isNotEmpty()) {
            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();
        }

        if (Schema::hasColumn('users', 'couvre_toutes_les_villes')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('couvre_toutes_les_villes'));
        }
    }
};

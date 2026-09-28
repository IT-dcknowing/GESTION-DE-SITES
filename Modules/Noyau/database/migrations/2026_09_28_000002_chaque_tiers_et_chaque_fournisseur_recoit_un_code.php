<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un code par tiers, un code par fournisseur.
 *
 * **La demande, du 28/09**, et le problème qu'elle règle : « on doit avoir un contrôle pour
 * éviter de saisir les mêmes clients plusieurs fois par faute d'orthographe […] mais si
 * deux portent le même nom ils doivent aussi être créés, donc pour cela, dans le logiciel,
 * donne un code à chaque client (client, courtier, assurance) ».
 *
 * Les deux moitiés de la phrase se tiennent, et c'est ce qui rend le code nécessaire :
 *
 *   - **empêcher le doublon involontaire** — « NSIA ASSURANCES » et « Nsia Assurance »
 *     coupent l'encours en deux, et l'on relance deux fois la moitié de la dette ;
 *   - **permettre l'homonyme volontaire** — deux sociétés portent réellement le même nom,
 *     et il faut pouvoir les distinguer autrement que par leur orthographe.
 *
 * Un nom ne peut pas faire les deux. Un code, si.
 *
 * **Pourquoi une table plutôt qu'une colonne sur `referentiels`.** Les tiers ne vivent pas
 * dans `referentiels` : mesuré le 28/09, cette table est **vide** en production. Ils
 * n'existent que comme chaînes dans les quatre colonnes des factures — `client`,
 * `assureur`, `courtier`, `depose_chez` — et le référentiel ne sert qu'à en déclarer un
 * qui n'a pas encore été facturé. Leur donner un code demandait donc un endroit où le
 * poser.
 *
 * **Le nom normalisé est la clé du contrôle.** C'est lui qu'on compare — insensible à la
 * casse, aux accents et à la ponctuation — pour dire « ce nom ressemble à celui-ci ». Il
 * n'est **pas** unique : deux homonymes volontaires le partagent, et c'est précisément le
 * cas qu'il faut permettre. L'unicité porte sur le code.
 *
 * Migration **additive** : deux créations, aucune réécriture. Les codes ne sont pas
 * attribués ici — les poser d'office sur 2 445 tiers serait une écriture de données sans
 * décision humaine. Ils s'attribuent à l'écran, par un geste qu'on voit et qui se trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tiers')) {
            Schema::create('tiers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

                // « T-0001 ». Unique dans l'entreprise, et c'est la seule unicité : c'est
                // lui qui distingue deux homonymes.
                $table->string('code', 20);

                // Le nom tel qu'il s'écrit sur les factures : c'est par cette chaîne que
                // la balance âgée, l'extrait de compte et les relances se rassemblent.
                $table->string('nom', 160);

                // Le même, sans casse ni accents ni ponctuation : c'est lui qu'on compare
                // pour prévenir du doublon. Volontairement **non unique**.
                $table->string('nom_normalise', 160);

                // Ce qu'il est sur les factures — client, assurance, courtier,
                // dépositaire. Un tiers en tient souvent plusieurs : la colonne dit ce
                // qu'on a déclaré, l'annuaire dit ce que les factures montrent.
                $table->string('role', 30)->nullable();

                $table->boolean('est_actif')->default(true);
                $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['entreprise_id', 'code'], 'tiers_code_unique');
                $table->index(['entreprise_id', 'nom_normalise'], 'tiers_nom_normalise');
            });
        }

        if (Schema::hasTable('referentiel_fournisseurs') && ! Schema::hasColumn('referentiel_fournisseurs', 'code')) {
            Schema::table('referentiel_fournisseurs', function (Blueprint $table) {
                // « FRS-0001 ». Nullable : les fiches venues du classeur n'en ont pas
                // encore, et leur en poser un d'office serait une écriture de données que
                // personne n'a demandée. La page les attribue par un geste visible.
                $table->string('code', 20)->nullable()->after('nom');
                $table->unique(['entreprise_id', 'code'], 'frs_code_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('referentiel_fournisseurs') && Schema::hasColumn('referentiel_fournisseurs', 'code')) {
            Schema::table('referentiel_fournisseurs', function (Blueprint $table) {
                $table->dropUnique('frs_code_unique');
                $table->dropColumn('code');
            });
        }

        Schema::dropIfExists('tiers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les écritures comptables se génèrent — 24/11.
 *
 * Additive seulement (règle de la base en ligne) : trois tables neuves et deux colonnes
 * facultatives sur `factures`. Rien n'est écrit dans les données existantes ; tant que la
 * migration n'est pas jouée, le module le dit (`SchemaDisponible`) au lieu de tomber.
 *
 * - `tiers_comptables` : le numéro auxiliaire de chaque client et fournisseur (410001…,
 *   400001…), distinct du compte collectif (411000, 401000), plus un tiers divers par nature.
 * - `operations_comptables` / `lignes_comptables` : une pièce = une opération équilibrée ; son
 *   règlement en est une autre. Chaque opération garde sa source (`source`, `source_id`) et
 *   l'empreinte de ce qu'elle a lu : une source modifiée est contre-passée, jamais réécrite.
 * - `factures.montant_ht` / `montant_tva` : lus **sur la facture**, par une personne. Le
 *   fichier du CA ne porte que le TTC ; la TVA ne se devine pas (décision du 24/11).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tiers_comptables')) {
            Schema::create('tiers_comptables', function (Blueprint $t) {
                $t->id();
                $t->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $t->string('nature', 12);                 // client | fournisseur
                $t->string('numero', 20);                 // 410001, 400001, 410000 (divers)
                $t->string('compte_general', 20);         // 411000 | 401000
                $t->string('nom', 191);
                $t->string('nom_normalise', 191);
                $t->string('ncc', 40)->nullable();
                $t->boolean('est_divers')->default(false);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('operations_comptables')) {
            Schema::create('operations_comptables', function (Blueprint $t) {
                $t->id();
                $t->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $t->unsignedBigInteger('numero_saisie');
                $t->string('nature', 30);                 // FactureVente, ReglementVente, FactureAchat…
                $t->string('source', 30);                 // facture, encaissement, caisse, suivi, fne…
                $t->unsignedBigInteger('source_id');
                $t->string('empreinte', 64);              // de ce qui a été lu sur la source
                $t->date('date_operation');
                $t->string('code_journal', 20);
                $t->string('reference_document', 100)->nullable();
                $t->string('libelle', 255);
                $t->foreignId('ville_id')->nullable();
                $t->foreignId('site_id')->nullable();
                $t->decimal('total_debit', 18, 4)->default(0);
                $t->decimal('total_credit', 18, 4)->default(0);
                // a_verifier : une donnée manque (TVA à lire, compte absent) — ne part pas.
                // prete : équilibrée, à déverser. contrepassee : annulée par une contre-passation.
                $t->string('statut', 20)->default('a_verifier');
                $t->text('motif')->nullable();
                $t->foreignId('contrepasse_operation_id')->nullable();
                $t->boolean('est_contrepassation')->default(false);
                // Déversement COMPTAFLOW
                $t->string('statut_deversement', 20)->default('non_envoyee'); // non_envoyee|envoyee|acceptee|refusee
                $t->text('message_deversement')->nullable();
                $t->unsignedSmallInteger('essais')->default(0);
                $t->timestamp('deversee_le')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('lignes_comptables')) {
            Schema::create('lignes_comptables', function (Blueprint $t) {
                $t->id();
                $t->foreignId('operation_id')->constrained('operations_comptables')->cascadeOnDelete();
                $t->foreignId('entreprise_id');
                $t->unsignedSmallInteger('rang');
                $t->string('compte', 20);
                $t->string('compte_tiers', 20)->nullable();
                $t->string('libelle', 255);
                $t->decimal('debit', 18, 4)->default(0);
                $t->decimal('credit', 18, 4)->default(0);
                $t->timestamps();
            });
        }

        // L'imputation d'une charge que la règle n'a pas su classer (« Autres ») : proposée par l'IA,
        // décidée par une personne. Le moteur ne lit que les imputations validées.
        if (! Schema::hasTable('imputations_comptables')) {
            Schema::create('imputations_comptables', function (Blueprint $t) {
                $t->id();
                $t->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $t->string('source', 30);
                $t->unsignedBigInteger('source_id');
                $t->string('cle_compte', 80);             // une clé du plan : « charge:Carburant & transport »
                $t->string('statut', 12)->default('proposee'); // proposee | validee | rejetee
                $t->boolean('par_ia')->default(true);
                $t->unsignedTinyInteger('confiance')->nullable();
                $t->string('raison', 500)->nullable();
                $t->foreignId('decide_par')->nullable();
                $t->timestamp('decide_le')->nullable();
                $t->timestamps();
            });
        }

        // Les index, nommés court et posés à part — 24/11. Le premier passage en ligne s'est arrêté sur
        // « Identifier name … is too long » (MySQL : 64 caractères au plus) après avoir créé
        // `operations_comptables` et une partie de ses index. Chaque index est donc ajouté s'il
        // manque : la migration se rejoue sur une base à moitié passée comme sur une base neuve.
        $this->index('tiers_comptables', 'tiers_cpt_numero_unique', ['entreprise_id', 'numero'], true,
            ['tiers_comptables_entreprise_id_numero_unique']);
        $this->index('tiers_comptables', 'tiers_cpt_nom_unique', ['entreprise_id', 'nature', 'nom_normalise'], true,
            ['tiers_comptables_entreprise_id_nature_nom_normalise_unique']);
        $this->index('tiers_comptables', 'tiers_cpt_ncc_index', ['entreprise_id', 'ncc'], false,
            ['tiers_comptables_entreprise_id_ncc_index']);
        $this->index('operations_comptables', 'ope_cpt_saisie_unique', ['entreprise_id', 'numero_saisie'], true,
            ['operations_comptables_entreprise_id_numero_saisie_unique']);
        $this->index('operations_comptables', 'ope_cpt_source_index', ['entreprise_id', 'source', 'source_id'], false,
            ['operations_comptables_entreprise_id_source_source_id_index']);
        $this->index('operations_comptables', 'ope_cpt_statuts_index', ['entreprise_id', 'statut', 'statut_deversement']);
        $this->index('operations_comptables', 'ope_cpt_date_index', ['entreprise_id', 'date_operation']);
        $this->index('lignes_comptables', 'lig_cpt_compte_index', ['entreprise_id', 'compte']);
        $this->index('imputations_comptables', 'imp_cpt_source_unique', ['entreprise_id', 'source', 'source_id'], true);

        foreach (['montant_ht', 'montant_tva'] as $colonne) {
            if (Schema::hasTable('factures') && ! Schema::hasColumn('factures', $colonne)) {
                Schema::table('factures', fn (Blueprint $t) => $t->decimal($colonne, 18, 4)->nullable());
            }
        }
        if (Schema::hasTable('factures') && ! Schema::hasColumn('factures', 'tva_lue_par')) {
            Schema::table('factures', function (Blueprint $t) {
                $t->foreignId('tva_lue_par')->nullable();
                $t->timestamp('tva_lue_le')->nullable();
            });
        }
    }

    /**
     * Ajoute un index s'il n'existe ni sous son nom, ni sous l'un des noms longs qu'un premier
     * passage interrompu a pu lui donner.
     *
     * @param  list<string>  $colonnes
     * @param  list<string>  $anciensNoms
     */
    private function index(string $table, string $nom, array $colonnes, bool $unique = false, array $anciensNoms = []): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ([$nom, ...$anciensNoms] as $existant) {
            if (Schema::hasIndex($table, $existant)) {
                return;
            }
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($colonnes, $nom) : $t->index($colonnes, $nom));
    }

    public function down(): void
    {
        if (Schema::hasTable('factures')) {
            foreach (['montant_ht', 'montant_tva', 'tva_lue_par', 'tva_lue_le'] as $colonne) {
                if (Schema::hasColumn('factures', $colonne)) {
                    Schema::table('factures', fn (Blueprint $t) => $t->dropColumn($colonne));
                }
            }
        }
        Schema::dropIfExists('imputations_comptables');
        Schema::dropIfExists('lignes_comptables');
        Schema::dropIfExists('operations_comptables');
        Schema::dropIfExists('tiers_comptables');
    }
};

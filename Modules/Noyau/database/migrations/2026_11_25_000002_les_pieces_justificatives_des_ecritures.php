<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les pièces justificatives des écritures — 25/11.
 *
 * Constat avant d'écrire : l'application ne garde **aucun PDF** des pièces qu'elle comptabilise.
 * La facture de vente vient du fichier du CA (des lignes, pas de document), la pièce du suivi
 * fournisseurs d'un classeur, la FNE du relevé JSON du portail — qui ne porte que le lien de
 * vérification de la DGI (`contenu.token`), pas le document. Le PDF d'une pièce n'existe donc que
 * si quelqu'un le joint ; c'est ce que tient `justificatifs_comptables`, une ligne par pièce
 * (facture, suivi, fne) et non par opération : une opération contre-passée puis remplacée garde
 * la même pièce.
 *
 * Sur `operations_comptables`, le suivi de l'envoi du PDF à COMPTAFLOW, qui ne part qu'après
 * l'acceptation des écritures (COMPTAFLOW rattache le fichier aux lignes déjà reçues).
 *
 * Additive et rejouable : tables et colonnes posées seulement si elles manquent, index nommés
 * court (64 caractères au plus pour MySQL — incident du 24/11).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('justificatifs_comptables')) {
            Schema::create('justificatifs_comptables', function (Blueprint $t) {
                $t->id();
                $t->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $t->string('source', 30);                  // facture | suivi | fne
                $t->unsignedBigInteger('source_id');
                $t->string('chemin', 500);                 // sur le disque privé « local »
                $t->string('nom_original', 255);
                $t->string('sha256', 64);
                $t->unsignedInteger('taille');
                $t->foreignId('depose_par')->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('justificatifs_comptables') && ! Schema::hasIndex('justificatifs_comptables', 'justif_cpt_source_unique')) {
            Schema::table('justificatifs_comptables', fn (Blueprint $t) => $t->unique(['entreprise_id', 'source', 'source_id'], 'justif_cpt_source_unique'));
        }

        if (Schema::hasTable('operations_comptables')) {
            foreach ([
                'justificatif_sha256' => fn (Blueprint $t) => $t->string('justificatif_sha256', 64)->nullable(),
                'justificatif_envoye_le' => fn (Blueprint $t) => $t->timestamp('justificatif_envoye_le')->nullable(),
                'justificatif_message' => fn (Blueprint $t) => $t->text('justificatif_message')->nullable(),
            ] as $colonne => $definition) {
                if (! Schema::hasColumn('operations_comptables', $colonne)) {
                    Schema::table('operations_comptables', $definition);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('operations_comptables')) {
            foreach (['justificatif_sha256', 'justificatif_envoye_le', 'justificatif_message'] as $colonne) {
                if (Schema::hasColumn('operations_comptables', $colonne)) {
                    Schema::table('operations_comptables', fn (Blueprint $t) => $t->dropColumn($colonne));
                }
            }
        }
        Schema::dropIfExists('justificatifs_comptables');
    }
};

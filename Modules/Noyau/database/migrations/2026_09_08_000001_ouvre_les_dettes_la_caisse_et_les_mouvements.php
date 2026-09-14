<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les destinations qui manquaient aux imports.
 *
 * Trois tables neuves, et quatre colonnes ajoutées à des tables existantes. Rien n'est
 * supprimé, rien n'est renommé, aucune valeur n'est touchée : sur une base qui porte des
 * écritures réelles, c'est la seule façon d'ouvrir un chantier de cette taille.
 *
 * **Pourquoi des tables neuves plutôt que de tout verser dans `charges` et
 * `encaissements`.** Ces deux-là sont les tables de saisie de la comptabilité, tenues à la
 * main, ligne par ligne. Y déverser des milliers de lignes importées mélangerait deux
 * natures de données qu'on ne saurait plus distinguer — et le jour où un import se révèle
 * faux, il n'y aurait aucun moyen de le retirer sans emporter la saisie manuelle avec lui.
 *
 * D'où la règle appliquée ici : **ce qui vient d'un fichier vit dans sa propre table**, et
 * les écrans d'indicateurs additionnent les deux sources. C'est un peu plus de travail à
 * l'affichage, et beaucoup moins de regrets à la reprise.
 *
 * Les quatre colonnes ajoutées (`lot_import_id`) servent le même principe pour les tables
 * qui, elles, reçoivent légitimement de l'import — factures et devis existent déjà avec la
 * bonne forme. Elles disent quel dépôt a écrit la ligne, donc laquelle retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Les factures fournisseurs — le KPI « dettes », qui n'avait nulle part où atterrir.
         *
         * Le fichier de suivi fournisseurs porte 40 colonnes ; on en retient celles qui
         * répondent à trois questions : combien doit-on, à qui, et pour quand. Les colonnes
         * de marge suivent, parce qu'elles sont dans le même fichier et qu'elles répondent à
         * la quatrième question, celle que personne ne pose mais que tout le monde a en
         * tête : est-ce qu'on gagne de l'argent sur cette pièce.
         */
        Schema::create('factures_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->string('numero_piece', 60);
            $table->string('nature_piece', 60)->nullable();
            $table->string('numero_bc', 60)->nullable();
            $table->string('fournisseur', 200);

            $table->date('date_facture')->nullable();
            $table->date('date_reception')->nullable();
            $table->date('date_echeance')->nullable();
            $table->date('date_reglement')->nullable();

            // En francs CFA, sans décimale : la devise n'en a pas, et un entier ne dérive
            // jamais alors qu'un flottant finit toujours par afficher 249 999,99999.
            $table->bigInteger('montant')->default(0);
            $table->bigInteger('montant_regle')->default(0);
            $table->bigInteger('reste_a_payer')->default(0);
            $table->bigInteger('montant_refacture')->default(0);
            $table->bigInteger('marge')->default(0);

            $table->string('mode_reglement', 60)->nullable();
            $table->string('imputation', 120)->nullable();
            $table->string('immatriculation', 40)->nullable();
            $table->string('numero_fiche', 40)->nullable();
            $table->string('numero_facture_client', 60)->nullable();
            $table->text('observations')->nullable();

            $table->string('code_agent', 8)->nullable();
            $table->string('source_rattachement', 20)->default('inconnue');
            $table->boolean('rattachement_presume')->default(false);
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            $table->timestamps();

            $table->unique(['entreprise_id', 'numero_piece', 'fournisseur'], 'frs_piece_unique');
            $table->index(['entreprise_id', 'date_echeance'], 'frs_echeance_index');
            $table->index(['entreprise_id', 'fournisseur'], 'frs_fournisseur_index');
            $table->index(['entreprise_id', 'ville_id', 'date_facture'], 'frs_ville_date_index');
        });

        /*
         * Le journal de caisse — la trésorerie réelle, jour par jour.
         *
         * Une seule table pour les deux sens : le fichier tient les entrées et les sorties
         * dans deux colonnes voisines, et les séparer en deux tables obligerait à les
         * recoller pour retrouver le solde. Le signe est porté par `sens`, le montant reste
         * toujours positif — c'est plus lisible qu'un nombre négatif dans un tableau.
         */
        Schema::create('mouvements_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->date('date');
            $table->string('sens', 8);
            $table->string('libelle', 255);
            $table->bigInteger('montant')->default(0);

            // Le solde tel que le fichier l'affiche. On ne le recalcule pas : s'il diverge
            // de notre propre cumul, c'est une information — le fichier a été retouché.
            $table->bigInteger('solde_annonce')->nullable();

            $table->string('beneficiaire', 200)->nullable();
            $table->string('immatriculation', 40)->nullable();
            $table->string('mois', 20)->nullable();
            $table->string('feuille', 60)->nullable();

            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();
            $table->timestamps();

            $table->index(['entreprise_id', 'ville_id', 'date'], 'caisse_ville_date_index');
            $table->index(['entreprise_id', 'sens'], 'caisse_sens_index');
        });

        /*
         * Les entrées et sorties de véhicules.
         *
         * Ces états ne sortent aujourd'hui qu'en PDF, et la demande était claire : préparer
         * les colonnes et la table de destination, pas davantage. La voici donc, prête à
         * recevoir — le jour où l'export Excel existera, ou le jour où l'API répondra, il n'y
         * aura qu'un lecteur à brancher.
         */
        Schema::create('mouvements_vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->string('sens', 8);
            $table->date('date');
            $table->string('numero_fiche', 40)->nullable();
            $table->string('immatriculation', 40)->nullable();
            $table->string('marque', 60)->nullable();
            $table->string('modele', 60)->nullable();
            $table->string('client', 160)->nullable();
            $table->string('motif', 120)->nullable();
            $table->text('observations')->nullable();

            $table->string('code_agent', 8)->nullable();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();
            $table->timestamps();

            $table->index(['entreprise_id', 'sens', 'date'], 'mvt_vehicules_sens_date_index');
            $table->index(['entreprise_id', 'numero_fiche'], 'mvt_vehicules_fiche_index');
        });

        /*
         * La trace du dépôt sur les tables qui reçoivent déjà de l'import.
         *
         * Nullable, et c'est tout le point : une ligne saisie à la main n'a pas de lot, et
         * doit pouvoir continuer de ne pas en avoir. La colonne distingue les deux origines
         * sans rien imposer à l'existant.
         */
        foreach (['factures', 'devis', 'encaissements', 'charges'] as $nom) {
            if (! Schema::hasColumn($nom, 'lot_import_id')) {
                Schema::table($nom, function (Blueprint $table) use ($nom) {
                    $table->foreignId('lot_import_id')->nullable()->after('id')
                        ->constrained('lots_import')->nullOnDelete();
                    $table->index(['entreprise_id', 'lot_import_id'], substr($nom, 0, 12).'_lot_index');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['factures', 'devis', 'encaissements', 'charges'] as $nom) {
            if (Schema::hasColumn($nom, 'lot_import_id')) {
                Schema::table($nom, function (Blueprint $table) use ($nom) {
                    $table->dropIndex(substr($nom, 0, 12).'_lot_index');
                    $table->dropConstrainedForeignId('lot_import_id');
                });
            }
        }

        Schema::dropIfExists('mouvements_vehicules');
        Schema::dropIfExists('mouvements_caisse');
        Schema::dropIfExists('factures_fournisseurs');
    }
};

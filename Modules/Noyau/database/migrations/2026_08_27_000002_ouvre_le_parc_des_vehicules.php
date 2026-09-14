<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La situation du parc : la fiche de réception, et ce qu'elle devient.
 *
 * Une table neuve, aucune touchée — la base porte des écritures réelles et rien de ce qui
 * existe ne bouge.
 *
 * **Pourquoi cette table s'importe la première.** La fiche de réception est la seule pièce
 * que tous les autres fichiers citent : le devis porte « FICHE DE RECEPTION », la facture
 * aussi. C'est elle qui établit « telle affaire appartient à telle ville », et tout le
 * reste s'y raccroche. On l'avait d'abord placée après les factures dans le plan ; la
 * comparaison des exports a montré l'inverse — 0 numéro de facture en commun entre l'état
 * des impayés et le CATTC, mais la fiche de réception présente des deux côtés.
 *
 * Les trois colonnes de rattachement — `ville_id`, `site_id`, `source_rattachement` — sont
 * le cœur du sujet : dans le logiciel, les trois villes vivent dans la même base, et rien
 * dans une ligne ne dit d'où elle vient sauf le code de deux lettres du numéro de fiche.
 * `site_id` accepte le vide, et c'est voulu : Abidjan a deux ateliers qu'aucun fichier ne
 * distingue. Tant que le code n'est pas rattaché à un atelier, la ligne s'arrête à la
 * ville — un chiffre en attente d'affectation vaut mieux qu'un chiffre attribué au hasard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers_vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // Où est allée la ligne, et pourquoi. La source est recopiée pour qu'on puisse
            // contester la décision six mois plus tard sans rejouer l'import.
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('code_agent', 8)->nullable();
            $table->string('source_rattachement', 20)->default('inconnue');
            $table->boolean('rattachement_presume')->default(false);

            // « FR-KKN° 010343 ». Unique par entreprise : c'est l'identité de l'affaire, et
            // on a vérifié fichier en main qu'aucun numéro n'apparaît dans deux villes.
            $table->string('numero_fiche', 40);

            $table->string('immatriculation', 40)->nullable();
            $table->string('marque', 60)->nullable();
            $table->string('modele', 60)->nullable();

            // Le logiciel mêle client et assurance dans une seule colonne ; on garde le
            // libellé tel quel plutôt que de deviner lequel des deux c'est.
            $table->string('client', 160)->nullable();
            $table->string('proprietaire', 160)->nullable();

            $table->string('motif', 60)->nullable();
            $table->string('statut', 80)->nullable();
            $table->text('travaux')->nullable();
            $table->text('informations')->nullable();

            $table->date('date_fiche')->nullable();
            $table->date('date_fin_prevue')->nullable();
            $table->date('date_theorique_atelier')->nullable();
            $table->date('date_transmission_devis')->nullable();
            $table->date('date_traitement_feb')->nullable();
            $table->date('date_effective_travaux')->nullable();
            $table->date('date_fin_travaux')->nullable();

            // Quel dépôt a écrit cette ligne pour la dernière fois. Le lot peut disparaître
            // sans emporter la fiche : la donnée survit à sa provenance.
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            $table->timestamps();

            $table->unique(['entreprise_id', 'numero_fiche'], 'dossiers_fiche_unique');
            $table->index(['entreprise_id', 'ville_id', 'date_fiche'], 'dossiers_ville_date_index');
            $table->index(['entreprise_id', 'site_id'], 'dossiers_site_index');
            $table->index(['entreprise_id', 'statut'], 'dossiers_statut_index');
            $table->index(['entreprise_id', 'immatriculation'], 'dossiers_immat_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers_vehicules');
    }
};

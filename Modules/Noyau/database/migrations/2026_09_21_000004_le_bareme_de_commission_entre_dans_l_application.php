<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le barème de commission devient une donnée, et non une règle écrite dans le code.
 *
 * **Pourquoi des tables plutôt qu'un tableau en PHP.** Le document de référence
 * (`Commission_Commerciaux_Artisan (1)_vf6.pdf`) laisse quatre questions ouvertes : le seuil
 * d'entrée (le texte dit « aucune commission sous 25 M », la grille ouvre à 20 M), les trous
 * entre tranches (25–26 M, 30–31 M), les bornes qui se chevauchent chez le responsable (40,
 * 50 et 60 M appartiennent à deux tranches), et l'assiette. Écrire ces valeurs dans le code
 * obligerait à un déploiement à chaque arbitrage, et surtout à nous demander la permission
 * pour changer un taux. Ce sont des tables : le gérant tranche lui-même, et voit aussitôt le
 * résultat.
 *
 * **La date d'effet.** Un barème changé ne réécrit jamais le passé. Chaque grille porte le
 * jour à partir duquel elle s'applique ; le mois d'octobre se calcule avec la grille en
 * vigueur en octobre, même si une autre est posée en décembre. C'est la seule façon de
 * pouvoir rouvrir une commission d'il y a six mois et retrouver le chiffre versé.
 *
 * **Migration additive** : deux tables neuves, aucune ligne existante touchée. Elles
 * naissent **vides** — rien n'écrit de barème automatiquement, ni ici ni dans
 * `app:deployer`. La grille du document est proposée à l'écran, et posée d'un clic par le
 * gérant, qui l'aura lue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baremes_commission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();

            // À qui la grille s'applique : « commercial » ou « responsable ». Le document en
            // donne deux, et rien ne dit qu'il n'y en aura jamais une troisième — d'où une
            // chaîne et non un booléen.
            $table->string('cible', 40);

            $table->string('libelle', 160);

            // Le jour à partir duquel la grille s'applique. Deux grilles de même cible ne
            // peuvent pas commencer le même jour : sinon aucune des deux ne serait « celle
            // qui s'applique ».
            $table->date('date_effet');

            /*
             * L'assiette : « global » ou « tranche ».
             *
             * Le document tranche lui-même, et l'arithmétique le confirme : « Commission
             * appliquée sur le chiffre d'affaires global mensuel HT », et la commission
             * indicative de la tranche 20–25 M vaut 200 000 F, soit 1 % de 20 M — donc du
             * chiffre entier, non de la part au-dessus d'un seuil. La seconde façon de
             * compter reste offerte parce qu'elle est la plus répandue ailleurs, et qu'un
             * barème futur pourrait l'adopter.
             */
            $table->string('assiette', 20)->default('global');

            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();

            // Le nom est recopié : l'accès peut disparaître, la décision reste attribuée.
            $table->string('auteur', 120)->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'cible', 'date_effet'], 'bareme_cible_effet_unique');
        });

        Schema::create('tranches_bareme', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bareme_commission_id')->constrained('baremes_commission')->cascadeOnDelete();

            // Le plancher est atteint, le plafond ne l'est pas : une tranche va de son
            // plancher inclus au plancher de la suivante exclu. C'est ce qui empêche
            // qu'un chiffre d'affaires de 40 M appartienne à deux tranches, comme dans le
            // document. Un plafond nul veut dire « et au-delà ».
            $table->unsignedBigInteger('plancher');
            $table->unsignedBigInteger('plafond')->nullable();

            // En pourcentage, tel qu'on le lit : 1.500 pour 1,5 %.
            $table->decimal('taux', 6, 3);

            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['bareme_commission_id', 'plancher'], 'tranche_bareme_plancher_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tranches_bareme');
        Schema::dropIfExists('baremes_commission');
    }
};

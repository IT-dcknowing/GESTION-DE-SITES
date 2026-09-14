<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ouverture du module Import.
 *
 * Quatre tables neuves, aucune touchée. Sur une base qui porte des écritures réelles,
 * c'est la seule façon d'ouvrir un chantier de cette taille sans rien risquer : tant
 * qu'aucun import n'a tourné, ces tables sont vides et l'application se comporte
 * exactement comme avant.
 *
 * Les trois idées qu'elles portent :
 *
 * 1. **Le lot** — chaque dépôt de fichier laisse une trace complète : qui, quand, quoi,
 *    combien de lignes lues, créées, mises à jour, rejetées. Un import qu'on ne peut pas
 *    raconter après coup n'est pas un import, c'est un accident.
 *
 * 2. **Le rejet** — une ligne qu'on ne sait pas lire n'est jamais devinée ni écrasée :
 *    elle est mise de côté avec son motif et ses valeurs d'origine. C'est ce qui permet
 *    de corriger le fichier plutôt que la base.
 *
 * 3. **Le code agent** — dans le logiciel, les trois villes sont mélangées ; on ne les
 *    distingue que par le code de deux lettres inscrit dans le numéro de fiche
 *    (« FR-KZN° 010669 »). Ce référentiel est donc la pièce maîtresse du rattachement,
 *    et il descend jusqu'au **site** : Abidjan en a deux, et aucun fichier ne les
 *    distingue — seul le code de la personne qui a rédigé la fiche le peut.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Le dépôt d'un fichier, et ce qu'il est devenu.
         *
         * L'empreinte est unique par entreprise : le même fichier ne se dépose pas deux
         * fois sans qu'on le dise. Ce n'est pas un interdit — un fichier cumulatif se
         * redépose légitimement — mais l'écran doit pouvoir annoncer « celui-ci est déjà
         * passé le 20/08, déposé par K. Désirée » avant qu'on ne relance le traitement.
         */
        Schema::create('lots_import', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // La ville déclarée au dépôt. Elle ne décide pas du rattachement des lignes
            // — ce sont la colonne SITE et le code agent qui tranchent — mais elle borne
            // ce que le déposant a le droit d'écrire.
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();

            // L'auteur peut partir ; ce qu'il a importé reste. Son nom est recopié à côté
            // du lien, comme pour les relances de recouvrement.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deposant', 120);

            $table->string('format', 40)->index();
            $table->string('nom_fichier', 255);
            $table->string('empreinte', 64);
            $table->unsignedBigInteger('taille')->default(0);

            // La période couverte, quand le fichier l'annonce (les états WinDev le font).
            $table->string('periode', 40)->nullable();

            $table->unsignedInteger('lignes_lues')->default(0);
            $table->unsignedInteger('lignes_creees')->default(0);
            $table->unsignedInteger('lignes_majs')->default(0);
            $table->unsignedInteger('lignes_ignorees')->default(0);
            $table->unsignedInteger('lignes_rejetees')->default(0);

            // depose : le fichier est là, rien n'est lu.
            // controle : lu et analysé, rien n'est écrit — c'est la simulation.
            // en_cours / termine / echec / annule : le traitement lui-même.
            $table->string('etat', 20)->default('depose')->index();
            $table->text('message')->nullable();

            $table->timestamp('demarre_le')->nullable();
            $table->timestamp('termine_le')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'empreinte'], 'lots_import_empreinte_unique');
            $table->index(['entreprise_id', 'format', 'created_at'], 'lots_import_parcours_index');
        });

        /*
         * Ce qu'on n'a pas su lire.
         *
         * Les valeurs d'origine sont conservées telles quelles : sans elles, « ligne 4212
         * rejetée » n'aide personne à corriger quoi que ce soit. Avec elles, on voit la
         * date impossible ou le fournisseur inconnu, et on répare la source.
         */
        Schema::create('lignes_rejetees_import', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_import_id')->constrained('lots_import')->cascadeOnDelete();

            $table->string('feuille', 120)->nullable();
            $table->unsignedInteger('numero_ligne');
            $table->string('motif', 255);
            $table->json('valeurs');

            $table->timestamps();

            $table->index(['lot_import_id', 'numero_ligne'], 'rejets_lot_ligne_index');
        });

        /*
         * Le code de deux lettres, et où travaille la personne qui le porte.
         *
         * Unique par entreprise, et c'est une règle métier, pas une commodité technique :
         * un code désigne une personne, une personne travaille sur un site. Les rares
         * fiches d'un code étranger à leur ville — un dépannage entre sites, une faute de
         * saisie — remontent en arbitrage plutôt que d'être classées d'office.
         *
         * `site_id` est facultatif parce qu'il ne se remplit pas tout seul : Bouaké et San
         * Pédro n'ont qu'un site, mais Abidjan en a deux, et **aucun fichier ne dit
         * lequel**. Tant que la répartition n'est pas faite, la ligne s'arrête à la ville.
         */
        Schema::create('codes_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code', 8);
            $table->string('libelle', 120)->nullable();

            // Ce que les imports ont rencontré : sert à trier les codes à nommer en tête
            // de liste, les plus fréquents d'abord.
            $table->unsignedInteger('occurrences')->default(0);
            $table->boolean('est_actif')->default(true);

            $table->timestamps();

            $table->unique(['entreprise_id', 'code'], 'codes_agents_code_unique');
            $table->index(['entreprise_id', 'ville_id'], 'codes_agents_ville_index');
        });

        /*
         * La table qui absorbe les fautes de frappe.
         *
         * « ABIIDJAN » avec un i de trop, « SAN-PEDRO » avec un tiret, « ÄBIDJAN » avec un
         * tréma : trois valeurs relevées dans les vrais fichiers, pour deux villes. Une
         * comparaison de chaînes les rejetterait toutes les trois ; une table de
         * correspondance les rattache une fois pour toutes.
         *
         * Elle sert aussi aux fournisseurs, aux clients, aux modes de règlement — partout
         * où une valeur libre doit rejoindre un référentiel.
         */
        Schema::create('correspondances_import', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // site | ville | fournisseur | tiers | moyen | imputation | statut | motif
            $table->string('domaine', 40);
            $table->string('valeur_source', 255);
            $table->string('valeur_cible', 255)->nullable();

            // Quand la cible est une ligne de la base plutôt qu'un libellé.
            $table->unsignedBigInteger('cible_id')->nullable();

            // Une correspondance non résolue est une question posée à l'utilisateur :
            // elle existe, elle est comptée, et l'écran la met en avant.
            $table->boolean('est_resolue')->default(false);
            $table->unsignedInteger('occurrences')->default(0);

            $table->timestamps();

            $table->unique(['entreprise_id', 'domaine', 'valeur_source'], 'correspondances_source_unique');
            $table->index(['entreprise_id', 'domaine', 'est_resolue'], 'correspondances_a_resoudre_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondances_import');
        Schema::dropIfExists('codes_agents');
        Schema::dropIfExists('lignes_rejetees_import');
        Schema::dropIfExists('lots_import');
    }
};

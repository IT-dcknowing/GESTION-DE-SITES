<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal de caisse entre, avec les colonnes que l'écran n'avait pas.
 *
 * **Ce que l'écran montrait, et d'où cela venait.** La page *Caisse* était bâtie sur le
 * classeur tenu à la main d'Abidjan : date, libellé, entrées, sorties, solde,
 * immatriculation, bénéficiaire. Le **journal de caisse** — la sortie du logiciel
 * comptable, celle que Bouaké et San-Pédro sont seules à avoir — porte davantage, et
 * c'est ce davantage que la règle « les colonnes d'une page listent d'abord celles du
 * fichier d'origine » réclamait :
 *
 * - un **numéro de pièce** et son **type de journal** (`MC`, `MC KR`, `MC KD`) ;
 * - un **motif** codifié — APPROV CAISSE, ACHATS DIVERS, CARBURANT — distinct du détail
 *   libre que l'opérateur écrit en dessous ;
 * - le **rôle du tiers** : le document dit « REMETTANT » quand l'argent entre et
 *   « BÉNÉFICIAIRE » quand il sort. Le classeur d'Abidjan mêle les deux dans une seule
 *   colonne intitulée « Bénéficiaire (fournisseurs)/remettant (client) » ; le journal les
 *   sépare, et on garde la distinction quand elle est donnée ;
 * - le **nom de la caisse** — « CAISSE BOUAKE », « CAISSE SAN-PEDRO ». Sans lui, deux
 *   caisses d'une même ville se confondraient ;
 * - la **page** du document où la ligne se trouve, pour qu'une ligne douteuse se retrouve
 *   papier en main.
 *
 * Le **solde progressif** et le **solde avant période** n'ont rien demandé de neuf du
 * premier côté : `solde_annonce` existait déjà et portait, ligne à ligne, le solde que le
 * fichier affiche. Il n'était simplement affiché nulle part.
 *
 * **Le solde avant période, lui, a sa table.** C'est une donnée du document et non d'un
 * mouvement : une caisse, une période, un solde de départ. Le recalculer à partir des
 * mouvements que nous avons serait faux — nous n'avons que ce que le document couvre, et
 * la caisse vivait avant. Le document l'annonce ; on le recopie, comme on recopie déjà le
 * solde de chaque ligne.
 *
 * **Additive d'un bout à l'autre** : des colonnes nullables sur une table qui existe, une
 * table neuve, et un élargissement du libellé. Les 1 155 mouvements déjà importés ne sont
 * pas touchés ; ils se compléteront d'eux-mêmes au prochain dépôt, ligne par ligne.
 */
return new class extends Migration
{
    /**
     * Ce que le journal porte et que la table n'avait pas.
     *
     * @var array<string, int>
     */
    private array $colonnes = [
        'numero_piece' => 40,
        'type_piece' => 20,
        'motif' => 190,
        'role_tiers' => 20,
        'caisse' => 120,
    ];

    public function up(): void
    {
        $apres = 'libelle';

        foreach ($this->colonnes as $nom => $longueur) {
            if (Schema::hasColumn('mouvements_caisse', $nom)) {
                $apres = $nom;

                continue;
            }

            Schema::table('mouvements_caisse', function (Blueprint $table) use ($nom, $longueur, $apres) {
                $table->string($nom, $longueur)->nullable()->after($apres);
            });

            $apres = $nom;
        }

        if (! Schema::hasColumn('mouvements_caisse', 'page')) {
            Schema::table('mouvements_caisse', function (Blueprint $table) {
                // Où lire la ligne dans le document imprimé. Sans numéro de ligne de
                // tableur, c'est le seul repère qu'on puisse rendre à qui vérifie.
                $table->unsignedSmallInteger('page')->nullable()->after('feuille');
            });
        }

        /*
         * Le libellé s'élargit, et c'est une contrainte relâchée, pas une donnée touchée.
         *
         * Le journal empile trois lignes dans sa cellule de libellé : la description, le
         * tiers, puis le détail libre. Une fois le numéro de pièce, le motif et le tiers
         * rangés dans leurs colonnes, il reste le détail — jusqu'à 95 caractères sur le
         * fichier de San-Pédro, davantage quand l'opérateur détaille deux travaux sur la
         * même pièce. Tronquer à 255 perdrait justement ce qu'on cherchait à lire.
         */
        Schema::table('mouvements_caisse', fn (Blueprint $table) => $table
            ->string('libelle', 500)->change());

        if (! $this->indexExiste('caisse_piece_index')) {
            Schema::table('mouvements_caisse', fn (Blueprint $table) => $table->index(
                ['entreprise_id', 'caisse', 'numero_piece'],
                'caisse_piece_index',
            ));
        }

        /*
         * Le solde d'avant, tel que la source l'annonce.
         *
         * Une ligne par document déposé : la caisse, la période qu'il couvre, et le solde
         * avec lequel elle s'ouvre. Redéposer le même document met à jour cette ligne au
         * lieu d'en ajouter une seconde — c'est une photographie, pas un événement, comme
         * la balance fournisseur.
         *
         * Le classeur tenu à la main d'Abidjan en a un lui aussi, qu'on ne lisait pas : il
         * écrit « SOLDE D'OUVERTURE » en quatrième ligne de chaque onglet mensuel.
         */
        if (! Schema::hasTable('ouvertures_caisse')) {
            Schema::create('ouvertures_caisse', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

                $table->string('caisse', 120);
                $table->date('debut')->nullable();
                $table->date('fin')->nullable();

                // En francs CFA, sans décimale, et signé : une caisse peut être annoncée
                // à découvert, et le cacher serait pire que de l'afficher.
                $table->bigInteger('solde_avant')->default(0);

                // D'où vient l'annonce : le journal imprimé, ou l'onglet du classeur.
                $table->string('source', 40)->nullable();
                $table->string('feuille', 60)->nullable();

                $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();
                $table->timestamps();

                // La ville entre dans la clé : deux villes peuvent tenir chacune une
                // caisse sans nom propre — le classeur d'Abidjan s'intitule simplement
                // « CAISSE DU MOIS DE … » — et leurs ouvertures ne doivent pas se
                // confondre. Une ville nulle (dépôt non filtré) échappe à l'index unique,
                // comme toujours en SQL ; c'est le code qui rapproche alors la ligne.
                $table->unique(
                    ['entreprise_id', 'ville_id', 'caisse', 'debut', 'fin'],
                    'caisse_ouverture_unique',
                );
                $table->index(['entreprise_id', 'ville_id', 'debut'], 'caisse_ouverture_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ouvertures_caisse');

        if ($this->indexExiste('caisse_piece_index')) {
            Schema::table('mouvements_caisse', fn (Blueprint $table) => $table->dropIndex('caisse_piece_index'));
        }

        // Le libellé ne se rétrécit pas : des lignes plus longues que 255 caractères ont pu
        // entrer entre-temps, et les raccourcir perdrait leur fin sans le dire.
        $presentes = array_values(array_filter(
            array_keys($this->colonnes),
            fn (string $nom) => Schema::hasColumn('mouvements_caisse', $nom),
        ));

        if (Schema::hasColumn('mouvements_caisse', 'page')) {
            $presentes[] = 'page';
        }

        if ($presentes !== []) {
            Schema::table('mouvements_caisse', fn (Blueprint $table) => $table->dropColumn($presentes));
        }
    }

    private function indexExiste(string $nom): bool
    {
        foreach (Schema::getIndexes('mouvements_caisse') as $index) {
            if (($index['name'] ?? null) === $nom) {
                return true;
            }
        }

        return false;
    }
};

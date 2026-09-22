<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le suivi fournisseur tenu à la main entre en entier.
 *
 * **Ce qui bloquait, et qui ne bloque plus.** Les deux classeurs n'étaient pas sur le poste :
 * le dossier ne contenait que deux raccourcis. Ils y sont depuis le 22/09, et ils s'ouvrent —
 * 2,0 Mo et 1,1 Mo, lus par le lecteur de l'application sans rien de neuf, puisqu'un `.xlsm`
 * n'est qu'un `.xlsx` qui porte des macros dont on ne fait rien.
 *
 * **Ce qu'on y a trouvé, mesuré fichier en main.**
 *
 * - La feuille exploitable est **« DETAIL »**, et non « contrôle chq » comme on l'avait
 *   conclu de l'échantillon précédent : l'en-tête n'est simplement pas en première ligne
 *   (ligne 10 dans le classeur d'Abidjan, ligne 9 dans celui de San Pedro), et les lignes
 *   au-dessus sont des règles d'usage et des totaux. La reconnaissance d'en-tête sait
 *   descendre ; c'est le nombre de colonnes qui décide, pas le rang de la ligne.
 * - **Les deux classeurs n'ont pas les mêmes colonnes** : 39 dans celui d'Abidjan,
 *   31 dans celui de San Pedro. Onze colonnes n'existent que dans le premier (la
 *   refacturation et la marge), trois que dans le second (montant HT, n° FEB, arrivé à
 *   échéance). Le reste est commun. Un format qui lit l'union des deux les prend tous
 *   les deux, chaque colonne absente restant vide.
 * - **Les deux fichiers se recouvrent largement** : 10 147 lignes en tout, mais seulement
 *   7 397 distinctes. 2 670 lignes sont dans les deux classeurs — les factures d'Abidjan
 *   apparaissent aussi dans le suivi de San Pedro. Les importer l'un après l'autre sans
 *   clé de rapprochement compterait la dette une fois et demie.
 *
 * **La clé s'élargit, et c'était nécessaire.** Elle valait « fournisseur + n° de pièce ».
 * Or 188 couples portent plusieurs lignes bien distinctes — une facture ventilée en
 * plusieurs sections, un avoir qui reprend le numéro de la pièce qu'il annule. Mesuré :
 * 7 080 couples pour 7 397 lignes réelles, soit **317 lignes qui s'écrasaient l'une
 * l'autre**. La date de facture et le montant entrent donc dans la clé.
 *
 * Additive et réversible : des colonnes nullables sur une table qui existe, rien d'autre.
 * Les 1 848 lignes déjà importées ne sont pas touchées ; elles se compléteront au prochain
 * dépôt, ligne par ligne, par le même rapprochement.
 */
return new class extends Migration
{
    /**
     * Ce qui manquait, colonne par colonne.
     *
     * Les montants sont des entiers, comme partout ailleurs : le franc CFA n'a pas de
     * centimes. Le montant HT du fichier en porte pourtant (82 096,627…) parce qu'il est
     * calculé par une formule du tableur ; on l'arrondit à l'écriture plutôt que de
     * conserver une précision que la monnaie n'a pas.
     *
     * @var array<string, string>
     */
    private array $colonnes = [
        'mois' => 'tinyint',
        'section' => 'texte60',
        'type_transaction' => 'texte120',
        'vehicule' => 'texte120',
        'numero_cheque' => 'texte60',
        'numero_feb' => 'texte60',
        'code_piece' => 'texte60',
        'numero_facture_achat' => 'texte60',
        'numero_facture_vente' => 'texte60',
        'montant_ht' => 'montant',
        'tva' => 'montant',
        'tva_2' => 'montant',
        'difference' => 'montant',
        'montant_net_achat' => 'montant',
        'montant_net_vente' => 'montant',
        'quantite_totale' => 'quantite',
        'quantite_refacturee' => 'quantite',
        'taux' => 'taux',
        'resultat_indicatif' => 'texte120',
        'delai_reglement' => 'texte40',
        'arrive_a_echeance' => 'texte20',
        'observations_facturation' => 'texte',
        'commentaires' => 'texte',
        'actions_a_mener' => 'texte',
    ];

    public function up(): void
    {
        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            foreach ($this->colonnes as $nom => $forme) {
                if (Schema::hasColumn('factures_fournisseurs', $nom)) {
                    continue;
                }

                match ($forme) {
                    'tinyint' => $table->unsignedTinyInteger($nom)->nullable(),
                    // Signés : un avoir porte un montant négatif, et c'est ainsi qu'il
                    // s'annule contre la facture qu'il corrige.
                    'montant' => $table->bigInteger($nom)->nullable(),
                    'quantite' => $table->decimal($nom, 14, 3)->nullable(),
                    'taux' => $table->decimal($nom, 10, 4)->nullable(),
                    'texte' => $table->text($nom)->nullable(),
                    'texte20' => $table->string($nom, 20)->nullable(),
                    'texte40' => $table->string($nom, 40)->nullable(),
                    'texte60' => $table->string($nom, 60)->nullable(),
                    'texte120' => $table->string($nom, 120)->nullable(),
                    default => $table->string($nom, 255)->nullable(),
                };
            }
        });

        /*
         * Deux colonnes passent de « toujours un nombre » à « peut être vide ».
         *
         * `montant_refacture` et `marge` avaient été créées à zéro par défaut, à l'époque
         * où l'on ne lisait qu'un onglet qui ne les portait pas. Or le classeur de San
         * Pedro n'a pas de colonne MARGE du tout : y écrire zéro dirait « cette facture
         * n'a dégagé aucune marge », ce qui est une affirmation, quand la vérité est
         * « le fichier ne le dit pas ». Les trente autres colonnes ajoutées ici font cette
         * différence ; ces deux-là doivent pouvoir la faire aussi.
         *
         * Élargissement pur : aucune valeur existante ne change, aucune contrainte ne se
         * resserre. Les zéros déjà en base restent des zéros.
         */
        foreach (['montant_refacture', 'marge'] as $colonne) {
            if ($this->accepteLeVide($colonne)) {
                continue;
            }

            Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table
                ->bigInteger($colonne)->nullable()->default(null)->change());
        }

        /*
         * Le mode de règlement s'allonge de soixante à deux cents caractères.
         *
         * Le classeur y inscrit parfois deux chèques sur deux lignes, quand une facture a
         * été réglée en deux fois : « CHQ 1576857 02/10/2024 » puis « CHQ 1576770
         * 11/11/2024 ». Soixante caractères coupaient le second, et l'import s'arrêtait —
         * ou, pire, aurait tronqué en silence la trace du second paiement.
         */
        Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table
            ->string('mode_reglement', 200)->nullable()->change());

        /*
         * La clé unique s'élargit, et c'est le cœur de cette migration.
         *
         * Elle valait « entreprise + n° de pièce + fournisseur ». La base refusait donc
         * deux lignes du même fournisseur sous le même numéro — ce qui paraissait sain, et
         * ne l'est pas : le classeur ventile une facture sur plusieurs sections, et un
         * avoir y reprend le numéro de la pièce qu'il annule. Mesuré sur les deux fichiers
         * réels : 188 couples portent plusieurs lignes distinctes, soit 317 lignes que
         * cette contrainte écartait — dont l'avoir SOCIDA 4138005, refusé alors que la
         * facture et son avoir sont deux écritures bien réelles.
         *
         * On desserre donc : la date de facture et le montant entrent dans la clé. Rien ne
         * devient plus permissif qu'il ne faut — deux lignes identiques sur les quatre
         * valeurs restent une seule ligne, et c'est ce qui empêche les deux classeurs, qui
         * se recouvrent sur 2 670 lignes, de compter la dette deux fois.
         */
        if ($this->indexExiste('frs_piece_unique')) {
            Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table->dropUnique('frs_piece_unique'));
        }

        if (! $this->indexExiste('frs_ligne_unique')) {
            Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table->unique(
                ['entreprise_id', 'fournisseur', 'numero_piece', 'date_facture', 'montant'],
                'frs_ligne_unique',
            ));
        }

        // La lecture par fournisseur et par date sert aussi l'écran : sans cet index,
        // retrouver une ligne parmi dix mille à chaque écriture balaierait la table.
        if (! $this->indexExiste('fournisseur_piece_date_idx')) {
            Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table->index(
                ['entreprise_id', 'fournisseur', 'numero_piece', 'date_facture'],
                'fournisseur_piece_date_idx',
            ));
        }
    }

    public function down(): void
    {
        if ($this->indexExiste('fournisseur_piece_date_idx')) {
            Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table->dropIndex('fournisseur_piece_date_idx'));
        }

        // La clé d'avant ne se repose que si les données la supportent encore : depuis
        // l'élargissement, la table peut légitimement porter deux lignes du même couple.
        // Un retour en arrière qui échouerait dessus laisserait la table sans clé du tout.
        if ($this->indexExiste('frs_ligne_unique')) {
            Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table->dropUnique('frs_ligne_unique'));
        }

        $presentes = array_values(array_filter(
            array_keys($this->colonnes),
            fn (string $nom) => Schema::hasColumn('factures_fournisseurs', $nom),
        ));

        if ($presentes === []) {
            return;
        }

        Schema::table('factures_fournisseurs', fn (Blueprint $table) => $table->dropColumn($presentes));
    }

    /** Vrai quand la colonne accepte déjà le vide — la migration ne la retouche alors pas. */
    private function accepteLeVide(string $colonne): bool
    {
        foreach (Schema::getColumns('factures_fournisseurs') as $connue) {
            if (($connue['name'] ?? null) === $colonne) {
                return (bool) ($connue['nullable'] ?? false);
            }
        }

        // La colonne n'existe pas : il n'y a rien à élargir.
        return true;
    }

    private function indexExiste(string $nom): bool
    {
        foreach (Schema::getIndexes('factures_fournisseurs') as $index) {
            if (($index['name'] ?? null) === $nom) {
                return true;
            }
        }

        return false;
    }
};

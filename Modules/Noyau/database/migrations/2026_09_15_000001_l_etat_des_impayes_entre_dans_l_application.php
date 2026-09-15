<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'état des impayés cesse d'être un fichier et devient un écran.
 *
 * **Ce que le fichier repris nous a appris.** « Etats des impayés L2A » n'est pas un export
 * du logiciel d'atelier : c'est un classeur tenu à la main par le superviseur de veille,
 * 8 961 créances sur un seul onglet, cinq années mêlées — 2022 à 2026.
 *
 * **Il faut d'abord dire ce qu'il a de bon**, parce que cela commande tout le reste : ses
 * chiffres sont justes. Recalculé ligne par ligne, il porte 6 329 977 795 F facturés,
 * 5 535 425 213 F réglés, et **798 999 354 F de reste à payer sur 1 332 créances ouvertes**,
 * soit 87,4 % de taux de règlement. Le total qu'il affiche lui-même en tête — 792 400 942 F
 * — tombe à 1 % près, et la reprise en base y retombe aussi. Ce classeur tient debout.
 *
 * Ce qu'il ne peut pas faire, en revanche, c'est se défendre contre la main qui le tient :
 *
 * - **Les colonnes glissent d'une ligne à l'autre.** La formule d'ancienneté se trouve
 *   tantôt en R, où elle est chez elle, tantôt en O, P ou Q — soit sous « Mode de
 *   règlement » pour 1 198 lignes, sous « banque » pour 1 492 autres. Sur 2 765 lignes, on
 *   lit donc un nombre de jours dans une colonne qui annonce un moyen de paiement.
 * - **Rien n'empêche d'encaisser plus qu'on n'a facturé** : 29 lignes le font, pour
 *   4 446 771 F, et ce trop-perçu vient en déduction du total, donc masque la dette
 *   d'autres clients.
 * - **Rien n'exige une date ni un numéro** : 45 lignes n'ont aucune date exploitable, 142
 *   aucun numéro, et 42 % aucun atelier.
 * - **Rien ne signale un doublon.** Le classeur en repère après coup, par une formule
 *   `CONCATENATE` rangée sous l'en-tête « Commentaires » — laquelle porte par ailleurs 345
 *   vraies consignes de travail. Une même colonne pour deux usages.
 *
 * Aucun de ces quatre points n'est un accident de manipulation : ce sont les limites
 * normales d'un tableur, et elles ne se corrigent pas en tenant mieux le tableur. C'est
 * pourquoi la saisie passe dans l'application, où une colonne ne peut pas glisser et où un
 * règlement supérieur au montant facturé est refusé à la frappe.
 *
 * **L'import reste en place**, et ce n'est pas une précaution : c'est lui qui a apporté
 * l'historique, et il doit pouvoir le réapporter. Ce qu'il dépose est désormais marqué
 * `est_etat_initial` et se lit dans un écran à part — l'état initial — pour qu'on ne
 * confonde jamais ce qui a été repris avec ce qui a été saisi.
 *
 * **Tout est additif et nullable.** La base en ligne porte des créances réelles : aucune
 * ligne existante ne change de sens, aucune reprise n'est nécessaire, et les écrans qui
 * ignorent ces colonnes continuent de fonctionner à l'identique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            /*
             * L'année de l'état dans lequel la ligne a été saisie — et elle ne bouge jamais.
             *
             * C'est la clé de la reconduction, et le choix mérite d'être écrit : une créance
             * non soldée **n'est pas recopiée** dans l'année suivante. Elle garde son année
             * d'origine, et l'état de l'année N l'affiche parce qu'elle est encore ouverte,
             * pas parce qu'on l'y a déplacée. Deux raisons, et la seconde est la vraie :
             *
             *   - recopier la ligne compterait la même créance deux fois dans un total ;
             *   - déplacer la ligne viderait l'état de l'année passée, qui ne se
             *     rapprocherait plus de ce qu'on y avait arrêté.
             *
             * Le report est donc une règle de lecture et non une écriture. Il se défait de
             * lui-même le jour où la facture est soldée, sans que personne n'ait à y penser.
             *
             * NULL veut dire « cette facture ne relève pas de l'état des impayés » : c'est
             * le cas des factures du chiffre d'affaires, qui n'apportent aucun règlement et
             * n'ont donc rien à faire dans une créance.
             */
            $table->unsignedSmallInteger('exercice_impayes')->nullable()->after('date');

            /* La reprise du fichier, toutes années mêlées et colonnes glissantes comprises. */
            $table->boolean('est_etat_initial')->default(false)->after('exercice_impayes');

            /*
             * Les colonnes du fichier qui n'avaient nulle part où aller.
             *
             * Elles finissaient en texte libre dans « observations », ou nulle part. Une
             * donnée rangée dans une phrase ne se filtre pas, ne se totalise pas et ne se
             * compare pas — c'est une donnée qu'on a gardée sans la conserver.
             */
            $table->date('date_reception')->nullable()->after('date');
            $table->string('banque', 120)->nullable()->after('courtier');
            $table->string('n_sinistre', 60)->nullable()->after('immatriculation');

            /*
             * La tranche d'ancienneté **telle que le fichier l'annonce**, à côté de celle que
             * l'application calcule. Les deux ne concordent pas toujours, et c'est précisément
             * ce qu'on veut pouvoir montrer : une tranche recopiée à la main dans un tableur
             * vieillit toute seule le lendemain, la nôtre se recalcule à chaque lecture.
             */
            $table->string('anciennete_declaree', 20)->nullable()->after('n_sinistre');

            $table->index(['entreprise_id', 'exercice_impayes'], 'factures_exercice_impayes_idx');
            $table->index(['entreprise_id', 'est_etat_initial'], 'factures_etat_initial_idx');
        });

        /*
         * Les colonnes du CATTC qui étaient repliées dans « observations ».
         *
         * Le fichier du chiffre d'affaires porte douze colonnes ; quatre d'entre elles —
         * sticker, code client, marque, modèle — étaient concaténées dans une phrase du
         * genre « Sinistre : X · Sticker : Y · Code client : Z ». L'écran ne pouvait donc
         * pas les afficher en colonnes, ni les trier, ni les rechercher séparément.
         *
         * Le numéro de sinistre, lui, est partagé avec l'état des impayés : il est déclaré
         * au-dessus, une seule fois.
         */
        Schema::table('factures', function (Blueprint $table) {
            $table->string('n_sticker', 60)->nullable()->after('n_sinistre');
            $table->string('code_client', 40)->nullable()->after('client');
            $table->string('marque', 60)->nullable()->after('vehicule');
            $table->string('modele', 60)->nullable()->after('marque');
        });

        /*
         * Le type de compteur cesse d'être une énumération figée en base.
         *
         * `type` était un ENUM de cinq valeurs, c'est-à-dire une seconde copie de
         * `GenerateurNumero::PREFIXES` — celle qu'on oublie de mettre à jour. Ajouter une
         * série de numérotation demandait donc une migration à chaque fois, et sur SQLite la
         * contrainte de vérification refusait la nouvelle valeur sans rien expliquer.
         *
         * La liste qui fait foi est dans le code, où elle est lue. La colonne se contente
         * désormais de stocker ce qu'on lui donne.
         */
        Schema::table('compteurs_documents', function (Blueprint $table) {
            $table->string('type', 10)->change();
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_exercice_impayes_idx');
            $table->dropIndex('factures_etat_initial_idx');
            $table->dropColumn([
                'exercice_impayes', 'est_etat_initial', 'date_reception', 'banque',
                'n_sinistre', 'anciennete_declaree', 'n_sticker', 'code_client', 'marque', 'modele',
            ]);
        });

        // Les compteurs créés sous une série absente de l'ancienne énumération empêcheraient
        // le retour en arrière : on les retire d'abord.
        DB::table('compteurs_documents')->whereNotIn('type', ['pro', 'dev', 'fac', 'com', 'nfa'])->delete();

        Schema::table('compteurs_documents', function (Blueprint $table) {
            $table->enum('type', ['pro', 'dev', 'fac', 'com', 'nfa'])->change();
        });
    }
};

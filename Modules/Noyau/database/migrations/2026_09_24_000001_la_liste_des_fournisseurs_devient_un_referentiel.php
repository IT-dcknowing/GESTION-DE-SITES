<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La feuille « Liste fournisseurs » devient un référentiel.
 *
 * **Ce qu'elle dit, et que rien ne disait.** Les deux classeurs de suivi portent, à côté de
 * la feuille `DETAIL` qu'on lisait déjà, une feuille qui décrit les fournisseurs eux-mêmes :
 * à quel terme chacun se règle (« Comptant », « 30 jours », « 45 jours », « 30 jours fin de
 * mois »), s'il facture la TVA, et pour six d'entre eux le plafond d'encours négocié. Rien
 * de tout cela n'était lu.
 *
 * **La mesure qui a décidé de la table.** Sur les 1 848 pièces reprises en local, **aucune**
 * ne porte de délai de règlement et **aucune** ne porte de date d'échéance : la colonne
 * « délais de règlement » n'existe que dans le classeur de San-Pédro, et la ligne ne la
 * remplit pas. En face, **1 756 de ces 1 848 pièces** — 95 % — appartiennent à un
 * fournisseur dont le terme est déclaré dans cette feuille. Le fichier sait donc pour quand
 * la facture est due ; il le sait ailleurs que sur la ligne.
 *
 * **Une table à part, et non des colonnes de plus sur la facture.** Le terme appartient au
 * fournisseur, pas à la pièce : le recopier sur chaque ligne le figerait au jour de
 * l'import, et le jour où un fournisseur passe de comptant à trente jours il faudrait
 * réécrire ses factures passées pour que l'écran dise vrai. C'est aussi la règle du module
 * depuis le début — le fichier reçu ne se réécrit jamais, les sources ne se mêlent pas.
 *
 * **Une seule liste pour l'entreprise, pas une par ville.** Les deux feuilles portent 287
 * noms en tout, dont 211 communs, et sur ces 211 elles ne se contredisent que **huit fois** —
 * dont sept où l'une des deux ne dit simplement rien. Un terme de règlement est négocié avec
 * un fournisseur, pas avec un atelier. Deux listes auraient obligé à choisir laquelle croire
 * pour une facture d'Abidjan payée depuis San-Pédro.
 *
 * **Le nom normalisé est la clé, le nom d'origine est conservé.** Les deux orthographes
 * d'apostrophe se croisent dans les fichiers (« BERNABE COTE D'IVOIRE » et « …D’IVOIRE ») ;
 * la normalisation du module les rapproche. Mesuré : 287 noms donnent 287 clés distinctes —
 * aucune collision — et 99 des 100 fournisseurs présents en base sont reconnus, soit
 * 1 847 pièces sur 1 848.
 *
 * **Migration additive** : une table neuve, aucune ligne existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referentiel_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('lot_import_id')->nullable()->constrained('lots_import')->nullOnDelete();

            $table->string('nom', 190);
            $table->string('nom_normalise', 190);

            // Le libellé du fichier, recopié à la lettre. C'est lui qu'on affiche : un
            // fournisseur qui a négocié « 30 jours fin de mois » ne se reconnaîtrait pas
            // dans « 30 jours », et personne ne saurait que l'application a interprété.
            $table->string('delai_reglement', 60)->nullable();

            // L'interprétation du libellé, à côté et jamais à la place. Elle reste nulle
            // quand la phrase n'est pas de celles qu'on sait lire, et l'écran dit alors
            // qu'il ne sait pas plutôt que d'annoncer une échéance fausse.
            $table->unsignedSmallInteger('jours_reglement')->nullable();
            $table->boolean('fin_de_mois')->default(false);

            // Trois états, et non deux : « OUI », « NON », et la colonne vide — 33 noms sur
            // 282 à Abidjan. Un booléen non nul aurait transformé « on ne sait pas » en
            // « non assujetti », ce qui est une affirmation fiscale qu'on n'a pas à faire.
            $table->boolean('assujetti_tva')->nullable();

            // Le plafond d'encours, tel qu'il est écrit — « Limite compte 12 500 000 FCFA ».
            // Il n'est pas converti en nombre : l'un des six l'écrit « 10 00 000 », et
            // deviner s'il s'agit d'un million ou de dix effacerait la faute au lieu de la
            // montrer à qui peut la corriger.
            $table->string('note', 255)->nullable();

            $table->string('source_feuille', 60)->nullable();
            $table->timestamps();

            // Un fournisseur, une fiche : la liste décrit un état, pas des événements.
            // Redéposer le classeur met la fiche à jour au lieu d'en ajouter une seconde.
            $table->unique(['entreprise_id', 'nom_normalise'], 'referentiel_fournisseur_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiel_fournisseurs');
    }
};

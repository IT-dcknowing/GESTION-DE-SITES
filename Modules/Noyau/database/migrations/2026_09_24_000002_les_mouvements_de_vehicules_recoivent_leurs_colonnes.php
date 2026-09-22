<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les entrées et sorties de véhicules reçoivent les colonnes de leur fichier.
 *
 * **Ce qui se passait.** Quatre colonnes des fichiers « Liste des véhicules entrés » et
 * « sortis » n'avaient aucune colonne où aller — les travaux à effectuer, le propriétaire,
 * le déposant et la date de livraison prévue. L'import ne les perdait pas : il en faisait
 * **une phrase**, recopiée dans `observations` sous la forme « TRAVAUX · Propriétaire : X ·
 * Déposant : Y · Livraison prévue : 01/09/2026 ». Le commentaire qui l'écrivait le disait
 * lui-même : « ce que le fichier porte en plus, et qu'aucune colonne n'accueille ».
 *
 * **Pourquoi une phrase ne suffit pas.** Mesuré sur les 147 mouvements repris en local :
 * **147 sur 147** portent une date de livraison prévue, 145 un propriétaire, 120 un
 * déposant. Une date rangée dans une phrase ne se trie pas, ne se filtre pas, et ne se
 * compare pas à aujourd'hui : on ne pouvait donc pas demander « quels véhicules devaient
 * sortir la semaine dernière et sont encore là », qui est pourtant la question du comptoir.
 * C'est la même faute que celle corrigée le 23/09 sur les quatre textes libres du suivi
 * fournisseur — les fondre revenait à perdre laquelle disait quoi.
 *
 * **Les lignes déjà en base ne sont pas réécrites.** Elles gardent leur phrase, qui reste
 * affichée telle quelle ; les quatre colonnes se remplissent au prochain dépôt du fichier,
 * qui est leur source. Une migration qui découperait la phrase pour répartir ses morceaux
 * devinerait — le déposant porte des retours à la ligne et des numéros de téléphone — et
 * remplacerait une donnée vraie par une donnée reconstituée.
 *
 * **Migration additive** : quatre colonnes nullables, aucune ligne existante touchée,
 * `observations` conservée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_vehicules', function (Blueprint $table) {
            // La longueur suit celle que l'import appliquait déjà à ces valeurs avant de
            // les coller dans la phrase : 500 pour les travaux, 200 pour les personnes.
            $table->text('travaux')->nullable()->after('motif');
            $table->string('proprietaire', 200)->nullable()->after('travaux');
            $table->string('deposant', 200)->nullable()->after('proprietaire');
            $table->date('date_livraison_prevue')->nullable()->after('deposant');
        });

        Schema::table('mouvements_vehicules', function (Blueprint $table) {
            // La promesse faite au client se regarde par date : c'est le seul usage de
            // cette colonne, et sans index le tri se ferait sur un balayage complet.
            $table->index(['entreprise_id', 'date_livraison_prevue'], 'mouvement_livraison_index');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_vehicules', function (Blueprint $table) {
            $table->dropIndex('mouvement_livraison_index');
            $table->dropColumn(['travaux', 'proprietaire', 'deposant', 'date_livraison_prevue']);
        });
    }
};

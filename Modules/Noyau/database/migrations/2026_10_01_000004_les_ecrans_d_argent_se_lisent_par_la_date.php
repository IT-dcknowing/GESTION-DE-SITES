<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les écrans d'argent se lisent par la date, et la base ne le savait pas.
 *
 * **Mesuré le 01/10, écran par écran** — chaque requête du périmètre sur `encaissements`
 * coûtait de 120 à 180 ms, et il y en a une dizaine par page :
 *
 * | Écran | Durée | Requêtes | dont SQL |
 * |---|---|---|---|
 * | `/banques` | 1 914 ms | 35 | 1 235 ms |
 * | `/tresorerie` | 1 651 ms | 62 | 914 ms |
 *
 * **Pourquoi l'index existant ne servait pas.** `encaissements` porte
 * `(entreprise_id, site_id, date)`. Or le périmètre s'écrit :
 *
 *     site_id IN (…) OR (site_id IS NULL AND (facture_id IS NULL OR EXISTS (…)))
 *
 * Un `OR` sur la deuxième colonne d'un index composite empêche de s'en servir : MySQL
 * parcourt la table entière, puis évalue la sous-requête corrélée **ligne par ligne**. Sept
 * mille sept cent quatorze fois, dix fois par page.
 *
 * **Ce que l'index ajouté change.** Toutes ces pages bornent la période —
 * `whereBetween('date', …)` —, et c'est la condition la plus sélective : elle réduit avant
 * que le périmètre n'ait à se prononcer. `(entreprise_id, date)` la rend utilisable, et
 * l'ordre des colonnes compte : l'entreprise d'abord, parce qu'aucune requête de cette
 * application ne lit deux entreprises à la fois.
 *
 * **Le second index sert au regroupement par moyen**, que l'écran des banques et le bloc
 * « ce que la trésorerie regroupe » font à chaque affichage.
 *
 * **Additive, et sans écriture de données.** Un index ne change aucune ligne. Sur les 7 714
 * encaissements et les 198 charges d'aujourd'hui, la pose est instantanée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encaissements', function (Blueprint $table) {
            // La période borne toutes les lectures d'argent : c'est par là qu'il faut entrer.
            $table->index(['entreprise_id', 'date'], 'encaissements_entreprise_date_idx');

            // Le regroupement par moyen — support de règlement — est refait à chaque page.
            $table->index(['entreprise_id', 'moyen'], 'encaissements_moyen_idx');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->index(['entreprise_id', 'date'], 'charges_entreprise_date_idx');
            $table->index(['entreprise_id', 'moyen'], 'charges_moyen_idx');
        });

        Schema::table('factures', function (Blueprint $table) {
            // La sous-requête du périmètre des encaissements lit `factures.site_id` et
            // `factures.ville_id` à partir de `factures.id` : la clé primaire la mène, et
            // cet index lui évite d'aller chercher la ligne entière.
            $table->index(['id', 'site_id', 'ville_id'], 'factures_perimetre_idx');
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_perimetre_idx');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex('charges_moyen_idx');
            $table->dropIndex('charges_entreprise_date_idx');
        });

        Schema::table('encaissements', function (Blueprint $table) {
            $table->dropIndex('encaissements_moyen_idx');
            $table->dropIndex('encaissements_entreprise_date_idx');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un barème court jusqu'à ce qu'un autre le remplace.
 *
 * **Ce que le propriétaire a tranché le 24/09**, et qui revient sur la décision du 22/09 :
 *
 * 1. **Un barème posé pour une année reste en vigueur les années suivantes**, sans que
 *    personne n'ait à le reconduire. Cloisonné par exercice, il obligeait à en reposer un
 *    chaque 1er janvier — et le jour où on l'oublie, plus aucune commission ne se calcule,
 *    sans que rien ne prévienne.
 * 2. **Une modification ne vaut que pour la suite.** Un barème changé en novembre 2026
 *    s'applique à novembre et après ; janvier à octobre gardent celui sous lequel ils ont
 *    été arrêtés. Depuis le 22/09, corriger la grille de 2026 recalculait les dix mois
 *    déjà annoncés — c'est précisément ce qu'on ne veut pas d'une rémunération.
 *
 * **La date d'effet redevient donc la clé**, et `exercice` n'est plus une cloison : la
 * grille qui répond pour un mois est la dernière dont la date d'effet précède ce mois. La
 * colonne `exercice` reste en place — elle dit sous quel exercice la grille a été posée, ce
 * qui se lit à l'écran — mais elle ne filtre plus rien.
 *
 * **Les grilles existantes sont déjà datées du 1er janvier de leur exercice** : la règle
 * s'applique à elles sans qu'aucune ligne ne soit réécrite, et une grille de 2026 couvre
 * 2027 dès aujourd'hui.
 *
 * **Migration additive au sens qui compte** : aucune donnée n'est touchée. Seul l'index
 * d'unicité change de colonnes — de (entreprise, cible, exercice) à (entreprise, cible,
 * date d'effet) — pour qu'une même année puisse porter deux grilles successives.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Les index sont vérifiés avant d'être touchés : la base en ligne et les postes de
        // développement n'ont pas suivi la même suite de migrations, et une migration qui
        // suppose un index présent s'arrête au milieu là où il ne l'est pas.
        if ($this->aLIndex('bareme_cible_exercice_unique')) {
            Schema::table('baremes_commission', function (Blueprint $table) {
                $table->dropUnique('bareme_cible_exercice_unique');
            });
        }

        if (! $this->aLIndex('bareme_cible_effet_unique')) {
            Schema::table('baremes_commission', function (Blueprint $table) {
                // Une grille par catégorie et par date d'effet : poser deux grilles le même
                // jour pour la même catégorie ne désignerait plus laquelle répond. Corriger
                // celle du jour reste possible — c'est la même ligne.
                $table->unique(['entreprise_id', 'cible', 'date_effet'], 'bareme_cible_effet_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->aLIndex('bareme_cible_effet_unique')) {
            Schema::table('baremes_commission', function (Blueprint $table) {
                $table->dropUnique('bareme_cible_effet_unique');
            });
        }

        if (! $this->aLIndex('bareme_cible_exercice_unique')) {
            Schema::table('baremes_commission', function (Blueprint $table) {
                $table->unique(['entreprise_id', 'cible', 'exercice'], 'bareme_cible_exercice_unique');
            });
        }
    }

    private function aLIndex(string $nom): bool
    {
        foreach (Schema::getIndexes('baremes_commission') as $index) {
            if (($index['name'] ?? null) === $nom) {
                return true;
            }
        }

        return false;
    }
};

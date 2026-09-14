<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une facture ou un devis peut exister sans qu'on sache encore de quel atelier il relève.
 *
 * **Ce que fait cette migration : elle relâche une contrainte, elle n'en ajoute aucune.**
 * `site_id` passe de « obligatoire » à « facultatif » sur `factures` et `devis`. Aucune
 * ligne existante n'est touchée — toutes en ont un — et rien de ce qui fonctionne
 * aujourd'hui ne change : les formulaires de saisie de l'application fournissent toujours
 * le site, et continueront de le faire.
 *
 * **Pourquoi c'était nécessaire.** Abidjan compte deux ateliers, et aucun fichier du
 * logiciel ne les distingue : la colonne SITE dit « ABIDJAN » et s'arrête là. Le seul
 * discriminant est le code de deux lettres de la personne qui a rédigé la fiche. Tant que
 * ce code n'est pas rattaché à un atelier, on ne sait pas où ranger la ligne.
 *
 * Trois réponses étaient possibles, et il faut dire pourquoi les deux autres ont été
 * écartées :
 *
 * 1. **Refuser la ligne.** On perdrait une facture réelle parce qu'on ignore qui est « TT ».
 *    Le chiffre d'affaires serait faux par défaut, et personne ne saurait de combien.
 * 2. **Choisir un atelier au hasard**, ou toujours le premier. Le chiffre d'affaires serait
 *    faux *et invisible* : le total de l'entreprise tomberait juste, la répartition entre
 *    les deux ateliers serait fausse, et rien ne le signalerait jamais.
 * 3. **Accepter la ligne sans atelier**, la compter dans les totaux de la ville, et la
 *    montrer dans un écran « à traiter » jusqu'à ce qu'on tranche.
 *
 * C'est la troisième. Un chiffre en attente d'affectation se voit et se corrige ; un chiffre
 * attribué au mauvais atelier ne se voit pas et ne se corrige jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['factures', 'devis'] as $nom) {
            Schema::table($nom, function (Blueprint $table) {
                $table->foreignId('site_id')->nullable()->change();
            });
        }

        /*
         * Un devis importé n'a pas de commercial, et c'est normal.
         *
         * La fiche « commercial » est une notion de cette plateforme : elle sert à suivre la
         * prospection et les objectifs. Le logiciel d'atelier, lui, ne connaît que la
         * personne qui a rédigé la proforma — un code de deux lettres, déjà porté ailleurs.
         *
         * Rendre un commercial obligatoire sur une proforma venue de l'extérieur reviendrait
         * à en désigner un au hasard, et à lui attribuer un chiffre d'affaires qu'il n'a pas
         * fait. Le champ devient donc facultatif, et le rapprochement se fera plus tard, par
         * le code employé, quand il sera rattaché à un compte.
         */
        Schema::table('devis', function (Blueprint $table) {
            $table->foreignId('commercial_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Le retour en arrière n'est possible que si aucune ligne n'attend son atelier :
        // remettre la contrainte alors que des lignes ont `site_id` à null échouerait, et
        // les supprimer pour y arriver serait pire que de laisser la migration en place.
        Schema::table('devis', function (Blueprint $table) {
            $table->foreignId('commercial_id')->nullable(false)->change();
        });

        foreach (['factures', 'devis'] as $nom) {
            Schema::table($nom, function (Blueprint $table) {
                $table->foreignId('site_id')->nullable(false)->change();
            });
        }
    }
};

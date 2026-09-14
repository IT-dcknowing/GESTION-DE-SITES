<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le courtier, et l'assurance qu'il représente.
 *
 * Une facture de sinistre n'a pas un payeur mais trois candidats possibles, et jusqu'ici
 * la base n'en connaissait qu'un : `client`. Quand un courtier apporte le dossier, c'est
 * lui qui règle — l'assurance est derrière lui, l'assuré encore derrière. Relancer la
 * compagnie pour une créance portée par son courtier, c'est écrire à quelqu'un qui n'a
 * rien à payer, et laisser le vrai débiteur tranquille.
 *
 * Deux colonnes s'ajoutent donc, toutes deux facultatives :
 *
 * - `assureur` : la compagnie représentée, quand la facture passe par un courtier ;
 * - `courtier` : le courtier lui-même, qui devient alors le tiers payant.
 *
 * Le tiers n'est plus une colonne mais une dérivation — courtier, sinon assureur, sinon
 * client. Aucune ligne existante ne change de sens : les deux colonnes naissent vides,
 * la dérivation retombe sur `client`, et les écrans affichent exactement ce qu'ils
 * affichaient hier. C'est ce qui permet d'ouvrir cette lecture sur une base qui porte
 * déjà des créances réelles sans reprise ni bascule.
 *
 * Les deux colonnes sont indexées : la balance âgée, la page Courtiers et l'extrait de
 * compte regroupent dessus, et sur plusieurs milliers de factures un balayage complet à
 * chaque affichage se sent immédiatement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->string('assureur', 160)->nullable()->after('client');
            $table->string('courtier', 160)->nullable()->after('assureur');

            $table->index(['entreprise_id', 'courtier']);
            $table->index(['entreprise_id', 'assureur']);
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex(['entreprise_id', 'courtier']);
            $table->dropIndex(['entreprise_id', 'assureur']);
            $table->dropColumn(['assureur', 'courtier']);
        });
    }
};

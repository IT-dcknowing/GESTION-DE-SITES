<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;

/**
 * Ouverture du module Recouvrement.
 *
 * Sur une base qui porte des écritures réelles, cette migration est additive de bout en
 * bout : deux tables neuves, trois colonnes facultatives, et deux rôles de plus. Aucune
 * donnée existante n'est lue autrement que pour être recopiée, aucune n'est modifiée,
 * aucune n'est supprimée.
 *
 * Les colonnes ajoutées aux factures sont nullables : les factures déjà saisies restent
 * valides sans reprise, et les écrans qui les ignorent continuent de fonctionner.
 */
return new class extends Migration
{
    /** Les deux rôles du module, ajoutés à chaque entreprise déjà en service. */
    private const ROLES = ['superviseur_recouvrement', 'agent_recouvrement'];

    public function up(): void
    {
        /*
         * Le véhicule et son immatriculation figurent sur l'extrait de compte remis au
         * client : sans eux, un assureur qui conteste une ligne ne peut pas la retrouver
         * dans son propre dossier, et la relance s'enlise sur une question d'identification.
         */
        Schema::table('factures', function (Blueprint $table) {
            $table->string('vehicule', 120)->nullable()->after('client');
            $table->string('immatriculation', 30)->nullable()->after('vehicule');
        });

        /*
         * Le commercial devient facultatif sur une facture.
         *
         * Jusqu'ici, toute facture naissait d'une prospection, donc d'un commercial. Le
         * recouvrement en fait naître d'une autre façon : un sinistre facturé à un
         * assureur n'a été prospecté par personne, et lui attribuer d'office un commercial
         * fausserait ses objectifs et sa commission — on lui compterait un chiffre
         * d'affaires qu'il n'a pas apporté.
         *
         * Le relâchement d'une contrainte ne peut invalider aucune ligne existante :
         * toutes portent déjà un commercial et le gardent. Les écrans par commercial
         * filtrent sur l'identifiant et laissent donc ces factures de côté, ce qui est
         * exactement le comportement voulu ; les totaux de chiffre d'affaires, eux, les
         * comptent comme les autres.
         */
        Schema::table('factures', function (Blueprint $table) {
            $table->foreignId('commercial_id')->nullable()->change();
        });

        /*
         * Le moyen de paiement cesse d'être une énumération figée en base.
         *
         * `moyen` était un ENUM de cinq valeurs, alors que l'application propose déjà
         * d'enrichir cette liste depuis les Paramètres (référentiel « Moyens de
         * paiement »). Une valeur ajoutée là était donc acceptée par le formulaire puis
         * refusée par la base : l'écran tombait en erreur au moment d'enregistrer, sans
         * que rien n'ait prévenu. C'était un défaut latent, indépendant du recouvrement,
         * que celui-ci ne fait que rendre certain — ses modes nomment la banque
         * (« VIREMENT — BGFI »), ce qu'un rapprochement bancaire exige.
         *
         * Passer d'ENUM à VARCHAR ne relit ni ne réécrit aucune valeur : les libellés
         * existants sont conservés à l'identique. On relâche une contrainte, on n'en
         * ajoute pas — aucune ligne déjà saisie ne peut devenir invalide.
         *
         * `charges` porte exactement la même énumération et le même défaut : la corriger
         * ici évite de laisser la moitié du problème en place.
         */
        Schema::table('encaissements', function (Blueprint $table) {
            $table->string('moyen', 60)->default('Espèces')->change();
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->string('moyen', 60)->default('Espèces')->change();
        });

        /*
         * L'objectif de recouvrement est une décision de direction, pas une constante du
         * logiciel : il change d'un exercice à l'autre, et deux entreprises n'ont pas le
         * même. La valeur par défaut est celle retenue avec L'Artisan Automobile.
         */
        Schema::table('entreprises', function (Blueprint $table) {
            $table->unsignedBigInteger('objectif_recouvrement_hebdomadaire')->default(15000000);
        });

        Schema::create('relances_recouvrement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // L'auteur peut partir ; ce qu'il a fait reste. Son nom est donc recopié à
            // côté du lien : une relance sans responsable identifiable ne prouve rien.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('responsable', 120);

            $table->date('date');
            $table->string('tiers', 160)->index();
            $table->string('factures_visees', 255)->default('Situation globale');

            // 1 à 5 : e-mail, téléphone, lettre, mise en demeure, contentieux.
            $table->unsignedTinyInteger('niveau');
            $table->string('canal', 60);
            $table->string('interlocuteur', 120)->nullable();
            $table->text('resultat')->nullable();
            $table->unsignedBigInteger('montant_promis')->default(0);
            $table->string('statut', 60)->default('En cours');

            $table->timestamps();

            $table->index(['entreprise_id', 'date']);
            $table->index(['entreprise_id', 'tiers']);
        });

        Schema::create('commentaires_ecart_recouvrement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // jour | semaine | mois, et la période concernée sous forme lisible et triable
            // (« 2026-08-20 », « 2026-S34 », « 2026-08 »).
            $table->string('periode', 10);
            $table->string('reference', 20);
            $table->text('texte');

            $table->timestamps();

            // Un écart n'a qu'un commentaire par période : deux versions concurrentes
            // d'une même explication ne s'arbitrent pas.
            //
            // L'index est nommé à la main : celui que Laravel déduirait des trois colonnes
            // dépasse les 64 caractères admis par MySQL, et la migration échouerait au
            // déploiement — après avoir déjà créé les tables précédentes.
            $table->unique(['entreprise_id', 'periode', 'reference'], 'commentaires_ecart_unique');
        });

        $this->creerLesRoles();
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('name', self::ROLES)->delete();

        Schema::dropIfExists('commentaires_ecart_recouvrement');
        Schema::dropIfExists('relances_recouvrement');

        Schema::table('entreprises', function (Blueprint $table) {
            $table->dropColumn('objectif_recouvrement_hebdomadaire');
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->dropColumn(['vehicule', 'immatriculation']);
        });

        /*
         * `commercial_id` reste volontairement facultatif après le retour en arrière.
         *
         * Le rendre à nouveau obligatoire échouerait sur les factures de recouvrement qui
         * n'en portent pas — et les faire disparaître pour satisfaire une contrainte
         * reviendrait à effacer du chiffre d'affaires réel. Un retour en arrière défait
         * ce qu'une migration a ajouté ; il ne détruit pas ce que les gens ont saisi
         * entre-temps.
         */
    }

    /**
     * Les deux rôles, dans chaque entreprise déjà ouverte.
     *
     * Spatie porte les rôles par équipe : un rôle créé pour une entreprise n'existe pas
     * pour les autres. Sans cette boucle, le module serait inattribuable partout sauf sur
     * une base neuve — et l'erreur ne se verrait qu'au moment de nommer quelqu'un.
     */
    private function creerLesRoles(): void
    {
        foreach (Entreprise::withoutGlobalScopes()->get() as $entreprise) {
            ProvisionneurEntreprise::creerRoles($entreprise);
        }
    }
};

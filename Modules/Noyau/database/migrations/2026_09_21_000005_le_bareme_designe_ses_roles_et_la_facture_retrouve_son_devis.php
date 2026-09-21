<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux manques trouvés en relisant le barème du 21/09 au soir.
 *
 * **1. La grille désigne elle-même les rôles qu'elle rémunère.** Jusqu'ici, savoir quelle
 * grille s'appliquait à qui était écrit dans le code : « si le compte a le rôle responsable
 * commercial, alors la grille responsable ». C'était une règle de rémunération enfermée dans
 * un fichier PHP — il aurait fallu un déploiement pour que la grille des commerciaux couvre
 * aussi les responsables de site, qui prospectent pourtant eux aussi. La grille porte donc
 * la liste des rôles qu'elle rémunère, et le gérant la coche à l'écran.
 *
 * La colonne est **nullable** : une grille sans rôles retombe sur son ancien comportement,
 * ce qui laisse les grilles déjà posées — s'il y en a — continuer de répondre.
 *
 * **2. La facture retrouve son devis.** Le constat qui a motivé ceci : sur 4 412 factures
 * de 2026, **103 portent un commercial**. La colonne « Commission » de l'écran Commerciaux
 * reste donc à zéro pour presque tout le monde, non parce que personne n'a vendu, mais parce
 * qu'on ne sait pas qui. Or 2 386 factures portent une `reference_devis` — qui contient en
 * réalité le **numéro de fiche de réception** — et 273 d'entre elles désignent un devis
 * présent en base. Le lien existe, écrit en toutes lettres, simplement jamais résolu.
 *
 * Cette table garde les couples devis / facture qu'un humain a dit ne pas aller ensemble.
 * Elle est distincte de `rapprochements_ecartes`, qui tient les couples prospection / devis :
 * les deux colonnes de celle-ci sont obligatoires et son index d'unicité porte sur elles. Y
 * loger une paire d'une autre nature aurait demandé de la défaire, donc de toucher à une
 * table existante pour n'y gagner qu'un nom plus court.
 *
 * **Migration additive** : une colonne nullable et une table neuve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baremes_commission', function (Blueprint $table) {
            // Les rôles rémunérés par cette grille, en JSON — « ["commercial"] », ou
            // « ["responsable_commercial","responsable_ville"] ». Nul = s'en remettre à la
            // cible, comme avant.
            $table->text('roles')->nullable()->after('cible');
        });

        Schema::create('ecarts_devis_facture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auteur', 120)->nullable();
            $table->timestamps();

            $table->unique(['devis_id', 'facture_id'], 'ecart_devis_facture_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecarts_devis_facture');

        Schema::table('baremes_commission', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};

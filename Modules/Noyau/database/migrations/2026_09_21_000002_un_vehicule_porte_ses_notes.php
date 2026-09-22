<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'on a à dire sur un véhicule, et qui l'a dit.
 *
 * **Le besoin.** On cherche une plaque, on veut savoir ce qu'elle a coûté en caisse et ce
 * qu'elle doit encore — et, presque toujours, on veut laisser un mot : « pièce commandée,
 * attendue le 12 », « le client conteste la peinture », « facture remise en main propre à
 * M. K. ». Aujourd'hui ce mot se note sur un cahier, ou nulle part. Le lendemain, celui
 * qui reprend le dossier repart de zéro.
 *
 * **Une table neuve plutôt qu'une colonne.** Une colonne « commentaire » quelque part
 * n'aurait retenu que le dernier mot, en effaçant le précédent sans le dire ; et elle
 * aurait fallu choisir *où* — sur la fiche de réception, sur la facture, sur le mouvement
 * de caisse ? Or ce qu'on commente n'est aucun des trois : c'est le véhicule, qui les
 * traverse tous. Les notes s'empilent donc, chacune avec son auteur et son heure, et rien
 * ne s'écrase.
 *
 * **La clé est l'immatriculation**, et non un identifiant de fiche : c'est la seule chose
 * que le parc, la caisse, les devis, les factures et les entrées/sorties partagent tous.
 * Elle est rangée en majuscules par l'application avant d'être écrite, de sorte que
 * « 1234 AB 01 » et « 1234 ab 01 » soient le même véhicule.
 *
 * Table neuve : aucune ligne existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_vehicule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->string('immatriculation', 30);
            $table->text('texte');
            // L'auteur s'efface le jour où son compte est supprimé, la note reste : une
            // observation reste vraie même quand celui qui l'a écrite est parti.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auteur', 120)->nullable();
            $table->timestamps();

            // On lit toujours « les notes de cette plaque, la plus récente en tête ».
            $table->index(['entreprise_id', 'immatriculation', 'created_at'], 'notes_vehicule_plaque_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes_vehicule');
    }
};

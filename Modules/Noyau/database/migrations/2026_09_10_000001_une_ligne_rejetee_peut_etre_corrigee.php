<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une ligne refusée peut être corrigée — et la correction se raconte.
 *
 * **Le manque auquel cette table répond.** Une ligne rejetée était un cul-de-sac : on la
 * lisait à l'écran, on ouvrait le fichier, on le corrigeait à la main, on le redéposait.
 * Trois lignes sur neuf mille imposaient donc de refaire tout l'import — et le fichier
 * corrigé n'ayant plus la même empreinte, il repassait comme un fichier neuf, sans lien
 * avec celui qu'il remplaçait.
 *
 * On corrige donc **dans l'application**, et la correction devient une écriture à part
 * entière, avec tout ce qu'une écriture doit porter : ce qui était, ce qui a été mis, par
 * qui, quand, depuis quelle adresse. Rien de tout cela n'est décoratif — corriger une ligne
 * d'import, c'est changer un montant qui finira dans un chiffre d'affaires. Qui l'a changé
 * est exactement la question qu'on posera dans six mois.
 *
 * **Le fichier déposé n'est jamais réécrit.** Il est la pièce d'origine, et son empreinte
 * garantit qu'il n'a pas bougé. La correction se pose par-dessus au moment de la relecture :
 * le fichier reste ce qu'il était, l'import voit ce qu'on a corrigé, et l'écart entre les
 * deux est visible ligne à ligne. Réécrire le classeur aurait fait disparaître la preuve
 * exactement là où on en a le plus besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corrections_import', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_import_id')->constrained('lots_import')->cascadeOnDelete();

            // Le lien vers la ligne rejetée s'efface, pas la correction : chaque relecture
            // remplace le journal des rejets, et l'histoire de ce qu'on a corrigé ne doit
            // pas disparaître avec lui.
            $table->foreignId('ligne_rejetee_id')->nullable()
                ->constrained('lignes_rejetees_import')->nullOnDelete();

            // Le numéro du tableur : c'est lui qui rattache la correction à la ligne du
            // fichier, et il survit à tout.
            $table->unsignedInteger('numero_ligne');
            $table->string('feuille', 120)->nullable();

            // 'corrigee' : des valeurs ont été remplacées.
            // 'retiree'  : la ligne est écartée de l'import, à la demande.
            $table->string('action', 20)->default('corrigee');

            $table->json('valeurs_avant');
            $table->json('valeurs_apres')->nullable();
            $table->string('motif', 255)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Recopié à côté du lien : l'auteur peut partir, sa correction reste.
            $table->string('auteur', 120);
            $table->string('adresse_ip', 45)->nullable();
            $table->string('poste', 255)->nullable();

            $table->timestamps();

            $table->index(['lot_import_id', 'numero_ligne'], 'corrections_lot_ligne_index');
            $table->index(['entreprise_id', 'created_at'], 'corrections_parcours_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrections_import');
    }
};

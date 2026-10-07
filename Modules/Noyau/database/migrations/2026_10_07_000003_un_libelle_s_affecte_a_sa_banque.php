<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un libellé de banque écrit à la main, affecté à la banque qu'il désigne.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Demandé le 07/10** : à côté de « Déclarer », dans la liste des libellés que l'écran des
 * banques ne sait pas ranger, un bouton « Affecter à » — « BGIF » est la BGFI, il ne faut pas
 * une banque de plus, il faut le dire une fois.
 *
 * **Pourquoi une table et non une réécriture des créances.** On aurait pu remplacer « BGIF »
 * par « BGFI » dans `factures.banque`. Ce serait écrire sur des centaines de lignes réelles,
 * et perdre ce que quelqu'un avait réellement noté — l'écran montre justement cette mention
 * dans sa colonne « Banque notée ». Ici on n'écrit qu'une ligne par décision, on la retire
 * d'un clic, et la créance reste telle qu'elle a été saisie.
 *
 * La clé est la forme réduite du libellé (`Banque::clePour`) : « bgif » et « B.G.I.F » sont la
 * même faute, et ne s'affectent qu'une fois.
 *
 * **Additive** : une table neuve. Aucune ligne existante n'est lue ni écrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libelles_de_banque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('banque_id')->constrained('banques')->cascadeOnDelete();
            // Le libellé tel qu'il a été trouvé, pour le montrer ; la clé, pour le reconnaître.
            $table->string('libelle', 160);
            $table->string('cle', 160);
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->timestamps();

            // Un libellé ne désigne qu'une banque : l'affecter à deux couperait ses totaux.
            $table->unique(['entreprise_id', 'cle'], 'libelles_de_banque_cle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libelles_de_banque');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chez qui la facture a été déposée.
 *
 * Une facture peut être établie au nom d'un client et déposée chez un autre : une
 * compagnie qui n'est ni l'assureur inscrit ni le courtier, une société qui prend en
 * charge les réparations de sa flotte, un garage donneur d'ordre. C'est alors ce
 * déposant qui règle, et c'est chez lui qu'il faut aller chercher l'argent.
 *
 * Jusqu'ici l'état savait **depuis quand** la facture était déposée — `date_reception`,
 * d'où part l'âge de la créance — mais jamais **chez qui**. Faute de colonne, le seul
 * moyen de le dire était de réécrire le client, ce qui revenait à mentir sur qui est
 * facturé ; ou de le noter dans les commentaires, ce qu'aucune balance âgée ne sait lire.
 *
 * Une colonne s'ajoute donc, facultative : `depose_chez`. Elle prend la tête de la
 * dérivation du tiers payant — déposant, sinon courtier, sinon assureur, sinon client —
 * de sorte que la balance âgée, les relances et l'extrait de compte comptent la créance
 * chez lui, et chez lui seulement.
 *
 * Aucune ligne existante ne change de sens : la colonne naît vide, la dérivation retombe
 * exactement sur ce qu'elle rendait hier, et les écrans affichent ce qu'ils affichaient.
 * C'est la condition pour ouvrir cette lecture sur une base qui porte déjà des créances
 * réelles, sans reprise ni bascule.
 *
 * L'index suit celui des deux autres colonnes de tiers, et pour la même raison : la
 * balance âgée, la page des tiers et l'extrait de compte regroupent dessus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->string('depose_chez', 160)->nullable()->after('courtier');

            $table->index(['entreprise_id', 'depose_chez']);
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex(['entreprise_id', 'depose_chez']);
            $table->dropColumn('depose_chez');
        });
    }
};

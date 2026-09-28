<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un chèque, plusieurs factures : la marque qui les relie.
 *
 * **La demande, du 28/09.** « Imaginons qu'un client donne un montant de 5 000 000 pour ses
 * créances, et que cela concerne environ 3 ou plusieurs factures. Il faudra permettre de
 * sélectionner les factures et de pouvoir remplir les informations une seule fois […] et
 * aussi on devrait ajouter une colonne paiement global qui va permettre de marquer que ces
 * lignes ont été payées de façon globale à travers un chèque ou autre. »
 *
 * **Pourquoi une colonne, et pas la référence existante.** `reference_origine` porte déjà
 * le numéro du chèque — mais elle porte aussi, quand on ne le donne pas, le numéro de la
 * facture. Elle ne peut donc pas répondre à « ces lignes forment-elles un seul
 * versement ? » : deux encaissements du même chèque saisis séparément la partageraient
 * sans former un versement, et un versement sans numéro de chèque n'aurait rien à
 * partager. Le lien doit être posé au moment du geste, par le geste, et par lui seul.
 *
 * **Ce que la colonne permet, et qui ne se déduit pas autrement** : dire au client
 * « votre chèque de 5 000 000 a soldé ces trois factures et laissé 300 000 sur la
 * quatrième », six mois plus tard, sans reconstituer le raisonnement.
 *
 * Migration **additive** : une colonne nullable et son index. Rien n'est réécrit, et les
 * 7 711 encaissements déjà en base restent tels quels — ils n'ont pas été saisis par ce
 * geste, et leur inventer une appartenance serait une invention.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('encaissements', 'reglement_global')) {
            return;
        }

        Schema::table('encaissements', function (Blueprint $table) {
            // La référence du versement qui a produit cette ligne — « RG-2809-0001 ».
            // Nulle quand l'encaissement vise une seule facture : un versement d'une
            // ligne n'est pas un règlement global, et le marquer comme tel ferait
            // apparaître des « paiements groupés » qui n'en sont pas.
            $table->string('reglement_global', 40)->nullable()->after('reference_origine');
            $table->index(['entreprise_id', 'reglement_global'], 'enc_reglement_global');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('encaissements', 'reglement_global')) {
            return;
        }

        Schema::table('encaissements', function (Blueprint $table) {
            $table->dropIndex('enc_reglement_global');
            $table->dropColumn('reglement_global');
        });
    }
};

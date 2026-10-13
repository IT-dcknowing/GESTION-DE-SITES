<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les montants gardent leurs centimes — 13/10.
 *
 * « Ne jamais arrondir des montants ; récupère tel que donné. » Le fichier du CA porte des
 * centimes (FA -5725 : 2 137 270,28), l'import les arrondissait à la ligne, et le total de l'écran
 * s'écartait de celui du classeur — 101 F sur 3 231 factures. Les colonnes d'argent étaient des
 * entiers : elles ne pouvaient pas faire autrement.
 *
 * **Une extension, pas une perte.** Un `bigint` devient un `decimal(18,4)` : toute valeur déjà en
 * base est reprise à l'identique (300 000 devient 300 000,0000), aucune n'est lue ni réécrite par
 * une règle métier. Quatre décimales, et non deux, parce que le classeur des fournisseurs porte
 * des hors-taxes calculés (82 096,627118644) et que « tel que donné » s'entend au-delà du centime ;
 * dix-huit chiffres en tout, soit quatorze avant la virgule — des milliers de milliards.
 *
 * Les colonnes restent signées (les avoirs sont négatifs), avec leur défaut et leur nullité d'avant.
 *
 * **Pas de retour en arrière** : repasser en entier arrondirait ce qu'on vient de cesser d'arrondir.
 * Les montants déjà arrondis par les imports d'avant ne se corrigent qu'en redéposant le fichier
 * d'origine — c'est au propriétaire de le décider, fichier par fichier.
 */
return new class extends Migration
{
    /**
     * table => colonne => [peut être vide, défaut]
     *
     * @var array<string, array<string, array{0: bool, 1: int|null}>>
     */
    private const COLONNES = [
        'charges' => ['montant' => [false, null]],
        'devis' => ['montant_devis' => [false, null], 'montant_valide' => [true, null]],
        'encaissements' => ['montant' => [false, 0]],
        'factures' => ['montant' => [false, 0]],
        'factures_fne' => ['montant_ht' => [false, 0], 'montant_tva' => [false, 0], 'timbre' => [false, 0], 'montant_ttc' => [false, 0]],
        'factures_fournisseurs' => [
            'montant' => [false, 0], 'montant_regle' => [false, 0], 'reste_a_payer' => [false, 0],
            'montant_refacture' => [true, null], 'marge' => [true, null], 'montant_ht' => [true, null],
            'tva' => [true, null], 'tva_2' => [true, null], 'difference' => [true, null],
            'montant_net_achat' => [true, null], 'montant_net_vente' => [true, null],
        ],
        'mouvements_bancaires' => ['debit' => [false, 0], 'credit' => [false, 0], 'solde_annonce' => [true, null]],
        'mouvements_caisse' => ['montant' => [false, 0], 'solde_annonce' => [true, null]],
        'ouvertures_caisse' => ['solde_avant' => [false, 0]],
        'pieces_bancaires' => ['montant' => [false, 0]],
        'reglements_fournisseur' => ['montant' => [false, 0]],
        'relances_recouvrement' => ['montant_promis' => [false, 0]],
        'soldes_fournisseur' => ['debit' => [false, 0], 'credit' => [false, 0], 'solde' => [false, 0]],
    ];

    public function up(): void
    {
        foreach (self::COLONNES as $nom => $colonnes) {
            if (! Schema::hasTable($nom)) {
                continue;
            }

            Schema::table($nom, function (Blueprint $table) use ($nom, $colonnes) {
                foreach ($colonnes as $colonne => [$vide, $defaut]) {
                    if (! Schema::hasColumn($nom, $colonne)) {
                        continue;
                    }

                    $definition = $table->decimal($colonne, 18, 4);
                    $vide ? $definition->nullable() : null;
                    $defaut !== null ? $definition->default($defaut) : null;
                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Volontairement vide : revenir à des entiers arrondirait les centimes qu'on garde.
    }
};

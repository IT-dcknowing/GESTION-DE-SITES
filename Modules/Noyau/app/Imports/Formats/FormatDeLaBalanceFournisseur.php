<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\SoldeFournisseur;

/**
 * La balance fournisseurs, telle que le logiciel comptable l'exporte.
 *
 * Quatre colonnes, relevées sur le fichier réel
 * (`BALANCE-FOURNISSEUR(global-pas de filtre date ni de site)-010126-170926.xlsx`) :
 * FOURNISSEURS, DEBIT, CREDIT, SOLDE. L'en-tête est en première ligne, une seule feuille.
 *
 * **Le fichier est global, et son nom le dit** : « pas de filtre date ni de site ». Il n'y a
 * donc ni colonne SITE ni période à en tirer, et prétendre le contraire reviendrait à
 * inventer un rattachement. Les lignes se rangent au niveau de l'entreprise.
 *
 * **Le solde est recopié, jamais recalculé.** Débit moins crédit ne redonne pas toujours la
 * colonne SOLDE du logiciel — lettrage, reports, écritures d'à-nouveau. Cet écart est une
 * information comptable ; le recalculer l'effacerait, exactement comme pour le solde annoncé
 * par les états de caisse.
 *
 * **Une photographie, pas un journal.** Réimporter met à jour le solde d'un fournisseur au
 * lieu d'en ajouter un second. L'historique reste dans le lot d'import, qui garde le fichier
 * tel qu'il est arrivé.
 */
class FormatDeLaBalanceFournisseur extends Format
{
    public static function cle(): string
    {
        return 'balance-fournisseurs';
    }

    public static function libelle(): string
    {
        return 'Balance fournisseurs — débit, crédit, solde';
    }

    public static function colonnes(): array
    {
        return [
            // L'intitulé est au pluriel dans le fichier : on le recopie tel quel, la
            // reconnaissance se faisant sur l'intitulé complet.
            'fournisseur' => 'FOURNISSEURS',
            'debit' => 'DEBIT',
            'credit' => 'CREDIT',
            'solde' => 'SOLDE',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['fournisseur'];
    }

    /** Le fichier est global : aucune colonne ne dit le lieu. */
    protected function colonneSite(array $ligne): ?string
    {
        return null;
    }

    /** Une balance ne cite ni fiche ni pièce : il n'y a pas de code agent à en tirer. */
    /** Un solde fournisseur appartient à l'entreprise, pas à un atelier : ce fichier ne porte aucun numéro de fiche, et aucun code n'a rien à y ranger. */
    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    protected function reference(array $ligne): ?string
    {
        return null;
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        // Une ligne de total n'est pas un fournisseur. L'importer créerait un compte
        // fantôme dont le solde vaudrait la somme de tous les autres, et il se glisserait
        // dans chaque total que l'application calcule ensuite.
        $nom = mb_strtoupper(trim((string) ($ligne['fournisseur'] ?? '')));

        if (in_array($nom, self::LIGNES_DE_TOTAL, true)) {
            return 'Ligne de total, et non un fournisseur.';
        }

        return null;
    }

    /** Les intitulés sous lesquels un tableur écrit une ligne de total. */
    private const LIGNES_DE_TOTAL = ['TOTAL', 'TOTAUX', 'CUMUL', 'TOTAL GENERAL', 'TOTAL GÉNÉRAL'];

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $fournisseur = self::texte($ligne['fournisseur'] ?? null, 160);

        $valeurs = [
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'debit' => (int) round((float) self::montant($ligne['debit'] ?? null)),
            'credit' => (int) round((float) self::montant($ligne['credit'] ?? null)),
            'solde' => (int) round((float) self::montant($ligne['solde'] ?? null)),
            'code_agent' => $rattachement['code'],
            'source_rattachement' => $rattachement['source'],
            'rattachement_presume' => $rattachement['presumee'],
        ];

        $existant = SoldeFournisseur::where('entreprise_id', $this->entrepriseId)
            ->where('fournisseur', $fournisseur)
            ->first();

        if ($existant) {
            $existant->fill($valeurs)->save();

            return 'maj';
        }

        SoldeFournisseur::create($valeurs + [
            'entreprise_id' => $this->entrepriseId,
            'fournisseur' => $fournisseur,
        ]);

        return 'cree';
    }
}

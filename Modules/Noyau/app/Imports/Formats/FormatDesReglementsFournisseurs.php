<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\ReglementFournisseur;

/**
 * Les règlements fournisseurs, tels que le logiciel comptable les exporte.
 *
 * Cinq colonnes, relevées sur le fichier réel
 * (`REGLEMENT-FOURNISSEUR(global-pas de filtre sur exercice)010126-170926.xlsx`) : DATE
 * REGLEMENT, CODE REGLEMENT, FOURNISSEURS, MODE REGLEMENT, MONTANT CFA.
 *
 * **Le code de règlement est la clé.** « F-REG N°000300 » identifie le paiement chez le
 * comptable : c'est lui qui permet de redéposer le fichier sans doubler ses lignes, et c'est
 * lui qu'on cite quand un fournisseur conteste avoir été payé. Sans lui, deux virements du
 * même jour, au même fournisseur, pour le même montant seraient indiscernables — et le
 * fichier en contient.
 *
 * **La date arrive en numéro de série de tableur** (46297 pour un jour de septembre 2026) :
 * le lecteur commun la convertit, comme pour tous les autres fichiers.
 *
 * **Le fichier est global** — son nom le dit, « pas de filtre sur exercice » — et ne porte
 * aucune colonne SITE. Les règlements se rangent donc au niveau de l'entreprise.
 */
class FormatDesReglementsFournisseurs extends Format
{
    public static function cle(): string
    {
        return 'reglements-fournisseurs';
    }

    public static function libelle(): string
    {
        return 'Règlements fournisseurs — paiements enregistrés';
    }

    public static function colonnes(): array
    {
        return [
            'date_reglement' => 'DATE REGLEMENT',
            'code_reglement' => 'CODE REGLEMENT',
            'fournisseur' => 'FOURNISSEURS',
            'mode_reglement' => 'MODE REGLEMENT',
            'montant' => 'MONTANT CFA',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        // Sans code, on ne saurait ni créer la ligne ni la retrouver au dépôt suivant.
        return ['code_reglement', 'fournisseur'];
    }

    protected function colonneSite(array $ligne): ?string
    {
        return null;
    }

    /** Le code de règlement est la référence : c'est de lui que se tire le code agent. */
    /** Le code de règlement du logiciel comptable n'est pas un numéro de fiche : il ressemble à un code sans en être un, et c'est justement pour cela qu'il faut le dire ici plutôt que de laisser l'écran le supposer. */
    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    protected function reference(array $ligne): ?string
    {
        return self::texte($ligne['code_reglement'] ?? null, 60);
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $code = self::texte($ligne['code_reglement'] ?? null, 60);

        $valeurs = [
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'date_reglement' => self::date($ligne['date_reglement'] ?? null),
            'fournisseur' => self::texte($ligne['fournisseur'] ?? null, 160),
            'mode_reglement' => self::texte($ligne['mode_reglement'] ?? null, 80),
            'montant' => (int) round((float) self::montant($ligne['montant'] ?? null)),
            'code_agent' => $rattachement['code'],
            'source_rattachement' => $rattachement['source'],
            'rattachement_presume' => $rattachement['presumee'],
        ];

        $existant = ReglementFournisseur::where('entreprise_id', $this->entrepriseId)
            ->where('code_reglement', $code)
            ->first();

        if ($existant) {
            $existant->fill($valeurs)->save();

            return 'maj';
        }

        ReglementFournisseur::create($valeurs + [
            'entreprise_id' => $this->entrepriseId,
            'code_reglement' => $code,
        ]);

        return 'cree';
    }
}

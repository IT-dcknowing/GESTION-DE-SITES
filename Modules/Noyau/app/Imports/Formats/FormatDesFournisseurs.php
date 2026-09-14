<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\FactureFournisseur;

/**
 * Le suivi des factures fournisseurs — ce que l'entreprise doit.
 *
 * **La feuille lue n'est pas celle qu'annonce le nom du classeur.** Le fichier compte cinq
 * onglets, et le seul exploitable est « contrôle chq » : vingt-deux colonnes propres, une
 * ligne par facture, avec le fournisseur, le montant, ce qui a été réglé et ce qui reste.
 * L'onglet « DETAIL », que son nom désignerait pourtant, n'est pas une liste : c'est une
 * feuille de calcul où les neuf premières lignes sont des règles d'usage et des totaux, et
 * dont les colonnes sont des formules empilées. La lire aurait donné des lignes vides et un
 * import qui « marche » sans rien contenir.
 *
 * La recherche de la feuille est donc laissée à la méthode générale, qui choisit celle dont
 * l'en-tête ressemble le plus à ce qu'on attend — et qui trouve « contrôle chq » d'elle-même.
 *
 * **La colonne SITE existe et vaut d'être suivie** : c'est le deuxième fichier après le CATTC
 * à dire d'où vient chaque ligne, ce qui évite d'avoir à le deviner.
 *
 * **Le TVA n'est pas repris.** Deux colonnes le portent, « TVA » et « TVA 2 », avec des
 * valeurs parfois égales et parfois non, sans qu'aucune règle ne se lise dans le fichier.
 * Reprendre l'une des deux au hasard donnerait un montant de taxe faux qu'on croirait juste ;
 * le montant total, lui, est net d'ambiguïté et c'est celui qui fait la dette.
 */
class FormatDesFournisseurs extends Format
{
    private ?array $piecesConnues = null;

    public static function cle(): string
    {
        return 'fournisseurs';
    }

    public static function libelle(): string
    {
        return 'Factures fournisseurs — ce que nous devons';
    }

    public static function colonnes(): array
    {
        return [
            'mois' => 'MOIS',
            'date_reglement' => 'DATE DE REGLEMENT',
            'date_facture' => 'DATE FACTURE/BC',
            'date_reception' => 'DATE RECEPTION FACTURE',
            'site' => 'SITE',
            'section' => 'SECTION',
            'numero_bc' => 'N° BC',
            'nature_piece' => 'NATURE PIECE',
            'numero_piece' => 'N° PIECE',
            'montant' => 'MONTANT',
            'fournisseur' => 'FOURNISSEUR',
            'mode_reglement' => 'MODE DE REGLEMENT',
            'numero_cheque' => 'n° CHQ',
            'montant_regle' => 'MONTANT REGLE',
            'reste_a_payer' => 'RESTE A PAYER',
            'imputation' => 'IMPUTATION',
            'vehicule' => 'VEHICULE',
            'immatriculation' => 'IMMAT',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['fournisseur', 'montant'];
    }

    protected function colonneSite(array $ligne): ?string
    {
        return self::texte($ligne['site'] ?? null, 60);
    }

    /** Le bon de commande porte parfois le code, mais pas de façon fiable : on n'en tire rien. */
    protected function reference(array $ligne): ?string
    {
        return null;
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if (self::montant($ligne['montant'] ?? null) === null) {
            return "Le montant de la facture n'est pas un nombre exploitable.";
        }

        // Sans date de facture, la dette n'a pas d'âge : elle ne peut ni entrer dans une
        // balance âgée ni être relancée à échéance. Mieux vaut la signaler que la ranger
        // à une date inventée.
        if (self::date($ligne['date_facture'] ?? null) === null) {
            return "La date de la facture est absente ou illisible.";
        }

        return null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $fournisseur = self::texte($ligne['fournisseur'] ?? null, 160);
        $piece = self::texte($ligne['numero_piece'] ?? null, 60);
        $montant = (int) round((float) self::montant($ligne['montant'] ?? null));

        $regle = self::montant($ligne['montant_regle'] ?? null);
        $reste = self::montant($ligne['reste_a_payer'] ?? null);

        $valeurs = [
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'numero_piece' => $piece,
            'nature_piece' => self::texte($ligne['nature_piece'] ?? null, 40),
            'numero_bc' => self::texte($ligne['numero_bc'] ?? null, 60),
            'fournisseur' => $fournisseur,
            'date_facture' => self::date($ligne['date_facture'] ?? null),
            'date_reception' => self::date($ligne['date_reception'] ?? null),
            'date_reglement' => self::date($ligne['date_reglement'] ?? null),
            'montant' => $montant,
            'montant_regle' => $regle === null ? 0 : (int) round($regle),
            // Le reste est repris tel que le fichier l'annonce plutôt que recalculé :
            // l'écart éventuel entre « montant − réglé » et la colonne du fichier est une
            // information comptable, et la recalculer l'effacerait.
            'reste_a_payer' => $reste === null ? max(0, $montant - (int) round((float) $regle)) : (int) round($reste),
            'mode_reglement' => self::texte($ligne['mode_reglement'] ?? null, 80),
            'imputation' => self::texte($ligne['imputation'] ?? null, 120),
            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 40),
            'observations' => $this->observations($ligne),
            'code_agent' => $rattachement['code'],
            'source_rattachement' => $rattachement['source'],
            'rattachement_presume' => $rattachement['presumee'],
        ];

        // La clé est le couple fournisseur + numéro de pièce : un même numéro de facture
        // revient chez deux fournisseurs différents, et un fournisseur ne réutilise pas
        // deux fois le sien. Quand la pièce manque, on retombe sur la date et le montant.
        $existante = $this->retrouver($fournisseur, $piece, $valeurs);

        if ($existante === null) {
            FactureFournisseur::withoutGlobalScopes()->create($valeurs);
            $this->piecesConnues = null;

            return 'cree';
        }

        if ($rattachement['presumee'] && $existante->site_id !== null) {
            unset($valeurs['site_id']);
        }

        $existante->fill($valeurs);

        if (! $existante->isDirty()) {
            return 'ignore';
        }

        $existante->save();

        return 'maj';
    }

    private function retrouver(?string $fournisseur, ?string $piece, array $valeurs): ?FactureFournisseur
    {
        $requete = FactureFournisseur::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('fournisseur', $fournisseur);

        if ($piece !== null && $piece !== '') {
            return $requete->where('numero_piece', $piece)->first();
        }

        return $requete
            ->where('date_facture', $valeurs['date_facture'])
            ->where('montant', $valeurs['montant'])
            ->first();
    }

    private function observations(array $ligne): ?string
    {
        $morceaux = array_filter([
            ($s = self::texte($ligne['section'] ?? null, 60)) ? "Section : {$s}" : null,
            ($v = self::texte($ligne['vehicule'] ?? null, 80)) ? "Véhicule : {$v}" : null,
            ($c = self::texte($ligne['numero_cheque'] ?? null, 40)) ? "Chèque : {$c}" : null,
        ]);

        return $morceaux === [] ? null : implode(' · ', $morceaux);
    }
}

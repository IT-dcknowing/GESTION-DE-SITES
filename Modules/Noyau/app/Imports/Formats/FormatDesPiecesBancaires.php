<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\PieceBancaire;

/**
 * Le relevé d'un compte bancaire, tel que le logiciel comptable l'exporte.
 *
 * **Huit colonnes, relevées sur l'écran et non sur le fichier.** Le propriétaire a montré le
 * 30/09 l'écran « Liste de toutes les pièces comptables » du logiciel ; le fichier lui-même
 * n'est pas encore arrivé. Les noms de colonnes sont donc ceux de l'écran, et c'est assumé :
 * ils seront confrontés au premier dépôt, et `colonnes()` est l'endroit où les corriger.
 *
 * | Colonne de l'écran | Ce qu'on en fait |
 * |---|---|
 * | DATE PIECE | la date de l'écriture |
 * | CODE PIECE | **la clé** : elle identifie la pièce chez le comptable |
 * | REFERENCE PIECE | le n° de chèque, de bordereau, de transaction |
 * | BANQUE EMETRICE | la banque du chèque **reçu** — pas notre compte |
 * | TYPE DE PIECES | ce dont on tire le sens, quand on sait le lire |
 * | MODELE DE REGLEMENT | chèque, virement, versement… |
 * | BENEFICIAIRES / REMETTANT | qui a versé, ou à qui l'on a payé |
 * | MONTANT PIECE | le montant |
 *
 * **Le compte n'est pas dans le fichier, et c'est le point à ne pas manquer.** Sur cet écran,
 * le compte bancaire se choisit **hors de la grille**, dans une liste au-dessus — AFG BANK,
 * BGFI BANK, BNI. Il est donc déclaré au dépôt et porté par le lot. « BANQUE EMETRICE » est
 * une autre chose : la banque du chèque reçu. Les confondre rangerait sous la BGFI tout
 * règlement reçu par chèque BGFI, quel que soit le compte crédité — c'est-à-dire fausserait
 * exactement la question que l'écran des banques sert à répondre.
 *
 * **Le fichier n'est pas ventilé par les codes employés** : c'est un export comptable, il ne
 * porte aucun code de saisie. Le rattachement vient de la ville et de l'atelier déclarés.
 */
class FormatDesPiecesBancaires extends Format
{
    /** Le compte déclaré au dépôt, lu une fois pour tout le fichier. */
    private ?int $banqueDuLot = null;

    private bool $banqueLue = false;

    public static function cle(): string
    {
        return 'banque';
    }

    public static function libelle(): string
    {
        return 'Relevé bancaire — pièces d’un compte';
    }

    public static function colonnes(): array
    {
        return [
            'date_piece' => 'DATE PIECE',
            'code_piece' => 'CODE PIECE',
            'reference_piece' => 'REFERENCE PIECE',
            'banque_emettrice' => 'BANQUE EMETRICE',
            'type_piece' => 'TYPE DE PIECES',
            'modele_reglement' => 'MODELE DE REGLEMENT',
            'beneficiaire' => 'BENEFICIAIRES / REMETTANT',
            'montant' => 'MONTANT PIECE',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        // Sans code de pièce, on ne saurait ni créer la ligne ni la retrouver au dépôt
        // suivant : le fichier se redéposerait en doublant tout.
        return ['code_piece'];
    }

    /** Un export comptable ne porte aucun code de saisie : rien à ventiler par là. */
    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    protected function colonneSite(array $ligne): ?string
    {
        return null;
    }

    protected function reference(array $ligne): ?string
    {
        return self::texte($ligne['code_piece'] ?? null, 60);
    }

    /**
     * Une ligne sans compte déclaré est refusée, et c'est la seule règle propre à ce format.
     *
     * Sans compte, une pièce bancaire n'est rattachable à rien : elle gonflerait le total
     * général sans paraître sous aucune banque. Le dépôt exige donc la banque — voir
     * `DepotController` —, et ce refus est la seconde garde, celle qui tient si un lot
     * ancien ou un rejeu arrive sans elle.
     */
    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        return null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $banqueId = $this->banqueDuLot($lotId);

        if ($banqueId === null) {
            // Jamais atteint par un dépôt ordinaire : le formulaire exige la banque. La
            // garde reste pour un rejeu d'un lot déposé autrement.
            return 'ignore';
        }

        $code = self::texte($ligne['code_piece'] ?? null, 60);
        $type = self::texte($ligne['type_piece'] ?? null, 80);

        $valeurs = [
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'date_piece' => self::date($ligne['date_piece'] ?? null),
            'reference_piece' => self::texte($ligne['reference_piece'] ?? null, 120),
            'banque_emettrice' => self::texte($ligne['banque_emettrice'] ?? null, 120),
            'type_piece' => $type,
            'modele_reglement' => self::texte($ligne['modele_reglement'] ?? null, 80),
            'beneficiaire' => self::texte($ligne['beneficiaire'] ?? null, 180),
            'montant' => (int) round((float) self::montant($ligne['montant'] ?? null)),
            // Nul quand le type ne se lit pas : une pièce qu'on ne sait pas orienter doit se
            // voir, pas se deviner.
            'sens' => PieceBancaire::sensDuType($type),
            'code_agent' => $rattachement['code'],
            'source_rattachement' => $rattachement['source'],
            'rattachement_presume' => $rattachement['presumee'],
        ];

        $existant = PieceBancaire::where('banque_id', $banqueId)
            ->where('code_piece', $code)
            ->first();

        if ($existant) {
            $existant->fill($valeurs)->save();

            return 'maj';
        }

        PieceBancaire::create($valeurs + [
            'entreprise_id' => $this->entrepriseId,
            'banque_id' => $banqueId,
            'code_piece' => $code,
        ]);

        return 'cree';
    }

    /**
     * Le compte déclaré au dépôt — lu **une fois** pour tout le fichier.
     *
     * Le relire à chaque ligne ferait une requête par pièce, sur un fichier qui en porte des
     * milliers. `$banqueLue` garde la valeur même quand elle est nulle : sans lui, un lot sans
     * banque ferait la requête à chaque ligne pour retomber sur le même vide.
     */
    private function banqueDuLot(?int $lotId): ?int
    {
        if ($this->banqueLue) {
            return $this->banqueDuLot;
        }

        $this->banqueLue = true;
        $this->banqueDuLot = $lotId === null
            ? null
            : LotImport::withoutGlobalScopes()->whereKey($lotId)->value('banque_id');

        return $this->banqueDuLot;
    }
}

<?php

namespace Modules\Noyau\Rapport\Support;

/**
 * Lit les lignes d'un fichier déposé (impôts, CNPS, salaires, récapitulatifs) et en tire des pièces.
 *
 * Le fichier n'a pas de format imposé — « ce que j'ai fait en ajoutant les impôts, la CNPS, des
 * fichiers de récap » : on cherche donc l'en-tête (une ligne qui nomme un montant et un libellé, une
 * date ou une référence), on repère les colonnes par leur nom, et chaque ligne qui porte un montant
 * devient une pièce. Une ligne « Total » est écartée — la compter avec ses lignes la doublerait.
 *
 * Classe pure, sans framework : les lignes arrivent déjà lues par `Classeur`.
 */
final class LectureDesPieces
{
    public const NATURES = [
        'impot' => 'Impôts',
        'cnps' => 'CNPS',
        'salaire' => 'Salaires',
        'recap' => 'Récapitulatif (sert au contrôle, ne se compte pas)',
        'autre' => 'Autre charge',
    ];

    /** La nature que dit un texte (nom de fichier, de feuille ou de ligne) — ou null. */
    public static function natureDepuis(?string $texte): ?string
    {
        $t = mb_strtolower((string) $texte, 'UTF-8');

        return match (true) {
            (bool) preg_match('/cnps|prevoyance|prévoyance|caisse nationale/u', $t) => 'cnps',
            (bool) preg_match('/imp[oô]t|dgi|tva|patente|taxe|avis de fermeture|fiscal/u', $t) => 'impot',
            (bool) preg_match('/salaire|paie|paye\b|net à payer|net a payer/u', $t) => 'salaire',
            (bool) preg_match('/r[ée]cap|synth[èe]se|situation/u', $t) => 'recap',
            default => null,
        };
    }

    /** « 1 234 567,50 », « 1.234.567 », 1234567.0 → 1234567 (francs : jamais de décimale utile). */
    public static function nombre(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur;
        }

        if (is_float($valeur)) {
            return (int) round($valeur);
        }

        $t = trim(str_replace(["\u{00A0}", "\u{202F}", ' ', 'FCFA', 'F CFA', 'XOF', 'F'], '', (string) $valeur));

        if ($t === '' || ! preg_match('/^-?[\d.,]+$/', $t)) {
            return null;
        }

        // Le dernier séparateur suivi d'un ou deux chiffres est décimal ; les autres sont des milliers.
        if (preg_match('/[.,](\d{1,2})$/', $t)) {
            $t = (string) preg_replace('/[.,](\d{1,2})$/', '|$1', $t);
            $t = str_replace(['.', ','], '', $t);
            $t = str_replace('|', '.', $t);
        } else {
            $t = str_replace(['.', ','], '', $t);
        }

        return (int) round((float) $t);
    }

    /** Une date lue — « 03/11/2026 », « 2026-11-03 », ou un numéro de série Excel — en AAAA-MM-JJ. */
    public static function date(mixed $valeur): ?string
    {
        if ($valeur instanceof \DateTimeInterface) {
            return $valeur->format('Y-m-d');
        }

        if (is_numeric($valeur) && (float) $valeur > 30000 && (float) $valeur < 80000) {
            return gmdate('Y-m-d', (int) (((float) $valeur - 25569) * 86400));
        }

        $t = trim((string) $valeur);

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $t, $m)) {
            $an = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $an) ? sprintf('%04d-%02d-%02d', $an, $m[2], $m[1]) : null;
        }

        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $t, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        return null;
    }

    private static function entete(mixed $cellule): string
    {
        return EmpreinteDePiece::texte(is_scalar($cellule) ? (string) $cellule : '');
    }

    /**
     * @param  list<list<mixed>>  $lignes
     * @return array{entete: bool, pieces: list<array{nature: string, statut: string, date: ?string, libelle: string, tiers: ?string, reference: ?string, montant: int, compte: bool}>}
     */
    public static function depuisLesLignes(array $lignes, ?string $natureParDefaut = null): array
    {
        $colonnes = null;
        $debut = 0;

        foreach (array_slice($lignes, 0, 25, true) as $i => $ligne) {
            $trouvees = [];

            foreach ($ligne as $j => $cellule) {
                $e = self::entete($cellule);

                if ($e === '') {
                    continue;
                }

                $champ = match (true) {
                    str_contains($e, 'MONTANT') || $e === 'TOTAL' || str_contains($e, 'SOLDE') || str_contains($e, 'DROITS') || str_contains($e, 'NETAPAYER') || $e === 'NET' => 'montant',
                    str_contains($e, 'DATE') => 'date',
                    str_contains($e, 'LIBELLE') || str_contains($e, 'DESIGNATION') || str_contains($e, 'OBJET') || str_contains($e, 'NATURE') || str_contains($e, 'DESCRIPTION') => 'libelle',
                    str_contains($e, 'REF') || str_contains($e, 'NUMERO') || $e === 'N' => 'reference',
                    str_contains($e, 'TIERS') || str_contains($e, 'BENEFICIAIRE') || str_contains($e, 'ORGANISME') || str_contains($e, 'FOURNISSEUR') => 'tiers',
                    str_contains($e, 'STATUT') || str_contains($e, 'ETAT') || str_contains($e, 'SITUATION') => 'statut',
                    default => null,
                };

                if ($champ !== null && ! isset($trouvees[$champ])) {
                    $trouvees[$champ] = $j;
                }
            }

            if (isset($trouvees['montant']) && count($trouvees) >= 2) {
                $colonnes = $trouvees;
                $debut = $i + 1;
                break;
            }
        }

        if ($colonnes === null) {
            return ['entete' => false, 'pieces' => []];
        }

        $pieces = [];

        foreach (array_slice($lignes, $debut, null, true) as $ligne) {
            $montant = self::nombre($ligne[$colonnes['montant']] ?? null);
            $libelle = trim((string) ($ligne[$colonnes['libelle'] ?? -1] ?? ''));

            if ($montant === null || $montant === 0) {
                continue;
            }

            // Une ligne de total reprend les autres : l'ajouter les compterait deux fois.
            if (preg_match('/^(sous[- ]?)?total/iu', $libelle) || preg_match('/^total/iu', trim((string) ($ligne[0] ?? '')))) {
                continue;
            }

            $nature = self::natureDepuis($libelle) ?? $natureParDefaut ?? 'autre';
            $texteStatut = mb_strtolower($libelle.' '.($ligne[$colonnes['statut'] ?? -1] ?? ''), 'UTF-8');
            $statut = preg_match('/pay[ée]|r[ée]gl[ée]|acquitt|vers[ée]/u', $texteStatut) && ! preg_match('/non (pay|r[ée]gl)|impay/u', $texteStatut)
                ? 'paye' : 'du';

            $pieces[] = [
                'nature' => $nature,
                'statut' => $nature === 'recap' ? 'info' : $statut,
                'date' => self::date($ligne[$colonnes['date'] ?? -1] ?? null),
                'libelle' => $libelle !== '' ? mb_substr($libelle, 0, 200) : (self::NATURES[$nature] ?? 'Pièce'),
                'tiers' => ($t = trim((string) ($ligne[$colonnes['tiers'] ?? -1] ?? ''))) !== '' ? mb_substr($t, 0, 200) : null,
                'reference' => ($r = trim((string) ($ligne[$colonnes['reference'] ?? -1] ?? ''))) !== '' ? mb_substr($r, 0, 80) : null,
                'montant' => abs($montant),
                'compte' => $nature !== 'recap',
            ];
        }

        return ['entete' => true, 'pieces' => $pieces];
    }
}

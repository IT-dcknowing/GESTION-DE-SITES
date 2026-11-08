<?php

namespace Modules\Noyau\Rapport\Support;

/**
 * L'empreinte d'une pièce du dépôt de rapport — ce qui l'empêche de compter deux fois (06/11).
 *
 * **La demande du propriétaire.** « Le système doit lire les fichiers déposés sans faire de doublons
 * — il se pourrait même qu'on dépose un fichier récapitulatif de ce qui est déjà là. » Deux garde-fous :
 *
 *  1. le **fichier** : le même contenu (SHA-256) déposé deux fois est refusé d'emblée ;
 *  2. la **pièce** : une ligne déjà connue (même nature, même statut, même référence — ou, sans
 *     référence, même date et même tiers — et même montant) n'est pas recréée, d'où qu'elle vienne :
 *     un récapitulatif qui reprend l'avis des impôts déjà saisi n'ajoute rien.
 *
 * Classe pure : aucune dépendance au framework, donc testable seule.
 */
final class EmpreinteDePiece
{
    /** Le texte ramené à ce qui le distingue : majuscules, sans accent, sans ponctuation ni espaces. */
    public static function texte(?string $texte): string
    {
        $t = mb_strtoupper(trim((string) $texte), 'UTF-8');
        $t = strtr($t, [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C',
        ]);

        return (string) preg_replace('/[^A-Z0-9]+/', '', $t);
    }

    public static function pourUnePiece(string $nature, string $statut, ?string $date, int $montant, ?string $reference, ?string $tiers, ?string $libelle = null): string
    {
        $ref = self::texte($reference);

        $cle = $ref !== ''
            ? implode('|', [$nature, $statut, 'ref', $ref, self::texte($libelle), $montant])
            : implode('|', [$nature, $statut, (string) $date, self::texte($tiers), self::texte($libelle), $montant]);

        return sha1($cle);
    }

    /** L'empreinte d'un fichier : son contenu, pas son nom. */
    public static function pourUnFichier(string $chemin): string
    {
        return (string) hash_file('sha256', $chemin);
    }
}

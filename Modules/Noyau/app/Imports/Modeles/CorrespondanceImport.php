<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;

/**
 * La table qui absorbe les fautes de frappe des fichiers.
 *
 * Trois valeurs relevées dans les vrais exports, pour deux villes : « ABIIDJAN » avec un
 * i de trop, « SAN-PEDRO » avec un tiret, « ÄBIDJAN » avec un tréma. Une comparaison de
 * chaînes les rejetterait toutes les trois ; une table de correspondance les rattache une
 * fois, et l'affaire est close pour tous les imports suivants.
 *
 * Elle sert partout où une valeur libre doit rejoindre un référentiel : les sites, les
 * fournisseurs, les tiers, les modes de règlement. Ce dernier cas est le plus spectaculaire
 * — l'état des impayés contient **730 modes de règlement distincts**, parce que le montant
 * est écrit dans le libellé : « CHEQUE DE 34,205,673 F CFA ». Il n'y en a que trois.
 *
 * Une correspondance non résolue n'est pas une erreur : c'est une question posée à
 * l'utilisateur. Elle est comptée, elle remonte dans l'écran, et l'import continue.
 */
#[Fillable([
    'entreprise_id', 'domaine', 'valeur_source', 'valeur_cible',
    'cible_id', 'est_resolue', 'occurrences',
])]
class CorrespondanceImport extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'correspondances_import';

    /** Les domaines où une valeur de fichier doit rejoindre une valeur de la base. */
    public const DOMAINES = [
        'site' => 'Sites',
        'ville' => 'Villes',
        'fournisseur' => 'Fournisseurs',
        'tiers' => 'Clients, assurances et courtiers',
        'moyen' => 'Modes de règlement',
        'imputation' => 'Imputations comptables',
        'statut' => 'Statuts de dossier',
        'motif' => 'Motifs de venue',
        // La colonne libre de la fiche de réception, quand elle nomme le commercial qui a
        // décroché l'affaire. Voir CommercialDeLaFiche.
        'commercial' => 'Commerciaux nommés sur la fiche de réception',
    ];

    protected function casts(): array
    {
        return ['est_resolue' => 'boolean', 'occurrences' => 'integer'];
    }

    /**
     * Ce qu'une valeur de fichier devient, ou null si personne ne l'a encore dit.
     *
     * La normalisation qui sert de clé est volontairement large — casse, accents,
     * espaces et ponctuation effacés. C'est elle qui fait que « SAN-PEDRO », « San Pedro »
     * et « SAN PÉDRO » ne posent la question qu'une seule fois.
     */
    public static function resoudre(int $entrepriseId, string $domaine, ?string $source): ?string
    {
        $source = trim((string) $source);

        if ($source === '') {
            return null;
        }

        $correspondance = static::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('domaine', $domaine)
            ->where('valeur_source', self::normaliser($source))
            ->first();

        return $correspondance?->est_resolue ? $correspondance->valeur_cible : null;
    }

    /**
     * Enregistre la rencontre d'une valeur, résolue ou non, et rend la correspondance.
     *
     * `$cible` vaut null quand on ne sait pas encore : la ligne est alors créée non
     * résolue, ce qui la fait apparaître dans l'écran des correspondances à traiter.
     */
    public static function rencontrer(int $entrepriseId, string $domaine, string $source, ?string $cible = null): self
    {
        $correspondance = static::withoutGlobalScopes()->firstOrCreate(
            [
                'entreprise_id' => $entrepriseId,
                'domaine' => $domaine,
                'valeur_source' => self::normaliser($source),
            ],
            [
                'valeur_cible' => $cible,
                'est_resolue' => $cible !== null,
                'occurrences' => 0,
            ],
        );

        $correspondance->increment('occurrences');

        return $correspondance;
    }

    /**
     * La forme sous laquelle deux orthographes d'une même chose se rejoignent.
     *
     * Les accents tombent, la ponctuation aussi, la casse est unifiée et les espaces
     * réduits. « ÄBIDJAN », « Abidjan » et « ABIDJAN  » donnent tous « ABIDJAN ».
     */
    public static function normaliser(string $valeur): string
    {
        $valeur = mb_strtoupper(trim($valeur));

        $accents = [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ];

        $valeur = strtr($valeur, $accents);

        // La ponctuation devient un espace plutôt que rien : « SAN-PEDRO » doit rejoindre
        // « SAN PEDRO », et non produire « SANPEDRO » qui ne ressemble plus à rien.
        $valeur = preg_replace('/[^A-Z0-9]+/u', ' ', $valeur);

        return trim(preg_replace('/\s+/', ' ', $valeur));
    }
}

<?php

namespace Modules\Import\Support;

use App\Models\User;

/**
 * Qui voit quelles pages de l'import, et qui a le droit d'écrire.
 *
 * La règle de départ est celle qui a été posée : **chaque responsable de ville fait
 * l'import de sa ville, et le gérant peut faire celui de n'importe laquelle.** Le
 * responsable de site en fait partie — c'est lui qui est devant l'atelier et qui sort les
 * exports — mais son périmètre reste sa ville, comme celui du superviseur.
 *
 * Le partage traduit une séparation simple :
 *
 * - **déposer** engage la base : c'est réservé à ceux qui répondent d'une ville ;
 * - **lire** le journal est ouvert plus largement, parce qu'un chiffre qu'on ne peut pas
 *   expliquer ne sert à personne ;
 * - **nommer les codes** décide de la ville et de l'atelier auxquels des millions de
 *   francs seront rattachés. C'est un acte de référentiel, pas de saisie : il remonte au
 *   gérant et au superviseur de ville.
 *
 * Ces règles sont appliquées par le middleware sur chaque route, et relues par chaque écran
 * avant d'agir. Une page absente du menu n'est pas une page fermée : son adresse s'écrit à
 * la main.
 */
class AccesImport
{
    /**
     * Les cinq pages, dans l'ordre où elles se lisent.
     *
     * Le module a maigri, et c'est voulu : **il sert à importer, un point c'est tout.**
     * Le parc véhicules est parti rejoindre les indicateurs, où il a sa place — l'import le
     * remplit, l'exploitation le consulte, et on le lit tous les jours alors qu'on n'importe
     * qu'une fois par semaine. Quant aux correspondances d'orthographe, elles ne méritaient
     * pas un écran à elles seules : la cascade de rattachement les résout, et ce qu'elle ne
     * résout pas se lit dans le journal du dépôt concerné.
     */
    public const PAGES = [
        'depot' => ['libelle' => 'Déposer un fichier', 'icone' => '⇪'],
        // Ce qui tourne à cet instant, et ce qui attend que quelqu'un le prenne. Sans cet
        // écran, un dépôt que personne ne traite reste « Déposé » sans que rien ne
        // l'explique — on attend devant une progression qui n'a pas commencé.
        'traitements' => ['libelle' => 'Traitement', 'icone' => '◐'],
        'lots' => ['libelle' => 'Journal des imports', 'icone' => '≣'],
        // Du texte, jamais du HTML : Blade échappe ce qu'il affiche, et une entité écrite
        // ici repassait à la moulinette — le menu montrait « Employés &amp; codes ».
        'codes' => ['libelle' => 'Employés & codes', 'icone' => '⚿'],
        // Quel fichier alimente quelle page. Sa question est celle de l'import — que dois-je
        // déposer, et qu'est-ce que cela va remplir — et non celle du recouvrement.
        'informations' => ['libelle' => 'Informations', 'icone' => 'ⓘ'],
    ];

    /** Sous-titre de chaque page, affiché sous son titre. */
    public const SOUS_TITRES = [
        'depot' => 'Le fichier est rangé, puis lu en arrière-plan. Vous pouvez quitter la page.',
        'traitements' => 'Ce qui est en cours de lecture, ce qui attend, et comment le lancer.',
        'lots' => "Chaque dépôt, qui l'a fait, quand, et ce qu'il a produit.",
        'codes' => "Qui rédige les fiches dans le logiciel d'atelier, et sous quelles deux lettres.",
        'informations' => "Quel fichier alimente quelle page, et dans quel ordre les déposer.",
    ];

    /** @var array<string, list<string>> */
    private const PAGES_PAR_ROLE = [
        'gerant' => ['depot', 'traitements', 'lots', 'codes', 'informations'],
        'responsable_ville' => ['depot', 'traitements', 'lots', 'codes', 'informations'],
        // Le responsable de site dépose et consulte, mais ne tranche pas le référentiel :
        // décider que « KZ » travaille au Site 1 déplace le chiffre d'affaires entre deux
        // ateliers dont l'un est le sien.
        'responsable_site' => ['depot', 'traitements', 'lots', 'informations'],
        // Le journal d'abord : c'est la page d'arrivée de la comptabilité, qui consulte
        // ce qui a été importé plutôt que ce qui est en train de l'être.
        'caissier' => ['lots', 'traitements', 'informations'],
    ];

    /** Rôles autorisés à déposer un fichier et à relancer un traitement. */
    private const DEPOSANTS = ['gerant', 'responsable_ville', 'responsable_site'];

    /** Rôles autorisés à nommer un code ou à trancher une correspondance. */
    private const ARBITRES = ['gerant', 'responsable_ville'];

    /** Le rôle retenu pour ce module, le plus étendu d'abord. */
    public static function role(?User $utilisateur): ?string
    {
        if (! $utilisateur) {
            return null;
        }

        foreach (array_keys(self::PAGES_PAR_ROLE) as $role) {
            if ($utilisateur->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function pagesDe(?User $utilisateur): array
    {
        $role = self::role($utilisateur);

        return $role === null ? [] : self::PAGES_PAR_ROLE[$role];
    }

    public static function peutVoir(?User $utilisateur, string $page): bool
    {
        return in_array($page, self::pagesDe($utilisateur), true);
    }

    public static function peutDeposer(?User $utilisateur): bool
    {
        return in_array(self::role($utilisateur), self::DEPOSANTS, true);
    }

    public static function peutArbitrer(?User $utilisateur): bool
    {
        return in_array(self::role($utilisateur), self::ARBITRES, true);
    }

    /** La page d'arrivée : la première à laquelle la personne a droit. */
    public static function premierePage(?User $utilisateur): ?string
    {
        return self::pagesDe($utilisateur)[0] ?? null;
    }
}

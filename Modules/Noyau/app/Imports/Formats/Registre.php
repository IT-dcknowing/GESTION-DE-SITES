<?php

namespace Modules\Noyau\Imports\Formats;

/**
 * Les formats que le module sait lire, et ceux qu'il saura lire.
 *
 * Une seule liste, tenue à jour, plutôt qu'un `match` recopié dans chaque écran. Ajouter
 * un format se fait en une ligne ici et une classe à côté.
 *
 * **La distinction entre `DISPONIBLES` et `ANNONCES` est délibérée.** Il serait facile de
 * n'afficher que ce qui marche et de laisser croire que le reste n'existe pas ; il serait
 * malhonnête d'afficher onze entrées dont une seule fonctionne. L'écran de dépôt montre
 * donc les deux, distinctement : ce qu'on peut importer aujourd'hui, et ce qui est prévu —
 * avec, pour chacun, ce qu'on sait déjà de son fichier, puisque les colonnes ont toutes
 * été relevées.
 */
class Registre
{
    /**
     * Les formats en état de marche.
     *
     * @var array<string, class-string<Format>>
     */
    public const DISPONIBLES = [
        // L'ordre est celui dans lequel il faut importer, et il suit les dépendances
        // mesurées : le parc fonde la fiche de réception, que le devis et la facture citent
        // tous les deux. Importer les factures avant le parc les priverait de leur ville.
        'parc' => FormatDuParc::class,
        'devis' => FormatDesDevis::class,
        'factures' => FormatDesFactures::class,
        // Les impayés viennent après les factures, et pas avant : ils apportent les
        // règlements, or un règlement n'a de sens qu'en regard d'une facture.
        'impayes' => FormatDesImpayes::class,
        'fournisseurs' => FormatDesFournisseurs::class,
        'caisse' => FormatDeLaCaisse::class,
        // Les entrées et les sorties viennent en dernier : elles enrichissent des fiches
        // que le parc a déjà posées, elles ne les fondent pas.
        'entrees' => FormatDesEntrees::class,
        'sorties' => FormatDesSorties::class,
    ];

    /**
     * Ce qui reste à écrire.
     *
     * **Vide, et c'est le but atteint.** Les huit types de fichiers du logiciel sont
     * désormais lus. Cette liste reste en place parce qu'elle a une fonction : elle dit
     * honnêtement ce qui manque plutôt que de laisser croire que tout est couvert. Le jour
     * où un nouveau fichier apparaît, il s'y déclare avant d'être écrit.
     *
     * @var array<string, array{libelle: string, source: string, note: string}>
     */
    public const ANNONCES = [];

    public static function connait(string $cle): bool
    {
        return isset(self::DISPONIBLES[$cle]);
    }

    /** @return class-string<Format> */
    public static function classe(string $cle): string
    {
        if (! self::connait($cle)) {
            throw new \InvalidArgumentException("Le format « {$cle} » n'est pas encore pris en charge.");
        }

        return self::DISPONIBLES[$cle];
    }

    /** Les formats importables, prêts pour une liste déroulante. */
    public static function options(): array
    {
        $options = [];

        foreach (self::DISPONIBLES as $cle => $classe) {
            $options[$cle] = $classe::libelle();
        }

        return $options;
    }

    public static function libelle(string $cle): string
    {
        if (self::connait($cle)) {
            return self::DISPONIBLES[$cle]::libelle();
        }

        return self::ANNONCES[$cle]['libelle'] ?? $cle;
    }
}

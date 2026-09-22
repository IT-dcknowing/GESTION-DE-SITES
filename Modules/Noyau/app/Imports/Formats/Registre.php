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
        // Les deux exports du logiciel comptable viennent après le classeur tenu à la
        // main, et ne s'y mêlent pas : celui-là dit ce que l'atelier croit devoir, ceux-ci
        // ce que la comptabilité a enregistré. C'est l'écart entre les deux qu'on cherche
        // quand un fournisseur réclame.
        'balance-fournisseurs' => FormatDeLaBalanceFournisseur::class,
        'reglements-fournisseurs' => FormatDesReglementsFournisseurs::class,
        'caisse' => FormatDeLaCaisse::class,
        // Le journal de caisse imprimé vient juste après le classeur tenu à la main : ils
        // décrivent la même caisse par deux bouts, et ce sont deux villes différentes qui
        // en dépendent — Abidjan tient un classeur, Bouaké et San-Pédro n'ont que ce
        // journal. Seul format lu dans un PDF, et c'est assumé : le logiciel comptable ne
        // sort pas cet état autrement.
        'journal-caisse' => FormatDuJournalDeCaisse::class,
        // Les entrées et les sorties viennent en dernier : elles enrichissent des fiches
        // que le parc a déjà posées, elles ne les fondent pas.
        'entrees' => FormatDesEntrees::class,
        'sorties' => FormatDesSorties::class,
    ];

    /**
     * Ce qui reste à écrire.
     *
     * **Vide, et c'est le but atteint.** Les dix types de fichiers sont désormais lus — les
     * huit du logiciel d'atelier, plus la balance et les règlements fournisseurs exportés du
     * logiciel comptable, écrits le 21/09/2026. Cette liste reste en place parce qu'elle a une fonction : elle dit
     * honnêtement ce qui manque plutôt que de laisser croire que tout est couvert. Le jour
     * où un nouveau fichier apparaît, il s'y déclare avant d'être écrit.
     *
     * @var array<string, array{libelle: string, source: string, note: string}>
     */
    public const ANNONCES = [];

    /**
     * Les fichiers qu'on a décidé de **ne pas** lire, et pourquoi.
     *
     * **Une troisième catégorie, et elle manquait.** Le registre disait ce qu'on sait lire
     * et ce qu'on saura lire ; il ne disait rien de ce qu'on a regardé puis écarté. Celui
     * qui tient un export de fiches de réception vient sur l'écran de dépôt, ne le trouve
     * nulle part, et n'a aucun moyen de savoir si c'est un oubli ou une décision. Il
     * redemande, ou pire, il attend.
     *
     * Une décision qui ne se lit nulle part se reprend tous les trois mois.
     */
    public const ECARTES = [
        'fiches-reception' => [
            'libelle' => 'Fiches de réception — non repris, et c’est voulu',
            'source' => 'Logiciel d’atelier — « Liste des fiches de réception »',
            'pourquoi' => 'La situation du parc porte les mêmes fiches avec davantage de colonnes : '
                .'dix-sept, dont les travaux à effectuer, le statut et les sept dates. Deux tableaux '
                .'pour la même chose seraient deux vérités à tenir d’accord. Seul le code client '
                .'manque au parc — décidé le 18/09 : on ne l’attend pas. L’obtenir suppose que '
                .'l’éditeur l’ajoute ou ouvre ses API ; d’ici là le rapprochement se fait par le nom '
                .'ramené à une forme comparable et, pour un véhicule, par l’immatriculation. Mesuré '
                .'le 24/09 : le code client n’est renseigné que sur 2 377 des 11 332 factures — il '
                .'ne tiendrait donc pas lieu de clé.',
        ],
    ];

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

        return self::ANNONCES[$cle]['libelle']
            ?? self::ECARTES[$cle]['libelle']
            ?? $cle;
    }
}

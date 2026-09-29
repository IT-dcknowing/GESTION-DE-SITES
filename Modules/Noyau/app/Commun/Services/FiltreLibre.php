<?php

namespace Modules\Noyau\Commun\Services;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Filtrer sur les colonnes qu'aucun filtre ne couvre.
 *
 * **La demande, du 28/09** : « pour toutes ces pages, en plus des filtres disponibles,
 * fais un filtre spécial pour les colonnes présentes […] un bouton "autre filtre" ; dans ce
 * bouton on doit avoir une liste déroulante de toutes les colonnes manquantes de leur
 * tableau, donc on devra sélectionner ; si la colonne est sélectionnée, un ou deux champs
 * devront s'ouvrir selon le type de données : pour les données à valeur fixe une liste
 * déroulante, pour les données à recherche un champ, mais pour les données à date deux
 * champs — cela servira d'intervalle. »
 *
 * **Le manque que cela comble.** Chaque écran offre trois ou quatre filtres, ceux dont on
 * se sert tous les jours : la période, la ville, l'activité, une recherche. Les tableaux,
 * eux, portent quinze à vingt-cinq colonnes. Les autres ne se filtrent pas — on exporte, on
 * ouvre le classeur, et l'on filtre ailleurs. Poser un filtre par colonne rendrait les
 * écrans illisibles ; n'en poser aucun oblige à sortir de l'application pour une question
 * qu'elle pourrait répondre.
 *
 * **Trois types, et c'est la forme du champ qui change.** C'est la distinction que le
 * propriétaire a posée, et elle est juste :
 *
 *   - `liste` — la colonne ne prend qu'un petit nombre de valeurs (une activité, un
 *     statut, un mode de règlement). Une liste déroulante : taper « Mécanique » avec une
 *     faute ne trouverait rien, et l'on croirait qu'il n'y a pas de lignes.
 *   - `texte` — un nom, une plaque, un numéro. Un champ, et une recherche qui contient.
 *   - `date` — **deux** champs, parce qu'on cherche presque toujours une tranche, et
 *     qu'un seul obligerait à connaître le jour exact.
 *   - `nombre` — deux champs également, pour la même raison : « entre 100 000 et
 *     500 000 » est la question qu'on pose, pas « exactement 234 512 ».
 *
 * **Ce que ce service ne fait pas** : deviner les colonnes. Chaque écran déclare les
 * siennes, avec leur type et, pour une liste, ses valeurs. Deviner depuis le schéma
 * donnerait des filtres sur `id`, `created_at` et `lot_import_id` — du bruit sur lequel on
 * ne cherche jamais, au milieu duquel on ne trouverait plus les trois qui comptent.
 */
class FiltreLibre
{
    /** Les types reconnus, et le nombre de champs que chacun ouvre. */
    public const TYPES = [
        'liste' => 1,
        'texte' => 1,
        'date' => 2,
        'nombre' => 2,
    ];

    /**
     * Déclare une colonne filtrable.
     *
     * Une fabrique plutôt qu'un tableau écrit à la main : les écrans en déclarent dix ou
     * vingt, et une clé mal orthographiée dans l'un d'eux produirait un filtre muet —
     * sans erreur, sans rien à l'écran, et l'on chercherait la panne ailleurs.
     *
     * **`$videEstNull` n'est pas un réglage de confort.** « — non renseigné — » se traduit
     * d'ordinaire par « la colonne est nulle **ou** vide », parce qu'un import laisse aussi
     * bien l'une que l'autre. Sur une colonne booléenne ou numérique, ce « ou vide » est un
     * piège : MySQL compare `''` à `0`, si bien que demander les fiches dont l'assujettissement
     * à la TVA n'est pas renseigné rendrait **aussi toutes les non-assujetties**. On aurait lu
     * une liste de fiches à compléter dont la plupart n'avaient rien à compléter. Sur ces
     * colonnes-là, vide veut dire nul, et rien d'autre.
     *
     * @param  string  $colonne  la colonne en base, préfixée de sa table si besoin
     * @param  array<int|string, string>  $options  pour un type `liste` : valeur => libellé
     * @return array{libelle: string, type: string, options: array<int|string, string>, videEstNull: bool}
     */
    public static function colonne(string $libelle, string $type = 'texte', array $options = [], bool $videEstNull = false): array
    {
        return [
            'libelle' => $libelle,
            'type' => array_key_exists($type, self::TYPES) ? $type : 'texte',
            'options' => $options,
            'videEstNull' => $videEstNull,
        ];
    }

    /**
     * Le nom sous lequel une colonne vit dans l'état Livewire.
     *
     * **Le défaut que cela répare, relevé le 28/09 : « le filtre ne marche pas ».** Il ne
     * marchait pas, et la cause était invisible à la lecture du code.
     *
     * Les colonnes sont nommées `devis.client`, `factures.banque` — table et colonne, pour
     * qu'une jointure ne rende pas la condition ambiguë. Or **Livewire lit le point comme
     * un séparateur de chemin** : `wire:model="filtresLibres.devis.client.valeur"` écrit
     * dans `filtresLibres['devis']['client']['valeur']` — une structure à trois étages —
     * et non dans `filtresLibres['devis.client']['valeur']`, que le serveur allait chercher.
     * La valeur arrivait donc bien au serveur, rangée là où personne ne la lisait : aucune
     * erreur, aucun message, et un filtre qui ne filtre rien.
     *
     * Le point devient donc un double blanc souligné dans l'état, et nulle part ailleurs :
     * la requête, elle, continue de voir le vrai nom.
     */
    public static function alias(string $colonne): string
    {
        return str_replace('.', '__', $colonne);
    }

    /**
     * Applique les filtres retenus à une requête.
     *
     * **Rien n'est appliqué sur une colonne non déclarée.** On parcourt la **déclaration**
     * et non ce qui arrive du navigateur : l'état Livewire pourrait porter n'importe quelle
     * clé, et s'en servir laisserait composer une condition sur n'importe quelle colonne de
     * la table — `mot_de_passe`, `entreprise_id`. La déclaration de l'écran est la seule
     * liste autorisée, et c'est elle qui mène la boucle.
     *
     * @param  array<string, array{libelle: string, type: string, options: array}>  $declarees
     * @param  array<string, array{de?: string, a?: string, valeur?: string}>  $retenus
     */
    public static function appliquer(Builder $requete, array $declarees, array $retenus): Builder
    {
        foreach ($declarees as $colonne => $declaree) {
            $valeurs = $retenus[self::alias($colonne)] ?? $retenus[$colonne] ?? null;

            if (! is_array($valeurs)) {
                continue;
            }

            $videEstNull = (bool) ($declaree['videEstNull'] ?? false);

            match ($declaree['type']) {
                'liste' => self::appliquerListe($requete, $colonne, $valeurs, $videEstNull),
                'date' => self::appliquerIntervalle($requete, $colonne, $valeurs, 'date'),
                'nombre' => self::appliquerIntervalle($requete, $colonne, $valeurs, 'nombre'),
                default => self::appliquerTexte($requete, $colonne, $valeurs, $videEstNull),
            };
        }

        return $requete;
    }

    /**
     * Le même filtre, sur un tableau que la base n'a pas construit.
     *
     * **Pourquoi une seconde écriture, et non un seul chemin.** Cinq écrans ne listent pas
     * des lignes de table : ils rapprochent. L'annuaire des fournisseurs réunit les fiches
     * déclarées et les fournisseurs que seules les pièces connaissent ; le classement des
     * commerciaux croise un chiffre d'affaires, un objectif et un barème ; les deux écrans de
     * rapprochement comparent deux populations plaque par plaque. Leurs colonnes — « pièces »,
     * « atteinte de l'objectif », « écart » — **n'existent dans aucune table** : elles sont
     * calculées en mémoire, ligne par ligne. Une condition SQL n'a rien sur quoi se poser.
     *
     * Le propriétaire a demandé le bouton « Autre filtre » sur ces écrans-là comme sur les
     * autres, et il a raison de ne pas voir la différence : elle est dans notre code, pas dans
     * ce qu'il regarde. Les deux chemins partagent donc la déclaration, les quatre types, le
     * composant et le compteur ; seule la mise en œuvre change.
     *
     * **Ce qu'on accepte en le faisant** : ces écrans chargent déjà tout pour agréger, et le
     * filtre ne fait que réduire ce qui est en main. Il n'y a rien de plus à lire.
     *
     * Les colonnes se déclarent ici **sans préfixe de table** : ce n'est plus une colonne
     * qu'on nomme, c'est la clé de la ligne — `pieces`, `reste`, `nom`.
     *
     * @param  Collection<int, mixed>  $lignes
     * @param  array<string, array{libelle: string, type: string, options: array}>  $declarees
     * @param  array<string, array{de?: string, a?: string, valeur?: string}>  $retenus
     * @return Collection<int, mixed>
     */
    public static function filtrerCollection(Collection $lignes, array $declarees, array $retenus): Collection
    {
        foreach ($declarees as $champ => $declaree) {
            $valeurs = $retenus[self::alias($champ)] ?? $retenus[$champ] ?? null;

            if (! is_array($valeurs) || ! self::estRempli($valeurs)) {
                continue;
            }

            $lignes = $lignes->filter(fn ($ligne) => self::ligneRetenue(
                data_get($ligne, $champ), $declaree, $valeurs,
            ));
        }

        return $lignes->values();
    }

    /**
     * Une ligne passe-t-elle la condition posée sur un de ses champs ?
     *
     * Séparée du parcours pour être lisible seule : c'est ici que se joue la comparaison,
     * et c'est ici qu'une erreur serait invisible.
     */
    private static function ligneRetenue(mixed $lu, array $declaree, array $valeurs): bool
    {
        $type = $declaree['type'];

        if (in_array($type, ['liste', 'texte'], true)) {
            $cherche = trim((string) ($valeurs['valeur'] ?? ''));

            if ($cherche === '') {
                return true;
            }

            // Un booléen se lit « 1 » / « 0 » dans une liste déroulante, comme en base.
            $valeur = is_bool($lu) ? ($lu ? '1' : '0') : (string) ($lu ?? '');

            if ($cherche === '__vide__') {
                return $lu === null || $valeur === '';
            }

            // La liste compare à l'identique — ses valeurs viennent d'elle-même. Le texte
            // contient, et sans se soucier de la casse : personne ne tape « CFAO » comme le
            // classeur l'écrit.
            return $type === 'liste'
                ? $valeur === $cherche
                : mb_stripos($valeur, $cherche) !== false;
        }

        $de = trim((string) ($valeurs['de'] ?? ''));
        $a = trim((string) ($valeurs['a'] ?? ''));

        if ($type === 'date') {
            $date = self::enDate($lu);

            // Une ligne sans date ne peut pas être dans une tranche de dates. La garder
            // ferait croire qu'elle y tombe ; c'est le contraire qu'il faut voir.
            if ($date === null) {
                return false;
            }

            return ($de === '' || $date->startOfDay()->gte(Carbon::parse($de)->startOfDay()))
                && ($a === '' || $date->startOfDay()->lte(Carbon::parse($a)->startOfDay()));
        }

        if (! is_numeric($lu) && ! is_bool($lu)) {
            return false;
        }

        $nombre = (float) $lu;

        return ($de === '' || ! is_numeric($de) || $nombre >= (float) $de)
            && ($a === '' || ! is_numeric($a) || $nombre <= (float) $a);
    }

    /** Une date, quelle que soit la forme sous laquelle la ligne la porte. */
    private static function enDate(mixed $lu): ?Carbon
    {
        if ($lu instanceof \DateTimeInterface) {
            return Carbon::instance($lu);
        }

        if (! is_string($lu) || trim($lu) === '') {
            return null;
        }

        try {
            return Carbon::parse($lu);
        } catch (\Throwable) {
            // Une colonne de classeur porte parfois « à confirmer » à la place d'une date.
            // Ce n'est pas une panne, et cela ne doit pas en devenir une.
            return null;
        }
    }

    /** Combien de filtres sont réellement posés — pour que le bouton le dise. */
    public static function compter(array $declarees, array $retenus): int
    {
        $poses = 0;

        foreach ($declarees as $colonne => $declaree) {
            $valeurs = $retenus[self::alias($colonne)] ?? $retenus[$colonne] ?? null;

            if (is_array($valeurs) && self::estRempli($valeurs)) {
                $poses++;
            }
        }

        return $poses;
    }

    /** Vrai dès qu'une des cases porte quelque chose. */
    public static function estRempli(mixed $valeurs): bool
    {
        foreach ((array) $valeurs as $valeur) {
            if (trim((string) $valeur) !== '') {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ les trois formes

    private static function appliquerListe(Builder $requete, string $colonne, array $valeurs, bool $videEstNull = false): void
    {
        $valeur = trim((string) ($valeurs['valeur'] ?? ''));

        if ($valeur === '') {
            return;
        }

        // « — Non renseigné — » est un choix à part entière, et souvent celui qu'on
        // cherche : les lignes dont la colonne est vide sont celles qu'il faut compléter.
        if ($valeur === '__vide__') {
            self::appliquerVide($requete, $colonne, $videEstNull);

            return;
        }

        $requete->where($colonne, $valeur);
    }

    private static function appliquerTexte(Builder $requete, string $colonne, array $valeurs, bool $videEstNull = false): void
    {
        $valeur = trim((string) ($valeurs['valeur'] ?? ''));

        if ($valeur === '') {
            return;
        }

        if ($valeur === '__vide__') {
            self::appliquerVide($requete, $colonne, $videEstNull);

            return;
        }

        $requete->where($colonne, 'like', '%'.$valeur.'%');
    }

    /** « Rien dedans » : nul, et vide aussi tant que la colonne porte du texte. */
    private static function appliquerVide(Builder $requete, string $colonne, bool $videEstNull): void
    {
        if ($videEstNull) {
            $requete->whereNull($colonne);

            return;
        }

        $requete->where(fn ($q) => $q->whereNull($colonne)->orWhere($colonne, ''));
    }

    /**
     * Une borne, deux bornes, ou aucune.
     *
     * **Les deux sont facultatives, et séparément.** « Depuis le 1er mars » et « jusqu'au
     * 31 mars » sont deux questions réelles ; obliger à remplir les deux ferait taper une
     * date qu'on ne cherche pas, et qui écarterait des lignes sans qu'on l'ait voulu.
     */
    private static function appliquerIntervalle(Builder $requete, string $colonne, array $valeurs, string $type): void
    {
        $de = trim((string) ($valeurs['de'] ?? ''));
        $a = trim((string) ($valeurs['a'] ?? ''));

        if ($de === '' && $a === '') {
            return;
        }

        if ($type === 'date') {
            if ($de !== '') {
                $requete->whereDate($colonne, '>=', $de);
            }

            if ($a !== '') {
                $requete->whereDate($colonne, '<=', $a);
            }

            return;
        }

        if ($de !== '' && is_numeric($de)) {
            $requete->where($colonne, '>=', (float) $de);
        }

        if ($a !== '' && is_numeric($a)) {
            $requete->where($colonne, '<=', (float) $a);
        }
    }
}

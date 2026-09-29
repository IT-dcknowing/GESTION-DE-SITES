<?php

namespace Modules\Noyau\Commun\Services;

use Illuminate\Contracts\Database\Query\Builder;

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
     * @param  string  $colonne  la colonne en base, préfixée de sa table si besoin
     * @param  array<int|string, string>  $options  pour un type `liste` : valeur => libellé
     * @return array{libelle: string, type: string, options: array<int|string, string>}
     */
    public static function colonne(string $libelle, string $type = 'texte', array $options = []): array
    {
        return [
            'libelle' => $libelle,
            'type' => array_key_exists($type, self::TYPES) ? $type : 'texte',
            'options' => $options,
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

            match ($declaree['type']) {
                'liste' => self::appliquerListe($requete, $colonne, $valeurs),
                'date' => self::appliquerIntervalle($requete, $colonne, $valeurs, 'date'),
                'nombre' => self::appliquerIntervalle($requete, $colonne, $valeurs, 'nombre'),
                default => self::appliquerTexte($requete, $colonne, $valeurs),
            };
        }

        return $requete;
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

    private static function appliquerListe(Builder $requete, string $colonne, array $valeurs): void
    {
        $valeur = trim((string) ($valeurs['valeur'] ?? ''));

        if ($valeur === '') {
            return;
        }

        // « — Non renseigné — » est un choix à part entière, et souvent celui qu'on
        // cherche : les lignes dont la colonne est vide sont celles qu'il faut compléter.
        if ($valeur === '__vide__') {
            $requete->where(fn ($q) => $q->whereNull($colonne)->orWhere($colonne, ''));

            return;
        }

        $requete->where($colonne, $valeur);
    }

    private static function appliquerTexte(Builder $requete, string $colonne, array $valeurs): void
    {
        $valeur = trim((string) ($valeurs['valeur'] ?? ''));

        if ($valeur === '') {
            return;
        }

        if ($valeur === '__vide__') {
            $requete->where(fn ($q) => $q->whereNull($colonne)->orWhere($colonne, ''));

            return;
        }

        $requete->where($colonne, 'like', '%'.$valeur.'%');
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

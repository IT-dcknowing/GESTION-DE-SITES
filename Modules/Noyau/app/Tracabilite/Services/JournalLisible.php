<?php

namespace Modules\Noyau\Tracabilite\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Le journal des modifications, écrit pour être lu.
 *
 * **Ce qu'il affichait.** La page de détail d'une créance montrait, dans la colonne
 * « Geste », le mot `updated`, et dans « Avant → après » des lignes comme
 * `ville_id : — → 1` ou `date_reception : — → 2026-08-17T00:00:00.000000Z`. Tout y était
 * exact et rien n'y était compréhensible : personne ne sait ce qu'est la ville numéro 1, et
 * une date au format machine se lit à l'envers du reste de l'application.
 *
 * Un journal sert à répondre à « qui a changé quoi, et quand ». S'il faut connaître les
 * noms de colonnes de la base pour le lire, il ne répond qu'à ceux qui n'en ont pas besoin.
 *
 * **Trois traductions, et rien de plus.** Le geste (`created` → « Créée »), le nom du champ
 * (`date_reception` → « Date de réception »), et la valeur — une date au format d'ici, un
 * booléen en oui/non, un vide en « vide », et surtout **un identifiant de ville, d'atelier,
 * de commercial ou de compte remplacé par son nom**. Les noms sont lus en une requête par
 * table pour toute la page : une par ligne en ferait trente sur un historique de trente
 * gestes.
 *
 * **La création n'est pas une modification.** Corrigé le 24/09 à la demande du
 * propriétaire, et la remarque est juste : afficher « Ville : vide → Abidjan » sur la ligne
 * de création laisse croire que quelqu'un a remplacé un vide par Abidjan, alors que
 * personne n'a rien remplacé — la fiche vient de naître avec cette valeur. Une création
 * n'a pas d'avant : **c'est elle, l'avant.** Elle se lit donc comme un état posé,
 * « Ville : Abidjan », sans flèche et sans vide. Les modifications qui suivent se lisent,
 * elles, en avant → après : ce sont les retouches de cette création.
 *
 * **Ce qui n'est pas traduit reste tel quel.** Un champ inconnu s'affiche sous son nom de
 * colonne, mis en forme mais non traduit : inventer un libellé pour un champ qu'on n'a pas
 * prévu serait pire que de montrer le nom technique, parce qu'on ne saurait plus lequel
 * c'est.
 */
class JournalLisible
{
    /** Les gestes du journal, dans les mots de l'application. */
    private const GESTES = [
        'created' => 'Créée',
        'updated' => 'Modifiée',
        'deleted' => 'Supprimée',
        'restored' => 'Rétablie',
    ];

    /**
     * Les colonnes dont le nom ne se devine pas.
     *
     * La liste est volontairement courte : elle ne couvre que ce qui apparaît réellement
     * dans les journaux des écrans qui l'utilisent. Une liste exhaustive serait une seconde
     * description de la base, à tenir à jour pour rien.
     */
    private const CHAMPS = [
        'ville_id' => 'Ville',
        'site_id' => 'Atelier',
        'user_id' => 'Compte',
        'commercial_id' => 'Commercial',
        'lot_import_id' => 'Fichier d’origine',
        'date_reception' => 'Date de réception',
        'date_facture' => 'Date de facture',
        'date_echeance' => 'Échéance',
        'date_reglement' => 'Date de règlement',
        'depose_chez' => 'Déposée chez',
        'montant_regle' => 'Montant réglé',
        'reste_a_payer' => 'Reste à payer',
        'numero_piece' => 'N° de pièce',
        'numero_fiche' => 'N° de fiche',
        'n_sinistre' => 'N° de sinistre',
        'n_facture' => 'N° de facture',
        'exercice_impayes' => 'Année de l’état',
        'est_etat_initial' => 'Ligne d’état initial',
        'code_agent' => 'Code employé',
        'rattachement_presume' => 'Rattachement présumé',
        'observations' => 'Observations',
        'immatriculation' => 'Immatriculation',
        'vehicule' => 'Véhicule',
        'activite' => 'Activité',
        'mode_reglement' => 'Mode de règlement',
        'nature_piece' => 'Nature de la pièce',
        'numero_facture_client' => 'N° de facture client',
    ];

    /**
     * Les champs qui portent un identifiant, et où lire le nom qui va avec.
     *
     * `commercial_id` y entre le 24/09 : la page de détail d'une créance affichait
     * « Commercial id : 41 », ce qui ne désigne personne pour qui lit le journal.
     *
     * @var array<string, array{0: string, 1: string}> champ => [table, colonne du nom]
     */
    private const REFERENCES = [
        'ville_id' => ['villes', 'nom'],
        'site_id' => ['sites', 'nom'],
        'commercial_id' => ['commerciaux', 'nom'],
        // Les comptes portent `name` et non `nom` : c'est la table de Laravel.
        'user_id' => ['users', 'name'],
    ];

    /** Le geste, dit en français — ou la description quand elle est déjà écrite pour nous. */
    public static function geste(Activity $trace): string
    {
        $description = trim((string) $trace->description);

        // Les écrans qui décrivent eux-mêmes leur geste — « État des impayés — créance
        // modifiée » — l'ont déjà écrit pour un lecteur : on ne le récrit pas.
        if ($description !== '' && ! isset(self::GESTES[$description])) {
            return $description;
        }

        return self::GESTES[$description] ?? self::GESTES[(string) $trace->event] ?? $description;
    }

    /**
     * Une trace de naissance, par opposition à une retouche.
     *
     * L'événement Eloquent tranche quand il est là. Sinon — un journal posé à la main par
     * un écran, sans événement — l'absence d'« avant » dit la même chose : on ne remplace
     * rien puisqu'il n'y avait rien.
     */
    public static function estUneCreation(Activity $trace): bool
    {
        $evenement = (string) $trace->event;

        if ($evenement !== '') {
            return $evenement === 'created';
        }

        return ($trace->properties['old'] ?? []) === [];
    }

    /**
     * Les changements d'une trace, champ par champ, prêts à lire.
     *
     * Une création rend des lignes `pose = true` : la valeur est un état posé, et non le
     * remplacement d'un vide, et l'écran l'affiche sans flèche. Les champs laissés vides à
     * la création ne sont pas rendus — une naissance n'a pas à énumérer ce qu'elle n'a pas.
     *
     * @param  array<string, string>  $noms  identifiant => nom, rendu par {@see noms()}
     * @return list<array{champ: string, avant: string, apres: string, pose: bool}>
     */
    public static function changements(Activity $trace, array $noms = []): array
    {
        $avant = $trace->properties['old'] ?? [];
        $apres = $trace->properties['attributes'] ?? [];
        $changements = [];

        if (self::estUneCreation($trace)) {
            foreach ($apres as $champ => $valeur) {
                if ($valeur === null || $valeur === '') {
                    continue;
                }

                $changements[] = [
                    'champ' => self::champ($champ),
                    'avant' => '',
                    'apres' => self::valeur($champ, $valeur, $noms),
                    'pose' => true,
                ];
            }

            return $changements;
        }

        // L'union des deux jeux : un champ ajouté n'apparaît que dans « après », un champ
        // vidé que dans « avant », et ni l'un ni l'autre ne doit se perdre.
        foreach (array_keys($apres + $avant) as $champ) {
            $valeurAvant = $avant[$champ] ?? null;
            $valeurApres = $apres[$champ] ?? null;

            if ($valeurAvant === $valeurApres) {
                continue;
            }

            $changements[] = [
                'champ' => self::champ($champ),
                'avant' => self::valeur($champ, $valeurAvant, $noms),
                'apres' => self::valeur($champ, $valeurApres, $noms),
                'pose' => false,
            ];
        }

        return $changements;
    }

    /** Le nom d'un champ, tel qu'on le dit à l'écran. */
    public static function champ(string $colonne): string
    {
        if (isset(self::CHAMPS[$colonne])) {
            return self::CHAMPS[$colonne];
        }

        // Faute de traduction, la colonne s'affiche lisiblement sans être renommée : on
        // doit pouvoir la retrouver dans la base à partir de ce qu'on lit.
        return Str::ucfirst(str_replace('_', ' ', $colonne));
    }

    /**
     * Une valeur, dans les mots de l'application.
     *
     * @param  array<string, string>  $noms
     */
    public static function valeur(string $champ, mixed $valeur, array $noms = []): string
    {
        if ($valeur === null || $valeur === '') {
            return 'vide';
        }

        if (is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }

        // Un identifiant ne dit rien à personne : « 1 » devient « Abidjan », « 41 » devient
        // le nom du commercial. Introuvable, il reste affiché — mieux vaut un numéro qu'un
        // blanc, parce qu'on peut encore aller le chercher.
        if (isset(self::REFERENCES[$champ])) {
            return $noms[$champ.':'.$valeur] ?? '#'.$valeur;
        }

        if ($valeur instanceof DateTimeInterface) {
            return $valeur->format('d/m/Y');
        }

        // Les dates arrivent du journal en chaîne ISO : on les rend au format d'ici plutôt
        // que de laisser lire « 2026-08-17T00:00:00.000000Z » au milieu d'une page en
        // français.
        if (is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}([T ]|$)/', $valeur) === 1) {
            try {
                return Carbon::parse($valeur)->format('d/m/Y');
            } catch (\Throwable) {
                return $valeur;
            }
        }

        if (is_array($valeur)) {
            return Str::limit(json_encode($valeur, JSON_UNESCAPED_UNICODE), 60);
        }

        return Str::limit((string) $valeur, 60);
    }

    /**
     * Les noms derrière les identifiants cités par ces traces, en une requête par table.
     *
     * @param  iterable<Activity>  $traces
     * @return array<string, string>
     */
    public static function noms(iterable $traces): array
    {
        $paniers = [];

        foreach ($traces as $trace) {
            foreach ([$trace->properties['old'] ?? [], $trace->properties['attributes'] ?? []] as $jeu) {
                foreach (array_keys(self::REFERENCES) as $champ) {
                    $valeur = $jeu[$champ] ?? null;

                    if (is_numeric($valeur)) {
                        $paniers[$champ][(int) $valeur] = true;
                    }
                }
            }
        }

        $noms = [];

        foreach ($paniers as $champ => $ids) {
            [$table, $colonne] = self::REFERENCES[$champ];

            foreach (DB::table($table)->whereIn('id', array_keys($ids))->pluck($colonne, 'id') as $id => $nom) {
                $noms[$champ.':'.$id] = (string) $nom;
            }
        }

        return $noms;
    }
}

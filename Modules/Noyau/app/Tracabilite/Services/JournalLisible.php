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
 * booléen en oui/non, un vide en « vide », et surtout **un identifiant de ville ou
 * d'atelier remplacé par son nom**. Les noms sont lus en une requête pour toute la page :
 * une par ligne en ferait trente sur un historique de trente gestes.
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
        'mode_reglement' => 'Mode de règlement',
        'nature_piece' => 'Nature de la pièce',
        'numero_facture_client' => 'N° de facture client',
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
     * Les changements d'une trace, champ par champ, prêts à lire.
     *
     * @param  array<string, string>  $noms  identifiant => nom, rendu par {@see nomsDesLieux()}
     * @return list<array{champ: string, avant: string, apres: string}>
     */
    public static function changements(Activity $trace, array $noms = []): array
    {
        $avant = $trace->properties['old'] ?? [];
        $apres = $trace->properties['attributes'] ?? [];

        // À la création, il n'y a pas d'« avant » : ce sont les valeurs posées qu'on montre.
        $champs = $avant === [] ? array_keys($apres) : array_keys($avant);
        $changements = [];

        foreach ($champs as $champ) {
            $valeurAvant = $avant[$champ] ?? null;
            $valeurApres = $apres[$champ] ?? null;

            if ($valeurAvant === $valeurApres) {
                continue;
            }

            $changements[] = [
                'champ' => self::champ($champ),
                'avant' => self::valeur($champ, $valeurAvant, $noms),
                'apres' => self::valeur($champ, $valeurApres, $noms),
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

        // Un identifiant de ville ou d'atelier ne dit rien à personne : « 1 » devient
        // « Abidjan ». Introuvable, il reste affiché — mieux vaut un numéro qu'un blanc.
        if ($champ === 'ville_id' || $champ === 'site_id') {
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
     * Les noms des villes et des ateliers cités par ces traces, en une requête chacune.
     *
     * @param  iterable<Activity>  $traces
     * @return array<string, string>
     */
    public static function nomsDesLieux(iterable $traces): array
    {
        $villes = [];
        $sites = [];

        foreach ($traces as $trace) {
            foreach ([$trace->properties['old'] ?? [], $trace->properties['attributes'] ?? []] as $jeu) {
                foreach (['ville_id' => &$villes, 'site_id' => &$sites] as $champ => &$panier) {
                    $valeur = $jeu[$champ] ?? null;

                    if (is_numeric($valeur)) {
                        $panier[(int) $valeur] = true;
                    }
                }
                unset($panier);
            }
        }

        $noms = [];

        foreach ([['ville_id', 'villes', $villes], ['site_id', 'sites', $sites]] as [$champ, $table, $ids]) {
            if ($ids === []) {
                continue;
            }

            foreach (DB::table($table)->whereIn('id', array_keys($ids))->pluck('nom', 'id') as $id => $nom) {
                $noms[$champ.':'.$id] = (string) $nom;
            }
        }

        return $noms;
    }
}

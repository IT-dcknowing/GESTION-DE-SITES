<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Services\CommercialDeLaFiche;

/**
 * « Situation du parc » — les fiches de réception.
 *
 * Le premier import de la chaîne, et le seul qui n'ait besoin d'aucun autre. Dix-sept
 * colonnes, **rigoureusement identiques dans les trois villes** : on l'a vérifié fichier en
 * main, même ordre, même orthographe, une seule feuille nommée « A ». La réponse à la
 * question posée plus tôt est donc oui pour ce format-là — les colonnes ne diffèrent pas
 * d'une ville à l'autre.
 *
 * **Ce qui diffère, c'est ce qui manque : il n'y a pas de colonne SITE.** Rien dans ces
 * fichiers ne dit de quelle ville vient une ligne, sauf le code de deux lettres du numéro
 * de fiche. C'est le seul des quatre grands formats dans ce cas, et c'est ce qui rend le
 * référentiel des codes agents indispensable plutôt que confortable.
 *
 * Trois mesures faites sur les 3 179 fiches des trois exports, qui expliquent les choix
 * ci-dessous :
 *
 * - **Aucun numéro de fiche n'apparaît dans deux villes** — les trois fichiers sont
 *   parfaitement disjoints. Le numéro est donc une vraie clé, contrairement aux devis, où
 *   216 des 217 proformas « San Pédro » se retrouvent dans le fichier « Abidjan ».
 * - **Cinq statuts et quatre motifs**, et pas un de plus. Ce sont des listes fermées.
 * - **Une seule ligne abîmée**, où un texte de travaux sur plusieurs lignes a débordé sur
 *   les colonnes voisines et poussé le statut hors de sa case. Elle est rejetée, avec ses
 *   valeurs, pour qu'on répare le fichier.
 */
class FormatDuParc extends Format
{
    private ?string $dernierNumero = null;

    private ?DossierVehicule $dernierDossier = null;

    /** @var array<string, true>|null les numéros déjà en base, chargés une seule fois */
    private ?array $numerosConnus = null;

    /**
     * La lecture du commercial nommé dans la colonne libre, instanciée une fois par import.
     *
     * Elle porte le référentiel des commerciaux en mémoire : le relire à chaque ligne
     * ferait deux mille requêtes pour onze noms.
     */
    private ?CommercialDeLaFiche $lectureDuCommercial = null;

    public static function cle(): string
    {
        return 'parc';
    }

    public static function libelle(): string
    {
        return 'Situation du parc — fiches de réception';
    }

    public static function colonnes(): array
    {
        return [
            'date_fiche' => 'DATE DE LA FICHE',
            'date_fin_prevue' => 'DATE FIN PREVUE',
            'date_theorique_atelier' => 'DATE THEORIQUE ATELIER',
            'numero_fiche' => 'N° FICHE RECEPTION',
            'immatriculation' => 'IMMAT. VEHICULE',
            'marque' => 'MARQUE',
            'modele' => 'MODELE',
            'client' => 'CLIENTS / ASSURANCES',
            'proprietaire' => 'PROPRIETAIRES',
            'motif' => 'MOTIF DE LA VENUE',
            'travaux' => 'TRAVAUX A EFFECTUER',
            'statut' => 'STATUT',
            'informations' => 'INFORMATIONS SUR LA SITUATION',
            'date_transmission_devis' => 'DATE TRANSMISSION DEVIS',
            'date_traitement_feb' => 'DATE TRAITEMENT FEB',
            'date_effective_travaux' => 'DATE EFFECTIVE TRAVAUX',
            'date_fin_travaux' => 'DATE FIN TRAVAUX',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['numero_fiche'];
    }

    protected function reference(array $ligne): ?string
    {
        return self::texte($ligne['numero_fiche'] ?? null, 40);
    }

    /** Ce fichier n'a pas de colonne SITE — c'est tout le problème qu'il pose. */
    protected function colonneSite(array $ligne): ?string
    {
        return null;
    }

    /**
     * Refuse ce qui n'a pas de numéro, et ce dont le statut a manifestement glissé.
     *
     * Le contrôle du statut n'est pas de la coquetterie : c'est lui qui attrape la ligne où
     * le texte des travaux a débordé de sa colonne. Sans lui, on rangerait « Essuie-glace à
     * remplacer » dans la case statut et l'anomalie deviendrait invisible.
     */
    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        $numero = self::texte($ligne['numero_fiche'] ?? null, 40);

        // Un en-tête répété au milieu du fichier ne doit pas devenir une fiche.
        if ($numero !== null && mb_strtoupper($numero) === 'N° FICHE RECEPTION') {
            return 'Ligne d\'en-tête répétée.';
        }

        $statut = self::texte($ligne['statut'] ?? null, 80);

        if ($statut !== null && ! in_array(mb_strtoupper($statut), DossierVehicule::STATUTS, true)) {
            return 'Le statut « '.mb_substr($statut, 0, 40).' » n\'est pas un statut connu : '
                .'les colonnes de cette ligne semblent décalées.';
        }

        return null;
    }

    /** Une fiche déjà importée garde sa ville : elle n'a pas déménagé entre deux dépôts. */
    protected function dossierExistant(array $ligne): array
    {
        $dossier = $this->dossierDe(self::texte($ligne['numero_fiche'] ?? null, 40));

        return [
            'site_id' => $dossier?->site_id,
            'presume' => (bool) $dossier?->rattachement_presume,
        ];
    }

    /**
     * La fiche déjà en base, si elle existe.
     *
     * Le résultat est retenu le temps d'une ligne parce qu'il est demandé deux fois de
     * suite — une fois pour connaître le site établi, une fois pour écrire. Interroger la
     * base deux fois pour la même fiche double la durée de l'import sans rien apporter.
     */
    private function dossierDe(?string $numero): ?DossierVehicule
    {
        if ($numero === null) {
            return null;
        }

        // La liste des numéros déjà connus est chargée d'un bloc, une seule fois. Sans
        // elle, un premier import interroge la base une fois par ligne pour s'entendre
        // répondre « rien » à chaque fois : sur les 2 204 fiches d'Abidjan, ces recherches
        // vaines représentaient l'essentiel de la durée d'une simulation.
        $this->numerosConnus ??= array_fill_keys(
            DossierVehicule::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->pluck('numero_fiche')
                ->all(),
            true,
        );

        if (! isset($this->numerosConnus[$numero])) {
            return null;
        }

        if ($numero !== $this->dernierNumero) {
            $this->dernierNumero = $numero;
            $this->dernierDossier = DossierVehicule::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('numero_fiche', $numero)
                ->first();
        }

        return $this->dernierDossier;
    }

    private function lectureDuCommercial(): CommercialDeLaFiche
    {
        return $this->lectureDuCommercial ??= new CommercialDeLaFiche($this->entrepriseId);
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $numero = self::texte($ligne['numero_fiche'] ?? null, 40);

        $valeurs = [
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'code_agent' => $rattachement['code'],
            'source_rattachement' => $rattachement['source'],
            'rattachement_presume' => $rattachement['presumee'],

            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 40),
            'marque' => self::texte($ligne['marque'] ?? null, 60),
            'modele' => self::texte($ligne['modele'] ?? null, 60),
            'client' => self::texte($ligne['client'] ?? null, 160),
            'proprietaire' => self::texte($ligne['proprietaire'] ?? null, 160),
            'motif' => self::texte($ligne['motif'] ?? null, 60),
            'statut' => self::texte($ligne['statut'] ?? null, 80),
            'travaux' => self::texte($ligne['travaux'] ?? null, 4000),
            'informations' => self::texte($ligne['informations'] ?? null, 4000),

            'date_fiche' => self::date($ligne['date_fiche'] ?? null),
            'date_fin_prevue' => self::date($ligne['date_fin_prevue'] ?? null),
            'date_theorique_atelier' => self::date($ligne['date_theorique_atelier'] ?? null),
            'date_transmission_devis' => self::date($ligne['date_transmission_devis'] ?? null),
            'date_traitement_feb' => self::date($ligne['date_traitement_feb'] ?? null),
            'date_effective_travaux' => self::date($ligne['date_effective_travaux'] ?? null),
            'date_fin_travaux' => self::date($ligne['date_fin_travaux'] ?? null),
        ];

        /*
         * Le commercial nommé en tête de la colonne libre, quand il y en a un.
         *
         * **Ce que cela relie.** Un devis du logiciel d'atelier cite son numéro de fiche ;
         * la fiche, elle, peut nommer le commercial qui a décroché l'affaire. C'est le
         * chemin arbitré avec la direction pour rattacher les devis importés aux
         * prospections, sans demander une colonne de plus au logiciel d'atelier.
         *
         * **Rien n'est deviné.** Un nom qui ne correspond pas exactement au référentiel
         * n'est pas rapproché : il est consigné comme une question, posée sur l'écran des
         * traitements, et la réponse vaut pour tous les dépôts suivants. Ce qui a été lu
         * reste écrit dans `commercial_saisi` — le fichier reçu ne se réécrit jamais.
         */
        $lu = $this->lectureDuCommercial()->lire($valeurs['informations']);

        $valeurs['commercial_saisi'] = $lu['saisi'];

        // Un rattachement déjà posé ne se retire pas parce qu'un dépôt suivant ne sait
        // plus le lire : on écrit ce qu'on a trouvé, on n'efface pas ce qu'on n'a pas.
        if ($lu['commercial_id'] !== null) {
            $valeurs['commercial_id'] = $lu['commercial_id'];
            $valeurs['commercial_source'] = $lu['source'];
        }

        $dossier = $this->dossierDe($numero);

        if ($dossier === null) {
            DossierVehicule::withoutGlobalScopes()->create($valeurs + [
                'entreprise_id' => $this->entrepriseId,
                'numero_fiche' => $numero,
                'lot_import_id' => $lotId,
            ]);

            // Un fichier peut répéter une fiche : la deuxième occurrence doit être vue
            // comme une mise à jour, pas comme une création qui violerait l'unicité.
            $this->numerosConnus[$numero] = true;
            $this->dernierNumero = null;

            return 'cree';
        }

        // Un rattachement déjà établi par la donnée ne se laisse pas écraser par une
        // présomption : redéposer le fichier de la mauvaise ville ne doit pas déplacer une
        // fiche dont on savait où elle allait.
        if ($rattachement['presumee'] && ! $dossier->rattachement_presume) {
            unset(
                $valeurs['ville_id'],
                $valeurs['site_id'],
                $valeurs['source_rattachement'],
                $valeurs['rattachement_presume'],
            );
        }

        $dossier->fill($valeurs);

        // `lot_import_id` est volontairement hors du tableau comparé : il dit quel dépôt a
        // **modifié** la fiche pour la dernière fois. Le compter comme une différence ferait
        // annoncer « 2 203 mises à jour » à chaque redépôt d'un fichier identique, alors
        // que rien n'a bougé — et un écran qui crie au changement pour rien finit par ne
        // plus être lu du tout.
        if (! $dossier->isDirty()) {
            return 'ignore';
        }

        $dossier->lot_import_id = $lotId;
        $dossier->save();

        return 'maj';
    }
}

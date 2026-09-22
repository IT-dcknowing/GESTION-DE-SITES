<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\MouvementVehicule;

/**
 * Le tronc commun des entrées et des sorties de véhicules.
 *
 * Les deux états sortent du même écran du logiciel et se ressemblent presque : mêmes
 * colonnes de véhicule, de client, de motif et de travaux. Ils diffèrent par leur première
 * colonne — « DATE » d'un côté, « DATE SORTIE » de l'autre — et par l'ordre des suivantes.
 * D'où deux classes filles minces sur un tronc commun, plutôt qu'une classe unique qui
 * essaierait de reconnaître les deux et finirait par confondre une entrée avec une sortie.
 *
 * **Le sens n'est pas deviné, il est déclaré par le format choisi au dépôt.** Rien dans le
 * contenu d'un de ces fichiers ne dit s'il s'agit d'entrées ou de sorties : les colonnes
 * sont presque les mêmes, et une même fiche figure dans les deux à quelques jours d'écart.
 * Le déduire aurait été un pari ; le demander au déposant est une question à laquelle il
 * répond sans hésiter, puisqu'il vient d'extraire le fichier.
 *
 * **L'en-tête est en ligne 2**, sous un titre et une période. Aucune constante ne le fixe :
 * la méthode générale cherche la ligne dont les intitulés ressemblent le plus à ceux
 * attendus, ce qui reste vrai si le logiciel ajoute un jour une ligne de titre.
 *
 * **Les dates arrivent en numéros de série**, comme dans les devis — le logiciel ne déclare
 * pas le format de ces cellules. La conversion les reconnaît d'elle-même ; on ne s'en occupe
 * pas ici.
 */
abstract class FormatDesMouvementsDeVehicules extends Format
{
    /** ENTREE ou SORTIE : c'est le format déposé qui le dit, pas le contenu du fichier. */
    abstract protected function sens(): string;

    public static function colonnesObligatoires(): array
    {
        return ['numero_fiche'];
    }

    /**
     * Le code employé se lit dans le numéro de fiche — « FR-KZN° 015100 » → KZ.
     *
     * C'est la même clé que partout ailleurs, et elle a ici une valeur particulière : ces
     * fichiers-là sont extraits site par site, donc chaque code croisé y est attaché à un
     * atelier certain dès lors que le déposant l'a déclaré.
     */
    protected function reference(array $ligne): ?string
    {
        return self::texte($ligne['numero_fiche'] ?? null, 40);
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if (self::date($ligne['date'] ?? null) === null) {
            return 'La date du mouvement est absente ou illisible.';
        }

        return null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $fiche = self::texte($ligne['numero_fiche'] ?? null, 40);
        $date = self::date($ligne['date'] ?? null);

        $valeurs = [
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'sens' => $this->sens(),
            'date' => $date,
            'numero_fiche' => $fiche,
            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 40),
            'marque' => self::texte($ligne['marque'] ?? null, 60),
            'modele' => self::texte($ligne['modele'] ?? null, 60),
            'client' => self::texte($ligne['client'] ?? null, 160),
            'motif' => self::texte($ligne['motif'] ?? null, 60),

            /*
             * Chaque colonne du fichier garde la sienne, depuis le 24/09.
             *
             * Ces quatre-là n'avaient nulle part où aller et finissaient collées en une
             * phrase dans `observations` : « TRAVAUX · Propriétaire : X · Déposant : Y ·
             * Livraison prévue : 01/09/2026 ». Mesuré sur les 147 mouvements repris, la
             * date de livraison prévue est renseignée **147 fois sur 147** — et rangée
             * dans une phrase, elle ne se triait pas, ne se filtrait pas et ne se
             * comparait pas à aujourd'hui. On ne pouvait donc pas poser la question du
             * comptoir : « qu'est-ce qui devait sortir et est encore là ? »
             *
             * `observations` n'est volontairement plus écrite : le fichier n'a pas de
             * colonne de ce nom, et les lignes qui portent déjà la phrase la gardent — la
             * découper pour en répartir les morceaux supposerait qu'on sait la relire, or
             * le déposant y porte des retours à la ligne et des numéros de téléphone.
             */
            'travaux' => self::texte($ligne['travaux'] ?? null, 500),
            'proprietaire' => self::texte($ligne['proprietaire'] ?? null, 200),
            'deposant' => self::texte($ligne['deposant'] ?? null, 200),
            'date_livraison_prevue' => self::date($ligne['date_livraison_prevue'] ?? null),

            'code_agent' => $rattachement['code'],
        ];

        // La clé est la fiche **et** le sens : une même affaire entre une fois et sort une
        // fois, et les deux mouvements doivent coexister. Ajouter la date à la clé aurait
        // créé un doublon à chaque correction de date dans le logiciel.
        $existant = MouvementVehicule::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('numero_fiche', $fiche)
            ->where('sens', $this->sens())
            ->first();

        if ($existant === null) {
            MouvementVehicule::withoutGlobalScopes()->create($valeurs);

            return 'cree';
        }

        if ($rattachement['presumee'] && $existant->site_id !== null) {
            unset($valeurs['site_id']);
        }

        $existant->fill($valeurs);

        if (! $existant->isDirty()) {
            return 'ignore';
        }

        $existant->save();

        return 'maj';
    }
}

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
            'observations' => $this->observations($ligne),
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

    /**
     * Ce que le fichier porte en plus, et qu'aucune colonne n'accueille.
     *
     * Le propriétaire et le déposant y figurent avec leur numéro de téléphone, sur deux
     * lignes dans la même cellule. On les conserve tels quels : dans un atelier, c'est
     * souvent le seul contact qu'on ait pour prévenir que le véhicule est prêt.
     */
    private function observations(array $ligne): ?string
    {
        $morceaux = array_filter([
            ($t = self::texte($ligne['travaux'] ?? null, 500)) ? $t : null,
            ($p = self::texte($ligne['proprietaire'] ?? null, 200)) ? "Propriétaire : {$p}" : null,
            ($d = self::texte($ligne['deposant'] ?? null, 200)) ? "Déposant : {$d}" : null,
            ($l = self::date($ligne['date_livraison_prevue'] ?? null))
                ? 'Livraison prévue : '.$l->format('d/m/Y') : null,
        ]);

        return $morceaux === [] ? null : implode(' · ', $morceaux);
    }
}

<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\MouvementVehicule;

/**
 * « Liste des véhicules sortis » — les livraisons.
 *
 * Même écran du logiciel que les entrées, à deux différences près qui suffisent à justifier
 * une classe distincte : la première colonne s'appelle « DATE SORTIE », et la date de
 * livraison prévue passe devant le numéro de fiche.
 *
 * **Deux dates cohabitent, et il ne faut pas les confondre** : celle de la sortie réelle et
 * celle qui avait été promise. C'est la première qui date le mouvement ; la seconde est
 * conservée en observation, parce que leur écart est précisément ce qui mesure le respect
 * des délais — un indicateur que personne ne pourra calculer si on écrase l'une par l'autre.
 */
class FormatDesSorties extends FormatDesMouvementsDeVehicules
{
    protected function sens(): string
    {
        return MouvementVehicule::SORTIE;
    }

    public static function cle(): string
    {
        return 'sorties';
    }

    public static function libelle(): string
    {
        return 'Sorties de véhicules';
    }

    public static function colonnes(): array
    {
        return [
            'date' => 'DATE SORTIE',
            'date_livraison_prevue' => 'DATE DE LIVR. PREVUE',
            'numero_fiche' => 'N° FICHE',
            'immatriculation' => 'N° IMMAT.',
            'marque' => 'MARQUE',
            'modele' => 'MODELE',
            'client' => 'CLIENTS',
            'proprietaire' => 'NOM ET CONTACT DU PROPRIETAIRE',
            'deposant' => 'NOM ET CONTACT DEPOSANT',
            'motif' => 'MOTIF DE LA VENUE',
            'travaux' => 'TRAVAUX A EFFECTUER',
        ];
    }
}

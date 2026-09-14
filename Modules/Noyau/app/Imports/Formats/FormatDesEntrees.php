<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\MouvementVehicule;

/**
 * « Liste des véhicules entrés » — les arrivées à l'atelier.
 *
 * Treize colonnes, en-tête en ligne 2. Le fichier est extrait **site par site** dans le
 * logiciel — celui qui a servi de référence porte « SITE 1 » en tête —, ce qui en fait le
 * meilleur candidat pour rattacher les codes employés à leur atelier : chaque code y est
 * forcément celui d'un agent du site déclaré.
 *
 * Les deux dernières colonnes, « BPC » et « BPC ET BS », valent 0 ou 1. Elles ne sont pas
 * reprises : rien dans le fichier ne dit ce que ces sigles désignent, et une colonne
 * booléenne dont on ignore le sens est plus nuisible qu'absente — on finirait par la
 * compter en croyant savoir.
 */
class FormatDesEntrees extends FormatDesMouvementsDeVehicules
{
    protected function sens(): string
    {
        return MouvementVehicule::ENTREE;
    }

    public static function cle(): string
    {
        return 'entrees';
    }

    public static function libelle(): string
    {
        return 'Entrées de véhicules';
    }

    public static function colonnes(): array
    {
        return [
            'date' => 'DATE',
            'numero_fiche' => 'N° FICHE',
            'immatriculation' => 'N° IMMAT.',
            'marque' => 'MARQUE',
            'modele' => 'MODELE',
            'client' => 'CLIENTS',
            'proprietaire' => 'NOM ET CONTACT DU PROPRIETAIRE',
            'deposant' => 'NOM ET CONTACT DEPOSANT',
            'motif' => 'MOTIF DE LA VENUE',
            'travaux' => 'TRAVAUX A EFFECTUER',
            'date_livraison_prevue' => 'DATE DE LIVR. PREVUE',
        ];
    }
}

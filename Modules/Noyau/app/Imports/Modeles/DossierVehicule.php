<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Imports\Concerns\MontreLesColonnesDuFichier;
use Modules\Noyau\Imports\Formats\FormatDuParc;

/**
 * Une fiche de réception : un véhicule entré à l'atelier, et ce qu'il y est devenu.
 *
 * C'est la pièce d'identité de toute affaire. Le devis la cite, la facture la cite, l'état
 * des impayés la cite — et c'est la seule chose qu'ils citent tous. D'où sa place en tête
 * de la chaîne d'import : sans elle, les autres fichiers n'ont rien à quoi se raccrocher.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'code_agent',
    'source_rattachement', 'rattachement_presume', 'lot_import_id',
    'numero_fiche', 'immatriculation', 'marque', 'modele',
    'client', 'proprietaire', 'motif', 'statut', 'travaux', 'informations',
    'commercial_saisi', 'commercial_id', 'commercial_source',
    'date_fiche', 'date_fin_prevue', 'date_theorique_atelier',
    'date_transmission_devis', 'date_traitement_feb',
    'date_effective_travaux', 'date_fin_travaux',
])]
class DossierVehicule extends Model
{
    use AppartientAUneEntreprise;
    use MontreLesColonnesDuFichier;

    protected $table = 'dossiers_vehicules';

    /**
     * Les cinq états du parcours, dans l'ordre où ils s'enchaînent.
     *
     * Relevés sur les 3 179 fiches des trois villes : ce ne sont pas des états inventés
     * pour la circonstance, mais exactement ceux que le logiciel produit. Une valeur hors
     * de cette liste signale une ligne abîmée — et il y en a une, où un texte de travaux
     * sur plusieurs lignes a débordé sur les colonnes voisines.
     */
    public const STATUTS = [
        'VEHICULE RECEPTIONNE/ EN ATTENTE DE DEVIS',
        'DEVIS EFFECTUE/ EN ATTENTE DE TRANSMISSION',
        'DEVIS TRANSMIS / EN ATTENTE DE VALIDATION PAR LE CLIENT',
        'DEVIS VALIDE / TRAVAUX EN COURS',
        'TRAVAUX TERMINES / VEHICULE LIVRE',
    ];

    /** Les quatre motifs de venue, eux aussi relevés et non supposés. */
    public const MOTIFS = [
        'TRAVAUX MECANIQUES',
        'SINISTRE',
        'DEVIS SP',
        'TRAVAUX TOLERIES ET PEINTURES',
    ];

    protected function casts(): array
    {
        return [
            'rattachement_presume' => 'boolean',
            'date_fiche' => 'date',
            'date_fin_prevue' => 'date',
            'date_theorique_atelier' => 'date',
            'date_transmission_devis' => 'date',
            'date_traitement_feb' => 'date',
            'date_effective_travaux' => 'date',
            'date_fin_travaux' => 'date',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Le commercial nommé en tête de la colonne « INFORMATIONS SUR LA SITUATION ».
     *
     * Nul tant que personne ne l'a écrit, ou tant que ce qui a été écrit n'a pas été
     * reconnu : un vide se lit « on ne sait pas », jamais « personne ».
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class, 'commercial_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotImport::class, 'lot_import_id');
    }

    /** Vrai quand l'affaire est close côté atelier. */
    public function estTerminee(): bool
    {
        return $this->statut === 'TRAVAUX TERMINES / VEHICULE LIVRE';
    }

    /**
     * Ce qu'il faut afficher quand on ne sait pas où ranger la fiche.
     *
     * Une fiche sans ville n'est pas une erreur silencieuse : elle se compte, elle
     * s'affiche, et elle attend qu'on nomme le code agent qui la débloquera.
     */
    public function attenteDAffectation(): bool
    {
        return $this->ville_id === null || $this->rattachement_presume;
    }

    /**
     * D'où viennent les colonnes de cette fiche.
     *
     * Le rendu lui-même est dans {@see MontreLesColonnesDuFichier}, partagé avec les autres
     * modèles issus d'un import. C'est ici que la règle est née : la vue listait les champs
     * un par un et en avait perdu deux en chemin — « DATE THEORIQUE ATELIER » et
     * « INFORMATIONS SUR LA SITUATION ». Depuis, les intitulés et l'ordre sont lus dans le
     * format, et une colonne ajoutée au format apparaît sans que personne y pense.
     */
    public static function formatDOrigine(): string
    {
        return FormatDuParc::class;
    }
}

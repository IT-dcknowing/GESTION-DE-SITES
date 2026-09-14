<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Imports\Formats\FormatDuParc;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

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
    'date_fiche', 'date_fin_prevue', 'date_theorique_atelier',
    'date_transmission_devis', 'date_traitement_feb',
    'date_effective_travaux', 'date_fin_travaux',
])]
class DossierVehicule extends Model
{
    use AppartientAUneEntreprise;

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
     * La fiche telle que le logiciel d'atelier l'écrit : ses libellés, son ordre, toutes
     * ses colonnes.
     *
     * **L'ordre et les intitulés ne sont pas recopiés à la main ici : ils sont lus dans le
     * format d'import.** C'est ce qui garantit qu'aucune colonne ne peut être oubliée à
     * l'affichage — ajouter une colonne au format la fait apparaître ici sans que personne
     * n'ait à y penser. La version précédente listait les champs un par un dans la vue, et
     * en avait perdu deux en chemin : « DATE THEORIQUE ATELIER » et « INFORMATIONS SUR LA
     * SITUATION ».
     *
     * **Les colonnes vides sont conservées**, avec un tiret. Une case vide dans le logiciel
     * est une information — les travaux n'ont pas commencé, le devis n'est pas transmis — et
     * la masquer donnerait à croire que le champ n'existe pas.
     *
     * @return array<string, string> intitulé du logiciel => valeur affichable
     */
    public function champsDuLogiciel(): array
    {
        $champs = [];

        foreach (FormatDuParc::colonnes() as $attribut => $intitule) {
            $valeur = $this->{$attribut} ?? null;

            $champs[$intitule] = match (true) {
                $valeur === null, $valeur === '' => '—',
                $valeur instanceof \DateTimeInterface => $valeur->format('d/m/Y'),
                default => (string) $valeur,
            };
        }

        return $champs;
    }
}

<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * L'entrée ou la sortie d'un véhicule de l'atelier.
 *
 * La table a précédé ses lecteurs : les colonnes et la destination ont été préparées avant
 * qu'on sache lire le format, plutôt que de prétendre le lire. Les deux lecteurs existent
 * depuis — « Liste des véhicules entrés » et « sortis » — et la clé est la fiche **et** le
 * sens, puisqu'une même affaire entre une fois et sort une fois.
 *
 * **Les quatre colonnes ajoutées le 24/09** — travaux, propriétaire, déposant, date de
 * livraison prévue — étaient jusque-là fondues en une phrase dans `observations`, faute de
 * colonne pour les recevoir. Une date rangée dans une phrase ne se trie pas et ne se
 * compare pas à aujourd'hui : c'est ce qui empêchait de demander ce qui devait sortir et
 * n'est pas sorti. `observations` n'est plus écrite par l'import ; les lignes qui portent
 * encore la phrase la gardent jusqu'au prochain dépôt de leur fichier.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'sens', 'date', 'numero_fiche', 'immatriculation', 'marque', 'modele',
    'client', 'motif', 'travaux', 'proprietaire', 'deposant', 'date_livraison_prevue',
    'observations', 'code_agent',
])]
class MouvementVehicule extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'mouvements_vehicules';

    public const ENTREE = 'entree';

    public const SORTIE = 'sortie';

    protected function casts(): array
    {
        return ['date' => 'date', 'date_livraison_prevue' => 'date'];
    }

    /**
     * Les entrées dont la date de livraison promise est passée, sans sortie enregistrée.
     *
     * **Pourquoi l'absence de sortie et non le statut de la fiche.** Le statut vient du
     * parc, qui est un autre fichier et une autre extraction : le croiser ferait dépendre
     * cette réponse de la fraîcheur d'un second dépôt. Ici, les deux faits comparés
     * viennent des deux états du même écran du logiciel — entré le tant, ressorti le tant —
     * et l'absence de la seconde ligne est précisément ce qu'on veut voir.
     *
     * Le rapprochement se fait sur le numéro de fiche, qui est la clé de ces fichiers, et
     * l'entreprise est répétée dans la sous-requête : une portée globale ne s'applique pas
     * à une table jointe par son nom.
     *
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public static function promesseDepassee($requete)
    {
        return $requete
            ->where('sens', self::ENTREE)
            ->whereNotNull('date_livraison_prevue')
            ->whereDate('date_livraison_prevue', '<', now())
            ->whereNotExists(function ($sous) {
                $sous->selectRaw('1')
                    ->from('mouvements_vehicules as sortie')
                    ->whereColumn('sortie.numero_fiche', 'mouvements_vehicules.numero_fiche')
                    ->whereColumn('sortie.entreprise_id', 'mouvements_vehicules.entreprise_id')
                    ->where('sortie.sens', self::SORTIE);
            });
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
}

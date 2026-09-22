<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Le solde avec lequel une caisse ouvre une période, tel que la source l'annonce.
 *
 * **Pourquoi ce n'est pas un calcul.** On pourrait additionner tous les mouvements
 * antérieurs et appeler cela le solde d'avant. Ce serait faux, et d'une fausseté
 * silencieuse : nous n'avons que ce que les documents déposés couvrent, et la caisse
 * existait avant le premier d'entre eux. Le journal de Bouaké ouvre à 0, celui de
 * San-Pédro à 31 260 — aucun mouvement de notre base ne l'explique, et c'est normal.
 *
 * **Une photographie, pas un événement.** Redéposer le même document met à jour la ligne
 * au lieu d'en ajouter une seconde : une caisse, une période, un solde. C'est la même
 * décision que pour la balance fournisseur, et pour la même raison — le fichier décrit un
 * état à un instant, pas une opération.
 *
 * Les deux sources en portent un. Le journal imprimé l'écrit en toutes lettres
 * (« SOLDE AVANT LA PERIODE : 31 260 ») ; le classeur tenu à la main d'Abidjan l'écrit en
 * quatrième ligne de chaque onglet mensuel (« SOLDE D'OUVERTURE »), où personne ne le
 * lisait.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'caisse', 'debut', 'fin', 'solde_avant', 'source', 'feuille',
])]
class OuvertureCaisse extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'ouvertures_caisse';

    /** D'où vient l'annonce. */
    public const DU_JOURNAL = 'journal';

    public const DU_CLASSEUR = 'classeur';

    protected function casts(): array
    {
        return [
            'debut' => 'date',
            'fin' => 'date',
            'solde_avant' => 'integer',
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

    /**
     * Pose l'annonce d'une caisse pour une période, ou met à jour celle qui y est déjà.
     *
     * **Pourquoi ce n'est pas un `updateOrCreate` tout simple.** Les bornes sont des dates,
     * et une comparaison SQL sur une colonne de date ne rapproche pas « 2026-04-01 » de
     * « 2026-04-01 00:00:00 ». Le rapprochement échouait donc silencieusement, et redéposer
     * le même journal butait sur la clé unique au lieu de reconnaître sa propre ligne. De
     * même, `where('ville_id', null)` ne rapproche rien en SQL : il faut `whereNull`.
     *
     * @param  array{entreprise_id: int, ville_id: int|null, caisse: string, debut: ?string, fin: ?string}  $identite
     */
    public static function consigner(array $identite, array $valeurs): self
    {
        $requete = self::withoutGlobalScopes()
            ->where('entreprise_id', $identite['entreprise_id'])
            ->where('caisse', $identite['caisse']);

        $requete = $identite['ville_id'] === null
            ? $requete->whereNull('ville_id')
            : $requete->where('ville_id', $identite['ville_id']);

        foreach (['debut', 'fin'] as $borne) {
            $requete = $identite[$borne] === null
                ? $requete->whereNull($borne)
                : $requete->whereDate($borne, $identite[$borne]);
        }

        $ouverture = $requete->first() ?? self::withoutGlobalScopes()->make($identite);

        $ouverture->fill($valeurs)->save();

        return $ouverture;
    }
}

<?php

namespace Modules\Noyau\Imports\Modeles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Noyau\Commun\Concerns\AppartientAUneEntreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;

/**
 * Un mouvement de caisse : ce qui entre, ce qui sort, et le solde qui suit.
 *
 * Les deux sens dans une seule table, avec un montant toujours positif et le sens dans sa
 * propre colonne. Un tableau où certaines lignes sont négatives se lit mal, et le total se
 * calcule aussi bien avec une condition qu'avec un signe.
 *
 * `solde_annonce` mérite un mot : c'est le solde tel que le fichier l'affiche, recopié sans
 * être vérifié. S'il s'écarte de notre propre cumul, ce n'est pas une erreur de notre côté
 * — c'est le signe que le fichier a été retouché à la main entre deux lignes, et c'est
 * précisément ce qu'on veut pouvoir montrer.
 *
 * **Depuis le 23/09, deux sources écrivent ici** et non plus une seule : le classeur tenu à
 * la main d'Abidjan, et le journal de caisse imprimé de Bouaké et San-Pédro. Les colonnes
 * que seul le journal renseigne — n° de pièce, type de journal, motif, rôle du tiers, nom
 * de la caisse — restent **vides** pour les lignes du classeur, et non à zéro : le classeur
 * ne les dit pas, il ne dit pas qu'elles n'existent pas.
 */
#[Fillable([
    'entreprise_id', 'ville_id', 'site_id', 'lot_import_id',
    'date', 'sens', 'libelle', 'montant', 'solde_annonce',
    'numero_piece', 'type_piece', 'motif', 'role_tiers', 'caisse',
    'beneficiaire', 'immatriculation', 'mois', 'feuille', 'page',
])]
class MouvementCaisse extends Model
{
    use AppartientAUneEntreprise;

    protected $table = 'mouvements_caisse';

    public const ENTREE = 'entree';

    public const SORTIE = 'sortie';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant' => 'integer',
            'solde_annonce' => 'integer',
            'page' => 'integer',
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
     * Qui a remis ou reçu l'argent, avec son rôle quand la source le dit.
     *
     * Le journal distingue le remettant du bénéficiaire ; le classeur d'Abidjan les range
     * dans une colonne unique. On rend donc « Remettant — X » quand c'est su, « X » sinon,
     * plutôt que d'inventer un rôle à partir du sens du mouvement : une sortie au profit
     * d'un client existe, et l'appeler bénéficiaire quand le fichier ne le dit pas serait
     * une déduction déguisée en donnée.
     */
    public function tiers(): ?string
    {
        $nom = trim((string) $this->beneficiaire);

        if ($nom === '') {
            return null;
        }

        return match ($this->role_tiers) {
            'remettant' => 'Remettant — '.$nom,
            'beneficiaire' => 'Bénéficiaire — '.$nom,
            default => $nom,
        };
    }

    /** Le montant signé, pour les additions. */
    public function signe(): int
    {
        return $this->sens === self::ENTREE ? $this->montant : -$this->montant;
    }
}

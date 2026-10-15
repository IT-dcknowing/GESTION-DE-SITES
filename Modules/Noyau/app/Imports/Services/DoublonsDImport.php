<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use App\Support\SchemaDisponible;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Formats\Format;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\DoublonImport;
use RuntimeException;

/**
 * Les lignes mises de côté comme doublons à l'import : les lire, les compter, les intégrer — 14/10.
 *
 * **La demande du propriétaire.** « Lors de l'import, on importe tout ; dans la page, un KPI fait la
 * différence — ce qui a été importé, et le montant correct ; un tableau, derrière un bouton, montre
 * les lignes considérées comme doublons (celle écartée) ; un bouton les intègre si en réalité ce
 * n'en sont pas. »
 *
 * La détection est dans `Format::premiereLibre()` : une ligne de la base ne sert qu'une fois par
 * dépôt, et la retrouver une seconde fois fait de la ligne du fichier une répétition. Ici, on ne
 * fait que la montrer et, sur décision, l'écrire à part entière.
 *
 * Le périmètre est celui de toutes les données d'argent : l'atelier s'il est connu, sinon la
 * ville, sinon la ligne paraît partout — la cacher reviendrait à la perdre.
 */
class DoublonsDImport
{
    /**
     * Intégrer écrit une ligne d'argent dans les données de l'entreprise : ceux qui décident des
     * imports de toute une ville. Le responsable d'atelier et le caissier voient, ne décident pas.
     */
    public const ROLES_QUI_INTEGRENT = ['gerant', 'responsable_ville'];

    public function __construct(private readonly int $entrepriseId) {}

    public static function disponible(): bool
    {
        return SchemaDisponible::table('doublons_import');
    }

    public static function peutIntegrer(?User $utilisateur): bool
    {
        return $utilisateur?->hasAnyRole(self::ROLES_QUI_INTEGRENT) === true;
    }

    /**
     * Les doublons de ces formats, dans ce périmètre et cette période.
     *
     * Une ligne sans date paraît quelle que soit la période : rien ne permet de l'en écarter.
     *
     * @param  list<string>  $formats
     * @param  array<int, int>  $idsSites
     * @param  array<int, int>  $idsVilles
     */
    public function requete(array $formats, array $idsSites, array $idsVilles, ?string $debut = null, ?string $fin = null): Builder
    {
        return DoublonImport::query()
            ->where('doublons_import.entreprise_id', $this->entrepriseId)
            ->whereIn('doublons_import.format', $formats)
            ->where(fn ($q) => $q
                ->whereIn('doublons_import.site_id', $idsSites)
                ->orWhere(fn ($sansAtelier) => $sansAtelier
                    ->whereNull('doublons_import.site_id')
                    ->where(fn ($ville) => $ville
                        ->whereNull('doublons_import.ville_id')
                        ->orWhereIn('doublons_import.ville_id', $idsVilles))))
            ->when($debut !== null && $fin !== null, fn ($q) => $q->where(fn ($periode) => $periode
                ->whereNull('doublons_import.date')
                ->orWhereBetween('doublons_import.date', [substr($debut, 0, 10).' 00:00:00', substr($fin, 0, 10).' 23:59:59'])));
    }

    /**
     * Combien, et pour quel montant : ce qui attend une décision, ce qui a été tranché.
     *
     * `montantATrancher` est **déjà dans le total de la page** (la ligne est écrite) : c'est ce
     * qu'il faudrait en retirer si ce sont bien des doublons. `montantEcarte` (lignes d'avant le
     * 15/10, mises de côté sans être écrites) n'y est pas.
     *
     * @return array{aTrancher: int, montantATrancher: int|float, ecartes: int, montantEcarte: int|float, tranches: int, retires: int, montantRetire: int|float}
     */
    public static function totaux(Builder $requete): array
    {
        $ligne = (clone $requete)->reorder()
            ->selectRaw("coalesce(sum(case when statut = 'a_trancher' then 1 else 0 end), 0) as n_attente")
            ->selectRaw("coalesce(sum(case when statut = 'a_trancher' then montant else 0 end), 0) as m_attente")
            ->selectRaw("coalesce(sum(case when statut = 'ecarte' then 1 else 0 end), 0) as n_ecartes")
            ->selectRaw("coalesce(sum(case when statut = 'ecarte' then montant else 0 end), 0) as m_ecartes")
            ->selectRaw("coalesce(sum(case when statut in ('garde', 'retire', 'integre') then 1 else 0 end), 0) as n_tranches")
            ->selectRaw("coalesce(sum(case when statut = 'retire' then 1 else 0 end), 0) as n_retires")
            ->selectRaw("coalesce(sum(case when statut = 'retire' then montant else 0 end), 0) as m_retires")
            ->toBase()->first();

        return [
            'aTrancher' => (int) ($ligne->n_attente ?? 0),
            'montantATrancher' => montant_de($ligne->m_attente ?? 0),
            'ecartes' => (int) ($ligne->n_ecartes ?? 0),
            'montantEcarte' => montant_de($ligne->m_ecartes ?? 0),
            'tranches' => (int) ($ligne->n_tranches ?? 0),
            'retires' => (int) ($ligne->n_retires ?? 0),
            'montantRetire' => montant_de($ligne->m_retires ?? 0),
        ];
    }

    /**
     * La ligne retenue que chaque doublon répète, lue d'un coup par table.
     *
     * @param  iterable<DoublonImport>  $doublons
     * @return array<string, object> « table#id » => la ligne retenue (date, montant, libellé, référence)
     */
    public static function retenues(iterable $doublons): array
    {
        $parTable = [];

        foreach ($doublons as $doublon) {
            if ($doublon->retenue_table !== null && $doublon->retenue_id !== null) {
                $parTable[$doublon->retenue_table][] = (int) $doublon->retenue_id;
            }
        }

        $rendu = [];

        foreach ($parTable as $table => $ids) {
            if (! isset(self::COLONNES_RETENUES[$table])) {
                continue;
            }

            foreach (DB::table($table)->whereIn('id', array_unique($ids))->get(['id', ...self::COLONNES_RETENUES[$table]]) as $ligne) {
                $rendu[$table.'#'.$ligne->id] = $ligne;
            }
        }

        return $rendu;
    }

    /** Ce qu'on lit de la ligne retenue, table par table — dans l'ordre : référence, date, libellé, montant. */
    public const COLONNES_RETENUES = [
        'factures' => ['n_facture', 'date', 'client', 'montant'],
        'devis' => ['numero', 'date_emission', 'client', 'montant_devis'],
        'mouvements_caisse' => ['numero_piece', 'date', 'libelle', 'montant'],
        'mouvements_bancaires' => ['libelle', 'date_operation', 'nature', 'credit'],
        'pieces_bancaires' => ['code_piece', 'date_piece', 'beneficiaire', 'montant'],
        'reglements_fournisseur' => ['code_reglement', 'date_reglement', 'fournisseur', 'montant'],
        'factures_fournisseurs' => ['numero_piece', 'date_facture', 'fournisseur', 'montant'],
    ];

    /**
     * Écrit à part entière une ligne mise de côté : ce n'était pas un doublon.
     *
     * Relue en base sous l'entreprise du lecteur, et seulement si elle attend encore une décision :
     * un identifiant venu du navigateur ne suffit pas à écrire de l'argent. L'écriture passe par le
     * même format que le dépôt — la ligne entre comme elle serait entrée si elle n'avait répété
     * personne — et dans une transaction : si elle échoue, rien n'est écrit et le doublon reste.
     */
    public function integrer(int $doublonId, User $par): DoublonImport
    {
        if (! self::peutIntegrer($par)) {
            throw new RuntimeException("Intégrer un doublon est réservé au gérant et au responsable de ville.");
        }

        return DB::transaction(function () use ($doublonId, $par) {
            $doublon = DoublonImport::query()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($doublonId)
                ->lockForUpdate()
                ->first();

            if ($doublon === null) {
                throw new RuntimeException('Ce doublon est introuvable.');
            }

            if (! $doublon->estEcarte()) {
                throw new RuntimeException('Ce doublon a déjà été tranché.');
            }

            $classe = Registre::classe($doublon->format);
            /** @var Format $format */
            $format = new $classe($this->entrepriseId, new Rattachement($this->entrepriseId));

            $rattachement = ($doublon->rattachement ?? []) + [
                'ville_id' => $doublon->ville_id, 'site_id' => $doublon->site_id,
                'code' => null, 'source' => 'doublon', 'presumee' => false,
            ];

            $cree = $format->integrer(
                Format::deserialiser($doublon->ligne ?? []),
                $rattachement,
                $doublon->lot_import_id,
                $doublon->feuille,
                $doublon->lot?->ville_id === null ? null : (int) $doublon->lot->ville_id,
            );

            if ($cree === null) {
                throw new RuntimeException("La ligne n'a pas pu être écrite : son dépôt d'origine n'existe plus, ou elle ne se lit plus.");
            }

            $doublon->forceFill([
                'statut' => DoublonImport::INTEGRE,
                'integre_id' => $cree->getKey(),
                'integre_par' => $par->id,
                'integre_le' => now(),
            ])->save();

            return $doublon;
        });
    }

    /** Les trois réponses à « supposé doublon » : garder la première, garder la seconde, garder les deux. */
    public const GARDER_LA_PREMIERE = 'garder_la_premiere';

    public const GARDER_LA_SECONDE = 'garder_la_seconde';

    public const GARDER_LES_DEUX = 'garder_les_deux';

    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> la table d'une ligne, et son modèle */
    private const MODELES = [
        'factures' => \Modules\Noyau\Exploitation\Modeles\Facture::class,
        'devis' => \Modules\Noyau\Exploitation\Modeles\Devis::class,
        'mouvements_caisse' => \Modules\Noyau\Imports\Modeles\MouvementCaisse::class,
        'mouvements_bancaires' => \Modules\Noyau\Imports\Modeles\MouvementBancaire::class,
        'pieces_bancaires' => \Modules\Noyau\Imports\Modeles\PieceBancaire::class,
        'reglements_fournisseur' => \Modules\Noyau\Imports\Modeles\ReglementFournisseur::class,
        'factures_fournisseurs' => \Modules\Noyau\Imports\Modeles\FactureFournisseur::class,
    ];

    /**
     * Tranche un doublon supposé — 15/10 : « on demande de choisir à travers un bouton et de retirer
     * l'autre ».
     *
     * - garder la première (celle lue d'abord) : la seconde est supprimée ;
     * - garder la seconde : la première est supprimée ;
     * - garder les deux : ce n'étaient pas des doublons, rien n'est supprimé.
     *
     * Relu en base sous l'entreprise du lecteur : un identifiant venu du navigateur ne suffit pas à
     * supprimer de l'argent. Une ligne déjà utilisée ailleurs (un règlement saisi sur la facture,
     * une charge reprise d'un mouvement de caisse) n'est pas supprimée : le refus le dit. La
     * décision reste écrite — au dépôt suivant, la ligne retirée ne revient pas.
     *
     * Une ligne d'avant le 15/10 (mise de côté sans être écrite) se tranche aussi : « garder les
     * deux » l'écrit, « garder la première » la laisse dehors.
     */
    public function trancher(int $doublonId, string $decision, User $par): DoublonImport
    {
        if (! self::peutIntegrer($par)) {
            throw new RuntimeException('Trancher un doublon est réservé au gérant et au responsable de ville.');
        }

        if (! in_array($decision, [self::GARDER_LA_PREMIERE, self::GARDER_LA_SECONDE, self::GARDER_LES_DEUX], true)) {
            throw new RuntimeException('Décision inconnue.');
        }

        $doublon = DoublonImport::query()->where('entreprise_id', $this->entrepriseId)->whereKey($doublonId)->first();

        if ($doublon === null) {
            throw new RuntimeException('Ce doublon est introuvable.');
        }

        if (! in_array($doublon->statut, DoublonImport::EN_ATTENTE, true)) {
            throw new RuntimeException('Ce doublon a déjà été tranché.');
        }

        if ($doublon->estEcarte()) {
            if ($decision === self::GARDER_LA_SECONDE) {
                throw new RuntimeException("Cette ligne n'a jamais été écrite : gardez la première, ou les deux.");
            }

            if ($decision === self::GARDER_LES_DEUX) {
                $doublon = $this->integrer($doublon->id, $par);
            }

            return $this->noter($doublon, $decision === self::GARDER_LES_DEUX ? DoublonImport::GARDE : DoublonImport::RETIRE, $decision, $par);
        }

        return DB::transaction(function () use ($doublon, $decision, $par) {
            if ($decision === self::GARDER_LA_PREMIERE) {
                $this->retirerLaLigne($doublon->retenue_table, $doublon->integre_id);
            } elseif ($decision === self::GARDER_LA_SECONDE) {
                $this->retirerLaLigne($doublon->retenue_table, $doublon->retenue_id);
            }

            return $this->noter($doublon, $decision === self::GARDER_LES_DEUX ? DoublonImport::GARDE : DoublonImport::RETIRE, $decision, $par);
        });
    }

    private function noter(DoublonImport $doublon, string $statut, string $decision, User $par): DoublonImport
    {
        $doublon->forceFill(['statut' => $statut] + (SchemaDisponible::colonne('doublons_import', 'decision')
            ? ['decision' => $decision, 'decide_par' => $par->id, 'decide_le' => now()] : []))->save();

        return $doublon;
    }

    /** Supprime une ligne écrite par l'import — jamais une ligne sur laquelle on a travaillé depuis. */
    private function retirerLaLigne(?string $table, $id): void
    {
        $classe = self::MODELES[$table] ?? null;

        if ($classe === null || $id === null) {
            throw new RuntimeException('Cette ligne ne se retire pas d\'ici.');
        }

        $ligne = $classe::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)->find($id);

        // Déjà partie (annulation du dépôt, suppression à la main) : il n'y a plus rien à retirer.
        if ($ligne === null) {
            return;
        }

        if ($table === 'factures') {
            // Le règlement que l'import avait reporté sur cette ligne part avec elle ; un règlement
            // saisi à la main, non : c'est un travail fait sur cette facture-là.
            if (DB::table('encaissements')->where('facture_id', $id)->whereNull('lot_import_id')->exists()) {
                throw new RuntimeException('Cette facture porte un règlement saisi à la main : gardez-la, ou retirez d\'abord le règlement.');
            }

            DB::table('encaissements')->where('facture_id', $id)->whereNotNull('lot_import_id')->delete();
        }

        if ($table === 'mouvements_caisse' && SchemaDisponible::colonne('charges', 'mouvement_caisse_id')
            && DB::table('charges')->where('mouvement_caisse_id', $id)->exists()) {
            throw new RuntimeException('Ce mouvement a été repris en charge : gardez-le, ou retirez d\'abord la charge.');
        }

        if ($table === 'factures_fournisseurs' && DB::table('reglements_fournisseur')->where('facture_fournisseur_id', $id)->exists()) {
            throw new RuntimeException('Cette pièce porte un règlement : gardez-la, ou retirez d\'abord le règlement.');
        }

        try {
            $ligne->delete();
        } catch (\Illuminate\Database\QueryException) {
            throw new RuntimeException('Cette ligne est utilisée ailleurs dans l\'application : elle ne se retire pas d\'ici.');
        }
    }

    /** Les formats dont un écran montre les doublons — tous ceux qui en écartent. */
    public const LIBELLES = [
        'factures' => "Chiffre d'affaires",
        'impayes' => 'État des impayés',
        'devis' => 'Devis',
        'caisse' => 'Caisse (classeur)',
        'journal-caisse' => 'Journal de caisse',
        'releve-banque' => 'Relevé bancaire',
        'banque' => 'Pièces bancaires',
        'fournisseurs' => 'Suivi fournisseurs',
        'reglements-fournisseurs' => 'Règlements fournisseurs',
    ];
}

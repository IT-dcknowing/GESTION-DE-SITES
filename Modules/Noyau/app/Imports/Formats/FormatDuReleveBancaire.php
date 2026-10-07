<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;

/**
 * Le relevé bancaire tel que la caissière le tient — BGFI, et AFG sur le même modèle.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Le constat du 07/10.** On avait préparé l'import des banques sur les champs du logiciel
 * (`FormatDesPiecesBancaires`, qui reste). Mais la caissière n'est à jour que dans la caisse ;
 * pour les banques, elle exporte les transactions de la banque et les recopie dans un classeur
 * où elle ajoute ses colonnes. **Ce classeur est le modèle.** AFG exporte un CSV de mai à
 * aujourd'hui ; elle le retraitera au même modèle, et l'import est le même.
 *
 * **Ce que portent les quatre classeurs BGFI reçus (2023 → 2026), mesuré :**
 *
 * | | |
 * |---|---|
 * | en-tête | douze lignes (compte, IBAN, période), puis **« Solde initial »**, puis les titres en ligne 13 |
 * | colonnes | Date · Libellé de l'opération · Débit(XOF) · Crédit(XOF) · Solde(XOF) |
 * | ajoutées par elle | **F « Libellé »** — la contrepartie (SANLAM, NSIA, SALAIRES…) — à partir de **2025** ; **G**, sans titre, la nature de la dépense, en **2026** |
 * | seconde feuille (2026) | « POINT DES ENCAISSEMENTS » : les crédits seuls, tirés de la première. **Non lue** : elle doublerait les entrées |
 *
 * **La règle de lecture, et la mesure qui la fonde.** Une ligne qui porte un montant est une
 * opération ; une ligne qui n'en porte pas — « MOTIF : … », « Échéance N° … », « TVA / Int. … » —
 * précise l'opération d'avant. Une opération sans date (11 sur 6 829) prend celle de la ligne
 * précédente. Lue ainsi, **la chaîne des soldes tient sans une seule rupture** sur les quatre
 * fichiers — 1 382, 1 513, 2 154 et 1 780 opérations —, et chaque année repart exactement du
 * solde où la précédente s'arrête. Toute autre lecture la casse.
 *
 * **Redéposer ne double rien.** Une opération se reconnaît à sa clé : compte, date, libellé,
 * montants et **solde annoncé** — ce dernier rend deux opérations identiques du même jour
 * distinctes, puisqu'il change entre elles. Deux classeurs qui se chevauchent se rejoignent.
 */
class FormatDuReleveBancaire extends Format
{
    /** Les titres sont en ligne 13 : douze lignes d'en-tête de banque avant eux. */
    protected const LIGNES_SONDEES = 20;

    private ?int $banqueDuLot = null;

    private bool $banqueLue = false;

    /** La dernière opération écrite : les lignes sans montant la précisent. */
    private ?MouvementBancaire $derniere = null;

    private ?string $derniereDate = null;

    private int $rang = 0;

    public static function cle(): string
    {
        return 'releve-banque';
    }

    public static function libelle(): string
    {
        return 'Relevé bancaire — suivi de la caissière (BGFI, AFG)';
    }

    public static function colonnes(): array
    {
        return [
            'date' => 'Date',
            'libelle' => "Libellé de l'opération",
            'debit' => 'Débit(XOF)',
            'credit' => 'Crédit(XOF)',
            'solde' => 'Solde(XOF)',
            'contrepartie' => 'Libellé',
        ];
    }

    /**
     * Les autres façons d'écrire les mêmes titres. Le CSV d'AFG sera retraité au modèle, mais
     * « Débit » sans la devise ne doit pas faire refuser un fichier par ailleurs juste.
     */
    private const AUTRES_TITRES = [
        'debit' => ['Débit', 'Debit'],
        'credit' => ['Crédit', 'Credit'],
        'solde' => ['Solde'],
        'libelle' => ["Libellé de l'operation", 'Libellé opération'],
    ];

    public static function colonnesObligatoires(): array
    {
        return ['date', 'libelle', 'debit', 'credit'];
    }

    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    public static function demandeUnCompte(): bool
    {
        return true;
    }

    /** Plusieurs années dans un même classeur — 2025 descend jusqu'au 31/12, et c'est voulu. */
    public static function porteUnSeulExercice(): bool
    {
        return false;
    }

    public static function colonneDeDate(): ?string
    {
        return 'date';
    }

    protected function colonneSite(array $ligne): ?string
    {
        return null;
    }

    protected function reference(array $ligne): ?string
    {
        return null;
    }

    protected function correspondance(array $cellules): array
    {
        $trouvees = parent::correspondance($cellules);

        foreach (self::AUTRES_TITRES as $cle => $titres) {
            if (isset($trouvees[$cle])) {
                continue;
            }

            $attendus = array_map(fn ($t) => CorrespondanceImport::normaliser($t), $titres);

            foreach ($cellules as $position => $valeur) {
                if (is_string($valeur) && in_array(CorrespondanceImport::normaliser($valeur), $attendus, true)) {
                    $trouvees[$cle] = $position;

                    break;
                }
            }
        }

        return $trouvees;
    }

    /** La colonne G n'a pas de titre : c'est celle qui suit « Libellé », quand il y en a une. */
    protected function extraire(array $cellules, array $entete): array
    {
        $ligne = parent::extraire($cellules, $entete);

        if (isset($entete['contrepartie'])) {
            $ligne['nature'] = $cellules[$entete['contrepartie'] + 1] ?? null;
        }

        return $ligne;
    }

    /**
     * Rien n'est refusé ici : une ligne sans montant n'est pas une erreur, elle précise
     * l'opération d'avant. Le seul refus — une opération qu'aucune date ne situe — se décide
     * à l'écriture, où l'on sait ce qui précède.
     */
    protected function refuser(array $ligne): ?string
    {
        return null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $banqueId = $this->banqueDuLot($lotId);

        if ($banqueId === null) {
            return 'ignore';
        }

        $debit = self::montant($ligne['debit'] ?? null);
        $credit = self::montant($ligne['credit'] ?? null);
        $libelle = self::texte($ligne['libelle'] ?? null);

        // Pas de montant : la suite de l'opération d'avant, ou une ligne de pied (le solde seul).
        if ($debit === null && $credit === null) {
            if ($libelle !== null && $this->derniere !== null && ! in_array(mb_strtolower($libelle), ['total', 'solde final'], true)) {
                $this->derniere->motif = trim(($this->derniere->motif ? $this->derniere->motif."\n" : '').$libelle);
                $this->derniere->save();
            }

            return 'ignore';
        }

        $date = self::date($ligne['date'] ?? null)?->toDateString() ?? $this->derniereDate;

        if ($date === null) {
            // Aucune opération ne la précède dans le fichier : rien ne la situe dans le temps.
            return 'ignore';
        }

        $this->derniereDate = $date;
        $debit = (int) round(abs((float) $debit));
        $credit = (int) round(abs((float) $credit));
        $solde = self::montant($ligne['solde'] ?? null);
        $solde = $solde === null ? null : (int) round($solde);
        $libelle ??= 'Opération sans libellé';

        $cle = substr(hash('sha256', implode('|', [$banqueId, $date, $libelle, $debit, $credit, $solde ?? ''])), 0, 40);

        $valeurs = [
            'lot_import_id' => $lotId,
            'date_operation' => $date,
            'libelle' => mb_substr($libelle, 0, 255),
            // Refait à chaque dépôt par les lignes de suite : sans cette remise à zéro, un
            // redépôt recollerait le motif derrière lui-même.
            'motif' => null,
            'debit' => $debit,
            'credit' => $credit,
            'sens' => $credit > 0 ? MouvementBancaire::ENTREE : MouvementBancaire::SORTIE,
            'solde_annonce' => $solde,
            'contrepartie' => self::texte($ligne['contrepartie'] ?? null, 160),
            'nature' => self::texte($ligne['nature'] ?? null, 160),
            'rang' => ++$this->rang,
        ];

        $existant = MouvementBancaire::withoutGlobalScopes()
            ->where('banque_id', $banqueId)->where('cle', $cle)->first();

        if ($existant !== null) {
            $existant->fill($valeurs)->save();
            $this->derniere = $existant;

            return 'maj';
        }

        $this->derniere = MouvementBancaire::withoutGlobalScopes()->create($valeurs + [
            'entreprise_id' => $this->entrepriseId,
            'banque_id' => $banqueId,
            'cle' => $cle,
        ]);

        return 'cree';
    }

    /** Le compte déclaré au dépôt, lu une fois pour tout le fichier — comme pour l'autre relevé. */
    private function banqueDuLot(?int $lotId): ?int
    {
        if ($this->banqueLue) {
            return $this->banqueDuLot;
        }

        $this->banqueLue = true;
        $this->banqueDuLot = $lotId === null
            ? null
            : LotImport::withoutGlobalScopes()->whereKey($lotId)->value('banque_id');

        return $this->banqueDuLot;
    }
}

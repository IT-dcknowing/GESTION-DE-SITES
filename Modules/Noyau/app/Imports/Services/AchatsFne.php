<?php

namespace Modules\Noyau\Imports\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Commun\Services\FormeComparable;
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\ReglementFournisseur;
use Modules\Noyau\Imports\Modeles\ReleveFne;
use RuntimeException;

/**
 * Les factures d'achat FNE : les lire, les rapprocher du suivi, les porter, les régler.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Le plan du propriétaire, 12/10.** Le logiciel d'atelier n'exporte pas les factures
 * fournisseurs ; le **fichier de suivi des fournisseurs** est la base, et il s'importe avant
 * ou après le relevé FNE — la correspondance tient donc **dans les deux sens**. Les achats
 * relevés au portail sont **portés au suivi**, comme aux impayés ; chaque facture a son
 * règlement, saisi par **« Régler »**, qui fait le **lettrage** dans l'application. Le relevé
 * prend toutes les pièces émises par un tiers sur la plateforme, **avoirs compris**, et chacune
 * va **dans son exercice**.
 *
 * ## Le fichier lu
 *
 * Celui que dépose `SCRAPER-PORTAIL-FNE/achats.js` (repris de Selflow) :
 *
 *     { login, source, periode: {du, au}, colonnes_a_l_ecran, factures: [ …API du portail… ] }
 *
 * Une liste nue est acceptée aussi. Les champs lus sont ceux que Selflow a relevés sur l'API
 * le 27/08/2026 : `reference` (le numéro FNE), `date`, `type`/`subtype`, `status`,
 * `company.{ncc,name}` pour l'émetteur, `totalBeforeTaxes`, `totalTaxes`, `fiscalStamp`,
 * `totalAfterTaxes`, `paymentMethod`. **La fiche complète de l'émetteur n'est pas conservée** :
 * le portail y joint sa clé d'API en clair ; seuls son NCC, son nom et son identifiant restent.
 *
 * ## Ce qu'il ne fait pas
 *
 * Il ne crée **aucune dette d'office**. Une facture relevée confirme une ligne du suivi, ou
 * attend qu'on la porte. Créer la dette parce qu'un fichier est arrivé doublonnerait la ligne
 * que le classeur de suivi apportera — c'est précisément ce qu'on évite.
 */
final class AchatsFne
{
    /** Les types et sous-types du portail qui disent un avoir. */
    private const MOTS_AVOIR = ['refund', 'avoir', 'credit', 'creditnote', 'credit_note'];

    /**
     * Lit un relevé et range ses factures. Un fichier déjà lu (même empreinte) ne s'importe pas
     * deux fois.
     *
     * @return array{deja: bool, factures: int, nouvelles: int, avoirs: int, ignorees: int, rapprochees: int}
     */
    public static function importer(string $contenu, string $nomFichier, int $entrepriseId, ?int $deposePar = null): array
    {
        $brut = json_decode($contenu, true);

        if (! is_array($brut)) {
            throw new RuntimeException('Le fichier n’est pas un relevé lisible : ce n’est pas du JSON.');
        }

        $factures = array_is_list($brut) ? $brut : ($brut['factures'] ?? null);

        if (! is_array($factures)) {
            throw new RuntimeException('Le fichier ne porte pas de liste « factures » : ce n’est pas un relevé des achats FNE.');
        }

        $empreinte = hash('sha256', $contenu);

        if (ReleveFne::withoutGlobalScopes()->where('entreprise_id', $entrepriseId)->where('empreinte', $empreinte)->exists()) {
            return ['deja' => true, 'factures' => 0, 'nouvelles' => 0, 'avoirs' => 0, 'ignorees' => 0, 'rapprochees' => 0];
        }

        $bilan = DB::transaction(function () use ($brut, $factures, $empreinte, $nomFichier, $entrepriseId, $deposePar) {
            $releve = ReleveFne::withoutGlobalScopes()->create([
                'entreprise_id' => $entrepriseId,
                'login' => mb_substr((string) ($brut['login'] ?? ''), 0, 60),
                'fichier' => mb_substr($nomFichier, 0, 255),
                'empreinte' => $empreinte,
                'periode_du' => self::date($brut['periode']['du'] ?? null),
                'periode_au' => self::date($brut['periode']['au'] ?? null),
                'depose_par' => $deposePar,
            ]);

            $compte = ['factures' => 0, 'nouvelles' => 0, 'avoirs' => 0, 'ignorees' => 0];

            foreach ($factures as $brute) {
                $reference = is_array($brute) ? trim((string) ($brute['reference'] ?? '')) : '';

                if ($reference === '') {
                    $compte['ignorees']++;

                    continue;
                }

                $valeurs = self::valeurs($brute, $entrepriseId, $releve->id);
                $existante = FactureFne::withoutGlobalScopes()
                    ->where('entreprise_id', $entrepriseId)->where('reference', mb_substr($reference, 0, 80))->first();

                if ($existante === null) {
                    FactureFne::withoutGlobalScopes()->create($valeurs);
                    $compte['nouvelles']++;
                } else {
                    // Le lien au suivi est une décision : un relevé suivant ne l'efface pas.
                    $existante->fill(array_diff_key($valeurs, ['facture_fournisseur_id' => true]))->save();
                }

                $compte['factures']++;
                $compte['avoirs'] += $valeurs['est_avoir'] ? 1 : 0;
            }

            $releve->update(['factures' => $compte['factures'], 'nouvelles' => $compte['nouvelles']]);

            return $compte;
        });

        return $bilan + ['deja' => false, 'rapprochees' => self::rapprocher($entrepriseId)];
    }

    /**
     * Rapproche les factures FNE encore seules des lignes du suivi — **dans les deux sens** : on
     * l'appelle après un relevé, et après un dépôt du classeur de suivi.
     *
     * Une ligne du suivi confirme une facture FNE quand, chez le même fournisseur (même NCC n'est
     * pas dans le classeur : on compare le **nom**, à sa forme réduite), elle porte le même
     * numéro (au noyau de `NumeroDePiece`), ou à défaut la même date et le même montant TTC.
     * **Seul un candidat unique est retenu** : deux lignes possibles, c'est une question, pas
     * une réponse — elle reste « à porter » et se tranche à l'écran.
     *
     * @return int le nombre de factures rapprochées
     */
    public static function rapprocher(int $entrepriseId): int
    {
        $seules = FactureFne::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)->whereNull('facture_fournisseur_id')->get();

        if ($seules->isEmpty()) {
            return 0;
        }

        $prises = FactureFne::withoutGlobalScopes()->where('entreprise_id', $entrepriseId)
            ->whereNotNull('facture_fournisseur_id')->pluck('facture_fournisseur_id')->flip();

        $faites = self::rapprocherParLeNumeroFne($entrepriseId, $seules, $prises);
        $seules = $seules->whereNull('facture_fournisseur_id');

        // Les lignes du suivi des mêmes fournisseurs, lues une fois, en colonnes légères.
        $noms = $seules->map(fn ($f) => FormeComparable::nom($f->emetteur_nom))->filter()->unique();
        $lignes = FactureFournisseur::withoutGlobalScopes()->where('entreprise_id', $entrepriseId)
            ->get(['id', 'fournisseur', 'numero_piece', 'numero_facture_achat', 'date_facture', 'montant'])
            ->reject(fn ($l) => isset($prises[$l->id]))
            ->filter(fn ($l) => $noms->contains(FormeComparable::nom($l->fournisseur)))
            ->groupBy(fn ($l) => FormeComparable::nom($l->fournisseur));

        foreach ($seules as $facture) {
            $candidates = ($lignes[FormeComparable::nom($facture->emetteur_nom)] ?? collect())
                ->reject(fn ($l) => isset($prises[$l->id]));

            $parNumero = $candidates->filter(fn ($l) => NumeroDePiece::memePiece($facture->reference, $l->numero_piece)
                || NumeroDePiece::memePiece($facture->reference, $l->numero_facture_achat));

            $retenues = $parNumero->isNotEmpty() ? $parNumero : $candidates->filter(fn ($l) => $l->date_facture
                && $facture->date_facture
                && $l->date_facture->isSameDay($facture->date_facture)
                && abs(montant_de($l->montant) - montant_de($facture->montant_ttc)) <= 1);

            if ($retenues->count() === 1) {
                $ligne = $retenues->first();
                $facture->forceFill(['facture_fournisseur_id' => $ligne->id])->save();
                $prises[$ligne->id] = true;
                $faites++;
            }
        }

        return $faites;
    }

    /** Les colonnes du suivi où l'on a vu, ou pourrait voir, le n° FNE recopié à la main. */
    private const COLONNES_OU_CHERCHER_LE_NUMERO_FNE = [
        'numero_piece', 'numero_facture_achat', 'code_piece', 'numero_bc', 'numero_feb', 'numero_facture_vente',
        'numero_facture_client', 'observations', 'observations_facturation', 'commentaires', 'imputation', 'actions_a_mener',
    ];

    /**
     * **La correspondance par le n° FNE écrit dans le classeur — 18/10.** « Le système doit faire
     * une correspondance entre les importés et ceux venant de la FNE, car les numéros de la FNE se
     * trouvent sur certains importés. » La première règle exigeait le **même fournisseur** (au nom
     * réduit) : or le portail donne la raison sociale (« SOCIETE IVOIRIENNE DE PIECES SARL ») là où
     * le classeur écrit « SIPA » — aucun rapprochement ne se faisait (0 sur 2 570 le 16/10).
     *
     * Le n° FNE, lui, est unique sur toute la plateforme : le retrouver, entier, dans une case d'une
     * ligne du suivi suffit, quel que soit le nom du fournisseur. On le cherche mot à mot (lettres et
     * chiffres, au moins 8 caractères) dans les colonnes de numéros et de remarques. **Une seule
     * ligne doit le porter** : deux lignes qui citent le même n°, c'est une question, pas une réponse.
     *
     * @param  \Illuminate\Support\Collection<int, FactureFne>  $seules
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $prises  modifiée au passage
     */
    private static function rapprocherParLeNumeroFne(int $entrepriseId, $seules, &$prises): int
    {
        $parNumero = $seules->keyBy(fn (FactureFne $f) => self::motDuNumero($f->reference))->forget('');

        if ($parNumero->isEmpty()) {
            return 0;
        }

        $porteurs = [];

        FactureFournisseur::withoutGlobalScopes()->where('entreprise_id', $entrepriseId)
            ->select(['id', ...self::COLONNES_OU_CHERCHER_LE_NUMERO_FNE])
            ->chunkById(1000, function ($lignes) use (&$porteurs, $parNumero, $prises) {
                foreach ($lignes as $ligne) {
                    if (isset($prises[$ligne->id])) {
                        continue;
                    }

                    $texte = implode(' ', array_map(fn ($c) => (string) $ligne->{$c}, self::COLONNES_OU_CHERCHER_LE_NUMERO_FNE));
                    $mots = preg_split('/[^A-Z0-9]+/', mb_strtoupper($texte), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $mots[] = self::motDuNumero($texte);

                    foreach (array_unique($mots) as $mot) {
                        if (strlen($mot) >= 8 && $parNumero->has($mot)) {
                            $porteurs[$mot][$ligne->id] = true;
                        }
                    }
                }
            });

        $faites = 0;

        foreach ($porteurs as $mot => $lignes) {
            if (count($lignes) !== 1) {
                continue;
            }

            $ligneId = (int) array_key_first($lignes);
            $facture = $parNumero[$mot];

            if (isset($prises[$ligneId]) || $facture->facture_fournisseur_id !== null) {
                continue;
            }

            $facture->forceFill(['facture_fournisseur_id' => $ligneId])->save();
            $prises[$ligneId] = true;
            $faites++;
        }

        return $faites;
    }

    /** Un n° réduit à ses lettres et chiffres, en majuscules : « 2626-A 000 002 » → « 2626A000002 ». */
    private static function motDuNumero(?string $numero): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $numero));
    }

    /**
     * Porte au suivi une facture FNE qu'aucune ligne ne confirme — comme on porte une facture à
     * l'état des impayés. La ligne créée est la dette ; son règlement viendra par « Régler ».
     */
    public static function porter(FactureFne $facture, User $par): FactureFournisseur
    {
        if ($facture->facture_fournisseur_id !== null) {
            throw new RuntimeException('Cette facture est déjà au suivi.');
        }

        return DB::transaction(function () use ($facture, $par) {
            $piece = FactureFournisseur::create([
                'entreprise_id' => $facture->entreprise_id,
                'user_id' => $par->id,
                'fournisseur' => mb_substr($facture->emetteur_nom ?: 'Fournisseur NCC '.$facture->emetteur_ncc, 0, 160),
                'numero_piece' => $facture->reference,
                'nature_piece' => $facture->est_avoir ? 'Avoir FNE' : 'Facture FNE',
                'date_facture' => $facture->date_facture,
                'montant' => montant_de($facture->montant_ttc),
                'montant_ht' => montant_de($facture->montant_ht),
                'tva' => montant_de($facture->montant_tva),
                'montant_regle' => 0,
                'reste_a_payer' => montant_de($facture->montant_ttc),
                'mode_reglement' => $facture->moyen_paiement,
                'observations' => 'Portée depuis la FNE (NCC '.($facture->emetteur_ncc ?: '—').').',
                'source_rattachement' => 'fne',
            ]);

            $facture->forceFill(['facture_fournisseur_id' => $piece->id])->save();

            activity()->performedOn($piece)->causedBy($par)
                ->withProperties(['fne' => $facture->reference, 'montant' => $facture->montant_ttc])
                ->log('facture_fne_portee');

            return $piece;
        });
    }

    /**
     * Règle une facture du suivi, et garde le lettrage : le règlement désigne la facture qu'il
     * solde. Le réglé et le reste de la pièce suivent — le reste se déduit, il ne se saisit pas.
     */
    public static function regler(FactureFournisseur $piece, int|float $montant, string $date, ?string $mode, User $par): ReglementFournisseur
    {
        if ($montant === 0) {
            throw new RuntimeException('Un règlement de zéro ne règle rien.');
        }

        // Un avoir se « règle » en négatif : on récupère l'argent, on ne le verse pas.
        if (abs($montant) > abs(montant_de($piece->reste_a_payer)) || ($montant > 0) !== (montant_de($piece->reste_a_payer) > 0)) {
            throw new RuntimeException('Le montant dépasse ce qui reste dû sur cette pièce ('.montant_nombre(montant_de($piece->reste_a_payer)).' F).');
        }

        return DB::transaction(function () use ($piece, $montant, $date, $mode, $par) {
            $piece->forceFill([
                'montant_regle' => montant_de($piece->montant_regle) + $montant,
                'reste_a_payer' => montant_de($piece->reste_a_payer) - $montant,
                'date_reglement' => $date,
                'mode_reglement' => $mode ?: $piece->mode_reglement,
            ])->save();

            $reglement = ReglementFournisseur::create([
                'entreprise_id' => $piece->entreprise_id,
                'ville_id' => $piece->ville_id,
                'site_id' => $piece->site_id,
                'facture_fournisseur_id' => $piece->id,
                'saisi_par' => $par->id,
                'date_reglement' => $date,
                'code_reglement' => GenerateurNumero::suivant((int) $piece->entreprise_id, EtatDesFournisseurs::SERIE_REGLEMENT, $date),
                'fournisseur' => $piece->fournisseur,
                'mode_reglement' => $mode,
                'montant' => $montant,
                'source_rattachement' => 'saisie',
            ]);

            activity()->performedOn($piece)->causedBy($par)
                ->withProperties(['montant' => $montant, 'reglement' => $reglement->code_reglement])
                ->log('facture_fournisseur_reglee');

            return $reglement;
        });
    }

    /**
     * Les lignes du suivi d'un exercice qu'aucune facture FNE ne confirme — l'autre sens des
     * différences : saisies au suivi, absentes de la plateforme (non normalisées, ou pas encore
     * relevées).
     */
    public static function suiviSansFne(int $entrepriseId, int $exercice): \Illuminate\Database\Eloquent\Builder
    {
        $annee = DB::connection()->getDriverName() === 'sqlite'
            ? "cast(strftime('%Y', factures_fournisseurs.date_facture) as integer)"
            : 'year(factures_fournisseurs.date_facture)';

        return FactureFournisseur::query()
            ->whereRaw("{$annee} = ?", [$exercice])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('factures_fne')
                ->whereColumn('factures_fne.facture_fournisseur_id', 'factures_fournisseurs.id'));
    }

    // ------------------------------------------------------------------ la lecture d'une facture

    /** @return array<string, mixed> */
    private static function valeurs(array $brute, int $entrepriseId, int $releveId): array
    {
        $type = self::texte($brute['type'] ?? null, 40);
        $sousType = self::texte($brute['subtype'] ?? null, 40);
        $mots = mb_strtolower(($type ?? '').' '.($sousType ?? '').' '.($brute['invoiceType'] ?? ''));
        $estAvoir = collect(self::MOTS_AVOIR)->contains(fn ($m) => str_contains(str_replace([' ', '-'], '', $mots), $m))
            || (float) ($brute['totalAfterTaxes'] ?? 0) < 0;

        // Un avoir porte des montants négatifs, quel que soit le signe que le portail lui donne.
        $signe = fn ($v) => $estAvoir ? -abs(montant_de($v)) : montant_de($v);
        $date = self::date($brute['date'] ?? ($brute['createdAt'] ?? null));
        $emetteur = is_array($brute['company'] ?? null) ? $brute['company'] : [];

        $contenu = $brute;
        $contenu['company'] = array_intersect_key($emetteur, array_flip(['id', 'ncc', 'name', 'rccm']));

        return [
            'entreprise_id' => $entrepriseId,
            'releve_fne_id' => $releveId,
            'reference' => mb_substr(trim((string) $brute['reference']), 0, 80),
            'fne_id' => self::texte($brute['id'] ?? null, 80),
            'type' => $type,
            'sous_type' => $sousType,
            'est_avoir' => $estAvoir,
            'statut_portail' => self::texte($brute['status'] ?? null, 40),
            'date_facture' => $date,
            'exercice' => $date ? (int) substr($date, 0, 4) : null,
            'emetteur_ncc' => self::texte($emetteur['ncc'] ?? null, 40),
            'emetteur_nom' => self::texte($emetteur['name'] ?? null, 200),
            'montant_ht' => $signe($brute['totalBeforeTaxes'] ?? 0),
            'montant_tva' => $signe($brute['totalTaxes'] ?? 0),
            'timbre' => montant_de($brute['fiscalStamp'] ?? 0),
            'montant_ttc' => $signe($brute['totalAfterTaxes'] ?? ($brute['totalDue'] ?? 0)),
            'moyen_paiement' => self::texte($brute['paymentMethod'] ?? null, 60),
            'contenu' => $contenu,
        ];
    }

    private static function date(mixed $valeur): ?string
    {
        if (! is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        try {
            return Carbon::parse($valeur)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function texte(mixed $valeur, int $longueur): ?string
    {
        $texte = is_scalar($valeur) ? trim((string) $valeur) : '';

        return $texte === '' ? null : mb_substr($texte, 0, $longueur);
    }
}

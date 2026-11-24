<?php

namespace Modules\Noyau\Comptabilite\Services;

use App\Support\SchemaDisponible;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Comptabilite\Modeles\LigneComptable;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Tiers;
use Modules\Noyau\Exploitation\Services\CategorieDeSortie;
use Modules\Noyau\Exploitation\Services\PlanComptable;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;

/**
 * Le moteur des écritures comptables — 24/11.
 *
 * Il lit les opérations de l'application et en tire des écritures en partie double, sur les règles
 * d'imputation de SELFLOW reprises pour un atelier, et les range dans `operations_comptables`. Il
 * ne décide rien qu'une pièce ne dise : ce qui manque (la TVA d'une facture, la banque d'un chèque)
 * met l'opération « à vérifier », avec son motif, et elle ne part pas vers COMPTAFLOW.
 *
 * **Les principes, tranchés par le propriétaire le 24/11**
 * - Une pièce et son règlement sont deux opérations (règle de SELFLOW, § 0).
 * - On n'arrondit rien : « on écrit tel que c'est, la comptabilité ne fait que retracer ». Les
 *   montants sont comptés exactement (`Montants`) et l'équilibre se vérifie sans tolérance.
 * - La TVA ne se devine pas : une vente n'en porte que si elle a été **lue sur la facture**
 *   (`factures.montant_ht`, `montant_tva`) ; un achat, que si sa pièce ou sa FNE la donne.
 * - Le chiffre d'affaires : `Facture::scopeDuChiffreDAffaires`, la table du CA est complète.
 * - Les charges d'abord, et jamais deux fois — la règle de `ChargesDeLaPeriode` :
 *   - une pièce du suivi fournisseurs est la charge ; sa FNE en donne le détail certifié ;
 *   - une FNE qu'aucune pièce du suivi ne porte est une charge à elle seule ;
 *   - une sortie de caisse « fournisseurs » est un **règlement** (401 / caisse), pas une charge ;
 *   - une sortie administrative est une charge payée en caisse (6x / caisse) ;
 *   - un versement ou un transfert n'est pas une charge : virement de fonds (585) ;
 *   - une charge reprise de la caisse ne s'ajoute pas à sa sortie.
 * - Une opération n'est jamais réécrite après envoi : elle est contre-passée (sens inversé, même
 *   référence) et la nouvelle prend sa place. Un brouillon jamais envoyé se remplace simplement.
 */
final class GenerateurDEcritures
{
    /** @var array<string, array{numero: string, libelle: string, propose: bool}> */
    private array $plan;

    private TiersComptables $tiers;

    /** @var array<int, int|null> site => ville */
    private array $villeDuSite;

    /** @var array<string, string> « source:id » => clé du plan, pour les imputations validées */
    private array $imputations = [];

    /** @var array<string, int> compteurs du dernier passage */
    private array $bilan = ['creees' => 0, 'inchangees' => 0, 'remplacees' => 0, 'contrepassees' => 0, 'a_verifier' => 0];

    public function __construct(private readonly int $entrepriseId)
    {
        $this->plan = PlanComptable::pourLEntreprise($entrepriseId);
        $this->tiers = new TiersComptables($entrepriseId);
        $this->villeDuSite = DB::table('sites')->where('entreprise_id', $entrepriseId)->pluck('ville_id', 'id')
            ->map(fn ($v) => $v === null ? null : (int) $v)->all();
        if (SchemaDisponible::table('imputations_comptables')) {
            foreach (DB::table('imputations_comptables')->where('entreprise_id', $entrepriseId)->where('statut', 'validee')
                ->get(['source', 'source_id', 'cle_compte']) as $i) {
                $this->imputations[$i->source.':'.$i->source_id] = $i->cle_compte;
            }
        }
    }

    /** Le compte d'une imputation décidée par une personne pour cette source, s'il y en a une. */
    private function imputationDecidee(string $source, int $id): ?string
    {
        $cle = $this->imputations[$source.':'.$id] ?? null;

        return $cle ? ($this->plan[$cle]['numero'] ?? null) : null;
    }

    public static function disponible(): bool
    {
        return SchemaDisponible::table('operations_comptables') && SchemaDisponible::table('tiers_comptables');
    }

    /**
     * Génère les écritures de la période, source par source — les charges d'abord.
     *
     * @return array<string, int>
     */
    public function generer(string $debut, string $fin): array
    {
        $debut = Carbon::parse($debut)->toDateString();
        $fin = Carbon::parse($fin)->toDateString();

        $this->piecesDuSuivi($debut, $fin);
        $this->fneSeules($debut, $fin);
        $this->reglementsFournisseurs($debut, $fin);
        $this->sortiesEtEntreesDeCaisse($debut, $fin);
        $this->chargesSaisies($debut, $fin);
        $this->ventes($debut, $fin);
        $this->encaissements($debut, $fin);

        return $this->bilan;
    }

    // ── Les charges ────────────────────────────────────────────────────────────────────────

    /** Les pièces du suivi fournisseurs : la charge, au journal des achats. */
    private function piecesDuSuivi(string $debut, string $fin): void
    {
        $fne = SchemaDisponible::table('factures_fne')
            ? DB::table('factures_fne')->where('entreprise_id', $this->entrepriseId)->whereNotNull('facture_fournisseur_id')
                ->get(['facture_fournisseur_id', 'reference', 'emetteur_ncc', 'montant_ht', 'montant_tva', 'timbre', 'montant_ttc'])
                ->keyBy('facture_fournisseur_id')
            : collect();
        $assujettis = DB::table('referentiel_fournisseurs')->where('entreprise_id', $this->entrepriseId)
            ->pluck('assujetti_tva', 'nom_normalise');

        DB::table('factures_fournisseurs')->where('entreprise_id', $this->entrepriseId)
            ->whereBetween('date_facture', [$debut, $fin])->orderBy('id')
            ->chunk(500, function ($pieces) use ($fne, $assujettis) {
                foreach ($pieces as $p) {
                    $f = $fne[$p->id] ?? null;
                    $nom = Tiers::normaliser((string) $p->fournisseur);
                    $this->enregistrer($this->achat(
                        source: 'suivi', sourceId: (int) $p->id, date: $p->date_facture,
                        piece: $p->numero_piece ?: ($f->reference ?? 'FF-'.$p->id),
                        fournisseur: $p->fournisseur, ncc: $f->emetteur_ncc ?? null,
                        ttc: $f ? $f->montant_ttc : $p->montant,
                        ht: $f ? $f->montant_ht : $p->montant_ht,
                        tva: $f ? $f->montant_tva : $this->somme($p->tva, $p->tva_2),
                        timbre: $f->timbre ?? null,
                        // Un fournisseur déclaré non assujetti n'a pas de TVA à lire : sa pièce est sa charge.
                        sansTva: isset($assujettis[$nom]) && ! $assujettis[$nom],
                        compteCharge: $this->compteDeLImputation($p->imputation) ?? $this->compte('charge:Achats pièces'),
                        villeId: $p->ville_id, siteId: $p->site_id,
                        certifiee: $f !== null,
                    ));
                }
            });
    }

    /** Les FNE qu'aucune pièce du suivi ne porte : la facture certifiée est la charge (06/11). */
    private function fneSeules(string $debut, string $fin): void
    {
        if (! SchemaDisponible::table('factures_fne')) {
            return;
        }

        DB::table('factures_fne')->where('entreprise_id', $this->entrepriseId)->whereNull('facture_fournisseur_id')
            ->whereBetween('date_facture', [$debut, $fin])->orderBy('id')
            ->chunk(500, function ($factures) {
                foreach ($factures as $f) {
                    $signe = $f->est_avoir ? -1 : 1;
                    $this->enregistrer($this->achat(
                        source: 'fne', sourceId: (int) $f->id, date: $f->date_facture,
                        piece: $f->reference ?: 'FNE-'.$f->id,
                        fournisseur: $f->emetteur_nom, ncc: $f->emetteur_ncc,
                        ttc: $this->signer($f->montant_ttc, $signe), ht: $this->signer($f->montant_ht, $signe),
                        tva: $this->signer($f->montant_tva, $signe), timbre: $this->signer($f->timbre, $signe),
                        sansTva: false, compteCharge: $this->compte('charge:Achats pièces'),
                        villeId: null, siteId: null, certifiee: true,
                    ));
                }
            });
    }

    /**
     * Une facture d'achat : Débit 6x (HT) + 445x (TVA) + 646200 (timbre certifié) + 648000 (autres
     * taxes certifiées) / Crédit 401000 + tiers (TTC). Un avoir (TTC négatif) s'écrit à l'envers.
     */
    private function achat(string $source, int $sourceId, $date, string $piece, ?string $fournisseur, ?string $ncc,
        $ttc, $ht, $tva, $timbre, bool $sansTva, ?string $compteCharge, $villeId, $siteId, bool $certifiee): array
    {
        $tiers = $this->tiers->de(TiersComptables::FOURNISSEUR, $fournisseur, $ncc);
        $ttcU = Montants::unites($ttc);
        $sens = $ttcU < 0 ? -1 : 1;
        $ttcU = abs($ttcU);
        $htU = $ht === null ? null : abs(Montants::unites($ht));
        $tvaU = abs(Montants::unites($tva));
        $timbreU = abs(Montants::unites($timbre));
        $motif = null;
        $lignes = [];

        if ($sansTva && ! $certifiee) {
            $htU = $ttcU;
            $tvaU = 0;
        } elseif ($htU === null || $htU === 0) {
            $motif = $certifiee ? 'La FNE ne donne pas son montant hors taxes.' : 'TVA de la pièce à lire : le suivi ne donne pas le hors-taxes (fournisseur assujetti ou non renseigné).';
        }

        $autres = $htU === null ? 0 : $ttcU - $htU - $tvaU - $timbreU;
        if ($motif === null && $autres < 0) {
            $motif = 'Le hors-taxes, la TVA et le timbre dépassent le TTC de la pièce.';
        }
        if ($motif === null && $autres > 0 && ! $certifiee) {
            $motif = 'Le hors-taxes et la TVA de la pièce ne font pas son TTC.';
        }

        $libelle = mb_substr($piece.' / '.($fournisseur ?: 'Fournisseur divers'), 0, 255);
        if ($motif === null) {
            $lignes[] = $this->ligne($compteCharge, $htU, 0, $piece.' / Achat '.($fournisseur ?: ''), null);
            if ($tvaU > 0) {
                $lignes[] = $this->ligne($this->compteTvaDeductible($compteCharge), $tvaU, 0, $piece.' / TVA déductible', null);
            }
            if ($timbreU > 0) {
                $lignes[] = $this->ligne($this->compte('timbre_achats'), $timbreU, 0, $piece.' / Timbre', null);
            }
            if ($autres > 0) {
                $lignes[] = $this->ligne('648000', $autres, 0, $piece.' / Autres taxes', null);
            }
            $lignes[] = $this->ligne(TiersComptables::collectif(TiersComptables::FOURNISSEUR, $this->plan), 0, $ttcU, $piece.' / Facturation Achat', $tiers);
            if ($sens < 0) {
                $lignes = $this->inverser($lignes);
            }
        }

        return $this->projet($source, $sourceId, $sens < 0 ? 'AvoirAchat' : 'FactureAchat', $date,
            $this->compte('journal:achats'), $piece, $libelle, $villeId, $siteId, $lignes, $motif);
    }

    /** Les règlements fournisseurs du suivi : Débit 401000 + tiers / Crédit trésorerie. */
    private function reglementsFournisseurs(string $debut, string $fin): void
    {
        if (! SchemaDisponible::table('reglements_fournisseur')) {
            return;
        }

        $caisse = DB::table('mouvements_caisse')->where('entreprise_id', $this->entrepriseId)->pluck('ville_id', 'id');
        $pieces = DB::table('factures_fournisseurs')->where('entreprise_id', $this->entrepriseId)->pluck('numero_piece', 'id');

        DB::table('reglements_fournisseur')->where('entreprise_id', $this->entrepriseId)
            ->whereBetween('date_reglement', [$debut, $fin])->orderBy('id')
            ->chunk(500, function ($reglements) use ($caisse, $pieces) {
                foreach ($reglements as $r) {
                    $villeId = $r->mouvement_caisse_id ? ($caisse[$r->mouvement_caisse_id] ?? $r->ville_id) : $r->ville_id;
                    [$compte, $journal, $motif] = $r->mouvement_caisse_id
                        ? [...$this->caisse($villeId), null]
                        : $this->tresorerie($r->mode_reglement, null, $villeId ?? $this->villeDuSite[$r->site_id] ?? null);
                    $piece = $pieces[$r->facture_fournisseur_id] ?? null;
                    $this->enregistrer($this->reglement('reglement_fournisseur', (int) $r->id, 'ReglementAchat', $r->date_reglement,
                        $piece ?: ($r->code_reglement ?: 'RF-'.$r->id), $r->code_reglement, $r->montant,
                        $this->tiers->de(TiersComptables::FOURNISSEUR, $r->fournisseur), $compte, $journal, $motif, $villeId, $r->site_id));
                }
            });
    }

    /**
     * Le journal de caisse, sortie par sortie : charge administrative, règlement fournisseur, ou
     * virement de fonds — selon la catégorie que lit `CategorieDeSortie`, la même que les Charges.
     * Côté entrées, seuls les approvisionnements (virements de fonds) s'écrivent : les recettes des
     * clients sont portées par les encaissements, qu'on ne double pas.
     */
    private function sortiesEtEntreesDeCaisse(string $debut, string $fin): void
    {
        $payees = SchemaDisponible::colonne('reglements_fournisseur', 'mouvement_caisse_id')
            ? DB::table('reglements_fournisseur')->where('entreprise_id', $this->entrepriseId)->whereNotNull('mouvement_caisse_id')
                ->pluck('mouvement_caisse_id')->flip()
            : collect();
        $reprises = SchemaDisponible::colonne('charges', 'mouvement_caisse_id')
            ? DB::table('charges')->where('entreprise_id', $this->entrepriseId)->whereNotNull('mouvement_caisse_id')
                ->pluck('libelle', 'mouvement_caisse_id')
            : collect();

        DB::table('mouvements_caisse')->where('entreprise_id', $this->entrepriseId)
            ->whereBetween('date', [$debut, $fin])->orderBy('id')
            ->chunk(500, function ($mouvements) use ($payees, $reprises) {
                foreach ($mouvements as $m) {
                    $villeId = $m->ville_id ?? ($m->site_id ? ($this->villeDuSite[$m->site_id] ?? null) : null);
                    [$caisse, $journal] = $this->caisse($villeId);
                    $categorie = CategorieDeSortie::de($m->libelle, $m->motif, $m->beneficiaire);
                    $piece = $m->numero_piece ?: 'CAI-'.$m->id;
                    $texte = trim((string) ($m->libelle ?: $m->motif));

                    if ($m->sens === MouvementCaisse::ENTREE) {
                        if (CategorieDeSortie::estUneCharge($categorie)) {
                            continue;
                        }
                        $this->enregistrer($this->projet('caisse', (int) $m->id, 'VirementDeFonds', $m->date, $journal, $piece,
                            mb_substr('Approvisionnement caisse / '.$texte, 0, 255), $villeId, $m->site_id, [
                                $this->ligne($caisse, abs(Montants::unites($m->montant)), 0, $piece.' / '.$texte, null),
                                $this->ligne($this->compte('virements_internes'), 0, abs(Montants::unites($m->montant)), $piece.' / Virement de fonds', null),
                            ]));

                        continue;
                    }

                    if (isset($payees[$m->id])) {
                        continue; // le règlement fournisseur rattaché porte déjà cette sortie
                    }

                    $montant = abs(Montants::unites($m->montant));

                    if (! CategorieDeSortie::estUneCharge($categorie)) {
                        $this->enregistrer($this->projet('caisse', (int) $m->id, 'VirementDeFonds', $m->date, $journal, $piece,
                            mb_substr('Sortie de fonds / '.$texte, 0, 255), $villeId, $m->site_id, [
                                $this->ligne($this->compte('virements_internes'), $montant, 0, $piece.' / '.$texte, null),
                                $this->ligne($caisse, 0, $montant, $piece.' / Virement de fonds', null),
                            ]));
                    } elseif (CategorieDeSortie::volet($categorie) === CategorieDeSortie::FOURNISSEURS) {
                        $this->enregistrer($this->reglement('caisse', (int) $m->id, 'ReglementAchat', $m->date, $piece, null, $m->montant,
                            $this->tiers->de(TiersComptables::FOURNISSEUR, $m->beneficiaire), $caisse, $journal, null, $villeId, $m->site_id, $texte));
                    } else {
                        $repris = $reprises[$m->id] ?? null;
                        $compte = $this->imputationDecidee('caisse', (int) $m->id)
                            ?? ($repris ? ($this->plan['charge:'.$repris]['numero'] ?? null) : null)
                            ?? $this->compte('charge:'.$categorie) ?? $this->compte('charge:Autres');
                        $this->enregistrer($this->projet('caisse', (int) $m->id, 'ChargeCaisse', $m->date, $journal, $piece,
                            mb_substr($texte !== '' ? $texte : 'Sortie de caisse', 0, 255), $villeId, $m->site_id, [
                                $this->ligne($compte, $montant, 0, $piece.' / '.$texte, null),
                                $this->ligne($caisse, 0, $montant, $piece.' / Sortie de caisse', null),
                            ]));
                    }
                }
            });
    }

    /** Les charges saisies qui ne viennent pas de la caisse (une reprise compte par sa sortie). */
    private function chargesSaisies(string $debut, string $fin): void
    {
        $requete = DB::table('charges')->where('entreprise_id', $this->entrepriseId)->whereBetween('date', [$debut, $fin]);
        if (SchemaDisponible::colonne('charges', 'mouvement_caisse_id')) {
            $requete->whereNull('mouvement_caisse_id');
        }

        $requete->orderBy('id')->chunk(500, function ($charges) {
            foreach ($charges as $c) {
                $villeId = $c->site_id ? ($this->villeDuSite[$c->site_id] ?? null) : null;
                [$tresorerie, $journal, $motif] = $this->tresorerie($c->moyen, $c->banque_id, $villeId);
                $piece = $c->reference_origine ?: ($c->numero ?: 'CHG-'.$c->id);
                $montant = abs(Montants::unites($c->montant));
                $estVirement = $c->type_operation !== 'Charges';
                $compte = $estVirement ? $this->compte('virements_internes')
                    : ($this->imputationDecidee('charge', (int) $c->id) ?? $this->plan['charge:'.$c->libelle]['numero'] ?? $this->compte('charge:Autres'));
                $texte = trim($c->libelle.' '.($c->tiers ?? ''));

                $this->enregistrer($this->projet('charge', (int) $c->id, $estVirement ? 'VirementDeFonds' : 'ChargeSaisie', $c->date,
                    $journal, $piece, mb_substr($texte, 0, 255), $villeId, $c->site_id, $motif ? [] : [
                        $this->ligne($compte, $montant, 0, $piece.' / '.$texte, null),
                        $this->ligne($tresorerie, 0, $montant, $piece.' / '.($c->type_operation ?: 'Charge'), null),
                    ], $motif));
            }
        });
    }

    // ── Les ventes et leurs encaissements ─────────────────────────────────────────────────

    /**
     * Les factures du chiffre d'affaires : Débit 411000 + tiers client (TTC) / Crédit 706x (HT) et
     * 4432 (TVA lue sur la facture). Sans TVA lue, l'opération attend sa lecture.
     */
    private function ventes(string $debut, string $fin): void
    {
        $avecTva = SchemaDisponible::colonne('factures', 'montant_ht');

        Facture::withoutGlobalScopes()->duChiffreDAffaires()
            ->where('factures.entreprise_id', $this->entrepriseId)
            ->whereBetween('factures.date', [$debut, $fin])->orderBy('factures.id')->toBase()
            ->chunk(500, function ($factures) use ($avecTva) {
                foreach ($factures as $f) {
                    $ttc = Montants::unites($f->montant);
                    $sens = ($ttc < 0 || $f->est_avoir) ? -1 : 1;
                    $ttc = abs($ttc);
                    $ht = $avecTva && $f->montant_ht !== null ? abs(Montants::unites($f->montant_ht)) : null;
                    $tva = $avecTva && $f->montant_tva !== null ? abs(Montants::unites($f->montant_tva)) : null;
                    $piece = $f->n_facture ?: ($f->numero ?: 'FAC-'.$f->id);
                    $villeId = $f->ville_id ?? ($f->site_id ? ($this->villeDuSite[$f->site_id] ?? null) : null);
                    $compteVente = match ($f->activite) {
                        'Mécanique' => $this->compte('ventes_mecanique'),
                        'Sinistre' => $this->compte('ventes_sinistre'),
                        default => $this->compte('ventes_autres'),
                    };
                    $motif = match (true) {
                        $ht === null || $tva === null => 'TVA à lire sur la facture : le fichier du CA ne donne que le TTC.',
                        $ht + $tva !== $ttc => 'Le hors-taxes et la TVA lus ne font pas le TTC de la facture.',
                        default => null,
                    };
                    $tiers = $this->tiers->de(TiersComptables::CLIENT, $f->client);
                    $lignes = $motif ? [] : array_values(array_filter([
                        $this->ligne(TiersComptables::collectif(TiersComptables::CLIENT, $this->plan), $ttc, 0, $piece.' / Facturation Vente', $tiers),
                        $this->ligne($compteVente, 0, $ht, $piece.' / '.($f->activite ?: 'Prestation').($f->immatriculation ? ' '.$f->immatriculation : ''), null),
                        $tva > 0 ? $this->ligne($this->compteTvaCollectee($compteVente), 0, $tva, $piece.' / TVA Collectée Vente', null) : null,
                    ]));
                    if ($sens < 0) {
                        $lignes = $this->inverser($lignes);
                    }
                    $libelle = ($sens < 0 ? 'Avoir client ' : 'Facture ').$piece.' — '.($f->client ?: 'Client divers').($f->assureur ? ' (assureur '.$f->assureur.')' : '');

                    $this->enregistrer($this->projet('facture', (int) $f->id, $sens < 0 ? 'AvoirVente' : 'FactureVente', $f->date,
                        $this->compte('journal:ventes'), $piece, mb_substr($libelle, 0, 255), $villeId, $f->site_id, $lignes, $motif));
                }
            });
    }

    /** Les encaissements : Débit trésorerie / Crédit 411000 + tiers du client de la facture. */
    private function encaissements(string $debut, string $fin): void
    {
        $factures = DB::table('factures')->where('entreprise_id', $this->entrepriseId)->get(['id', 'client', 'n_facture', 'numero', 'ville_id', 'site_id'])->keyBy('id');

        DB::table('encaissements')->where('entreprise_id', $this->entrepriseId)
            ->whereBetween('date', [$debut, $fin])->orderBy('id')
            ->chunk(500, function ($encaissements) use ($factures) {
                foreach ($encaissements as $e) {
                    $f = $e->facture_id ? ($factures[$e->facture_id] ?? null) : null;
                    $villeId = $f?->ville_id ?? ($e->site_id ? ($this->villeDuSite[$e->site_id] ?? null) : null);
                    [$compte, $journal, $motif] = $this->tresorerie($e->moyen, $e->banque_id, $villeId);
                    $piece = $f ? ($f->n_facture ?: $f->numero) : ($e->reference_origine ?: ($e->numero ?: 'ENC-'.$e->id));
                    $client = $f?->client ?: ($e->client ?: $e->autres_tiers);

                    $this->enregistrer($this->reglement('encaissement', (int) $e->id, 'ReglementVente', $e->date, (string) $piece,
                        $e->reference_origine, $e->montant, $this->tiers->de(TiersComptables::CLIENT, $client), $compte, $journal, $motif,
                        $villeId, $e->site_id, $client));
                }
            });
    }

    // ── Briques ──────────────────────────────────────────────────────────────────────────

    /**
     * Un règlement : client → Débit trésorerie / Crédit 411 ; fournisseur → Débit 401 / Crédit
     * trésorerie. Un montant négatif (remboursement) s'écrit à l'envers. Libellé de SELFLOW :
     * « Rglt/{piece}/{reference}/Vente {…} ».
     */
    private function reglement(string $source, int $sourceId, string $nature, $date, string $piece, ?string $reference, $montant,
        $tiers, ?string $tresorerie, ?string $journal, ?string $motif, $villeId, $siteId, ?string $objet = null): array
    {
        $client = $nature === 'ReglementVente';
        // Le montant arrive toujours tel que lu en base : jamais déjà converti.
        $u = Montants::unites($montant);
        $libelle = mb_substr(implode('/', array_filter(['Rglt', $piece, $reference, ($client ? 'Vente ' : 'Achat ').($objet ?: $tiers->nom)])), 0, 255);
        $collectif = TiersComptables::collectif($client ? TiersComptables::CLIENT : TiersComptables::FOURNISSEUR, $this->plan);
        $lignes = $motif ? [] : ($client
            ? [$this->ligne($tresorerie, abs($u), 0, $libelle, null), $this->ligne($collectif, 0, abs($u), $libelle, $tiers)]
            : [$this->ligne($collectif, abs($u), 0, $libelle, $tiers), $this->ligne($tresorerie, 0, abs($u), $libelle, null)]);
        if ($u < 0) {
            $lignes = $this->inverser($lignes);
        }

        return $this->projet($source, $sourceId, $nature, $date, (string) $journal, $piece,
            $client ? 'Règlement client' : 'Règlement fournisseur', $villeId, $siteId, $lignes, $motif);
    }

    /**
     * Le compte et le journal de trésorerie d'un paiement — sans jamais présumer une banque
     * (guide COMPTAFLOW, § 3) : la banque dite, sinon la caisse de la ville pour des espèces, sinon
     * 5112 pour un chèque reçu sans banque ; un virement sans banque est à vérifier.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} compte, journal, motif
     */
    private function tresorerie(?string $moyen, $banqueId, $villeId): array
    {
        if ($banqueId && isset($this->plan['banque:'.$banqueId])) {
            return [$this->plan['banque:'.$banqueId]['numero'], $this->plan['journal:banque:'.$banqueId]['numero'], null];
        }

        $moyen = Tiers::normaliser((string) $moyen);

        return match (true) {
            str_starts_with($moyen, 'ESP') || $moyen === 'CAISSE' => [...$this->caisse($villeId), null],
            str_starts_with($moyen, 'CH') => [$this->compte('cheques_a_encaisser'), $this->compte('journal:cheques'), null],
            default => [null, $this->compte('journal:od'), 'Moyen de paiement « '.($moyen ?: 'non renseigné').' » : la banque ou la caisse est à préciser.'],
        };
    }

    /** @return array{0: string, 1: string} la caisse et le journal de la ville */
    private function caisse($villeId): array
    {
        if ($villeId && isset($this->plan['caisse:'.$villeId])) {
            return [$this->plan['caisse:'.$villeId]['numero'], $this->plan['journal:caisse:'.$villeId]['numero']];
        }

        return [$this->compte('caisse'), $this->compte('journal:caisse')];
    }

    private function compte(string $cle): ?string
    {
        return $this->plan[$cle]['numero'] ?? null;
    }

    /** Un numéro de compte écrit dans la colonne « imputation » du suivi (« 605100 », « 6011 »…). */
    private function compteDeLImputation(?string $imputation): ?string
    {
        return preg_match('/\b([1-8]\d{2,5})\b/', (string) $imputation, $m) ? PlanComptable::six($m[1]) : null;
    }

    /** La TVA déductible suit la racine de la charge (règle de SELFLOW, § 6.3). */
    private function compteTvaDeductible(?string $compteCharge): string
    {
        return match (true) {
            str_starts_with((string) $compteCharge, '60') => $this->compte('tva_deductible'),
            str_starts_with((string) $compteCharge, '61') => $this->compte('tva_deductible_transport'),
            default => $this->compte('tva_deductible_services'),
        };
    }

    /** La TVA collectée suit la racine du produit : 706 → services, sinon marchandises. */
    private function compteTvaCollectee(string $compteVente): string
    {
        return str_starts_with($compteVente, '706') ? $this->compte('tva_collectee_services') : $this->compte('tva_collectee');
    }

    private function ligne(?string $compte, int $debit, int $credit, string $libelle, $tiers): array
    {
        return [
            'compte' => $compte,
            'compte_tiers' => $tiers ? $this->tiers->numeroPourComptaflow($tiers) : null,
            'libelle' => mb_substr(trim($libelle, ' /'), 0, 255),
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    private function inverser(array $lignes): array
    {
        return array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $lignes);
    }

    /** La somme exacte de montants lus en base, rendue sous la même forme : un texte décimal. */
    private function somme(...$montants): ?string
    {
        if (array_filter($montants, fn ($m) => $m !== null) === []) {
            return null;
        }

        return Montants::texte(array_sum(array_map(fn ($m) => Montants::unites($m), $montants)));
    }

    private function signer($montant, int $signe): ?string
    {
        return $montant === null ? null : Montants::texte($signe * Montants::unites($montant));
    }

    /** Une opération à enregistrer, contrôlée : comptes à 6 chiffres, équilibre exact, centimes. */
    private function projet(string $source, int $sourceId, string $nature, $date, ?string $journal, string $reference,
        string $libelle, $villeId, $siteId, array $lignes, ?string $motif = null): array
    {
        $debit = array_sum(array_column($lignes, 'debit'));
        $credit = array_sum(array_column($lignes, 'credit'));

        if ($motif === null) {
            $motif = match (true) {
                $date === null => 'La pièce n’a pas de date.',
                $lignes === [] => 'Aucune ligne à écrire.',
                $journal === null || $journal === '' => 'Journal non paramétré.',
                collect($lignes)->contains(fn ($l) => ! preg_match('/^\d{6}$/', (string) $l['compte'])) => 'Un compte n’est pas paramétré sur 6 chiffres.',
                $debit !== $credit => 'Opération déséquilibrée : débit '.Montants::texte($debit).', crédit '.Montants::texte($credit).'.',
                collect($lignes)->contains(fn ($l) => Montants::auDelaDuCentime($l['debit'] + $l['credit'])) => 'Un montant a plus de deux décimales : COMPTAFLOW n’en garde que deux.',
                default => null,
            };
        }

        return compact('source', 'sourceId', 'nature', 'date', 'journal', 'reference', 'libelle', 'villeId', 'siteId', 'lignes', 'motif', 'debit', 'credit');
    }

    /**
     * Range une opération : inchangée si sa source n'a pas bougé ; remplacée si elle n'est jamais
     * partie ; contre-passée puis remplacée si COMPTAFLOW l'a déjà reçue.
     */
    private function enregistrer(array $p): void
    {
        $empreinte = hash('sha256', json_encode([$p['nature'], (string) $p['date'], $p['journal'], $p['reference'], $p['libelle'], $p['lignes'], $p['motif']]));

        $actuelle = OperationComptable::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)
            ->where('source', $p['source'])->where('source_id', $p['sourceId'])
            ->where('est_contrepassation', false)->where('statut', '!=', OperationComptable::CONTREPASSEE)->first();

        if ($actuelle && $actuelle->empreinte === $empreinte) {
            $this->bilan['inchangees']++;

            return;
        }

        DB::transaction(function () use ($p, $empreinte, $actuelle) {
            if ($actuelle) {
                if (in_array($actuelle->statut_deversement, ['non_envoyee', 'refusee'], true)) {
                    $actuelle->delete();
                    $this->bilan['remplacees']++;
                } else {
                    $this->contrepasser($actuelle);
                }
            }

            $this->creer($p, $empreinte);
        });
    }

    private function creer(array $p, string $empreinte): OperationComptable
    {
        $operation = OperationComptable::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entrepriseId,
            'numero_saisie' => $this->numeroSuivant(),
            'nature' => $p['nature'],
            'source' => $p['source'],
            'source_id' => $p['sourceId'],
            'empreinte' => $empreinte,
            'date_operation' => $p['date'] ?? now()->toDateString(),
            'code_journal' => (string) ($p['journal'] ?? ''),
            'reference_document' => mb_substr($p['reference'], 0, 100),
            'libelle' => $p['libelle'] !== '' ? $p['libelle'] : $p['nature'],
            'ville_id' => $p['villeId'],
            'site_id' => $p['siteId'],
            'total_debit' => Montants::texte($p['debit']),
            'total_credit' => Montants::texte($p['credit']),
            'statut' => $p['motif'] === null ? OperationComptable::PRETE : OperationComptable::A_VERIFIER,
            'motif' => $p['motif'],
        ]);

        foreach (array_values($p['lignes']) as $rang => $l) {
            LigneComptable::create([
                'operation_id' => $operation->id, 'entreprise_id' => $this->entrepriseId, 'rang' => $rang + 1,
                'compte' => (string) $l['compte'], 'compte_tiers' => $l['compte_tiers'], 'libelle' => $l['libelle'] ?: $p['libelle'],
                'debit' => Montants::texte($l['debit']), 'credit' => Montants::texte($l['credit']),
            ]);
        }

        $this->bilan['creees']++;
        if ($p['motif'] !== null) {
            $this->bilan['a_verifier']++;
        }

        return $operation;
    }

    /** Annule une opération déjà transmise : mêmes lignes, sens inversé, même référence. */
    public function contrepasser(OperationComptable $operation): OperationComptable
    {
        $inverse = OperationComptable::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entrepriseId,
            'numero_saisie' => $this->numeroSuivant(),
            'nature' => 'ContrePassation',
            'source' => $operation->source,
            'source_id' => $operation->source_id,
            'empreinte' => hash('sha256', 'contrepassation:'.$operation->id),
            'date_operation' => $operation->date_operation,
            'code_journal' => $operation->code_journal,
            'reference_document' => $operation->reference_document,
            'libelle' => mb_substr('Contre-passation '.$operation->libelle.' — saisie '.$operation->numero_saisie, 0, 255),
            'ville_id' => $operation->ville_id,
            'site_id' => $operation->site_id,
            'total_debit' => $operation->total_credit,
            'total_credit' => $operation->total_debit,
            'statut' => OperationComptable::PRETE,
            'est_contrepassation' => true,
            'contrepasse_operation_id' => $operation->id,
        ]);

        foreach ($operation->lignes as $l) {
            LigneComptable::create([
                'operation_id' => $inverse->id, 'entreprise_id' => $this->entrepriseId, 'rang' => $l->rang,
                'compte' => $l->compte, 'compte_tiers' => $l->compte_tiers, 'libelle' => mb_substr('Contre-passation '.$l->libelle, 0, 255),
                'debit' => $l->credit, 'credit' => $l->debit,
            ]);
        }

        $operation->forceFill(['statut' => OperationComptable::CONTREPASSEE])->save();
        $this->bilan['contrepassees']++;

        return $inverse;
    }

    private function numeroSuivant(): int
    {
        return (int) OperationComptable::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)
            ->lockForUpdate()->max('numero_saisie') + 1;
    }
}

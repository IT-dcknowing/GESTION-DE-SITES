<?php

namespace Modules\Noyau\Comptabilite\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Exploitation\Modeles\LiaisonComptaflow;
use Modules\Noyau\Exploitation\Services\ComptaflowClient;
use Modules\Noyau\Exploitation\Services\DebitComptaflowDepasse;
use Modules\Noyau\Exploitation\Services\PlanComptable;
use Throwable;

/**
 * Le déversement vers COMPTAFLOW — 24/11, sur le contrat des documents 01 et 02 de COMPTAFLOW.
 *
 * 1. Le référentiel d'abord : les comptes, journaux et tiers que les opérations à envoyer
 *    utilisent. COMPTAFLOW ne réécrit rien de ce qu'il a déjà ; un tiers déjà associé à
 *    COMPTAFLOW n'est pas renvoyé : son numéro vient de lui.
 * 2. Puis chaque opération prête, entière et atomique, avec ses bornes d'exercice. Une clé stable
 *    par ligne (`L{id}`) et par opération (`OP{id}`) rend le rejeu sans doublon. COMPTAFLOW
 *    préfixe la clé de ligne de « GESTION-DE-SITES-{entreprise} » ; il garde en revanche
 *    `operation_source` tel quel dans `operation_selflow` (ExternalSyncController, ligne 623) :
 *    c'est donc « OP{id} », exactement, que le justificatif doit nommer.
 * 3. Enfin le PDF justificatif des opérations acceptées qui en ont un (`PiecesJustificatives`) :
 *    COMPTAFLOW le rattache aux lignes déjà reçues, il ne peut donc partir qu'après elles.
 *
 * Une opération « à vérifier » ou déjà acceptée ne part pas. Un refus garde son message et se
 * retente au passage suivant, cinq fois au plus.
 *
 * **Le débit de COMPTAFLOW** (constat dans son code, 25/11) : `throttle:60,1` sur les écritures,
 * `throttle:20,1` sur les justificatifs, et un compteur **commun** à ces routes pour une même IP.
 * Les appels sont donc espacés (un peu plus d'une seconde) ; les justificatifs attendent que la
 * minute soit passée et partent à dix-huit au plus par passage. Un 429 arrête le passage sans
 * compter d'essai : ce n'est pas un refus de l'opération.
 */
final class DeversementComptable
{
    public const ESSAIS_MAX = 5;

    /** Entre deux appels : 60 par minute au plus, le référentiel compris. */
    public const INTERVALLE_MS = 1050;

    /** Les justificatifs : 20 par minute au plus chez COMPTAFLOW, sur le même compteur. */
    public const JUSTIFICATIFS_PAR_PASSAGE = 18;

    public const INTERVALLE_JUSTIFICATIF_MS = 3100;

    /** La fenêtre du compteur de COMPTAFLOW, plus une seconde. */
    public const FENETRE_MS = 61000;

    private int $appels = 0;

    public function __construct(private readonly int $entrepriseId, private readonly ComptaflowClient $client = new ComptaflowClient) {}

    /** @return array{envoyees: int, acceptees: int, refusees: int, justificatifs: int, message: ?string} */
    public function deverser(?int $limite = 200): array
    {
        $bilan = ['envoyees' => 0, 'acceptees' => 0, 'refusees' => 0, 'justificatifs' => 0, 'message' => null];

        $liaison = LiaisonComptaflow::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)->first();
        if (! $liaison || $liaison->statut !== 'active') {
            return ['message' => 'Aucune liaison COMPTAFLOW active pour cette entreprise.'] + $bilan;
        }

        $operations = OperationComptable::withoutGlobalScopes()->with('lignes')
            ->where('entreprise_id', $this->entrepriseId)
            ->where('statut', '!=', OperationComptable::A_VERIFIER)
            ->whereIn('statut_deversement', ['non_envoyee', 'refusee'])
            ->where('essais', '<', self::ESSAIS_MAX)
            // Une opération contre-passée n'est envoyée que si elle était déjà partie : sinon elle a été remplacée.
            ->orderBy('numero_saisie')->limit($limite)->get();

        $derniere = null;

        if ($operations->isNotEmpty()) {
            try {
                $this->espacer(self::INTERVALLE_MS);
                $this->client->deverserReferentiel($liaison, ...$this->referentiel($operations));
            } catch (Throwable $e) {
                return ['message' => 'Référentiel refusé par COMPTAFLOW : '.$e->getMessage()] + $bilan;
            }

            foreach ($operations as $operation) {
                $avant = $operation->only(['statut_deversement', 'essais']);
                $operation->forceFill(['statut_deversement' => 'envoyee', 'essais' => $operation->essais + 1])->save();
                $annee = $operation->date_operation->year;

                try {
                    $this->espacer(self::INTERVALLE_MS);
                    $reponse = $this->client->deverser($liaison, 'OP'.$operation->id, $this->ecritures($operation),
                        ['debut' => $annee.'-01-01', 'fin' => $annee.'-12-31']);
                    $refus = (array) ($reponse['refus'] ?? []);
                    $operation->forceFill($refus === []
                        ? ['statut_deversement' => 'acceptee', 'message_deversement' => null, 'deversee_le' => now()]
                        : ['statut_deversement' => 'refusee', 'message_deversement' => mb_substr(implode(' ; ', $refus), 0, 2000)])->save();
                    $refus === [] ? $bilan['acceptees']++ : $bilan['refusees']++;
                } catch (DebitComptaflowDepasse $e) {
                    // Rien n'est arrivé chez COMPTAFLOW : l'opération reprend son état, sans essai consommé.
                    $operation->forceFill($avant)->save();
                    $bilan['message'] = $e->getMessage();

                    break;
                } catch (Throwable $e) {
                    $operation->forceFill(['statut_deversement' => 'refusee', 'message_deversement' => mb_substr($e->getMessage(), 0, 2000)])->save();
                    $bilan['refusees']++;
                }
                $bilan['envoyees']++;
                $derniere = $operation;
            }
        }

        if ($bilan['message'] === null) {
            $bilan['justificatifs'] = $this->envoyerLesJustificatifs($liaison, $bilan['message']);
        }

        if ($operations->isEmpty() && $bilan['justificatifs'] === 0 && $bilan['message'] === null) {
            return ['message' => 'Rien à déverser.'] + $bilan;
        }

        $liaison->forceFill(array_filter([
            'dernier_deversement_le' => now(),
            'dernier_deversement_id' => $derniere ? 'OP'.$derniere->id : null,
            'dernier_message' => mb_substr($bilan['acceptees'].' acceptée(s), '.$bilan['refusees'].' refusée(s)'
                .($bilan['justificatifs'] ? ', '.$bilan['justificatifs'].' justificatif(s)' : '')
                .($bilan['message'] ? ' — '.$bilan['message'] : ''), 0, 2000),
        ], fn ($v) => $v !== null))->save();

        return $bilan;
    }

    /**
     * Les PDF des opérations acceptées qui n'ont pas encore le leur chez COMPTAFLOW.
     *
     * 201 : rattaché ; 200 : le même y était déjà — envoyés tous deux. 409 : une autre pièce y est
     * déjà ; le même fichier ne se renvoie pas (son empreinte est gardée), seul un autre PDF joint
     * relance l'envoi. 404 : COMPTAFLOW n'a pas encore les lignes, on réessaiera.
     */
    private function envoyerLesJustificatifs(LiaisonComptaflow $liaison, ?string &$message): int
    {
        if (! PiecesJustificatives::disponible()) {
            return 0;
        }

        $candidates = OperationComptable::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('statut_deversement', 'acceptee')
            ->whereIn('source', PiecesJustificatives::SOURCES)
            ->whereNull('justificatif_envoye_le')
            ->orderBy('numero_saisie')->limit(500)->get();

        $envoyes = 0;
        $tentes = 0;

        foreach ($candidates as $operation) {
            $pdf = PiecesJustificatives::pdf($operation);
            if ($pdf === null || $operation->justificatif_sha256 === $pdf['sha256']) {
                continue;
            }
            if ($tentes >= self::JUSTIFICATIFS_PAR_PASSAGE) {
                break;
            }

            // Le premier attend que la minute des écritures soit passée : le compteur est commun.
            $this->espacer($tentes === 0 ? self::FENETRE_MS : self::INTERVALLE_JUSTIFICATIF_MS);
            $tentes++;

            try {
                $reponse = $this->client->deverserJustificatif($liaison, 'OP'.$operation->id, $pdf['nom'], (string) file_get_contents($pdf['chemin']));
            } catch (DebitComptaflowDepasse $e) {
                $message = $e->getMessage();

                break;
            } catch (Throwable $e) {
                $operation->forceFill(['justificatif_message' => mb_substr($e->getMessage(), 0, 2000)])->save();

                continue;
            }

            $texte = (string) ($reponse['corps']['message'] ?? '');
            $operation->forceFill(match (true) {
                in_array($reponse['statut'], [200, 201], true) => ['justificatif_envoye_le' => now(), 'justificatif_sha256' => $pdf['sha256'], 'justificatif_message' => null],
                $reponse['statut'] === 409 => ['justificatif_sha256' => $pdf['sha256'], 'justificatif_message' => mb_substr('COMPTAFLOW (409) : '.$texte, 0, 2000)],
                default => ['justificatif_message' => mb_substr('COMPTAFLOW ('.$reponse['statut'].') : '.$texte, 0, 2000)],
            })->save();

            if (in_array($reponse['statut'], [200, 201], true)) {
                $envoyes++;
            }
        }

        return $envoyes;
    }

    /** Laisse passer le temps voulu entre deux appels — `Sleep` se simule dans les épreuves. */
    private function espacer(int $millisecondes): void
    {
        if ($this->appels > 0) {
            Sleep::for($millisecondes)->milliseconds();
        }
        $this->appels++;
    }

    /** Les lignes au format JSON de COMPTAFLOW : un compte par ligne, le tiers à part. */
    public function ecritures(OperationComptable $operation): array
    {
        return $operation->lignes->map(function ($l) use ($operation) {
            $debit = Montants::unites($l->debit);
            $credit = Montants::unites($l->credit);

            return [
                'date_ecriture' => $operation->date_operation->toDateString(),
                'libelle' => $l->libelle,
                'reference_document' => $operation->reference_document,
                'code_journal' => $operation->code_journal,
                'compte_debit' => $debit > 0 ? $l->compte : null,
                'compte_credit' => $credit > 0 ? $l->compte : null,
                'debit' => Montants::pourEnvoi($debit),
                'credit' => Montants::pourEnvoi($credit),
                'compte_tiers' => $l->compte_tiers,
                'cle_source' => 'L'.$l->id,
                'operation_source' => 'OP'.$operation->id,
            ];
        })->values()->all();
    }

    /**
     * Le référentiel utilisé par ces opérations : plan, journaux, tiers.
     *
     * @return array{0: list<array>, 1: list<array>, 2: list<array>}
     */
    public function referentiel($operations): array
    {
        $plan = PlanComptable::pourLEntreprise($this->entrepriseId);
        $intitules = [];
        foreach ($plan as $cle => $p) {
            if (PlanComptable::type($cle) === 'compte') {
                $intitules[$p['numero']] ??= $p['libelle'];
            }
        }
        $journaux = [];
        foreach ($plan as $cle => $p) {
            if (PlanComptable::type($cle) === 'journal') {
                $tresorerie = str_starts_with($cle, 'journal:caisse') || str_starts_with($cle, 'journal:banque') || $cle === 'journal:cheques';
                $compte = match (true) {
                    str_starts_with($cle, 'journal:caisse:') => $plan['caisse:'.substr($cle, 15)]['numero'] ?? null,
                    $cle === 'journal:caisse' => $plan['caisse']['numero'] ?? null,
                    str_starts_with($cle, 'journal:banque:') => $plan['banque:'.substr($cle, 15)]['numero'] ?? null,
                    $cle === 'journal:cheques' => $plan['cheques_a_encaisser']['numero'] ?? null,
                    default => null,
                };
                $journaux[$p['numero']] ??= [
                    'code_journal' => $p['numero'],
                    'intitule' => $p['libelle'],
                    'type' => match (true) {
                        $cle === 'journal:ventes' => 'Ventes',
                        $cle === 'journal:achats' => 'Achats',
                        $tresorerie => 'Trésorerie',
                        default => 'Opérations Diverses',
                    },
                    'compte_numero' => $compte,
                ];
            }
        }

        $comptes = [];
        $codes = [];
        $numerosTiers = [];
        foreach ($operations as $operation) {
            $codes[$operation->code_journal] = true;
            foreach ($operation->lignes as $l) {
                $comptes[$l->compte] = true;
                if ($l->compte_tiers) {
                    $numerosTiers[$l->compte_tiers] = true;
                }
            }
        }

        $planComptable = collect(array_keys($comptes))->map(fn ($n) => [
            'numero_de_compte' => (string) $n,
            'intitule' => $intitules[$n] ?? 'Compte '.$n,
        ])->values()->all();

        $codesJournaux = collect(array_keys($codes))->map(fn ($c) => $journaux[$c] ?? [
            'code_journal' => (string) $c, 'intitule' => (string) $c, 'type' => 'Opérations Diverses', 'compte_numero' => null,
        ])->values()->all();

        // Un tiers dont le numéro vient de COMPTAFLOW n'est pas dans `tiers_comptables` : il n'est pas renvoyé.
        $tiers = DB::table('tiers_comptables')->where('entreprise_id', $this->entrepriseId)
            ->whereIn('numero', array_keys($numerosTiers))->get()
            ->map(fn ($t) => [
                'numero_de_tiers' => $t->numero,
                'intitule' => $t->nom,
                'compte_general' => $t->compte_general,
                'type_de_tiers' => $t->nature,
                'informations' => array_filter(['ncc' => $t->ncc]),
            ])->values()->all();

        return [$planComptable, $codesJournaux, $tiers];
    }
}

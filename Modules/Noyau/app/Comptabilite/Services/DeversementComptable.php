<?php

namespace Modules\Noyau\Comptabilite\Services;

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Exploitation\Modeles\LiaisonComptaflow;
use Modules\Noyau\Exploitation\Services\ComptaflowClient;
use Modules\Noyau\Exploitation\Services\PlanComptable;
use Throwable;

/**
 * Le déversement vers COMPTAFLOW — 24/11, sur le contrat des documents 01 et 02 de COMPTAFLOW.
 *
 * 1. Le référentiel d'abord : les comptes, journaux et tiers que les opérations à envoyer
 *    utilisent. COMPTAFLOW ne réécrit rien de ce qu'il a déjà ; un tiers déjà associé à
 *    COMPTAFLOW n'est pas renvoyé : son numéro vient de lui.
 * 2. Puis chaque opération prête, entière et atomique, avec ses bornes d'exercice. Une clé stable
 *    par ligne (`L{id}`) et par opération (`OP{id}`) rend le rejeu sans doublon ; COMPTAFLOW les
 *    préfixe de « GESTION-DE-SITES-{entreprise} ».
 *
 * Une opération « à vérifier » ou déjà acceptée ne part pas. Un refus garde son message et se
 * retente au passage suivant, cinq fois au plus.
 */
final class DeversementComptable
{
    public const ESSAIS_MAX = 5;

    public function __construct(private readonly int $entrepriseId, private readonly ComptaflowClient $client = new ComptaflowClient) {}

    /** @return array{envoyees: int, acceptees: int, refusees: int, message: ?string} */
    public function deverser(?int $limite = 200): array
    {
        $liaison = LiaisonComptaflow::withoutGlobalScopes()->where('entreprise_id', $this->entrepriseId)->first();
        if (! $liaison || $liaison->statut !== 'active') {
            return ['envoyees' => 0, 'acceptees' => 0, 'refusees' => 0, 'message' => 'Aucune liaison COMPTAFLOW active pour cette entreprise.'];
        }

        $operations = OperationComptable::withoutGlobalScopes()->with('lignes')
            ->where('entreprise_id', $this->entrepriseId)
            ->where('statut', '!=', OperationComptable::A_VERIFIER)
            ->whereIn('statut_deversement', ['non_envoyee', 'refusee'])
            ->where('essais', '<', self::ESSAIS_MAX)
            // Une opération contre-passée n'est envoyée que si elle était déjà partie : sinon elle a été remplacée.
            ->orderBy('numero_saisie')->limit($limite)->get();

        if ($operations->isEmpty()) {
            return ['envoyees' => 0, 'acceptees' => 0, 'refusees' => 0, 'message' => 'Rien à déverser.'];
        }

        try {
            $this->client->deverserReferentiel($liaison, ...$this->referentiel($operations));
        } catch (Throwable $e) {
            return ['envoyees' => 0, 'acceptees' => 0, 'refusees' => 0, 'message' => 'Référentiel refusé par COMPTAFLOW : '.$e->getMessage()];
        }

        $bilan = ['envoyees' => 0, 'acceptees' => 0, 'refusees' => 0, 'message' => null];

        foreach ($operations as $operation) {
            $bilan['envoyees']++;
            $operation->forceFill(['statut_deversement' => 'envoyee', 'essais' => $operation->essais + 1])->save();
            $annee = $operation->date_operation->year;

            try {
                $reponse = $this->client->deverser($liaison, 'OP'.$operation->id, $this->ecritures($operation),
                    ['debut' => $annee.'-01-01', 'fin' => $annee.'-12-31']);
                $refus = (array) ($reponse['refus'] ?? []);
                $operation->forceFill($refus === []
                    ? ['statut_deversement' => 'acceptee', 'message_deversement' => null, 'deversee_le' => now()]
                    : ['statut_deversement' => 'refusee', 'message_deversement' => mb_substr(implode(' ; ', $refus), 0, 2000)])->save();
                $refus === [] ? $bilan['acceptees']++ : $bilan['refusees']++;
            } catch (Throwable $e) {
                $operation->forceFill(['statut_deversement' => 'refusee', 'message_deversement' => mb_substr($e->getMessage(), 0, 2000)])->save();
                $bilan['refusees']++;
            }
        }

        $derniere = $operations->last();
        $liaison->forceFill(['dernier_deversement_le' => now(), 'dernier_deversement_id' => 'OP'.$derniere->id,
            'dernier_message' => $bilan['acceptees'].' acceptée(s), '.$bilan['refusees'].' refusée(s)'])->save();

        return $bilan;
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

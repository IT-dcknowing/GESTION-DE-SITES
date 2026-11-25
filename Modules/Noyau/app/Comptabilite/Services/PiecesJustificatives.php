<?php

namespace Modules\Noyau\Comptabilite\Services;

use App\Models\User;
use App\Support\SchemaDisponible;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Imports\Modeles\FactureFne;
use RuntimeException;

/**
 * La pièce justificative d'une opération — 25/11.
 *
 * Ce qui existe réellement, vérifié avant d'écrire ce service : **aucun PDF** n'est gardé par
 * l'application. La facture de vente est une ligne du fichier du CA ; la pièce du suivi
 * fournisseurs, une ligne de classeur ; la FNE, une entrée du relevé JSON du portail, qui ne
 * porte que son lien de vérification sur le site de la DGI (`FactureFne::urlDeVerification`).
 *
 * D'où deux sortes de justificatif, jamais confondues :
 * - le **PDF joint** à la pièce (`justificatifs_comptables`), le seul qui part vers COMPTAFLOW ;
 * - le **lien du portail FNE**, qui s'ouvre depuis l'écran mais n'est pas un fichier : il ne
 *   s'envoie pas, COMPTAFLOW n'accepte qu'un PDF dont il vérifie l'empreinte.
 *
 * Le justificatif appartient à la pièce (source + identifiant), pas à l'opération : une
 * opération contre-passée puis remplacée garde le même document. Une pièce du suivi confirmée par
 * une FNE prend le PDF de l'une ou de l'autre.
 */
final class PiecesJustificatives
{
    /** Les sources qui sont des pièces — un règlement ou une sortie de caisse n'en a pas ici. */
    public const SOURCES = ['facture', 'suivi', 'fne'];

    /** La limite de COMPTAFLOW (`ExternalJustificatifController::TAILLE_MAX_OCTETS`) : 10 Mo. */
    public const TAILLE_MAX_KO = 10240;

    public static function disponible(): bool
    {
        return SchemaDisponible::table('justificatifs_comptables')
            && SchemaDisponible::colonne('operations_comptables', 'justificatif_envoye_le');
    }

    /**
     * Le PDF de l'opération, s'il existe **sur le disque** et commence bien par `%PDF-`.
     *
     * @return array{chemin: string, nom: string, sha256: string}|null
     */
    public static function pdf(OperationComptable $operation): ?array
    {
        if (! self::disponible() || ! in_array($operation->source, self::SOURCES, true)) {
            return null;
        }

        foreach (self::piecesDe($operation->entreprise_id, $operation->source, (int) $operation->source_id) as [$source, $id]) {
            $ligne = DB::table('justificatifs_comptables')->where('entreprise_id', $operation->entreprise_id)
                ->where('source', $source)->where('source_id', $id)->first();
            $absolu = $ligne ? self::cheminAbsolu($ligne->chemin) : null;

            if ($absolu !== null) {
                return ['chemin' => $absolu, 'nom' => $ligne->nom_original, 'sha256' => $ligne->sha256];
            }
        }

        return null;
    }

    /** Le lien de vérification de la FNE de la pièce, sur le portail de la DGI. */
    public static function lienDuPortail(OperationComptable $operation): ?string
    {
        $fne = match ($operation->source) {
            'fne' => FactureFne::query()->withoutGlobalScopes()->where('entreprise_id', $operation->entreprise_id)->find($operation->source_id),
            'suivi' => FactureFne::query()->withoutGlobalScopes()->where('entreprise_id', $operation->entreprise_id)
                ->where('facture_fournisseur_id', $operation->source_id)->first(),
            default => null,
        };

        return $fne?->urlDeVerification();
    }

    /**
     * Ce que l'écran affiche pour une page d'opérations, en deux requêtes : opération => PDF joint
     * (vrai/faux) et lien du portail.
     *
     * @param  Collection<int, OperationComptable>  $operations
     * @return array<int, array{pdf: bool, portail: bool}>
     */
    public static function pourLEcran(Collection $operations): array
    {
        $etat = [];
        $pieces = $operations->filter(fn ($o) => in_array($o->source, self::SOURCES, true));

        if ($pieces->isEmpty()) {
            return $etat;
        }

        $entreprise = (int) $pieces->first()->entreprise_id;
        $suivis = $pieces->where('source', 'suivi')->pluck('source_id')->map(fn ($v) => (int) $v)->all();
        $fnes = $pieces->where('source', 'fne')->pluck('source_id')->map(fn ($v) => (int) $v)->all();

        // Les FNE des pièces de la page, et dans les deux sens le lien suivi ↔ FNE.
        $fneLues = DB::table('factures_fne')->where('entreprise_id', $entreprise)
            ->where(fn ($q) => $q->whereIn('id', $fnes ?: [0])->orWhereIn('facture_fournisseur_id', $suivis ?: [0]))
            ->get(['id', 'facture_fournisseur_id', 'contenu']);
        $avecLien = fn ($f) => trim((string) ((json_decode((string) $f->contenu, true) ?: [])['token'] ?? '')) !== '';

        $joints = self::disponible()
            ? DB::table('justificatifs_comptables')->where('entreprise_id', $entreprise)
                ->whereIn('source', self::SOURCES)
                ->whereIn('source_id', $pieces->pluck('source_id')->merge($fneLues->pluck('id'))->merge($fneLues->pluck('facture_fournisseur_id'))->filter()->unique()->values()->all())
                ->get(['source', 'source_id', 'chemin'])
                ->filter(fn ($j) => self::cheminAbsolu($j->chemin) !== null)
                ->map(fn ($j) => $j->source.':'.$j->source_id)->flip()
            : collect();

        foreach ($pieces as $o) {
            $liees = match ($o->source) {
                'suivi' => $fneLues->where('facture_fournisseur_id', $o->source_id),
                'fne' => $fneLues->where('id', $o->source_id),
                default => collect(),
            };
            $cles = [$o->source.':'.$o->source_id];
            foreach ($liees as $f) {
                $cles[] = 'fne:'.$f->id;
                if ($f->facture_fournisseur_id) {
                    $cles[] = 'suivi:'.$f->facture_fournisseur_id;
                }
            }

            $etat[$o->id] = [
                'pdf' => collect($cles)->contains(fn ($c) => $joints->has($c)),
                'portail' => $liees->contains($avecLien),
            ];
        }

        return $etat;
    }

    /**
     * Joint le PDF d'une pièce. Un PDF déjà transmis à COMPTAFLOW ne se remplace plus : COMPTAFLOW
     * refuserait l'autre (409) et les deux applications ne montreraient pas le même document.
     */
    public static function deposer(OperationComptable $operation, UploadedFile $fichier, User $auteur): void
    {
        if (! self::disponible()) {
            throw new RuntimeException("La mise à jour du serveur n'est pas encore passée (migration du 25/11).");
        }
        if (! in_array($operation->source, self::SOURCES, true)) {
            throw new RuntimeException('Cette opération n’est pas une pièce : un règlement ou une sortie de caisse ne porte pas de justificatif ici.');
        }

        $contenu = (string) file_get_contents($fichier->getRealPath());
        if (! str_starts_with($contenu, '%PDF-')) {
            throw new RuntimeException('Le fichier n’est pas un PDF.');
        }

        $dejaEnvoye = OperationComptable::withoutGlobalScopes()->where('entreprise_id', $operation->entreprise_id)
            ->where('source', $operation->source)->where('source_id', $operation->source_id)
            ->whereNotNull('justificatif_envoye_le')->exists();
        if ($dejaEnvoye) {
            throw new RuntimeException('Le PDF de cette pièce est déjà chez COMPTAFLOW : il ne se remplace pas d’ici.');
        }

        $sha = hash('sha256', $contenu);
        $chemin = 'justificatifs-comptables/'.$operation->entreprise_id.'/'.$operation->source.'-'.$operation->source_id.'-'.substr($sha, 0, 16).'.pdf';
        Storage::disk('local')->put($chemin, $contenu);

        $ancienne = DB::table('justificatifs_comptables')->where('entreprise_id', $operation->entreprise_id)
            ->where('source', $operation->source)->where('source_id', $operation->source_id)->first();

        DB::table('justificatifs_comptables')->updateOrInsert(
            ['entreprise_id' => $operation->entreprise_id, 'source' => $operation->source, 'source_id' => $operation->source_id],
            ['chemin' => $chemin, 'nom_original' => mb_substr(basename($fichier->getClientOriginalName()), 0, 255), 'sha256' => $sha,
                'taille' => strlen($contenu), 'depose_par' => $auteur->id, 'updated_at' => now(), 'created_at' => $ancienne->created_at ?? now()],
        );

        if ($ancienne && $ancienne->chemin !== $chemin) {
            Storage::disk('local')->delete($ancienne->chemin);
        }
    }

    /**
     * La pièce elle-même, puis celle qui la confirme (suivi ↔ FNE).
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function piecesDe(int $entreprise, string $source, int $id): array
    {
        $pieces = [[$source, $id]];

        if ($source === 'suivi') {
            foreach (DB::table('factures_fne')->where('entreprise_id', $entreprise)->where('facture_fournisseur_id', $id)->pluck('id') as $fne) {
                $pieces[] = ['fne', (int) $fne];
            }
        }
        if ($source === 'fne') {
            $suivi = DB::table('factures_fne')->where('entreprise_id', $entreprise)->where('id', $id)->value('facture_fournisseur_id');
            if ($suivi) {
                $pieces[] = ['suivi', (int) $suivi];
            }
        }

        return $pieces;
    }

    /** Le chemin sur le disque, seulement si le fichier y est et que c'est un PDF. */
    private static function cheminAbsolu(?string $chemin): ?string
    {
        if (! is_string($chemin) || ! str_starts_with($chemin, 'justificatifs-comptables/') || str_contains($chemin, '..')) {
            return null;
        }

        $absolu = Storage::disk('local')->path($chemin);
        if (! is_file($absolu)) {
            return null;
        }

        $debut = (string) @file_get_contents($absolu, false, null, 0, 5);

        return $debut === '%PDF-' ? $absolu : null;
    }
}

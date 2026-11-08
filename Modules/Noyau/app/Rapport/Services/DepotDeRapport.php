<?php

namespace Modules\Noyau\Rapport\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Rapport\Modeles\DepotRapport;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Support\EmpreinteDePiece;
use Modules\Noyau\Rapport\Support\LectureDesPieces;

/**
 * Le dépôt de fichiers du module Rapport — l'avis des impôts, la CNPS, les récapitulatifs — 06/11.
 *
 * **Sans doublon, à trois niveaux** (voir `EmpreinteDePiece`) :
 *  1. le fichier : même contenu, même empreinte → refusé (`DepotEnDoubleDeRapport`) ;
 *  2. la pièce : une ligne déjà connue n'est pas recréée, qu'elle vienne d'un autre fichier ou de la
 *     saisie à la main ;
 *  3. l'application : un paiement qui retrouve une sortie de caisse ou un débit de banque du même
 *     montant (à trois jours près) est gardé mais **ne compte pas** — la caisse le compte déjà dans
 *     les charges. Un récapitulatif non plus ne compte jamais : il ne sert qu'à contrôler.
 *
 * Une image (l'avis de fermeture photographié) est rangée telle quelle : ses montants se saisissent
 * à la main, ou se lisent par l'assistant (`AssistantDuRapport::lireUneImage`) si une clé est posée.
 */
final class DepotDeRapport
{
    public const EXTENSIONS_LUES = ['xlsx', 'xls', 'csv', 'txt', 'pdf'];

    public const EXTENSIONS_IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    public static function deposer(UploadedFile $fichier, User $auteur, ?string $nature = null, ?int $exercice = null): DepotRapport
    {
        $empreinte = EmpreinteDePiece::pourUnFichier($fichier->getRealPath());

        $existant = DepotRapport::query()->where('entreprise_id', $auteur->entreprise_id)->where('empreinte_fichier', $empreinte)->first();

        if ($existant !== null) {
            throw new DepotEnDoubleDeRapport($existant);
        }

        $extension = strtolower($fichier->getClientOriginalExtension());
        $chemin = $fichier->store('rapports/'.$auteur->entreprise_id, 'local');

        $depot = DepotRapport::query()->create([
            'entreprise_id' => $auteur->entreprise_id,
            'user_id' => $auteur->id,
            'nom_original' => mb_substr($fichier->getClientOriginalName(), 0, 255),
            'chemin' => $chemin,
            'empreinte_fichier' => $empreinte,
            'nature_declaree' => $nature,
            'statut' => 'lu',
        ]);

        if (in_array($extension, self::EXTENSIONS_IMAGES, true)) {
            $depot->update([
                'statut' => 'a_completer',
                'message' => 'Image rangée : saisissez les montants du document, ou faites-le lire par l\'assistant.',
            ]);

            return $depot;
        }

        try {
            $lignes = self::lireLesLignes(Storage::disk('local')->path($chemin), $extension);
        } catch (\Throwable $e) {
            $depot->update(['statut' => 'erreur', 'message' => 'Fichier illisible : '.mb_substr($e->getMessage(), 0, 300)]);

            return $depot;
        }

        $natureParDefaut = $nature ?: LectureDesPieces::natureDepuis($fichier->getClientOriginalName());
        $lecture = LectureDesPieces::depuisLesLignes($lignes, $natureParDefaut);

        if (! $lecture['entete'] || $lecture['pieces'] === []) {
            $depot->update([
                'statut' => 'a_completer',
                'message' => 'Aucune ligne reconnue (il faut au moins une colonne de montant et une de libellé, date ou référence). Le fichier est rangé ; saisissez les montants à la main.',
            ]);

            return $depot;
        }

        $bilan = self::enregistrer($lecture['pieces'], $auteur, $depot, $exercice, false);

        $depot->update([
            'statut' => 'lu',
            'nb_lignes' => count($lecture['pieces']),
            'nb_nouvelles' => $bilan['nouvelles'],
            'nb_doublons' => $bilan['doublons'],
            'message' => sprintf('%d pièce(s) nouvelle(s), %d déjà connue(s)%s.', $bilan['nouvelles'], $bilan['doublons'],
                $bilan['dejaDansLApplication'] > 0 ? ', '.$bilan['dejaDansLApplication'].' déjà comptée(s) par la caisse ou la banque (gardée(s), non recomptée(s))' : ''),
        ]);

        return $depot;
    }

    /**
     * Une pièce saisie à la main (ou lue par l'assistant) : même règle de doublon que le fichier.
     *
     * @param  array{nature: string, statut: string, date: ?string, libelle: string, tiers: ?string, reference: ?string, montant: int}  $piece
     * @return array{nouvelles: int, doublons: int, dejaDansLApplication: int}
     */
    public static function saisir(array $piece, User $auteur, ?int $exercice = null, ?DepotRapport $depot = null): array
    {
        $piece['compte'] = $piece['nature'] !== 'recap';

        return self::enregistrer([$piece], $auteur, $depot, $exercice, true);
    }

    /**
     * @param  list<array<string, mixed>>  $pieces
     * @return array{nouvelles: int, doublons: int, dejaDansLApplication: int}
     */
    private static function enregistrer(array $pieces, User $auteur, ?DepotRapport $depot, ?int $exercice, bool $manuelle): array
    {
        $bilan = ['nouvelles' => 0, 'doublons' => 0, 'dejaDansLApplication' => 0];

        DB::transaction(function () use ($pieces, $auteur, $depot, $exercice, $manuelle, &$bilan) {
            foreach ($pieces as $p) {
                $empreinte = EmpreinteDePiece::pourUnePiece($p['nature'], $p['statut'], $p['date'], (int) $p['montant'], $p['reference'], $p['tiers'], $p['libelle']);

                if (PieceRapport::query()->where('entreprise_id', $auteur->entreprise_id)->where('empreinte', $empreinte)->exists()) {
                    $bilan['doublons']++;

                    continue;
                }

                $compte = (bool) $p['compte'];

                if ($compte && $p['statut'] === 'paye' && self::dejaCompteParLApplication((int) $p['montant'], $p['date'])) {
                    $compte = false;
                    $bilan['dejaDansLApplication']++;
                }

                PieceRapport::query()->create([
                    'entreprise_id' => $auteur->entreprise_id,
                    'depot_rapport_id' => $depot?->id,
                    'nature' => $p['nature'],
                    'statut' => $p['statut'],
                    'exercice' => $exercice ?: ($p['date'] ? (int) substr($p['date'], 0, 4) : (int) now()->year),
                    'date_piece' => $p['date'],
                    'libelle' => $p['libelle'],
                    'tiers' => $p['tiers'],
                    'reference' => $p['reference'],
                    'montant' => (int) $p['montant'],
                    'compte' => $compte,
                    'saisie_manuelle' => $manuelle,
                    'empreinte' => $empreinte,
                ]);

                $bilan['nouvelles']++;
            }
        });

        return $bilan;
    }

    /**
     * Ce paiement est-il déjà dans l'application ? Une sortie de caisse ou un débit de banque du même
     * montant, à trois jours près : la caisse le compte déjà dans les charges (« caisse administrative »).
     */
    public static function dejaCompteParLApplication(int $montant, ?string $date): bool
    {
        if ($montant <= 0) {
            return false;
        }

        $fenetre = $date !== null
            ? [\Illuminate\Support\Carbon::parse($date)->subDays(3)->startOfDay(), \Illuminate\Support\Carbon::parse($date)->addDays(3)->endOfDay()]
            : null;

        $caisse = MouvementCaisse::query()->where('sens', MouvementCaisse::SORTIE)->where('montant', $montant)
            ->when($fenetre, fn ($q) => $q->whereBetween('date', $fenetre))->exists();

        if ($caisse) {
            return true;
        }

        return MouvementBancaire::query()->where('debit', $montant)
            ->when($fenetre, fn ($q) => $q->whereBetween('date_operation', $fenetre))->exists();
    }

    /** @return list<list<mixed>> */
    private static function lireLesLignes(string $chemin, string $extension): array
    {
        if (in_array($extension, ['csv', 'txt'], true)) {
            $lignes = [];
            $poignee = fopen($chemin, 'r');
            $premiere = (string) fgets($poignee);
            rewind($poignee);
            $separateur = substr_count($premiere, ';') >= substr_count($premiere, ',') ? ';' : ',';

            while (($l = fgetcsv($poignee, 0, $separateur)) !== false) {
                $lignes[] = array_map(fn ($c) => is_string($c) ? mb_convert_encoding($c, 'UTF-8', 'UTF-8, ISO-8859-1') : $c, $l);
            }

            fclose($poignee);

            return $lignes;
        }

        $lecteur = Classeur::ouvrir($chemin);
        $lignes = [];

        try {
            foreach ($lecteur->feuilles() as $feuille) {
                foreach ($lecteur->lignes($feuille) as $ligne) {
                    $lignes[] = (array) $ligne;
                }
            }
        } finally {
            $lecteur->fermer();
        }

        return $lignes;
    }
}

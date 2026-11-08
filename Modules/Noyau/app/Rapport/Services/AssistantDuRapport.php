<?php

namespace Modules\Noyau\Rapport\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * L'assistant du module Rapport — les commentaires du classeur Excel, dans l'application — 06/11.
 *
 * Deux étages, volontairement séparés :
 *
 *  - **`commentaires()`** : des règles de lecture, écrites ici, sans réseau. Elles produisent les
 *    commentaires de chaque page (ce qui domine, ce qui alerte, ce qui manque) et servent toujours.
 *  - **`commenterParIA()` / `lireUneImage()`** : l'IA, seulement si `ANTHROPIC_API_KEY` est posée et
 *    **seulement sur clic** — rien ne part vers l'extérieur sans un geste. Elle ne reçoit que les
 *    chiffres agrégés du rapport (jamais un nom de salarié ni un relevé), et son texte s'affiche
 *    marqué comme tel. Si elle échoue, les règles restent.
 *
 * L'IA n'écrit jamais dans la base : elle propose un texte, ou des pièces à confirmer.
 */
final class AssistantDuRapport
{
    public static function iaDisponible(): bool
    {
        return (string) config('services.anthropic.cle') !== '';
    }

    private static function m(int|float $v): string
    {
        return number_format((float) $v / 1_000_000, 1, ',', ' ').' M';
    }

    private static function p(int|float $a, int|float $b): string
    {
        return $b > 0 ? number_format($a / $b * 100, 1, ',', ' ').' %' : '—';
    }

    /**
     * Les commentaires de chaque page, tirés des chiffres — le « moteur de réflexion » sans IA.
     *
     * @param  array<string, mixed>  $r  le rapport (`RapportFinancier::tout()`)
     * @return array<string, list<array{niveau: string, texte: string}>>  page => remarques (niveau : info | alerte | bon)
     */
    public static function commentaires(array $r): array
    {
        $c = [];
        $ca = $r['ca'];
        $total = (float) $ca['total'];

        if ($total > 0) {
            $villes = collect($ca['parVille'])->sortByDesc('montant')->values();
            $c['ca'][] = ['niveau' => 'info', 'texte' => sprintf('Le CA de la période est de %s FCFA sur %d factures, soit %s par mois en moyenne.', self::m($total), $ca['nombre'], self::m($ca['moyenneMensuelle']))];

            if ($villes->isNotEmpty()) {
                $c['ca'][] = ['niveau' => 'info', 'texte' => sprintf('%s pèse %s du CA.', $villes[0]['ville'], self::p($villes[0]['montant'], $total))];
            }

            if ($ca['nonRattache']['nombre'] > 0) {
                $c['ca'][] = ['niveau' => 'alerte', 'texte' => sprintf('%d facture(s), %s FCFA (%s du CA), n\'ont plus de code éditeur : leur ville est inconnue. Elles sont dans le total, pas dans les villes — à corriger dans le logiciel d\'atelier.', $ca['nonRattache']['nombre'], self::m($ca['nonRattache']['montant']), self::p($ca['nonRattache']['montant'], $total))];
            }

            $mois = collect($ca['parMois'])->where('complet', true);
            if ($mois->count() >= 3) {
                $meilleur = $mois->sortByDesc('montant')->first();
                $faible = $mois->sortBy('montant')->first();
                $c['ca'][] = ['niveau' => 'info', 'texte' => sprintf('Meilleur mois : %s (%s) ; mois le plus faible : %s (%s).', $meilleur['libelle'], self::m($meilleur['montant']), $faible['libelle'], self::m($faible['montant']))];
            }
        } else {
            $c['ca'][] = ['niveau' => 'alerte', 'texte' => 'Aucun chiffre d\'affaires sur la période et le périmètre choisis.'];
        }

        $rec = $r['recouvrement'];
        $c['recouvrement'][] = ['niveau' => $rec['reste'] > 0 ? 'alerte' : 'bon', 'texte' => sprintf('%s FCFA restent à recouvrer sur %d factures ouvertes, chez %d clients.', self::m($rec['reste']), $rec['ouvertes'], $rec['nombreClients'])];

        if ($rec['reste'] > 0 && $rec['top10']->isNotEmpty()) {
            $part = $rec['top10']->sum('reste');
            $c['recouvrement'][] = ['niveau' => 'info', 'texte' => sprintf('Les dix premiers débiteurs représentent %s de l\'encours — c\'est là que se joue le recouvrement.', self::p($part, $rec['reste']))];
            $sansReglement = $rec['top10']->filter(fn ($t) => $t['dernierReglement'] === null);

            if ($sansReglement->isNotEmpty()) {
                $c['recouvrement'][] = ['niveau' => 'alerte', 'texte' => 'Aucun règlement enregistré pour : '.$sansReglement->pluck('client')->implode(', ').'.'];
            }
        }

        $ancien = (float) ($rec['anciennete']['> 1 an'] ?? 0);
        if ($ancien > 0 && $rec['reste'] > 0) {
            $c['recouvrement'][] = ['niveau' => 'alerte', 'texte' => sprintf('%s de l\'encours a plus d\'un an : plus il vieillit, moins il se recouvre.', self::p($ancien, $rec['reste']))];
        }

        $ch = $r['charges'];
        $c['charges'][] = ['niveau' => 'info', 'texte' => sprintf('Les charges comptées sont de %s FCFA (caisse administrative, saisies, suivi fournisseurs et FNE).', self::m($ch['totalCompte']))];

        if ($ch['classement'] !== []) {
            $premier = $ch['classement'][0];
            $c['charges'][] = ['niveau' => 'info', 'texte' => sprintf('Premier poste de dépense : %s (%s, %s des charges).', $premier['poste'], self::m($premier['montant']), self::p($premier['montant'], $ch['totalCompte'] ?: 1))];
        }

        if ($ch['fournisseurs']['reste'] > 0) {
            $c['charges'][] = ['niveau' => 'alerte', 'texte' => sprintf('Il reste %s FCFA à payer aux fournisseurs sur la période (%s déjà réglés).', self::m($ch['fournisseurs']['reste']), self::m($ch['fournisseurs']['regle']))];
        }

        if ($ch['impots']['aRegler'] > 0) {
            $c['charges'][] = ['niveau' => 'alerte', 'texte' => sprintf('Impôts à régler : %s FCFA d\'après les pièces déposées.', self::m($ch['impots']['aRegler']))];
        } elseif (! $ch['impots']['depose']) {
            $c['charges'][] = ['niveau' => 'info', 'texte' => 'Aucun fichier d\'impôts ni de CNPS déposé : déposez-les dans l\'onglet « Dépôt de fichiers » pour qu\'ils entrent dans le rapport.'];
        }

        if ($ch['cnps']['aRegler'] === 0 && $ch['cnps']['paye'] === 0) {
            $c['charges'][] = ['niveau' => 'info', 'texte' => 'CNPS : aucune pièce déposée, le rapport ne peut donc ni dire ce qui est dû ni ce qui est payé.'];
        }

        $co = $r['conclusion'];
        $c['conclusion'][] = ['niveau' => $co['engagements'] > 0 ? 'alerte' : 'bon', 'texte' => sprintf('Les engagements identifiés (fournisseurs, salaires, impôts, CNPS) sont de %s FCFA.', self::m($co['engagements']))];

        if ($co['engagements'] > 0 && $rec['reste'] > 0) {
            $c['conclusion'][] = ['niveau' => 'info', 'texte' => sprintf('Pour les couvrir, il faut recouvrer %s de l\'encours clients (%s FCFA).', self::p($co['engagements'], $rec['reste']), self::m($rec['reste']))];
        }

        if ($co['semaines'] !== null) {
            $c['conclusion'][] = ['niveau' => 'info', 'texte' => sprintf('Au rythme des 4 dernières semaines (%s par semaine), il faudrait environ %s semaines d\'encaissements pour les couvrir — si tout y était affecté.', self::m($co['encaissementHebdo']), number_format($co['semaines'], 1, ',', ' '))];
        }

        if ($r['tresorerie']['net'] < 0) {
            $c['conclusion'][] = ['niveau' => 'alerte', 'texte' => sprintf('Le journal et les relevés de la période se soldent par %s FCFA de décaissements de plus que d\'encaissements.', self::m(abs($r['tresorerie']['net'])))];
        }

        return $c;
    }

    /**
     * Le rapport commenté par l'IA — texte libre, en français, sur les seuls chiffres agrégés.
     *
     * @param  array<string, mixed>  $resume  `RapportFinancier::resumePourIA()`
     */
    public static function commenterParIA(array $resume): ?string
    {
        if (! self::iaDisponible()) {
            return null;
        }

        $systeme = 'Tu es l\'analyste financier d\'un garage automobile en Côte d\'Ivoire (montants en FCFA). '
            .'À partir des seuls chiffres fournis, rédige en français un commentaire de gestion pour le gérant : '
            .'5 à 8 phrases, concret, sans jargon, qui dit ce qui domine, ce qui inquiète, et les trois actions à mener. '
            .'N\'invente aucun chiffre ; si une donnée manque, dis-le. N\'emploie pas de liste à puces.';

        return self::appeler($systeme, [['type' => 'text', 'text' => json_encode($resume, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]], 900);
    }

    /**
     * Lit une image déposée (avis des impôts, relevé CNPS photographié) et propose des pièces.
     * Rien n'est enregistré ici : la page montre les pièces, et le gérant confirme.
     *
     * @return list<array{nature: string, statut: string, date: ?string, libelle: string, tiers: ?string, reference: ?string, montant: int}>|null
     */
    public static function lireUneImage(string $cheminAbsolu, string $mime): ?array
    {
        if (! self::iaDisponible() || ! is_file($cheminAbsolu)) {
            return null;
        }

        $systeme = 'Tu lis un document administratif ivoirien (avis de la DGI, appel de cotisation CNPS, relevé). '
            .'Réponds UNIQUEMENT par un tableau JSON de lignes, sans texte autour : '
            .'[{"nature":"impot|cnps|salaire|autre","statut":"du|paye","date":"AAAA-MM-JJ ou null","libelle":"...","reference":"... ou null","tiers":"... ou null","montant":entier en francs}]. '
            .'Une ligne par montant distinct (ex. droits simples, pénalités, frais) ; n\'ajoute pas de ligne de total. '
            .'Si un chiffre est illisible, omets la ligne plutôt que de le deviner.';

        $texte = self::appeler($systeme, [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode((string) file_get_contents($cheminAbsolu))]],
            ['type' => 'text', 'text' => 'Extrais les lignes de ce document.'],
        ], 1200);

        if ($texte === null) {
            return null;
        }

        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $texte)), true);

        if (! is_array($json)) {
            return null;
        }

        $pieces = [];

        foreach ($json as $l) {
            $montant = \Modules\Noyau\Rapport\Support\LectureDesPieces::nombre($l['montant'] ?? null);
            $nature = in_array($l['nature'] ?? '', ['impot', 'cnps', 'salaire', 'autre'], true) ? $l['nature'] : 'autre';

            if ($montant === null || $montant <= 0) {
                continue;
            }

            $pieces[] = [
                'nature' => $nature,
                'statut' => ($l['statut'] ?? 'du') === 'paye' ? 'paye' : 'du',
                'date' => \Modules\Noyau\Rapport\Support\LectureDesPieces::date($l['date'] ?? null),
                'libelle' => mb_substr((string) ($l['libelle'] ?? 'Pièce lue'), 0, 200),
                'tiers' => isset($l['tiers']) && $l['tiers'] !== null ? mb_substr((string) $l['tiers'], 0, 200) : null,
                'reference' => isset($l['reference']) && $l['reference'] !== null ? mb_substr((string) $l['reference'], 0, 80) : null,
                'montant' => $montant,
            ];
        }

        return $pieces;
    }

    /** @param  list<array<string, mixed>>  $contenu */
    private static function appeler(string $systeme, array $contenu, int $max): ?string
    {
        try {
            $reponse = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.cle'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('services.anthropic.modele'),
                'max_tokens' => $max,
                'system' => $systeme,
                'messages' => [['role' => 'user', 'content' => $contenu]],
            ]);

            if (! $reponse->successful()) {
                Log::warning('Assistant du rapport : réponse '.$reponse->status());

                return null;
            }

            return collect($reponse->json('content', []))->where('type', 'text')->pluck('text')->implode("\n") ?: null;
        } catch (\Throwable $e) {
            Log::warning('Assistant du rapport : '.$e->getMessage());

            return null;
        }
    }
}

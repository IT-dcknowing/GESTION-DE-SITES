<?php

namespace Modules\Noyau\IA\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Le client des IA de l'application — 10/11.
 *
 * **Un usage, une IA.** « On aura deux IA : une pour le rapport, une pour les imputations. » Chaque
 * usage (`rapport`, `imputations`) est affecté par le Super admin à un fournisseur (OpenAI, Anthropic,
 * Google Gemini, OpenRouter) dont la clé est déjà chiffrée dans `configurations_ia` ; l'usage peut
 * avoir son modèle et ses instructions propres (`affectations_ia`).
 *
 * **Un seul geste pour l'appelant** : `texte()` (une demande, une réponse) ou `discuter()` (une
 * conversation où l'IA peut appeler des *outils* — lire une donnée, enregistrer une analyse — que
 * l'application exécute, sous les habilitations du compte). Les formats propres à chaque fournisseur
 * s'arrêtent ici.
 *
 * La clé ne sort jamais de cette classe : elle est déchiffrée à l'appel, jamais journalisée.
 */
final class ClientIA
{
    private function __construct(
        public readonly string $usage,
        public readonly string $fournisseur,
        public readonly string $modele,
        public readonly ?string $instructions,
        private readonly string $cle,
    ) {}

    /** L'IA de cet usage, ou null si elle n'est pas affectée, pas activée ou sans clé. */
    public static function pour(string $usage): ?self
    {
        if (Schema::hasTable('affectations_ia') && Schema::hasTable('configurations_ia')) {
            $a = DB::table('affectations_ia')->where('usage', $usage)->where('actif', true)->first();
            $c = $a ? DB::table('configurations_ia')->where('fournisseur', $a->fournisseur)->where('actif', true)->first() : null;

            if ($a && $c && filled($c->cle_api_chiffree)) {
                try {
                    $cle = Crypt::decryptString($c->cle_api_chiffree);
                } catch (\Throwable) {
                    return null;
                }

                return new self($usage, $a->fournisseur, trim((string) ($a->modele ?: $c->modele)), $a->instructions, $cle);
            }
        }

        // Repli, pour le seul rapport : la clé Anthropic posée dans l'environnement.
        if ($usage === 'rapport' && (string) config('services.anthropic.cle') !== '') {
            return new self($usage, 'anthropic', (string) config('services.anthropic.modele'), null, (string) config('services.anthropic.cle'));
        }

        return null;
    }

    /**
     * Une demande, une réponse. Les parties sont neutres : `['type' => 'text', 'text' => …]` ou
     * `['type' => 'image', 'mime' => …, 'base64' => …]`.
     *
     * @param  list<array<string, string>>  $parties
     */
    public function texte(string $systeme, array $parties, int $max = 1200): string
    {
        return match ($this->fournisseur) {
            'anthropic' => $this->anthropic($systeme, [['role' => 'user', 'content' => array_map(fn ($p) => $this->partieAnthropic($p), $parties)]], [], $max)['texte'],
            'google' => $this->google($systeme, [['role' => 'user', 'parts' => array_map(fn ($p) => $this->partieGoogle($p), $parties)]], [], $max)['texte'],
            default => $this->openai($systeme, [['role' => 'user', 'content' => array_map(fn ($p) => $this->partieOpenai($p), $parties)]], [], $max)['texte'],
        };
    }

    /**
     * Une conversation avec outils. `$historique` = tours simples `[['role' => 'user'|'assistant', 'content' => '…']]`.
     * `$outils` = `[['name', 'description', 'parameters' => schéma JSON]]`. `$executeur(nom, arguments): string`
     * lit ou écrit pour l'IA — et c'est lui qui contrôle les habilitations. Au plus `$maxEtapes` allers-retours.
     *
     * @param  list<array{role: string, content: string}>  $historique
     * @param  list<array<string, mixed>>  $outils
     * @param  callable(string, array<string, mixed>): string  $executeur
     */
    public function discuter(string $systeme, array $historique, array $outils, callable $executeur, int $maxEtapes = 6, int $max = 1800): string
    {
        return match ($this->fournisseur) {
            'anthropic' => $this->boucleAnthropic($systeme, $historique, $outils, $executeur, $maxEtapes, $max),
            'google' => $this->boucleGoogle($systeme, $historique, $outils, $executeur, $maxEtapes, $max),
            default => $this->boucleOpenai($systeme, $historique, $outils, $executeur, $maxEtapes, $max),
        };
    }

    // ───────────────────────────────────────────────────────────── Anthropic

    private function partieAnthropic(array $p): array
    {
        return $p['type'] === 'image'
            ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $p['mime'], 'data' => $p['base64']]]
            : ['type' => 'text', 'text' => $p['text']];
    }

    /** @return array{texte: string, blocs: list<array<string, mixed>>, appels: list<array<string, mixed>>} */
    private function anthropic(string $systeme, array $messages, array $outils, int $max): array
    {
        $corps = ['model' => $this->modele, 'max_tokens' => $max, 'system' => $this->systeme($systeme), 'messages' => $messages];

        if ($outils !== []) {
            $corps['tools'] = array_map(fn ($o) => ['name' => $o['name'], 'description' => $o['description'], 'input_schema' => $o['parameters']], $outils);
        }

        $r = $this->appeler('https://api.anthropic.com/v1/messages', ['x-api-key' => $this->cle, 'anthropic-version' => '2023-06-01'], $corps);
        $blocs = $r['content'] ?? [];

        return [
            'texte' => trim(collect($blocs)->where('type', 'text')->pluck('text')->implode("\n")),
            'blocs' => $blocs,
            'appels' => collect($blocs)->where('type', 'tool_use')->values()->all(),
        ];
    }

    private function boucleAnthropic(string $systeme, array $historique, array $outils, callable $executeur, int $maxEtapes, int $max): string
    {
        $messages = array_map(fn ($t) => ['role' => $t['role'], 'content' => $t['content']], $historique);

        for ($etape = 0; $etape < $maxEtapes; $etape++) {
            $r = $this->anthropic($systeme, $messages, $outils, $max);

            if ($r['appels'] === []) {
                return $r['texte'] !== '' ? $r['texte'] : 'Je n\'ai rien à ajouter.';
            }

            $messages[] = ['role' => 'assistant', 'content' => $r['blocs']];
            $resultats = [];

            foreach ($r['appels'] as $appel) {
                $resultats[] = ['type' => 'tool_result', 'tool_use_id' => $appel['id'], 'content' => $this->executer($executeur, (string) $appel['name'], (array) ($appel['input'] ?? []))];
            }

            $messages[] = ['role' => 'user', 'content' => $resultats];
        }

        return 'J\'ai atteint la limite d\'étapes pour cette demande : reformulez-la plus simplement ou en deux temps.';
    }

    // ──────────────────────────────────────────── OpenAI et OpenRouter (même format)

    private function partieOpenai(array $p): array
    {
        return $p['type'] === 'image'
            ? ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$p['mime'].';base64,'.$p['base64']]]
            : ['type' => 'text', 'text' => $p['text']];
    }

    /** @return array{texte: string, message: array<string, mixed>, appels: list<array<string, mixed>>} */
    private function openai(string $systeme, array $messages, array $outils, int $max): array
    {
        $corps = ['model' => $this->modele, 'messages' => array_merge([['role' => 'system', 'content' => $this->systeme($systeme)]], $messages)];
        $corps[$this->fournisseur === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $max;

        if ($outils !== []) {
            $corps['tools'] = array_map(fn ($o) => ['type' => 'function', 'function' => ['name' => $o['name'], 'description' => $o['description'], 'parameters' => $o['parameters']]], $outils);
        }

        $url = $this->fournisseur === 'openrouter' ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $r = $this->appeler($url, ['Authorization' => 'Bearer '.$this->cle], $corps);
        $message = $r['choices'][0]['message'] ?? [];

        return ['texte' => trim((string) ($message['content'] ?? '')), 'message' => $message, 'appels' => $message['tool_calls'] ?? []];
    }

    private function boucleOpenai(string $systeme, array $historique, array $outils, callable $executeur, int $maxEtapes, int $max): string
    {
        $messages = array_map(fn ($t) => ['role' => $t['role'], 'content' => $t['content']], $historique);

        for ($etape = 0; $etape < $maxEtapes; $etape++) {
            $r = $this->openai($systeme, $messages, $outils, $max);

            if ($r['appels'] === []) {
                return $r['texte'] !== '' ? $r['texte'] : 'Je n\'ai rien à ajouter.';
            }

            $messages[] = $r['message'];

            foreach ($r['appels'] as $appel) {
                $arguments = json_decode((string) ($appel['function']['arguments'] ?? '{}'), true);
                $messages[] = ['role' => 'tool', 'tool_call_id' => $appel['id'], 'content' => $this->executer($executeur, (string) ($appel['function']['name'] ?? ''), is_array($arguments) ? $arguments : [])];
            }
        }

        return 'J\'ai atteint la limite d\'étapes pour cette demande : reformulez-la plus simplement ou en deux temps.';
    }

    // ───────────────────────────────────────────────────────────── Gemini

    private function partieGoogle(array $p): array
    {
        return $p['type'] === 'image' ? ['inlineData' => ['mimeType' => $p['mime'], 'data' => $p['base64']]] : ['text' => $p['text']];
    }

    /** Gemini veut les types de schéma en majuscules (OBJECT, STRING…). */
    public static function schemaGoogle(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        foreach ($schema as $cle => $valeur) {
            $schema[$cle] = $cle === 'type' && is_string($valeur) ? strtoupper($valeur) : self::schemaGoogle($valeur);
        }

        return $schema;
    }

    /** @return array{texte: string, contenu: array<string, mixed>, appels: list<array<string, mixed>>} */
    private function google(string $systeme, array $contenus, array $outils, int $max): array
    {
        $corps = ['systemInstruction' => ['parts' => [['text' => $this->systeme($systeme)]]], 'contents' => $contenus, 'generationConfig' => ['maxOutputTokens' => $max]];

        if ($outils !== []) {
            $corps['tools'] = [['functionDeclarations' => array_map(fn ($o) => ['name' => $o['name'], 'description' => $o['description'], 'parameters' => self::schemaGoogle($o['parameters'])], $outils)]];
        }

        $r = $this->appeler('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($this->modele).':generateContent', ['x-goog-api-key' => $this->cle], $corps);
        $contenu = $r['candidates'][0]['content'] ?? ['role' => 'model', 'parts' => []];
        $parties = $contenu['parts'] ?? [];

        return [
            'texte' => trim(collect($parties)->pluck('text')->filter()->implode("\n")),
            'contenu' => $contenu,
            'appels' => collect($parties)->pluck('functionCall')->filter()->values()->all(),
        ];
    }

    private function boucleGoogle(string $systeme, array $historique, array $outils, callable $executeur, int $maxEtapes, int $max): string
    {
        $contenus = array_map(fn ($t) => ['role' => $t['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $t['content']]]], $historique);

        for ($etape = 0; $etape < $maxEtapes; $etape++) {
            $r = $this->google($systeme, $contenus, $outils, $max);

            if ($r['appels'] === []) {
                return $r['texte'] !== '' ? $r['texte'] : 'Je n\'ai rien à ajouter.';
            }

            $contenus[] = $r['contenu'];
            $reponses = [];

            foreach ($r['appels'] as $appel) {
                $reponses[] = ['functionResponse' => ['name' => $appel['name'], 'response' => ['resultat' => $this->executer($executeur, (string) $appel['name'], (array) ($appel['args'] ?? []))]]];
            }

            $contenus[] = ['role' => 'user', 'parts' => $reponses];
        }

        return 'J\'ai atteint la limite d\'étapes pour cette demande : reformulez-la plus simplement ou en deux temps.';
    }

    // ───────────────────────────────────────────────────────────── commun

    private function systeme(string $systeme): string
    {
        return $this->instructions ? $systeme."\n\nInstructions propres à cet usage, fixées par l'administrateur :\n".$this->instructions : $systeme;
    }

    /** L'outil s'exécute sous les habilitations du compte ; une erreur revient à l'IA comme un texte, pas comme un plantage. */
    private function executer(callable $executeur, string $nom, array $arguments): string
    {
        try {
            return mb_substr((string) $executeur($nom, $arguments), 0, 14000);
        } catch (\Throwable $e) {
            return 'Erreur : '.$e->getMessage();
        }
    }

    /** @return array<string, mixed> */
    private function appeler(string $url, array $entetes, array $corps): array
    {
        try {
            $reponse = Http::withHeaders($entetes)->timeout(110)->acceptJson()->post($url, $corps);
        } catch (\Throwable $e) {
            throw new IAIndisponible('Le fournisseur ne répond pas ('.mb_substr($e->getMessage(), 0, 120).').');
        }

        if (! $reponse->successful()) {
            $detail = $reponse->json('error.message') ?? $reponse->json('error') ?? $reponse->body();
            throw new IAIndisponible('Le fournisseur a refusé la demande (HTTP '.$reponse->status().') : '.mb_substr(is_string($detail) ? $detail : json_encode($detail), 0, 200));
        }

        return (array) $reponse->json();
    }
}

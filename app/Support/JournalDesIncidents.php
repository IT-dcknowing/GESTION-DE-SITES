<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Les pannes, retrouvées par la référence que l'utilisateur a lue à l'écran.
 *
 * Deux sources, et c'est ce qui rend la lecture fiable :
 *
 * 1. **la table `incidents`**, écrite au moment de la panne — message, origine, adresse,
 *    compte, pile d'appels ;
 * 2. **le journal du serveur** (`storage/logs`), où chaque panne est écrite depuis le
 *    début avec la même référence. C'est lui qui retrouve les incidents d'avant la table —
 *    `ERR-XEYTWV` du 09/10 — et ceux qu'une base injoignable n'a pas laissé écrire.
 *
 * Rien ici ne doit jamais casser : on est appelé quand quelque chose a déjà cassé.
 */
final class JournalDesIncidents
{
    /** Au-delà, un fichier de journal n'est lu que par sa fin : il peut peser des centaines de Mo. */
    private const OCTETS_LUS_PAR_FICHIER = 8 * 1024 * 1024;

    /**
     * Les pannes **corrigées dans le code**, reconnues à leur message — demandé le 09/10 : « si
     * l'erreur est réglée, la ligne doit disparaître ».
     *
     * Une panne de cette liste disparaît si elle est survenue **avant la mise en ligne du code
     * qui la corrige**. Cette date se lit sur ce fichier lui-même : chaque correction ajoute
     * une ligne ici, donc le `git pull` qui l'apporte réécrit ce fichier, et sa date de
     * modification est celle du déploiement. Si la panne revient après, elle reparaît — elle
     * n'était pas réglée, ou pas partout.
     *
     * Ajouter une ligne à chaque panne corrigée : motif (expression régulière sur le message),
     * et ce qui l'a corrigée.
     */
    public const CORRIGES = [
        ['motif' => "/isn't in GROUP BY/", 'correction' => '09/10 — /banques et comptabilité : le reste des factures se calcule avant le regroupement (MySQL).'],
        ['motif' => "/Data too long for column 'factures_visees'/", 'correction' => '09/10 — relance : la liste des factures visées est abrégée quand elle dépasse la colonne.'],
        ['motif' => '/Call to a member function format\(\) on string.*Recouvrement/s', 'correction' => '09/10 — tableau de bord du recouvrement : le dernier geste d’un agent est toujours une date.'],
        ['motif' => "/Unknown column 'factures\.date_reception'/", 'correction' => '30/09 — migration de la date de dépôt passée.'],
        ['motif' => '/Undefined variable \$dateDebut.*filtre-periode/s', 'correction' => '16/09 — le filtre de période reçoit ses dates par défaut.'],
        ['motif' => '/InvalidStateException/', 'correction' => '09/10 — connexion Google expirée : retour à la page de connexion, ce n’est plus une panne.'],
    ];

    /**
     * La signature d'une panne : sa nature et l'endroit du code. Cinq références pour la même
     * faute partagent une signature, et se règlent ensemble.
     */
    public static function signature(array $incident): string
    {
        $origine = trim((string) ($incident['origine'] ?? ''));

        $base = $origine !== ''
            ? ($incident['exception'] ?? '').'|'.$origine
            // Sans origine (une ligne de journal mal formée) : le message, débarrassé de ce
            // qui varie d'une fois à l'autre — nombres, identifiants, valeurs citées.
            : preg_replace(["/'[^']*'/", '/\d+/'], ["''", '0'], (string) ($incident['message'] ?? ''));

        return hash('sha256', (string) $base);
    }

    /** La correction qui règle cet incident, s'il est réglé — par le code ou à la main. */
    public static function reglement(array $incident): ?string
    {
        $date = isset($incident['date']) && $incident['date'] ? strtotime((string) $incident['date']) : null;

        // Corrigé dans le code, et survenu avant la mise en ligne de la correction.
        $deploiement = @filemtime(__FILE__) ?: null;

        foreach (self::CORRIGES as $corrige) {
            if (preg_match($corrige['motif'], ($incident['exception'] ?? '').' '.($incident['message'] ?? '').' '.($incident['origine'] ?? '')) === 1
                && $date !== null && $deploiement !== null && $date < $deploiement) {
                return $corrige['correction'];
            }
        }

        // Déclaré réglé depuis la page Maintenance, et survenu avant la déclaration.
        $regle = self::reglesALaMain()[self::signature($incident)] ?? null;

        if ($regle !== null && $date !== null && $date <= strtotime((string) $regle->regle_le)) {
            return 'Déclarée réglée le '.date('d/m/Y à H:i', strtotime((string) $regle->regle_le)).'.';
        }

        return null;
    }

    /**
     * Déclare réglée la panne de cette référence — et toutes celles de même signature.
     *
     * Les lignes de la table sont effacées ; celles du journal du serveur, qu'on ne réécrit
     * pas, sont masquées par la signature retenue ici.
     */
    public static function declarerRegle(string $reference, ?int $par = null): bool
    {
        $incident = self::trouver($reference);

        if ($incident === null) {
            return false;
        }

        $signature = self::signature($incident);
        $maintenant = now();

        try {
            DB::table('incidents_regles')->updateOrInsert(
                ['signature' => $signature],
                ['libelle' => Str::limit((string) $incident['message'], 1000), 'regle_le' => $maintenant,
                    'regle_par' => $par, 'updated_at' => $maintenant, 'created_at' => $maintenant],
            );

            $aEffacer = DB::table('incidents')->where('created_at', '<=', $maintenant)->get()
                ->filter(fn ($l) => self::signature((array) $l) === $signature)
                ->pluck('id');

            if ($aEffacer->isNotEmpty()) {
                DB::table('incidents')->whereIn('id', $aEffacer)->delete();
            }
        } catch (Throwable) {
            return false;
        }

        self::$regles = null;

        return true;
    }

    /** @var array<string, object>|null */
    private static ?array $regles = null;

    /** Le dossier des journaux ; les tests en donnent un à eux, pour ne pas lire celui des autres. */
    public static ?string $dossierDuJournal = null;

    /** @return array<string, object> signature => déclaration */
    private static function reglesALaMain(): array
    {
        if (self::$regles !== null) {
            return self::$regles;
        }

        try {
            return self::$regles = DB::table('incidents_regles')->get()->keyBy('signature')->all();
        } catch (Throwable) {
            return self::$regles = [];
        }
    }

    public static function noter(string $reference, Throwable $e, Request $requete, string $trace): void
    {
        try {
            $utilisateur = $requete->user();
            $assistant = class_exists(\Modules\SuperAdmin\Services\ModeSwitch::class)
                && \Modules\SuperAdmin\Services\ModeSwitch::enCours()
                ? \Modules\SuperAdmin\Services\ModeSwitch::origine()?->id
                : null;

            DB::table('incidents')->insert([
                'reference' => $reference,
                'entreprise_id' => $utilisateur?->entreprise_id,
                'user_id' => $utilisateur?->id,
                'assistant_id' => $assistant,
                'methode' => $requete->method(),
                'url' => Str::limit($requete->fullUrl(), 2000, ''),
                'exception' => Str::limit($e::class, 250, ''),
                'message' => Str::limit($e->getMessage(), 5000),
                'origine' => Str::limit(str_replace(base_path(), '', $e->getFile()).':'.$e->getLine(), 490, ''),
                'trace' => Str::limit($trace, 20000),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Table absente (migration non passée) ou base injoignable : le journal du
            // serveur garde la trace, et `trouver()` sait l'y relire.
        }
    }

    /**
     * Un incident par sa référence : la table d'abord, le journal du serveur ensuite.
     *
     * @return array{reference: string, date: ?string, exception: ?string, message: ?string, origine: ?string, url: ?string, methode: ?string, user_id: ?int, assistant_id: ?int, trace: ?string, source: string}|null
     */
    public static function trouver(string $reference): ?array
    {
        $reference = Str::upper(trim($reference));

        if (preg_match('/^ERR-[A-Z0-9]{4,12}$/', $reference) !== 1) {
            return null;
        }

        try {
            $ligne = DB::table('incidents')->where('reference', $reference)->first();

            if ($ligne !== null) {
                return [
                    'reference' => $ligne->reference,
                    'date' => (string) $ligne->created_at,
                    'exception' => $ligne->exception,
                    'message' => $ligne->message,
                    'origine' => $ligne->origine,
                    'url' => $ligne->url,
                    'methode' => $ligne->methode,
                    'user_id' => $ligne->user_id,
                    'assistant_id' => $ligne->assistant_id,
                    'trace' => $ligne->trace,
                    'source' => 'base',
                ];
            }
        } catch (Throwable) {
            // On retombe sur le journal.
        }

        foreach (self::fichiers() as $fichier) {
            foreach (self::lignesDuJournal($fichier) as $texte) {
                if (str_contains($texte, $reference.' — ') || str_contains($texte, $reference.' - ')) {
                    return self::lireLaLigne($texte) + ['source' => 'journal du serveur ('.basename($fichier).')'];
                }
            }
        }

        return null;
    }

    /**
     * Les pannes **encore ouvertes**, une ligne par signature, de la plus récente à la plus
     * ancienne — demandé le 09/10 : « on ne garde que ce qui sont des erreurs, pas celles
     * corrigées ». Chaque ligne dit combien de fois la panne est survenue, et depuis quand.
     *
     * @return Collection<int, array{reference: string, date: ?string, premiere: ?string, fois: int, exception: ?string, message: ?string, url: ?string, signature: string}>
     */
    public static function recents(int $nombre = 50): Collection
    {
        $tous = collect();

        try {
            $tous = DB::table('incidents')->orderByDesc('created_at')->limit(1000)->get()
                ->map(fn ($l) => [
                    'reference' => $l->reference, 'date' => (string) $l->created_at,
                    'exception' => $l->exception, 'message' => $l->message, 'url' => $l->url,
                    'origine' => $l->origine,
                ]);
        } catch (Throwable) {
        }

        foreach (self::fichiers() as $fichier) {
            foreach (self::lignesDuJournal($fichier) as $texte) {
                if (preg_match('/ERR-[A-Z0-9]{6}/', $texte) === 1) {
                    $lu = self::lireLaLigne($texte);

                    if ($lu['reference'] !== '' && ! $tous->contains('reference', $lu['reference'])) {
                        $tous->push($lu);
                    }
                }
            }
        }

        return $tous
            ->reject(fn ($i) => self::reglement($i) !== null)
            ->groupBy(fn ($i) => self::signature($i))
            ->map(function (Collection $memes, string $signature) {
                $parDate = $memes->sortByDesc('date')->values();

                return $parDate->first() + [
                    'signature' => $signature,
                    'fois' => $memes->count(),
                    'premiere' => $parDate->last()['date'] ?? null,
                ];
            })
            ->sortByDesc('date')->take($nombre)->values();
    }

    /** Ce que le message laisse deviner de la cause, quand elle est connue. */
    public static function explication(?string $message): ?string
    {
        $message = (string) $message;

        return match (true) {
            preg_match("/doesn't exist|no such table|Base table or view not found/i", $message) === 1 =>
                'Une table manque en base : une migration n’a pas été passée sur ce serveur. '
                .'Lancer `php artisan app:deployer` (ou `php artisan migrate`) — les migrations sont additives.',
            preg_match('/Unknown column|no such column|has no column/i', $message) === 1 =>
                'Une colonne manque en base : une migration n’a pas été passée sur ce serveur. '
                .'Lancer `php artisan app:deployer` (ou `php artisan migrate`).',
            preg_match('/Allowed memory size/i', $message) === 1 =>
                'La page a dépassé la mémoire allouée à PHP : trop de lignes lues d’un coup.',
            preg_match('/Maximum execution time/i', $message) === 1 =>
                'La page a dépassé le temps d’exécution permis : une requête trop lente.',
            preg_match('/SQLSTATE\[42000\]|only_full_group_by/i', $message) === 1 =>
                'Une requête SQL refusée par MySQL (syntaxe ou regroupement) : à corriger dans le code.',
            default => null,
        };
    }

    /**
     * Les migrations que ce serveur n'a pas encore passées — la cause la plus fréquente d'une
     * panne juste après une mise à jour.
     *
     * @return array<int, string>
     */
    public static function migrationsEnAttente(): array
    {
        try {
            $migrator = app('migrator');
            $chemins = array_merge([database_path('migrations')], $migrator->paths());
            $fichiers = array_keys($migrator->getMigrationFiles($chemins));

            if (! $migrator->repositoryExists()) {
                return $fichiers;
            }

            return array_values(array_diff($fichiers, $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------ le journal du serveur

    /** @return array<int, string> les fichiers de journal, le plus récent d'abord */
    private static function fichiers(): array
    {
        $fichiers = glob((self::$dossierDuJournal ?? storage_path('logs')).'/*.log') ?: [];
        usort($fichiers, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return array_slice($fichiers, 0, 10);
    }

    /** @return \Generator<int, string> les lignes d'entrée de journal, de la fin vers le début */
    private static function lignesDuJournal(string $fichier): \Generator
    {
        try {
            $taille = filesize($fichier) ?: 0;
            $poignee = fopen($fichier, 'rb');

            if ($poignee === false) {
                return;
            }

            if ($taille > self::OCTETS_LUS_PAR_FICHIER) {
                fseek($poignee, $taille - self::OCTETS_LUS_PAR_FICHIER);
                fgets($poignee); // la première ligne est coupée
            }

            $lignes = [];

            while (($ligne = fgets($poignee)) !== false) {
                // Seules les têtes d'entrée nous intéressent : « [2026-10-09 00:42:01] … ».
                if (str_starts_with($ligne, '[') && str_contains($ligne, 'ERR-')) {
                    $lignes[] = rtrim($ligne);
                }
            }

            fclose($poignee);

            yield from array_reverse($lignes);
        } catch (Throwable) {
            return;
        }
    }

    /** Une ligne de journal Laravel : « [date] env.ERROR: ERR-XXXXXX — message {contexte} ». */
    private static function lireLaLigne(string $texte): array
    {
        preg_match('/^\[([^\]]+)\]/', $texte, $date);
        preg_match('/(ERR-[A-Z0-9]{4,12})\s+[—-]\s+(.*)$/u', $texte, $corps);

        $message = $corps[2] ?? $texte;
        $contexte = [];

        // Le contexte est un objet JSON en fin de ligne ; on le détache du message.
        if (($debut = strrpos($message, ' {"')) !== false) {
            $json = json_decode(rtrim(substr($message, $debut + 1), ' []'), true);

            if (is_array($json)) {
                $contexte = $json;
                $message = substr($message, 0, $debut);
            }
        }

        return [
            'reference' => $corps[1] ?? '',
            'date' => $date[1] ?? null,
            'exception' => $contexte['exception'] ?? null,
            'message' => trim($message),
            'origine' => isset($contexte['origine']) ? str_replace(base_path(), '', (string) $contexte['origine']) : null,
            'url' => $contexte['url'] ?? null,
            'methode' => null,
            'user_id' => $contexte['utilisateur'] ?? null,
            'assistant_id' => null,
            'trace' => null,
        ];
    }
}

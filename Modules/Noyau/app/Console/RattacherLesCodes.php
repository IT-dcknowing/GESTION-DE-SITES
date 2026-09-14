<?php

namespace Modules\Noyau\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Colle en une fois les identifiants de liaison relevés dans le logiciel d'atelier.
 *
 * **Pourquoi une commande et pas une table écrite dans le code.** La correspondance entre
 * les deux lettres et les personnes est une **donnée de cette entreprise-là**, relevée un
 * jour donné dans un logiciel tiers. L'inscrire dans le code aurait trois défauts : elle
 * serait fausse dès qu'un employé change, elle voyagerait avec l'application chez le client
 * suivant, et la corriger demanderait un déploiement. Elle vit donc dans un fichier qu'on
 * tient à jour et qu'on rejoue.
 *
 * **Le fichier attendu** est un CSV à deux ou trois colonnes, séparées par `;` ou `,` :
 *
 *      KZ;KEITA ZEINAB
 *      AB;BAHINTCHIE AURIANE;auriane@exemple.ci
 *      YB;BOKOLA YANNICK BOYA
 *
 * La première colonne est le code, la deuxième le nom tel que le logiciel l'écrit, la
 * troisième — facultative — l'adresse du compte de la plateforme à rattacher. Une ligne
 * vide ou commençant par `#` est ignorée.
 *
 * **Ce que la commande ne fait pas**, et c'est délibéré :
 *
 * - elle ne crée aucun compte. Rattacher un code à quelqu'un qui n'a pas d'accès n'aurait
 *   aucun sens, et créer l'accès à sa place en aurait encore moins ;
 * - elle ne vole pas un code déjà rattaché à une autre personne. Elle le signale et passe :
 *   un code qui change de titulaire déplace tout l'historique qu'il porte, et cela se décide,
 *   cela ne se subit pas ;
 * - elle n'écrase pas un rattachement d'atelier posé à la main.
 *
 * Sans `--appliquer`, elle se contente de dire ce qu'elle ferait. C'est le mode par défaut,
 * parce qu'une reprise en base se regarde avant de se lancer.
 */
class RattacherLesCodes extends Command
{
    protected $signature = 'codes:rattacher
        {fichier : chemin du CSV « code;nom;email »}
        {--entreprise= : identifiant de l\'entreprise (par défaut, la seule s\'il n\'y en a qu\'une)}
        {--appliquer : écrire réellement ; sans cette option, rien n\'est modifié}';

    protected $description = 'Rattache les identifiants de liaison du logiciel d\'atelier aux codes et aux comptes.';

    public function handle(): int
    {
        $chemin = $this->argument('fichier');

        if (! is_file($chemin)) {
            $this->error("Fichier introuvable : {$chemin}");

            return self::FAILURE;
        }

        $entrepriseId = $this->entrepriseId();

        if ($entrepriseId === null) {
            return self::FAILURE;
        }

        $lignes = $this->lire($chemin);

        if ($lignes === []) {
            $this->error('Aucune ligne exploitable dans ce fichier.');

            return self::FAILURE;
        }

        $appliquer = (bool) $this->option('appliquer');

        $this->info(($appliquer ? 'Application' : 'Simulation').' sur l\'entreprise '.$entrepriseId
            .' — '.count($lignes).' ligne(s) lue(s).');
        $this->newLine();

        $comptes = User::where('entreprise_id', $entrepriseId)->get();
        $rangees = ['creees' => 0, 'nommees' => 0, 'rattachees' => 0, 'refusees' => 0, 'inchangees' => 0];
        $tableau = [];

        foreach ($lignes as [$code, $nom, $email]) {
            $agent = CodeAgent::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)
                ->where('code', $code)
                ->first();

            $compte = $email === null ? null : $comptes->firstWhere('email', $email);
            $etat = [];

            if ($agent === null) {
                // Un code du logiciel qu'aucun fichier importé n'a encore croisé : on le
                // crée quand même, pour que le nom soit là le jour où il apparaîtra.
                $etat[] = 'code inconnu des imports — créé';
                $rangees['creees']++;
                $agent = new CodeAgent([
                    'entreprise_id' => $entrepriseId,
                    'code' => $code,
                    'occurrences' => 0,
                    'est_actif' => true,
                ]);
            }

            if ($nom !== null && $agent->libelle !== $nom) {
                $etat[] = 'nom posé';
                $rangees['nommees']++;
                $agent->libelle = $nom;
            }

            if ($email !== null && $compte === null) {
                $etat[] = "compte « {$email} » introuvable";
                $rangees['refusees']++;
            } elseif ($compte !== null) {
                if ($agent->user_id !== null && $agent->user_id !== $compte->id) {
                    $etat[] = 'déjà rattaché à un autre compte — laissé tel quel';
                    $rangees['refusees']++;
                } elseif ($agent->user_id !== $compte->id) {
                    $etat[] = 'rattaché à '.$compte->name;
                    $rangees['rattachees']++;
                    $agent->user_id = $compte->id;
                }
            }

            if ($etat === []) {
                $rangees['inchangees']++;
                $etat[] = 'rien à changer';
            } elseif ($appliquer) {
                $agent->save();
            }

            $tableau[] = [
                $code,
                mb_substr((string) $nom, 0, 34),
                number_format((int) $agent->occurrences, 0, ',', ' '),
                implode(' · ', $etat),
            ];
        }

        $this->table(['Code', 'Nom dans le logiciel', 'Lignes', 'Effet'], $tableau);

        $this->newLine();
        $this->line(sprintf(
            '  %d créé(s) · %d nom(s) posé(s) · %d rattachement(s) · %d refus · %d inchangé(s)',
            $rangees['creees'], $rangees['nommees'], $rangees['rattachees'],
            $rangees['refusees'], $rangees['inchangees'],
        ));

        if (! $appliquer) {
            $this->newLine();
            $this->warn('  Rien n\'a été écrit. Relancez avec --appliquer pour enregistrer.');
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string|null, 2: string|null}> */
    private function lire(string $chemin): array
    {
        $lignes = [];

        foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $brute) {
            $brute = trim($brute);

            if ($brute === '' || str_starts_with($brute, '#')) {
                continue;
            }

            // Le séparateur n'est pas imposé : un tableur français exporte en « ; », un
            // export anglo-saxon en « , ». Refuser l'un des deux ferait perdre une heure
            // à quelqu'un pour une raison qu'aucun message n'expliquerait.
            $colonnes = array_map('trim', preg_split('/[;,\t]/', $brute) ?: []);
            $code = mb_strtoupper($colonnes[0] ?? '');

            if (! preg_match('/^[A-Z]{2}$/', $code)) {
                $this->warn("  Ligne ignorée (code non conforme) : {$brute}");

                continue;
            }

            $lignes[] = [
                $code,
                ($colonnes[1] ?? '') !== '' ? mb_substr($colonnes[1], 0, 120) : null,
                ($colonnes[2] ?? '') !== '' ? $colonnes[2] : null,
            ];
        }

        return $lignes;
    }

    private function entrepriseId(): ?int
    {
        if ($this->option('entreprise')) {
            return (int) $this->option('entreprise');
        }

        $entreprises = Entreprise::orderBy('id')->get();

        if ($entreprises->count() === 1) {
            return (int) $entreprises->first()->id;
        }

        $this->error('Plusieurs entreprises existent : précisez --entreprise=<id>.');

        foreach ($entreprises as $entreprise) {
            $this->line("  {$entreprise->id} — {$entreprise->nom}");
        }

        return null;
    }
}

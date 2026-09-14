<?php

namespace Modules\Noyau\Tracabilite\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Modules\Noyau\Tracabilite\Modeles\SessionUtilisateur;
use Modules\Noyau\Tracabilite\Modeles\VisiteEcran;

/**
 * Tient le journal des connexions et des écrans ouverts.
 *
 * Trois règles gouvernent tout ce fichier :
 *
 * 1. **Rien de ce qui est écrit ici ne doit empêcher une page de s'afficher.** Le journal
 *    est un témoin, pas un péage. Chaque écriture est donc enveloppée, et un incident de
 *    base laisse passer la requête au lieu de renvoyer une erreur 500 à quelqu'un qui
 *    voulait seulement consulter ses prospects.
 *
 * 2. **On n'enregistre que des pages.** Livewire envoie une requête à chaque frappe au
 *    clavier ; les compter comme des visites remplirait la table en une journée et
 *    donnerait une lecture fausse — cent « visites » pour un seul écran ouvert.
 *
 * 3. **La durée se ferme à l'arrivée de la suivante.** Une page n'annonce pas qu'on la
 *    quitte : c'est l'ouverture de la page d'après qui borne la précédente. La dernière
 *    d'une session est bornée par la dernière activité constatée.
 */
class JournalDeNavigation
{
    /** Clef sous laquelle la session porte le numéro de sa ligne de journal. */
    public const CLEF_SESSION = 'tracabilite_session';

    /**
     * Clef posée pendant qu'un administrateur assiste quelqu'un sous son identité.
     *
     * **Tant qu'elle est là, ce journal n'écrit rien.** C'est la seule façon honnête de
     * tenir un registre de présence : l'administrateur qui prend la place de quelqu'un pour
     * l'aider n'est pas cette personne, et le compte assisté n'a pas ouvert l'application.
     * Sans cette garde, une assistance de dix minutes apparaissait comme une connexion de
     * l'intéressé, avec ses écrans et sa durée — et l'écran de traçabilité mentait sur la
     * seule question qu'on lui pose.
     *
     * Le passage lui-même n'est pas perdu pour autant : il est consigné dans le journal
     * d'audit, au nom de l'administrateur, qui est bien celui qui agit.
     */
    public const CLEF_ASSISTANCE = 'switch.origine';

    /**
     * Ouvre une ligne de journal pour une connexion qui vient d'aboutir.
     */
    public function ouvrirSession(User $utilisateur, Request $requete): ?SessionUtilisateur
    {
        if ($this->enAssistance($requete)) {
            return null;
        }

        try {
            $agent = (string) $requete->userAgent();

            $session = SessionUtilisateur::create([
                'user_id' => $utilisateur->id,
                'entreprise_id' => $utilisateur->entreprise_id,
                // Lu en base plutôt que par getRoleNames() : hors du contexte d'une
                // entreprise, Spatie filtre par équipe et ne renverrait rien.
                'role' => $this->roleDe($utilisateur),
                'identifiant_session' => $requete->hasSession() ? $requete->session()->getId() : null,
                'adresse_ip' => $requete->ip(),
                'navigateur' => Str::limit($agent, 250, ''),
                'plateforme' => $this->plateforme($agent),
                'ouverte_le' => now(),
                'derniere_activite_le' => now(),
            ]);

            if ($requete->hasSession()) {
                $requete->session()->put(self::CLEF_SESSION, $session->id);
            }

            return $session;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Ferme la ligne de journal d'une session qui se termine.
     *
     * L'identifiant de la ligne est cherché d'abord dans la session (le chemin normal),
     * puis par l'identifiant de session Laravel : à la déconnexion, Fortify régénère la
     * session, et la clef posée à l'entrée peut avoir déjà disparu.
     */
    public function fermerSession(?int $ligneId, ?string $identifiantSession, string $motif = 'deconnexion'): void
    {
        try {
            $session = $ligneId
                ? SessionUtilisateur::find($ligneId)
                : ($identifiantSession
                    ? SessionUtilisateur::whereNull('fermee_le')
                        ->where('identifiant_session', $identifiantSession)
                        ->latest('ouverte_le')->first()
                    : null);

            if (! $session || $session->fermee_le !== null) {
                return;
            }

            $this->clore($session, now(), $motif);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Enregistre l'ouverture d'un écran et referme le précédent.
     *
     * @return void
     */
    public function enregistrerVisite(User $utilisateur, Request $requete): void
    {
        if ($this->enAssistance($requete)) {
            return;
        }

        try {
            $session = $this->sessionCourante($utilisateur, $requete);

            if (! $session) {
                return;
            }

            $maintenant = now();

            /*
             * La page précédente s'arrête à la dernière preuve de présence, et non à
             * l'instant présent : un écran laissé ouvert pendant la pause de midi, puis
             * quitté à 14 h, ne s'est pas « consulté » deux heures. `derniere_activite_le`
             * est le dernier moment où l'on sait quelqu'un devant l'écran.
             */
            $precedente = VisiteEcran::where('session_utilisateur_id', $session->id)
                ->where('duree_secondes', 0)
                ->orderByDesc('id')->first();

            if ($precedente) {
                $fin = $session->derniere_activite_le ?? $maintenant;

                $precedente->forceFill([
                    'duree_secondes' => max(0, (int) $precedente->vue_le->diffInSeconds($fin, absolute: false)),
                ])->save();
            }

            VisiteEcran::create([
                'session_utilisateur_id' => $session->id,
                'user_id' => $utilisateur->id,
                'entreprise_id' => $utilisateur->entreprise_id,
                'route' => $requete->route()?->getName(),
                'url' => Str::limit($requete->fullUrl(), 495, ''),
                'ecran' => NomDEcran::pour($requete->route()?->getName(), $requete->path()),
                'vue_le' => $maintenant,
            ]);

            $this->marquerActivite($session, $maintenant);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Prolonge une session sans y ajouter de visite : c'est ce qui distingue « encore
     * là » de « a ouvert un nouvel écran ». Appelé sur les requêtes Livewire, qui sont
     * une preuve de présence mais pas un changement de page.
     */
    public function marquerPresence(User $utilisateur, Request $requete): void
    {
        try {
            $session = $this->sessionCourante($utilisateur, $requete);

            if ($session) {
                $this->marquerActivite($session, now());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Referme les sessions qu'aucune déconnexion n'a closes : navigateur fermé, machine
     * éteinte, cookie expiré. Sans cela, elles resteraient « en ligne » pour toujours.
     *
     * @return int le nombre de sessions closes
     */
    public function cloturerLesSessionsInactives(): int
    {
        $limite = now()->subMinutes(SessionUtilisateur::MINUTES_AVANT_INACTIVITE);

        $closes = 0;

        SessionUtilisateur::whereNull('fermee_le')
            ->where('derniere_activite_le', '<', $limite)
            ->chunkById(200, function ($sessions) use (&$closes) {
                foreach ($sessions as $session) {
                    // La session s'arrête à la dernière preuve de présence, jamais à
                    // l'heure du nettoyage : sinon un oubli de tâche planifiée d'une
                    // semaine ferait apparaître des journées de sept jours.
                    $this->clore($session, $session->derniere_activite_le, 'expiration');
                    $closes++;
                }
            });

        return $closes;
    }

    /**
     * Efface les traces plus anciennes que la durée de conservation retenue.
     *
     * Un journal nominatif se garde le temps qu'il sert, et pas au-delà : passé quelques
     * mois, il ne renseigne plus sur personne et ne fait qu'accumuler des déplacements
     * individuels dans une base qui n'a plus de raison de les porter.
     *
     * @return array{sessions: int, visites: int}
     */
    public function purger(int $joursConserves = 180): array
    {
        $limite = now()->subDays(max(1, $joursConserves));

        // Les visites partent d'elles-mêmes par cascade, mais celles des sessions encore
        // vivantes doivent l'être aussi : une session ouverte depuis longtemps ne
        // justifie pas de garder son parcours d'il y a six mois.
        $visites = VisiteEcran::where('vue_le', '<', $limite)->delete();
        $sessions = SessionUtilisateur::whereNotNull('fermee_le')->where('ouverte_le', '<', $limite)->delete();

        return ['sessions' => $sessions, 'visites' => $visites];
    }

    /**
     * La ligne de journal de la session en cours.
     *
     * Rouvre une ligne si la session existe côté navigateur mais plus côté journal : cas
     * d'un déploiement, d'une purge, ou d'une session ouverte avant la mise en place du
     * journal. Sans cela, ces personnes resteraient invisibles jusqu'à leur prochaine
     * connexion — et l'écran de traçabilité mentirait par omission.
     */
    /**
     * Vrai pendant qu'un administrateur assiste quelqu'un sous son identité.
     *
     * La question est posée deux fois, à la requête puis au magasin de session global, et
     * ce n'est pas de la ceinture et des bretelles : l'événement de connexion ne porte pas
     * la requête, et selon d'où il est déclenché, celle qu'on retrouve peut n'avoir aucune
     * session attachée. Un garde qui laisse passer quand il ne sait pas est un garde qui
     * n'en est pas un — et ce qu'il laisserait passer ici, c'est une fausse connexion
     * inscrite au compte de quelqu'un.
     */
    private function enAssistance(Request $requete): bool
    {
        if ($requete->hasSession() && $requete->session()->has(self::CLEF_ASSISTANCE)) {
            return true;
        }

        return Session::isStarted() && Session::has(self::CLEF_ASSISTANCE);
    }

    private function sessionCourante(User $utilisateur, Request $requete): ?SessionUtilisateur
    {
        if (! $requete->hasSession() || $this->enAssistance($requete)) {
            return null;
        }

        $id = $requete->session()->get(self::CLEF_SESSION);

        if ($id) {
            $session = SessionUtilisateur::find($id);

            // Une ligne close ne se rouvre pas : on en ouvre une neuve, sans quoi deux
            // présences séparées par une nuit se liraient comme une seule.
            if ($session && $session->user_id === $utilisateur->id && $session->fermee_le === null) {
                return $session;
            }
        }

        return $this->ouvrirSession($utilisateur, $requete);
    }

    private function marquerActivite(SessionUtilisateur $session, \DateTimeInterface $instant): void
    {
        $ouverture = $session->ouverte_le ?? $instant;

        $session->forceFill([
            'derniere_activite_le' => $instant,
            'duree_secondes' => max(0, (int) $ouverture->diffInSeconds($instant, absolute: true)),
        ])->save();
    }

    private function clore(SessionUtilisateur $session, \DateTimeInterface $fin, string $motif): void
    {
        DB::transaction(function () use ($session, $fin, $motif) {
            $ouverture = $session->ouverte_le ?? $fin;

            $session->forceFill([
                'fermee_le' => $fin,
                'motif_fin' => $motif,
                'duree_secondes' => max(0, (int) $ouverture->diffInSeconds($fin, absolute: true)),
            ])->save();

            // Le dernier écran ouvert est borné par la fin de session, sans quoi il
            // resterait à zéro seconde alors qu'il a peut-être été le plus consulté.
            $derniere = VisiteEcran::where('session_utilisateur_id', $session->id)
                ->where('duree_secondes', 0)
                ->orderByDesc('id')->first();

            if ($derniere) {
                $derniere->forceFill([
                    'duree_secondes' => max(0, (int) $derniere->vue_le->diffInSeconds($fin, absolute: true)),
                ])->save();
            }
        });
    }

    private function roleDe(User $utilisateur): ?string
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.model_id', $utilisateur->id)
            ->value('roles.name');
    }

    /** Le système d'exploitation, tel qu'il s'annonce. Indicatif, jamais probant. */
    private function plateforme(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            $agent === '' => 'Inconnue',
            default => 'Autre',
        };
    }
}

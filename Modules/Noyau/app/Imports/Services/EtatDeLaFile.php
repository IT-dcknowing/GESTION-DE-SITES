<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Modeles\LotImport;

/**
 * Où en est le travail de fond — et surtout, y a-t-il quelqu'un pour le faire.
 *
 * **La question à laquelle cette classe répond.** « Le fichier est rangé, puis lu en
 * arrière-plan » : très bien, mais *par qui* ? Le traitement d'un import n'a lieu que si un
 * exécuteur de file tourne quelque part. S'il n'y en a pas, le dépôt réussit, l'écran dit
 * « Déposé », le compteur reste à zéro — et rien, absolument rien, ne dit que personne ne
 * viendra. On attend devant une progression qui n'a jamais commencé.
 *
 * On mesure donc trois choses, et on les montre :
 *
 * - **combien de travaux attendent** dans la file ;
 * - **combien de lots sont en cours** ou déposés sans avoir démarré ;
 * - **depuis combien de temps** le plus ancien attend — c'est le seul indice fiable de
 *   l'absence d'exécuteur, puisqu'un travail pris en charge disparaît de la file en
 *   quelques secondes.
 *
 * On ne prétend pas savoir si un `queue:work` tourne : rien, dans la base, ne le dit avec
 * certitude. On dit ce qu'on observe, et on laisse conclure — c'est plus honnête qu'un
 * voyant vert qui se tromperait.
 */
class EtatDeLaFile
{
    /**
     * Au-delà, un travail qui attend n'attend plus : personne ne le prend.
     *
     * Deux minutes laissent le temps à un exécuteur occupé par un gros fichier de finir
     * avant qu'on ne l'accuse d'être absent.
     */
    public const PATIENCE_EN_SECONDES = 120;

    public function __construct(private int $entrepriseId) {}

    /**
     * @return array{
     *     en_file: int, echecs: int, en_cours: int, en_attente: int,
     *     plus_ancien: ?int, executeur_douteux: bool, parallele: bool,
     * }
     */
    public function mesurer(): array
    {
        $enFile = 0;
        $echecs = 0;
        $plusAncien = null;

        // La file de la base est la seule qu'on sache interroger. Sur Redis ou SQS on ne
        // rend rien plutôt que d'inventer : un chiffre faux serait pire que pas de chiffre.
        if (config('queue.default') === 'database') {
            $enFile = DB::table('jobs')->count();
            $echecs = DB::table('failed_jobs')->count();
            $depart = DB::table('jobs')->min('available_at');
            $plusAncien = $depart === null ? null : max(0, time() - (int) $depart);
        }

        $enCours = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('etat', 'en_cours')->count();

        $enAttente = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('etat', 'depose')->count();

        return [
            'en_file' => $enFile,
            'echecs' => $echecs,
            'en_cours' => $enCours,
            'en_attente' => $enAttente,
            'plus_ancien' => $plusAncien,
            /*
             * Un travail qui traîne depuis plus de deux minutes sans que rien ne bouge :
             * c'est le signe qu'aucun exécuteur ne tourne, et c'est le moment de le dire.
             *
             * **Mais seulement si cette entreprise a réellement un lot en attente.** La
             * table `jobs` est commune à toute la plateforme et garde les travaux qu'aucun
             * exécuteur n'a pris, y compris ceux dont le lot a été traité à la main depuis
             * ou annulé. Sans cette condition, le bandeau annonçait « un traitement attend
             * depuis 2 640 minutes » à une entreprise dont les douze lots étaient terminés :
             * trois travaux résiduels le maintenaient allumé indéfiniment.
             *
             * Un avertissement permanent qu'on ne peut pas faire taire cesse d'être lu —
             * et le jour où un import restera vraiment bloqué, on ne le verra pas.
             */
            'executeur_douteux' => $plusAncien !== null
                && $plusAncien > self::PATIENCE_EN_SECONDES
                && $enCours + $enAttente > 0,
            // Plusieurs dépôts peuvent attendre ensemble : rien ne l'interdit, et c'est
            // même l'usage normal — on dépose les trois villes à la suite.
            'parallele' => $enFile + $enCours + $enAttente > 1,
        ];
    }
}

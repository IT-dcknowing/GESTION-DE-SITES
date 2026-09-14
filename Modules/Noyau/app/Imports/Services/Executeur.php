<?php

namespace Modules\Noyau\Imports\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Formats\Format;
use Modules\Noyau\Imports\Formats\Resultat;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Lecteurs\LecteurCorrige;
use Modules\Noyau\Imports\Modeles\LigneRejeteeImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Throwable;

/**
 * Fait tourner un import, et répond de ce qu'il a fait.
 *
 * Trois garanties, et ce sont elles qui justifient que cette classe existe plutôt que de
 * laisser chaque format se débrouiller :
 *
 * **Tout ou rien.** Le parcours entier tient dans une transaction. Un import qui casse à la
 * ligne 4 212 ne laisse pas 4 211 fiches à moitié rattachées derrière lui : la base
 * retrouve l'état exact qu'elle avait avant le dépôt. Sur une base qui porte des écritures
 * réelles, c'est non négociable.
 *
 * **La simulation ne ment pas.** En mode contrôle, le travail est fait pour de vrai —
 * lecture, analyse, rattachement, comptage — puis la transaction est annulée. « Rien n'a
 * été écrit » n'est donc pas une promesse tenue par la discipline du code, mais par la
 * base elle-même. C'est la seule forme de cette promesse à laquelle on puisse se fier.
 *
 * **On sait toujours où on en est.** Le rappel d'avancée est appelé régulièrement pendant
 * la lecture, ce qui permet à l'écran d'afficher autre chose qu'un sablier immobile sur un
 * fichier de neuf mille lignes.
 */
class Executeur
{
    /** Tous les combien de lignes on prévient l'écran de l'avancée. */
    public const PAS_D_AVANCEE = 100;

    public function __construct(private int $entrepriseId) {}

    /**
     * Traite le fichier d'un lot et met le lot à jour.
     *
     * @param  class-string<Format>  $format
     * @param  Closure(int): void|null  $surAvancee  reçoit le nombre de lignes déjà lues
     */
    public function traiter(LotImport $lot, string $format, bool $ecrire = true, ?Closure $surAvancee = null): Resultat
    {
        $lot->forceFill([
            'etat' => 'en_cours',
            'demarre_le' => now(),
            'message' => null,
        ])->save();

        $rattachement = new Rattachement($this->entrepriseId);
        $lecteur = null;

        try {
            // Le fichier reçu n'est jamais réécrit : il est la pièce d'origine, et son
            // empreinte le prouve. Les corrections faites depuis se posent par-dessus au
            // moment de la lecture — l'import voit les valeurs corrigées, le disque garde
            // celles du fichier, et l'écart entre les deux reste consultable.
            $lecteur = LecteurCorrige::envelopper(
                Classeur::ouvrir($lot->chemin()),
                (new CorrectionsDUnLot($this->entrepriseId))->carte($lot),
            );

            $resultat = $this->dansUneTransaction(
                $ecrire,
                fn () => (new $format($this->entrepriseId, $rattachement))->parcourir(
                    $lecteur,
                    $lot->ville_id,
                    $ecrire ? $lot->id : null,
                    $ecrire,
                    $this->cadencer($surAvancee),
                    $lot->site_id,
                ),
            );
        } catch (Throwable $panne) {
            $lot->forceFill([
                'etat' => 'echec',
                'termine_le' => now(),
                // Le message d'une exception peut contenir un chemin de serveur ; on ne
                // renvoie à l'écran que ce que le module a lui-même formulé.
                'message' => $this->messageLisible($panne),
            ])->save();

            $this->fermer($lecteur);

            throw $panne;
        }

        $this->fermer($lecteur);

        // Les rencontres de codes et d'orthographes sont écrites hors de la transaction du
        // parcours : même sur une simulation, savoir quels codes ont été croisés est un
        // renseignement utile, et il ne modifie aucune donnée métier.
        $rattachement->terminer();

        if ($ecrire) {
            $this->journaliserLesRejets($lot, $resultat);
        }

        $lot->forceFill([
            'etat' => $ecrire ? 'termine' : 'controle',
            'termine_le' => now(),
            'lignes_lues' => $resultat->lues,
            'lignes_creees' => $resultat->creees,
            'lignes_majs' => $resultat->majs,
            'lignes_ignorees' => $resultat->ignorees,
            'lignes_rejetees' => $resultat->rejetees,
            'message' => $resultat->resume(),
        ])->save();

        return $resultat;
    }

    /**
     * Exécute le parcours sous transaction, et annule quand on ne fait que simuler.
     *
     * L'annulation volontaire passe par une exception dédiée : c'est la façon la plus sûre
     * de garantir qu'aucun chemin de sortie n'oublie le `rollBack`.
     */
    private function dansUneTransaction(bool $ecrire, Closure $travail): Resultat
    {
        if ($ecrire) {
            return DB::transaction($travail);
        }

        $resultat = null;

        try {
            DB::transaction(function () use ($travail, &$resultat) {
                $resultat = $travail();

                throw new SimulationTerminee;
            });
        } catch (SimulationTerminee) {
            // Attendu : la transaction vient d'être annulée, la base n'a pas bougé.
        }

        return $resultat ?? new Resultat;
    }

    /** N'appelle l'écran que de loin en loin : prévenir à chaque ligne coûte plus cher que lire. */
    private function cadencer(?Closure $surAvancee): ?Closure
    {
        if ($surAvancee === null) {
            return null;
        }

        return function (int $lues) use ($surAvancee) {
            if ($lues % self::PAS_D_AVANCEE === 0) {
                $surAvancee($lues);
            }
        };
    }

    private function journaliserLesRejets(LotImport $lot, Resultat $resultat): void
    {
        // Un nouveau passage remplace le journal du précédent : ce qui compte est ce que
        // le dernier traitement a refusé, pas l'accumulation des tentatives.
        $lot->rejets()->delete();

        foreach (array_chunk($resultat->rejets, 200) as $paquet) {
            $lignes = [];

            foreach ($paquet as $rejet) {
                $lignes[] = [
                    'lot_import_id' => $lot->id,
                    'feuille' => $resultat->feuille,
                    'numero_ligne' => $rejet['ligne'],
                    'motif' => mb_substr($rejet['motif'], 0, 255),
                    'valeurs' => json_encode($rejet['valeurs'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            LigneRejeteeImport::insert($lignes);
        }
    }

    private function fermer(?object $lecteur): void
    {
        try {
            $lecteur?->fermer();
        } catch (Throwable) {
            // Un fichier qui refuse de se refermer n'a pas à faire échouer un import réussi.
        }
    }

    /**
     * Ce qu'on accepte de montrer d'une panne.
     *
     * Les exceptions du module sont écrites pour être lues par l'utilisateur ; les autres
     * peuvent porter un chemin de serveur, un fragment de requête ou un nom de table, et
     * n'ont rien à faire dans une interface.
     */
    private function messageLisible(Throwable $panne): string
    {
        return $panne instanceof \RuntimeException
            ? mb_substr($panne->getMessage(), 0, 500)
            : "Le traitement s'est interrompu. Le fichier n'a pas été importé et la base n'a pas changé.";
    }
}

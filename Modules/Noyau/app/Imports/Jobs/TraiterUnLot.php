<?php

namespace Modules\Noyau\Imports\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Executeur;
use Modules\Noyau\Imports\Services\ImportArrete;
use Modules\Noyau\Imports\Services\SuiviDuTraitement;
use Throwable;

/**
 * Le traitement d'un lot, hors du temps de la requête.
 *
 * Le raisonnement est celui qu'on tient d'habitude pour les imports volumineux, et il vaut
 * ici : on **range le fichier, on répond tout de suite, on travaille après**. Lire les
 * 9 004 lignes de l'état des impayés dans la requête qui reçoit le téléversement, c'est
 * une page qui tourne trente secondes, un navigateur qui abandonne, et un utilisateur qui
 * redépose — donc deux traitements pour un fichier.
 *
 * Ce que l'écran suit pendant ce temps n'est pas un sablier décoratif : le nombre de lignes
 * lues est écrit sur le lot au fil de la lecture, et la page le relit. La progression
 * affichée est la vraie.
 *
 * **Un seul essai, volontairement.** Un import qui échoue à mi-chemin a déjà tout annulé —
 * la transaction s'en charge — mais le rejouer d'office ferait repartir un traitement lourd
 * sur une cause qui n'a pas disparu : un fichier mal formé le reste. Le lot passe en échec
 * avec son motif, et c'est une personne qui décide de relancer.
 */
class TraiterUnLot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Large, parce qu'un gros fichier est lent sans être en panne. */
    public int $timeout = 900;

    public function __construct(
        public LotImport $lot,
        public bool $ecrire = true,
    ) {}

    public function handle(): void
    {
        $lot = $this->lot->fresh();

        // Le lot peut être lancé par deux chemins — le processus détaché démarré au dépôt, et
        // la file d'attente qui sert de filet. Un seul le prend ; l'autre trouve le travail
        // commencé, ou fini, et s'en va sans rien faire. C'est aussi ce qui empêche un message
        // livré deux fois de lire deux fois le même fichier.
        if ($lot === null || ! SuiviDuTraitement::prendre($lot)) {
            return;
        }

        try {
            (new Executeur((int) $lot->entreprise_id))->traiter(
                $lot->fresh(),
                Registre::classe($lot->format),
                $this->ecrire,
                // L'avancée part dans le cache et non sur la ligne du lot : celle-ci est
                // verrouillée par la transaction de l'import. Voir SuiviDuTraitement.
                fn (int $lues) => SuiviDuTraitement::avancer($lot->id, $lues),
            );
        } catch (ImportArrete) {
            // Voulu, et déjà écrit sur le lot par l'exécuteur : ce n'est pas une panne.
        } finally {
            SuiviDuTraitement::oublier($lot->id);
        }
    }

    /**
     * Ce qu'on laisse derrière quand la file elle-même abandonne.
     *
     * Le cas visé est celui que l'exécuteur ne voit pas : dépassement de délai, processus
     * arrêté net. Sans ce filet, le lot resterait « en cours » pour toujours et l'écran
     * afficherait une progression figée que personne ne saurait interpréter.
     */
    public function failed(?Throwable $panne): void
    {
        LotImport::withoutGlobalScopes()->whereKey($this->lot->id)->update([
            'etat' => 'echec',
            'termine_le' => now(),
            'message' => "Le traitement s'est interrompu. Rien n'a été importé : la base est "
                .'restée dans l\'état où elle était avant le dépôt.',
        ]);
    }
}

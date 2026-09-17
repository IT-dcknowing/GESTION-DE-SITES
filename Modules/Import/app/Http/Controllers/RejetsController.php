<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\CorrectionsDUnLot;
use RuntimeException;

/**
 * Enregistrer les corrections d'un import, et le rejouer dans la foulée.
 *
 * **Le raisonnement.** Une ligne refusée n'est pas une erreur de l'application : c'est une
 * donnée que le fichier ne portait pas correctement — un montant vide, une date illisible.
 * Jusqu'ici la seule issue était de rouvrir le classeur, de le corriger et de tout
 * redéposer : neuf mille lignes relues pour trois cellules, et un fichier corrigé qui
 * revenait comme un fichier neuf, sans lien avec celui qu'il remplaçait.
 *
 * On corrige donc ici, puis on relance. Deux gestes, deux boutons, dans cet ordre — parce
 * qu'enregistrer sans relancer est un cas réel : on corrige deux lignes le matin, la
 * troisième demande un coup de fil, et l'import attend.
 *
 * **Rien n'est écrit dans le fichier.** Les corrections sont conservées à part et posées
 * par-dessus au moment de la relecture. Le fichier reste la pièce d'origine, son empreinte
 * le prouve, et la page de traçabilité montre ce qui sépare l'un de l'autre.
 */
class RejetsController
{
    public function store(Request $requete, LotImport $lot): RedirectResponse
    {
        if (! AccesImport::peutDeposer($requete->user())) {
            return back()->with('refus-import', "Votre rôle ne permet pas de corriger un import.");
        }

        $requete->validate([
            'valeurs' => ['nullable', 'array'],
            'retirer' => ['nullable', 'array'],
            'motif' => ['nullable', 'string', 'max:255'],
            'relancer' => ['nullable'],
        ]);

        // Les numéros de ligne postés ne servent qu'à retrouver des rejets existants : le
        // service les recoupe avec le journal, et tout ce qui n'y figure pas est ignoré.
        $saisies = [];

        foreach ((array) $requete->input('valeurs', []) as $numero => $colonnes) {
            if (! is_array($colonnes) || ! is_numeric($numero)) {
                continue;
            }

            $saisies[(int) $numero] = array_map(
                fn ($v) => is_scalar($v) ? (string) $v : '',
                array_filter($colonnes, fn ($cle) => is_numeric($cle), ARRAY_FILTER_USE_KEY),
            );
        }

        $retirees = array_values(array_map('intval', array_filter(
            (array) $requete->input('retirer', []),
            fn ($v) => is_numeric($v),
        )));

        $service = new CorrectionsDUnLot((int) $lot->entreprise_id);

        try {
            $compte = $service->enregistrer(
                $lot,
                $saisies,
                $retirees,
                $requete->user(),
                $requete->ip(),
                mb_substr((string) $requete->userAgent(), 0, 255),
                $requete->input('motif'),
            );
        } catch (RuntimeException $panne) {
            return back()->with('refus-import', $panne->getMessage());
        }

        $message = sprintf(
            '%d ligne(s) corrigée(s), %d retirée(s). Le fichier déposé, lui, n\'a pas changé.',
            $compte['corrigees'],
            $compte['retirees'],
        );

        if ($compte['corrigees'] === 0 && $compte['retirees'] === 0) {
            return back()->with('refus-import', "Aucune modification n'a été relevée : rien n'a été enregistré.");
        }

        if (! $requete->boolean('relancer')) {
            return back()->with('annonce-import', $message.' Relancez l\'import quand vous serez prêt.');
        }

        // La relance démarre à part, comme un dépôt, et l'on suit son avancée sur la page du
        // dépôt. Elle se faisait dans la requête : la page restait blanche le temps de relire
        // neuf mille lignes, sans rien montrer, et pouvait être coupée par le délai du serveur.
        try {
            (new Depot((int) $lot->entreprise_id))->relancer($lot, simuler: false);
        } catch (\RuntimeException $panne) {
            return back()->with('refus-import', $message.' La relance est impossible : '.$panne->getMessage());
        }

        return redirect()
            ->route('import.depot', ['lot' => $lot->id])
            ->with('annonce-import', $message.' La lecture a redémarré : son avancée s\'affiche ci-dessous.');
    }
}

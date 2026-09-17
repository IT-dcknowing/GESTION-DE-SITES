<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AnnulationDUnLot;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\SuiviDuTraitement;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les gestes qu'on pose sur un dépôt déjà fait : le rejouer, l'annuler, le relire.
 *
 * **Pourquoi ces quatre boutons ne faisaient rien.** Ils étaient posés sur la couche
 * interactive, comme le dépôt l'était avant eux. Dans un navigateur où celle-ci ne démarre
 * pas, un `wire:click` est un bouton peint : il n'y a ni requête, ni erreur, ni trace — le
 * clic ne va nulle part. Ce sont maintenant des formulaires qui postent, et le pire qui
 * puisse leur arriver est un message d'erreur à l'écran.
 *
 * **Il n'y a pas de geste « traiter ».** La lecture démarre d'elle-même au dépôt comme à la
 * relance — voir LanceurDeTraitement. Les boutons « Traiter maintenant » et « Tout traiter »
 * existaient parce qu'elle ne démarrait pas toujours ; ils ont disparu avec ce défaut. Le seul
 * geste posé sur une lecture en cours est de l'**arrêter**.
 */
class LotController
{
    /**
     * Ce qu'on accepte de faire à un lot, et rien d'autre.
     *
     * « Recontrôler » et « traiter » en sont sortis avec leurs boutons. Un geste que plus
     * aucune interface ne propose mais que l'adresse accepte encore est une porte qu'on
     * laisse entrouverte sans la surveiller : ici elle relançait la lecture d'un fichier de
     * neuf mille lignes. La relance immédiate après correction, elle, n'est pas perdue —
     * elle vit dans {@see RejetsController}, à l'endroit où elle a un sens.
     */
    public const GESTES = ['reimporter', 'annuler', 'arreter'];

    public function agir(Request $requete, LotImport $lot): RedirectResponse
    {
        if (! AccesImport::peutDeposer($requete->user())) {
            return back()->with('refus-import', "Votre rôle ne permet pas d'agir sur un import.");
        }

        $requete->validate([
            'geste' => ['required', Rule::in(self::GESTES)],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        $geste = (string) $requete->input('geste');

        try {
            $message = match ($geste) {
                'reimporter' => $this->relancer($lot),
                'annuler' => $this->annuler($requete, $lot),
                // Arrêter une lecture en cours, ou un dépôt qui n'a pas encore commencé.
                // « annuler », lui, défait un import terminé.
                'arreter' => SuiviDuTraitement::demanderLArret(
                    $lot, (int) $requete->user()->id, (string) $requete->user()->name,
                ),
            };
        } catch (RuntimeException $panne) {
            return back()->with('refus-import', $panne->getMessage());
        }

        return back()->with('annonce-import', $message);
    }

    /**
     * Le fichier tel qu'il a été déposé, pour un contrôle ou un audit.
     *
     * Le fichier dort hors du serveur web et n'a aucune URL propre : on le sert par une
     * réponse, après avoir vérifié l'entreprise et le rôle. Le nom rendu est celui du dépôt,
     * pas l'empreinte sous laquelle il est rangé — personne n'a besoin de savoir comment on
     * range nos fichiers, et l'auditeur veut retrouver le nom qu'il a envoyé.
     */
    public function fichier(Request $requete, LotImport $lot): StreamedResponse
    {
        abort_unless(AccesImport::peutVoir($requete->user(), 'lots'), 403);

        if (! $lot->fichierPresent()) {
            abort(404, "Le fichier de ce dépôt n'est plus conservé.");
        }

        return Storage::disk(LotImport::DISQUE)->download(
            $lot->cheminRelatif(),
            $this->nomDeTelechargement($lot),
        );
    }

    private function relancer(LotImport $lot): string
    {
        (new Depot((int) $lot->entreprise_id))->relancer($lot, simuler: false);

        return "Lecture relancée : elle a démarré, son avancée s'affiche ci-dessous.";
    }


    private function annuler(Request $requete, LotImport $lot): string
    {
        $motif = trim((string) $requete->input('motif'));

        if ($motif === '') {
            throw new RuntimeException("Dites pourquoi : c'est ce motif qu'on relira pour comprendre.");
        }

        $compte = (new AnnulationDUnLot((int) $lot->entreprise_id))
            ->annuler($lot, $motif, (int) $requete->user()->id);

        return trim(sprintf(
            '%d ligne(s) supprimée(s). %s Le fichier reste déposé : vous pouvez le redéposer autrement.',
            $compte['supprimees'],
            $compte['retenues'] > 0
                ? $compte['retenues'].' ligne(s) retenue(s) parce que retouchées depuis.'
                : '',
        ));
    }

    /** Un nom de fichier propre, sans rien qui puisse s'échapper de l'en-tête HTTP. */
    private function nomDeTelechargement(LotImport $lot): string
    {
        $nom = preg_replace('/[^\w\-. ()éèêàùôîïçÉÈÊÀÙÔÎÏÇ]/u', '_', (string) $lot->nom_fichier);
        $nom = trim((string) $nom);

        return $nom !== '' ? $nom : 'import-'.$lot->id.'.xlsx';
    }
}

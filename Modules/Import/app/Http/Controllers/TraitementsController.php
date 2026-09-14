<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Modeles\LotImport;
use Throwable;

/**
 * Les traitements en cours : les suivre, et les lancer quand personne ne vient.
 *
 * **Pourquoi cet écran existe.** Un import part en file d'attente : le fichier est rangé, la
 * page rend la main, un exécuteur travaille de son côté. Encore faut-il qu'un exécuteur
 * tourne. Quand il n'y en a pas — poste de développement, hébergement mutualisé, service
 * arrêté — le dépôt réussit, l'écran affiche « Déposé », le compteur reste à zéro, et
 * **rien ne dit que personne ne viendra jamais**. On attendait devant une progression qui
 * n'avait pas commencé.
 *
 * D'où les deux gestes de cette page. Suivre ce qui tourne, et **prendre le travail en
 * main** si rien ne le prend : « Tout traiter » fait le travail dans la requête, lot après
 * lot, et rend la main quand c'est fini.
 *
 * **Le nombre de lots traités d'un coup est borné, et il faut dire pourquoi.** Une requête
 * web a un temps limité — celui du serveur, puis celui du navigateur. Traiter cinquante
 * fichiers à la suite finirait par une coupure au milieu du vingtième, et un lot coupé en
 * plein travail est précisément ce qu'on passe son temps à éviter. On en prend quelques-uns,
 * on le dit, et on invite à recommencer. Un traitement borné qui aboutit vaut mieux qu'un
 * traitement illimité qui casse.
 */
class TraitementsController
{
    /** Combien de lots on accepte de traiter dans une seule requête. */
    public const LOTS_PAR_PASSAGE = 5;

    public function tout(Request $requete): RedirectResponse
    {
        if (! AccesImport::peutDeposer($requete->user())) {
            return back()->with('refus-import', "Votre rôle ne permet pas de lancer un traitement.");
        }

        $enAttente = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $requete->user()->entreprise_id)
            ->whereIn('etat', ['depose', 'echec'])
            ->orderBy('created_at')
            ->limit(self::LOTS_PAR_PASSAGE)
            ->get();

        if ($enAttente->isEmpty()) {
            return back()->with('annonce-import', "Aucun traitement n'attend : la file est vide.");
        }

        $faits = 0;
        $casses = [];

        foreach ($enAttente as $lot) {
            try {
                $lot->forceFill(['etat' => 'depose', 'message' => null, 'lignes_lues' => 0])->save();
                (new TraiterUnLot($lot, true))->handle();
                $faits++;
            } catch (Throwable) {
                // Un fichier qui casse n'empêche pas les suivants : son lot porte déjà son
                // motif d'échec, et l'écran le montrera à côté des autres.
                $casses[] = $lot->nom_fichier;
            }
        }

        $message = $faits.' traitement(s) terminé(s).';

        if ($casses !== []) {
            return back()->with('refus-import', $message.' En échec : '.implode(', ', $casses)
                .'. Ouvrez leur dépôt dans le journal pour connaître le motif.');
        }

        $reste = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $requete->user()->entreprise_id)
            ->where('etat', 'depose')->count();

        return back()->with(
            'annonce-import',
            $message.($reste > 0 ? ' Il en reste '.$reste.' : relancez pour les prendre.' : ''),
        );
    }

    /**
     * L'état des traitements, en JSON — de quoi prévenir n'importe quel écran.
     *
     * **Pourquoi une adresse plutôt qu'un composant vivant.** Un import se termine pendant
     * qu'on travaille ailleurs : on est sur la balance âgée, et le parc vient de finir de se
     * charger. Il faut le dire là où la personne se trouve, pas seulement sur la page de
     * l'import. Une petite adresse que n'importe quelle page interroge de loin en loin fait
     * ce travail sans rien devoir à la couche interactive.
     *
     * La réponse est volontairement pauvre : des identifiants, des états, des compteurs.
     * Aucun nom de client, aucun montant — c'est une sonnette, pas une fenêtre.
     */
    public function etat(Request $requete): JsonResponse
    {
        $utilisateur = $requete->user();

        if (! AccesImport::peutVoir($utilisateur, 'lots')) {
            return response()->json(['en_cours' => [], 'termines' => []]);
        }

        $lots = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $utilisateur->entreprise_id)
            ->where(fn ($q) => $q->whereIn('etat', ['depose', 'en_cours'])
                // Ce qui vient de finir : la fenêtre est courte, parce qu'on annonce un
                // événement, pas un état. Un import terminé ce matin n'a rien à dire.
                ->orWhere(fn ($r) => $r->whereIn('etat', ['termine', 'echec'])
                    ->where('termine_le', '>=', now()->subMinutes(10))))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'nom_fichier', 'format', 'etat', 'lignes_lues', 'lignes_creees', 'lignes_rejetees', 'termine_le']);

        return response()->json([
            'en_cours' => $lots->whereIn('etat', ['depose', 'en_cours'])->map(fn (LotImport $l) => [
                'id' => $l->id,
                'fichier' => $l->nom_fichier,
                'etat' => $l->etat,
                'lues' => (int) $l->lignes_lues,
            ])->values(),
            'termines' => $lots->whereIn('etat', ['termine', 'echec'])->map(fn (LotImport $l) => [
                'id' => $l->id,
                'fichier' => $l->nom_fichier,
                'etat' => $l->etat,
                'lues' => (int) $l->lignes_lues,
                'creees' => (int) $l->lignes_creees,
                'rejetees' => (int) $l->lignes_rejetees,
                'fini_le' => $l->termine_le?->toIso8601String(),
            ])->values(),
        ]);
    }
}

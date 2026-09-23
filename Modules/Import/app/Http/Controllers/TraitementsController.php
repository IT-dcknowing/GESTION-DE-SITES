<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\CommercialDeLaFiche;
use Modules\Noyau\Imports\Services\SuiviDuTraitement;

/**
 * L'état des traitements, pour la veille posée dans la mise en page.
 *
 * **« Tout traiter » a disparu.** Il faisait la lecture dans la requête, lot après lot, pour
 * rattraper les dépôts qu'aucun exécuteur de file ne prenait. La lecture démarre désormais
 * d'elle-même au dépôt — voir LanceurDeTraitement — et ce bouton ne servait plus qu'à doubler
 * un geste déjà fait. Son adresse est fermée avec lui : un geste qu'aucune interface ne propose
 * mais qu'une adresse accepte encore est une porte qu'on ne surveille plus.
 */
class TraitementsController
{
    /**
     * Reconnaître les commerciaux nommés sur les fiches de réception.
     *
     * **Pourquoi ici, et pas sur l'écran de dépôt.** La lecture d'un fichier démarre au
     * dépôt et se poursuit sur cette page ; c'est donc ici qu'on apprend qu'un nom n'a pas
     * été reconnu, et ici qu'on doit pouvoir répondre. Poser la question sur l'écran de
     * dépôt obligerait à y revenir alors qu'on vient de le quitter.
     *
     * **Ce que ce geste écrit.** Une correspondance, et les fiches déjà lues sous ce
     * libellé. La correspondance vaut pour tous les dépôts suivants : une faute de frappe
     * ne se demande qu'une fois. Rien d'autre ne bouge — ni les devis, ni les prospections,
     * qui se rapprochent sur leur propre écran, avec leur propre confirmation.
     *
     * **Un formulaire qui poste.** Comme le dépôt et comme l'annuaire des codes : un écran
     * de référentiel n'a aucune raison de dépendre de la couche interactive.
     */
    public function reconnaitreLesCommerciaux(Request $requete): RedirectResponse
    {
        $utilisateur = $requete->user();

        if (! AccesImport::peutArbitrer($utilisateur)) {
            return back()->withErrors([
                'commerciaux' => 'Reconnaître un commercial relève du gérant ou du superviseur de ville.',
            ]);
        }

        $donnees = $requete->validate([
            'reponses' => ['required', 'array'],
            'reponses.*' => ['nullable', 'integer'],
        ], [
            'reponses.required' => 'Aucune réponse à enregistrer.',
        ]);

        $entrepriseId = (int) $utilisateur->entreprise_id;
        $service = new CommercialDeLaFiche($entrepriseId);

        $libelles = CorrespondanceImport::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('domaine', CommercialDeLaFiche::DOMAINE)
            ->where('est_resolue', false)
            ->pluck('valeur_source', 'id');

        $reconnus = 0;
        $fiches = 0;

        foreach ($donnees['reponses'] as $correspondanceId => $commercialId) {
            // Laissé vide, c'est « je ne sais pas encore » : la question reste posée. Y
            // répondre à moitié vaut mieux que d'être obligé de tout trancher d'un coup.
            if (! $commercialId || ! isset($libelles[(int) $correspondanceId])) {
                continue;
            }

            $reprises = $service->repondre($libelles[(int) $correspondanceId], (int) $commercialId);

            $reconnus++;
            $fiches += $reprises;
        }

        return back()->with('message-commerciaux', $reconnus === 0
            ? "Rien n'a été enregistré : aucun nom n'a reçu de réponse."
            : $reconnus.' nom(s) reconnu(s), '.$fiches.' fiche(s) rattachée(s).');
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
                'lues' => SuiviDuTraitement::etat($l)['lues'],
                'pourcentage' => SuiviDuTraitement::etat($l)['pourcentage'],
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

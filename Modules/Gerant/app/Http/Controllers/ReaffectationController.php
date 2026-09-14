<?php

namespace Modules\Gerant\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Noyau\Entreprises\Services\ReaffecterUnEmploye;
use RuntimeException;

/**
 * La mutation d'un employé, en requête HTTP ordinaire.
 *
 * Même raison que pour le dépôt d'un fichier : c'est un geste qui engage la base — il change
 * ce qu'une personne voit et où elle saisit — et il ne doit pas pouvoir échouer en silence
 * parce qu'un script ne s'est pas chargé. Un formulaire qui poste, ou bien la page change,
 * ou bien elle affiche une erreur.
 *
 * Toute la règle métier est dans {@see ReaffecterUnEmploye}. Ce contrôleur ne fait que trois
 * choses : vérifier que c'est bien le gérant qui demande, passer les valeurs, et rapporter.
 */
class ReaffectationController
{
    public function store(Request $requete): RedirectResponse
    {
        $decideur = $requete->user();

        // Le gérant, et lui seul. Déplacer quelqu'un change son périmètre de saisie : c'est
        // une décision d'organisation, pas un réglage.
        if (! $decideur->hasRole('gerant')) {
            return back()->withErrors(['employe' => "Seul le gérant réaffecte un employé."]);
        }

        $donnees = $requete->validate([
            'employe' => ['required', 'integer'],
            'ville' => ['required', 'integer'],
            'site' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'max:60'],
            'motif' => ['nullable', 'string', 'max:255'],
        ], [
            'employe.required' => 'Choisissez la personne à réaffecter.',
            'ville.required' => 'Indiquez la ville de destination.',
        ]);

        $employe = User::where('entreprise_id', $decideur->entreprise_id)->find($donnees['employe']);

        if ($employe === null) {
            return back()->withErrors(['employe' => "Cette personne n'existe pas dans votre entreprise."]);
        }

        try {
            $mouvement = (new ReaffecterUnEmploye((int) $decideur->entreprise_id))->deplacer(
                $employe,
                (int) $donnees['ville'],
                ($donnees['site'] ?? null) ? (int) $donnees['site'] : null,
                ($donnees['role'] ?? '') !== '' ? $donnees['role'] : null,
                $donnees['motif'] ?? null,
                $decideur,
            );
        } catch (RuntimeException $refus) {
            return back()->withErrors(['employe' => $refus->getMessage()])->withInput();
        }

        return redirect()
            ->route('parametres', ['onglet' => 'reaffectations'])
            ->with('message-reaffectation', sprintf(
                '%s est désormais rattaché à %s. Son travail passé reste où il a été fait, et il continue de le consulter.',
                $employe->name,
                $mouvement->siteApres?->nom ?? $mouvement->villeApres?->nom ?? 'son nouveau lieu',
            ));
    }
}

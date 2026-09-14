<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Import\Support\AccesImport;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Nommer un employé du logiciel d'atelier, et dire où il travaille.
 *
 * **Un formulaire qui poste, pas une action interactive.** Le bouton « Renseigner » ne
 * répondait pas, et la cause n'était pas dans cette page : la couche interactive ne
 * démarrait pas dans le navigateur, et tout ce qui en dépendait restait muet. Un écran de
 * référentiel n'a aucune raison d'en dépendre — on ouvre une ligne, on la corrige, on
 * enregistre. Trois requêtes ordinaires.
 *
 * **Ce que cet écran décide, selon le fichier.** Quand l'extraction a été filtrée sur un
 * atelier dans le logiciel — Site 1, Site 2, Bouaké, San Pédro — et que ce filtre est
 * déclaré au dépôt, c'est la déclaration qui range les lignes : le code n'a rien à trancher.
 *
 * Mais tous les exports ne se filtrent pas, et certains ne se filtreront jamais : ils
 * sortent d'un bloc, les trois villes mêlées. Ceux-là se déposent sous « Toutes les villes »,
 * et **ce sont alors les codes tenus ici qui ventilent chaque ligne**. Renseigner l'annuaire
 * n'est donc pas un confort de lecture : c'est ce qui rend ces dépôts-là exploitables.
 *
 * Dans les deux cas il sert en plus à lire un nom en clair dans les tableaux plutôt qu'un
 * code, à rattacher les devis à leur commercial pour les indicateurs, et à prévenir au dépôt
 * quand les codes d'un fichier désignent une autre ville que celle déclarée.
 */
class CodesController
{
    public function update(Request $requete): RedirectResponse
    {
        $utilisateur = $requete->user();

        if (! AccesImport::peutArbitrer($utilisateur)) {
            return back()->withErrors(['code' => "Tenir l'annuaire relève du gérant ou du superviseur de ville."]);
        }

        $entrepriseId = (int) $utilisateur->entreprise_id;

        $donnees = $requete->validate([
            'code' => ['required', 'string', 'size:2'],
            'libelle' => ['nullable', 'string', 'max:120'],
            'ville' => ['nullable', 'integer'],
            'site' => ['nullable', 'integer'],
        ], [
            'code.required' => 'Aucun code à enregistrer.',
            'code.size' => 'Un code du logiciel fait exactement deux lettres.',
        ]);

        $agent = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)
            ->where('code', mb_strtoupper($donnees['code']))
            ->first();

        if ($agent === null) {
            return back()->withErrors(['code' => 'Ce code ne figure pas dans l\'annuaire.']);
        }

        $villeId = ($donnees['ville'] ?? null) ? (int) $donnees['ville'] : null;
        $siteId = ($donnees['site'] ?? null) ? (int) $donnees['site'] : null;

        // La ville doit exister chez nous, et l'atelier lui appartenir. Sans ces deux
        // vérifications, une valeur forgée rattacherait un employé à l'atelier d'une autre
        // entreprise — et, de proche en proche, ses fiches avec.
        if ($villeId !== null && ! Ville::withoutGlobalScopes()
            ->where('entreprise_id', $entrepriseId)->whereKey($villeId)->exists()) {
            return back()->withErrors(['code' => "Cette ville n'est pas la vôtre."]);
        }

        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()
                ->where('entreprise_id', $entrepriseId)->whereKey($siteId)->first();

            if ($site === null) {
                return back()->withErrors(['code' => "Cet atelier n'est pas le vôtre."]);
            }

            if ($villeId !== null && (int) $site->ville_id !== $villeId) {
                return back()->withErrors(['code' => "Cet atelier n'appartient pas à la ville choisie."]);
            }

            $villeId ??= (int) $site->ville_id;
        }

        $agent->forceFill([
            'libelle' => ($donnees['libelle'] ?? '') !== '' ? $donnees['libelle'] : $agent->libelle,
            'ville_id' => $villeId,
            'site_id' => $siteId,
        ])->save();

        return redirect()
            ->route('import.codes')
            ->with('message-code', sprintf('« %s » enregistré.', $agent->code));
    }
}

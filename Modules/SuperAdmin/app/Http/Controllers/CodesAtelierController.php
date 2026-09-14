<?php

namespace Modules\SuperAdmin\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;

/**
 * Donner à chacun son code d'atelier, depuis la plateforme.
 *
 * **Pourquoi cet écran est ici et non dans le module Import.** L'annuaire des codes du
 * module Import se lit code par code : « KZ, c'est quelle ville, quel atelier ? ». C'est la
 * bonne question quand on range des fichiers. Ce n'est pas celle qu'on se pose quand on
 * ouvre les accès d'une entreprise, où l'on part des personnes : « Koffi Yao, quel est son
 * code ? ». Deux entrées sur la même donnée, et la seconde manquait — trente-huit codes en
 * base, aucun rattaché à quelqu'un.
 *
 * **Un formulaire qui poste, une ligne par personne.** Pas de couche interactive : on tape
 * deux lettres, on enregistre, la page revient avec son message. Le geste marche dans
 * n'importe quel navigateur, ce qui compte pour un écran d'administration qu'on ouvre
 * rarement et souvent en déplacement.
 *
 * **Le super administrateur est le seul à pouvoir déplacer un code.** Quand deux lettres
 * sont déjà portées par quelqu'un, chacun se voit refuser la reprise depuis son profil —
 * sans quoi on s'attribuerait les fiches d'un collègue. Ici elle est permise, parce que
 * c'est justement le rôle : arbitrer. Le déplacement est annoncé en clair et inscrit au
 * journal des deux côtés.
 */
class CodesAtelierController
{
    public function update(Request $requete): RedirectResponse
    {
        $donnees = $requete->validate([
            'utilisateur' => ['required', 'integer', 'exists:users,id'],
            'code' => ['nullable', 'string', 'max:2'],
            'entreprise' => ['nullable', 'integer'],
        ], [
            'utilisateur.required' => 'Aucune personne désignée.',
            'code.max' => "Un code d'atelier fait exactement deux lettres.",
        ]);

        $personne = User::withoutGlobalScopes()->findOrFail($donnees['utilisateur']);
        $retour = ['entreprise' => $donnees['entreprise'] ?? $personne->entreprise_id];

        // Les comptes de la plateforme ne travaillent dans aucun atelier : leur donner un
        // code rattacherait des fiches à quelqu'un qui n'en produit pas.
        if (! $personne->entreprise_id) {
            return redirect()->route('super-admin.codes', $retour)
                ->with('refus-code', "« {$personne->name} » n'appartient à aucune entreprise : un code d'atelier n'aurait rien à désigner.");
        }

        try {
            $resultat = CodeDeLAtelier::attribuer(
                $personne,
                $donnees['code'] ?? '',
                $requete->user(),
                deplacerSiPris: true,
            );
        } catch (InvalidArgumentException $refus) {
            return redirect()->route('super-admin.codes', $retour)->with('refus-code', $refus->getMessage());
        }

        return redirect()->route('super-admin.codes', $retour)
            ->with('annonce', $this->raconter($personne, $resultat));
    }

    /**
     * Ce qui vient de se passer, dit en une phrase.
     *
     * Le déplacement est nommé explicitement : reprendre un code à quelqu'un déplace aussi
     * tout ce que les prochains imports lui rattacheront, et l'administrateur doit lire ce
     * qu'il vient de faire plutôt que de le découvrir dans un tableau de chiffre d'affaires.
     *
     * @param  array{code: ?string, ancien: ?string, repris_a: ?string}  $resultat
     */
    private function raconter(User $personne, array $resultat): string
    {
        if ($resultat['code'] === null) {
            return "Code d'atelier retiré à « {$personne->name} ».";
        }

        if ($resultat['code'] === $resultat['ancien']) {
            return "« {$personne->name} » portait déjà le code {$resultat['code']} : rien n'a changé.";
        }

        $phrase = "Code {$resultat['code']} attribué à « {$personne->name} »";

        if ($resultat['repris_a'] !== null) {
            $phrase .= ", repris à « {$resultat['repris_a']} »";
        }

        return $phrase.'. Il lui sera demandé de le confirmer à sa prochaine connexion.';
    }
}

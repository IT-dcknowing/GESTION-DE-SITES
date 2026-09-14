<?php

namespace Modules\Noyau\Commun\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;

/**
 * Rattacher son compte à son code du logiciel d'atelier — en requête ordinaire.
 *
 * **Le champ existait, le bouton ne répondait pas.** Il reposait sur la couche interactive,
 * comme le dépôt d'import avant lui : dans un navigateur où celle-ci ne démarre pas, on
 * tapait ses deux lettres, on cliquait, et rien ne se passait — sans message ni erreur. Or
 * c'est le seul écran où chacun peut relier lui-même son travail dans l'atelier à son compte
 * ici, sans passer par un administrateur.
 *
 * **Vider le champ détache le code.** C'est le geste attendu quand on s'est trompé de
 * personne, et il ne doit pas obliger à écrire à quelqu'un.
 *
 * **Le refus « ce code est déjà celui de quelqu'un » arrivait en page blanche.** Le service
 * lève une `InvalidArgumentException` ; on n'attrapait qu'une `RuntimeException`, qui n'en
 * est pas la parente. Le seul cas d'erreur prévu par cet écran était donc le seul qu'il ne
 * savait pas rattraper. Il est maintenant rendu tel quel à la personne, avec le nom de qui
 * porte le code.
 */
class EnregistrerLaLiaison
{
    public function __invoke(Request $requete): RedirectResponse
    {
        $utilisateur = $requete->user();
        $saisi = trim((string) $requete->input('liaison'));

        try {
            // Personne ne reprend le code d'un collègue depuis son propre profil : c'est
            // un arbitrage, et il revient à l'administrateur de la plateforme.
            $resultat = CodeDeLAtelier::attribuer($utilisateur, $saisi, $utilisateur, deplacerSiPris: false);
        } catch (InvalidArgumentException $refus) {
            return back()->with('refus-profil', $refus->getMessage());
        }

        if ($resultat['code'] === null) {
            return back()->with('annonce', "Code d'atelier retiré.");
        }

        return back()->with('annonce', "Code d'atelier enregistré : ".$resultat['code'].'.');
    }
}

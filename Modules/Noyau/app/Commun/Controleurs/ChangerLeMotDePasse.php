<?php

namespace Modules\Noyau\Commun\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changer son mot de passe — en requête ordinaire, et sans redemander ce qu'on vient de taper.
 *
 * **Deux défauts corrigés d'un coup, et ils se tenaient.**
 *
 * Le premier est le plus grave. L'écran reposait sur la couche interactive : dans un
 * navigateur où celle-ci ne démarre pas, le bouton « Enregistrer » ne faisait rien. Or cet
 * écran est un passage obligé — tant que le mot de passe provisoire n'est pas remplacé,
 * l'application ne laisse aller nulle part ailleurs. Un bouton muet à cet endroit n'est pas
 * une gêne : c'est une porte fermée sur un compte qu'on vient de créer. C'est donc un
 * formulaire HTML qui poste, comme le dépôt d'import et pour la même raison.
 *
 * **Le second : le mot de passe actuel n'est plus demandé au premier passage.** Il faut
 * distinguer deux situations que l'écran confondait.
 *
 * - *Changement volontaire*, depuis son espace personnel. Le mot de passe actuel est
 *   exigé, et il le reste : c'est ce qui empêche un passant de s'emparer d'un poste laissé
 *   ouvert et d'en changer la serrure. On ne touche pas à cette garde.
 * - *Premier passage imposé*, quand l'accès a été créé avec un mot de passe provisoire
 *   remis de la main à la main. La personne vient de le saisir à l'écran de connexion,
 *   quelques secondes plus tôt : c'est ce qui lui a ouvert la session. Le redemander ne
 *   prouve rien de plus, et demande de retenir un mot de passe qu'on est précisément en
 *   train de remplacer. Le parcours par courriel ne le demande pas non plus — l'écran de
 *   première connexion ne fait que proposer d'en choisir un.
 *
 * La garde qui reste au premier passage est la session elle-même : on n'entre ici qu'après
 * s'être authentifié, et le drapeau retombe dès le mot de passe choisi.
 */
class ChangerLeMotDePasse
{
    public function __invoke(Request $requete): RedirectResponse
    {
        $utilisateur = $requete->user();

        // Relu sur le compte, jamais sur ce que le formulaire prétend : un champ caché
        // annonçant « première connexion » suffirait sans cela à sauter la vérification.
        $impose = (bool) $utilisateur->doit_changer_mot_de_passe;

        $regles = [
            'nouveauMotDePasse' => ['required', 'confirmed', Password::min(8)],
        ];

        if (! $impose) {
            $regles['motDePasseActuel'] = ['required', 'current_password'];
        }

        $donnees = $requete->validate($regles, [], [
            'motDePasseActuel' => 'mot de passe actuel',
            'nouveauMotDePasse' => 'nouveau mot de passe',
        ]);

        // Remplacer un mot de passe par lui-même laisse croire au changement sans rien
        // changer : le provisoire, connu d'un tiers, resterait en service.
        if (Hash::check($donnees['nouveauMotDePasse'], (string) $utilisateur->password)) {
            return back()->withErrors([
                'nouveauMotDePasse' => 'Choisissez un mot de passe différent de celui en cours.',
            ]);
        }

        $utilisateur->forceFill([
            'password' => Hash::make($donnees['nouveauMotDePasse']),
            'doit_changer_mot_de_passe' => false,
        ])->save();

        // Identifiant de session renouvelé : celui qui avait cours pendant que le mot de
        // passe provisoire circulait ne vaut plus rien.
        $requete->session()->regenerate();

        return redirect()->route('redirection')
            ->with('annonce', 'Mot de passe enregistré.');
    }
}

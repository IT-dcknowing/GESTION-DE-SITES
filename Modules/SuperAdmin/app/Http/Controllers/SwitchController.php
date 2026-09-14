<?php

namespace Modules\SuperAdmin\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\SuperAdmin\Services\ModeSwitch;
use RuntimeException;

/**
 * Entrer dans le compte de quelqu'un pour l'assister, et en ressortir.
 *
 * **Deux routes, deux gardes différentes, et c'est voulu.**
 *
 * L'entrée est réservée aux administrateurs de la plateforme, habilitation à l'appui. La
 * **sortie ne l'est pas** : pendant le détour, l'utilisateur authentifié est la personne
 * assistée, qui n'a évidemment pas ce rôle. Fermer la sortie derrière la même habilitation
 * enfermerait l'administrateur dans le compte qu'il vient d'ouvrir — il n'aurait plus qu'à
 * se déconnecter, en perdant sa propre session. La sortie se garde donc autrement : elle ne
 * fait rien si aucune identité d'origine n'est en session, et cette identité n'a pu y être
 * posée que par une entrée légitime.
 *
 * Le passage lui-même n'ouvre **aucune connexion** au nom de la personne assistée : le
 * journal de présence s'en abstient tant que le drapeau est posé. Il est en revanche
 * consigné dans le journal d'audit, au nom de l'administrateur — qui est bien celui qui agit.
 */
class SwitchController
{
    public function entrer(Request $requete, User $utilisateur): RedirectResponse
    {
        try {
            ModeSwitch::entrer($requete->user(), $utilisateur);
        } catch (RuntimeException $panne) {
            return back()->with('refus-switch', $panne->getMessage());
        }

        // On atterrit à la racine : c'est le routeur d'accueil qui sait où mène chaque rôle,
        // et l'assistance commence par là où la personne commence elle-même.
        return redirect('/')->with(
            'annonce-switch',
            'Mode switch actif : vous voyez l\'application comme '.$utilisateur->name.'.'
        );
    }

    public function sortir(Request $requete): RedirectResponse
    {
        $origine = ModeSwitch::sortir();

        if ($origine === null) {
            return redirect('/');
        }

        return redirect()->route('super-admin.acces.index')
            ->with('annonce-switch', 'Mode switch quitté : vous êtes de nouveau '.$origine->name.'.');
    }
}

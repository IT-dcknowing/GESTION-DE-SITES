<?php

namespace Modules\Noyau\Commun\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;
use Modules\Noyau\Entreprises\Services\VilleDeTravail;

/**
 * Changer d'année ou de ville regardée — par une requête ordinaire.
 *
 * Ces deux sélecteurs ne modifient rien en base : ce sont des loupes, rangées dans la
 * session. Mais ils commandent **ce que tous les écrans calculent**, et ils étaient posés
 * dans des composants interactifs. Le jour où cette couche n'a pas démarré, les deux listes
 * affichaient un choix qui ne prenait jamais effet : on croyait consulter San Pédro et l'on
 * lisait les chiffres de l'entreprise entière. C'est la pire des pannes — celle qui ne dit
 * rien et donne des chiffres.
 *
 * Un formulaire, une redirection, et l'affaire est close. On revient d'où l'on vient pour
 * que le changement se voie sur l'écran qu'on regardait, et pas ailleurs.
 */
class ChoisirLaLoupe
{
    public function ville(Request $requete): RedirectResponse
    {
        $choix = $requete->input('ville');

        VilleDeTravail::choisir($choix === null || $choix === '' ? null : (int) $choix);

        return $this->retour($requete);
    }

    public function exercice(Request $requete): RedirectResponse
    {
        $annee = $requete->integer('annee');

        ExerciceDeTravail::choisir($annee > 0 ? $annee : null);

        return $this->retour($requete);
    }

    /**
     * Revenir sur l'écran d'où vient la demande.
     *
     * On n'accepte que les adresses de l'application : un `Referer` est écrit par le
     * navigateur mais reste, à la lettre, une valeur envoyée par le client — et rediriger
     * vers ce qu'un client demande est le mécanisme même de la redirection ouverte.
     */
    private function retour(Request $requete): RedirectResponse
    {
        $venue = (string) $requete->headers->get('referer', '');

        if ($venue !== '' && str_starts_with($venue, (string) config('app.url'))) {
            return redirect()->to($venue);
        }

        return redirect()->route('redirection');
    }
}

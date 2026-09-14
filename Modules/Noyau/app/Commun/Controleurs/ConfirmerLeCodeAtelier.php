<?php

namespace Modules\Noyau\Commun\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;

/**
 * « Oui, c'est bien mon code. »
 *
 * Un geste, une requête, une ligne au journal. La boîte qui pose la question s'affiche sur
 * tous les écrans tant qu'on n'y a pas répondu ; ici on y répond.
 *
 * **Pourquoi la réponse « non » n'a pas de contrôleur.** Dire non n'est pas une décision à
 * enregistrer, c'est le début d'une correction : la boîte ouvre alors le champ de saisie, et
 * c'est {@see EnregistrerLaLiaison} qui reçoit le bon code. Écrire un refus en base sans
 * savoir ce qui le remplace ne servirait qu'à faire disparaître la question.
 */
class ConfirmerLeCodeAtelier
{
    public function __invoke(Request $requete): RedirectResponse
    {
        $utilisateur = $requete->user();
        $code = CodeDeLAtelier::de($utilisateur);

        if ($code === null) {
            return back();
        }

        CodeDeLAtelier::confirmer($utilisateur);

        return back()->with('annonce', 'Code d\'atelier « '.$code->code.' » confirmé. Merci.');
    }
}

<?php

namespace Modules\Noyau\Commun\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * « Où vous joindre ? » — le numéro qu'on n'a pas pu saisir à l'ouverture du compte.
 *
 * **Pourquoi la personne le donne elle-même.** Les accès s'ouvrent en série, souvent depuis
 * une liste de noms : le numéro manque pour une bonne part d'entre eux, et courir après
 * trente numéros un par un n'arrive jamais. Or c'est précisément de ce numéro qu'on a
 * besoin le jour où une fiche pose question — un code d'atelier qui ne correspond à rien,
 * un montant qui ne tombe pas juste. Sans lui, on renonce à demander, et l'écart reste.
 *
 * La boîte le demande là où la personne se trouve, une fois, et se tait dès qu'elle a
 * répondu. Comme la question du code, c'est un formulaire qui poste : elle marche dans un
 * navigateur où la couche interactive ne démarre pas.
 *
 * **Chacun écrit le sien, et rien d'autre.** Le numéro est posé sur le compte connecté, pris
 * de la session — jamais sur un identifiant reçu du formulaire, qui permettrait de modifier
 * la fiche de quelqu'un d'autre. Le super administrateur, lui, le corrige depuis l'écran des
 * accès : c'est le même champ, et il se met donc à jour des deux côtés.
 */
class EnregistrerSonTelephone
{
    /**
     * Ce qu'on accepte comme numéro.
     *
     * Volontairement large : les numéros s'écrivent « 0707070707 », « 07 07 07 07 07 »,
     * « +225 07 07 07 07 07 », et refuser une de ces formes ferait renoncer à en donner un.
     * On exige seulement huit chiffres — en deçà, ce n'est pas un numéro, c'est une faute
     * de frappe qu'il vaut mieux signaler tout de suite que composer plus tard.
     */
    public const FORMAT = '/^[0-9+().\s-]{8,40}$/';

    public function __invoke(Request $requete): RedirectResponse
    {
        $donnees = Validator::make($requete->all(), [
            'telephone' => ['required', 'string', 'max:40', 'regex:'.self::FORMAT],
        ], [
            'telephone.required' => 'Indiquez un numéro où vous joindre.',
            'telephone.regex' => "Ce numéro n'en a pas la forme : chiffres, espaces et « + » seulement, huit chiffres au moins.",
        ])->validate();

        $numero = trim($donnees['telephone']);

        // Le motif laisse passer « ++++++++ » : c'est le compte des chiffres qui tranche.
        if (preg_match_all('/\d/', $numero) < 8) {
            return back()->withErrors([
                'telephone' => "Ce numéro n'en a pas la forme : huit chiffres au moins.",
            ]);
        }

        // Écrit sur le compte connecté, jamais sur un identifiant reçu : un formulaire qui
        // désignerait sa cible laisserait modifier la fiche d'un collègue.
        $requete->user()->forceFill(['telephone' => $numero])->save();

        activity()
            ->causedBy($requete->user())
            ->performedOn($requete->user())
            ->log('Numéro de téléphone renseigné par son titulaire');

        return back()->with('annonce', 'Numéro enregistré : '.$numero.'. Merci.');
    }
}

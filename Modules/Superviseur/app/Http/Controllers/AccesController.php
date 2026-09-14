<?php

namespace Modules\Superviseur\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Actions\ModifierAcces;
use Modules\Noyau\Entreprises\Actions\RenvoyerLAcces;
use Modules\Noyau\Entreprises\Actions\SupprimerAcces;
use Modules\Noyau\Entreprises\Support\HierarchieAcces;
use Modules\Noyau\Entreprises\Support\PeutModifierUnAcces;
use RuntimeException;

/**
 * Les gestes qu'on pose sur l'accès de quelqu'un d'autre, en requête HTTP ordinaire.
 *
 * **Pourquoi ce contrôleur existe.** Révoquer, renvoyer, supprimer, modifier : tout cela
 * reposait sur la couche interactive. Dans un navigateur où celle-ci ne démarre pas, le
 * bouton ne fait rien du tout — pas d'erreur, pas de trace. Or il s'agit ici de fermer ou
 * d'ouvrir l'accès de quelqu'un : c'est exactement le genre de geste qui ne doit jamais
 * échouer en silence, ni réussir sans qu'on le sache.
 *
 * **Le droit d'agir se vérifie ici, sur chaque appel.** Un bouton absent de l'écran n'a
 * jamais fermé une adresse : la question « ai-je le droit d'agir sur cette personne » se
 * tranche à partir des deux comptes, par {@see HierarchieAcces}, et l'écran ne fait que
 * refléter la réponse.
 */
class AccesController
{
    /** Ce qu'on accepte de faire, et rien d'autre. */
    public const GESTES = ['basculer', 'renvoyer', 'supprimer'];

    public function agir(Request $requete, User $utilisateur): RedirectResponse
    {
        $acteur = $requete->user();
        $motif = HierarchieAcces::motifDuRefus($acteur, $utilisateur);

        if ($motif !== null) {
            return back()->with('refus-acces', $motif);
        }

        $requete->validate(['geste' => ['required', Rule::in(self::GESTES)]]);

        try {
            $message = match ((string) $requete->input('geste')) {
                'basculer' => $this->basculer($utilisateur),
                'renvoyer' => $this->renvoyer($acteur, $utilisateur),
                'supprimer' => $this->supprimer($acteur, $utilisateur),
            };
        } catch (RuntimeException $panne) {
            return back()->with('refus-acces', $panne->getMessage());
        }

        return back()->with('annonce-acces', $message);
    }

    /**
     * Reprendre un accès : ses coordonnées, son rôle, son périmètre.
     *
     * **Ce qui est ouvert et ce qui ne l'est pas**, et pourquoi la distinction tient :
     *
     * - l'**entreprise** ne bouge pas. Un compte qui a servi y a laissé ses écritures, et
     *   le déplacer les abandonnerait derrière lui ;
     * - le **rôle** se reprend dans les deux sens. Une écriture porte un lieu et un auteur,
     *   jamais un rôle : une facture est rattachée à un site, pas à « responsable de site ».
     *   La changer de rôle ne déplace donc rien de ce qui a été saisi. Deux réserves
     *   seulement — on ne devient pas gérant par ici, et on ne démet pas le dernier gérant
     *   d'une entreprise ;
     * - la **ville et l'atelier** passent par la réaffectation, qui garde l'historique et
     *   laisse à l'intéressé la lecture de son ancien poste. Les changer en silence ferait
     *   disparaître son travail passé de son écran sans que rien ne l'explique.
     */
    public function update(Request $requete, User $utilisateur, ModifierAcces $action): RedirectResponse
    {
        $acteur = $requete->user();
        $motif = HierarchieAcces::motifDuRefus($acteur, $utilisateur);

        if ($motif !== null) {
            return back()->with('refus-acces', $motif);
        }

        $permission = new PeutModifierUnAcces($acteur, $utilisateur);

        $donnees = $requete->validate([
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utilisateur->id)],
            'telephone' => ['nullable', 'string', 'max:40'],
            'motDePasse' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(array_keys($permission->rolesAtteignables()))],
            'objectifMecanique' => ['nullable', 'integer', 'min:0'],
            'objectifSinistre' => ['nullable', 'integer', 'min:0'],
        ], [
            'role.in' => "Ce rôle ne peut pas être attribué depuis cet écran.",
            'email.unique' => 'Cette adresse est déjà celle d\'un autre accès.',
            'motDePasse.min' => 'Un mot de passe fait au moins huit caractères.',
        ]);

        $role = (string) $donnees['role'];

        if ($role !== $permission->roleActuel() && ! $permission->roleModifiable()) {
            return back()->with('refus-acces', $permission->motifDuRoleFige());
        }

        // Le périmètre ne se reprend pas ici : il suit la réaffectation. On repasse donc
        // celui du compte tel qu'il est, pour que l'action le repose à l'identique.
        $changements = $action->executer(
            $utilisateur,
            $role,
            [
                'nom' => $donnees['nom'],
                'email' => $donnees['email'],
                'telephone' => $donnees['telephone'] ?? null,
                'mot_de_passe' => $donnees['motDePasse'] ?? null,
                'entreprise_id' => $utilisateur->entreprise_id,
                'ville_id' => $utilisateur->ville_id,
                'site_id' => $utilisateur->site_id,
                'objectif_mecanique' => (int) ($donnees['objectifMecanique'] ?? 0),
                'objectif_sinistre' => (int) ($donnees['objectifSinistre'] ?? 0),
            ],
            structureModifiable: false,
            roleModifiable: $permission->roleModifiable(),
        );

        $resume = $changements === []
            ? 'Accès enregistré.'
            : 'Accès enregistré — '.implode(', ', array_map(
                fn ($cle, $valeur) => $cle.' : '.$valeur,
                array_keys($changements),
                $changements,
            )).'.';

        return redirect()->route('acces.creer')->with('annonce-acces', $resume);
    }

    private function basculer(User $utilisateur): string
    {
        if ($utilisateur->est_actif) {
            $utilisateur->update(['est_actif' => false]);

            return "Accès de {$utilisateur->name} révoqué.";
        }

        // C'est l'action qui ouvre l'accès, car c'est elle qui sait souhaiter la bienvenue :
        // un accès préparé inactif n'a reçu aucun courriel, il le reçoit maintenant.
        app(CreerAcces::class)->activer($utilisateur);

        return "Accès de {$utilisateur->name} activé — courriel de bienvenue envoyé.";
    }

    private function renvoyer(User $acteur, User $utilisateur): string
    {
        $bilan = app(RenvoyerLAcces::class)->executer($acteur, $utilisateur);

        return "Courriel renvoyé à {$utilisateur->name}"
            .($bilan['active'] ? " — l'accès, encore fermé, vient d'être ouvert." : '.')
            .($bilan['lien'] === 'definition'
                ? ' Il contient un lien pour choisir son mot de passe.'
                : ' Ce compte est déjà en service : son mot de passe est inchangé.');
    }

    private function supprimer(User $acteur, User $utilisateur): string
    {
        $bilan = app(SupprimerAcces::class)->executer($acteur, $utilisateur);

        return "Accès de {$utilisateur->name} supprimé — fiche commerciale : {$bilan['fiche commerciale']}.";
    }
}

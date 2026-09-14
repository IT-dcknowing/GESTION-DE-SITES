<?php

namespace Modules\Noyau\Entreprises\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ce qu'on a le droit de reprendre sur l'accès de quelqu'un — et ce qui reste fermé.
 *
 * **Un seul verrou en tenait trois, et deux d'entre eux n'avaient pas lieu d'être.** Le
 * raisonnement d'origine tenait en une phrase : « cet accès a déjà servi, son rôle et son
 * entreprise ne se reprennent plus, ses écritures y sont rattachées ». La seconde moitié
 * est vraie ; la première en tire une conclusion trop large. Car **une écriture n'est pas
 * rattachée à un rôle** : une facture porte un site et un auteur, jamais « responsable de
 * site ». Changer le rôle de quelqu'un ne déplace donc rien — cela change ce qu'il verra
 * demain, pas ce qu'il a écrit hier.
 *
 * Trois questions distinctes, donc, et trois réponses :
 *
 * - **l'entreprise** reste figée dès que le compte a servi. Déplacer un compte d'une
 *   société à l'autre laisserait ses écritures dans la première, et le cloisonnement entre
 *   clients est ce qu'on ne négocie pas ;
 * - **le rôle** se reprend, dans les deux sens, avec deux réserves. On ne devient pas
 *   gérant par une modification d'accès — le gérant ne saisit pas, il répond de toute
 *   l'entreprise, et son accès se crée. Et l'on ne retire pas son rôle au dernier gérant
 *   d'une entreprise, ce qui la laisserait sans personne pour nommer qui que ce soit, y
 *   compris pour réparer l'erreur ;
 * - **la ville et l'atelier** passent par la réaffectation, qui garde l'histoire et laisse
 *   à l'intéressé la lecture de son ancien poste. Les changer ici, en silence, ferait
 *   disparaître son travail passé de son écran sans que rien ne l'explique.
 *
 * Cette classe ne fait que répondre. Elle n'écrit rien, et c'est pour cela qu'elle peut
 * servir aussi bien à décider d'un affichage qu'à fermer une requête.
 */
class PeutModifierUnAcces
{
    /** Ce qui ne s'obtient pas par une modification d'accès. */
    public const HORS_ATTEINTE = ['gerant', 'super_admin'];

    public function __construct(
        private User $acteur,
        private User $cible,
    ) {}

    /** Le rôle réellement inscrit en base, sans dépendre de l'équipe posée dans la requête. */
    public function roleActuel(): string
    {
        return (string) DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.model_id', $this->cible->id)
            ->value('roles.name');
    }

    /** Vrai si retirer son rôle à ce compte laisserait son entreprise sans gérant. */
    public function estLeDernierGerant(): bool
    {
        if ($this->roleActuel() !== 'gerant') {
            return false;
        }

        return ! DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', 'gerant')
            ->where('users.entreprise_id', $this->cible->entreprise_id)
            ->where('users.id', '!=', $this->cible->id)
            ->exists();
    }

    public function roleModifiable(): bool
    {
        return ! $this->estLeDernierGerant();
    }

    public function motifDuRoleFige(): string
    {
        return "C'est le dernier gérant de cette entreprise : lui retirer son rôle la laisserait "
            ."sans direction, et sans personne pour réparer l'erreur.";
    }

    /**
     * Les rôles vers lesquels cet accès peut basculer.
     *
     * Deux bornes se combinent : ce que l'acteur a le droit de nommer — on ne nomme que
     * strictement sous soi — et ce qui ne s'obtient jamais par ici.
     *
     * Le rôle actuel figure toujours dans la liste, même s'il n'est plus attribuable :
     * sans lui, enregistrer un simple changement de numéro de téléphone déplacerait la
     * personne dans un autre rôle sans que rien ne l'annonce.
     *
     * @return array<string, string>
     */
    public function rolesAtteignables(): array
    {
        $noms = match (true) {
            $this->acteur->hasRole('super_admin') => [
                'gerant', 'responsable_ville', 'responsable_site', 'commercial', 'caissier',
                'superviseur_recouvrement', 'agent_recouvrement',
            ],
            $this->acteur->hasRole('gerant') => [
                'responsable_ville', 'responsable_site', 'commercial', 'caissier',
                'superviseur_recouvrement', 'agent_recouvrement',
            ],
            $this->acteur->hasRole('responsable_ville') => ['responsable_site', 'commercial', 'caissier'],
            $this->acteur->hasRole('superviseur_recouvrement') => ['agent_recouvrement'],
            default => [],
        };

        if (! $this->acteur->hasRole('super_admin')) {
            $noms = array_values(array_diff($noms, self::HORS_ATTEINTE));
        }

        $actuel = $this->roleActuel();

        if ($actuel !== '' && ! in_array($actuel, $noms, true)) {
            array_unshift($noms, $actuel);
        }

        $liste = [];

        foreach ($noms as $nom) {
            $liste[$nom] = LibellesRoles::de($nom);
        }

        return $liste;
    }
}

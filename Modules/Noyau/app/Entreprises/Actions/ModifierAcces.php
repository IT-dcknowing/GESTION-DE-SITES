<?php

namespace Modules\Noyau\Entreprises\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\ChoixDeLieu;
use Modules\Noyau\Entreprises\Support\ChoixDeVille;
use Modules\Noyau\Entreprises\Support\RolesCommerciaux;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reprend un accès existant : ses coordonnées, son périmètre, et son rôle.
 *
 * Changer un rôle n'est pas changer un libellé. Le rôle est ce qui ouvre les écrans,
 * mais il détermine aussi trois choses qui vivent ailleurs : la ville ou le lieu dont
 * la personne répond, la désignation portée par cette ville ou ce lieu, et l'existence
 * d'une fiche commerciale. Ne toucher qu'à l'un des quatre laisse un compte incohérent
 * — un superviseur encore inscrit comme responsable de son ancienne ville, ou un
 * commercial devenu comptable et qui continue de figurer dans les objectifs.
 *
 * C'est pourquoi tout se fait ici, d'un bloc et dans une transaction : ou les quatre
 * suivent, ou rien ne bouge.
 */
class ModifierAcces
{
    /** Rôles dont le titulaire prospecte : il doit exister comme commercial. */
    /** Voir RolesCommerciaux : la liste vit là, pour n'exister qu'une fois. */
    private const ROLES_COMMERCIAUX = RolesCommerciaux::TOUS;

    /**
     * @param  array<string, mixed>  $donnees  nom, email, telephone, mot_de_passe, entreprise_id, ville_id, site_id, objectifs
     * @param  bool  $structureModifiable  faux quand l'entreprise du compte est figée :
     *                                     il a servi, ses écritures y sont rattachées, et
     *                                     le déplacer les laisserait derrière lui. Le
     *                                     **rôle**, lui, n'emporte aucune écriture — voir
     *                                     $roleModifiable.
     * @param  bool  $roleModifiable  vrai quand le rôle peut être repris. Une écriture porte
     *                                un lieu et un auteur, jamais un rôle : la changer de
     *                                rôle ne déplace donc rien.
     * @return array<string, string> ce qui a changé, pour l'annoncer sans le deviner
     */
    public function executer(User $compte, string $role, array $donnees, bool $structureModifiable, bool $roleModifiable = false): array
    {
        return DB::transaction(function () use ($compte, $role, $donnees, $structureModifiable, $roleModifiable) {
            $ancienRole = $this->roleActuel($compte);
            $changements = [];

            $compte->forceFill([
                'name' => $donnees['nom'],
                'email' => $donnees['email'],
                'telephone' => ($donnees['telephone'] ?? null) ?: null,
            ]);

            if (! empty($donnees['mot_de_passe'])) {
                // Reposer un mot de passe coupe la connexion en cours du titulaire : on
                // ne le fait que si le champ a été rempli, jamais en corrigeant une adresse.
                $compte->password = Hash::make($donnees['mot_de_passe']);
                $compte->doit_changer_mot_de_passe = true;
                $changements['mot de passe'] = 'remplacé';
            }

            $compte->save();

            if (! $structureModifiable) {
                // L'entreprise est figée. Le rôle, lui, peut suivre : il ne déplace aucune
                // écriture, et refuser de le reprendre obligeait à créer un second compte
                // pour la même personne — deux comptes dont un seul porte l'historique.
                if ($roleModifiable && $role !== $ancienRole) {
                    DB::table('villes')->where('responsable_id', $compte->id)->update(['responsable_id' => null]);
                    DB::table('sites')->where('responsable_id', $compte->id)->update(['responsable_id' => null]);

                    $this->poserLeRole($compte, $role, (int) $compte->entreprise_id);
                    $changements['rôle'] = ($ancienRole ?: '—').' → '.$role;
                    $ancienRole = $role;
                }

                $ville = $this->poserLePerimetre($compte, $ancienRole, $donnees, $changements);
                $this->accorderLaFiche($compte, $ancienRole, $ville, $donnees, $changements);

                return $changements;
            }

            $entrepriseId = (int) ($donnees['entreprise_id'] ?: $compte->entreprise_id);

            if ($entrepriseId !== (int) $compte->entreprise_id) {
                $changements['entreprise'] = 'transférée';
            }

            if ($role !== $ancienRole) {
                $changements['rôle'] = ($ancienRole ?: '—').' → '.$role;
            }

            // Les désignations d'abord : sans cela, l'ancienne ville continuerait de le
            // nommer responsable alors qu'il ne l'est plus.
            DB::table('villes')->where('responsable_id', $compte->id)->update(['responsable_id' => null]);
            DB::table('sites')->where('responsable_id', $compte->id)->update(['responsable_id' => null]);

            $compte->forceFill(['entreprise_id' => $entrepriseId])->save();

            $this->poserLeRole($compte, $role, $entrepriseId);
            $ville = $this->poserLePerimetre($compte, $role, $donnees, $changements);
            $this->accorderLaFiche($compte, $role, $ville, $donnees, $changements);

            return $changements;
        });
    }

    /**
     * Le rôle réellement inscrit en base, sans dépendre de l'équipe posée dans la requête.
     *
     * getRoleNames() filtre sur l'équipe courante : depuis un écran Super Admin, qui
     * n'est rattaché à aucune entreprise, il ne renvoie rien — et l'on croirait le
     * compte sans rôle.
     */
    private function roleActuel(User $compte): string
    {
        return (string) DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.model_id', $compte->id)
            ->value('roles.name');
    }

    /**
     * Repose le rôle, toutes équipes confondues.
     *
     * syncRoles() ne retirerait que les rôles de l'équipe courante : un compte transféré
     * d'une entreprise à l'autre garderait son ancien rôle dans l'ancienne, et le
     * retrouverait intact si on l'y ramenait. On efface donc la liaison en clair.
     */
    private function poserLeRole(User $compte, string $role, int $entrepriseId): void
    {
        DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->where('model_id', $compte->id)
            ->delete();

        // Une entreprise créée hors du parcours habituel peut n'avoir aucun rôle : on
        // s'en assure plutôt que d'échouer sur un rôle introuvable.
        ProvisionneurEntreprise::creerRoles(Entreprise::findOrFail($entrepriseId));

        app(PermissionRegistrar::class)->setPermissionsTeamId($entrepriseId);
        $compte->unsetRelation('roles');
        $compte->assignRole($role);
    }

    /**
     * Rattache le compte à son périmètre, et inscrit la désignation qui va avec.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, string>  $changements
     */
    private function poserLePerimetre(User $compte, string $role, array $donnees, array &$changements): ?Ville
    {
        if ($role === 'gerant') {
            // Le gérant répond de l'entreprise entière : ni ville ni lieu.
            $compte->forceFill(['ville_id' => null, 'site_id' => null])->save();

            return null;
        }

        if ($role === 'responsable_site') {
            /*
             * Un lieu précis, ou tous ceux d'une ville. Les désignations que ce compte
             * portait ont été effacées juste avant par l'appelant : c'est ce qui permet de
             * ramener quelqu'un de « tous les sites » à un seul sans qu'il reste inscrit
             * sur l'autre.
             */
            $ville = ChoixDeLieu::poser(
                (int) $compte->entreprise_id, $compte, $donnees['site_id'] ?? null
            );

            if (! $ville) {
                return null;
            }

            $changements['périmètre'] = ChoixDeLieu::libelle($compte->refresh()) ?: $ville->nom;

            return $ville;
        }

        // « toutes » n'appartient qu'au responsable commercial : le formulaire ne le
        // propose pas aux autres, et la requête ne suffit pas à l'obtenir.
        $choixVille = $donnees['ville_id'] ?? null;

        if (ChoixDeVille::estToutes($choixVille) && ! ChoixDeVille::peutCouvrirToutesLesVilles($role)) {
            $choixVille = null;
        }

        $siteId = $role === 'commercial' ? ($donnees['site_id'] ?? null) : null;
        $ville = ChoixDeVille::poser((int) $compte->entreprise_id, $compte, $choixVille, $siteId);

        if (! $ville) {
            return null;
        }

        if ($role === 'responsable_ville') {
            $ville->forceFill(['responsable_id' => $compte->id])->save();
        }

        $changements['périmètre'] = ChoixDeVille::libelle($compte->refresh()) ?: $ville->nom;

        return $ville;
    }

    /**
     * Crée, met à jour ou retire la fiche commerciale, selon le nouveau rôle.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, string>  $changements
     */
    private function accorderLaFiche(User $compte, string $role, ?Ville $ville, array $donnees, array &$changements): void
    {
        $fiche = Commercial::withoutGlobalScopes()->where('user_id', $compte->id)->first();
        $prospecte = in_array($role, self::ROLES_COMMERCIAUX, true);

        if (! $prospecte) {
            if ($fiche) {
                $changements['fiche commerciale'] = $fiche->retirerDuService();
            }

            return;
        }

        if (! $ville) {
            return;
        }

        $valeurs = [
            'entreprise_id' => $compte->entreprise_id,
            'ville_id' => $ville->id,
            'nom' => $donnees['nom'],
            'objectif_mecanique' => (int) ($donnees['objectif_mecanique'] ?? 0),
            'objectif_sinistre' => (int) ($donnees['objectif_sinistre'] ?? 0),
            'statut' => 'Actif',
        ];

        if ($fiche) {
            $fiche->forceFill($valeurs)->save();

            return;
        }

        // Le compte n'était pas commercial jusqu'ici : sans fiche, ni ses prospections
        // ni son chiffre d'affaires ne seraient rattachables à quiconque.
        Commercial::withoutGlobalScopes()->create($valeurs + [
            'user_id' => $compte->id,
            'numero' => GenerateurNumero::suivant($compte->entreprise_id, 'com'),
            'est_spontane' => false,
        ]);

        $changements['fiche commerciale'] = 'créée';
    }
}

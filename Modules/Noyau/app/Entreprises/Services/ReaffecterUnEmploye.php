<?php

namespace Modules\Noyau\Entreprises\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Reaffectation;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Déplacer une personne d'un lieu à un autre, sans déplacer son travail.
 *
 * **La distinction qui fait tout.** Une facture appartient à l'atelier où elle a été faite,
 * définitivement : c'est là que le chiffre d'affaires a eu lieu, et le déplacer fausserait
 * deux ateliers d'un coup — celui qu'on vide et celui qu'on gonfle. Ce qui se déplace, c'est
 * la personne : son écran, son périmètre de saisie, et son code du logiciel d'atelier pour
 * que ses **prochaines** fiches partent au bon endroit.
 *
 * **Ce qui est délibérément interdit.**
 *
 * - On ne réaffecte pas vers le rôle de gérant. Le gérant ne saisit pas ; il n'a ni ville
 *   ni atelier, et l'y envoyer ne voudrait rien dire. Il faut créer l'accès pour cela.
 * - On ne réaffecte pas un gérant. Même raison, dans l'autre sens.
 * - On ne réaffecte pas vers un site d'une autre entreprise, ni vers un site inactif.
 *
 * **Ce qui est conservé.** Tout. La ligne de réaffectation garde d'où venait la personne,
 * où elle va, avec quel rôle avant et après, qui l'a décidé et pourquoi. C'est ce qui rend
 * l'ancien lieu consultable pour elle, et l'histoire lisible pour le gérant.
 */
class ReaffecterUnEmploye
{
    /** Le gérant ne se réaffecte ni ne s'obtient par réaffectation : il ne saisit nulle part. */
    public const ROLES_INTERDITS = ['gerant', 'super_admin'];

    public function __construct(private int $entrepriseId) {}

    /**
     * @param  string|null  $nouveauRole  null pour conserver le rôle actuel
     *
     * @throws RuntimeException
     */
    public function deplacer(
        User $employe,
        ?int $villeId,
        ?int $siteId,
        ?string $nouveauRole,
        ?string $motif,
        User $decideur,
    ): Reaffectation {
        if ((int) $employe->entreprise_id !== $this->entrepriseId) {
            throw new RuntimeException("Cette personne n'appartient pas à votre entreprise.");
        }

        $roleActuel = $this->roleDe($employe);

        if (in_array($roleActuel, self::ROLES_INTERDITS, true)) {
            throw new RuntimeException(
                'Un '.LibellesRoles::de($roleActuel).' ne se réaffecte pas : il ne saisit dans aucun atelier.'
            );
        }

        $roleVise = $nouveauRole ?: $roleActuel;

        if (in_array($roleVise, self::ROLES_INTERDITS, true)) {
            throw new RuntimeException(
                'On ne devient pas '.LibellesRoles::de($roleVise).' par réaffectation : cet accès se crée.'
            );
        }

        [$ville, $site] = $this->resoudreLeLieu($villeId, $siteId);

        if ($ville === null && $site === null) {
            throw new RuntimeException('Indiquez au moins la ville de destination.');
        }

        $villeFinale = $site?->ville_id ?? $ville?->id;

        $avant = [
            'ville' => $employe->ville_id ? (int) $employe->ville_id : null,
            'site' => $employe->site_id ? (int) $employe->site_id : null,
            'role' => $roleActuel,
        ];

        if ($avant['ville'] === $villeFinale
            && $avant['site'] === ($site?->id)
            && $avant['role'] === $roleVise) {
            throw new RuntimeException('Cette personne est déjà à cet endroit, avec ce rôle.');
        }

        return DB::transaction(function () use ($employe, $site, $villeFinale, $roleVise, $roleActuel, $avant, $motif, $decideur) {
            // Le compte suit la personne. Ses écritures, elles, ne bougent pas d'une ligne.
            $employe->forceFill([
                'ville_id' => $villeFinale,
                'site_id' => $site?->id,
            ])->save();

            if ($roleVise !== $roleActuel) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($this->entrepriseId);
                $employe->syncRoles([$roleVise]);
            }

            // Un responsable ne reste pas responsable de l'atelier qu'il quitte : sinon il
            // continuerait d'en voir la saisie, et deux personnes se croiraient chez elles
            // au même endroit.
            $this->retirerLesResponsabilitesAnciennes($employe, $avant, $site?->id, $villeFinale);
            $this->poserLesResponsabilitesNouvelles($employe, $roleVise, $site?->id, $villeFinale);

            $code = $this->deplacerLeCode($employe, $villeFinale, $site?->id);

            return Reaffectation::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entrepriseId,
                'user_id' => $employe->id,
                'code_agent_id' => $code?->id,
                'ville_avant_id' => $avant['ville'],
                'site_avant_id' => $avant['site'],
                'role_avant' => $avant['role'],
                'ville_apres_id' => $villeFinale,
                'site_apres_id' => $site?->id,
                'role_apres' => $roleVise,
                'motif' => $motif === null ? null : mb_substr(trim($motif), 0, 255),
                'decidee_par' => $decideur->id,
            ]);
        });
    }

    /** @return array{0: Ville|null, 1: Site|null} */
    private function resoudreLeLieu(?int $villeId, ?int $siteId): array
    {
        $site = $siteId === null ? null : Site::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('est_actif', true)
            ->find($siteId);

        if ($siteId !== null && $site === null) {
            throw new RuntimeException("Cet atelier n'existe pas, ou il n'est plus actif.");
        }

        $ville = $villeId === null ? null : Ville::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('est_actif', true)
            ->find($villeId);

        if ($villeId !== null && $ville === null) {
            throw new RuntimeException("Cette ville n'existe pas, ou elle n'est plus active.");
        }

        if ($site !== null && $ville !== null && (int) $site->ville_id !== (int) $ville->id) {
            throw new RuntimeException("Cet atelier n'appartient pas à la ville choisie.");
        }

        return [$ville, $site];
    }

    /**
     * Le code du logiciel d'atelier suit la personne — mais seulement lui.
     *
     * Les fiches déjà rédigées sous ce code gardent leur atelier : c'est là qu'elles ont
     * été faites. Seules les suivantes iront au nouveau lieu.
     */
    private function deplacerLeCode(User $employe, ?int $villeId, ?int $siteId): ?CodeAgent
    {
        $code = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('user_id', $employe->id)
            ->first();

        if ($code === null) {
            return null;
        }

        $code->forceFill(['ville_id' => $villeId, 'site_id' => $siteId])->save();

        return $code;
    }

    private function retirerLesResponsabilitesAnciennes(User $employe, array $avant, ?int $siteApres, ?int $villeApres): void
    {
        if ($avant['site'] !== null && $avant['site'] !== $siteApres) {
            Site::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($avant['site'])
                ->where('responsable_id', $employe->id)
                ->update(['responsable_id' => null]);
        }

        if ($avant['ville'] !== null && $avant['ville'] !== $villeApres) {
            Ville::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($avant['ville'])
                ->where('responsable_id', $employe->id)
                ->update(['responsable_id' => null]);
        }
    }

    /**
     * Rendre la personne responsable de son nouveau lieu, quand son rôle l'exige.
     *
     * Sans cela, `Site::visiblesPour()` ne lui rendrait aucun atelier et son écran serait
     * vide au lendemain de sa mutation — un vide que rien n'expliquerait, puisque le
     * changement aurait bien été enregistré. Le périmètre d'un responsable se lit sur le
     * lieu, pas sur le compte.
     */
    private function poserLesResponsabilitesNouvelles(User $employe, ?string $role, ?int $siteApres, ?int $villeApres): void
    {
        if ($role === 'responsable_site' && $siteApres !== null) {
            Site::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($siteApres)
                ->update(['responsable_id' => $employe->id]);
        }

        if ($role === 'responsable_ville' && $villeApres !== null) {
            Ville::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->whereKey($villeApres)
                ->update(['responsable_id' => $employe->id]);
        }
    }

    private function roleDe(User $employe): ?string
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entrepriseId);

        return $employe->roles()->pluck('name')->first();
    }
}

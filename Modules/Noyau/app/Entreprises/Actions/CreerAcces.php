<?php

namespace Modules\Noyau\Entreprises\Actions;

use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Commun\Services\CodeAuteur;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Support\HierarchieAcces;
use Modules\Noyau\Entreprises\Support\LibellesRoles;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;
use Modules\Noyau\Commun\Mails\BienvenueNouvelAcces;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;

/**
 * Création d'un accès au sein d'une entreprise, quel qu'en soit le rôle. Utilisée par
 * le Gérant, par les Responsables (qui créent Commerciaux et Comptabilité) et par le
 * Super Admin (n'importe quel rôle, dans n'importe quelle entreprise).
 *
 * Le périmètre attendu dans $donnees dépend du rôle :
 * - responsable_ville, commercial, caissier → `ville_id` (la comptabilité et les
 *   commerciaux travaillent pour une ville entière, pas pour un lieu précis) ;
 * - responsable_site → `site_id`, le lieu dont il répond ;
 * - gerant → aucun, son périmètre étant l'entreprise.
 *
 * `code_agent` est facultatif et vient d'ailleurs : c'est le code de deux lettres que la
 * personne laisse sur tout ce qu'elle saisit dans le logiciel de l'atelier — « FR-KZN°
 * 010669 ». Le renseigner ici évite d'avoir à le rattacher après coup, et fait aussitôt
 * remonter dans la bonne ville et le bon atelier tout ce que cette personne a déjà écrit.
 */
class CreerAcces
{
    /** Rôles dont le titulaire prospecte aussi : il doit donc exister comme commercial. */
    private const ROLES_COMMERCIAUX = ['responsable_ville', 'responsable_site', 'commercial'];

    /**
     * Pourquoi le code employé n'a pas pu être rattaché, le cas échéant.
     *
     * L'accès est créé quoi qu'il arrive ; l'écran lit cette propriété pour dire, sans
     * dramatiser, qu'il reste deux lettres à régler.
     */
    public ?string $refusDuCode = null;

    public function executer(Entreprise $entreprise, string $role, array $donnees): User
    {
        $utilisateur = $this->creer($entreprise, $role, $donnees);

        // Un accès créé inactif est un accès préparé, pas ouvert : on ne souhaite la
        // bienvenue à personne tant qu'il ne peut pas entrer. Le courriel partira à
        // l'activation, quand la place sera réellement prête.
        if ($utilisateur->est_actif) {
            // Hors transaction : un incident d'envoi ne doit jamais annuler la création
            // d'un accès pourtant valide. L'accès existe, le courriel se renvoie au besoin.
            $this->annoncerLAcces($entreprise, $utilisateur, $role);
        }

        return $utilisateur;
    }

    /**
     * Ouvre un accès préparé et souhaite la bienvenue à son titulaire.
     *
     * C'est le pendant du brouillon : l'accès existait déjà, avec son rôle et son
     * périmètre, mais son titulaire n'en savait rien. L'activation est le moment où
     * il l'apprend — et le courriel ne part qu'une fois, à la première ouverture.
     */
    public function activer(User $utilisateur): bool
    {
        if ($utilisateur->est_actif) {
            return false;
        }

        $entreprise = $utilisateur->entreprise()->withoutGlobalScopes()->first();

        $utilisateur->forceFill(['est_actif' => true])->save();

        if ($entreprise) {
            $this->annoncerLAcces($entreprise, $utilisateur, $this->roleDe($utilisateur));
        }

        return true;
    }

    /**
     * Le rôle, lu en base.
     *
     * getRoleNames() ne convient pas ici, même après avoir repointé l'équipe de Spatie :
     * la relation `roles` est mise en cache sur le modèle, et si elle a été chargée plus
     * tôt dans la requête, le filtre posé après coup ne rejoue rien. Le courriel
     * annonçait alors « Rôle : — » à quelqu'un qui en a bien un.
     *
     * S'ajoutait un effet de bord jamais défait : l'équipe restait celle du dernier
     * compte traité pour toute la suite de la requête.
     */
    private function roleDe(User $utilisateur): string
    {
        return HierarchieAcces::roleDe($utilisateur);
    }

    private function creer(Entreprise $entreprise, string $role, array $donnees): User
    {
        return DB::transaction(function () use ($entreprise, $role, $donnees) {
            $utilisateur = User::create([
                'entreprise_id' => $entreprise->id,
                'name' => $donnees['nom'],
                'email' => $donnees['email'],
                'password' => $donnees['mot_de_passe'],
                'telephone' => $donnees['telephone'] ?? null,
                'email_verified_at' => now(),
                // Un accès créé par un administrateur impose un changement de mot de passe ;
                // une inscription volontaire (par code entreprise) non, le mot de passe étant déjà choisi.
                'doit_changer_mot_de_passe' => $donnees['doit_changer_mot_de_passe'] ?? true,
                // Par défaut l'accès s'ouvre tout de suite ; créé inactif, il attend
                // qu'on l'active — aucun courriel ne part, et la connexion est refusée.
                'est_actif' => $donnees['est_actif'] ?? true,
            ]);

            // Le rang qui fera son identifiant, posé tout de suite et pour toujours. Le
            // laisser à la première saisie marcherait aussi, mais quelqu'un qui n'a rien
            // saisi n'aurait alors aucun code à montrer — or c'est précisément dans ce
            // moment-là, à l'arrivée, qu'on le lui apprend.
            CodeAuteur::rangDe($utilisateur);

            /*
             * assignRole écrit dans l'équipe posée : il faut donc la poser sur
             * l'entreprise du nouveau compte. Mais elle est ensuite remise telle qu'on
             * l'a trouvée — celle de l'administrateur qui agit. Sans cela, un super
             * administrateur qui vient d'ouvrir un accès n'est plus reconnu comme tel
             * pour la suite de sa propre requête : son rôle vit dans l'équipe 0, et on
             * l'aurait laissé dans celle de l'entreprise.
             */
            $registrar = app(PermissionRegistrar::class);
            $equipeInitiale = $registrar->getPermissionsTeamId();

            try {
                $registrar->setPermissionsTeamId($entreprise->id);
                $utilisateur->assignRole($role);
            } finally {
                $registrar->setPermissionsTeamId($equipeInitiale);
            }

            $ville = $this->affecterPerimetre($entreprise, $utilisateur, $role, $donnees);

            if (in_array($role, self::ROLES_COMMERCIAUX, true) && $ville) {
                $this->creerFicheCommercial($entreprise, $utilisateur, $ville, $donnees);
            }

            // Le code vient après le périmètre, et c'est l'ordre qui compte : il en reprend
            // la ville et le site plutôt que de les redemander.
            //
            // **Il ne bloque jamais la création.** Un code mal formé ou déjà porté par
            // quelqu'un d'autre est une question de référentiel, pas une raison de refuser
            // un accès dont tout le reste est valable — et refuser au dernier moment
            // obligerait à ressaisir le formulaire entier pour deux lettres. Le compte est
            // créé, le motif du refus est conservé sur l'action, et le code se rattache
            // plus tard depuis l'écran des codes employés.
            if (! empty($donnees['code_agent'])) {
                try {
                    // Par le service plutôt que par l'affectation brute : lui seul inscrit
                    // le geste au journal et laisse la confirmation à faire, pour que la
                    // personne reconnaisse elle-même son code à son premier écran.
                    CodeDeLAtelier::attribuer(
                        $utilisateur->refresh(),
                        $donnees['code_agent'],
                        auth()->user() ?? $utilisateur,
                    );
                } catch (\InvalidArgumentException $refus) {
                    $this->refusDuCode = $refus->getMessage();
                }
            }

            return $utilisateur;
        });
    }

    /**
     * Souhaite la bienvenue au titulaire et lui indique où se connecter.
     *
     * Une adresse peut être erronée, le serveur de courrier indisponible : l'échec est
     * journalisé, jamais propagé. Refuser un accès parce qu'un courriel n'est pas parti
     * serait la pire des réponses pour celui qui attend de travailler.
     */
    private function annoncerLAcces(Entreprise $entreprise, User $utilisateur, string $role): void
    {
        try {
            Mail::to($utilisateur->email)->send(new BienvenueNouvelAcces(
                $utilisateur->fresh(),
                $entreprise,
                LibellesRoles::de($role),
                $this->libellePerimetre($utilisateur),
            ));
        } catch (\Throwable $e) {
            Log::warning("Courriel de bienvenue non envoyé à {$utilisateur->email} : ".$e->getMessage());
        }
    }

    /** « Abidjan — Site 2 » pour un responsable de lieu, « Abidjan » pour les autres. */
    private function libellePerimetre(User $utilisateur): ?string
    {
        if ($utilisateur->site_id) {
            return Site::find($utilisateur->site_id)?->nom;
        }

        return $utilisateur->ville_id ? Ville::find($utilisateur->ville_id)?->nom : null;
    }

    /**
     * Rattache l'accès à son périmètre et renvoie la ville qui en découle.
     *
     * Un responsable est rattaché en se voyant désigné sur sa ville ou son lieu
     * (`responsable_id`) ; la comptabilité, elle, porte son rattachement sur son propre
     * compte (`users.ville_id`), plusieurs comptables pouvant partager la même ville.
     */
    private function affecterPerimetre(Entreprise $entreprise, User $utilisateur, string $role, array $donnees): ?Ville
    {
        if ($role === 'responsable_site') {
            $site = Site::where('id', $donnees['site_id'] ?? null)->where('entreprise_id', $entreprise->id)->firstOrFail();
            $site->update(['responsable_id' => $utilisateur->id]);
            $utilisateur->update(['ville_id' => $site->ville_id, 'site_id' => $site->id]);

            return $site->ville;
        }

        if (! in_array($role, ['responsable_ville', 'commercial', 'caissier'], true)) {
            return null;
        }

        $ville = Ville::where('id', $donnees['ville_id'] ?? null)->where('entreprise_id', $entreprise->id)->firstOrFail();

        if ($role === 'responsable_ville') {
            $ville->update(['responsable_id' => $utilisateur->id]);
        }

        // Le rattachement est porté par le compte lui-même, pas seulement par la fiche
        // commercial ou la colonne responsable_id : c'est ce qui permet de le retrouver
        // même après une purge des données, et de ne jamais réaffecter quelqu'un par défaut.
        $utilisateur->update(['ville_id' => $ville->id, 'site_id' => null]);

        return $ville;
    }

    /**
     * Un responsable de ville ou de site prospecte lui aussi : il doit donc figurer
     * parmi les commerciaux, avec ses propres objectifs, sans quoi ni ses prospections
     * ni son chiffre d'affaires ne seraient rattachables à quiconque.
     */
    private function creerFicheCommercial(Entreprise $entreprise, User $utilisateur, Ville $ville, array $donnees): void
    {
        Commercial::create([
            'entreprise_id' => $entreprise->id,
            'ville_id' => $ville->id,
            'user_id' => $utilisateur->id,
            'numero' => GenerateurNumero::suivant($entreprise->id, 'com'),
            'nom' => $donnees['nom'],
            'objectif_mecanique' => (int) ($donnees['objectif_mecanique'] ?? 0),
            'objectif_sinistre' => (int) ($donnees['objectif_sinistre'] ?? 0),
            'statut' => 'Actif',
            'est_spontane' => false,
        ]);
    }
}

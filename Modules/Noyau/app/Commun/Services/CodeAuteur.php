<?php

namespace Modules\Noyau\Commun\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le code qui désigne la personne ayant saisi une ligne.
 *
 *      A-C-KY-0007
 *      │ │ │   └── le 7ᵉ accès ouvert dans cette entreprise
 *      │ │ └────── Koffi Yao
 *      │ └──────── Commercial
 *      └────────── Abidjan
 *
 * Il complète le numéro du document sans le remplacer : « P-0565 » dit le rang de la
 * prospection dans toute l'entreprise, « A-C-KY-0007 » dit de qui elle vient. Les deux
 * sont nécessaires — le premier pour classer, le second pour rendre des comptes.
 *
 * **Le dernier bloc comptait les saisies ; il compte désormais les personnes.** Auparavant
 * « 0007 » voulait dire « la septième prospection de Koffi Yao » : le même agent portait
 * donc un numéro différent sur chaque ligne, et deux numéros différents le même jour sur
 * deux écrans. Ce n'était pas un identifiant mais un compteur — or un identifiant qui
 * change n'identifie plus rien, ne se retient pas, ne se dicte pas au téléphone. Le rang
 * est maintenant attribué une fois à l'ouverture de l'accès, unique dans l'entreprise, et
 * ne bouge plus jamais. Le rang du document, lui, n'est pas perdu : c'est le numéro de la
 * pièce qui l'a toujours porté.
 *
 * Le code est figé au moment de la saisie et n'est jamais recalculé : une personne qui
 * change de ville ou de rôle plus tard ne doit pas réécrire l'histoire de ce qu'elle a
 * déjà saisi.
 */
final class CodeAuteur
{
    /**
     * Une lettre par rôle, toutes distinctes.
     *
     * La première lettre du rôle ne suffisait pas : Commercial et Caissier donnent tous
     * deux « C », les deux Responsables tous deux « R » — le code aurait cessé de dire
     * qui avait saisi, ce qui est précisément son objet. « K » pour la caisse et « S »
     * pour le superviseur lèvent l'ambiguïté sans allonger le code.
     */
    public const LETTRES_ROLE = [
        'super_admin' => 'P',        // Plateforme
        'gerant' => 'G',
        'responsable_ville' => 'S',  // Superviseur de ville
        'responsable_site' => 'R',
        'commercial' => 'C',
        'caissier' => 'K',           // Comptabilité
        'superviseur_recouvrement' => 'V',  // superViseur recouvrement — S et R sont pris
        'agent_recouvrement' => 'A',        // Agent de recouvrement
    ];

    /** Marque un auteur dont le rôle n'est pas reconnu — visible plutôt que silencieux. */
    private const ROLE_INCONNU = 'X';

    /**
     * Le code de cette personne. Le même à chaque appel, pour toute sa vie dans la maison.
     */
    public static function pour(?User $auteur): ?string
    {
        // Import, seeder, tâche planifiée : personne derrière l'écran, donc personne à
        // désigner. Mieux vaut une colonne vide qu'un code attribué à tort.
        if (! $auteur) {
            return null;
        }

        return self::composer($auteur, self::rangDe($auteur));
    }

    /**
     * Le rang de la personne dans son entreprise, attribué à la première demande.
     *
     * Normalement il est posé à l'ouverture de l'accès. Ce repli couvre les comptes créés
     * autrement — une commande, une reprise de base — et il compte plutôt que de laisser
     * quelqu'un sans identifiant.
     *
     * Le calcul est verrouillé : deux comptes créés dans la même seconde ne peuvent pas
     * repartir avec le même rang, ce qui mettrait deux personnes sous un seul identifiant.
     */
    public static function rangDe(User $auteur): int
    {
        if ($auteur->rang_auteur) {
            return (int) $auteur->rang_auteur;
        }

        $rang = DB::transaction(function () use ($auteur) {
            $dernier = (int) User::withoutGlobalScopes()
                ->where('entreprise_id', $auteur->entreprise_id)
                ->lockForUpdate()
                ->max('rang_auteur');

            $rang = $dernier + 1;

            User::withoutGlobalScopes()->where('id', $auteur->id)->update(['rang_auteur' => $rang]);

            return $rang;
        });

        $auteur->rang_auteur = $rang;

        return $rang;
    }

    /**
     * Le début du code, tel qu'il s'écrira, avant que le compte n'existe.
     *
     * Sert à l'écran de création : on montre à quoi ressemblera le code de la personne
     * qu'on est en train de créer, sans rien consommer ni rien enregistrer. Le rang est
     * remplacé par des points parce qu'il n'est attribué qu'à l'enregistrement : le montrer
     * d'avance reviendrait à le promettre, et deux accès ouverts en même temps depuis deux
     * postes verraient le même.
     *
     * **Ce code n'a rien à voir avec le code de deux lettres du logiciel d'atelier.** Celui-ci
     * est produit par la plateforme et identifie qui saisit ici ; l'autre vient du logiciel
     * d'atelier et sert à rattacher les lignes importées. On les affiche côte à côte
     * précisément pour qu'on cesse de les confondre.
     */
    public static function apercuDuPrefixe(?string $ville, ?string $role, ?string $nom): string
    {
        return implode('-', [
            self::premiereLettre((string) $ville) ?: '·',
            self::LETTRES_ROLE[$role] ?? self::ROLE_INCONNU,
            self::initiales((string) $nom) ?: '··',
            '····',
        ]);
    }

    /** Le code tel qu'il s'écrit, sans toucher au compteur. */
    public static function composer(User $auteur, int $rang): string
    {
        return implode('-', [
            self::lettreVille($auteur),
            self::lettreRole($auteur),
            self::initiales($auteur->name),
            str_pad((string) $rang, 4, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * La première lettre de la ville de rattachement.
     *
     * Le gérant et la plateforme ne sont d'aucune ville : ils portent alors la première
     * lettre de l'entreprise, qui est bien leur périmètre.
     */
    private static function lettreVille(User $auteur): string
    {
        $nom = $auteur->ville?->nom
            ?? $auteur->site?->ville?->nom
            ?? $auteur->entreprise?->nom
            ?? '';

        return self::premiereLettre($nom) ?: '·';
    }

    /**
     * La lettre du rôle, lue sans passer par l'équipe courante.
     *
     * getRoleNames() filtre sur l'équipe posée dans la requête en cours : hors requête
     * — une migration, une commande, une file d'attente — l'équipe n'est pas posée et
     * la méthode ne renvoie rien. Le code se retrouvait alors marqué « rôle inconnu »
     * pour des agents qui en avaient bien un. On interroge donc la table directement.
     */
    private static function lettreRole(User $auteur): string
    {
        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $auteur->getMorphClass())
            ->where('model_has_roles.model_id', $auteur->getKey())
            ->pluck('roles.name');

        foreach ($roles as $role) {
            if (isset(self::LETTRES_ROLE[$role])) {
                return self::LETTRES_ROLE[$role];
            }
        }

        return self::ROLE_INCONNU;
    }

    /**
     * Les initiales du nom et du prénom : « Koffi Yao » donne KY.
     *
     * Un nom d'un seul mot donne ses deux premières lettres — une initiale seule serait
     * trop souvent partagée entre deux agents d'une même ville.
     */
    private static function initiales(string $nom): string
    {
        $mots = preg_split('/[\s\-]+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($mots) === 0) {
            return '··';
        }

        if (count($mots) === 1) {
            return Str::upper(Str::ascii(Str::substr($mots[0], 0, 2)));
        }

        return self::premiereLettre($mots[0]).self::premiereLettre(end($mots));
    }

    private static function premiereLettre(string $mot): string
    {
        // Passage en ASCII : « Élise » doit donner E, pas un caractère accentué qui se
        // lirait mal dans un tableau ou dans une recherche.
        return Str::upper(Str::ascii(Str::substr(trim($mot), 0, 1)));
    }
}

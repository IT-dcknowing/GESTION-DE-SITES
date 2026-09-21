<?php

namespace Modules\SuperAdmin\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Services\CodeDeLAtelier;

/**
 * Donner à chacun son code d'atelier, depuis la plateforme.
 *
 * **Pourquoi cet écran est ici et non dans le module Import.** L'annuaire des codes du
 * module Import se lit code par code : « KZ, c'est quelle ville, quel atelier ? ». C'est la
 * bonne question quand on range des fichiers. Ce n'est pas celle qu'on se pose quand on
 * ouvre les accès d'une entreprise, où l'on part des personnes : « Koffi Yao, quel est son
 * code ? ». Deux entrées sur la même donnée, et la seconde manquait — trente-huit codes en
 * base, aucun rattaché à quelqu'un.
 *
 * **Un formulaire qui poste, une ligne par personne.** Pas de couche interactive : on tape
 * deux lettres, on enregistre, la page revient avec son message. Le geste marche dans
 * n'importe quel navigateur, ce qui compte pour un écran d'administration qu'on ouvre
 * rarement et souvent en déplacement.
 *
 * **Le super administrateur est le seul à pouvoir déplacer un code.** Quand deux lettres
 * sont déjà portées par quelqu'un, chacun se voit refuser la reprise depuis son profil —
 * sans quoi on s'attribuerait les fiches d'un collègue. Ici elle est permise, parce que
 * c'est justement le rôle : arbitrer. Le déplacement est annoncé en clair et inscrit au
 * journal des deux côtés.
 */
class CodesAtelierController
{
    public function update(Request $requete): RedirectResponse
    {
        $donnees = $requete->validate([
            'utilisateur' => ['required', 'integer', 'exists:users,id'],
            'code' => ['nullable', 'string', 'max:2'],
            'entreprise' => ['nullable', 'integer'],
        ], [
            'utilisateur.required' => 'Aucune personne désignée.',
            'code.max' => "Un code d'atelier fait exactement deux lettres.",
        ]);

        $personne = User::withoutGlobalScopes()->findOrFail($donnees['utilisateur']);
        $retour = ['entreprise' => $donnees['entreprise'] ?? $personne->entreprise_id];

        // Les comptes de la plateforme ne travaillent dans aucun atelier : leur donner un
        // code rattacherait des fiches à quelqu'un qui n'en produit pas.
        if (! $personne->entreprise_id) {
            return redirect()->route('super-admin.codes', $retour)
                ->with('refus-code', "« {$personne->name} » n'appartient à aucune entreprise : un code d'atelier n'aurait rien à désigner.");
        }

        try {
            $resultat = CodeDeLAtelier::attribuer(
                $personne,
                $donnees['code'] ?? '',
                $requete->user(),
                deplacerSiPris: true,
            );
        } catch (InvalidArgumentException $refus) {
            return redirect()->route('super-admin.codes', $retour)->with('refus-code', $refus->getMessage());
        }

        return redirect()->route('super-admin.codes', $retour)
            ->with('annonce', $this->raconter($personne, $resultat));
    }

    /**
     * Déclarer un code de saisie, et la personne qui le porte, sans lui ouvrir de compte.
     *
     * **Pourquoi cela existe.** Une partie de ceux qui saisissent dans le logiciel d'atelier
     * n'ont pas accès à cette application, et n'en auront peut-être jamais. Leur code arrive
     * pourtant par chaque import, et restait une énigme de deux lettres : un volume de
     * fiches, aucun nom, personne à appeler pour lever un doute.
     *
     * **Le code se tape à la main, et c'est voulu.** On n'attend pas qu'un import l'ait
     * rencontré : une nouvelle recrue est connue le jour où elle arrive, et son travail doit
     * se ranger au bon atelier dès sa première fiche. Un code déclaré ici est un code comme
     * les autres — le rattachement des imports s'en sert exactement de la même façon, et
     * compte ses fiches. La seule chose qu'il n'a pas, c'est un accès.
     *
     * **Déclarer n'ouvre rien.** `user_id` n'est pas touché. Le jour où l'accès s'ouvre, le
     * bouton « Créer un compte » de la ligne reprend ces informations et c'est l'écran des
     * accès qui attribue le code — là où cela se décide.
     *
     * **Le même formulaire corrige.** Un code déjà déclaré se retrouve par ses deux lettres
     * et se met à jour : deux entrées pour les mêmes initiales n'auraient aucun sens, et la
     * base le refuse de toute façon (unique sur entreprise + code).
     */
    public function declarer(Request $requete): RedirectResponse
    {
        $donnees = $requete->validate([
            'entreprise' => ['required', 'integer', 'exists:entreprises,id'],
            'code' => ['required', 'string', 'regex:'.CodeDeLAtelier::FORMAT],
            'nom' => ['required', 'string', 'max:120'],
            'prenom' => ['nullable', 'string', 'max:120'],
            'fonction' => ['nullable', 'string', 'max:120'],
            'ville_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
        ], [
            'code.required' => 'Indiquez les deux lettres du code.',
            'code.regex' => "Un code de saisie fait exactement deux lettres : c'est sous cette forme que le logiciel l'inscrit dans les numéros de fiche.",
            'nom.required' => 'Indiquez au moins le nom de la personne : un code sans nom ne renseigne personne.',
        ]);

        $entrepriseId = (int) $donnees['entreprise'];
        $deuxLettres = mb_strtoupper(trim($donnees['code']));
        $retour = ['entreprise' => $entrepriseId, 'vue' => 'import'];

        $code = CodeAgent::withoutGlobalScopes()->firstOrNew(
            ['entreprise_id' => $entrepriseId, 'code' => $deuxLettres],
            ['est_actif' => true, 'occurrences' => 0],
        );

        // Un code déjà porté par un compte se corrige sur la ligne de son titulaire, pas
        // ici : deux écrans qui écrivent la même chose finiraient par se contredire.
        if ($code->exists && $code->user_id !== null) {
            return redirect()->route('super-admin.codes', $retour)->with(
                'refus-code',
                "Le code « {$deuxLettres} » est déjà celui d'un compte : c'est sur sa ligne, dans l'onglet « Comptes », qu'il se corrige.",
            );
        }

        $nouveau = ! $code->exists;

        // Le lieu n'est retenu que s'il appartient bien à l'entreprise : un identifiant
        // recopié à la main ne doit pas rattacher des fiches à l'atelier d'une autre maison.
        $villeId = $this->appartientALEntreprise(Ville::class, $donnees['ville_id'] ?? null, $entrepriseId);
        $siteId = $this->appartientALEntreprise(Site::class, $donnees['site_id'] ?? null, $entrepriseId);

        // Un atelier désigne sa ville : la laisser vide rendrait le rattachement muet. Et un
        // atelier d'une autre ville que celle choisie ne se retient pas — sans quoi le
        // formulaire dirait Abidjan et la base rangerait à Bouaké.
        if ($siteId !== null) {
            $villeDuSite = Site::withoutGlobalScopes()->whereKey($siteId)->value('ville_id');

            if ($villeId !== null && (int) $villeDuSite !== $villeId) {
                $siteId = null;
            } else {
                $villeId = (int) $villeDuSite;
            }
        }

        $code->forceFill([
            'entreprise_id' => $entrepriseId,
            'code' => $deuxLettres,
            'est_actif' => true,
            'occurrences' => (int) ($code->occurrences ?? 0),
            'nom' => $this->propre($donnees['nom']),
            'prenom' => $this->propre($donnees['prenom'] ?? null),
            'fonction' => $this->propre($donnees['fonction'] ?? null),
            'ville_id' => $villeId,
            'site_id' => $siteId,
        ])->save();

        activity()
            ->causedBy($requete->user())
            ->performedOn($code)
            ->withProperties(array_filter([
                'code' => $code->code,
                'nom' => $code->nomComplet() ?: null,
                'fonction' => $code->fonction,
            ]))
            ->log($nouveau ? 'Code de saisie déclaré' : 'Code de saisie corrigé');

        return redirect()->route('super-admin.codes', $retour)->with(
            'annonce',
            $nouveau
                ? "Code {$code->code} déclaré pour « {$code->nomComplet()} ». Les prochains imports lui rattacheront ses fiches ; aucun accès ne lui est ouvert."
                : "Code {$code->code} corrigé : « {$code->nomComplet()} ».",
        );
    }

    /** Une valeur vide vaut « on ne sait pas », et s'écrit null plutôt qu'une chaîne creuse. */
    private function propre(?string $valeur): ?string
    {
        return trim((string) $valeur) !== '' ? trim((string) $valeur) : null;
    }

    /**
     * L'identifiant, s'il désigne bien quelque chose de cette entreprise — sinon null.
     *
     * @param  class-string<Model>  $modele
     */
    private function appartientALEntreprise(string $modele, ?int $id, ?int $entrepriseId): ?int
    {
        if (! $id || ! $entrepriseId) {
            return null;
        }

        return $modele::withoutGlobalScopes()
            ->whereKey($id)->where('entreprise_id', $entrepriseId)->value('id');
    }

    /**
     * Ce qui vient de se passer, dit en une phrase.
     *
     * Le déplacement est nommé explicitement : reprendre un code à quelqu'un déplace aussi
     * tout ce que les prochains imports lui rattacheront, et l'administrateur doit lire ce
     * qu'il vient de faire plutôt que de le découvrir dans un tableau de chiffre d'affaires.
     *
     * @param  array{code: ?string, ancien: ?string, repris_a: ?string}  $resultat
     */
    private function raconter(User $personne, array $resultat): string
    {
        if ($resultat['code'] === null) {
            return "Code d'atelier retiré à « {$personne->name} ».";
        }

        if ($resultat['code'] === $resultat['ancien']) {
            return "« {$personne->name} » portait déjà le code {$resultat['code']} : rien n'a changé.";
        }

        $phrase = "Code {$resultat['code']} attribué à « {$personne->name} »";

        if ($resultat['repris_a'] !== null) {
            $phrase .= ", repris à « {$resultat['repris_a']} »";
        }

        return $phrase.'. Il lui sera demandé de le confirmer à sa prochaine connexion.';
    }
}

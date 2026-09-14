<?php

namespace Modules\Noyau\Entreprises\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Imports\Modeles\LotImport;

/**
 * Efface une entreprise et tout ce qui lui appartient.
 *
 * C'est le seul geste de la plateforme qui ne laisse rien derrière lui : écritures,
 * villes, lieux, accès, rôles, conversations, notes, compteurs. Rien n'est mis de côté
 * « au cas où » — une entreprise à moitié effacée est pire que les deux états francs,
 * car elle laisse des comptes orphelins capables de se connecter dans le vide.
 *
 * Une seule exception, délibérée : le journal d'activité est conservé. C'est la trace
 * de ce qui a été fait sur la plateforme, y compris de cette suppression ; l'effacer
 * reviendrait à effacer la preuve du geste.
 *
 * **Les fichiers déposés partent avec le reste.** Les tables d'import auraient de toute
 * façon disparu par cascade, mais les classeurs rangés sur le disque privé, eux, ne
 * cascadent pas : ils contiennent les noms, les immatriculations et les montants dus de
 * milliers d'affaires, et les garder après avoir effacé l'entreprise serait conserver les
 * données de quelqu'un qui n'est plus là. Ils sont donc comptés et effacés explicitement,
 * plutôt que laissés au hasard d'une clef étrangère.
 *
 * L'ordre suit les dépendances, des feuilles vers la racine. Les colonnes qui
 * désignent un responsable sont vidées avant la suppression des comptes : une clef
 * étrangère encore pointée bloquerait l'opération à mi-chemin.
 */
class SupprimerEntreprise
{
    /** @return array<string,int> nombre de lignes supprimées, par nature */
    public function executer(Entreprise $entreprise): array
    {
        $identifiant = (int) $entreprise->id;

        $bilan = DB::transaction(function () use ($entreprise) {
            $id = $entreprise->id;
            $comptes = User::withoutGlobalScopes()->where('entreprise_id', $id)->pluck('id')->all();
            $bilan = [];

            // --- Le travail de recouvrement, avant les factures qu'il visait.
            $bilan['relances'] = DB::table('relances_recouvrement')->where('entreprise_id', $id)->delete();
            $bilan["commentaires d'écart"] = DB::table('commentaires_ecart_recouvrement')
                ->where('entreprise_id', $id)->delete();

            // --- Les imports : les corrections et les rejets pendent au lot, le lot part après.
            $lots = DB::table('lots_import')->where('entreprise_id', $id)->pluck('id');
            $bilan['corrections de lignes rejetées'] = DB::table('corrections_import')->where('entreprise_id', $id)->delete();
            $bilan['lignes rejetées'] = DB::table('lignes_rejetees_import')->whereIn('lot_import_id', $lots)->delete();

            // --- Écritures et ce qui s'y accroche.
            $bilan['informations libres'] = DB::table('donnees_libres')->where('entreprise_id', $id)->delete();
            $bilan['encaissements'] = DB::table('encaissements')->where('entreprise_id', $id)->delete();
            $bilan['factures'] = DB::table('factures')->where('entreprise_id', $id)->delete();
            $bilan['devis'] = DB::table('devis')->where('entreprise_id', $id)->delete();
            $bilan['prospections'] = DB::table('prospections')->where('entreprise_id', $id)->delete();
            $bilan['charges'] = DB::table('charges')->where('entreprise_id', $id)->delete();
            $bilan['saisies journalières'] = DB::table('saisies_journalieres')->where('entreprise_id', $id)->delete();
            $bilan['fiches commerciales'] = DB::table('commerciaux')->where('entreprise_id', $id)->delete();
            $bilan['compteurs'] = DB::table('compteurs_documents')->where('entreprise_id', $id)->delete();

            // --- Ce que les imports remplissent et que rien d'autre n'alimente.
            $bilan['fiches de réception'] = DB::table('dossiers_vehicules')->where('entreprise_id', $id)->delete();
            $bilan['mouvements de caisse'] = DB::table('mouvements_caisse')->where('entreprise_id', $id)->delete();
            $bilan['entrées et sorties de véhicules'] = DB::table('mouvements_vehicules')->where('entreprise_id', $id)->delete();
            $bilan['factures fournisseurs'] = DB::table('factures_fournisseurs')->where('entreprise_id', $id)->delete();
            $bilan['dépôts de fichiers'] = DB::table('lots_import')->where('entreprise_id', $id)->delete();

            // --- Les réglages de rattachement. L'historique des mutations vient d'abord :
            //     il désigne des codes agents, et un code effacé le laisserait sans sujet.
            $bilan['réaffectations'] = DB::table('reaffectations')->where('entreprise_id', $id)->delete();
            $bilan['codes agents'] = DB::table('codes_agents')->where('entreprise_id', $id)->delete();
            $bilan['correspondances'] = DB::table('correspondances_import')->where('entreprise_id', $id)->delete();

            // --- Exercices : les clôtures par ville avant les exercices eux-mêmes.
            $exercices = DB::table('exercices')->where('entreprise_id', $id)->pluck('id');
            DB::table('exercice_villes')->whereIn('exercice_id', $exercices)->delete();
            $bilan['exercices'] = DB::table('exercices')->where('entreprise_id', $id)->delete();

            $bilan['listes déroulantes'] = DB::table('referentiels')->where('entreprise_id', $id)->delete();

            // --- Ce qui appartient aux personnes plutôt qu'à l'entreprise.
            $this->effacerCeQuiTientAuxComptes($comptes, $bilan);

            // --- Organisation : on détache les responsables avant de supprimer.
            DB::table('sites')->where('entreprise_id', $id)->update(['responsable_id' => null]);
            DB::table('villes')->where('entreprise_id', $id)->update(['responsable_id' => null]);
            $bilan['lieux'] = DB::table('sites')->where('entreprise_id', $id)->delete();
            $bilan['villes'] = DB::table('villes')->where('entreprise_id', $id)->delete();

            // --- Accès. Le lien « créé par » est vidé d'abord : un compte encore
            //     désigné comme parrain d'un autre bloquerait la suppression.
            DB::table('users')->whereIn('cree_par_id', $comptes)->update(['cree_par_id' => null]);
            DB::table('model_has_roles')->whereIn('model_id', $comptes)
                ->where('model_type', (new User)->getMorphClass())->delete();
            DB::table('model_has_permissions')->whereIn('model_id', $comptes)
                ->where('model_type', (new User)->getMorphClass())->delete();
            $bilan['accès'] = DB::table('users')->where('entreprise_id', $id)->delete();

            // --- Rôles propres à l'entreprise : ils ne servent plus à personne.
            DB::table('role_has_permissions')->whereIn(
                'role_id',
                DB::table('roles')->where('entreprise_id', $id)->pluck('id')
            )->delete();
            $bilan['rôles'] = DB::table('roles')->where('entreprise_id', $id)->delete();

            $entreprise->delete();
            $bilan['entreprise'] = 1;

            return $bilan;
        });

        // Après la transaction, jamais avant : effacer un fichier ne s'annule pas, et un
        // retour arrière laisserait des lots sans leur fichier. Dans l'autre sens, le pire
        // qui puisse rester est un fichier que plus personne ne désigne.
        $bilan['fichiers déposés'] = LotImport::effacerLesFichiersDe($identifiant);

        return array_filter($bilan, fn (int $n) => $n > 0);
    }

    /**
     * Messagerie, notes, notifications et abonnements : rattachés aux personnes, ils
     * n'ont plus d'objet une fois les comptes partis.
     *
     * @param  array<int, int>  $comptes
     * @param  array<string, int>  $bilan
     */
    private function effacerCeQuiTientAuxComptes(array $comptes, array &$bilan): void
    {
        if (empty($comptes)) {
            return;
        }

        $conversations = DB::table('conversation_participants')->whereIn('user_id', $comptes)
            ->pluck('conversation_id')->unique();

        DB::table('messages')->whereIn('conversation_id', $conversations)->delete();
        DB::table('conversation_participants')->whereIn('conversation_id', $conversations)->delete();
        $bilan['conversations'] = DB::table('conversations')->whereIn('id', $conversations)->delete();

        $bilan['notes'] = DB::table('notes')->whereIn('user_id', $comptes)->delete();
        DB::table('dossiers_notes')->whereIn('user_id', $comptes)->delete();

        DB::table('notifications_app')->whereIn('user_id', $comptes)->delete();
        DB::table('abonnements_push')->whereIn('user_id', $comptes)->delete();
        DB::table('compteurs_auteur')->whereIn('user_id', $comptes)->delete();
    }
}

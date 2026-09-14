<?php

namespace Modules\Noyau\Entreprises\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\CompteurDocument;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AnnulationDUnLot;
use RuntimeException;

/**
 * Purge des données d'une entreprise : remettre le compteur à zéro sans démonter la maison.
 *
 * Réservé au Super Admin. Ce qui part, ce sont les **écritures et les imports** ; ce qui
 * reste, c'est l'**organisation** — la fiche entreprise, les villes, les lieux, les accès.
 * C'est le geste qu'on fait après une période d'essai, quand la structure est bonne mais
 * que les chiffres ne le sont pas.
 *
 * **Ce que la purge couvrait, et ce qui lui manquait.** Elle ne connaissait que les six
 * tables d'exploitation nées avec l'application. Entre-temps deux modules ont ouvert
 * onze tables de plus, et la purge ne les voyait pas : on vidait les factures et on
 * laissait derrière trois mille cent soixante-dix-huit fiches de réception, mille cent
 * cinquante-cinq mouvements de caisse, mille huit cent quarante-huit dettes fournisseurs,
 * cent quatre-vingt-seize relances et les douze fichiers déposés sur le disque. Une base
 * « vidée » dans cet état est pire qu'une base pleine : les écrans d'indicateurs
 * continuent d'afficher des montants que plus aucune facture ne justifie, et le prochain
 * dépôt du même fichier est refusé pour doublon d'empreinte alors que rien n'en reste.
 *
 * **Les fichiers déposés partent aussi.** Ils ne sont pas un détail de stockage : tant
 * que l'empreinte d'un fichier figure en base, redéposer ce fichier est annoncé comme un
 * doublon — donc une purge qui garde les lots interdit de rejouer exactement le scénario
 * qu'on veut retester. Le disque est nettoyé **après** la transaction, jamais avant :
 * supprimer un fichier ne s'annule pas, et un `ROLLBACK` survenu entre les deux laisserait
 * des lots sans leur fichier, ce qui est bien plus grave que l'inverse.
 *
 * **Trois élargissements, chacun explicitement demandé.** Les fiches commerciales, les
 * accès sauf celui du gérant, et les réglages de rattachement des imports. Rien de tout
 * cela n'est emporté par défaut, parce que rien de tout cela n'est un chiffre.
 *
 * **Ce que la purge ne touche jamais.** Le journal d'activité, qui est la trace de ce qui
 * a été fait sur la plateforme — y compris de cette purge. Et les rôles, les listes
 * déroulantes, les exercices : de l'organisation, pas des écritures. Pour ne rien laisser
 * du tout, c'est la suppression d'entreprise qu'il faut, pas la purge.
 */
class PurgerDonneesEntreprise
{
    /** Rôles dont le titulaire prospecte : sa fiche commercial fait partie de l'organisation, pas des écritures. */
    private const ROLES_COMMERCIAUX = ['responsable_ville', 'responsable_site', 'commercial'];

    /**
     * Les tables vidées, et le mot qui les désigne dans le bilan rendu à l'écran.
     *
     * **L'ordre est celui des clefs étrangères**, des feuilles vers la racine : ce qui
     * pend à une écriture part avant elle. Le libellé est en français parce qu'il est lu
     * par la personne qui vient de déclencher la purge, pas par un développeur.
     *
     * `lots_import` n'est pas dans cette liste : son tour vient à part, parce qu'il faut
     * d'abord relever les numéros des lots pour retirer de la file les traitements qui
     * les attendaient encore.
     */
    private const TABLES = [
        // Les informations libres pendent aux écritures : les laisser en place ferait des
        // orphelines, rattachées à des lignes qui n'existent plus.
        'donnees_libres' => 'informations libres',

        // Les corrections de lignes rejetées désignent leur lot : elles partent avant lui.
        'corrections_import' => 'corrections de lignes rejetées',

        // Recouvrement : le travail de relance, et les explications d'écart.
        'relances_recouvrement' => 'relances',
        'commentaires_ecart_recouvrement' => 'commentaires d\'écart',

        // Exploitation : encaissements avant factures, factures avant devis.
        'encaissements' => 'encaissements',
        'factures' => 'factures',
        'devis' => 'devis',
        'prospections' => 'prospections',
        'charges' => 'charges',
        'saisies_journalieres' => 'saisies journalières',

        // Ce que les imports remplissent et que rien d'autre n'alimente.
        'mouvements_caisse' => 'mouvements de caisse',
        'mouvements_vehicules' => 'entrées et sorties de véhicules',
        'factures_fournisseurs' => 'factures fournisseurs',
        'dossiers_vehicules' => 'fiches de réception',
    ];

    /**
     * @return array<string,int> nombre de lignes supprimées, par nature
     */
    public function executer(
        Entreprise $entreprise,
        bool $purgerCommerciaux = false,
        bool $purgerAcces = false,
        bool $purgerReglagesImport = false,
    ): array {
        $this->verifierQueRienNEstOublie();

        $bilan = DB::transaction(function () use ($entreprise, $purgerCommerciaux, $purgerAcces, $purgerReglagesImport) {
            $id = $entreprise->id;
            $compte = [];

            foreach (self::TABLES as $table => $libelle) {
                $compte[$libelle] = DB::table($table)->where('entreprise_id', $id)->delete();
            }

            $compte += $this->effacerLesLots($entreprise);

            if ($purgerCommerciaux) {
                $compte['fiches commerciales'] = Commercial::withoutGlobalScopes()->where('entreprise_id', $id)->delete();
            }

            // Remise à zéro de la numérotation pour repartir sur P-0001, D-0001, F-0001.
            $compte['compteurs'] = CompteurDocument::withoutGlobalScopes()
                ->where('entreprise_id', $id)
                ->when(! $purgerCommerciaux, fn ($q) => $q->where('type', '!=', 'com'))
                ->delete();

            // Les accès partent avant que les fiches ne soient reconstituées, sinon on
            // en recréerait pour des comptes qu'on s'apprête à supprimer.
            if ($purgerAcces) {
                $compte['accès'] = $this->supprimerLesAcces($entreprise);
            }

            if ($purgerCommerciaux) {
                $compte['fiches commerciales recréées'] = $this->reconstituerCommerciaux($entreprise);
            }

            $compte += $this->traiterLesReglagesDImport($entreprise, $purgerReglagesImport);

            return $compte;
        });

        // Hors transaction, et à dessein : voir le commentaire de tête.
        $bilan['fichiers déposés'] = LotImport::effacerLesFichiersDe((int) $entreprise->id);

        return array_filter($bilan, fn (int $n) => $n > 0);
    }

    /**
     * Garde-fou contre l'oubli d'une table.
     *
     * `AnnulationDUnLot` tient déjà la liste des tables qu'un import remplit, parce
     * qu'annuler un lot demande exactement cette connaissance. Une table ajoutée là et
     * oubliée ici laisserait, après une purge qui se dit complète, des lignes que plus
     * aucune facture ne justifie — et l'écart ne se verrait que des mois plus tard, sur un
     * indicateur faux. Mieux vaut une purge qui refuse de partir qu'une purge qui ment.
     */
    private function verifierQueRienNEstOublie(): void
    {
        $oubliees = array_diff(array_keys(AnnulationDUnLot::TABLES), array_keys(self::TABLES));

        if ($oubliees !== []) {
            throw new RuntimeException(
                'La purge ne connaît pas ces tables alimentées par les imports : '
                .implode(', ', $oubliees).'. Ajoutez-les à PurgerDonneesEntreprise::TABLES.'
            );
        }
    }

    /**
     * Les lots déposés, leurs lignes rejetées, et les traitements qui les attendaient.
     *
     * Un traitement encore en file pour un lot supprimé ne casse rien — le job relit son
     * lot, ne le trouve pas et s'arrête — mais il reste compté dans « travaux en attente »
     * et fait croire à un import en cours alors qu'il n'y en a plus aucun. On le retire
     * donc, en visant **le numéro exact du lot** relevé dans la charge sérialisée : une
     * recherche sur le seul nom de la classe emporterait les imports d'une autre
     * entreprise, ce qu'une purge n'a jamais le droit de faire.
     *
     * @return array<string,int>
     */
    private function effacerLesLots(Entreprise $entreprise): array
    {
        $lots = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $entreprise->id)
            ->pluck('id')
            ->all();

        if ($lots === []) {
            return [];
        }

        $compte = [];
        $compte['lignes rejetées'] = DB::table('lignes_rejetees_import')->whereIn('lot_import_id', $lots)->delete();
        $compte['traitements en file'] = $this->retirerDeLaFile($lots);
        $compte['dépôts de fichiers'] = DB::table('lots_import')->whereIn('id', $lots)->delete();

        return $compte;
    }

    /**
     * Retire de la file, et des échecs, les traitements qui portaient ces lots.
     *
     * On ne sait interroger que la file de la base : sur Redis ou SQS on ne touche à rien
     * plutôt que de prétendre avoir nettoyé.
     *
     * @param  array<int,int>  $lots
     */
    private function retirerDeLaFile(array $lots): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        $retires = 0;

        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $aRetirer = [];

            foreach (DB::table($table)->where('payload', 'like', '%TraiterUnLot%')->get(['id', 'payload']) as $ligne) {
                $commande = json_decode((string) $ligne->payload, true)['data']['command'] ?? '';

                // La charge d'un job porte son modèle sous forme d'identifiant sérialisé :
                // « …"LotImport";s:2:"id";i:44; ». C'est ce numéro-là qu'on compare.
                if (preg_match('/LotImport";s:2:"id";i:(\d+);/', (string) $commande, $trouve)
                    && in_array((int) $trouve[1], $lots, true)) {
                    $aRetirer[] = $ligne->id;
                }
            }

            if ($aRetirer !== []) {
                $retires += DB::table($table)->whereIn('id', $aRetirer)->delete();
            }
        }

        return $retires;
    }

    /**
     * Les réglages de rattachement : codes agents et correspondances.
     *
     * Ce ne sont pas des données importées, ce sont des **réponses humaines** à des
     * questions que les imports ont posées : « le code KZ, c'est quelle ville ? »,
     * « ÄBIDJAN, c'est bien Abidjan ? ». À ce titre ils relèvent de l'organisation, comme
     * les lieux, et ils survivent par défaut — refaire cette cartographie à la main après
     * chaque purge n'aurait aucun sens.
     *
     * Deux nuances quand on les garde :
     *
     * - les **occurrences repartent à zéro**. Ce compteur dit « ce code a été rencontré
     *   quatre cent douze fois dans les imports » ; sans import, il ne dit plus rien de
     *   vrai, et il sert à trier les codes à nommer en tête de liste.
     * - les **correspondances non résolues sont supprimées**. Une correspondance en
     *   attente est une question posée par un fichier ; le fichier n'existe plus, la
     *   question n'a plus d'objet. Celles qui sont résolues, elles, sont la réponse et
     *   restent.
     *
     * Quand on les efface, les réaffectations qui ne portaient que sur un code du logiciel
     * partent avec lui : leur sujet a disparu, et une ligne d'historique sans sujet
     * n'apprend plus rien à personne.
     *
     * @return array<string,int>
     */
    private function traiterLesReglagesDImport(Entreprise $entreprise, bool $purger): array
    {
        $id = $entreprise->id;

        if (! $purger) {
            $sansObjet = DB::table('correspondances_import')
                ->where('entreprise_id', $id)->where('est_resolue', false)->delete();

            DB::table('correspondances_import')->where('entreprise_id', $id)->update(['occurrences' => 0]);
            DB::table('codes_agents')->where('entreprise_id', $id)->update(['occurrences' => 0]);

            return ['correspondances sans objet' => $sansObjet];
        }

        $compte = [];

        // L'historique avant son sujet : une réaffectation encore rattachée à un code
        // bloquerait moins qu'elle ne mentirait, mais elle n'a plus rien à raconter.
        $compte['réaffectations de codes'] = DB::table('reaffectations')
            ->where('entreprise_id', $id)->whereNull('user_id')->delete();

        $compte['correspondances'] = DB::table('correspondances_import')->where('entreprise_id', $id)->delete();
        $compte['codes agents'] = DB::table('codes_agents')->where('entreprise_id', $id)->delete();

        return $compte;
    }

    /**
     * Supprime les accès de l'entreprise, sauf ceux des gérants.
     *
     * Le gérant est épargné à dessein : c'est lui qui recréera les autres. Une
     * entreprise sans aucun accès ne se rouvre plus depuis l'application — il
     * faudrait repasser par le super administrateur pour chaque compte.
     *
     * Celui qui déclenche la purge est épargné lui aussi, même s'il n'est pas
     * gérant : se supprimer soi-même en cours de route couperait la session et
     * laisserait l'opération à mi-chemin.
     *
     * @return int nombre d'accès supprimés
     */
    private function supprimerLesAcces(Entreprise $entreprise): int
    {
        $comptes = User::where('entreprise_id', $entreprise->id)->get();
        $roles = User::nomsRolesParUtilisateur($comptes->pluck('id'));

        $aSupprimer = $comptes
            ->reject(fn (User $u) => str_contains($roles[$u->id] ?? '', 'gerant'))
            ->reject(fn (User $u) => $u->id === auth()->id())
            ->pluck('id')
            ->all();

        if (empty($aSupprimer)) {
            return 0;
        }

        // Les fiches commerciales de ces comptes partent avec eux : les laisser
        // rattacherait des prospections à venir à des gens qui ne travaillent plus là.
        Commercial::withoutGlobalScopes()->whereIn('user_id', $aSupprimer)->delete();

        // Rien ne doit plus les désigner, sinon la suppression bute sur une clef.
        DB::table('sites')->whereIn('responsable_id', $aSupprimer)->update(['responsable_id' => null]);
        DB::table('villes')->whereIn('responsable_id', $aSupprimer)->update(['responsable_id' => null]);
        DB::table('users')->whereIn('cree_par_id', $aSupprimer)->update(['cree_par_id' => null]);

        DB::table('notifications_app')->whereIn('user_id', $aSupprimer)->delete();
        DB::table('abonnements_push')->whereIn('user_id', $aSupprimer)->delete();
        DB::table('compteurs_auteur')->whereIn('user_id', $aSupprimer)->delete();
        DB::table('notes')->whereIn('user_id', $aSupprimer)->delete();
        DB::table('dossiers_notes')->whereIn('user_id', $aSupprimer)->delete();

        // L'historique de mutation suit la personne : sans elle, « passé d'Abidjan à San
        // Pédro » ne désigne plus personne.
        DB::table('reaffectations')->whereIn('user_id', $aSupprimer)->delete();

        $morph = (new User)->getMorphClass();
        DB::table('model_has_roles')->whereIn('model_id', $aSupprimer)->where('model_type', $morph)->delete();
        DB::table('model_has_permissions')->whereIn('model_id', $aSupprimer)->where('model_type', $morph)->delete();

        return User::whereIn('id', $aSupprimer)->delete();
    }

    /**
     * Une entreprise vidée de ses commerciaux ne peut plus rien saisir : la liste
     * déroulante « Commercial » est vide et le « Client spontané » a disparu avec le
     * reste. Or ces fiches relèvent de l'organisation, pas des écritures — elles sont
     * donc reconstituées à l'identique après la purge, remises à zéro d'objectifs.
     *
     * @return int nombre de fiches recréées
     */
    private function reconstituerCommerciaux(Entreprise $entreprise): int
    {
        $recreees = 0;

        foreach (Ville::where('entreprise_id', $entreprise->id)->orderBy('id')->get() as $ville) {
            Commercial::create([
                'entreprise_id' => $entreprise->id,
                'ville_id' => $ville->id,
                'numero' => 'SP-'.$ville->code,
                'nom' => 'Client spontané',
                'objectif_mensuel' => 0,
                'statut' => 'Actif',
                'est_spontane' => true,
            ]);
            $recreees++;
        }

        $utilisateurs = User::where('entreprise_id', $entreprise->id)->where('est_actif', true)->orderBy('id')->get();
        $roles = User::nomsRolesParUtilisateur($utilisateurs->pluck('id'));

        foreach ($utilisateurs as $utilisateur) {
            $villeId = $this->villeDe($utilisateur, $entreprise);

            if (! $villeId || ! $this->prospecte($roles[$utilisateur->id] ?? '')) {
                continue;
            }

            Commercial::create([
                'entreprise_id' => $entreprise->id,
                'ville_id' => $villeId,
                'user_id' => $utilisateur->id,
                'numero' => GenerateurNumero::suivant($entreprise->id, 'com'),
                'nom' => $utilisateur->name,
                'objectif_mecanique' => 0,
                'objectif_sinistre' => 0,
                'statut' => 'Actif',
                'est_spontane' => false,
            ]);
            $recreees++;
        }

        return $recreees;
    }

    private function prospecte(string $roles): bool
    {
        foreach (self::ROLES_COMMERCIAUX as $role) {
            if (str_contains($roles, $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ville à laquelle rattacher la fiche recréée. Le compte porte lui-même son
     * rattachement (`users.ville_id`), justement pour qu'il survive à la suppression de
     * la fiche ; la ville supervisée et celle du lieu confié servent de garde-fou pour
     * les comptes antérieurs à cet ancrage. Aucun repli arbitraire : sans rattachement
     * connu, mieux vaut ne pas recréer de fiche que d'affecter quelqu'un au hasard.
     */
    private function villeDe(User $utilisateur, Entreprise $entreprise): ?int
    {
        if ($utilisateur->ville_id) {
            return (int) $utilisateur->ville_id;
        }

        $villeSupervisee = Ville::where('entreprise_id', $entreprise->id)->where('responsable_id', $utilisateur->id)->value('id');

        if ($villeSupervisee) {
            return (int) $villeSupervisee;
        }

        $villeDuSite = DB::table('sites')->where('entreprise_id', $entreprise->id)
            ->where('responsable_id', $utilisateur->id)->value('ville_id');

        return $villeDuSite ? (int) $villeDuSite : null;
    }
}

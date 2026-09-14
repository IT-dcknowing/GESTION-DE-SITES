<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Actions\PurgerDonneesEntreprise;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AnnulationDUnLot;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La purge face aux deux modules qui sont arrivés après elle.
 *
 * Elle savait vider les six tables d'exploitation d'origine et elle s'arrêtait là. Deux
 * modules ont ouvert onze tables depuis, et une base « vidée » gardait donc ses fiches de
 * réception, ses mouvements de caisse, ses dettes fournisseurs, ses relances — et les
 * fichiers déposés sur le disque, dont l'empreinte interdisait de redéposer le même
 * fichier. Ce qui est exactement le geste qu'on veut pouvoir refaire pour retester.
 *
 * Ce que ces tests tiennent : plus aucune ligne d'import ne survit, le disque est vide, le
 * traitement encore en file part avec son lot — et les réponses humaines aux questions des
 * imports, elles, survivent, sauf demande expresse.
 */
class PurgeDesImportsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(LotImport::DISQUE);

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan',
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ', 'nom' => 'Atelier 1',
        ]);

        $this->gerant = User::create([
            'name' => 'Gérante', 'email' => 'gerante@exemple.test', 'password' => 'mot-de-passe-de-test',
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'est_actif' => true,
        ]);

        $this->gerant->assignRole('gerant');
    }

    public function test_la_purge_ne_laisse_aucune_ligne_importee_derriere_elle(): void
    {
        $lot = $this->lotAvecSonFichier();
        $this->toutCeQuUnImportRemplit($lot);

        $bilan = (new PurgerDonneesEntreprise())->executer($this->entreprise);

        // La liste de référence est celle que tient l'annulation d'un lot : si un jour une
        // table y est ajoutée sans l'être ici, c'est cette assertion qui le dira.
        foreach (array_keys(AnnulationDUnLot::TABLES) as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('entreprise_id', $this->entreprise->id)->count(),
                "La table $table garde des lignes après la purge."
            );
        }

        $this->assertSame(0, DB::table('lots_import')->count());
        $this->assertSame(0, DB::table('lignes_rejetees_import')->count());
        $this->assertSame(0, DB::table('corrections_import')->count());
        $this->assertSame(0, DB::table('relances_recouvrement')->count());
        $this->assertSame(0, DB::table('commentaires_ecart_recouvrement')->count());

        // Le bilan rendu à l'écran nomme ce qui est parti, en français.
        $this->assertSame(1, $bilan['fiches de réception']);
        $this->assertSame(1, $bilan['mouvements de caisse']);
        $this->assertSame(1, $bilan['factures fournisseurs']);
        $this->assertSame(1, $bilan['relances']);
        $this->assertSame(1, $bilan['dépôts de fichiers']);

        // L'organisation, elle, n'a pas bougé : c'est tout l'intérêt d'une purge.
        $this->assertSame(1, Ville::count());
        $this->assertSame(1, Site::count());
        $this->assertSame(1, User::count());
    }

    public function test_le_fichier_depose_quitte_le_disque_et_le_meme_fichier_peut_revenir(): void
    {
        $lot = $this->lotAvecSonFichier();

        // Un fichier mis de côté par un contrôle préalable : rattaché à aucun lot, il
        // survivrait à une purge qui ne regarderait que les dépôts aboutis.
        $enAttente = LotImport::DOSSIER_ATTENTE.'/'.$this->entreprise->id.'/doute.xlsx';
        Storage::disk(LotImport::DISQUE)->put($enAttente, 'classeur en attente de confirmation');

        Storage::disk(LotImport::DISQUE)->assertExists($lot->cheminRelatif());

        $bilan = (new PurgerDonneesEntreprise())->executer($this->entreprise);

        Storage::disk(LotImport::DISQUE)->assertMissing($lot->cheminRelatif());
        Storage::disk(LotImport::DISQUE)->assertMissing($enAttente);
        $this->assertSame(2, $bilan['fichiers déposés']);

        // L'empreinte est unique par entreprise : tant qu'elle est en base, redéposer le
        // même fichier est annoncé comme un doublon. C'est bien cette contrainte-là qu'une
        // purge doit lever, sinon on ne peut pas rejouer le scénario qu'on voulait tester.
        $this->assertSame(0, DB::table('lots_import')->where('empreinte', $lot->empreinte)->count());
    }

    public function test_le_traitement_encore_en_file_part_avec_le_lot_qu_il_attendait(): void
    {
        config(['queue.default' => 'database']);

        $lot = $this->lotAvecSonFichier();
        $autre = $this->travailEnFilePour(999_999);
        $ancien = $this->travailEnFilePour($lot->id);

        $bilan = (new PurgerDonneesEntreprise())->executer($this->entreprise);

        $this->assertSame(1, $bilan['traitements en file']);
        $this->assertSame(0, DB::table('jobs')->where('id', $ancien)->count());

        // Le travail d'un autre lot reste : une purge ne vide jamais la file de quelqu'un
        // d'autre, et c'est pour ça qu'on vise le numéro du lot et non le nom de la classe.
        $this->assertSame(1, DB::table('jobs')->where('id', $autre)->count());
    }

    public function test_les_reglages_de_rattachement_survivent_et_leurs_compteurs_repartent_a_zero(): void
    {
        $this->reglagesDeRattachement();

        (new PurgerDonneesEntreprise())->executer($this->entreprise);

        // Le code garde sa ville : c'est une réponse humaine, pas une donnée importée.
        $code = DB::table('codes_agents')->where('code', 'KZ')->first();
        $this->assertNotNull($code);
        $this->assertSame($this->ville->id, (int) $code->ville_id);

        // Mais son compteur ne peut plus dire « rencontré 412 fois » : il ne reste aucun
        // import où il aurait pu l'être.
        $this->assertSame(0, (int) $code->occurrences);

        // La correspondance résolue est la réponse, elle reste. Celle qui attendait encore
        // une réponse était une question posée par un fichier qui n'existe plus.
        $this->assertSame(1, DB::table('correspondances_import')->count());
        $this->assertSame('ÄBIDJAN', DB::table('correspondances_import')->value('valeur_source'));
        $this->assertSame(0, (int) DB::table('correspondances_import')->value('occurrences'));
    }

    public function test_les_reglages_de_rattachement_partent_quand_on_le_demande(): void
    {
        $this->reglagesDeRattachement();

        $bilan = (new PurgerDonneesEntreprise())->executer($this->entreprise, purgerReglagesImport: true);

        $this->assertSame(0, DB::table('codes_agents')->count());
        $this->assertSame(0, DB::table('correspondances_import')->count());
        $this->assertSame(1, $bilan['codes agents']);
        $this->assertSame(2, $bilan['correspondances']);
    }

    /** Un lot déposé, avec son fichier rangé là où le modèle le calcule. */
    private function lotAvecSonFichier(): LotImport
    {
        $lot = LotImport::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'user_id' => $this->gerant->id,
            'deposant' => 'Gérante',
            'format' => 'impayes',
            'nom_fichier' => 'Etats des impayes.xlsx',
            'empreinte' => str_repeat('a', 64),
            'taille' => 4096,
            'etat' => 'termine',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), 'contenu du classeur');

        return $lot;
    }

    /** Une ligne dans chaque table qu'un import remplit, plus le travail de recouvrement. */
    private function toutCeQuUnImportRemplit(LotImport $lot): void
    {
        $commun = ['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'site_id' => $this->site->id, 'lot_import_id' => $lot->id,
            'created_at' => now(), 'updated_at' => now()];

        DB::table('dossiers_vehicules')->insert($commun + [
            'numero_fiche' => 'FR-KZN° 010669', 'immatriculation' => '1234 AB 01',
            'client' => 'Client Test', 'date_fiche' => now()->toDateString(),
        ]);

        DB::table('mouvements_caisse')->insert($commun + [
            'date' => now()->toDateString(), 'sens' => 'sortie',
            'libelle' => 'Achat pièces', 'montant' => 150_000,
        ]);

        DB::table('mouvements_vehicules')->insert($commun + [
            'sens' => 'entree', 'date' => now()->toDateString(), 'numero_fiche' => 'FR-KZN° 010669',
        ]);

        DB::table('factures_fournisseurs')->insert($commun + [
            'numero_piece' => 'FF-0001', 'fournisseur' => 'Pièces Auto SARL',
            'date_facture' => now()->toDateString(), 'montant' => 800_000, 'reste_a_payer' => 800_000,
        ]);

        $rejet = DB::table('lignes_rejetees_import')->insertGetId([
            'lot_import_id' => $lot->id, 'numero_ligne' => 4212,
            'motif' => 'Date illisible', 'valeurs' => json_encode(['DATE' => '31/02/2026']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('corrections_import')->insert([
            'entreprise_id' => $this->entreprise->id, 'lot_import_id' => $lot->id,
            'ligne_rejetee_id' => $rejet, 'numero_ligne' => 4212, 'action' => 'corrigee',
            'valeurs_avant' => json_encode(['DATE' => '31/02/2026']),
            'valeurs_apres' => json_encode(['DATE' => '28/02/2026']),
            'auteur' => 'Gérante', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('relances_recouvrement')->insert([
            'entreprise_id' => $this->entreprise->id, 'user_id' => $this->gerant->id,
            'responsable' => 'Gérante', 'date' => now()->toDateString(), 'tiers' => 'Client Test',
            'factures_visees' => 'Situation globale', 'niveau' => 2, 'canal' => 'Téléphone',
            'statut' => 'En cours', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('commentaires_ecart_recouvrement')->insert([
            'entreprise_id' => $this->entreprise->id, 'user_id' => $this->gerant->id,
            'periode' => 'semaine', 'reference' => '2026-S34', 'texte' => 'Deux gros dossiers décalés.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Un code agent nommé à la main, une correspondance résolue et une qui attend encore. */
    private function reglagesDeRattachement(): void
    {
        DB::table('codes_agents')->insert([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'site_id' => $this->site->id, 'code' => 'KZ', 'libelle' => 'K. Désirée',
            'occurrences' => 412, 'est_actif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('correspondances_import')->insert([
            ['entreprise_id' => $this->entreprise->id, 'domaine' => 'ville',
                'valeur_source' => 'ÄBIDJAN', 'valeur_cible' => 'Abidjan', 'cible_id' => $this->ville->id,
                'est_resolue' => true, 'occurrences' => 37, 'created_at' => now(), 'updated_at' => now()],
            ['entreprise_id' => $this->entreprise->id, 'domaine' => 'fournisseur',
                'valeur_source' => 'PIECES AUTOO', 'valeur_cible' => null, 'cible_id' => null,
                'est_resolue' => false, 'occurrences' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Un travail de traitement en attente dans la file, pour un lot donné.
     *
     * La charge est écrite à la main, dans la forme exacte que Laravel produit pour un job
     * porteur d'un modèle : c'est ce format-là que la purge relit pour ne retirer que les
     * traitements de ses propres lots.
     */
    private function travailEnFilePour(int $lotId): int
    {
        $commande = 'O:39:"Modules\Noyau\Imports\Jobs\TraiterUnLot":2:{s:3:"lot";'
            .'O:45:"Illuminate\Contracts\Database\ModelIdentifier":5:'
            .'{s:5:"class";s:39:"Modules\Noyau\Imports\Modeles\LotImport";s:2:"id";i:'.$lotId.';'
            .'s:9:"relations";a:0:{}s:10:"connection";s:5:"mysql";s:15:"collectionClass";N;}'
            .'s:6:"ecrire";b:1;}';

        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'Modules\Noyau\Imports\Jobs\TraiterUnLot',
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'data' => ['commandName' => 'Modules\Noyau\Imports\Jobs\TraiterUnLot', 'command' => $commande],
            ]),
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AffectationDesCodes;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\DepotEnDouble;
use Modules\Noyau\Imports\Services\NomDeFichier;
use Modules\Noyau\Imports\Services\RapprochementDesCodes;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Le dépôt d'un fichier, et le code employé qui lui donne son sens.
 *
 * Trois exigences se retrouvent ici, et elles se tiennent :
 *
 * - **pas de doublon** si un import a déjà été fait, y compris par quelqu'un d'autre ;
 * - **chaque ville dépose la sienne**, et le gérant dépose pour toutes ;
 * - **le traitement ne bloque pas la page**, parce qu'un fichier de neuf mille lignes ne
 *   se lit pas dans le temps d'une requête.
 */
class ImportDepotTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $siteUn;

    private Site $siteDeux;

    private const EN_TETE = [
        'DATE DE LA FICHE', 'DATE FIN PREVUE', 'DATE THEORIQUE ATELIER', 'N° FICHE RECEPTION',
        'IMMAT. VEHICULE', 'MARQUE', 'MODELE', 'CLIENTS / ASSURANCES', 'PROPRIETAIRES',
        'MOTIF DE LA VENUE', 'TRAVAUX A EFFECTUER', 'STATUT', 'INFORMATIONS SUR LA SITUATION',
        'DATE TRANSMISSION DEVIS', 'DATE TRAITEMENT FEB', 'DATE EFFECTIVE TRAVAUX', 'DATE FIN TRAVAUX',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach (['gerant', 'responsable_ville', 'responsable_site'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->siteUn = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        $this->siteDeux = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-2', 'nom' => 'Abidjan — Site 2', 'est_actif' => true,
        ]);
        $this->bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id,
            'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------------- le dépôt

    public function test_un_depot_range_le_fichier_et_met_le_travail_en_file(): void
    {
        Queue::fake();

        $lot = (new Depot($this->entreprise->id))->recevoir(
            $this->compte('gerant'),
            $this->classeurTeleverse(),
            'parc',
            $this->abidjan->id,
        );

        // La requête n'a pas lu une ligne : elle a rangé et rendu la main.
        $this->assertSame('depose', $lot->etat);
        $this->assertSame(0, $lot->lignes_lues);
        Storage::disk(LotImport::DISQUE)->assertExists($lot->cheminRelatif());
        Queue::assertPushed(TraiterUnLot::class);
    }

    public function test_le_depot_retient_l_atelier_declare_et_le_transmet(): void
    {
        Queue::fake();

        $lot = (new Depot($this->entreprise->id))->recevoir(
            $this->compte('gerant'),
            $this->classeurTeleverse(),
            'parc',
            $this->abidjan->id,
            false,
            $this->siteDeux->id,
        );

        $this->assertSame($this->siteDeux->id, $lot->site_id);
    }

    public function test_un_atelier_d_une_autre_ville_est_refuse(): void
    {
        Queue::fake();

        // La liste déroulante ne le proposerait pas ; une valeur forgée à la main, si. Le
        // contrôle appartient donc au service, pas à l'écran.
        $this->expectExceptionMessage("Cet atelier n'appartient pas à la ville déclarée.");

        (new Depot($this->entreprise->id))->recevoir(
            $this->compte('gerant'),
            $this->classeurTeleverse(),
            'parc',
            $this->bouake->id,
            false,
            $this->siteUn->id,
        );
    }

    public function test_les_ateliers_ne_sont_proposes_que_la_ou_le_choix_existe(): void
    {
        $service = new Depot($this->entreprise->id);

        // Abidjan en a deux : la question se pose. Bouaké n'en a qu'un : elle ne se pose pas,
        // et proposer un choix unique ferait croire qu'il y en avait d'autres.
        $this->assertCount(2, $service->sitesDeLaVille($this->abidjan->id));
        $this->assertSame([], $service->sitesDeLaVille($this->bouake->id));
    }

    public function test_le_meme_fichier_ne_s_importe_pas_deux_fois_meme_renomme(): void
    {
        Queue::fake();

        $service = new Depot($this->entreprise->id);
        $premier = $service->recevoir($this->compte('gerant'), $this->classeurTeleverse('parc-abidjan.xlsx'), 'parc', $this->abidjan->id);

        // Quelqu'un d'autre, un autre jour, sous un autre nom : c'est le même contenu.
        try {
            $service->recevoir(
                $this->compte('responsable_ville', ['ville_id' => $this->abidjan->id], 'sup@alpha.test'),
                $this->classeurTeleverse('EXPORT FINAL v2.xlsx'),
                'parc',
                $this->abidjan->id,
            );
            $this->fail('Un fichier déjà importé doit être refusé.');
        } catch (DepotEnDouble $double) {
            // Le refus dit qui, quand, et ce que ça avait donné : c'est la question que la
            // personne se pose vraiment.
            $this->assertSame($premier->id, $double->lotExistant->id);
            $this->assertStringContainsString('déjà passé', $double->getMessage());
        }

        $this->assertSame(1, LotImport::withoutGlobalScopes()->count());
    }

    public function test_un_responsable_ne_depose_que_pour_sa_ville(): void
    {
        Queue::fake();

        $responsable = $this->compte('responsable_ville', ['ville_id' => $this->bouake->id]);
        $service = new Depot($this->entreprise->id);

        $this->assertSame([$this->bouake->id => 'Bouaké'], $service->villesOuvertes($responsable));

        $this->expectExceptionMessage('Vous ne pouvez pas déposer de fichier pour cette ville.');
        $service->recevoir($responsable, $this->classeurTeleverse(), 'parc', $this->abidjan->id);
    }

    public function test_le_gerant_depose_pour_toutes_les_villes(): void
    {
        $ouvertes = (new Depot($this->entreprise->id))->villesOuvertes($this->compte('gerant'));

        $this->assertCount(2, $ouvertes);
    }

    public function test_un_fichier_qui_n_est_pas_un_classeur_est_refuse_avant_d_etre_range(): void
    {
        Queue::fake();

        $intrus = UploadedFile::fake()->createWithContent('rapport.xlsx', '<?php system($_GET["c"]); ?>');

        try {
            (new Depot($this->entreprise->id))->recevoir($this->compte('gerant'), $intrus, 'parc', $this->abidjan->id);
            $this->fail('Un fichier déguisé en classeur doit être refusé.');
        } catch (\RuntimeException $refus) {
            $this->assertStringContainsString("n'est ni un classeur", $refus->getMessage());
        }

        // Rien n'a été rangé, rien n'a été déclaré : le contrôle porte sur le contenu et
        // vient avant toute écriture.
        $this->assertSame(0, LotImport::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_le_traitement_en_file_lit_le_fichier_et_rend_compte(): void
    {
        $lot = (new Depot($this->entreprise->id))->recevoir(
            $this->compte('gerant'),
            $this->classeurTeleverse(),
            'parc',
            $this->abidjan->id,
        );

        // Sans Queue::fake, la connexion « sync » des tests exécute le travail aussitôt.
        $lot->refresh();

        $this->assertSame('termine', $lot->etat);
        $this->assertSame(2, $lot->lignes_creees);
        $this->assertSame(2, DossierVehicule::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------------- le nom du fichier

    public function test_le_nom_du_fichier_pre_remplit_sans_jamais_decider(): void
    {
        $service = new NomDeFichier($this->entreprise->id);

        $lecture = $service->analyser('Abidjan_Situation du parc190826.xls');
        $this->assertSame('parc', $lecture['format']);
        $this->assertSame($this->abidjan->id, $lecture['ville_id']);
        $this->assertSame('au 19/08/2026', $lecture['periode']);

        // Les noms mutilés par les accents perdus doivent quand même se reconnaître.
        $this->assertSame($this->bouake->id, $service->analyser('Bouak_Devis190826.xlsx')['ville_id']);

        // L'indice le plus long l'emporte : « SITUATION DU PARC » sur « PARC ».
        $this->assertSame('impayes', $service->analyser('Etats des impayés L2A au 200826.xlsx')['format']);

        // Un nom qui ne dit rien ne fait rien dire.
        $lecture = $service->analyser('classeur1.xlsx');
        $this->assertNull($lecture['format']);
        $this->assertNull($lecture['ville_id']);
        $this->assertFalse($lecture['conforme']);

        $this->assertTrue($service->analyser('ABIDJAN_PARC_190826.xlsx')['conforme']);
    }

    // ------------------------------------------------------------ le code de l'employé

    public function test_creer_un_acces_avec_son_code_rattache_aussitot_son_travail(): void
    {
        // Des fiches signées KZ dorment déjà en base, sans atelier : personne ne savait qui
        // était KZ.
        foreach (['FR-KZN° 000001', 'FR-KZN° 000002'] as $numero) {
            DossierVehicule::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entreprise->id,
                'ville_id' => $this->abidjan->id,
                'numero_fiche' => $numero,
                'code_agent' => 'KZ',
                'source_rattachement' => 'depot',
                'rattachement_presume' => true,
            ]);
        }

        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'KZ', 'occurrences' => 2, 'est_actif' => true,
        ]);

        // On ouvre l'accès du responsable du Site 1 en renseignant son code.
        $utilisateur = (new CreerAcces)->executer($this->entreprise, 'responsable_site', [
            'nom' => 'KOUADIO Zita',
            'email' => 'zita@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'site_id' => $this->siteUn->id,
            'code_agent' => 'KZ',
            'est_actif' => false,
        ]);

        $agent = CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->first();

        $this->assertSame($utilisateur->id, $agent->user_id);
        $this->assertSame('KOUADIO Zita', $agent->libelle);
        // La ville et l'atelier viennent du périmètre du compte, jamais d'un second champ.
        $this->assertSame($this->siteUn->id, $agent->site_id);
        $this->assertSame($this->abidjan->id, $agent->ville_id);

        // Et le travail déjà en base se range enfin.
        $deplacees = (new AffectationDesCodes($this->entreprise->id))->rejouerLesPresomptions();

        $this->assertSame(2, $deplacees);
        $this->assertSame(2, DossierVehicule::withoutGlobalScopes()
            ->where('site_id', $this->siteUn->id)->count());
    }

    public function test_un_code_deja_pris_n_empeche_pas_la_creation_de_l_acces(): void
    {
        $premier = $this->compte('responsable_site', ['site_id' => $this->siteUn->id, 'ville_id' => $this->abidjan->id]);
        (new AffectationDesCodes($this->entreprise->id))->attribuerA($premier, 'KZ');

        // Le code est une question de référentiel, pas une raison de refuser un accès dont
        // tout le reste est valable. Refuser au dernier moment obligerait à ressaisir le
        // formulaire entier pour deux lettres.
        $action = new CreerAcces;

        $nouveau = $action->executer($this->entreprise, 'responsable_site', [
            'nom' => 'Quelqu\'un d\'autre',
            'email' => 'autre@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'site_id' => $this->siteDeux->id,
            'code_agent' => 'KZ',
            'est_actif' => false,
        ]);

        // Le compte existe…
        $this->assertNotNull(User::where('email', 'autre@alpha.test')->first());
        $this->assertSame($this->siteDeux->id, $nouveau->site_id);

        // …et l'écran sait quoi dire, sans dramatiser.
        $this->assertStringContainsString('déjà celui de', (string) $action->refusDuCode);

        // Et surtout : le code n'a pas changé de main. Un code désigne une personne, et
        // c'est bien la première qui le garde.
        $this->assertSame($premier->id, CodeAgent::withoutGlobalScopes()->where('code', 'KZ')->value('user_id'));
    }

    public function test_un_code_mal_forme_est_refuse_avec_la_raison(): void
    {
        $compte = $this->compte('responsable_site', ['site_id' => $this->siteUn->id]);

        $this->expectExceptionMessage('exactement deux lettres');
        (new AffectationDesCodes($this->entreprise->id))->attribuerA($compte, 'KZ1');
    }

    public function test_un_code_vide_ne_cree_rien(): void
    {
        $compte = $this->compte('responsable_site', ['site_id' => $this->siteUn->id]);

        $this->assertNull((new AffectationDesCodes($this->entreprise->id))->attribuerA($compte, ''));
        $this->assertSame(0, CodeAgent::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------- le rapprochement des noms

    public function test_les_formes_reconnues_sont_celles_des_vrais_codes(): void
    {
        // Prénom + nom : « TT » pour TOU Tahirou.
        $this->assertContains('TT', RapprochementDesCodes::formesDe('TOU TAHIROU'));
        // Nom + prénom : « SK » pour Sandrine Kouadio.
        $this->assertContains('SK', RapprochementDesCodes::formesDe('SANDRINE KOUADIO'));
        // Les deux premières lettres du nom de famille : « AB » pour ABE Géraud.
        $this->assertContains('AB', RapprochementDesCodes::formesDe('ABE GERAUD WILFRIED'));

        // Les mentions d'état civil ne donnent pas d'initiale : « MADAME ADOU VANESSA » ne
        // doit pas produire « MA », qui est un code réel appartenant à quelqu'un d'autre.
        $this->assertNotContains('MA', RapprochementDesCodes::formesDe('MADAME ADOU VANESSA'));
        $this->assertContains('AV', RapprochementDesCodes::formesDe('MADAME ADOU VANESSA'));
    }

    public function test_le_rapprochement_propose_mais_ne_conclut_pas(): void
    {
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'TT', 'occurrences' => 2, 'est_actif' => true,
        ]);
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'KZ', 'occurrences' => 924, 'est_actif' => true,
        ]);

        $service = new RapprochementDesCodes($this->entreprise->id);

        $proposes = $service->codesPossiblesPour('TOU TAHIROU')->pluck('code')->all();
        $this->assertSame(['TT'], $proposes);

        // Le point qui compte : sur les vrais fichiers, 7 codes sur 17 ne correspondent à
        // personne — dont KZ, qui porte 924 fiches. Le service doit le dire, pas inventer.
        $this->assertTrue($service->comptesPossiblesPour('KZ')->isEmpty());
    }

    public function test_un_code_deja_attribue_n_est_plus_propose(): void
    {
        $compte = $this->compte('responsable_site', ['site_id' => $this->siteUn->id]);
        (new AffectationDesCodes($this->entreprise->id))->attribuerA($compte, 'TT');

        $this->assertTrue((new RapprochementDesCodes($this->entreprise->id))
            ->codesPossiblesPour('TOU TAHIROU')->isEmpty());
    }

    // ------------------------------------------------------------------------ fabrique

    /** Le chemin du classeur d'essai, construit une seule fois pour tout le scénario. */
    private ?string $classeur = null;

    /**
     * Le même classeur, présenté sous le nom qu'on veut.
     *
     * Le fichier est construit **une seule fois** et réutilisé, et ce n'est pas une
     * optimisation : une archive ZIP horodate ses entrées, donc deux classeurs générés à
     * une seconde d'écart n'ont pas les mêmes octets, donc pas la même empreinte. Le
     * scénario « le même fichier, renommé » exige les mêmes octets — sinon le test passe
     * ou échoue selon l'heure à laquelle on le lance.
     */
    private function classeurTeleverse(string $nom = 'Abidjan_Situation du parc190826.xlsx'): UploadedFile
    {
        $this->classeur ??= $this->classeurXlsx(['A' => [
            self::EN_TETE,
            $this->fiche('FR-KZN° 010669'),
            $this->fiche('FR-ABN° 012094'),
        ]]);

        // Une copie par téléversement : l'objet UploadedFile consomme le fichier qu'on lui
        // donne, et deux dépôts sont bien deux envois distincts du même contenu.
        $copie = $this->classeur.'-'.bin2hex(random_bytes(4));
        copy($this->classeur, $copie);

        return new UploadedFile($copie, $nom, 'application/vnd.ms-excel', null, true);
    }

    private function fiche(string $numero): array
    {
        return [
            ['date' => '2026-01-02'], ['date' => '2026-03-11'], null, $numero,
            'AA689JQ01', 'TOYOTA', 'BELTA', 'COMAR ASSURANCES', 'SAFCA-ALIOS',
            'SINISTRE', 'A REMPLACER', 'DEVIS VALIDE / TRAVAUX EN COURS', null,
            null, ['date' => '2026-02-20'], null, null,
        ];
    }

    private function compte(string $role, array $extra = [], string $email = null): User
    {
        $utilisateur = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $email ?? $role.'@alpha.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ] + $extra);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $utilisateur->assignRole($role);

        return $utilisateur->fresh();
    }
}

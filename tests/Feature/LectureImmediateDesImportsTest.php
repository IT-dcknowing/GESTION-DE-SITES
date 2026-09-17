<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\FormatDuParc;
use Modules\Noyau\Imports\Jobs\TraiterUnLot;
use Modules\Noyau\Imports\Lecteurs\LecteurXlsx;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Depot;
use Modules\Noyau\Imports\Services\Executeur;
use Modules\Noyau\Imports\Services\ImportArrete;
use Modules\Noyau\Imports\Services\SuiviDuTraitement;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;
use ZipArchive;

/**
 * La lecture d'un fichier démarre au dépôt, se voit avancer, et s'arrête sur demande.
 *
 * Le défaut signalé : après « Lancer l'import », rien ne bougeait tant qu'on n'avait pas cliqué
 * « Traiter maintenant » ou « Tout traiter », et la barre de progression restait figée. Trois
 * causes, trois garanties tenues ici :
 *
 * - la lecture ne dépend plus d'un exécuteur de file — et le même lot n'est jamais lu deux fois
 *   quand deux chemins le lancent ;
 * - l'avancée ne s'écrit plus dans la transaction de l'import, où personne ne la voyait ;
 * - l'arrêt demandé défait tout ce que la lecture avait commencé d'écrire.
 */
class LectureImmediateDesImportsTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

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
        Cache::store(SuiviDuTraitement::MAGASIN)->flush();

        foreach (['gerant', 'responsable_ville', 'responsable_site', 'caissier'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_un_lot_n_est_pris_qu_une_fois(): void
    {
        $lot = $this->lot();

        // Le processus lancé au dépôt et la file d'attente arrivent tous deux : un seul lit.
        $this->assertTrue(SuiviDuTraitement::prendre($lot));
        $this->assertFalse(SuiviDuTraitement::prendre($lot));
        $this->assertSame('en_cours', $lot->fresh()->etat);
    }

    public function test_le_travail_en_file_ne_relit_pas_un_lot_deja_pris(): void
    {
        $lot = $this->deposer(5, Queue::fake(...));

        SuiviDuTraitement::prendre($lot);

        (new TraiterUnLot($lot, true))->handle();

        $this->assertSame(0, DossierVehicule::withoutGlobalScopes()->count());
    }

    public function test_le_depot_lit_le_fichier_sans_qu_on_ait_a_le_lancer(): void
    {
        $lot = $this->deposer(3);

        $lot->refresh();

        $this->assertSame('termine', $lot->etat);
        $this->assertSame(3, DossierVehicule::withoutGlobalScopes()->count());
        $this->assertSame(100, SuiviDuTraitement::etat($lot)['pourcentage']);
    }

    public function test_l_avancee_se_lit_pendant_la_lecture(): void
    {
        $lot = $this->lot(['lignes_estimees' => 400]);
        SuiviDuTraitement::prendre($lot);

        SuiviDuTraitement::avancer($lot->id, 100);

        $etat = SuiviDuTraitement::etat($lot->fresh());

        $this->assertSame(100, $etat['lues']);
        $this->assertSame(25, $etat['pourcentage']);
        $this->assertFalse($etat['interrompu']);
    }

    public function test_arreter_un_depot_qui_n_a_pas_commence_l_annule_sans_rien_lire(): void
    {
        $gerant = $this->compte('gerant');
        $lot = $this->lot();

        $this->actingAs($gerant)
            ->post(route('import.lot.agir', $lot->id), ['geste' => 'arreter'])
            ->assertSessionHas('annonce-import');

        $this->assertSame('annule', $lot->fresh()->etat);

        // Le travail en file qui arriverait ensuite trouve le lot annulé et n'y touche pas.
        $this->assertFalse(SuiviDuTraitement::prendre($lot));
    }

    public function test_arreter_une_lecture_en_cours_defait_tout_ce_qu_elle_avait_ecrit(): void
    {
        $gerant = $this->compte('gerant');
        $lot = $this->deposer(150, Queue::fake(...));

        // On rejoue ce que fait le travail : prendre, puis lire en notant l'avancée.
        SuiviDuTraitement::prendre($lot);
        SuiviDuTraitement::demanderLArret($lot->fresh(), $gerant->id, $gerant->name);

        try {
            (new Executeur($this->entreprise->id))->traiter(
                $lot->fresh(),
                FormatDuParc::class,
                true,
                fn (int $lues) => SuiviDuTraitement::avancer($lot->id, $lues),
            );
            $this->fail("La lecture aurait dû s'arrêter à la centième ligne.");
        } catch (ImportArrete) {
        }

        $lot->refresh();

        $this->assertSame('annule', $lot->etat);
        $this->assertSame($gerant->id, (int) $lot->annule_par);
        $this->assertStringContainsString("rien n'a été écrit", $lot->message);
        // Cent fiches avaient été écrites dans la transaction : aucune ne reste.
        $this->assertSame(0, DossierVehicule::withoutGlobalScopes()->count());
    }

    public function test_un_import_termine_ne_s_arrete_pas(): void
    {
        $gerant = $this->compte('gerant');
        $lot = $this->lot(['etat' => 'termine']);

        $this->actingAs($gerant)
            ->post(route('import.lot.agir', $lot->id), ['geste' => 'arreter'])
            ->assertSessionHas('refus-import');

        $this->assertSame('termine', $lot->fresh()->etat);
    }

    public function test_on_ne_relance_pas_une_lecture_qui_tourne_encore(): void
    {
        $lot = $this->lot();
        SuiviDuTraitement::prendre($lot);
        SuiviDuTraitement::avancer($lot->id, 100);

        $this->expectException(\RuntimeException::class);

        (new Depot($this->entreprise->id))->relancer($lot->fresh());
    }

    public function test_les_ecrans_n_ont_plus_de_bouton_pour_lancer_la_lecture(): void
    {
        $gerant = $this->compte('gerant');
        $lot = $this->lot();

        foreach ([route('import.depot', ['lot' => $lot->id]), route('import.traitements'), route('import.lot', $lot->id)] as $adresse) {
            $this->actingAs($gerant)->get($adresse)
                ->assertOk()
                ->assertDontSee('Traiter maintenant')
                ->assertDontSee('Tout traiter')
                ->assertDontSee('Traiter celui-ci')
                ->assertSee("Annuler l'import", false);
        }

        // L'adresse du « tout traiter » est fermée avec son bouton.
        $this->actingAs($gerant)->post('/import/traitements')->assertStatus(405);
    }

    public function test_le_classeur_annonce_sa_longueur_sans_etre_parcouru(): void
    {
        $chemin = $this->classeurXlsx(['A' => [self::EN_TETE, $this->fiche(1)]]);

        // Un vrai tableur écrit l'étendue de la feuille dès son en-tête.
        $zip = new ZipArchive;
        $zip->open($chemin);
        $feuille = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<sheetData>', '<dimension ref="A1:Q9108"/><sheetData>', $feuille));
        $zip->close();

        $this->assertSame(9108, (new LecteurXlsx($chemin))->estimerLignes());
    }

    // ----------------------------------------------------------------------------- outils

    private function deposer(int $fiches, ?\Closure $avant = null): LotImport
    {
        if ($avant !== null) {
            $avant();
        }

        $lignes = [self::EN_TETE];

        for ($i = 1; $i <= $fiches; $i++) {
            $lignes[] = $this->fiche($i);
        }

        $chemin = $this->classeurXlsx(['A' => $lignes]);

        return (new Depot($this->entreprise->id))->recevoir(
            $this->compte('gerant', 'depot-'.bin2hex(random_bytes(3)).'@alpha.test'),
            new UploadedFile($chemin, 'Abidjan_Situation du parc.xlsx', 'application/vnd.ms-excel', null, true),
            'parc',
            $this->abidjan->id,
        );
    }

    private function lot(array $valeurs = []): LotImport
    {
        return LotImport::withoutGlobalScopes()->create($valeurs + [
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'deposant' => 'Essai',
            'format' => 'parc',
            'nom_fichier' => 'parc.xlsx',
            'empreinte' => bin2hex(random_bytes(32)),
            'etat' => 'depose',
        ]);
    }

    private function fiche(int $rang): array
    {
        return [
            ['date' => '2026-01-02'], ['date' => '2026-03-11'], null, sprintf('FR-KZN° %06d', 10000 + $rang),
            'AA'.$rang.'JQ01', 'TOYOTA', 'BELTA', 'COMAR ASSURANCES', 'SAFCA-ALIOS',
            'SINISTRE', 'A REMPLACER', 'DEVIS VALIDE / TRAVAUX EN COURS', null,
            null, ['date' => '2026-02-20'], null, null,
        ];
    }

    private function compte(string $role, ?string $email = null): User
    {
        $utilisateur = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $email ?? $role.'@alpha.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $utilisateur->assignRole($role);

        return $utilisateur->fresh();
    }
}

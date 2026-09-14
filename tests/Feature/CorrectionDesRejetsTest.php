<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Lecteurs\LecteurCorrige;
use Modules\Noyau\Imports\Modeles\CorrectionImport;
use Modules\Noyau\Imports\Modeles\LigneRejeteeImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\CorrectionsDUnLot;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Corriger une ligne refusée, et pouvoir dire qui l'a fait.
 *
 * **Ce qui est vérifié, et pourquoi chacun de ces points compte.**
 *
 * - On ne corrige **que** les lignes que l'import a lui-même refusées. Le numéro de ligne
 *   vient du journal des rejets, jamais du formulaire : sans cela, n'importe quelle ligne
 *   du classeur — y compris une ligne acceptée et déjà écrite — deviendrait modifiable
 *   depuis le navigateur.
 * - Le **fichier déposé n'est pas réécrit** : son empreinte reste celle calculée à la
 *   réception. C'est elle qui prouve qu'on n'y a pas touché, et qui refuse les doublons.
 * - La correction est **vue par la relecture**, à travers la surcouche : sans cela elle
 *   serait un commentaire décoratif.
 * - Tout est **consigné** : valeur d'avant, valeur d'après, auteur, adresse. Corriger une
 *   ligne d'import déplace de l'argent dans un chiffre d'affaires.
 */
class CorrectionDesRejetsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private LotImport $lot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['gerant', 'caissier'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'code' => 'ABJ-1', 'nom' => 'Site 1', 'est_actif' => true]);

        $this->lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'user_id' => null,
            'deposant' => 'Essai',
            'format' => 'parc',
            'nom_fichier' => 'parc.xls',
            'empreinte' => str_repeat('a', 64),
            'taille' => 10,
            'etat' => 'termine',
            'lignes_lues' => 3,
            'lignes_rejetees' => 2,
        ]);

        LigneRejeteeImport::create([
            'lot_import_id' => $this->lot->id,
            'feuille' => 'Feuil1',
            'numero_ligne' => 12,
            'motif' => 'La colonne « MONTANT » est vide.',
            'valeurs' => [0 => '2026-01-05', 1 => 'FR-KZN° 010669', 2 => ''],
        ]);

        LigneRejeteeImport::create([
            'lot_import_id' => $this->lot->id,
            'feuille' => 'Feuil1',
            'numero_ligne' => 19,
            'motif' => 'La date de la facture est absente ou illisible.',
            'valeurs' => [0 => '', 1 => 'FR-ABN° 010670', 2 => '355000'],
        ]);
    }

    public function test_une_correction_est_enregistree_avec_son_avant_et_son_apres(): void
    {
        $auteur = $this->compte('gerant');

        $compte = (new CorrectionsDUnLot((int) $this->entreprise->id))->enregistrer(
            $this->lot,
            [12 => [2 => '150000']],
            [],
            $auteur,
            '203.0.113.7',
            'Navigateur d\'essai',
            'Montant repris sur la facture papier',
        );

        $this->assertSame(1, $compte['corrigees']);

        $correction = CorrectionImport::withoutGlobalScopes()->first();

        $this->assertNotNull($correction);
        $this->assertSame('corrigee', $correction->action);
        $this->assertSame('', $correction->valeurs_avant[2] ?? null);
        $this->assertSame('150000', $correction->valeurs_apres[2] ?? null);
        $this->assertSame('203.0.113.7', $correction->adresse_ip);
        $this->assertSame($auteur->name, $correction->auteur);
        $this->assertSame([2 => ['avant' => '', 'apres' => '150000']], $correction->ecarts());
    }

    public function test_la_correction_est_vue_par_la_relecture(): void
    {
        $service = new CorrectionsDUnLot((int) $this->entreprise->id);

        $service->enregistrer($this->lot, [12 => [2 => '150000']], [], $this->compte('gerant'), null, null);

        $lecteur = LecteurCorrige::envelopper(
            new LecteurDEssai(['Feuil1' => [12 => [0 => '2026-01-05', 1 => 'FR-KZ', 2 => ''], 19 => [0 => '', 1 => 'x', 2 => '355000']]]),
            $service->carte($this->lot),
        );

        $lues = [];

        foreach ($lecteur->lignes('Feuil1') as $numero => $cellules) {
            $lues[$numero] = $cellules;
        }

        $this->assertSame('150000', $lues[12][2], 'La relecture doit voir la valeur corrigée.');
        $this->assertSame('355000', $lues[19][2], "Les autres lignes ne bougent pas.");
    }

    public function test_une_ligne_retiree_disparait_de_la_relecture(): void
    {
        $service = new CorrectionsDUnLot((int) $this->entreprise->id);

        $compte = $service->enregistrer($this->lot, [], [19], $this->compte('gerant'), null, null);

        $this->assertSame(1, $compte['retirees']);

        $lecteur = LecteurCorrige::envelopper(
            new LecteurDEssai(['Feuil1' => [12 => [0 => 'a'], 19 => [0 => 'b']]]),
            $service->carte($this->lot),
        );

        $numeros = [];

        foreach ($lecteur->lignes('Feuil1') as $numero => $cellules) {
            $numeros[] = $numero;
        }

        $this->assertSame([12], $numeros, "Une ligne écartée ne doit plus être lue du tout.");
    }

    public function test_une_ligne_qui_n_a_pas_ete_refusee_n_est_pas_corrigeable(): void
    {
        $service = new CorrectionsDUnLot((int) $this->entreprise->id);

        // La ligne 500 n'a jamais été rejetée : elle est peut-être acceptée et déjà écrite.
        $compte = $service->enregistrer($this->lot, [500 => [2 => '999999']], [], $this->compte('gerant'), null, null);

        $this->assertSame(0, $compte['corrigees']);
        $this->assertSame(0, CorrectionImport::withoutGlobalScopes()->count());
    }

    public function test_le_fichier_depose_n_est_jamais_reecrit(): void
    {
        $empreinte = $this->lot->empreinte;

        (new CorrectionsDUnLot((int) $this->entreprise->id))
            ->enregistrer($this->lot, [12 => [2 => '150000']], [], $this->compte('gerant'), null, null);

        $this->assertSame(
            $empreinte,
            $this->lot->fresh()->empreinte,
            "L'empreinte prouve que le fichier reçu n'a pas bougé : c'est la pièce d'origine.",
        );
    }

    public function test_seul_qui_depose_peut_corriger(): void
    {
        $this->actingAs($this->compte('caissier'))
            ->post(route('import.lot.corriger', $this->lot->id), ['valeurs' => [12 => [2 => '150000']]])
            ->assertRedirect();

        $this->assertSame(
            0,
            CorrectionImport::withoutGlobalScopes()->count(),
            "Corriger un import engage la base autant que déposer.",
        );
    }

    public function test_la_derniere_correction_d_une_ligne_fait_foi(): void
    {
        $service = new CorrectionsDUnLot((int) $this->entreprise->id);
        $auteur = $this->compte('gerant');

        $service->enregistrer($this->lot, [12 => [2 => '150000']], [], $auteur, null, null);
        $service->enregistrer($this->lot, [12 => [2 => '160000']], [], $auteur, null, null);

        $carte = $service->carte($this->lot);
        $cle = LecteurCorrige::cle('Feuil1', 12);

        $this->assertSame('160000', $carte[$cle]['valeurs'][2]);
        $this->assertSame(2, $service->histoire($this->lot)->count(), "Les deux écritures restent lisibles.");
    }

    private function compte(string $role): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $role.'@corrections.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole($role);

        return $u->fresh();
    }
}

/** Un classeur en mémoire : la surcouche se teste sans dépendre d'un fichier sur disque. */
class LecteurDEssai implements \Modules\Noyau\Imports\Lecteurs\Lecteur
{
    public function __construct(private array $feuilles) {}

    public function feuilles(): array
    {
        return array_keys($this->feuilles);
    }

    public function lignes(?string $feuille = null): \Generator
    {
        foreach ($this->feuilles[$feuille] ?? [] as $numero => $cellules) {
            yield $numero => $cellules;
        }
    }

    public function fermer(): void {}
}

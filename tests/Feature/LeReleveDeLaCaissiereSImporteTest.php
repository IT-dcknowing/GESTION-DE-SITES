<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Imports\Formats\FormatDuReleveBancaire;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\MouvementBancaire;
use Modules\Noyau\Imports\Services\Executeur;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Le relevé bancaire tel que la caissière le tient s'importe — constat du 07/10.
 *
 * Le classeur de test reprend la forme exacte des quatre classeurs BGFI reçus : douze lignes
 * d'en-tête de banque, le solde initial, les titres en ligne 13, des lignes « MOTIF : … » qui
 * précisent l'opération d'avant, une opération sans date, les colonnes F et G ajoutées par la
 * caissière, une seconde feuille « POINT DES ENCAISSEMENTS » et des lignes de pied.
 */
class LeReleveDeLaCaissiereSImporteTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(LotImport::DISQUE);

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        $this->ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
    }

    public function test_le_releve_2026_se_lit_comme_la_chaine_des_soldes_le_veut(): void
    {
        $bgfi = $this->banque('BGFI');
        $this->importer($bgfi, $this->releve2026());

        $ops = MouvementBancaire::withoutGlobalScopes()->orderBy('rang')->get();

        $this->assertCount(4, $ops, 'Quatre opérations : les lignes MOTIF, de pied et la seconde feuille ne sont pas des opérations.');
        $this->assertSame([86_801, 0, 0, 5_000_000], $ops->pluck('credit')->all());
        $this->assertSame([0, 4_820_000, 100, 0], $ops->pluck('debit')->all());

        // La ligne MOTIF précise l'opération d'avant.
        $this->assertSame('MOTIF: DC99992512310078 L ARTISAN DE L AUTOMOBILE', $ops[0]->motif);
        // L'opération sans date prend celle de la ligne d'avant.
        $this->assertSame('2026-01-02', $ops[2]->date_operation->toDateString());
        // Les colonnes de la caissière : F la contrepartie, G la nature.
        $this->assertSame('LEADWAY', $ops[0]->contrepartie);
        $this->assertSame('pièces carrosserie', $ops[1]->nature);
        $this->assertSame(MouvementBancaire::ENTREE, $ops[0]->sens);
        $this->assertSame(MouvementBancaire::SORTIE, $ops[1]->sens);

        // La chaîne : chaque solde annoncé suit le précédent, ligne à ligne.
        $solde = 7_910_017;
        foreach ($ops as $op) {
            $solde += $op->credit - $op->debit;
            $this->assertSame($solde, $op->solde_annonce, "Rupture de chaîne au rang {$op->rang}.");
        }
    }

    public function test_un_releve_2023_sans_les_colonnes_ajoutees_se_lit_aussi(): void
    {
        $this->importer($this->banque('BGFI'), ['SOLDE JOURNALIER BGFI BANK' => array_merge($this->entete(42_154_595, false), [
            [['date' => '2023-01-02'], 'RETRAIT CH 1220461 CONDE OUMA', 13_000_000, null, 29_154_595],
            [['date' => '2023-01-02'], 'VERS. ESP.VERST/ YAO EKRA', null, 8_000_000, 37_154_595],
        ])]);

        $ops = MouvementBancaire::withoutGlobalScopes()->orderBy('rang')->get();
        $this->assertCount(2, $ops);
        $this->assertNull($ops[0]->contrepartie);
        $this->assertSame(37_154_595, $ops[1]->solde_annonce);
    }

    public function test_redeposer_ou_chevaucher_ne_double_rien(): void
    {
        $bgfi = $this->banque('BGFI');
        $this->importer($bgfi, $this->releve2026(), 'releve-2026.xlsx');
        $this->importer($bgfi, $this->releve2026(), 'releve-2026-bis.xlsx');

        $this->assertSame(4, MouvementBancaire::withoutGlobalScopes()->count());
        // Le motif n'est pas recollé derrière lui-même.
        $this->assertSame('MOTIF: DC99992512310078 L ARTISAN DE L AUTOMOBILE', MouvementBancaire::withoutGlobalScopes()->orderBy('rang')->first()->motif);

        // Le même relevé déposé sur un autre compte est une autre histoire.
        $this->importer($this->banque('AFG'), $this->releve2026(), 'releve-afg.xlsx');
        $this->assertSame(8, MouvementBancaire::withoutGlobalScopes()->count());
    }

    public function test_le_depot_exige_le_compte_pour_ce_type_aussi(): void
    {
        $this->assertSame(FormatDuReleveBancaire::class, Registre::classe('releve-banque'));
        $this->assertContains('releve-banque', Registre::formatsParCompte());
        $this->assertContains('banque', Registre::formatsParCompte(), "L'import du logiciel reste.");

        $this->banque('BGFI');

        $this->actingAs($this->gerant())
            ->post(route('import.deposer'), [
                'fichier' => UploadedFile::fake()->createWithContent('releve.xlsx', 'x'),
                'format' => 'releve-banque',
                'ville' => (string) $this->ville->id,
            ])
            ->assertSessionHasErrors('banque');
    }

    public function test_la_commande_declare_bgfi_et_afg_d_abord_en_constat(): void
    {
        $this->artisan('banques:declarer')->assertSuccessful();
        $this->assertSame(0, Banque::withoutGlobalScopes()->count(), 'Le constat n\'écrit rien.');

        $this->artisan('banques:declarer', ['--appliquer' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['BGFI', 'AFG'], Banque::withoutGlobalScopes()->pluck('nom')->all());
        $this->assertNotNull(Banque::withoutGlobalScopes()->first()->code, 'Le code est posé par le système.');

        // Relancée : rien de plus.
        $this->artisan('banques:declarer', ['--appliquer' => true])->assertSuccessful();
        $this->assertSame(2, Banque::withoutGlobalScopes()->count());
    }

    /** Le classeur 2026, en petit : la forme exacte du vrai. */
    private function releve2026(): array
    {
        return [
            'SOLDE JOURNALIER BGFI BANK' => array_merge($this->entete(7_910_017, true), [
                [['date' => '2026-01-02'], 'VIR.RECU  LEADWAY ASSURANCE IA', null, 86_801, 7_996_818, 'LEADWAY'],
                [' ', 'MOTIF: DC99992512310078 L ARTISAN DE L AUTOMOBILE', ' ', ' ', null, null],
                [['date' => '2026-01-02'], 'CHEQUE NO  1854929', 4_820_000, null, 3_176_818, 'OUATTARA', 'pièces carrosserie'],
                // Une opération sans date : elle prend celle d'avant.
                [' ', 'VERSEMENT VERST / PRESTATIONS', 100, null, 3_176_718, 'BGFI'],
                [['date' => '2026-01-05'], 'VERSEMENT VERST / PRESTATIONS', null, 5_000_000, 8_176_718, 'BGFI'],
                [null, 'Echéance N° 023 / 0', ' ', ' ', null, null],
                // Les lignes de pied : un solde, rien d'autre.
                [null, null, null, null, 8_176_718],
                [null, null, null, null, 8_176_718],
            ]),
            'POINT DES ENCAISSEMENTS' => array_merge($this->entete(7_910_017, false, ['Date', "Libellé de l'opération", 'Crédit(XOF)', 'Libellé']), [
                [['date' => '2026-01-02'], 'VIR.RECU  LEADWAY ASSURANCE IA', 86_801, 'LEADWAY'],
                [null, 'Total', 86_801],
            ]),
        ];
    }

    private function entete(int $soldeInitial, bool $avecLibelle, ?array $titres = null): array
    {
        return [
            ['EXTRAIT DE COMPTE'],
            ['Période du', ['date' => '2026-01-01'], 'au', ['date' => '2026-10-05']],
            [],
            ['Code client', 103056],
            ['Nom du client', "SARL L'ARTISAN AUTOMOBILE"],
            ['Numéro de compte', '01000-02010305601-13 XOF'],
            ['Libellé du compte', 'CPTE ORD PME PMI'],
            ['Code IBAN', 'CI33CI1620100000201030560113'],
            ['Date', ['date' => '2026-10-05']],
            [],
            [],
            [null, 'Solde initial (XOF) : '.number_format($soldeInitial, 0, ',', ' '), null, null, $soldeInitial],
            $titres ?? array_merge(['Date', "Libellé de l'opération", 'Débit(XOF)', 'Crédit(XOF)', 'Solde(XOF)'], $avecLibelle ? ['Libellé'] : []),
        ];
    }

    private function importer(Banque $banque, array $feuilles, string $nom = 'releve.xlsx'): void
    {
        $chemin = $this->classeurXlsx($feuilles);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'banque_id' => $banque->id,
            'deposant' => 'Caissière', 'format' => 'releve-banque', 'nom_fichier' => $nom,
            'empreinte' => hash('sha256', $nom.$banque->id), 'taille' => 1024, 'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        (new Executeur($this->entreprise->id))->traiter($lot, FormatDuReleveBancaire::class);
    }

    private function banque(string $nom): Banque
    {
        return Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'nom' => $nom, 'nom_normalise' => Banque::clePour($nom),
        ]);
    }

    private function gerant(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'est_actif' => true,
        ]);
        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

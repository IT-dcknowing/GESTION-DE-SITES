<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Imports\Formats\FormatDesPiecesBancaires;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\PieceBancaire;
use Modules\Noyau\Imports\Services\Executeur;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Un relevé bancaire se dépose **par compte**, et le compte ne vient pas du fichier.
 *
 * **C'est le point dont tout le reste découle.** Sur l'écran « Liste de toutes les pièces
 * comptables » du logiciel, montré le 30/09, le compte bancaire se choisit **au-dessus de la
 * grille** — AFG BANK, BGFI BANK, BNI. L'export ne porte donc pas le nom de notre banque.
 *
 * Et il porte une colonne « BANQUE EMETRICE » qui ressemble à s'y méprendre : c'est la banque
 * du chèque **reçu**. Les confondre rangerait sous la BGFI tout règlement reçu par chèque
 * BGFI, quel que soit le compte crédité — c'est-à-dire fausserait exactement la question que
 * l'écran des banques sert à répondre. Ce test les tient séparées.
 *
 * **Les huit colonnes sont celles de l'écran, pas celles d'un fichier reçu.** Le fichier n'est
 * pas encore arrivé ; `FormatDesPiecesBancaires::colonnes()` est l'endroit où les corriger
 * quand il le sera, et ce test dira aussitôt si la correction a tenu.
 */
class LeReleveBancaireSeDeposeParCompteTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------ le type est offert

    public function test_le_releve_bancaire_est_un_type_deposable(): void
    {
        $this->assertTrue(Registre::connait('banque'));
        $this->assertArrayHasKey('banque', Registre::options());
        $this->assertSame(FormatDesPiecesBancaires::class, Registre::classe('banque'));

        // Et il n'est plus annoncé comme à venir : une entrée ne peut pas être les deux.
        $this->assertArrayNotHasKey('banque', Registre::ANNONCES);
    }

    /** L'écran de dépôt le propose, et ouvre le champ du compte. */
    public function test_l_ecran_de_depot_propose_le_type_et_le_champ_du_compte(): void
    {
        $this->banque('BGFI');

        $this->actingAs($this->compte('gerant'))
            ->get(route('import.depot'))
            ->assertOk()
            // Le type s'appelle « Banque » dans la liste du dépôt — raccourci le 01/10 :
            // la liste est lue d'un coup d'œil, et « Relevé bancaire — pièces d'un compte »
            // y tenait trois fois la place des autres.
            ->assertSee('Banque', false)
            ->assertSeeHtml('id="champ-banque"')
            ->assertSee('BGFI', false);
    }

    // ------------------------------------------------------------------ le dépôt exige le compte

    /**
     * Le dépôt refuse un relevé sans compte.
     *
     * Sans compte, une pièce bancaire n'est rattachable à rien : elle gonflerait le total
     * général sans paraître sous aucune banque.
     */
    public function test_un_releve_sans_compte_est_refuse(): void
    {
        $this->banque('BGFI');

        $this->actingAs($this->compte('gerant'))
            ->post(route('import.deposer'), [
                'fichier' => UploadedFile::fake()->createWithContent('releve.xlsx', 'x'),
                'format' => 'banque',
                'ville' => (string) $this->ville->id,
            ])
            ->assertSessionHasErrors('banque');

        $this->assertSame(0, LotImport::withoutGlobalScopes()->count());
    }

    /** Et les autres types ne le demandent pas : la règle ne vaut que là où elle a un sens. */
    public function test_les_autres_types_ne_demandent_pas_de_compte(): void
    {
        $this->banque('BGFI');

        $this->actingAs($this->compte('gerant'))
            ->post(route('import.deposer'), [
                'fichier' => UploadedFile::fake()->createWithContent('impayes.xlsx', 'x'),
                'format' => 'impayes',
                'ville' => (string) $this->ville->id,
            ])
            ->assertSessionDoesntHaveErrors('banque');
    }

    // ------------------------------------------------------------------ ce que le format écrit

    /**
     * Le compte déclaré au dépôt prime sur la banque émettrice.
     *
     * C'est la règle entière en un test : le fichier dit « BNI » dans sa colonne de banque
     * émettrice, le dépôt déclare la BGFI, et la pièce appartient à la **BGFI**.
     */
    public function test_le_compte_du_depot_prime_sur_la_banque_emettrice(): void
    {
        $bgfi = $this->banque('BGFI');
        $this->banque('BNI');

        $this->importer($bgfi);

        $piece = PieceBancaire::withoutGlobalScopes()->where('code_piece', 'PC-0001')->first();

        $this->assertNotNull($piece, 'La pièce doit être écrite.');
        $this->assertSame($bgfi->id, $piece->banque_id, 'Le compte est celui déclaré au dépôt.');
        $this->assertSame('BNI', $piece->banque_emettrice, 'La banque émettrice reste celle du chèque reçu.');
        $this->assertSame(450_000, $piece->montant);
        $this->assertSame('SOCIDA', $piece->beneficiaire);
        $this->assertSame('CHQ 4455', $piece->reference_piece);
    }

    /**
     * Redéposer le même relevé met à jour au lieu de doubler.
     *
     * Le code de pièce est la clé, et il l'est **par compte** : deux banques peuvent numéroter
     * pareil, et confondre leurs pièces écraserait l'une par l'autre.
     */
    public function test_redeposer_le_meme_releve_ne_double_pas_les_pieces(): void
    {
        $bgfi = $this->banque('BGFI');

        $this->importer($bgfi);
        $this->importer($bgfi, nom: 'releve-du-lendemain.xlsx');

        $this->assertSame(2, PieceBancaire::withoutGlobalScopes()->count(),
            'Deux pièces au fichier, deux en base — et non quatre.');
    }

    /** Deux comptes peuvent numéroter pareil sans s'écraser. */
    public function test_deux_comptes_peuvent_porter_le_meme_numero_de_piece(): void
    {
        $bgfi = $this->banque('BGFI');
        $bni = $this->banque('BNI');

        $this->importer($bgfi);
        $this->importer($bni, nom: 'releve-bni.xlsx');

        $this->assertSame(2, PieceBancaire::withoutGlobalScopes()->where('code_piece', 'PC-0001')->count(),
            'Le même numéro sur deux comptes désigne deux écritures différentes.');
    }

    /**
     * Le sens reste nul quand le type de pièce ne se lit pas.
     *
     * **Et c'est le choix à défendre.** Poser « entrée » par défaut ferait des sorties des
     * entrées, et le total serait faux du double de leur montant sans que rien ne le signale.
     * Les libellés réels ne seront connus qu'au premier fichier ; d'ici là, une pièce qu'on ne
     * sait pas orienter doit se voir.
     */
    public function test_le_sens_reste_nul_tant_que_le_type_ne_se_lit_pas(): void
    {
        $this->assertSame(PieceBancaire::ENTREE, PieceBancaire::sensDuType('ENCAISSEMENT'));
        $this->assertSame(PieceBancaire::SORTIE, PieceBancaire::sensDuType('Règlement fournisseur'));
        $this->assertNull(PieceBancaire::sensDuType('PC'));
        $this->assertNull(PieceBancaire::sensDuType(''));
        $this->assertNull(PieceBancaire::sensDuType(null));

        $bgfi = $this->banque('BGFI');
        $this->importer($bgfi);

        $pieces = PieceBancaire::withoutGlobalScopes()->pluck('sens', 'code_piece');

        $this->assertSame(PieceBancaire::ENTREE, $pieces['PC-0001']);
        $this->assertSame(PieceBancaire::SORTIE, $pieces['PC-0002']);
    }

    // ------------------------------------------------------------------ le décor

    /**
     * Dépose un relevé et le fait traiter, comme le ferait l'écran d'import.
     *
     * Le lot porte `banque_id` : c'est par lui que le compte déclaré au dépôt parvient au
     * format, le fichier ne le nommant pas.
     */
    private function importer(Banque $banque, string $nom = 'releve-bancaire.xlsx'): void
    {
        $lignes = [
            ['DATE PIECE', 'CODE PIECE', 'REFERENCE PIECE', 'BANQUE EMETRICE',
                'TYPE DE PIECES', 'MODELE DE REGLEMENT', 'BENEFICIAIRES / REMETTANT', 'MONTANT PIECE'],
            ['15/09/2026', 'PC-0001', 'CHQ 4455', 'BNI',
                'ENCAISSEMENT', 'CHEQUE', 'SOCIDA', '450000'],
            ['16/09/2026', 'PC-0002', 'VIR 7788', 'BGFI',
                'REGLEMENT FOURNISSEUR', 'VIREMENT', 'CFAO MOTORS', '120000'],
        ];

        $chemin = $this->classeurXlsx(['A' => $lignes]);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'banque_id' => $banque->id,
            'deposant' => 'K. Désirée',
            'format' => 'banque',
            'nom_fichier' => $nom,
            'empreinte' => hash('sha256', $nom.$banque->id),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        (new Executeur($this->entreprise->id))->traiter($lot, FormatDesPiecesBancaires::class);
    }

    private function banque(string $nom): Banque
    {
        return Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => $nom,
            'nom_normalise' => Banque::clePour($nom),
        ]);
    }

    /** @var array<string, User> */
    private array $comptes = [];

    private function compte(string $role): User
    {
        if (isset($this->comptes[$role])) {
            return $this->comptes[$role];
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $this->comptes[$role] = $compte->fresh();
    }
}

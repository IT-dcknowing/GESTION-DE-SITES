<?php

namespace Tests\Feature;

use App\Models\User;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Formats\FormatDeLaCaisse;
use Modules\Noyau\Imports\Formats\FormatDesFactures;
use Modules\Noyau\Imports\Formats\FormatDesImpayes;
use Modules\Noyau\Imports\Formats\Resultat;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Modeles\DoublonImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Services\AnnulationDUnLot;
use Modules\Noyau\Imports\Services\DoublonsDImport;
use Modules\Noyau\Imports\Services\Rattachement;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les doublons d'import se gardent, se montrent et se tranchent — 14/10, revu le 15/10.
 *
 * Le cas réel : le fichier du CA du 12/10 porte FA -8730 deux fois. La seconde ligne réécrivait
 * la première, et le total de l'écran avait 10 000 F de moins que le fichier. Depuis le 15/10, les
 * deux lignes sont importées ; la seconde est signalée « supposée doublon à preuve du contraire »,
 * avec la raison, et l'on choisit laquelle garder (ou les deux). Redéposer ne réimporte rien.
 */
class LesDoublonsDImportSontGardesEtSIntegrentTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_au_premier_import_rien_n_est_ecarte_et_le_doublon_suppose_dit_pourquoi(): void
    {
        $fichier = [
            ['02/03/2026', 'FA -8729', 'LEADWAY', '500000,25'],
            ['03/03/2026', 'FA -8730', 'SAHAM', '10000'],
            ['03/03/2026', 'FA -8730', 'SAHAM', '10000'],
        ];

        $resultat = $this->deposerLeCa($fichier);

        $this->assertSame(3, $resultat->creees, 'Les trois lignes sont importées.');
        $this->assertSame(1, $resultat->doublons);
        $this->assertTrue($resultat->coherent());
        $this->assertSame(520000.25, montant_de(Facture::withoutGlobalScopes()->sum('montant')), 'Le total est celui du fichier.');
        $this->assertSame(['FA -8730', 'FA -8730#2'], Facture::withoutGlobalScopes()->where('n_facture', 'FA -8730')->orderBy('id')->pluck('numero')->all());

        $doublon = DoublonImport::query()->sole();
        $this->assertSame(DoublonImport::A_TRANCHER, $doublon->statut);
        $this->assertSame(10000, $doublon->montant);
        $this->assertStringContainsString('Supposé doublon à preuve du contraire', $doublon->raison);
        $this->assertStringContainsString('même n° « FA -8730 »', $doublon->raison);
        $this->assertStringContainsString('même date 03/03/2026', $doublon->raison);
        $this->assertStringContainsString('10 000 F', $doublon->raison);

        // Le même fichier redéposé : rien ne s'importe une seconde fois.
        $apres = $this->deposerLeCa($fichier);
        $this->assertSame(0, $apres->creees);
        $this->assertSame(3, Facture::withoutGlobalScopes()->count());
        $this->assertSame(1, DoublonImport::query()->count());
    }

    public function test_garder_la_premiere_retire_la_seconde_et_elle_ne_revient_pas(): void
    {
        $fichier = [['03/03/2026', 'FA -8730', 'SAHAM', '10000'], ['03/03/2026', 'FA -8730', 'SAHAM', '10000']];
        $this->deposerLeCa($fichier);

        $doublon = DoublonImport::query()->sole();
        (new DoublonsDImport($this->e->id))->trancher($doublon->id, DoublonsDImport::GARDER_LA_PREMIERE, $this->compte('gerant'));

        $this->assertSame(['FA -8730'], Facture::withoutGlobalScopes()->pluck('numero')->all());
        $this->assertSame(DoublonImport::RETIRE, $doublon->fresh()->statut);

        $apres = $this->deposerLeCa($fichier);
        $this->assertSame(0, $apres->creees);
        $this->assertSame(1, Facture::withoutGlobalScopes()->count(), 'La ligne retirée ne revient pas au dépôt suivant.');
    }

    public function test_garder_la_seconde_retire_la_premiere(): void
    {
        $fichier = [['03/03/2026', 'FA -8730', 'SAHAM', '10000'], ['03/03/2026', 'FA -8730', 'SAHAM', '10000']];
        $this->deposerLeCa($fichier);

        (new DoublonsDImport($this->e->id))->trancher(DoublonImport::query()->sole()->id, DoublonsDImport::GARDER_LA_SECONDE, $this->compte('gerant'));

        $this->assertSame(['FA -8730#2'], Facture::withoutGlobalScopes()->pluck('numero')->all());

        $this->deposerLeCa($fichier);
        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
    }

    public function test_un_doublon_se_tranche_une_fois_et_pas_par_n_importe_qui(): void
    {
        $this->deposerLeCa([['03/03/2026', 'FA -1', 'SAHAM', '10000'], ['03/03/2026', 'FA -1', 'SAHAM', '10000']]);
        $doublon = DoublonImport::query()->sole();

        try {
            (new DoublonsDImport($this->e->id))->trancher($doublon->id, DoublonsDImport::GARDER_LES_DEUX, $this->compte('responsable_site'));
            $this->fail('Le responsable d\'atelier ne décide pas.');
        } catch (RuntimeException) {
        }

        $gerant = $this->compte('gerant');
        (new DoublonsDImport($this->e->id))->trancher($doublon->id, DoublonsDImport::GARDER_LES_DEUX, $gerant);
        $this->assertSame(DoublonImport::GARDE, $doublon->fresh()->statut);
        $this->assertSame(2, Facture::withoutGlobalScopes()->count());

        $this->expectException(RuntimeException::class);
        (new DoublonsDImport($this->e->id))->trancher($doublon->id, DoublonsDImport::GARDER_LA_PREMIERE, $gerant);
    }

    public function test_deux_depenses_identiques_le_meme_jour_entrent_toutes_les_deux_et_deux_montants_proches_aussi(): void
    {
        $lot = $this->lot('caisse');
        $lecteur = $this->lecteur(['MARS 26' => [
            1 => ['CAISSE DU MOIS DE MARS'], 2 => [], 3 => [], 4 => ["SOLDE D'OUVERTURE", '', '', '', '100000'],
            5 => ['Date', 'Libellé de la transaction(objet,N°Fiche de reception,N° facture)', 'Entrées', 'sorties', 'Solde'],
            6 => ['02/03/2026', 'CARBURANT', '', '5000', '95000'],
            7 => ['02/03/2026', 'CARBURANT', '', '5000', '90000'],
            8 => ['03/03/2026', 'PAIN', '', '1000,2', '88999,8'],
            9 => ['03/03/2026', 'PAIN', '', '1000,6', '87999,2'],
        ]]);

        $resultat = $this->format(FormatDeLaCaisse::class)->parcourir($lecteur, $this->ville->id, $lot);

        $this->assertSame(4, $resultat->creees);
        $this->assertSame(1, $resultat->doublons, 'Seules les deux dépenses identiques sont supposées doublons.');
        $this->assertSame(['1000.2', '1000.6'], MouvementCaisse::withoutGlobalScopes()->where('libelle', 'PAIN')->orderBy('montant')
            ->pluck('montant')->map(fn ($m) => (string) $m)->all(), 'Deux montants à 0,40 F d\'écart ne se fondent plus.');

        $doublon = DoublonImport::query()->sole();
        $this->assertSame(-5000, $doublon->montant, 'Une sortie se montre en négatif.');
        $this->assertSame('MARS 26', $doublon->feuille);

        (new DoublonsDImport($this->e->id))->trancher($doublon->id, DoublonsDImport::GARDER_LES_DEUX, $this->compte('gerant'));
        $this->assertSame(2, MouvementCaisse::withoutGlobalScopes()->where('libelle', 'CARBURANT')->count());
    }

    public function test_une_ligne_utilisee_ailleurs_ne_se_retire_pas(): void
    {
        $lecteur = $this->lecteur(['MARS 26' => [
            1 => ['CAISSE'], 2 => [], 3 => [], 4 => [],
            5 => ['Date', 'Libellé de la transaction(objet,N°Fiche de reception,N° facture)', 'Entrées', 'sorties', 'Solde'],
            6 => ['02/03/2026', 'CARBURANT', '', '5000', '95000'],
            7 => ['02/03/2026', 'CARBURANT', '', '5000', '90000'],
        ]]);
        $this->format(FormatDeLaCaisse::class)->parcourir($lecteur, $this->ville->id, $this->lot('caisse'));

        $doublon = DoublonImport::query()->sole();
        \Modules\Noyau\Exploitation\Modeles\Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'mouvement_caisse_id' => $doublon->integre_id,
            'date' => '2026-03-02', 'type_operation' => 'Charges', 'libelle' => 'Fonctionnement', 'moyen' => 'Espèces', 'montant' => 5000,
        ]);

        try {
            (new DoublonsDImport($this->e->id))->trancher($doublon->id, DoublonsDImport::GARDER_LA_PREMIERE, $this->compte('gerant'));
            $this->fail('Un mouvement repris en charge ne se retire pas.');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('repris en charge', $refus->getMessage());
        }

        $this->assertSame(2, MouvementCaisse::withoutGlobalScopes()->count());
        $this->assertSame(DoublonImport::A_TRANCHER, $doublon->fresh()->statut);
    }

    public function test_une_ligne_repetee_de_l_etat_des_impayes_est_importee_et_signalee(): void
    {
        $colonnes = FormatDesImpayes::colonnes();

        $lecteur = $this->lecteur(['ETAT' => [
            1 => array_values(array_map(fn ($c) => $colonnes[$c], ['numero', 'date', 'client', 'immatriculation', 'montant'])),
            2 => ['17', '02/03/2026', 'KONE', 'AB-123-CD', '300000'],
            3 => ['17', '02/03/2026', 'KONE', 'AB-123-CD', '300000'],
        ]]);

        $resultat = $this->format(FormatDesImpayes::class)->parcourir($lecteur, $this->ville->id, $this->lot('impayes'));

        $this->assertSame(2, $resultat->creees);
        $this->assertSame(1, $resultat->doublons);
        $this->assertSame(2, Facture::withoutGlobalScopes()->count());
    }

    public function test_annuler_le_depot_retire_les_doublons_en_attente(): void
    {
        $lot = $this->lot('factures');
        $this->deposerLeCa([['03/03/2026', 'FA -1', 'X', '10000'], ['03/03/2026', 'FA -1', 'X', '10000']], $lot);
        $this->assertSame(1, DoublonImport::query()->count());

        (new AnnulationDUnLot($this->e->id))->annuler(LotImport::findOrFail($lot), 'erreur de fichier', $this->compte('gerant')->id);

        $this->assertSame(0, DoublonImport::query()->count());
        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
    }

    public function test_la_page_du_ca_montre_les_doublons_supposes_et_le_choix(): void
    {
        $this->deposerLeCa([
            [now()->format('d/m/Y'), 'FA -8730', 'SAHAM', '10000'],
            [now()->format('d/m/Y'), 'FA -8730', 'SAHAM', '10000'],
        ]);

        $gerant = $this->compte('gerant');
        Volt::actingAs($gerant)->test('pilotage.chiffre-affaires')->assertSeeLivewire('commun.doublons-d-import');

        $doublon = DoublonImport::query()->sole();
        Volt::actingAs($gerant)->test('commun.doublons-d-import', [
            'formats' => ['factures'], 'idsSites' => [$this->site->id], 'idsVilles' => [$this->ville->id],
            'debut' => now()->startOfYear()->toDateString(), 'fin' => now()->toDateString(), 'retenu' => 20000, 'libelleRetenu' => 'CA importé',
        ])
            ->assertSee('Montant sans les doublons supposés')
            ->assertSee('10 000 F')
            ->call('basculer')
            ->assertSee('Garder la seconde')
            ->assertSee('Supposé doublon à preuve du contraire')
            ->assertSee('Garder la première')
            ->call('trancher', $doublon->id, DoublonsDImport::GARDER_LA_SECONDE)
            ->assertDispatched('doublon-integre');

        $this->assertSame(10000, montant_de(Facture::withoutGlobalScopes()->sum('montant')));
    }

    // ------------------------------------------------------------------ outils

    private function deposerLeCa(array $lignes, ?int $lot = null): Resultat
    {
        $entete = array_map(fn ($c) => FormatDesFactures::colonnes()[$c], ['date', 'numero', 'client', 'montant']);

        $feuille = [1 => $entete];
        foreach ($lignes as $i => $ligne) {
            $feuille[$i + 2] = $ligne;
        }

        return $this->format(FormatDesFactures::class)->parcourir($this->lecteur(['CA' => $feuille]), $this->ville->id, $lot ?? $this->lot('factures'));
    }

    private function format(string $classe)
    {
        return new $classe($this->e->id, new Rattachement($this->e->id));
    }

    /** @param  array<string, array<int, list<string>>>  $feuilles */
    private function lecteur(array $feuilles): Lecteur
    {
        return new class($feuilles) implements Lecteur
        {
            public function __construct(private array $feuilles) {}

            public function feuilles(): array
            {
                return array_keys($this->feuilles);
            }

            public function lignes(?string $feuille = null): Generator
            {
                yield from $this->feuilles[$feuille ?? array_key_first($this->feuilles)] ?? [];
            }

            public function fermer(): void {}
        };
    }

    private int $numeroDeLot = 0;

    private function lot(string $format): int
    {
        $n = ++$this->numeroDeLot;

        return LotImport::create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'deposant' => 'Reprise',
            'format' => $format, 'nom_fichier' => "$format-$n.xlsx", 'empreinte' => str_pad(dechex($n), 64, '0', STR_PAD_LEFT), 'etat' => 'termine',
        ])->id;
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Awa Koné', 'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id, 'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

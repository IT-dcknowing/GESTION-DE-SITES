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
 * Les doublons d'import se gardent, se montrent et s'intègrent — 14/10.
 *
 * Le cas réel : le fichier du CA du 12/10 porte FA -8730 deux fois. La seconde ligne réécrivait
 * la première, et le total de l'écran avait 10 000 F de moins que le fichier. Désormais la ligne
 * répétée est gardée à part, l'écran dit « importé » et « retenu », et « Intégrer » la compte.
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

    public function test_une_facture_repetee_dans_le_fichier_du_ca_est_gardee_a_part_puis_integree(): void
    {
        $fichier = [
            ['02/03/2026', 'FA -8729', 'LEADWAY', '500000,25'],
            ['03/03/2026', 'FA -8730', 'SAHAM', '10000'],
            ['03/03/2026', 'FA -8730', 'SAHAM', '10000'],
        ];

        $resultat = $this->deposerLeCa($fichier);

        $this->assertSame(2, $resultat->creees);
        $this->assertSame(1, $resultat->doublons);
        $this->assertTrue($resultat->coherent(), 'lues = créées + maj + ignorées + rejetées + doublons');
        $this->assertSame(510000.25, montant_de(Facture::withoutGlobalScopes()->sum('montant')), 'Le doublon ne compte pas tant qu\'on ne l\'a pas intégré.');

        $doublon = DoublonImport::query()->sole();
        $this->assertSame('factures', $doublon->format);
        $this->assertSame('FA -8730', $doublon->reference);
        $this->assertSame(10000, $doublon->montant);
        $this->assertSame(Facture::withoutGlobalScopes()->where('n_facture', 'FA -8730')->value('id'), (int) $doublon->retenue_id);

        // Le même fichier redéposé : rien ne se double, ni les factures, ni le doublon.
        $this->deposerLeCa($fichier);
        $this->assertSame(2, Facture::withoutGlobalScopes()->count());
        $this->assertSame(1, DoublonImport::query()->count());

        // « Ce n'était pas un doublon » : la ligne compte, sous un numéro interne distinct.
        (new DoublonsDImport($this->e->id))->integrer($doublon->id, $this->compte('gerant'));

        $this->assertSame(520000.25, montant_de(Facture::withoutGlobalScopes()->sum('montant')), 'Le total est celui du fichier.');
        $this->assertSame(['FA -8730', 'FA -8730#2'], Facture::withoutGlobalScopes()->where('n_facture', 'FA -8730')->orderBy('id')->pluck('numero')->all());
        $this->assertSame(DoublonImport::INTEGRE, $doublon->fresh()->statut);

        // Redéposé après l'intégration : chaque ligne retrouve la sienne.
        $apres = $this->deposerLeCa($fichier);
        $this->assertSame(0, $apres->doublons);
        $this->assertSame(0, $apres->creees);
        $this->assertSame(3, Facture::withoutGlobalScopes()->count());
    }

    public function test_un_doublon_ne_s_integre_qu_une_fois_et_pas_par_n_importe_qui(): void
    {
        $this->deposerLeCa([
            ['03/03/2026', 'FA -1', 'SAHAM', '10000'],
            ['03/03/2026', 'FA -1', 'SAHAM', '10000'],
        ]);
        $doublon = DoublonImport::query()->sole();

        try {
            (new DoublonsDImport($this->e->id))->integrer($doublon->id, $this->compte('responsable_site'));
            $this->fail('Le responsable d\'atelier ne décide pas.');
        } catch (RuntimeException) {
        }

        $gerant = $this->compte('gerant');
        (new DoublonsDImport($this->e->id))->integrer($doublon->id, $gerant);

        $this->expectException(RuntimeException::class);
        (new DoublonsDImport($this->e->id))->integrer($doublon->id, $gerant);
    }

    public function test_deux_depenses_identiques_le_meme_jour_ne_se_fondent_plus_et_deux_montants_proches_non_plus(): void
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

        $this->assertSame(3, $resultat->creees);
        $this->assertSame(1, $resultat->doublons, 'La seconde dépense de 5 000 F est gardée à part, pas réécrite sur la première.');
        $this->assertSame(['1000.2', '1000.6'], MouvementCaisse::withoutGlobalScopes()->where('libelle', 'PAIN')->orderBy('montant')
            ->pluck('montant')->map(fn ($m) => (string) $m)->all(), 'Deux montants à 0,40 F d\'écart ne se fondent plus.');

        $doublon = DoublonImport::query()->sole();
        $this->assertSame(-5000, $doublon->montant, 'Une sortie se montre en négatif.');
        $this->assertSame('MARS 26', $doublon->feuille);

        (new DoublonsDImport($this->e->id))->integrer($doublon->id, $this->compte('gerant'));
        $this->assertSame(2, MouvementCaisse::withoutGlobalScopes()->where('libelle', 'CARBURANT')->count());
        $this->assertSame('MARS 26', MouvementCaisse::withoutGlobalScopes()->where('libelle', 'CARBURANT')->latest('id')->value('feuille'));
    }

    public function test_une_ligne_repetee_de_l_etat_des_impayes_est_gardee_a_part(): void
    {
        $colonnes = FormatDesImpayes::colonnes();

        $lecteur = $this->lecteur(['ETAT' => [
            1 => array_values(array_map(fn ($c) => $colonnes[$c], ['numero', 'date', 'client', 'immatriculation', 'montant'])),
            2 => ['17', '02/03/2026', 'KONE', 'AB-123-CD', '300000'],
            3 => ['17', '02/03/2026', 'KONE', 'AB-123-CD', '300000'],
        ]]);

        $resultat = $this->format(FormatDesImpayes::class)->parcourir($lecteur, $this->ville->id, $this->lot('impayes'));

        $this->assertSame(1, $resultat->creees);
        $this->assertSame(1, $resultat->doublons);
        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
    }

    public function test_annuler_le_depot_retire_les_doublons_en_attente(): void
    {
        $lot = $this->lot('factures');
        $this->deposerLeCa([['03/03/2026', 'FA -1', 'X', '10000'], ['03/03/2026', 'FA -1', 'X', '10000']], $lot);
        $this->assertSame(1, DoublonImport::query()->count());

        (new AnnulationDUnLot($this->e->id))->annuler(LotImport::findOrFail($lot), 'erreur de fichier', $this->compte('gerant')->id);

        $this->assertSame(0, DoublonImport::query()->count());
    }

    public function test_la_page_du_ca_dit_l_importe_le_retenu_et_montre_le_doublon_a_integrer(): void
    {
        $this->deposerLeCa([
            [now()->format('d/m/Y'), 'FA -8730', 'SAHAM', '10000'],
            [now()->format('d/m/Y'), 'FA -8730', 'SAHAM', '10000'],
        ]);

        $gerant = $this->compte('gerant');
        // La page pose le composant ; il se rend à part (un test Livewire ne rend pas les enfants).
        Volt::actingAs($gerant)->test('pilotage.chiffre-affaires')->assertSeeLivewire('commun.doublons-d-import');

        $doublon = DoublonImport::query()->sole();
        Volt::actingAs($gerant)->test('commun.doublons-d-import', [
            'formats' => ['factures'], 'idsSites' => [$this->site->id], 'idsVilles' => [$this->ville->id],
            'debut' => now()->startOfYear()->toDateString(), 'fin' => now()->toDateString(), 'retenu' => 10000, 'libelleRetenu' => 'CA retenu',
        ])
            ->assertSee('Importé — toutes les lignes du fichier')
            ->assertSee('20 000 F')
            ->call('basculer')
            ->assertSee('FA -8730')
            ->assertSee('Intégrer')
            ->call('integrer', $doublon->id)
            ->assertDispatched('doublon-integre');

        $this->assertSame(20000, montant_de(Facture::withoutGlobalScopes()->sum('montant')));
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

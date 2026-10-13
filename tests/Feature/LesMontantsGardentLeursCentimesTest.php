<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Montant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Exploitation\Services\ReglementGlobal;
use Modules\Noyau\Imports\Formats\FormatDesFactures;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ne jamais arrondir un montant — 13/10.
 *
 * « Ne jamais arrondir des montants ; récupère tel que donné. » Le fichier du CA porte des
 * centimes (FA -5725 : 2 137 270,28) : l'import les arrondissait au franc, et le total de l'écran
 * s'écartait de celui du classeur de 101 F. Les colonnes d'argent sont des décimaux, un montant
 * entier reste un entier, et rien ne coupe plus les centimes entre le fichier et l'écran.
 */
class LesMontantsGardentLeursCentimesTest extends TestCase
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

    public function test_la_lecture_d_un_montant_ne_coupe_jamais_les_centimes(): void
    {
        // MySQL rend un décimal — et la somme d'un décimal — sous forme de chaîne.
        $this->assertSame(1867767452.25, Montant::de('1867767452.2500'));
        $this->assertSame(300000, Montant::de('300000.0000'));
        $this->assertSame(300000, Montant::de(300000));
        $this->assertSame(0.3, Montant::de(0.1 + 0.2), 'Le bruit de la virgule flottante est écarté.');
        $this->assertSame(0, Montant::de(null));
        $this->assertSame(-59000.5, Montant::de('-59000.5000'));

        $this->assertSame('2 137 270,28 F', ae(2137270.28));
        $this->assertSame('300 000 F', ae('300000.0000'));
        $this->assertSame('0,50 F', ae(0.5), 'Au moins deux décimales dès qu\'il y en a.');
        $this->assertSame('82 096,6271 F', ae(82096.6271), 'Jusqu\'à quatre, sans zéro inutile.');
    }

    public function test_le_ca_importe_garde_ses_centimes_et_le_total_est_celui_du_classeur(): void
    {
        $this->cattc(['numero' => 'FA -5725', 'date' => '2026-03-02', 'client' => 'LEADWAY', 'montant' => '2137270,28']);
        $this->cattc(['numero' => 'FA -5726', 'date' => '2026-03-02', 'client' => 'CIE', 'montant' => '1000000.5']);

        $this->assertSame(2137270.28, Facture::withoutGlobalScopes()->where('n_facture', 'FA -5725')->value('montant') + 0.0);
        $this->assertSame(2137270.28, Facture::withoutGlobalScopes()->where('n_facture', 'FA -5725')->first()->montant);
        $this->assertSame(3137270.78, Montant::de(Facture::withoutGlobalScopes()->sum('montant')), 'Le total est celui du classeur, au centime.');
    }

    public function test_un_depot_apres_l_ancien_arrondi_met_a_jour_la_ligne_sans_la_doubler(): void
    {
        // Une ligne écrite avant le 13/10 : arrondie au franc.
        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'numero' => 'F-1', 'n_facture' => 'FA -5725', 'date' => '2026-03-02',
            'client' => 'LEADWAY', 'activite' => 'Sinistre', 'montant' => 2137270,
        ]);

        $this->cattc(['numero' => 'FA -5725', 'date' => '2026-03-02', 'client' => 'LEADWAY', 'montant' => '2137270,28']);

        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
        $this->assertSame(2137270.28, Facture::withoutGlobalScopes()->first()->montant);
    }

    public function test_une_piece_fournisseur_arrondie_avant_est_retrouvee_puis_mise_a_jour(): void
    {
        $ancienne = FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'fournisseur' => 'RIMCO', 'numero_piece' => 'SP-1',
            'date_facture' => '2026-01-03', 'montant' => 538189, 'montant_regle' => 0, 'reste_a_payer' => 538189,
        ]);

        $this->fournisseurs(['fournisseur' => 'RIMCO', 'numero_piece' => 'SP-1', 'date_facture' => '2026-01-03',
            'montant' => '538188,60', 'montant_regle' => '0', 'reste_a_payer' => '538188,60', 'montant_ht' => '456091.1016949']);

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count(), 'Retrouvée à un demi-franc près, pas doublée.');
        $piece = $ancienne->fresh();
        $this->assertSame(538188.6, $piece->montant);
        $this->assertSame(456091.1017, $piece->montant_ht, 'Quatre décimales conservées.');
    }

    public function test_le_reste_a_encaisser_garde_les_centimes(): void
    {
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'numero' => 'F-1', 'n_facture' => 'FA -1', 'date' => '2026-03-02',
            'client' => 'X', 'activite' => 'Mécanique', 'montant' => 2137270.28,
        ]);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'facture_id' => $facture->id, 'date' => '2026-03-03', 'type' => 'Client', 'moyen' => 'Chèque', 'montant' => 1000000.1,
        ]);

        $facture = Facture::withoutGlobalScopes()->withSum('encaissements', 'montant')->find($facture->id);

        $this->assertSame(1137270.18, Recouvrement::reste($facture));
        $this->assertSame(1137270.18, $facture->resteAEncaisser());
    }

    public function test_un_versement_global_repartit_les_centimes_sans_les_couper(): void
    {
        $a = Facture::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'numero' => 'F-1', 'n_facture' => 'A', 'date' => '2026-03-01', 'client' => 'X', 'activite' => 'Mécanique', 'montant' => 100.25]);
        $b = Facture::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'numero' => 'F-2', 'n_facture' => 'B', 'date' => '2026-03-02', 'client' => 'X', 'activite' => 'Mécanique', 'montant' => 200.5]);

        $r = ReglementGlobal::repartir(collect([$a, $b]), 300.75);

        $this->assertSame(100.25, $r['parts'][0]['part']);
        $this->assertSame(200.5, $r['parts'][1]['part']);
        $this->assertSame(0, $r['reste_du_versement']);
        $this->assertSame(300.75, $r['total_du']);
    }

    public function test_la_synthese_et_son_total_montrent_les_centimes(): void
    {
        $jour = now()->startOfMonth()->addDays(2)->toDateString();
        foreach ([['A', 100.25], ['B', 200.5]] as [$n, $m]) {
            Facture::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => "F-$n", 'n_facture' => $n,
                'date' => $jour, 'client' => 'X', 'activite' => 'Mécanique', 'montant' => $m]);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $g = User::create(['entreprise_id' => $this->e->id, 'name' => 'G', 'email' => 'g@a.t', 'password' => Hash::make('x'),
            'ville_id' => $this->ville->id, 'site_id' => $this->site->id, 'est_actif' => true]);
        $g->assignRole('gerant');

        $ecran = Volt::actingAs($g->fresh())->test('gerant.tableau-de-bord');

        $this->assertSame(300.75, $ecran->instance()->kpis['ca']);
        $ecran->assertSee('300,75 F');
    }

    public function test_un_montant_qui_s_ecrit_autrement_n_est_pas_un_changement(): void
    {
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'numero' => 'F-1', 'n_facture' => 'FA -1', 'date' => '2026-03-02',
            'client' => 'X', 'activite' => 'Mécanique', 'montant' => 300000,
        ]);

        // MySQL rend « 300000.0000 » ; le fichier donne 300000 : même montant, pas une mise à jour.
        $facture->setRawAttributes(['montant' => '300000.0000'] + $facture->getAttributes(), true);
        $facture->fill(['montant' => 300000]);

        $this->assertFalse($facture->isDirty('montant'));

        $facture->fill(['montant' => 300000.25]);
        $this->assertTrue($facture->isDirty('montant'));
    }

    private function cattc(array $ligne): void
    {
        $this->format(FormatDesFactures::class)->ecrireLaLigne($ligne, $this->rattachement(), $this->lot('factures'));
    }

    private function fournisseurs(array $ligne): void
    {
        $this->format(FormatDesFournisseurs::class)->ecrireLaLigne($ligne, $this->rattachement(), $this->lot('fournisseurs'));
    }

    private function rattachement(): array
    {
        return ['ville_id' => $this->ville->id, 'site_id' => null, 'code' => null, 'source' => 'colonne', 'presumee' => false];
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

    private function format(string $classe)
    {
        return eval('return new class($this->e->id, new '.Rattachement::class.'($this->e->id)) extends '.$classe.' {
            public function ecrireLaLigne(array $ligne, array $rattachement, int $lot): void { $this->ecrire($ligne, $rattachement, $lot); }
        };');
    }
}

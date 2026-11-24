<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Rapport\Services\RapportFinancier;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'onglet Commerciaux du rapport récapitule l'activité — 24/11.
 *
 * « Le but est un récapitulatif de l'activité des commerciaux : les prospections, celles passées en
 * devis, toutes celles saisies ; le nombre de prospections, en devis, en attente, les factures. »
 *
 * Le décor, sur l'exercice 2026 :
 *
 * - Koffi : six prospections saisies dans l'exercice (un brouillon, une refusée, quatre transmises ou
 *   validées) et une de 2025 qui ne compte pas ; deux passées en devis, dont une sans devis relié.
 *   Cinq devis dans l'exercice (un sans atelier), dont un suivi d'une facture par sa fiche, un
 *   validé, deux en attente, un refusé — et un de 2025. Deux factures, dont une à centimes.
 * - Awa : une prospection passée en devis sans devis, un devis en attente.
 * - Le « Client spontané » n'est pas un commercial : il n'a pas de ligne.
 */
class LeRapportRecapituleLActiviteDesCommerciauxTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Site $site;

    private User $gerant;

    private Commercial $koffi;

    private Commercial $awa;

    private int $rang = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->gerant = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $ville->id, 'site_id' => $this->site->id, 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();
        $this->actingAs($this->gerant);

        $commercial = fn (string $numero, string $nom, bool $spontane = false) => Commercial::create([
            'entreprise_id' => $this->e->id, 'ville_id' => $ville->id, 'numero' => $numero, 'nom' => $nom,
            'objectif_mensuel' => 1_000_000, 'statut' => 'Actif', 'est_spontane' => $spontane,
        ]);
        $this->koffi = $commercial('C-001', 'Koffi Yao');
        $this->awa = $commercial('C-002', 'Awa Koné');
        $spontane = $commercial('C-003', 'Client spontané', true);

        // Koffi — les prospections.
        $this->prospection($this->koffi, '2026-02-03', 'Brouillon');
        $this->prospection($this->koffi, '2026-02-04', 'Refusée');
        $enDevis = $this->prospection($this->koffi, '2026-03-02', 'Validée', true);
        $this->prospection($this->koffi, '2026-03-10', 'Transmise', true, 'PR-AB-9999');
        $this->prospection($this->koffi, '2026-04-01', 'Validée');
        $this->prospection($this->koffi, '2026-05-01', 'Validée');
        $this->prospection($this->koffi, '2025-12-30', 'Validée', true);

        // Koffi — les devis.
        $this->devis($this->koffi, '2026-03-05', 'En attente', 'F-100', $enDevis->id);
        $this->devis($this->koffi, '2026-03-12', 'Validé');
        $this->devis($this->koffi, '2026-04-02', 'En attente');
        $this->devis($this->koffi, '2026-04-03', 'Refusé');
        $this->devis($this->koffi, '2026-06-01', 'En attente', null, null, sansAtelier: true);
        $this->devis($this->koffi, '2025-12-31', 'En attente');

        // Koffi — les factures ; la première suit le devis F-100 par sa fiche de réception.
        $this->facture($this->koffi, '2026-03-20', 1_500_000.5, 'F-100');
        $this->facture($this->koffi, '2026-04-15', 1_000_000);

        // Awa.
        $this->prospection($this->awa, '2026-06-10', 'Transmise', true);
        $this->devis($this->awa, '2026-06-12', 'En attente');

        // Le client spontané : ses lignes existent, mais il n'est pas un commercial à récapituler.
        $this->prospection($spontane, '2026-06-10', 'Validée', true);
    }

    public function test_chaque_commercial_a_ses_prospections_ses_devis_et_ses_factures(): void
    {
        $lignes = collect(RapportFinancier::pour($this->gerant, 2026)->commerciaux())->keyBy('commercial');

        $this->assertSame(['Koffi Yao', 'Awa Koné'], $lignes->keys()->all(), 'Le client spontané n\'a pas de ligne.');

        $k = $lignes['Koffi Yao'];
        $this->assertSame(6, $k['prospectionsSaisies'], 'Brouillon et refusée sont des saisies ; 2025 ne compte pas.');
        $this->assertSame(4, $k['prospections'], 'Transmises ou validées, comme la page Prospects.');
        $this->assertSame(2, $k['passeesEnDevis']);
        $this->assertSame(1, $k['attenteDuDevis'], 'Celle dont le devis est relié n\'attend plus.');
        $this->assertEqualsWithDelta(0.5, $k['tauxProspectionDevis'], 1e-9);

        $this->assertSame(5, $k['devisEmis'], 'Le devis sans atelier compte (piège 1) ; celui de 2025 non.');
        $this->assertSame(2, $k['devisTransformes'], 'Le validé et celui suivi d\'une facture.');
        $this->assertSame(1, $k['devisFactures']);
        $this->assertSame(2, $k['devisEnAttente'], 'Le devis « En attente » suivi d\'une facture n\'attend plus.');
        $this->assertEqualsWithDelta(0.4, $k['tauxDevisFacture'], 1e-9);

        $this->assertSame(2, $k['factures']);
        $this->assertSame(2_500_000.5, $k['realisation'], 'Le CA garde ses centimes.');

        $a = $lignes['Awa Koné'];
        $this->assertSame([1, 1, 1, 1, 1, 1, 0], [$a['prospectionsSaisies'], $a['prospections'], $a['passeesEnDevis'], $a['attenteDuDevis'], $a['devisEmis'], $a['devisEnAttente'], $a['devisTransformes']]);
        $this->assertEqualsWithDelta(0.0, $a['tauxDevisFacture'], 1e-9);
    }

    public function test_le_total_additionne_les_comptes_et_recalcule_les_taux(): void
    {
        $t = RapportFinancier::pour($this->gerant, 2026)->totalDesCommerciaux();

        $this->assertSame(7, $t['prospectionsSaisies']);
        $this->assertSame(5, $t['prospections']);
        $this->assertSame(3, $t['passeesEnDevis']);
        $this->assertSame(2, $t['attenteDuDevis']);
        $this->assertEqualsWithDelta(0.6, $t['tauxProspectionDevis'], 1e-9, '3 ÷ 5, et non la moyenne de 50 % et 100 %.');
        $this->assertSame(6, $t['devisEmis']);
        $this->assertSame(3, $t['devisEnAttente']);
        $this->assertSame(2, $t['devisTransformes']);
        $this->assertEqualsWithDelta(2 / 6, $t['tauxDevisFacture'], 1e-9);
        $this->assertSame(2, $t['factures']);
        $this->assertSame(2_500_000.5, $t['realisation']);
    }

    public function test_l_onglet_affiche_les_cartes_le_tableau_et_les_definitions(): void
    {
        HabilitationAction::query()->create(['domaine' => 'rapport', 'action' => 'voir', 'role' => null, 'user_id' => $this->gerant->id, 'accorde' => true]);
        HabilitationsDActions::oublier();

        Volt::actingAs($this->gerant)->test('pilotage.rapport')->set('exercice', 2026)->call('aller', 'commerciaux')
            ->assertOk()
            ->assertSee('Prospections saisies')
            ->assertSee('Passées en devis')
            ->assertSee('En attente du devis')
            ->assertSee('Taux devis → facture')
            ->assertSee('Koffi Yao')
            ->assertSee('Réalisé, objectif et encours par commercial')
            ->assertSee('brouillons et refusées compris');
    }

    private function prospection(Commercial $c, string $date, string $statut, bool $enDevis = false, ?string $nDevis = null): Prospection
    {
        return Prospection::create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'commercial_id' => $c->id,
            'numero' => 'P-'.(++$this->rang), 'date' => $date, 'client' => 'Client '.$this->rang, 'moyen' => 'RDV',
            'activite' => 'Mécanique', 'passage' => $enDevis, 'date_passage' => $enDevis ? $date : null,
            'devis_apres_passage' => $enDevis, 'date_devis' => $enDevis ? $date : null, 'n_devis' => $nDevis,
            'statut_validation' => $statut,
        ]);
    }

    private function devis(Commercial $c, string $date, string $statut, ?string $fiche = null, ?int $prospection = null, bool $sansAtelier = false): Devis
    {
        $devis = Devis::create([
            'entreprise_id' => $this->e->id, 'site_id' => $sansAtelier ? null : $this->site->id, 'commercial_id' => $c->id,
            'numero' => 'D-'.(++$this->rang), 'n_fiche_reception' => $fiche, 'date_emission' => $date,
            'client' => 'Client du devis', 'activite' => 'Mécanique', 'statut' => $statut, 'montant_devis' => 800_000,
        ]);

        if ($prospection !== null) {
            $devis->forceFill(['prospection_id' => $prospection])->save();
        }

        return $devis;
    }

    private function facture(Commercial $c, string $date, int|float $montant, ?string $fiche = null): Facture
    {
        $facture = Facture::create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'FAC-'.(++$this->rang),
            'n_facture' => 'F-'.$this->rang, 'date' => $date, 'client' => 'Client facturé', 'montant' => $montant,
            'activite' => 'Mécanique', 'reference_devis' => $fiche,
        ]);
        $facture->forceFill(['commercial_id' => $c->id])->save();

        return $facture;
    }
}

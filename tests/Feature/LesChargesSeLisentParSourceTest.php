<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Services\CategorieDeSortie;
use Modules\Noyau\Exploitation\Services\ChargesParSource;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Services\AchatsFne;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les retours du 22/10 sur les charges, les fournisseurs et la FNE.
 *
 * Le décor : une charge saisie (10 000) ; deux sorties de caisse sans atelier — une avance sur
 * salaire (20 000, administrative) et un achat de filtres (5 000, fournisseur) ; une pièce du suivi
 * (118 000) ; deux factures FNE, l'une rapprochée de la pièce par son numéro (118 000), l'autre pas
 * encore au suivi (59 000).
 */
class LesChargesSeLisentParSourceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        $this->gerant = $this->compte();

        Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'date' => '2026-03-05',
            'type_operation' => 'Charges', 'libelle' => 'Fonctionnement', 'moyen' => 'Espèces', 'montant' => 10_000,
        ]);

        foreach ([['AVANCE SALAIRE MARS', 20_000], ['ACHAT FILTRES A HUILE', 5_000]] as $i => [$libelle, $montant]) {
            MouvementCaisse::withoutGlobalScopes()->create([
                'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'site_id' => null,
                'date' => '2026-03-0'.($i + 6), 'sens' => MouvementCaisse::SORTIE, 'libelle' => $libelle,
                'montant' => $montant, 'numero_piece' => 'PC-'.$i,
            ]);
        }

        FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id,
            'fournisseur' => 'Ets Kouassi pièces', 'numero_piece' => 'BL-77', 'date_facture' => '2026-03-11',
            'montant' => 118_000, 'montant_regle' => 0, 'reste_a_payer' => 118_000,
            'observations' => 'Facture normalisée 2626A0000002 reçue par mail',
        ]);

        AchatsFne::importer($this->releve(), 'achats.json', $this->e->id);
    }

    /** « Les mécaniques, les sinistres, l'achat de pièces, tout ce qui remonte aux travaux : charges fournisseurs. » */
    public function test_le_libelle_partage_la_caisse_entre_administratif_et_fournisseurs(): void
    {
        $volet = fn (string $libelle) => CategorieDeSortie::volet(CategorieDeSortie::de($libelle));

        $this->assertSame(CategorieDeSortie::FOURNISSEURS, $volet('ACHAT FILTRES A HUILE'));
        $this->assertSame(CategorieDeSortie::FOURNISSEURS, $volet('TRAVAUX SINISTRE AA 1234'));
        $this->assertSame(CategorieDeSortie::FOURNISSEURS, $volet('MECANIQUE TOYOTA HILUX'));
        $this->assertSame(CategorieDeSortie::ADMINISTRATIF, $volet('AVANCE SALAIRE MARS'));
        $this->assertSame(CategorieDeSortie::ADMINISTRATIF, $volet('TRANSPORT LIVREUR'));
        // Ce que la règle ne sait pas lire ne se prête pas à un fournisseur.
        $this->assertSame(CategorieDeSortie::ADMINISTRATIF, $volet('XYZ'));
    }

    public function test_chaque_source_se_lit_telle_qu_elle_est_sans_s_additionner(): void
    {
        $src = ChargesParSource::pourLesSites($this->e->id, [$this->site->id], '2026-01-01', '2026-12-31')->tout();

        // Depuis le 26/10, la caisse ne compte que ses sorties administratives : l'achat de filtres
        // se montre à côté, le suivi fournisseurs porte les achats.
        $this->assertSame(20_000, $src['caisse']['total']);
        $this->assertSame(20_000, $src['caisse']['administratif']);
        $this->assertSame(5_000, $src['caisse']['fournisseurs']);
        $this->assertSame(10_000, $src['saisies']['total']);
        // Ce qui s'additionne : la caisse administrative, les saisies et le suivi fournisseurs.
        $this->assertSame(148_000, $src['retenu']);
        $this->assertSame(118_000, $src['suivi']['total']);
        // Toutes les FNE de la période, au suivi ou non : rien n'est retiré sans lettrage.
        $this->assertSame(177_000, $src['fne']['total']);
        $this->assertSame(2, $src['fne']['n']);
        $this->assertSame(0, $src['banque']['total']);
    }

    public function test_un_filtre_d_activite_ne_laisse_que_les_saisies_qui_la_portent(): void
    {
        $src = ChargesParSource::pourLesSites($this->e->id, [$this->site->id], '2026-01-01', '2026-12-31', 'Mécanique')->tout();

        $this->assertSame(0, $src['caisse']['total']);
        $this->assertSame(0, $src['fne']['total']);
        $this->assertSame(0, $src['suivi']['total']);
    }

    public function test_la_page_charges_et_l_accueil_du_gerant_montrent_les_sources(): void
    {
        Volt::actingAs($this->gerant)->test('pilotage.charges')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31')
            ->assertSee('Charges selon la caisse')
            ->assertSee('Charges administratives (1)')
            ->assertSee('Charges fournisseurs (1) — non comptées')
            ->assertSee('Factures FNE')
            ->assertSee('Banque — débits des relevés');

        $accueil = Volt::actingAs($this->gerant)->test('gerant.tableau-de-bord')
            ->set('dateDebut', '2026-01-01')->set('dateFin', '2026-12-31')
            ->assertSee('Charges — synthèse par source');

        $this->assertSame(148_000, $accueil->instance()->chargesParSource['retenu']);
        // Le KPI « Charges » du haut dit la même chose que la synthèse — 26/10.
        $this->assertSame(148_000, $accueil->instance()->kpis['charges']);
    }

    /** « Factures reçues » et « À qui nous devons le plus » : un cadre, deux boutons — 22/10. */
    public function test_la_page_fournisseurs_montre_toutes_les_factures_dans_un_seul_tableau(): void
    {
        $ecran = Volt::actingAs($this->gerant)->test('pilotage.fournisseurs')->set('exercice', 2026);

        // La pièce du suivi, et la FNE que personne n'a portée : deux lignes, la FNE liée ne double pas.
        $this->assertSame(2, $ecran->instance()->detail->total());
        $ecran->assertSee('Ets Kouassi pièces')->assertSee('2626A0000003')->assertSee('FNE — pas encore au suivi');

        $ecran->set('provenanceFiltre', 'suivi');
        $this->assertSame(1, $ecran->instance()->detail->total());

        $ecran->set('provenanceFiltre', 'fne');
        $this->assertSame(1, $ecran->instance()->detail->total());

        // Un filtre d'état ne lit que le suivi : la FNE ne dit pas ce qui en a été réglé.
        $ecran->set('provenanceFiltre', 'toutes')->set('etatFiltre', 'ouvertes');
        $this->assertSame(1, $ecran->instance()->detail->total());

        $ecran->call('montrer', 'principaux')
            ->assertSee('Pièces ouvertes')
            ->assertDontSee('FNE — pas encore au suivi');
    }

    /** « Une colonne aux deux endroits : rapproché ou non, d'un signe, et le lien vers l'autre ; un filtre » — 26/10. */
    public function test_le_rapprochement_suivi_fne_se_lit_et_se_filtre_des_deux_cotes(): void
    {
        $piece = FactureFournisseur::withoutGlobalScopes()->where('numero_piece', 'BL-77')->sole();
        $fne = FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->sole();
        $this->assertSame($piece->id, (int) $fne->facture_fournisseur_id);

        $suivi = Volt::actingAs($this->gerant)->test('pilotage.fournisseurs')->set('exercice', 2026)
            ->assertSee(route('fournisseurs.fne.detail', $fne->id), false);

        $suivi->set('rapprochementFiltre', 'rapprochees');
        $this->assertSame(1, $suivi->instance()->detail->total());

        // Non rapprochées : la seule FNE que personne n'a portée — la pièce rapprochée sort.
        $suivi->set('rapprochementFiltre', 'non')->assertSee('2626A0000003');
        $this->assertSame(1, $suivi->instance()->detail->total());

        $ecranFne = Volt::actingAs($this->gerant)->test('pilotage.fournisseurs-fne')->set('exercice', 2026)
            ->assertSee(route('fournisseurs.piece', $piece->id), false)
            ->assertSee('non rapprochée');

        $ecranFne->set('filtre', 'au_suivi');
        $this->assertSame(1, $ecranFne->instance()->nombreLignes);
        $ecranFne->set('filtre', 'a_porter');
        $this->assertSame(1, $ecranFne->instance()->nombreLignes);
    }

    /** Mois, Code pièce et Résultat indicatif ne se saisissent plus — 22/10 ; le reste des précisions revient. */
    public function test_le_formulaire_rend_les_precisions_sans_les_trois_champs_retires(): void
    {
        Volt::actingAs($this->gerant)->test('pilotage.fournisseurs')
            ->call('choisirLaSaisie')
            ->set('precisionsOuvertes', true)
            ->assertSee('Les précisions du classeur', false)
            ->assertSee('N° BC')
            ->assertSee('Montant net vente')
            ->assertDontSee('Code pièce')
            ->assertDontSee('Résultat indicatif');
    }

    /** « Filtrer sur le tiers, le numéro FNE, la date ; et le montant de A à B » — 22/10. */
    public function test_la_page_fne_se_filtre_par_tiers_numero_date_et_montant(): void
    {
        $ecran = Volt::actingAs($this->gerant)->test('pilotage.fournisseurs-fne')->set('exercice', 2026);
        $this->assertSame(2, $ecran->instance()->nombreLignes);

        $ecran->set('numero', '0000003');
        $this->assertSame(1, $ecran->instance()->nombreLignes);

        $ecran->call('viderLesFiltres')->set('montantMin', '60000')->set('montantMax', '200000');
        $this->assertSame(1, $ecran->instance()->nombreLignes);
        $this->assertSame(118_000, $ecran->instance()->totalFiltre);

        $ecran->call('viderLesFiltres')->set('dateDu', '2026-04-01');
        $this->assertSame(1, $ecran->instance()->nombreLignes);

        $ecran->call('viderLesFiltres')->set('tiers', 'inconnu');
        $this->assertSame(0, $ecran->instance()->nombreLignes);
        $ecran->set('tiers', 'CIE');
        $this->assertSame(2, $ecran->instance()->nombreLignes);
    }

    // ------------------------------------------------------------------------------ outils

    private function releve(): string
    {
        $emetteur = ['id' => 'c1', 'ncc' => '1234567A', 'name' => 'CIE PIECES', 'rccm' => 'CI-ABJ'];

        return json_encode([
            'login' => '9999999Z',
            'source' => '/ws/invoices?listing=received',
            'periode' => ['du' => '2026-01-01', 'au' => '2026-10-12'],
            'factures' => [
                ['reference' => '2626A0000002', 'id' => 'a2', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2026-03-10T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 100000, 'totalTaxes' => 18000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 118000],
                ['reference' => '2626A0000003', 'id' => 'a3', 'type' => 'invoice', 'subtype' => 'normal', 'status' => 'certified',
                    'date' => '2026-04-02T09:00:00.000Z', 'company' => $emetteur,
                    'totalBeforeTaxes' => 50000, 'totalTaxes' => 9000, 'fiscalStamp' => 0, 'totalAfterTaxes' => 59000],
            ],
        ]);
    }

    private function compte(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

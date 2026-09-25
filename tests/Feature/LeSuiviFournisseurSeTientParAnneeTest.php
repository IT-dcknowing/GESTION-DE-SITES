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
use Modules\Noyau\Exploitation\Services\EtatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le suivi fournisseur se tient par année, comme l'état des impayés.
 *
 * **Ce que ce test protège.** L'écran listait toutes les pièces depuis 2023 dans une seule
 * suite : on ne peut pas arrêter un exercice sur une liste sans fin. L'état d'une année
 * montre désormais ce qui y a été facturé **plus ce qui traîne depuis avant** — et c'est
 * cette règle-là qu'il faut verrouiller, parce qu'elle a deux façons de se tromper qui ne
 * se voient pas :
 *
 * - **recopier** la ligne reportée compterait la dette deux fois dans un total, et on s'en
 *   apercevrait au moment de rapprocher, c'est-à-dire trop tard ;
 * - **déplacer** la ligne viderait l'état de l'année passée, qui ne correspondrait plus à
 *   ce qu'on y avait arrêté.
 *
 * Aucune des deux n'arrive ici : une pièce garde l'année de sa facture, et le report est
 * une règle de lecture. Il se défait de lui-même le jour où la pièce est réglée — personne
 * n'a de bascule à lancer au 1er janvier.
 *
 * **Et la saisie à la main, qui ouvre une porte qu'il faut garder.** L'écran était en
 * lecture seule, et c'est à ce titre qu'il avait été ouvert au comptable. Il reçoit
 * maintenant une saisie : le responsable d'atelier doit continuer de lire sans écrire, le
 * doublon doit être refusé à la frappe, et une ligne venue d'un fichier ne doit pas voir sa
 * clé d'import retouchée — sans quoi le prochain dépôt la recréerait et la dette
 * compterait double.
 */
class LeSuiviFournisseurSeTientParAnneeTest extends TestCase
{
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

    // ------------------------------------------------------------------ la règle de l'année

    public function test_l_etat_d_une_annee_porte_ce_qui_y_a_ete_facture(): void
    {
        $this->piece('SOCIDA', '1001', '2025-03-04', 500_000, 500_000);
        $this->piece('NSIA', '1002', '2026-02-10', 300_000, 0);

        $lignes2026 = EtatDesFournisseurs::requete(2026)->pluck('numero_piece')->all();
        $lignes2025 = EtatDesFournisseurs::requete(2025)->pluck('numero_piece')->all();

        $this->assertSame(['1002'], $lignes2026);
        $this->assertSame(['1001'], $lignes2025);
    }

    public function test_ce_qui_reste_du_se_reporte_tout_seul_sans_etre_recopie(): void
    {
        $ancienne = $this->piece('SOCIDA', '1001', '2025-03-04', 500_000, 200_000);
        $this->piece('NSIA', '1002', '2026-02-10', 300_000, 0);

        $etat = EtatDesFournisseurs::requete(2026)->get();

        // Elle est là, et elle n'a pas bougé d'année : rien n'a été recopié ni déplacé.
        $this->assertEqualsCanonicalizing(['1001', '1002'], $etat->pluck('numero_piece')->all());
        $this->assertSame(2025, $ancienne->fresh()->date_facture->year);
        $this->assertSame(2, FactureFournisseur::withoutGlobalScopes()->count());

        $this->assertTrue(EtatDesFournisseurs::estReportee($ancienne, 2026));
        $this->assertSame('Reporté 2025', EtatDesFournisseurs::libelleReport($ancienne, 2026));
        $this->assertSame('—', EtatDesFournisseurs::libelleReport($ancienne, 2025));
    }

    public function test_le_report_se_defait_le_jour_ou_la_piece_est_reglee(): void
    {
        $ancienne = $this->piece('SOCIDA', '1001', '2025-03-04', 500_000, 200_000);

        $this->assertContains('1001', EtatDesFournisseurs::requete(2026)->pluck('numero_piece')->all());

        // Réglée : elle sort de l'état de 2026 sans que personne ne lance quoi que ce soit,
        // et reste dans celui de 2025, qui ne change pas.
        $ancienne->update(['montant_regle' => 500_000, 'reste_a_payer' => 0]);

        $this->assertNotContains('1001', EtatDesFournisseurs::requete(2026)->pluck('numero_piece')->all());
        $this->assertContains('1001', EtatDesFournisseurs::requete(2025)->pluck('numero_piece')->all());
    }

    public function test_les_totaux_separent_l_annee_de_son_report(): void
    {
        $this->piece('SOCIDA', '1001', '2025-03-04', 500_000, 200_000);   // reporté, 300 000 dus
        $this->piece('NSIA', '1002', '2026-02-10', 300_000, 100_000);     // de l'année, 200 000 dus
        $this->piece('SHELL', '1003', '2026-04-01', 80_000, 80_000);      // de l'année, soldée

        $totaux = EtatDesFournisseurs::totaux(EtatDesFournisseurs::requete(2026), 2026);

        $this->assertSame(3, $totaux['lignes']);
        $this->assertSame(880_000, $totaux['facture']);
        $this->assertSame(380_000, $totaux['regle']);
        $this->assertSame(500_000, $totaux['reste']);
        $this->assertSame(2, $totaux['ouvertes']);
        $this->assertSame(1, $totaux['reportees']);
        $this->assertSame(300_000, $totaux['resteReporte']);
    }

    public function test_un_trop_paye_ne_rembourse_pas_la_dette_d_un_autre(): void
    {
        $this->piece('SOCIDA', '1001', '2026-03-04', 500_000, 200_000);
        $avoir = $this->piece('NSIA', '1002', '2026-02-10', 100_000, 100_000);
        $avoir->update(['reste_a_payer' => -50_000]);

        $totaux = EtatDesFournisseurs::totaux(EtatDesFournisseurs::requete(2026), 2026);

        // 300 000 dus, et un trop-payé de 50 000 compté à part — non déduit. Un total plus
        // petit que l'une de ses parts passerait pour une erreur de calcul.
        $this->assertSame(300_000, $totaux['reste']);
        $this->assertSame(50_000, $totaux['avoirs']);
    }

    public function test_une_piece_sans_date_n_est_perdue_dans_aucune_annee(): void
    {
        $orpheline = $this->piece('SOCIDA', '1001', null, 90_000, 0);

        // Elle n'a pas d'année ; la laisser hors de tout état reviendrait à l'oublier.
        $this->assertContains('1001', EtatDesFournisseurs::requete(2026)->pluck('numero_piece')->all());
        $this->assertContains('1001', EtatDesFournisseurs::requete(2025)->pluck('numero_piece')->all());
        $this->assertFalse(EtatDesFournisseurs::estReportee($orpheline, 2026));

        $orpheline->update(['montant_regle' => 90_000, 'reste_a_payer' => 0]);

        $this->assertNotContains('1001', EtatDesFournisseurs::requete(2026)->pluck('numero_piece')->all());
    }

    public function test_les_annees_consultables_comprennent_l_annee_ouverte_meme_vide(): void
    {
        $this->piece('SOCIDA', '1001', '2024-03-04', 500_000, 500_000);

        $annees = EtatDesFournisseurs::exercices($this->entreprise->id);

        $this->assertContains(2024, $annees);
        $this->assertContains((int) now()->year, $annees);
        // De la plus récente à la plus ancienne : c'est l'année en cours qu'on ouvre.
        $triees = $annees;
        rsort($triees);
        $this->assertSame($triees, $annees);
    }

    // ------------------------------------------------------------------ qui peut écrire

    public function test_le_responsable_d_atelier_lit_mais_n_ecrit_pas(): void
    {
        $this->assertTrue(EtatDesFournisseurs::peutEcrire($this->compte('gerant')));
        $this->assertTrue(EtatDesFournisseurs::peutEcrire($this->compte('responsable_ville')));
        $this->assertTrue(EtatDesFournisseurs::peutEcrire($this->compte('caissier')));
        $this->assertFalse(EtatDesFournisseurs::peutEcrire($this->compte('responsable_site')));

        // Il lit la page — c'est un indicateur de son atelier.
        $this->actingAs($this->compte('responsable_site'))
            ->get(route('fournisseurs'))->assertOk();
    }

    public function test_l_action_refuse_meme_si_la_route_a_laisse_passer(): void
    {
        // Une route protégée ne protège que l'entrée : l'action est appelable directement.
        Volt::actingAs($this->compte('responsable_site'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->call('enregistrer')
            ->assertForbidden();

        $this->assertSame(0, FactureFournisseur::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ la saisie

    public function test_une_facture_recue_entre_deux_depots_se_saisit(): void
    {
        Volt::actingAs($this->compte('caissier'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('numeroPiece', '4138005')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->set('montantRegle', '50000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $piece = FactureFournisseur::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('SOCIDA', $piece->fournisseur);
        $this->assertSame(100_000, (int) $piece->reste_a_payer);
        $this->assertSame($this->ville->id, $piece->ville_id);
        // Les deux relations se lisent ensemble : un auteur et pas de lot, c'est une saisie.
        $this->assertNull($piece->lot_import_id);
        $this->assertNotNull($piece->user_id);
    }

    /**
     * La saisie à la main couvre les mêmes colonnes que le classeur.
     *
     * **Ce qui manquait, relevé par le propriétaire le 24/09.** La table porte quarante
     * colonnes depuis le 22/09 ; le formulaire n'en offrait que douze. Une pièce reçue
     * entre deux dépôts était donc plus pauvre que la même venue du classeur, et sa page
     * de détail affichait des cases vides qu'aucun écran ne permettait de remplir.
     *
     * La liste vient des deux classeurs, feuille « DETAIL » en main : celui d'Abidjan et
     * celui de San-Pédro. On garde ce qu'ils ont en commun et l'on complète par le surplus
     * de chacun — le n° de FEB et le montant HT ne sont qu'à San-Pédro, les quantités et
     * les deux numéros de facture ne sont qu'à Abidjan.
     */
    public function test_la_saisie_a_la_main_porte_les_colonnes_des_deux_classeurs(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'RIMCO SETACI')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '538189')
            // Le dossier
            ->set('mois', '3')
            ->set('section', 'Carrosserie')
            ->set('numeroBc', '227')
            ->set('dateReception', '2026-03-06')
            ->set('typeTransaction', 'Achat de pièces')
            ->set('numeroFiche', 'FR-AB 010136')
            ->set('vehicule', 'GREAT WALL M4')
            ->set('codePiece', '24VGCFC50038')
            // Le règlement
            ->set('dateReglement', '2026-03-20')
            ->set('delaiReglement', '30 jours')
            ->set('numeroCheque', 'CHQ 486440')
            ->set('numeroFeb', '1615')
            // Les montants
            ->set('montantHt', '456092')
            ->set('tva', '82097')
            ->set('tva2', '0')
            ->set('montantRefacture', '600000')
            ->set('montantNetAchat', '456092')
            ->set('montantNetVente', '508474')
            ->set('quantiteTotale', '4')
            ->set('quantiteRefacturee', '3')
            // La refacturation
            ->set('numeroFactureAchat', 'FA-1234')
            ->set('numeroFactureVente', 'FV-5678')
            ->set('numeroFactureClient', '1615')
            ->set('resultatIndicatif', 'Marge correcte')
            ->set('observationsFacturation', 'Une pièce non refacturée.')
            ->set('commentaires', 'Reçue sans bon de livraison.')
            ->set('actionsAMener', 'Vérifier le chèque émis.')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $piece = FactureFournisseur::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(3, (int) $piece->mois);
        $this->assertSame('Carrosserie', $piece->section);
        $this->assertSame('227', $piece->numero_bc);
        $this->assertSame('2026-03-06', $piece->date_reception?->toDateString());
        $this->assertSame('Achat de pièces', $piece->type_transaction);
        $this->assertSame('FR-AB 010136', $piece->numero_fiche);
        $this->assertSame('GREAT WALL M4', $piece->vehicule);
        $this->assertSame('24VGCFC50038', $piece->code_piece);

        $this->assertSame('2026-03-20', $piece->date_reglement?->toDateString());
        $this->assertSame('30 jours', $piece->delai_reglement);
        $this->assertSame('CHQ 486440', $piece->numero_cheque);
        $this->assertSame('1615', $piece->numero_feb);

        $this->assertSame(456092, (int) $piece->montant_ht);
        $this->assertSame(82097, (int) $piece->tva);
        $this->assertSame(600000, (int) $piece->montant_refacture);
        $this->assertSame(508474, (int) $piece->montant_net_vente);
        $this->assertSame(4.0, (float) $piece->quantite_totale);

        $this->assertSame('FA-1234', $piece->numero_facture_achat);
        $this->assertSame('FV-5678', $piece->numero_facture_vente);
        $this->assertSame('Marge correcte', $piece->resultat_indicatif);
        $this->assertSame('Une pièce non refacturée.', $piece->observations_facturation);
        $this->assertSame('Reçue sans bon de livraison.', $piece->commentaires);
        $this->assertSame('Vérifier le chèque émis.', $piece->actions_a_mener);
    }

    public function test_une_precision_laissee_vide_reste_vide_et_ne_vaut_pas_zero(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $piece = FactureFournisseur::withoutGlobalScopes()->firstOrFail();

        // Une case laissée vide dit « on ne sait pas », jamais « c'est zéro ». Un montant
        // HT à 0 F se lirait comme une facture exonérée, ce qui est une autre information.
        $this->assertNull($piece->montant_ht);
        $this->assertNull($piece->tva);
        $this->assertNull($piece->mois);
        $this->assertNull($piece->section);
    }

    public function test_l_atelier_n_est_demande_que_la_ou_il_y_a_un_choix_a_faire(): void
    {
        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs');

        // Une ville à un seul atelier ne pose aucune question.
        $ecran->set('villeSaisie', (string) $this->ville->id);
        $this->assertSame([], $ecran->instance()->sitesOuSaisir);

        // Abidjan en a deux : la question vaut d'être posée, et le champ paraît.
        $second = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-2', 'nom' => 'Abidjan — Site 2', 'est_actif' => true,
        ]);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('villeSaisie', (string) $this->ville->id);

        $this->assertArrayHasKey($second->id, $ecran->instance()->sitesOuSaisir);
        $ecran->assertSee('Atelier');
    }

    public function test_un_atelier_d_une_autre_ville_ne_se_pose_pas(): void
    {
        $autreVille = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $ailleurs = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $autreVille->id,
            'code' => 'BKE-1', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->set('villeSaisie', (string) $this->ville->id)
            // Un identifiant recopié à la main rattacherait la pièce à l'atelier d'une
            // autre ville — et, de proche en proche, son montant avec.
            ->set('siteSaisie', (string) $ailleurs->id)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertNull(FactureFournisseur::withoutGlobalScopes()->firstOrFail()->site_id);
    }

    public function test_sans_numero_la_piece_en_recoit_un(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        // Une dette sans référence ne se retrouve pas au téléphone.
        $this->assertStringStartsWith('FRS-', (string) FactureFournisseur::withoutGlobalScopes()->value('numero_piece'));
    }

    public function test_le_meme_regle_que_l_import_refuse_le_doublon_a_la_frappe(): void
    {
        $this->piece('SOCIDA', '4138005', '2026-03-04', 150_000, 0);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('numeroPiece', '4138005')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->call('enregistrer')
            ->assertHasErrors('numeroPiece');

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
    }

    public function test_un_reglement_ne_peut_pas_depasser_le_montant(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->set('montantRegle', '200000')
            ->call('enregistrer')
            ->assertHasErrors('montantRegle');

        $this->assertSame(0, FactureFournisseur::withoutGlobalScopes()->count());
    }

    public function test_la_ville_recue_du_formulaire_ne_prouve_rien(): void
    {
        $ailleurs = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        Volt::actingAs($this->compte('caissier'))->test('pilotage.fournisseurs')
            ->set('fournisseur', 'SOCIDA')
            ->set('dateFacture', '2026-03-04')
            ->set('montant', '150000')
            ->set('villeSaisie', (string) $ailleurs->id)
            ->call('enregistrer')
            ->assertHasNoErrors();

        // Le caissier d'Abidjan ne peut pas ranger une dette à Bouaké : la ville hors
        // périmètre est écartée, la pièce reste à préciser plutôt que d'être mal rangée.
        $this->assertNull(FactureFournisseur::withoutGlobalScopes()->value('ville_id'));
    }

    // ------------------------------------------------------------------ la page de détail

    public function test_la_page_d_une_piece_montre_ce_que_le_tableau_ne_montre_pas(): void
    {
        $piece = $this->piece('SOCIDA', '4138005', '2026-03-04', 150_000, 50_000);
        $piece->update(['numero_cheque' => 'CH-4412', 'commentaires' => 'Livraison partielle']);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->assertSee('4138005')
            ->assertSee('CH-4412')
            ->assertSee('Livraison partielle');
    }

    public function test_une_piece_hors_perimetre_repond_comme_une_piece_qui_n_existe_pas(): void
    {
        $ailleurs = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $piece = $this->piece('SOCIDA', '4138005', '2026-03-04', 150_000, 0);
        $piece->update(['ville_id' => $ailleurs->id]);

        // L'identifiant vient de l'adresse, donc de n'importe qui. On ne dit pas qu'elle
        // existe ailleurs : on dit qu'on ne l'a pas.
        Volt::actingAs($this->compte('caissier'))->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->assertSee('Pièce introuvable')
            ->assertDontSee('4138005');
    }

    public function test_la_cle_d_import_d_une_ligne_importee_ne_se_retouche_pas(): void
    {
        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'deposant' => 'K. Désirée', 'format' => 'fournisseurs',
            'nom_fichier' => 'suivi.xlsm', 'empreinte' => hash('sha256', 'x'),
            'taille' => 10, 'etat' => 'termine',
        ]);

        $piece = $this->piece('SOCIDA', '4138005', '2026-03-04', 150_000, 0);
        $piece->update(['lot_import_id' => $lot->id]);

        $this->assertSame(
            ['fournisseur', 'numero_piece', 'date_facture', 'montant'],
            EtatDesFournisseurs::champsVerrouilles($piece->fresh()),
        );

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->call('modifier')
            ->set('fournisseur', 'AUTRE CHOSE')
            ->set('montant', '9999999')
            ->set('montantRegle', '25000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $apres = $piece->fresh();

        // La clé n'a pas bougé : un prochain dépôt du même fichier reconnaîtra sa ligne au
        // lieu de la recréer. Le reste, lui, s'est bien corrigé.
        $this->assertSame('SOCIDA', $apres->fournisseur);
        $this->assertSame(150_000, (int) $apres->montant);
        $this->assertSame(25_000, (int) $apres->montant_regle);
        $this->assertSame(125_000, (int) $apres->reste_a_payer);
    }

    public function test_une_ligne_saisie_a_la_main_se_corrige_en_entier(): void
    {
        $piece = $this->piece('SOCIDA', 'FRS-0403-0001', '2026-03-04', 150_000, 0);

        $this->assertSame([], EtatDesFournisseurs::champsVerrouilles($piece));

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->call('modifier')
            ->set('fournisseur', 'SOCIDA CÔTE D IVOIRE')
            ->set('montant', '160000')
            ->set('montantRegle', '60000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $apres = $piece->fresh();

        $this->assertSame('SOCIDA CÔTE D IVOIRE', $apres->fournisseur);
        $this->assertSame(160_000, (int) $apres->montant);
        $this->assertSame(100_000, (int) $apres->reste_a_payer);
    }

    // ------------------------------------------------------------------ outillage

    // ------------------------------------------------------------------ le tableau initial

    /**
     * Le pendant du « Tableau état initial » des impayés.
     *
     * **Ce qu'il protège, et que l'état par année ne peut pas montrer.** L'état d'une année
     * retient ce qui y a été facturé plus ce qui reste dû depuis avant : une pièce de 2023
     * **déjà soldée** n'entre donc dans aucun exercice, et disparaît de tous les écrans.
     * C'est acceptable pour travailler, et inacceptable pour vérifier une reprise. Le
     * propriétaire l'a relevé le 24/09 : « je ne vois pas le bouton initial comme celui
     * des impayés ».
     */
    public function test_le_tableau_initial_montre_aussi_ce_qui_est_solde_et_ancien(): void
    {
        $this->piece('SOCIDA', '1001', '2023-03-04', 500_000, 500_000);
        $this->piece('NSIA', '1002', '2026-02-10', 300_000, 0);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-tableau-initial');

        // Les deux, alors qu'aucun exercice ne porte la première.
        $this->assertSame(2, $ecran->instance()->nombre);
        $ecran->assertSee('1001')->assertSee('1002');

        // Et l'état de 2026, lui, ne la voit pas : c'est bien deux vues, pas deux copies.
        $this->assertSame(['1002'], EtatDesFournisseurs::requete(2026)->pluck('numero_piece')->all());
    }

    public function test_le_tableau_initial_separe_la_reprise_de_la_saisie(): void
    {
        $lot = LotImport::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'deposant' => 'Reprise',
            'format' => 'fournisseurs',
            'nom_fichier' => 'FSF L2A.xlsx',
            'empreinte' => str_repeat('a', 64),
            'etat' => 'termine',
        ]);

        $this->piece('SOCIDA', '1001', '2025-03-04', 500_000, 0)->update(['lot_import_id' => $lot->id]);
        $this->piece('NSIA', '1002', '2026-02-10', 300_000, 0);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs-tableau-initial');

        $ecran->set('origineFiltre', 'saisie');
        $this->assertSame(1, $ecran->instance()->nombre);
        $this->assertSame(['1002'], $ecran->instance()->lignes->pluck('numero_piece')->all());

        $ecran->set('origineFiltre', 'fichier');
        $this->assertSame(['1001'], $ecran->instance()->lignes->pluck('numero_piece')->all());
    }

    /**
     * Le périmètre se lit sur l'identité du lecteur, jamais sur le paramètre reçu.
     *
     * Un tableau « tout, tel quel » est précisément celui où l'on serait tenté d'oublier la
     * règle : c'est celui où elle compte le plus.
     */
    public function test_le_tableau_initial_ne_montre_pas_une_autre_ville(): void
    {
        $ailleurs = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ailleurs->id,
            'code' => 'BKE-1', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        $this->piece('SOCIDA', '1001', '2026-03-04', 500_000, 0)->update(['ville_id' => $ailleurs->id]);
        $this->piece('NSIA', '1002', '2026-02-10', 300_000, 0);

        // Le périmètre d'un responsable de ville se lit sur la ville qu'il dirige, et non
        // sur la case « ville » de sa fiche — c'est la règle de Site::visiblesPour().
        $responsable = $this->compte('responsable_ville');
        $this->ville->update(['responsable_id' => $responsable->id]);

        // Sans filtre, il voit la sienne, et elle seule.
        $ecran = Volt::actingAs($responsable)
            ->test('pilotage.fournisseurs-tableau-initial');

        $this->assertSame(['1002'], $ecran->instance()->lignes->pluck('numero_piece')->all());

        // Le filtre ne lui est même pas offert — mais l'état se force depuis le navigateur.
        // Forcé, il n'ouvre rien : le filtre ne peut qu'élaguer le périmètre, jamais
        // l'élargir. Zéro ligne est la bonne réponse ; la pièce de Bouaké en serait une
        // mauvaise, et silencieuse.
        $ecran->set('villeFiltre', (string) $ailleurs->id);

        $this->assertSame([], $ecran->instance()->lignes->pluck('numero_piece')->all());
    }

    private function piece(string $fournisseur, string $numero, ?string $date, int $montant, int $regle): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'fournisseur' => $fournisseur,
            'numero_piece' => $numero,
            'date_facture' => $date,
            'montant' => $montant,
            'montant_regle' => $regle,
            'reste_a_payer' => $montant - $regle,
        ]);
    }

    /** @var array<string, User> */
    private array $comptes = [];

    /** Le même rôle rend le même compte : deux appels ne doivent pas buter sur le courriel. */
    private function compte(string $role): User
    {
        if (isset($this->comptes[$role])) {
            return $this->comptes[$role];
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $this->comptes[$role] = $compte->fresh();
    }
}

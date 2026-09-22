<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\FormeComparable;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Modeles\RapprochementEcarte;
use Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le chantier 4 : relier la prospection au devis qu'elle a produit.
 *
 * **Le constat.** Sur 2 673 devis en base, 241 portent un `prospection_id` — 9 %. Les
 * autres viennent de l'import de l'atelier : rien n'y dit quel commercial a décroché
 * l'affaire, si bien que le devis n'est compté à personne et que la prospection reste
 * « sans suite » alors qu'elle en a eu une.
 *
 * **La clé, tranchée par le propriétaire le 18/09/2026 : immatriculation + date.** Le n° de
 * fiche de réception reste accepté et l'emporte quand il est là, mais il ne peut pas être
 * la condition — « le devis ne se fait pas toujours au même moment que la prospection ».
 *
 * Ce que ces tests tiennent : la fenêtre ne remonte pas dans le passé, la piste la plus
 * sûre gagne, un refus se souvient d'être un refus, le périmètre vaut des deux côtés, et
 * rien ne se rattache sans un clic.
 */
class RapprocherLaProspectionEtLeDevisTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    private Commercial $commercial;

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

        $this->commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'numero' => 'C-001',
            'nom' => 'Koffi Yao',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | La forme sous laquelle deux chaînes se comparent
    |--------------------------------------------------------------------------
    */

    public function test_une_plaque_se_compare_quelles_que_soient_ses_graphies(): void
    {
        $formes = ['1234 AB 01', '1234ab01', ' 1234-AB-01 ', '1234  Ab  01'];

        foreach ($formes as $forme) {
            $this->assertSame('1234AB01', FormeComparable::plaque($forme), $forme.' devrait désigner le même véhicule.');
        }

        // Et deux véhicules différents ne se confondent pas pour autant.
        $this->assertNotSame(FormeComparable::plaque('1234 AB 01'), FormeComparable::plaque('1235 AB 01'));
    }

    public function test_un_nom_de_client_se_compare_sans_ses_accents_ni_sa_forme_juridique(): void
    {
        $this->assertSame(FormeComparable::nom('Bernabé'), FormeComparable::nom('BERNABE'));
        $this->assertSame(FormeComparable::nom('Nestlé CI'), FormeComparable::nom('NESTLE'));
        $this->assertSame(FormeComparable::nom('SIFCA  S.A.'), FormeComparable::nom('Sifca'));

        // « SA » au début fait partie du nom : ce n'est pas une forme juridique traînante.
        $this->assertSame('SA MAISON', FormeComparable::nom('Sa Maison'));
        $this->assertNotSame(FormeComparable::nom('CFAO Motors'), FormeComparable::nom('CFAO Technologies'));
    }

    public function test_la_plaque_s_enregistre_lisible_et_non_compressee(): void
    {
        $prospection = $this->prospection('2026-03-02', ['immatriculation' => ' 1234  ab 01 ']);

        // Rangée, mais toujours lisible : c'est ce que le conducteur a sur sa carte grise.
        $this->assertSame('1234 AB 01', $prospection->fresh()->immatriculation);
    }

    /*
    |--------------------------------------------------------------------------
    | Les trois pistes
    |--------------------------------------------------------------------------
    */

    public function test_la_plaque_rapproche_la_prospection_du_devis_emis_quelques_jours_apres(): void
    {
        $prospection = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Un autre nom, sans rapport');
        $this->fiche('FR-AB 010136', '1234-AB-01');

        $propositions = RapprochementProspectionDevis::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('plaque', $propositions[0]['motif']);
        $this->assertSame($devis->id, $propositions[0]['devis']->id);
        $this->assertSame($prospection->id, $propositions[0]['prospection']->id);
        $this->assertSame(4, $propositions[0]['ecart']);
    }

    public function test_le_numero_de_fiche_emporte_la_decision_quand_il_est_la(): void
    {
        $this->prospection('2026-03-02', [
            'immatriculation' => '1234 AB 01',
            'n_fiche_reception' => 'FR-AB 010200',
        ]);

        // Deux devis candidats : l'un porte la même plaque, l'autre la même fiche. La
        // fiche est le même dossier — elle ne s'interprète pas, elle gagne.
        $parLaPlaque = $this->devis('2026-03-04', 'FR-AB 010136', 'Client A');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $parLaFiche = $this->devis('2026-03-08', 'FR-AB 010200', 'Client B');

        $propositions = RapprochementProspectionDevis::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('fiche', $propositions[0]['motif']);
        $this->assertSame($parLaFiche->id, $propositions[0]['devis']->id);
        $this->assertNotSame($parLaPlaque->id, $propositions[0]['devis']->id);
    }

    public function test_a_piste_egale_le_devis_le_plus_proche_dans_le_temps_l_emporte(): void
    {
        $this->prospection('2026-03-02', ['client' => 'Bernabé']);

        $leProche = $this->devis('2026-03-04', 'FR-AB 000001', 'BERNABE');
        $this->devis('2026-03-12', 'FR-AB 000002', 'Bernabe SA');

        $propositions = RapprochementProspectionDevis::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('nom', $propositions[0]['motif']);
        $this->assertSame($leProche->id, $propositions[0]['devis']->id);
    }

    /*
    |--------------------------------------------------------------------------
    | La fenêtre
    |--------------------------------------------------------------------------
    */

    public function test_un_devis_anterieur_a_la_prospection_n_est_jamais_propose(): void
    {
        $this->prospection('2026-03-10', ['immatriculation' => '1234 AB 01']);
        $this->devis('2026-03-04', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        // Une affaire ne se chiffre pas avant d'être visitée.
        $this->assertCount(0, RapprochementProspectionDevis::propositions([$this->site->id]));
    }

    public function test_au_dela_de_la_fenetre_c_est_une_autre_affaire(): void
    {
        $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $this->devis('2026-04-20', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $this->assertCount(0, RapprochementProspectionDevis::propositions([$this->site->id], 15));

        // Le même couple réapparaît si l'on élargit soi-même la fenêtre.
        $this->assertCount(1, RapprochementProspectionDevis::propositions([$this->site->id], 60));
    }

    public function test_une_fenetre_forgee_retombe_sur_la_valeur_par_defaut(): void
    {
        foreach ([0, -12, 4000, 'quinze'] as $valeur) {
            $this->assertSame(
                RapprochementProspectionDevis::FENETRE_EN_JOURS,
                RapprochementProspectionDevis::fenetre($valeur),
            );
        }

        $this->assertSame(30, RapprochementProspectionDevis::fenetre('30'));
    }

    /*
    |--------------------------------------------------------------------------
    | Confirmer, écarter — et ne jamais décider seul
    |--------------------------------------------------------------------------
    */

    public function test_confirmer_rattache_le_devis_et_le_porte_au_commercial_de_la_prospection(): void
    {
        $gerant = $this->compte('gerant');
        $prospection = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $autreCommercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'numero' => 'C-002', 'nom' => 'Personne',
        ]);
        $devis->update(['commercial_id' => $autreCommercial->id]);

        $refus = RapprochementProspectionDevis::confirmer($gerant, $prospection->id, $devis->id, [$this->site->id]);

        $this->assertNull($refus);
        $this->assertSame($prospection->id, $devis->fresh()->prospection_id);

        // Le commercial suit l'affaire : c'est celui qui a fait la visite qui la porte.
        $this->assertSame($this->commercial->id, $devis->fresh()->commercial_id);

        // Et le couple disparaît des propositions, puisqu'il n'est plus une question.
        $this->assertCount(0, RapprochementProspectionDevis::propositions([$this->site->id]));
    }

    public function test_un_devis_deja_rattache_ne_se_rattache_pas_deux_fois(): void
    {
        $gerant = $this->compte('gerant');
        $premiere = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $seconde = $this->prospection('2026-03-03', ['immatriculation' => '1234 AB 01', 'numero' => 'P-0002']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        RapprochementProspectionDevis::confirmer($gerant, $premiere->id, $devis->id, [$this->site->id]);
        $refus = RapprochementProspectionDevis::confirmer($gerant, $seconde->id, $devis->id, [$this->site->id]);

        $this->assertSame('Ce devis est déjà rattaché à une prospection.', $refus);
        $this->assertSame($premiere->id, $devis->fresh()->prospection_id);
    }

    public function test_un_couple_ecarte_ne_revient_plus_dans_la_liste(): void
    {
        $gerant = $this->compte('gerant');
        $prospection = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $this->assertCount(1, RapprochementProspectionDevis::propositions([$this->site->id]));

        $this->assertNull(RapprochementProspectionDevis::ecarter($gerant, $prospection->id, $devis->id, [$this->site->id]));
        $this->assertCount(0, RapprochementProspectionDevis::propositions([$this->site->id]));

        // Le refus est une décision : elle porte son auteur, comme une confirmation.
        $ecarte = RapprochementEcarte::first();
        $this->assertSame($gerant->id, $ecarte->user_id);
        $this->assertSame($gerant->name, $ecarte->auteur);

        // Le devis reste libre : écarter n'est pas rattacher.
        $this->assertNull($devis->fresh()->prospection_id);
    }

    public function test_le_perimetre_vaut_des_deux_cotes_du_couple(): void
    {
        $gerant = $this->compte('gerant');
        $prospection = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        // Un périmètre qui ne contient pas l'atelier de ces pièces : le service refuse, et
        // il refuse **avant** d'écrire quoi que ce soit.
        $refus = RapprochementProspectionDevis::confirmer($gerant, $prospection->id, $devis->id, [999]);

        $this->assertSame("Cette prospection ou ce devis n'est pas dans votre périmètre.", $refus);
        $this->assertNull($devis->fresh()->prospection_id);
        $this->assertCount(0, RapprochementProspectionDevis::propositions([999]));
    }

    public function test_une_prospection_refusee_ne_se_rapproche_de_rien(): void
    {
        $this->prospection('2026-03-02', [
            'immatriculation' => '1234 AB 01',
            'statut_validation' => 'Refusée',
        ]);
        $this->devis('2026-03-06', 'FR-AB 010136', 'Client');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        // Rattacher un devis à une visite refusée validerait par la bande ce qu'un
        // responsable a refusé.
        $this->assertCount(0, RapprochementProspectionDevis::propositions([$this->site->id]));
    }

    /*
    |--------------------------------------------------------------------------
    | L'écran
    |--------------------------------------------------------------------------
    */

    public function test_l_ecran_propose_puis_confirme_sur_un_clic(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $prospection = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Client du devis');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $ecran = Volt::actingAs($gerant)->test('pilotage.rapprochement-prospections-devis')
            ->assertSee($prospection->numero)
            ->assertSee($devis->numero)
            ->assertSee('Même immatriculation');

        // Tant qu'on n'a pas cliqué, rien n'est écrit : l'écran propose.
        $this->assertNull($devis->fresh()->prospection_id);

        $ecran->call('confirmer', $prospection->id, $devis->id)
            ->assertSee('Rapprochement confirmé');

        $this->assertSame($prospection->id, $devis->fresh()->prospection_id);
    }

    public function test_les_lignes_cochees_se_confirment_ensemble(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $premiere = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devisUn = $this->devis('2026-03-06', 'FR-AB 010136', 'Client un');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $seconde = $this->prospection('2026-03-03', ['numero' => 'P-0002', 'immatriculation' => '5678 CD 01']);
        $devisDeux = $this->devis('2026-03-07', 'FR-AB 010137', 'Client deux');
        $this->fiche('FR-AB 010137', '5678 CD 01');

        // Entre « tout confirmer » et « ligne à ligne » manquait le geste ordinaire : je
        // lis la page, j'en reconnais deux, je les confirme ensemble.
        Volt::actingAs($gerant)->test('pilotage.rapprochement-prospections-devis')
            ->call('basculer', $premiere->id.'-'.$devisUn->id)
            ->call('basculer', $seconde->id.'-'.$devisDeux->id)
            ->call('confirmerLaSelection')
            ->assertSee('2 rapprochement(s) confirmé(s)');

        $this->assertSame($premiere->id, $devisUn->fresh()->prospection_id);
        $this->assertSame($seconde->id, $devisDeux->fresh()->prospection_id);
    }

    public function test_une_ligne_decochee_n_est_pas_confirmee(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $prospection = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Client du devis');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        // Cocher puis décocher doit laisser la ligne intacte : une case qui garde sa coche
        // après le second clic confirmerait ce qu'on venait d'écarter.
        Volt::actingAs($gerant)->test('pilotage.rapprochement-prospections-devis')
            ->call('basculer', $prospection->id.'-'.$devis->id)
            ->call('basculer', $prospection->id.'-'.$devis->id)
            ->call('confirmerLaSelection')
            ->assertSee('Aucune ligne cochée');

        $this->assertNull($devis->fresh()->prospection_id);
    }

    public function test_la_confirmation_en_lot_ne_touche_pas_les_rapprochements_par_le_nom(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $parLaPlaque = $this->prospection('2026-03-02', ['immatriculation' => '1234 AB 01']);
        $devisPlaque = $this->devis('2026-03-06', 'FR-AB 010136', 'Sans rapport');
        $this->fiche('FR-AB 010136', '1234 AB 01');

        $this->prospection('2026-03-03', ['client' => 'Bernabé', 'numero' => 'P-0002']);
        $devisNom = $this->devis('2026-03-07', 'FR-AB 010137', 'BERNABE');

        Volt::actingAs($gerant)->test('pilotage.rapprochement-prospections-devis')
            ->call('confirmerLesCertains')
            ->assertSee('1 rapprochement(s) confirmé(s)');

        $this->assertSame($parLaPlaque->id, $devisPlaque->fresh()->prospection_id);

        // Un même client revient plusieurs fois dans l'année : ce rapprochement-là se
        // regarde avant d'être confirmé.
        $this->assertNull($devisNom->fresh()->prospection_id);
    }

    public function test_le_commercial_n_entre_pas_dans_l_ecran_qui_lui_attribue_des_devis(): void
    {
        $this->actingAs($this->compte('commercial'));

        // Se rattacher soi-même les devis de l'atelier serait gonfler sa performance.
        $this->get(route('rapprochement.prospections-devis'))->assertRedirect();
    }

    /*
    |--------------------------------------------------------------------------
    | Le numéro du devis, exigé au moment où l'on déclare le passage en devis
    |--------------------------------------------------------------------------
    */

    public function test_declarer_un_passage_en_devis_sans_son_numero_est_refuse(): void
    {
        $commercial = $this->compte('commercial');
        $this->commercial->forceFill(['user_id' => $commercial->id])->save();
        $this->actingAs($commercial);

        /*
         * Décidé par le propriétaire le 22/09 : quand le commercial coche « devis après
         * passage », il tient le devis, et il peut en donner le numéro. L'exiger à cet
         * instant-là supprime tout le rapprochement qui suivrait.
         */
        Volt::actingAs($commercial)->test('commercial.mes-prospections')
            ->set('date', '2026-03-02')
            ->set('client', 'Client visité')
            ->set('activite', 'Mécanique')
            ->set('devisApres', true)
            ->set('dateDevis', '2026-03-02')
            ->set('nDevis', '')
            ->call('ajouterEtTransmettre')
            ->assertHasErrors('nDevis');

        $this->assertSame(0, Prospection::count());
    }

    public function test_une_prospection_sans_devis_ne_reclame_aucun_numero(): void
    {
        $commercial = $this->compte('commercial');
        $this->commercial->forceFill(['user_id' => $commercial->id])->save();
        $this->actingAs($commercial);

        // La plupart des visites ne produisent rien tout de suite : exiger un numéro les
        // rendrait impossibles à saisir.
        Volt::actingAs($commercial)->test('commercial.mes-prospections')
            ->set('date', '2026-03-02')
            ->set('client', 'Client visité')
            ->set('activite', 'Mécanique')
            ->set('devisApres', false)
            ->call('ajouterEtTransmettre')
            ->assertHasNoErrors();

        $this->assertSame(1, Prospection::count());
        $this->assertNull(Prospection::first()->n_devis);
    }

    public function test_le_numero_declare_rapproche_sans_rien_deviner(): void
    {
        $prospection = $this->prospection('2026-03-02', ['n_devis' => 'PR-MT-11434']);

        // Deux devis candidats. Celui que la prospection nomme gagne, même si l'autre est
        // plus proche dans le temps et porte le même client.
        $this->devis('2026-03-03', 'FR-AB 000001', 'Client visité');
        $nomme = Devis::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id,
            'numero' => 'PR-MT-11434',
            'n_fiche_reception' => 'FR-AB 000002',
            'date_emission' => '2026-03-09',
            'client' => 'Un tout autre nom',
            'activite' => 'Mécanique',
            'statut' => 'En attente',
            'montant_devis' => 450_000,
        ]);

        $propositions = RapprochementProspectionDevis::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('devis', $propositions[0]['motif']);
        $this->assertSame($nomme->id, $propositions[0]['devis']->id);

        // Et le prospection->numero n'a pas eu à être confirmé par la plaque ni par le nom.
        $this->assertSame($prospection->id, $propositions[0]['prospection']->id);
    }

    public function test_un_numero_de_fiche_ecrit_dans_ce_champ_fonctionne_aussi(): void
    {
        // Un commercial qui n'a que la fiche sous les yeux ne doit pas être bloqué : le
        // champ s'appelle « n° du devis », il accepte les deux numérotations.
        $this->prospection('2026-03-02', ['n_devis' => 'FR-AB 010136']);
        $devis = $this->devis('2026-03-06', 'FR-AB 010136', 'Sans rapport');

        $propositions = RapprochementProspectionDevis::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('devis', $propositions[0]['motif']);
        $this->assertSame($devis->id, $propositions[0]['devis']->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    /** @param  array<string, mixed>  $attributs */
    private function prospection(string $date, array $attributs = []): Prospection
    {
        return Prospection::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id,
            'numero' => $attributs['numero'] ?? 'P-0001',
            'date' => $date,
            'client' => $attributs['client'] ?? 'Client visité',
            'activite' => 'Mécanique',
            'moyen' => 'RDV',
            'statut_validation' => $attributs['statut_validation'] ?? 'Validée',
            'immatriculation' => $attributs['immatriculation'] ?? null,
            'n_fiche_reception' => $attributs['n_fiche_reception'] ?? null,
            'n_devis' => $attributs['n_devis'] ?? null,
        ]);
    }

    private function devis(string $emission, string $fiche, string $client): Devis
    {
        return Devis::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id,
            'numero' => 'D-'.substr(md5($fiche.$emission), 0, 8),
            'n_fiche_reception' => $fiche,
            'date_emission' => $emission,
            'client' => $client,
            'activite' => 'Mécanique',
            'statut' => 'En attente',
            'montant_devis' => 450_000,
        ]);
    }

    private function fiche(string $numero, string $plaque): DossierVehicule
    {
        return DossierVehicule::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'numero_fiche' => $numero,
            'immatriculation' => $plaque,
            'client' => 'Propriétaire',
        ]);
    }

    private function compte(string $role): User
    {
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

        return $compte->fresh();
    }
}

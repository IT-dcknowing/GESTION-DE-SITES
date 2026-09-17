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
use Modules\Noyau\Entreprises\Services\VilleDeTravail;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Imports\Formats\FormatDesImpayes;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les décisions du 16/09/2026 sur les créances, et les gestes qui en découlent.
 *
 * - **L'âge d'une créance part de son dépôt chez le client**, et de son édition à défaut —
 *   pour tout le recouvrement à la fois.
 * - **Une facture connaît sa ville** même quand on ignore son atelier : la colonne SITE du
 *   classeur dit « ABIDJAN » sur 5 097 lignes, et Abidjan a deux ateliers.
 * - L'état des impayés gagne **Détail**, **Modifier** et **Porter une facture existante**, et
 *   sa date de réception devient obligatoire.
 */
class DepotEtVilleDesCreancesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $site;

    private Site $siteBouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        $this->siteBouake = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id,
            'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | L'ancienneté part du dépôt
    |--------------------------------------------------------------------------
    */

    public function test_l_anciennete_se_compte_depuis_le_depot_et_depuis_l_edition_a_defaut(): void
    {
        $this->actingAs($this->compte('gerant'));

        $arrete = now()->startOfDay();

        $deposee = $this->creance('DEPOSEE', 100_000, dateEdition: $arrete->copy()->subDays(95), dateDepot: $arrete->copy()->subDays(20));
        $nonDeposee = $this->creance('EDITION', 100_000, dateEdition: $arrete->copy()->subDays(95));

        // Éditée il y a 95 jours, déposée il y a 20 : c'est le dépôt qui ouvre le droit de
        // réclamer. Comptée depuis l'édition, elle serait au contentieux cinq jours après
        // avoir été remise au client.
        $this->assertSame(20, Recouvrement::anciennete($deposee, $arrete));
        $this->assertSame(2, Recouvrement::niveau($deposee, $arrete)['niveau']);

        $this->assertSame(95, Recouvrement::anciennete($nonDeposee, $arrete));
        $this->assertSame(5, Recouvrement::niveau($nonDeposee, $arrete)['niveau']);
    }

    /*
    |--------------------------------------------------------------------------
    | La ville
    |--------------------------------------------------------------------------
    */

    public function test_la_ville_suit_l_atelier_a_chaque_ecriture(): void
    {
        $facture = $this->creance('F1', 100_000, site: $this->siteBouake);

        $this->assertSame($this->bouake->id, $facture->fresh()->ville_id);

        $facture->update(['site_id' => $this->site->id]);

        $this->assertSame($this->abidjan->id, $facture->fresh()->ville_id);
    }

    public function test_la_ville_regardee_filtre_les_factures_par_leur_ville_meme_sans_atelier(): void
    {
        $this->actingAs($this->compte('gerant'));

        $abidjanSansAtelier = $this->creance('ABJ-SANS-ATELIER', 100_000, site: null, ville: $this->abidjan);
        $bouake = $this->creance('BOUAKE', 200_000, site: $this->siteBouake);
        $nulPart = $this->creance('NUL-PART', 300_000, site: null);

        VilleDeTravail::choisir($this->bouake->id);

        $ids = Recouvrement::requete()->pluck('id')->all();

        // La créance d'Abidjan sans atelier ne s'invite plus dans Bouaké : c'était le cas des
        // 5 077 lignes reprises du classeur tant que seul l'atelier comptait.
        $this->assertNotContains($abidjanSansAtelier->id, $ids);
        $this->assertContains($bouake->id, $ids);
        // Celle dont on ignore tout reste visible partout : la cacher serait la perdre.
        $this->assertContains($nulPart->id, $ids);
    }

    public function test_les_encaissements_du_tableau_de_bord_suivent_la_ville_regardee(): void
    {
        $this->actingAs($this->compte('gerant'));

        $abidjan = $this->creance('ABJ', 500_000, site: null, ville: $this->abidjan);
        $bouake = $this->creance('BOU', 500_000, site: $this->siteBouake);
        $this->encaisser($abidjan, 100_000);
        $this->encaisser($bouake, 30_000);

        VilleDeTravail::choisir($this->bouake->id);

        $total = (int) Recouvrement::encaissementsDeLaVilleRegardee(Encaissement::query())->sum('montant');

        $this->assertSame(30_000, $total);
    }

    public function test_le_tableau_de_bord_du_recouvrement_propose_la_ville(): void
    {
        $this->actingAs($this->compte('gerant'))
            ->get(route('recouvrement.tableau-de-bord'))
            ->assertOk()
            ->assertSee('Toutes les villes (consolidé)')
            ->assertSee('Bouaké');
    }

    public function test_l_import_n_ecrit_pas_une_ville_presumee(): void
    {
        $lot = LotImport::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'deposant' => 'Reprise',
            'format' => 'impayes', 'nom_fichier' => 'Etats.xlsx', 'empreinte' => str_repeat('b', 64), 'etat' => 'termine',
        ]);

        $format = new class($this->entreprise->id, new Rattachement($this->entreprise->id)) extends FormatDesImpayes {
            public function ecrireLaLigne(array $ligne, array $rattachement, int $lot): void
            {
                $this->ecrire($ligne, $rattachement, $lot);
            }
        };

        $ligne = fn (string $numero) => [
            'numero' => $numero, 'date' => '2025-02-01', 'client' => 'CLIENT', 'montant' => '100000',
        ];

        // La colonne SITE dit « ABIDJAN » : la ville est établie par la donnée.
        $format->ecrireLaLigne($ligne('ETABLIE'), [
            'ville_id' => $this->abidjan->id, 'site_id' => null, 'code' => null, 'source' => 'colonne', 'presumee' => false,
        ], $lot->id);

        // Colonne vide : la ville ne vient que du dépôt, et le classeur couvre toute l'entreprise.
        $format->ecrireLaLigne($ligne('PRESUMEE'), [
            'ville_id' => $this->abidjan->id, 'site_id' => null, 'code' => null, 'source' => 'depot', 'presumee' => true,
        ], $lot->id);

        $this->assertSame($this->abidjan->id, Facture::where('n_facture', 'ETABLIE')->value('ville_id'));
        $this->assertNull(Facture::where('n_facture', 'PRESUMEE')->value('ville_id'));
    }

    public function test_la_commande_pose_la_ville_d_apres_l_atelier_en_constat_d_abord(): void
    {
        $facture = $this->creance('ANCIENNE', 100_000, site: $this->siteBouake);
        \Illuminate\Support\Facades\DB::table('factures')->where('id', $facture->id)->update(['ville_id' => null]);

        $this->artisan('factures:poser-la-ville')->assertSuccessful();
        $this->assertNull($facture->fresh()->ville_id, 'Le constat ne doit rien écrire.');

        $this->artisan('factures:poser-la-ville', ['--appliquer' => true])->assertSuccessful();
        $this->assertSame($this->bouake->id, $facture->fresh()->ville_id);
    }

    /*
    |--------------------------------------------------------------------------
    | L'écran : colonnes, détail, modifier
    |--------------------------------------------------------------------------
    */

    public function test_le_tableau_montre_les_commentaires_et_les_colonnes_du_reglement(): void
    {
        $this->actingAs($this->compte('gerant'));

        $creance = $this->creance('4420', 300_000, dateDepot: now()->subDays(3));
        $creance->update(['observations' => 'EN ATTENTE DE JUSTIF DE REGLEMENT PAR SAAR', 'banque' => 'BGFI', 'vehicule' => 'TOYOTA HILUX']);
        $this->encaisser($creance, 50_000, 'VIREMENT — BGFI');

        Volt::test('pilotage.impayes')
            ->set('exercice', (int) now()->format('Y'))
            ->assertSee('Commentaires')
            ->assertSee('EN ATTENTE DE JUSTIF DE REGLEMENT PAR SAAR')
            ->assertSee('Date de réception')
            ->assertSee('Mode de règlement')
            ->assertSee('VIREMENT — BGFI')
            ->assertSee('BGFI')
            ->assertSee('TOYOTA HILUX')
            ->assertSee('Détail')
            ->assertSee('Modifier');
    }

    public function test_le_detail_dit_l_origine_et_les_reglements(): void
    {
        $this->actingAs($this->compte('gerant'));

        $creance = $this->creance('4421', 300_000, dateDepot: now()->subDays(3));
        $this->encaisser($creance, 75_000, 'CHÈQUE');

        // Le détail a sa propre page, et non un volet déplié sous la ligne.
        $this->get(route('impayes.detail', $creance->id))
            ->assertOk()
            ->assertSee("Saisie à l'état des impayés")
            ->assertSee("Qui l'a touchée", false)
            ->assertSee('CHÈQUE')
            ->assertSee(route('impayes', ['exercice' => $creance->exercice_impayes, 'modifier' => $creance->id]));

        Volt::test('pilotage.impayes')
            ->set('exercice', (int) now()->format('Y'))
            ->assertSee(route('impayes.detail', $creance->id), false)
            ->assertDontSee("Qui l'a touchée", false);
    }

    public function test_la_page_de_detail_ne_montre_pas_une_creance_hors_du_perimetre(): void
    {
        $horsPerimetre = $this->creance('BOU-2', 300_000, site: $this->siteBouake, dateDepot: now()->subDays(3));
        $horsPerimetre->update(['observations' => 'NOTE CONFIDENTIELLE DE BOUAKE']);

        $responsable = $this->compte('responsable_site');
        $this->site->update(['responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->get(route('impayes.detail', $horsPerimetre->id))
            ->assertOk()
            ->assertSee('Créance introuvable')
            ->assertDontSee('NOTE CONFIDENTIELLE DE BOUAKE');
    }

    public function test_modifier_depuis_la_page_de_detail_ouvre_le_formulaire(): void
    {
        $this->actingAs($this->compte('gerant'));

        $creance = $this->creance('4424', 300_000, dateDepot: now()->subDays(3));

        $this->get(route('impayes', ['modifier' => $creance->id]))
            ->assertOk()
            ->assertSee('Modifier la créance '.$creance->numero)
            ->assertSee('Enregistrer les modifications');
    }

    public function test_modifier_corrige_la_ligne_et_ajoute_un_nouveau_reglement(): void
    {
        $this->actingAs($this->compte('gerant'));

        $creance = $this->creance('4422', 300_000, dateDepot: now()->subDays(3));
        $this->encaisser($creance, 100_000);

        Volt::test('pilotage.impayes')
            ->call('modifier', $creance->id)
            ->assertSet('fNumero', '4422')
            ->set('fCommentaires', 'Relancé par téléphone')
            ->set('fMontant', '320000')
            ->set('fRegle', '20000')
            ->set('fModeReglement', 'ESPÈCE')
            ->set('fDateReglement', now()->toDateString())
            ->call('enregistrer')
            ->assertHasNoErrors();

        $creance->refresh();

        $this->assertSame('Relancé par téléphone', $creance->observations);
        $this->assertSame(320_000, $creance->montant);
        // Le règlement s'ajoute, il ne remplace pas le précédent.
        $this->assertSame(2, Encaissement::where('facture_id', $creance->id)->count());
        $this->assertSame(200_000, $creance->resteAEncaisser());
    }

    public function test_modifier_refuse_un_montant_sous_ce_qui_est_deja_encaisse(): void
    {
        $this->actingAs($this->compte('gerant'));

        $creance = $this->creance('4423', 300_000, dateDepot: now()->subDays(3));
        $this->encaisser($creance, 250_000);

        Volt::test('pilotage.impayes')
            ->call('modifier', $creance->id)
            ->set('fMontant', '200000')
            ->call('enregistrer')
            ->assertHasErrors('fMontant');

        $this->assertSame(300_000, $creance->fresh()->montant);
    }

    public function test_une_ligne_reprise_garde_sa_cle_d_import_quoi_qu_envoie_le_navigateur(): void
    {
        $this->actingAs($this->compte('gerant'));

        $lot = LotImport::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'deposant' => 'Reprise',
            'format' => 'impayes', 'nom_fichier' => 'Etats.xlsx', 'empreinte' => str_repeat('c', 64), 'etat' => 'termine',
        ]);
        $reprise = $this->creance('17', 300_000, dateDepot: now()->subDays(3));
        $reprise->forceFill(['lot_import_id' => $lot->id, 'est_etat_initial' => true])->save();

        // Un champ désactivé à l'écran n'arrête rien : la requête est réécrite ici à la main.
        Volt::test('pilotage.impayes')
            ->call('modifier', $reprise->id)
            ->set('fNumero', '99')
            ->set('fMontant', '1')
            ->set('fCommentaires', 'Contesté')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $reprise->refresh();

        $this->assertSame('17', $reprise->n_facture);
        $this->assertSame(300_000, $reprise->montant);
        $this->assertSame('Contesté', $reprise->observations);
    }

    public function test_on_ne_modifie_pas_une_creance_hors_de_son_perimetre(): void
    {
        $horsPerimetre = $this->creance('BOU-1', 300_000, site: $this->siteBouake, dateDepot: now()->subDays(3));

        // Responsable du seul atelier d'Abidjan : la créance de Bouaké ne lui appartient pas,
        // et son identifiant tapé à la main ne doit rien ouvrir.
        $responsable = $this->compte('responsable_site');
        $this->site->update(['responsable_id' => $responsable->id]);
        $this->actingAs($responsable);

        Volt::test('pilotage.impayes')
            ->call('modifier', $horsPerimetre->id)
            ->assertSet('enModification', null)
            ->set('enModification', $horsPerimetre->id)
            ->set('fClient', 'PIRATE')
            ->set('fDate', now()->subDays(5)->toDateString())
            ->set('fDateReception', now()->toDateString())
            ->set('fNumero', 'BOU-1')
            ->set('fMontant', '1')
            ->call('enregistrer')
            ->assertHasErrors('fNumero');

        $this->assertNotSame('PIRATE', $horsPerimetre->fresh()->client);
    }

    /*
    |--------------------------------------------------------------------------
    | Porter une facture existante
    |--------------------------------------------------------------------------
    */

    public function test_porter_une_facture_du_cattc_la_fait_entrer_a_l_etat_et_au_recouvrement(): void
    {
        $this->actingAs($this->compte('gerant'));

        $cattc = $this->factureDuCattc('FA -5713', 400_000, '1179JF01');

        // Avant : hors de l'état, et hors du recouvrement — elle n'apporte aucun règlement.
        $this->assertNotContains($cattc->id, Recouvrement::requete()->pluck('id')->all());

        // Le client d'abord, puis la facture parmi les siennes, par son numéro de saisie.
        Volt::test('pilotage.impayes-porter')
            ->assertSee('ALLIANZ (1)')
            ->set('client', 'ALLIANZ')
            ->assertSee($cattc->numero.' · N° FA -5713')
            ->set('factureId', (string) $cattc->id)
            ->assertSee('CATTC importé')
            // Ce que la facture sait déjà se remplit tout seul.
            ->assertSet('pSiteId', (string) $this->site->id)
            ->set('pDateReception', now()->subDays(2)->toDateString())
            ->set('pRegle', '150000')
            ->set('pModeReglement', 'VIREMENT — BGFI')
            ->set('pDateReglement', now()->toDateString())
            ->call('porter')
            ->assertHasNoErrors()
            ->assertDispatched('facture-portee');

        $cattc->refresh();

        $this->assertSame((int) $cattc->date->format('Y'), $cattc->exercice_impayes);
        $this->assertSame(now()->subDays(2)->toDateString(), $cattc->date_reception->toDateString());
        $this->assertSame('FA -5713', $cattc->n_facture, 'Porter ne renumérote rien.');
        $this->assertSame(250_000, $cattc->resteAEncaisser());
        $this->assertContains($cattc->id, Recouvrement::requete()->pluck('id')->all());
    }

    public function test_porter_exige_la_date_de_depot(): void
    {
        $this->actingAs($this->compte('gerant'));

        $cattc = $this->factureDuCattc('FA -5714', 400_000, null);

        Volt::test('pilotage.impayes-porter', ['facture' => $cattc->id])
            ->assertSet('client', 'ALLIANZ')
            ->call('porter')
            ->assertHasErrors('pDateReception');

        $this->assertNull($cattc->fresh()->exercice_impayes);
    }

    public function test_porter_depuis_une_autre_page_ouvre_le_panneau_sur_la_facture(): void
    {
        $this->actingAs($this->compte('gerant'));

        $cattc = $this->factureDuCattc('FA -5716', 400_000, null);

        $this->get(route('impayes', ['porter' => $cattc->id]))
            ->assertOk()
            ->assertSee("Porter une facture existante à l'état", false)
            ->assertSee('FA -5716');

        // Une fois portée, l'écran referme le panneau et montre l'année où elle est entrée.
        Volt::test('pilotage.impayes')
            ->call('basculerPortage')
            ->assertSet('porterOuvert', true)
            ->dispatch('facture-portee', exercice: 2025, texte: 'Facture portée.')
            ->assertSet('porterOuvert', false)
            ->assertSet('exercice', 2025)
            ->assertDispatched('annonce');
    }

    public function test_le_chiffre_d_affaires_envoie_une_facture_a_l_etat(): void
    {
        $this->actingAs($this->compte('gerant'));

        $cattc = $this->factureDuCattc('FA -5717', 400_000, null);
        $cattc->update(['date' => now()->startOfMonth()]);

        Volt::test('pilotage.chiffre-affaires')
            ->assertSee(route('impayes', ['porter' => $cattc->id]), false)
            ->assertSee("Porter à l'état", false);

        // Le responsable commercial lit le chiffre d'affaires, pas l'état des impayés : pas de
        // bouton qui le mènerait à un refus.
        $this->actingAs($this->compte('responsable_commercial'));

        Volt::test('pilotage.chiffre-affaires')
            ->assertDontSee(route('impayes', ['porter' => $cattc->id]), false);
    }

    public function test_porter_ne_montre_pas_une_facture_hors_du_perimetre(): void
    {
        $horsPerimetre = $this->factureDuCattc('FA -9999', 400_000, null);
        $horsPerimetre->update(['site_id' => $this->siteBouake->id, 'client' => 'CLIENT DE BOUAKE']);

        $responsable = $this->compte('responsable_site');
        $this->site->update(['responsable_id' => $responsable->id]);
        $this->actingAs($responsable);

        Volt::test('pilotage.impayes-porter', ['facture' => $horsPerimetre->id])
            ->assertSet('factureId', '')
            ->assertDontSee('CLIENT DE BOUAKE')
            ->set('factureId', (string) $horsPerimetre->id)
            ->set('pDateReception', now()->toDateString())
            ->call('porter')
            ->assertHasErrors('factureId');

        $this->assertNull($horsPerimetre->fresh()->exercice_impayes);
    }

    public function test_les_totaux_calcules_en_base_sont_ceux_de_la_regle(): void
    {
        $this->actingAs($this->compte('gerant'));

        $annee = (int) now()->format('Y');
        $ouverte = $this->creance('OUV', 300_000);
        $tropPercue = $this->creance('TROP', 100_000);
        $soldee = $this->creance('SOLDEE', 50_000);
        $this->encaisser($ouverte, 100_000);
        $this->encaisser($tropPercue, 130_000);
        $this->encaisser($soldee, 50_000);
        $ancienne = Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'numero' => 'IMP-ANCIENNE',
            'n_facture' => 'ANC', 'date' => now()->subYear(), 'exercice_impayes' => $annee - 1,
            'client' => 'Ancien', 'montant' => 70_000, 'activite' => 'Mécanique', 'type' => 'FNE',
        ]);

        $requete = EtatDesImpayes::requete($annee);
        $enMemoire = EtatDesImpayes::totaux((clone $requete)->withSum('encaissements', 'montant')->get(), $annee);

        $this->assertSame($enMemoire, EtatDesImpayes::totauxEnBase($requete, $annee));
        $this->assertSame(270_000, $enMemoire['reste'], 'Le trop-perçu ne rembourse pas la dette des autres.');

        $ouvertes = EtatDesImpayes::filtrerLeSolde(EtatDesImpayes::requete($annee), 'ouvertes')->pluck('id')->sort()->values()->all();
        $this->assertSame([$ouverte->id, $ancienne->id], $ouvertes);
        $soldees = EtatDesImpayes::filtrerLeSolde(EtatDesImpayes::requete($annee), 'soldees')->pluck('id')->sort()->values()->all();
        $this->assertSame([$tropPercue->id, $soldee->id], $soldees);
    }

    public function test_porter_une_facture_deja_suivie_sous_un_autre_numero_demande_confirmation(): void
    {
        $this->actingAs($this->compte('gerant'));

        // La même affaire, reprise du classeur sous « 17 » et importée du CATTC sous « FA -5715 ».
        $this->creance('17', 400_000, immatriculation: '1179 JF 01', dateDepot: now()->subDays(3));
        $cattc = $this->factureDuCattc('FA -5715', 400_000, '1179JF01');

        $composant = Volt::test('pilotage.impayes-porter', ['facture' => $cattc->id])
            ->set('pDateReception', now()->subDays(2)->toDateString())
            ->call('porter')
            ->assertHasErrors('pPasLeMemeDossier');

        $this->assertNull($cattc->fresh()->exercice_impayes);

        $composant->set('pPasLeMemeDossier', true)->call('porter')->assertHasNoErrors();

        $this->assertNotNull($cattc->fresh()->exercice_impayes);
    }

    public function test_deux_creances_se_saisissent_a_la_suite_sans_reglement(): void
    {
        /*
         * La saisie se fait en série. Après la première créance, les champs vidés par
         * `reset()` revenaient à null au lieu d'une chaîne vide, et la seconde — sans
         * règlement — se voyait réclamer un mode et une date de règlement.
         */
        $this->actingAs($this->compte('gerant'));

        $composant = Volt::test('pilotage.impayes');

        foreach (['6001', '6002'] as $numero) {
            $composant
                ->set('fDate', now()->subDays(4)->toDateString())
                ->set('fDateReception', now()->subDays(2)->toDateString())
                ->set('fNumero', $numero)
                ->set('fClient', 'SERIE')
                ->set('fSiteId', (string) $this->site->id)
                ->set('fMontant', '10000')
                ->call('enregistrer')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, Facture::where('client', 'SERIE')->count());
    }

    public function test_la_date_de_reception_est_obligatoire_a_la_saisie(): void
    {
        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes')
            ->set('fDate', now()->subDays(4)->toDateString())
            ->set('fNumero', '5001')
            ->set('fClient', 'SANS DEPOT')
            ->set('fSiteId', (string) $this->site->id)
            ->set('fMontant', '10000')
            ->call('enregistrer')
            ->assertHasErrors('fDateReception');
    }

    /*
    |--------------------------------------------------------------------------
    | Outils
    |--------------------------------------------------------------------------
    */

    private function creance(
        string $numero,
        int $montant,
        ?Site $site = null,
        ?Ville $ville = null,
        mixed $dateEdition = null,
        mixed $dateDepot = null,
        ?string $immatriculation = null,
    ): Facture {
        $site = func_num_args() >= 3 ? $site : $this->site;
        $date = $dateEdition ?? now()->subDays(10);

        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $site?->id,
            'ville_id' => $ville?->id,
            'numero' => 'IMP-'.substr(md5($numero), 0, 10),
            'n_facture' => $numero,
            'date' => $date,
            'date_reception' => $dateDepot,
            'exercice_impayes' => (int) $date->format('Y'),
            'client' => 'Client '.$numero,
            'immatriculation' => $immatriculation,
            'montant' => $montant,
            'activite' => 'Mécanique',
            'type' => 'FNE',
        ]);
    }

    private function factureDuCattc(string $numero, int $montant, ?string $immatriculation): Facture
    {
        $lot = LotImport::firstOrCreate(
            ['entreprise_id' => $this->entreprise->id, 'format' => 'factures'],
            ['ville_id' => $this->abidjan->id, 'deposant' => 'Reprise', 'nom_fichier' => 'CATTC.xlsx',
                'empreinte' => str_repeat('d', 64), 'etat' => 'termine'],
        );

        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'lot_import_id' => $lot->id,
            'site_id' => $this->site->id,
            'numero' => mb_substr($numero, 0, 20),
            'n_facture' => $numero,
            'date' => now()->subDays(12),
            'client' => 'ALLIANZ',
            'immatriculation' => $immatriculation,
            'montant' => $montant,
            'activite' => 'Mécanique',
            'type' => 'FNE',
        ]);
    }

    private function encaisser(Facture $facture, int $montant, string $moyen = 'ESPÈCE'): void
    {
        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $facture->site_id,
            'facture_id' => $facture->id, 'date' => now()->toDateString(),
            'type' => 'Client', 'moyen' => $moyen, 'montant' => $montant, 'client' => $facture->tiersPayant(),
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
            'ville_id' => $this->abidjan->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

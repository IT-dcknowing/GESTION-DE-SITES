<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Rapport\Modeles\PieceRapport;
use Modules\Noyau\Rapport\Services\AssistantDuRapport;
use Modules\Noyau\Rapport\Services\RapportFinancier;
use Modules\Noyau\Rapport\Support\PeriodeDuRapport;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Le rapport se lit par période et par lieu, et s'écrit sans abréviation ni tiret long — 25/11.
 *
 * Les demandes du propriétaire : retirer les lignes « Source : » ; plus de tiret long dans le texte
 * affiché ; « écrire correctement les montants » (en entier, exacts) ; la Conclusion avant les
 * Commerciaux ; une semaine du jeudi au mercredi, un mois ou des dates libres ; la ville, l'atelier
 * et l'activité, vérifiés au serveur ; le détail par semaine et par mois.
 *
 * Le décor, en novembre 2026 (le 25/11 est un mercredi) :
 *
 * - Abidjan (deux ateliers) et Bouaké (un atelier) ; une ville d'une autre entreprise.
 * - Des factures datées autour de la semaine du jeudi 19 au mercredi 25 novembre : le 18 (mercredi
 *   de la semaine d'avant), le 19, le 25, le 26 (jeudi de la semaine d'après), et une d'octobre.
 * - Une facture sans ville (code éditeur perdu), pour la remarque des « non rattachées ».
 */
class LeRapportSeLitParPeriodeEtParLieuTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $abj1;

    private Site $abj2;

    private Site $bke;

    private User $gerant;

    private int $rang = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 10:00:00');

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->abidjan = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
        $this->abj1 = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan Zone 4', 'est_actif' => true]);
        $this->abj2 = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->abidjan->id, 'code' => 'A2', 'nom' => 'Abidjan Yopougon', 'est_actif' => true]);
        $this->bke = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->bouake->id, 'code' => 'B1', 'nom' => 'Bouaké Centre', 'est_actif' => true]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $this->gerant = User::create([
            'entreprise_id' => $this->e->id, 'name' => 'Gérant', 'email' => 'gerant@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->abidjan->id, 'site_id' => $this->abj1->id, 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $this->gerant->assignRole('gerant');
        $this->gerant = $this->gerant->fresh();
        $this->actingAs($this->gerant);

        foreach (['voir', 'deposer', 'ia'] as $action) {
            HabilitationAction::query()->create(['domaine' => 'rapport', 'action' => $action, 'role' => null, 'user_id' => $this->gerant->id, 'accorde' => true]);
        }
        HabilitationsDActions::oublier();

        $this->facture($this->abj1, '2026-11-18', 100_000);
        $this->facture($this->abj1, '2026-11-19', 1_701_234_567.25);
        $this->facture($this->abj2, '2026-11-25', 300_000, 'Sinistre');
        $this->facture($this->bke, '2026-11-25', 50_000);
        $this->facture($this->bke, '2026-11-26', 7_000);
        $this->facture($this->bke, '2026-10-10', 20_000, 'Sinistre');
        $this->facture(null, '2026-11-20', 4_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_semaine_va_du_jeudi_au_mercredi_et_se_choisit_par_n_importe_quel_jour(): void
    {
        foreach (['2026-11-19', '2026-11-22', '2026-11-25'] as $jour) {
            $p = PeriodeDuRapport::lire('semaine', 2026, $jour);
            $this->assertSame('2026-11-19', $p->debut->toDateString(), "Le {$jour} est dans la semaine qui commence le jeudi 19.");
            $this->assertSame('2026-11-25', $p->fin->toDateString(), 'Elle finit le mercredi 25.');
        }

        $this->assertSame('2026-11-26', PeriodeDuRapport::lire('semaine', 2026, '2026-11-26')->debut->toDateString(), 'Un jeudi ouvre sa propre semaine.');

        $ca = RapportFinancier::pour($this->gerant, 2026, ['periode' => 'semaine', 'jour' => '2026-11-22'])->chiffreDAffaires();

        // Le 19, le 25 (deux factures) et la facture sans ville du 20 ; ni le mercredi 18 ni le jeudi 26.
        $this->assertSame(1_701_234_567.25 + 300_000 + 50_000 + 4_000, $ca['total']);
        $this->assertSame(4, $ca['nombre']);
        $this->assertCount(1, $ca['parSemaine'], 'Une semaine, une ligne.');
        $this->assertFalse($ca['parSemaine'][0]['partielle']);
    }

    public function test_le_mois_et_son_detail_par_semaine(): void
    {
        $ca = RapportFinancier::pour($this->gerant, 2026, ['periode' => 'mois', 'mois' => '2026-10'])->chiffreDAffaires();

        $this->assertSame(20_000, $ca['total'], 'Seule la facture d\'octobre.');
        $this->assertCount(1, $ca['parMois']);
        $this->assertTrue($ca['parMois'][0]['complet'], 'Octobre est fini et entier dans la période.');
        $this->assertSame(20_000, $ca['moyenneMensuelle']);

        // Octobre 2026 commence un jeudi et finit un samedi : cinq semaines, la dernière coupée.
        $this->assertSame('2026-10-01', $ca['parSemaine'][0]['debut']->toDateString());
        $this->assertSame('2026-10-31', end($ca['parSemaine'])['fin']->toDateString());
        $this->assertTrue(end($ca['parSemaine'])['partielle']);
        $this->assertSame(20_000, collect($ca['parSemaine'])->sum('montant'), 'Les semaines redonnent le total.');

        $novembre = RapportFinancier::pour($this->gerant, 2026, ['periode' => 'mois', 'mois' => '2026-11'])->chiffreDAffaires();
        $this->assertFalse($novembre['parMois'][0]['complet'], 'Novembre est en cours.');
    }

    public function test_les_dates_libres_sont_incluses_et_une_fin_avant_le_debut_est_ramenee(): void
    {
        $ca = RapportFinancier::pour($this->gerant, 2026, ['periode' => 'dates', 'du' => '2026-11-19', 'au' => '2026-11-20'])->chiffreDAffaires();
        $this->assertSame(1_701_234_567.25 + 4_000, $ca['total'], 'Les deux bornes comptent.');

        $p = PeriodeDuRapport::lire('dates', 2026, null, null, '2026-11-25', '2026-11-01');
        $this->assertSame(['2026-11-25', '2026-11-25'], [$p->debut->toDateString(), $p->fin->toDateString()]);

        $this->assertSame('exercice', PeriodeDuRapport::lire('nimporte', 2026)->mode, 'Un mode inconnu vaut l\'exercice.');
    }

    public function test_la_ville_l_atelier_et_l_activite_filtrent_le_chiffre_d_affaires_et_les_charges(): void
    {
        $semaine = ['periode' => 'semaine', 'jour' => '2026-11-25'];

        $bouake = RapportFinancier::pour($this->gerant, 2026, $semaine + ['ville' => $this->bouake->id])->chiffreDAffaires();
        $this->assertSame(50_000, $bouake['total'], 'Bouaké seule : la facture sans ville n\'y est pas.');

        $atelier = RapportFinancier::pour($this->gerant, 2026, $semaine + ['ville' => $this->abidjan->id, 'atelier' => $this->abj2->id])->chiffreDAffaires();
        $this->assertSame(300_000, $atelier['total']);

        $sinistre = RapportFinancier::pour($this->gerant, 2026, ['activite' => 'Sinistre'])->chiffreDAffaires();
        $this->assertSame(320_000, $sinistre['total'], 'Le Sinistre de l\'exercice, toutes villes.');

        $inconnue = RapportFinancier::pour($this->gerant, 2026, $semaine + ['activite' => 'Carrosserie'])->chiffreDAffaires();
        $this->assertSame(1_701_234_567.25 + 300_000 + 50_000 + 4_000, $inconnue['total'], 'Une activité inconnue ne filtre rien.');

        $this->assertSame(0, RapportFinancier::pour($this->gerant, 2026, $semaine + ['activite' => 'Sinistre', 'ville' => $this->bouake->id])->chiffreDAffaires()['total']);
    }

    public function test_une_ville_ou_un_atelier_hors_du_perimetre_est_refuse(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $ailleurs = Ville::create(['entreprise_id' => $autre->id, 'code' => 'SPD', 'nom' => 'San Pédro', 'est_actif' => true]);

        $this->assertFalse(RapportFinancier::lieuAutorise($this->gerant, $ailleurs->id, null));
        $this->assertFalse(RapportFinancier::lieuAutorise($this->gerant, $this->bouake->id, $this->abj1->id), 'Un atelier d\'Abidjan n\'est pas à Bouaké.');
        $this->assertTrue(RapportFinancier::lieuAutorise($this->gerant, $this->abidjan->id, $this->abj1->id));

        try {
            RapportFinancier::pour($this->gerant, 2026, ['ville' => $ailleurs->id]);
            $this->fail('Une ville hors périmètre aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        Volt::actingAs($this->gerant)->test('pilotage.rapport')->set('ville', (string) $ailleurs->id)->assertForbidden();
        // Par l'adresse : la page entière est refusée, l'identifiant forgé ne retombe pas sur « toutes les villes ».
        $this->get('/rapport?ville='.$ailleurs->id)->assertForbidden();
        $this->get('/rapport?ville='.$this->bouake->id)->assertOk();
        Volt::actingAs($this->gerant)->test('pilotage.rapport')->set('ville', (string) $this->bouake->id)->assertOk()->assertSee('Bouaké');
    }

    public function test_la_conclusion_vient_avant_les_commerciaux(): void
    {
        $page = Volt::actingAs($this->gerant)->test('pilotage.rapport');
        $cles = array_keys($page->instance()->onglets);

        $this->assertLessThan(array_search('commerciaux', $cles, true), array_search('conclusion', $cles, true));
        $page->assertSeeHtmlInOrder(["aller('charges')", "aller('conclusion')", "aller('commerciaux')"]);
        $page->assertSeeInOrder(['Conclusion', 'Trésorerie, engagements', 'Commerciaux', 'Récapitulatif par commercial']);
    }

    public function test_ni_tiret_long_ni_ligne_source_dans_aucun_onglet(): void
    {
        PieceRapport::query()->create([
            'entreprise_id' => $this->e->id, 'nature' => 'impot', 'statut' => 'du', 'exercice' => 2026, 'date_piece' => '2026-11-20',
            'libelle' => 'Droits simples', 'montant' => 174_861_255, 'compte' => true, 'saisie_manuelle' => true, 'empreinte' => 'x1',
        ]);

        foreach ([[], ['periode' => 'semaine', 'jour' => '2026-11-25'], ['periode' => 'mois', 'mois' => '2026-11', 'ville' => (string) $this->abidjan->id]] as $criteres) {
            foreach (['presentation', 'ca', 'recouvrement', 'charges', 'conclusion', 'commerciaux', 'precedent', 'depot', 'ia'] as $vue) {
                $page = Volt::actingAs($this->gerant)->test('pilotage.rapport')->set($criteres)->call('aller', $vue)->assertOk();
                $html = $page->html();

                $this->assertStringNotContainsString('—', $html, "Tiret long dans l'onglet {$vue}.");
                $this->assertStringNotContainsString('–', $html, "Tiret moyen dans l'onglet {$vue}.");
                $this->assertStringNotContainsString('Source :', $html, "Ligne « Source » dans l'onglet {$vue}.");
                $this->assertStringNotContainsString('Sources :', $html);
            }
        }

        $semaine = Volt::actingAs($this->gerant)->test('pilotage.rapport')->set(['periode' => 'semaine', 'jour' => '2026-11-22'])->call('aller', 'charges');
        $semaine->assertSee('Charges : semaine du jeudi 19/11/2026 au mercredi 25/11/2026')
            ->assertSee('Charges comptées par semaine')
            ->assertSee('Droits simples')
            ->assertSee('174 861 255');
    }

    public function test_les_montants_s_ecrivent_en_entier_et_exacts(): void
    {
        $rapport = RapportFinancier::pour($this->gerant, 2026, ['periode' => 'dates', 'du' => '2026-11-19', 'au' => '2026-11-19']);
        $textes = collect(AssistantDuRapport::commentaires($rapport->tout()))->flatten(1)->pluck('texte')->implode("\n");

        $this->assertStringContainsString('Le CA de la période est de 1 701 234 567,25 F sur 1 factures', $textes);
        $this->assertStringNotContainsString(' M ', $textes.' ');
        $this->assertStringNotContainsString('FCFA', $textes);
        $this->assertStringNotContainsString('—', $textes);

        Volt::actingAs($this->gerant)->test('pilotage.rapport')->set(['periode' => 'dates', 'du' => '2026-11-19', 'au' => '2026-11-19'])
            ->assertSee('1 701 234 567,25 F')
            ->assertDontSee('1 701,2 M')
            ->assertDontSee('M FCFA');
    }

    private function facture(?Site $site, string $date, int|float $montant, string $activite = 'Mécanique'): Facture
    {
        return Facture::create([
            'entreprise_id' => $this->e->id, 'site_id' => $site?->id, 'ville_id' => $site?->ville_id, 'numero' => 'FAC-'.(++$this->rang),
            'n_facture' => 'F-'.$this->rang, 'date' => $date, 'client' => 'Client '.$this->rang, 'montant' => $montant,
            'activite' => $activite,
        ]);
    }
}

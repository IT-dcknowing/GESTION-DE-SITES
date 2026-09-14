<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

/**
 * La période qui remplace la date libre, et les totaux qui manquaient aux téléchargements.
 *
 * **Deux défauts signalés, et ils se tenaient.** Une date tapée à la main faisait deux
 * métiers à la fois — la date de l'écriture qu'on enregistre, et l'arrêté auquel on regarde
 * le stock — si bien que reculer l'une déplaçait l'autre. Et le fichier téléchargé n'avait
 * pas la ligne de totaux que l'écran affichait : on emportait un tableau dont il fallait
 * refaire la somme.
 *
 * Ce qui est fixé ici : l'arrêté se **déduit** de la période, il ne dépasse jamais
 * aujourd'hui, une période forgée à la main retombe sur la plus large — et le total du
 * fichier refait exactement le corps du fichier.
 */
class PeriodeEtExportsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['gerant'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
        $ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'code' => 'ABJ-1', 'nom' => 'Site 1', 'est_actif' => true]);
    }

    public function test_l_annee_entiere_ne_depasse_jamais_aujourd_hui(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 11));

        $periode = PeriodeDeTravail::depuis(null, null, null);

        $this->assertSame(
            '2026-09-11',
            $periode->arrete->toDateString(),
            "Arrêter l'encours au 31 décembre d'un exercice en cours ferait entrer des factures qui n'existent pas.",
        );

        Carbon::setTestNow();
    }

    public function test_un_mois_arrete_a_sa_derniere_journee(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 11));

        $periode = PeriodeDeTravail::depuis('3', null, null);

        $this->assertSame('2026-03-31', $periode->arrete->toDateString());
        $this->assertSame('2026-03-01', $periode->debut->toDateString());
        $this->assertStringContainsString('Mars', $periode->enClair());

        Carbon::setTestNow();
    }

    public function test_un_jour_arrete_a_ce_jour_la(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 11));

        $periode = PeriodeDeTravail::depuis('3', null, '15');

        $this->assertSame('2026-03-15', $periode->arrete->toDateString());
        $this->assertSame(15, $periode->jour);

        Carbon::setTestNow();
    }

    public function test_une_periode_forgee_retombe_sur_la_plus_large(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 11));

        // Le paramètre vient de l'adresse : il se réécrit à la main. Rien de tout cela ne
        // doit produire un arrêté fantaisiste, et surtout pas un arrêté faux en silence.
        foreach ([['pas-un-mois', null, null], ['99', null, null], ['0', null, null], ['-3', null, null]] as [$m, $s, $j]) {
            $periode = PeriodeDeTravail::depuis($m, $s, $j);

            $this->assertNull($periode->mois, 'Un mois hors des douze retombe sur l\'année entière.');
            $this->assertSame('2026-09-11', $periode->arrete->toDateString());
        }

        // Une semaine sans mois n'a pas de sens : elle est ignorée, pas devinée.
        $this->assertNull(PeriodeDeTravail::depuis(null, '2', null)->semaine);

        Carbon::setTestNow();
    }

    public function test_les_parametres_ne_transportent_que_ce_qui_est_choisi(): void
    {
        $this->assertSame([], PeriodeDeTravail::depuis(null, null, null)->parametres());
        $this->assertSame(['moisFiltre' => 3], PeriodeDeTravail::depuis('3', null, null)->parametres());
    }

    public function test_le_pdf_de_la_balance_porte_sa_ligne_de_totaux(): void
    {
        $this->facture('NSIA ASSURANCES', 'F-001', 300_000);
        $this->facture('GNA ASSURANCES', 'F-002', 200_000);

        $reponse = $this->actingAs($this->gerant())
            ->get(route('recouvrement.telecharger', ['document' => 'balance', 'format' => 'pdf']));

        $reponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $reponse->headers->get('content-type'));
        // Le PDF est une réponse ordinaire, pas un flux : son contenu se lit directement.
        // « TOTAL » y figure en clair, le texte n'étant pas comprimé dans nos documents.
        $this->assertStringContainsString('TOTAL', $reponse->getContent());
    }

    public function test_le_classeur_porte_le_total_en_nombre_et_il_refait_le_corps(): void
    {
        $this->facture('NSIA ASSURANCES', 'F-001', 300_000);
        $this->facture('GNA ASSURANCES', 'F-002', 200_000);

        $reponse = $this->actingAs($this->gerant())
            ->get(route('recouvrement.telecharger', ['document' => 'balance', 'format' => 'excel']));

        $reponse->assertOk();

        $chemin = tempnam(sys_get_temp_dir(), 'essai').'.xlsx';
        file_put_contents($chemin, $reponse->streamedContent());

        $archive = new ZipArchive;
        $archive->open($chemin);
        $feuille = (string) $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();
        @unlink($chemin);

        $this->assertStringContainsString('TOTAL', $feuille);

        // Le montant sort en **nombre**, pas en texte : un tableau qu'on ne peut pas
        // additionner à l'arrivée n'a pas rendu service.
        $this->assertStringContainsString('<v>500000</v>', $feuille);
    }

    public function test_le_word_porte_lui_aussi_son_total(): void
    {
        $this->facture('NSIA ASSURANCES', 'F-001', 300_000);

        $reponse = $this->actingAs($this->gerant())
            ->get(route('recouvrement.telecharger', ['document' => 'balance', 'format' => 'word']));

        $reponse->assertOk();
        $this->assertStringContainsString('tr class="tot"', $reponse->getContent());
        $this->assertStringContainsString('TOTAL', $reponse->getContent());
    }

    public function test_un_tableau_vide_n_affiche_pas_un_total_a_zero(): void
    {
        // Une ligne noire portant « 0 » là où il n'y a rien ferait chercher ce qu'on a
        // filtré de travers : on ne la pose pas.
        $reponse = $this->actingAs($this->gerant())
            ->get(route('recouvrement.telecharger', ['document' => 'balance', 'format' => 'word']));

        $reponse->assertOk();
        $this->assertStringNotContainsString('tr class="tot"', $reponse->getContent());
    }

    private function facture(string $tiers, string $numero, int $montant): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'client' => $tiers,
            'assureur' => $tiers,
            'n_facture' => $numero,
            'date' => now()->subDays(40),
            'montant' => $montant,
            'activite' => 'Sinistre',
            'type' => 'Facture',
        ]);
    }

    private function gerant(): User
    {
        $u = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Gérant',
            'email' => 'gerant@periode.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $u->assignRole('gerant');

        return $u->fresh();
    }
}

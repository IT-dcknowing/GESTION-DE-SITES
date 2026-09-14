<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

/**
 * Les documents emportés portent enfin l'identité de la maison — et se relisent.
 *
 * **Trois défauts, signalés sur des captures, et une cause commune à deux d'entre eux.**
 *
 * Le document Word affichait « par tranche d&apos;ancienneté » en toutes lettres. `&apos;`
 * est une entité XML parfaitement valide, que le lecteur HTML de Word ne connaît pas : le
 * texte était échappé pour du XML et servi comme du HTML. Ses montants se cassaient en
 * morceaux, « 697 027 » sur trois lignes, faute d'interdire la coupure dans une cellule
 * de nombres.
 *
 * Le PDF tronquait ses montants avec des points de suspension : dix colonnes sur une page
 * debout laissent moins de cinquante points chacune. L'orientation suit désormais le
 * tableau.
 *
 * Et aucun des trois n'avait d'en-tête : reçu par un assureur, l'état ne disait pas qui
 * réclame.
 */
class EnTeteDesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('gerant', 'web');

        $this->entreprise = Entreprise::create([
            'nom' => "L'Artisan Automobile", 'slug' => 'artisan', 'est_active' => true,
        ]);
        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Site 1', 'est_actif' => true,
        ]);

        $this->facture('NSIA ASSURANCES', 'F-001', 697_027);
        $this->facture('GNA ASSURANCES', 'F-002', 25_226_722);
    }

    public function test_le_word_ne_sert_plus_d_entites_xml_a_un_lecteur_html(): void
    {
        $html = $this->emporter('balance', 'word')->getContent();

        // C'est très exactement ce que montrait la capture : « d&apos;ancienneté ».
        $this->assertStringNotContainsString('&apos;', $html);
        $this->assertStringContainsString('&#039;', $html);
    }

    public function test_le_word_porte_l_en_tete_de_la_maison(): void
    {
        $html = $this->emporter('balance', 'word')->getContent();

        $this->assertStringContainsString("L'Artisan Automobile", html_entity_decode($html));
        $this->assertStringContainsString('class="ent"', $html);
        $this->assertStringContainsString('Balance âgée', html_entity_decode($html));
    }

    public function test_un_montant_ne_se_coupe_jamais_en_morceaux(): void
    {
        $html = $this->emporter('balance', 'word')->getContent();

        // La cause du « 697 » au-dessus de « 027 » : rien n'interdisait la coupure.
        $this->assertStringContainsString('white-space:nowrap', $html);
    }

    public function test_un_tableau_large_bascule_en_paysage(): void
    {
        // La balance âgée porte neuf colonnes : debout, chaque colonne tombe sous
        // cinquante points et les montants sortent tronqués.
        $pdf = $this->emporter('balance', 'pdf')->getContent();

        $this->assertMatchesRegularExpression('/MediaBox \[0 0 841\.89 595\.28\]/', $pdf);
        $this->assertStringNotContainsString('...', $pdf);
    }

    public function test_le_pdf_porte_le_nom_de_la_maison_et_son_logo_s_il_existe(): void
    {
        $pdf = $this->emporter('balance', 'pdf')->getContent();

        $this->assertStringContainsString("L'Artisan Automobile", $pdf);
        $this->assertStringContainsString('TOTAL', $pdf);
    }

    public function test_le_classeur_porte_son_bandeau_et_ses_largeurs_de_colonnes(): void
    {
        $reponse = $this->emporter('balance', 'excel');

        $chemin = tempnam(sys_get_temp_dir(), 'essai').'.xlsx';
        file_put_contents($chemin, $reponse->streamedContent());

        $archive = new ZipArchive;
        $archive->open($chemin);
        $feuille = (string) $archive->getFromName('xl/worksheets/sheet1.xml');
        $styles = (string) $archive->getFromName('xl/styles.xml');
        $archive->close();
        @unlink($chemin);

        // L'ordre imposé par le schéma : les vues, puis les colonnes, puis les données.
        // Inversé, le classeur devient suspect au tableur.
        $this->assertTrue(
            strpos($feuille, '<sheetViews') < strpos($feuille, '<cols'),
            'Les vues doivent précéder les colonnes.',
        );
        $this->assertTrue(strpos($feuille, '<mergeCells') > strpos($feuille, '</sheetData>'));

        $this->assertStringContainsString('customWidth="1"', $feuille);
        $this->assertStringContainsString('<mergeCell', $feuille);
        $this->assertStringContainsString("L'Artisan Automobile", html_entity_decode($feuille, ENT_QUOTES | ENT_XML1));

        // Le séparateur de milliers : neuf chiffres collés se relisent deux fois.
        $this->assertStringContainsString('formatCode="#,##0"', $styles);
    }

    private function emporter(string $document, string $format)
    {
        return $this->actingAs($this->gerant())
            ->get(route('recouvrement.telecharger', ['document' => $document, 'format' => $format]))
            ->assertOk();
    }

    private function facture(string $tiers, string $numero, int $montant): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'client' => $tiers,
            'assureur' => $tiers,
            'n_facture' => $numero,
            'date' => now()->subDays(45),
            'montant' => $montant,
            'activite' => 'Sinistre',
            'type' => 'Facture',
        ]);
    }

    private function gerant(): User
    {
        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Gérant',
            'email' => 'gerant@documents.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

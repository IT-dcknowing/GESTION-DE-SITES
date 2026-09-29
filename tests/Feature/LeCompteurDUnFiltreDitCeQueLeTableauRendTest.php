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
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le nombre entre parenthèses est celui que le tableau rendra.
 *
 * **Le défaut, relevé le 28/09 : « les éléments entre parenthèses ne reflètent pas la
 * réalité dans le tableau ».** Exact, et l'écart était énorme — le filtre annonçait
 * « Réglées (85) » au-dessus d'un tableau qui affichait « Détail des factures (2) ».
 *
 * **La cause.** Les compteurs partaient de la requête de base, qui ne porte ni le filtre
 * d'état ni celui d'origine, alors que le tableau applique les deux. « Réglées (85) »
 * voulait donc dire « 85 réglées toutes origines confondues », pendant que le tableau ne
 * montrait que les réglées d'une seule origine. Deux questions, deux nombres, et rien pour
 * dire lequel répondait à quoi.
 *
 * **La règle.** Un compteur de filtre ne peut dire qu'une chose pour être utile : *si je
 * choisis celui-ci, combien de lignes vais-je voir ?* Il porte donc **tous les autres
 * filtres, et pas le sien**.
 */
class LeCompteurDUnFiltreDitCeQueLeTableauRendTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    private LotImport $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);

        $this->lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'deposant' => 'Reprise', 'format' => 'factures', 'nom_fichier' => 'cattc.xlsx',
            'empreinte' => str_repeat('a', 64), 'etat' => 'termine',
        ]);
    }

    /**
     * Le compteur d'état tient compte du filtre d'origine.
     *
     * C'est le cas exact de la capture du 28/09 : une réglée importée, une réglée saisie,
     * et le filtre annonçait deux là où le tableau n'en montrait qu'une.
     */
    public function test_le_compteur_d_etat_porte_le_filtre_d_origine(): void
    {
        $this->facture('F-001', 100_000, reglee: true, importee: true);
        $this->facture('F-002', 100_000, reglee: true, importee: false);
        $this->facture('F-003', 100_000, reglee: false, importee: true);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.chiffre-affaires');

        // Toutes origines : deux réglées.
        $this->assertSame(2, $ecran->instance()->comptesParEtat['reglee']);

        // Restreint aux reprises : une seule — et c'est ce que le tableau rendra.
        $ecran->set('origineFiltre', 'import');

        $this->assertSame(1, $ecran->instance()->comptesParEtat['reglee']);

        $ecran->set('etatImpayesFiltre', 'reglee');

        $this->assertSame(
            $ecran->instance()->comptesParEtat['reglee'],
            $ecran->instance()->nombreDetail,
            'Le nombre annoncé doit être celui que le tableau affiche.',
        );
    }

    /** Et réciproquement : le compteur d'origine tient compte du filtre d'état. */
    public function test_le_compteur_d_origine_porte_le_filtre_d_etat(): void
    {
        $this->facture('F-001', 100_000, reglee: true, importee: true);
        $this->facture('F-002', 100_000, reglee: false, importee: true);
        $this->facture('F-003', 100_000, reglee: false, importee: false);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.chiffre-affaires');

        $this->assertSame(2, $ecran->instance()->comptesParOrigine['import']);

        $ecran->set('etatImpayesFiltre', 'reglee');

        $this->assertSame(1, $ecran->instance()->comptesParOrigine['import']);

        $ecran->set('origineFiltre', 'import');

        $this->assertSame(
            $ecran->instance()->comptesParOrigine['import'],
            $ecran->instance()->nombreDetail,
        );
    }

    /** « Toutes » compte aussi, et compte ce que le tableau rendrait sans ce filtre. */
    public function test_le_choix_toutes_annonce_son_propre_nombre(): void
    {
        $this->facture('F-001', 100_000, reglee: true, importee: true);
        $this->facture('F-002', 100_000, reglee: false, importee: false);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.chiffre-affaires')
            ->set('origineFiltre', 'import');

        $this->assertSame(1, $ecran->instance()->comptesParEtat['toutes']);
        $this->assertSame(1, $ecran->instance()->nombreDetail);
    }

    /**
     * Une facture sans atelier entre quand même dans le chiffre d'affaires.
     *
     * **Le défaut, relevé le 29/09** : « pourquoi dans cette page la valeur portée à
     * l'état est 0 alors que j'ai des factures qui sont marquées porté à l'état ? »
     *
     * L'écran retenait ses lignes par `whereIn('site_id', …)`, et un `site_id` nul n'entre
     * dans aucun `whereIn`. Or **8 848 des 8 852 factures portées à l'état n'ont pas
     * d'atelier** : la colonne SITE des exports dit « ABIDJAN », et Abidjan en a deux, si
     * bien que l'import s'arrête à la ville.
     *
     * Le compteur à zéro n'était donc que le symptôme. Le vrai dégât est plus grave :
     * **le chiffre d'affaires lui-même** ne comptait pas ces factures — 2 021 sur 4 412
     * pour le seul exercice 2026. Le total de l'écran n'était pas le chiffre d'affaires de
     * l'entreprise, mais celui de ses factures rattachées à un atelier.
     */
    public function test_une_facture_sans_atelier_compte_dans_le_chiffre_d_affaires(): void
    {
        $avecAtelier = $this->facture('F-001', 100_000, reglee: false, importee: true);

        // Celle que la colonne SITE n'a pas su ranger : sa ville est connue, son atelier non.
        $sansAtelier = $this->facture('F-002', 400_000, reglee: false, importee: true);
        $sansAtelier->forceFill([
            'site_id' => null,
            'ville_id' => $this->site->ville_id,
            'exercice_impayes' => now()->year,
        ])->save();

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.chiffre-affaires');

        $this->assertSame(2, $ecran->instance()->nombreDetail, 'Les deux factures doivent paraître.');
        $this->assertSame(500_000, $ecran->instance()->kpis['total'], "Le chiffre d'affaires les compte toutes.");

        // Et le filtre annonce enfin ce qu'il trouvera.
        $this->assertSame(1, $ecran->instance()->comptesParEtat['portee']);

        $ecran->set('etatImpayesFiltre', 'portee');
        $this->assertSame(1, $ecran->instance()->nombreDetail);
        $ecran->assertSee('F-002');
    }

    // ------------------------------------------------------------------ le décor

    private function facture(string $numero, int $montant, bool $reglee, bool $importee): Facture
    {
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'lot_import_id' => $importee ? $this->lot->id : null,
            'date' => now()->subDays(5),
            'n_facture' => $numero,
            'client' => 'MITRELLI CI',
            'activite' => 'Mécanique',
            'montant' => $montant,
        ]);

        if ($reglee) {
            Encaissement::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entreprise->id,
                'site_id' => $this->site->id,
                'facture_id' => $facture->id,
                'date' => now()->subDays(2),
                'type' => 'Client',
                'activite' => 'Mécanique',
                'moyen' => 'CHÈQUE',
                'montant' => $montant,
                'client' => 'MITRELLI CI',
            ]);
        }

        return $facture;
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->site->ville_id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

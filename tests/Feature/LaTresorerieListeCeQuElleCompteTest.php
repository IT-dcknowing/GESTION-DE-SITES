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
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La trésorerie liste ce qu'elle compte.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Le défaut, relevé le 02/10 : l'écran se contredisait à dix centimètres d'intervalle.**
 * Le journal de caisse était entré dans les totaux le 01/10 — c'est de l'argent réellement
 * sorti du tiroir — mais les deux tableaux du bas ne lisaient que `encaissements` et
 * `charges`. Sur le serveur, cela donnait **« Décaissements (0) »** sous un total de sorties
 * de 436 783 059 F.
 *
 * *« Les encaissements et décaissements doivent enregistrer ceux importés, c'est juste qu'il
 * les liste, c'est tout. La tréso prend tout, pas seulement ceux d'ici. »*
 *
 * Ce fichier tient la règle dans les deux sens : **ce qui est compté est listé**, et une
 * ligne listée se dit d'où elle vient.
 */
class LaTresorerieListeCeQuElleCompteTest extends TestCase
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

    /**
     * Une sortie de caisse apparaît dans les décaissements, alors qu'il n'y a aucune charge.
     *
     * C'est exactement la capture du serveur : « Décaissements (0) » sous un total de sorties
     * non nul. Le tableau était vide parce qu'il ne lisait qu'une des deux sources.
     */
    public function test_une_sortie_de_caisse_est_listee_meme_sans_aucune_charge(): void
    {
        $this->mouvement(MouvementCaisse::SORTIE, 80_000, 'Achat de carburant');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie');

        $this->assertSame(0, Charge::withoutGlobalScopes()->count(), 'Le test ne vaut que sans charge.');
        $this->assertSame(1, $ecran->instance()->nombreDecaissements);

        $ligne = collect($ecran->instance()->detailDecaissements)->first();

        $this->assertSame(80_000, (int) $ligne->montant);
        $this->assertSame('Achat de carburant', $ligne->libelle);
        $this->assertTrue($ligne->vientDuJournal);
    }

    /** Une entrée de caisse rejoint les encaissements, et se dit journal. */
    public function test_une_entree_de_caisse_est_listee_avec_les_encaissements(): void
    {
        $this->encaissement(500_000);
        $this->mouvement(MouvementCaisse::ENTREE, 120_000, 'Règlement comptoir');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie');

        $this->assertSame(2, $ecran->instance()->nombreEncaissements);

        $origines = collect($ecran->instance()->detailEncaissements)
            ->mapWithKeys(fn ($l) => [(int) $l->montant => $l->vientDuJournal]);

        $this->assertFalse($origines[500_000]);
        $this->assertTrue($origines[120_000]);
    }

    /**
     * Ce que l'écran compte en haut, il le liste en bas.
     *
     * C'est la règle, et c'est elle qu'on tient plutôt qu'un chiffre : les deux nombres
     * viennent de deux chemins différents, et c'est leur accord qui vaut.
     */
    public function test_le_total_des_sorties_est_celui_des_lignes_listees(): void
    {
        $this->charge(30_000);
        $this->mouvement(MouvementCaisse::SORTIE, 80_000, 'Carburant');
        $this->mouvement(MouvementCaisse::SORTIE, 15_000, 'Transport');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie');

        $this->assertSame(3, $ecran->instance()->nombreDecaissements);
        $this->assertSame(
            125_000,
            (int) collect($ecran->instance()->detailDecaissements)->sum('montant'),
            'La somme des lignes listées doit être celle que les indicateurs annoncent.',
        );
        $this->assertSame(125_000, $ecran->instance()->kpis['decaisse']);
    }

    /**
     * La liste est triée du plus récent au plus ancien, les deux sources mêlées.
     *
     * Trier chaque source puis les recoller donnerait deux listes l'une après l'autre, pas
     * une liste. C'est pour cela que le tri se fait sur l'union, en base.
     */
    public function test_les_deux_sources_sont_melees_dans_un_seul_ordre(): void
    {
        $this->charge(10_000, '2026-03-01');
        $this->mouvement(MouvementCaisse::SORTIE, 20_000, 'Au milieu', '2026-03-02');
        $this->charge(30_000, '2026-03-03');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie');

        $this->assertSame(
            [30_000, 20_000, 10_000],
            collect($ecran->instance()->detailDecaissements)->map(fn ($l) => (int) $l->montant)->all(),
        );
    }

    /**
     * Un filtre sur une colonne que le journal ne porte pas l'écarte.
     *
     * Une ligne sans « type d'encaissement » ne peut pas satisfaire un filtre sur ce type.
     * L'y laisser serait mentir sur ce qu'on a filtré.
     */
    public function test_un_filtre_sur_une_colonne_absente_du_journal_lecarte(): void
    {
        $this->encaissement(500_000);
        $this->mouvement(MouvementCaisse::ENTREE, 120_000, 'Règlement comptoir');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie')
            ->set('filtresLibres', ['encaissements__type' => ['valeur' => 'Client']]);

        $this->assertSame(1, $ecran->instance()->nombreEncaissements);
        $this->assertFalse(collect($ecran->instance()->detailEncaissements)->first()->vientDuJournal);
    }

    /**
     * Un filtre sur une colonne qu'il porte s'applique à lui aussi.
     *
     * **La faute que ce test empêche**, et que j'ai commise en écrivant le service : écarter
     * le journal quand un filtre ne le concerne pas, mais oublier de lui appliquer ceux qui
     * le concernent. Toutes ses lignes seraient alors passées sous un filtre qu'on croyait
     * avoir posé — et cela ne se serait vu qu'à l'usage, sur un écran d'argent.
     */
    public function test_un_filtre_sur_une_colonne_quil_porte_sapplique_a_lui_aussi(): void
    {
        $this->mouvement(MouvementCaisse::ENTREE, 120_000, 'Comptoir', beneficiaire: 'KOUASSI');
        $this->mouvement(MouvementCaisse::ENTREE, 90_000, 'Comptoir', beneficiaire: 'TOUSSAINT');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie')
            ->set('filtresLibres', ['encaissements__client' => ['valeur' => 'KOUASSI']]);

        $this->assertSame(1, $ecran->instance()->nombreEncaissements);
        $this->assertSame(120_000, (int) collect($ecran->instance()->detailEncaissements)->first()->montant);
    }

    /** Filtrer « Espèces » ne doit pas faire disparaître le journal, qui n'en porte pas d'autre. */
    public function test_filtrer_sur_especes_garde_le_journal(): void
    {
        $this->mouvement(MouvementCaisse::ENTREE, 120_000, 'Comptoir');

        $ecran = Volt::actingAs($this->gerant())->test('pilotage.tresorerie')
            ->set('filtresLibres', ['encaissements__moyen' => ['valeur' => 'Espèce']]);

        $this->assertSame(1, $ecran->instance()->nombreEncaissements);
    }

    private function mouvement(string $sens, int $montant, string $libelle, string $date = '2026-03-02', string $beneficiaire = 'TOUSSAINT'): MouvementCaisse
    {
        return MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'sens' => $sens,
            'montant' => $montant,
            'libelle' => $libelle,
            'beneficiaire' => $beneficiaire,
        ]);
    }

    private function charge(int $montant, string $date = '2026-03-02'): Charge
    {
        return Charge::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'type_operation' => 'Charges',
            'libelle' => 'Fonctionnement',
            'moyen' => 'Espèces',
            'montant' => $montant,
        ]);
    }

    private function encaissement(int $montant, string $date = '2026-03-02'): Encaissement
    {
        return Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'type' => 'Client',
            'moyen' => 'Chèque',
            'montant' => $montant,
            'client' => 'ACTIVA',
        ]);
    }

    private function gerant(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => 'gerant@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole('gerant');

        return $compte->fresh();
    }
}

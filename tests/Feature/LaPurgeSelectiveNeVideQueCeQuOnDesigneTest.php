<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Actions\PurgeParModule;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La purge sélective ne vide que ce qu'on désigne.
 *
 * **Demandé le 01/10** : *« Fais des cases à cocher des pages ayant des données, et dès que
 * les pages seront cochées et supprimées, les données seront supprimées […] classer par
 * module […] un bouton tout cocher au niveau de chaque module […] envoyer un message de
 * confirmation et dire ce qui sera vraiment supprimé. »*
 *
 * **Pourquoi ce fichier est écrit plus serré que les autres.** C'est le geste le plus
 * dangereux de l'application, et il s'exerce sur une base qui porte des données réelles. Un
 * test qui ne vérifierait que « ça supprime » serait inutile : ce qu'il faut tenir, c'est
 * que **ça ne supprime rien d'autre**.
 *
 * Quatre garanties, et chacune a son test :
 *
 * 1. ce qui n'est pas coché reste ;
 * 2. rien ne part sans que le nom de l'entreprise ait été retapé ;
 * 3. une autre entreprise n'est jamais touchée ;
 * 4. ce que la boîte annonce est exactement ce qui part.
 */
class LaPurgeSelectiveNeVideQueCeQuOnDesigneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Entreprise $voisine;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->voisine = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------ le service

    /** Chaque ensemble est compté avant d'être proposé : on ne coche pas à l'aveugle. */
    public function test_les_volumes_sont_comptes_ensemble_par_ensemble(): void
    {
        $this->prospection();
        $this->facture();
        $this->facture();

        $volumes = PurgeParModule::volumes($this->entreprise->id);

        $this->assertSame(1, $volumes['prospections']);
        $this->assertSame(2, $volumes['factures']);
        $this->assertSame(0, $volumes['devis']);
    }

    /**
     * Ce qui n'est pas coché reste — la garantie qui fait tout l'intérêt du geste.
     */
    public function test_ce_qui_n_est_pas_coche_reste(): void
    {
        $this->prospection();
        $this->facture();

        (new PurgeParModule)->executer($this->entreprise, ['prospections']);

        $this->assertSame(0, Prospection::withoutGlobalScopes()->count());
        $this->assertSame(1, Facture::withoutGlobalScopes()->count(), 'La facture n’était pas cochée.');
    }

    /**
     * Une autre entreprise n'est jamais touchée.
     *
     * La plateforme est multi-entreprises : une purge qui déborderait viderait les données
     * de quelqu'un qui n'a rien demandé, et rien ne le signalerait avant longtemps.
     */
    public function test_une_autre_entreprise_n_est_jamais_touchee(): void
    {
        $this->prospection();
        $this->prospection(chez: $this->voisine);

        (new PurgeParModule)->executer($this->entreprise, ['prospections']);

        $this->assertSame(
            1,
            Prospection::withoutGlobalScopes()->where('entreprise_id', $this->voisine->id)->count(),
            'La voisine garde les siennes.',
        );
    }

    /**
     * Supprimer les devis **délie** les factures sans les supprimer — et c'est annoncé.
     *
     * C'est la différence entre perdre une ligne et perdre un lien. L'écran le dit à côté de
     * la case, et la boîte de confirmation le répète : découvrir après coup qu'une facture
     * ne sait plus de quel devis elle vient serait le genre de surprise qu'on ne répare pas.
     */
    public function test_supprimer_les_devis_delie_les_factures_sans_les_supprimer(): void
    {
        $devis = $this->devis();
        $facture = $this->facture();
        $facture->forceFill(['devis_id' => $devis->id])->save();

        $this->assertNotNull(PurgeParModule::lots()['devis']['entraine'] ?? null,
            'L’entraînement doit être déclaré, pas découvert.');

        (new PurgeParModule)->executer($this->entreprise, ['devis']);

        $facture->refresh();

        $this->assertSame(1, Facture::withoutGlobalScopes()->count(), 'La facture reste.');
        $this->assertNull($facture->devis_id, 'Mais elle ne désigne plus de devis.');
    }

    /** Un ensemble inconnu est ignoré, et un choix vide refusé. */
    public function test_un_choix_vide_est_refuse(): void
    {
        $this->prospection();

        $this->expectException(\RuntimeException::class);

        (new PurgeParModule)->executer($this->entreprise, ['ensemble-qui-n-existe-pas']);
    }

    // ------------------------------------------------------------------ l'écran

    /**
     * Rien ne part sans que le nom de l'entreprise ait été retapé.
     *
     * Ce n'est pas une formalité : c'est le seul moment où la personne écrit elle-même ce
     * qu'elle vise, et c'est ce qui distingue un clic d'une décision.
     */
    public function test_rien_ne_part_sans_le_nom_retape(): void
    {
        $this->prospection();

        Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->set('choixId', (string) $this->entreprise->id)
            ->set('lotsChoisis.prospections', true)
            ->set('confirmationChoix', 'Alph')
            ->call('viderLesChoisis')
            ->assertHasErrors('confirmationChoix');

        $this->assertSame(1, Prospection::withoutGlobalScopes()->count());
    }

    /** Et une case cochée sur un ensemble vide ne vaut pas un choix. */
    public function test_cocher_un_ensemble_vide_ne_vaut_pas_un_choix(): void
    {
        Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->set('choixId', (string) $this->entreprise->id)
            // Aucune prospection en base : la case est cochée, l'ensemble est vide.
            ->set('lotsChoisis.prospections', true)
            ->set('confirmationChoix', 'Alpha')
            ->call('viderLesChoisis')
            ->assertHasErrors('lotsChoisis');
    }

    /**
     * Un volume annoncé par le navigateur ne commande rien.
     *
     * **Pourquoi ce test existe depuis le 02/10.** Les volumes étaient recomptés à chaque
     * clic, ce qui rendait les cases à cocher lentes — vingt et un `count(*)` pour une case.
     * Ils sont désormais retenus dans une propriété, et une propriété Livewire fait
     * l'aller-retour par le navigateur : on peut lui faire dire ce qu'on veut.
     *
     * La rapidité n'a donc pas le droit d'être payée en confiance. Le geste qui supprime
     * recompte en base avant de décider ce qu'il retient, et c'est ce que ce test tient :
     * un ensemble vide qu'on annonce plein reste refusé.
     */
    public function test_un_volume_annonce_par_le_navigateur_ne_commande_rien(): void
    {
        Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->set('choixId', (string) $this->entreprise->id)
            // Aucune prospection en base, et pourtant le navigateur en annonce mille.
            ->set('volumesLots.prospections', 1000)
            ->set('lotsChoisis.prospections', true)
            ->set('confirmationChoix', 'Alpha')
            ->call('viderLesChoisis')
            ->assertHasErrors('lotsChoisis');
    }

    /**
     * Le récapitulatif dit exactement ce qui part — c'est ce que la boîte affiche.
     *
     * « Envoyer un message de confirmation et dire ce qui sera vraiment supprimé. » Un écran
     * qui demande « êtes-vous sûr ? » sans dire de quoi ne protège de rien.
     */
    public function test_le_recapitulatif_dit_ce_qui_part(): void
    {
        $this->prospection();
        $this->facture();
        $this->facture();

        $ecran = Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->set('choixId', (string) $this->entreprise->id)
            ->set('lotsChoisis.prospections', true)
            ->set('lotsChoisis.factures', true);

        $recap = $ecran->instance()->recapitulatif;

        $this->assertSame(3, $recap['total']);
        $this->assertCount(2, $recap['lignes']);
        $this->assertStringContainsString('1 ligne(s)', $recap['lignes'][0]);
        $this->assertStringContainsString('2 ligne(s)', $recap['lignes'][1]);
        // L'entraînement des factures sur les encaissements est annoncé.
        $this->assertNotEmpty($recap['entraines']);
    }

    /** Le bouton d'un module coche tout ce qu'il porte, puis le décoche. */
    public function test_le_bouton_du_module_coche_puis_decoche_tout(): void
    {
        $this->prospection();
        $this->facture();

        $ecran = Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->set('choixId', (string) $this->entreprise->id)
            ->call('basculerLeModule', 'exploitation');

        // Les trois ensembles du module qui portent quelque chose — la fiche commerciale
        // comprise, puisque la prospection en a exigé une.
        $this->assertSame(['prospections', 'factures', 'commerciaux'], $ecran->instance()->lotsRetenus);

        $ecran->call('basculerLeModule', 'exploitation');

        $this->assertSame([], $ecran->instance()->lotsRetenus);
    }

    /** Et le geste complet vide ce qui est coché, et le journal en garde la trace. */
    public function test_le_geste_complet_vide_et_se_trace(): void
    {
        $this->prospection();
        $this->facture();

        Volt::actingAs($this->superAdmin())->test('superadmin.maintenance')
            ->set('choixId', (string) $this->entreprise->id)
            ->set('lotsChoisis.prospections', true)
            ->set('confirmationChoix', 'Alpha')
            ->call('viderLesChoisis')
            ->assertHasNoErrors();

        $this->assertSame(0, Prospection::withoutGlobalScopes()->count());
        $this->assertSame(1, Facture::withoutGlobalScopes()->count());

        $this->assertDatabaseHas('activity_log', ['description' => 'Purge sélective des données']);
    }

    // ------------------------------------------------------------------ le décor

    private ?Commercial $commercial = null;

    private function commercial(): Commercial
    {
        return $this->commercial ??= Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'numero' => 'C-0001',
            'nom' => 'KOUASSI Jean',
            'statut' => 'Actif',
        ]);
    }

    private function prospection(?Entreprise $chez = null): Prospection
    {
        $chez ??= $this->entreprise;

        return Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $chez->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial()->id,
            'date' => now()->toDateString(),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Mécanique',
            'moyen' => 'RDV',
            'statut_validation' => 'Validée',
            'numero' => 'P-'.Prospection::withoutGlobalScopes()->count(),
        ]);
    }

    private function devis(): Devis
    {
        return Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date_emission' => now()->toDateString(),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Mécanique',
            'numero' => 'D-'.Devis::withoutGlobalScopes()->count(),
            'montant_devis' => 500_000,
        ]);
    }

    private function facture(): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'n_facture' => 'F-'.Facture::withoutGlobalScopes()->count(),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Mécanique',
            'montant' => 500_000,
        ]);
    }

    /**
     * Le compte de la plateforme.
     *
     * Son rôle appartient à l'équipe plateforme et non à une entreprise : c'est ce qui lui
     * permet d'agir sur toutes, et c'est aussi pourquoi il faut le créer explicitement — le
     * provisionneur d'entreprise ne le pose pas.
     */
    private function superAdmin(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(SuperAdminSeeder::EQUIPE_PLATEFORME);

        Role::firstOrCreate([
            'name' => 'super_admin', 'guard_name' => 'web',
            'entreprise_id' => SuperAdminSeeder::EQUIPE_PLATEFORME,
        ]);

        $compte = User::create([
            'entreprise_id' => null,
            'name' => 'DC Knowing',
            'email' => 'super@dc-knowing.test',
            'password' => Hash::make('motdepasse123'),
            'est_actif' => true,
            'est_fondateur' => true,
        ]);

        $compte->assignRole('super_admin');

        return $compte->fresh();
    }
}

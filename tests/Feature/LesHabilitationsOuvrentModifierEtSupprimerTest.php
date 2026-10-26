<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\SuppressionDUneCreance;
use Modules\Noyau\Exploitation\Services\SuppressionDUnePieceFournisseur;
use Modules\Noyau\Imports\Modeles\FactureFne;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Services\AchatsFne;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les habilitations de modification et de suppression — 26/10.
 *
 * « Au niveau de la maintenance, une section me permettant de donner des habilitations de
 * suppression et de modification à certains rôles déjà créés ou à certains utilisateurs
 * précisément ; les boutons apparaissent chez ceux qui les ont. »
 */
class LesHabilitationsOuvrentModifierEtSupprimerTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        // Le Super admin d'abord : il vit hors des entreprises (équipe 0) ; les comptes ensuite.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::create([
            'name' => 'Fondateur', 'email' => 'fondateur@plateforme.test', 'password' => 'mot-de-passe-de-test',
            'est_actif' => true, 'est_fondateur' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    public function test_sans_habilitation_le_code_decide_comme_avant(): void
    {
        $creance = $this->creance('F-1');

        $this->assertNull(SuppressionDUneCreance::refus($this->compte('gerant'), $creance));
        $this->assertNotNull(SuppressionDUneCreance::refus($this->compte('responsable_ville'), $creance));
        $this->assertTrue(HabilitationsDActions::accorde($this->compte('responsable_site'), 'creances', 'modifier'));
        $this->assertFalse(HabilitationsDActions::accorde($this->compte('commercial'), 'creances', 'modifier'));
    }

    public function test_un_role_recoit_la_suppression_et_le_bouton_apparait(): void
    {
        $superviseur = $this->compte('responsable_ville');
        $creance = $this->creance('F-2');

        $this->section(['beneficiaire' => 'role', 'role' => 'responsable_ville', 'domaine' => 'creances', 'actions' => ['supprimer']])
            ->assertHasNoErrors();

        $this->assertSame(1, HabilitationAction::query()->where(['role' => 'responsable_ville', 'action' => 'supprimer', 'accorde' => true])->count());
        $this->assertNull(SuppressionDUneCreance::refus($superviseur, $creance));

        $this->assertTrue(Volt::actingAs($superviseur)->test('pilotage.impayes')->instance()->peutSupprimer);
    }

    public function test_un_compte_precis_l_emporte_sur_son_role(): void
    {
        $retire = $this->compte('responsable_site', 'site-a');
        $garde = $this->compte('responsable_site', 'site-b');

        // Le rôle perd « Modifier »…
        $this->section(['sens' => 'retirer', 'beneficiaire' => 'role', 'role' => 'responsable_site', 'domaine' => 'creances', 'actions' => ['modifier']])
            ->assertHasNoErrors();

        // … sauf pour un compte que l'on nomme.
        $this->section(['beneficiaire' => 'compte', 'compte' => (string) $garde->id, 'domaine' => 'creances', 'actions' => ['modifier']])
            ->assertHasNoErrors();

        $this->assertFalse(HabilitationsDActions::accorde($retire, 'creances', 'modifier'));
        $this->assertTrue(HabilitationsDActions::accorde($garde, 'creances', 'modifier'));

        $this->assertFalse(Volt::actingAs($retire)->test('pilotage.impayes')->instance()->peutModifier);
        $this->assertTrue(Volt::actingAs($garde)->test('pilotage.impayes')->instance()->peutModifier);

        // Le geste refusé ne s'obtient pas en appelant l'action à la main.
        $creance = $this->creance('F-3');
        Volt::actingAs($retire)->test('pilotage.impayes')->call('modifier', $creance->id)->assertSet('enModification', null);

        // Accorder de nouveau au rôle efface le retrait : la règle du code revient.
        $this->section(['beneficiaire' => 'role', 'role' => 'responsable_site', 'domaine' => 'creances', 'actions' => ['modifier']])
            ->assertHasNoErrors();
        $this->assertSame(0, HabilitationAction::query()->whereNull('user_id')->count());
    }

    public function test_le_gerant_ne_perd_jamais_ses_gestes(): void
    {
        $this->section(['sens' => 'retirer', 'beneficiaire' => 'role', 'role' => 'gerant', 'domaine' => 'creances', 'actions' => ['supprimer']])
            ->assertHasErrors('role');

        $this->assertSame(0, HabilitationAction::query()->count());
    }

    public function test_seul_le_super_admin_donne_des_habilitations(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('superadmin.habilitations')
            ->set('role', 'caissier')->set('actions', ['supprimer'])
            ->call('enregistrer')
            ->assertForbidden();
    }

    public function test_une_piece_fournisseur_saisie_se_supprime_et_rend_sa_fne(): void
    {
        $gerant = $this->compte('gerant');
        $caissier = $this->compte('caissier');

        $piece = FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id,
            'fournisseur' => 'Ets Kouassi pièces', 'numero_piece' => 'BL-77', 'date_facture' => '2026-03-11',
            'montant' => 118_000, 'montant_regle' => 0, 'reste_a_payer' => 118_000,
            'observations' => 'Facture normalisée 2626A0000002 reçue par mail',
        ]);
        AchatsFne::importer($this->releve(), 'achats.json', $this->e->id);
        $this->assertSame($piece->id, (int) FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));

        // Le caissier modifie (le code le lui donne) mais ne supprime pas.
        Volt::actingAs($caissier)->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->assertSee('Modifier')->assertDontSee('Oui, effacer');
        $this->assertNotNull(SuppressionDUnePieceFournisseur::refus($caissier, $piece));

        Volt::actingAs($gerant)->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->call('demanderLaSuppression')
            ->assertSee('Oui, effacer')
            ->call('confirmerLaSuppression')
            ->assertRedirect(route('fournisseurs'));

        $this->assertNull(FactureFournisseur::withoutGlobalScopes()->find($piece->id));
        // La FNE reste, détachée : elle redevient « pas encore au suivi ».
        $this->assertNull(FactureFne::withoutGlobalScopes()->where('reference', '2626A0000002')->value('facture_fournisseur_id'));
    }

    public function test_une_piece_reglee_ne_se_supprime_pas(): void
    {
        $piece = FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id,
            'fournisseur' => 'Ets Kouassi', 'numero_piece' => 'F-9', 'date_facture' => '2026-03-11',
            'montant' => 50_000, 'montant_regle' => 10_000, 'reste_a_payer' => 40_000,
        ]);

        $refus = SuppressionDUnePieceFournisseur::refus($this->compte('gerant'), $piece);
        $this->assertStringContainsString('règlement', (string) $refus);
    }

    // ------------------------------------------------------------------------------ outils

    /**
     * Remplit et enregistre la section de la Maintenance, en Super admin : son rôle est de l'équipe 0,
     * puis les comptes de l'entreprise se relisent dans la leur.
     */
    private function section(array $valeurs)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $ecran = Volt::actingAs($this->admin->fresh())->test('superadmin.habilitations');

        foreach ($valeurs as $propriete => $valeur) {
            $ecran->set($propriete, $valeur);
        }

        $ecran->call('enregistrer');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        return $ecran;
    }

    private function creance(string $numero): Facture
    {
        $date = now()->subDays(20);

        return Facture::create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id,
            'numero' => 'IMP-'.substr(md5($numero), 0, 10), 'n_facture' => $numero,
            'date' => $date, 'date_reception' => now()->subDays(10),
            'exercice_impayes' => (int) $date->format('Y'),
            'client' => 'Client '.$numero, 'montant' => 100_000, 'activite' => 'Sinistre',
        ]);
    }

    private function compte(string $role, ?string $suffixe = null): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).($suffixe ? '-'.$suffixe : '').'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }

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
            ],
        ]);
    }
}

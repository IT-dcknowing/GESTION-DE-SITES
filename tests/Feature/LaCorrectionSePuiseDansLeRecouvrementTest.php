<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La correction des clients, dans le module Recouvrement — 28/10.
 *
 * « La correction part dans le recouvrement, car elle doit puiser dans les données du recouvrement
 * et non dans celles des impayés — toujours avec la logique initiale de correction » : les factures
 * cochées passent au client d'arrivée, rien n'est supprimé.
 */
class LaCorrectionSePuiseDansLeRecouvrementTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_les_factures_cochees_passent_et_rien_n_est_supprime(): void
    {
        $gerant = $this->compte('gerant');

        // Les tiers du recouvrement : NSIA porté comme assureur, et comme client.
        $assuree = $this->creance('F-1', ['client' => 'M. YAO', 'assureur' => 'NSIA']);
        $directe = $this->creance('F-2', ['client' => 'NSIA']);
        $this->creance('F-3', ['client' => 'M. KONAN', 'assureur' => 'SAHAM']);
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $assuree->id, 'date' => '2026-03-05',
            'type' => 'Client', 'moyen' => 'Virement', 'montant' => 10_000, 'client' => 'NSIA',
        ]);
        RelanceRecouvrement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'responsable' => 'Agent', 'date' => '2026-03-06', 'tiers' => 'NSIA',
            'niveau' => 1, 'canal' => 'E-mail',
        ]);
        Referentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'type' => Referentiel::TIERS_RECOUVREMENT, 'valeur' => 'NSIA', 'est_actif' => true,
        ]);

        $ecran = Volt::actingAs($gerant)->test('recouvrement.correction');
        // La liste puise dans le recouvrement : l'assureur y figure, pas seulement les clients.
        $this->assertArrayHasKey('SAHAM', $ecran->instance()->options);

        $ecran->set('depart', 'NSIA');
        $this->assertSame(2, $ecran->instance()->factures->count());
        $this->assertArrayNotHasKey('NSIA', $ecran->instance()->optionsArrivee);

        $ecran->set('cochees', [(string) $assuree->id])->set('arrivee', 'SAHAM')
            ->call('corriger')->assertHasNoErrors()
            // NSIA porte encore F-2 : la page reste sur lui, cases vides.
            ->assertSet('depart', 'NSIA')->assertSet('cochees', [])->assertSee('Factures de « NSIA » (1)');

        $this->assertSame('SAHAM', $assuree->fresh()->assureur, 'Le nom change dans la colonne où il se lit.');
        $this->assertSame('M. YAO', $assuree->fresh()->client);
        $this->assertSame('NSIA', $directe->fresh()->client, 'La facture non cochée reste.');
        $this->assertSame('SAHAM', Encaissement::withoutGlobalScopes()->sole()->client);
        // Rien n'est supprimé : ni les relances, ni la place au référentiel.
        $this->assertSame('NSIA', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);
        $this->assertSame(1, Referentiel::withoutGlobalScopes()->where('valeur', 'NSIA')->count());
        $this->assertSame(200_000, montant_de($assuree->fresh()->montant), 'Les montants ne bougent pas.');
    }

    public function test_un_client_ne_se_corrige_pas_vers_lui_meme_ni_sans_facture_cochee(): void
    {
        $gerant = $this->compte('gerant');
        $f = $this->creance('F-1', ['client' => 'NSIA']);

        Volt::actingAs($gerant)->test('recouvrement.correction')
            ->set('depart', 'NSIA')->set('cochees', [(string) $f->id])->set('arrivee', ' nsia ')
            ->call('corriger')->assertHasErrors('arrivee');

        Volt::actingAs($gerant)->test('recouvrement.correction')
            ->set('depart', 'NSIA')->set('arrivee', 'SAHAM')
            ->call('corriger')->assertHasErrors('arrivee');

        $this->assertSame('NSIA', $f->fresh()->client);
    }

    public function test_la_page_s_ouvre_au_gerant_et_par_l_habilitation(): void
    {
        $gerant = $this->compte('gerant');
        $superviseur = $this->compte('superviseur_recouvrement');

        // Le superviseur du recouvrement ne l'a pas d'office (le propriétaire, 28/10).
        $this->actingAs($superviseur)->get(route('recouvrement.correction'))->assertForbidden();
        $this->actingAs($superviseur)->get(route('recouvrement.balance'))->assertOk()->assertDontSee('Correction des clients');
        $this->actingAs($gerant)->get(route('recouvrement.correction'))->assertOk();

        HabilitationAction::query()->create([
            'domaine' => 'tiers_recouvrement', 'action' => 'corriger', 'user_id' => $superviseur->id, 'accorde' => true,
        ]);
        HabilitationsDActions::oublier();

        $this->actingAs($superviseur)->get(route('recouvrement.correction'))->assertOk()->assertSee('Correction des clients');
        $this->actingAs($superviseur)->get(route('recouvrement.balance'))->assertOk()->assertSee('Correction des clients');
    }

    // ------------------------------------------------------------------------------ outils

    /** @param  array<string, string>  $tiers */
    private function creance(string $numero, array $tiers): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'IMP-'.$numero, 'n_facture' => $numero,
            'date' => '2026-03-01', 'date_reception' => '2026-03-02', 'exercice_impayes' => 2026,
            'montant' => 200_000, 'activite' => 'Sinistre', ...$tiers,
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($role), 'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

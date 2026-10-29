<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\ClientCommercial;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\PerimetreCommercialDuRecouvrement;
use Modules\Recouvrement\Support\AccesRecouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le recouvrement des clients d'un commercial — 28/10.
 *
 * « Au niveau des commerciaux qu'on affecte à un client, le but est de voir les impayés de ces
 * clients. Fais une ouverture où l'on peut voir les infos du recouvrement — tableau de bord, saisie
 * (uniquement la relance), balance âgée, extrait de compte, courtiers s'il y a un courtier —
 * uniquement en rapport avec les clients qu'ils suivent. Au tableau où un client a quelqu'un qui le
 * suit, un bouton qui ramène vers ses pages ; et le bouton Relancer ouvre la saisie, sur la relance. »
 */
class LeCommercialVoitLesImpayesDeSesClientsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    private User $vendeur;

    private Commercial $fiche;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);

        $this->vendeur = $this->compte('commercial', 'awa');
        $this->fiche = Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $this->vendeur->id, 'numero' => 'C-1', 'nom' => 'Awa']);

        // Ses clients : SOTRA (paie lui-même) et M. KONAN (son assureur paie, par un courtier).
        $this->suivre('Sotra Transports');
        $this->suivre('M. KONAN');
        $this->creance('F-1', ['client' => 'SOTRA TRANSPORTS'], 300_000);
        $this->creance('F-2', ['client' => 'M. KONAN', 'assureur' => 'NSIA', 'courtier' => 'ASCOMA'], 150_000);

        // Ce qui n'est pas à lui : un autre client, et une autre facture du même assureur.
        $this->creance('F-3', ['client' => 'GARAGE TOTAL'], 900_000);
        $this->creance('F-4', ['client' => 'M. YAO', 'assureur' => 'NSIA', 'courtier' => 'ASCOMA'], 700_000);
    }

    public function test_cinq_ecrans_et_ses_seuls_clients(): void
    {
        $this->assertSame(['tableau-de-bord', 'saisie', 'balance', 'courtiers', 'extrait'], AccesRecouvrement::pagesDe($this->vendeur));

        foreach (['tableau-de-bord', 'saisie', 'balance', 'courtiers', 'extrait'] as $page) {
            $this->actingAs($this->vendeur)->get(route('recouvrement.'.$page))->assertOk();
        }

        foreach (['synthese', 'clients', 'relances', 'encaissements', 'audit'] as $page) {
            $this->actingAs($this->vendeur)->get(route('recouvrement.'.$page))->assertRedirect(route('recouvrement.tableau-de-bord'));
        }

        // La balance : SOTRA et le courtier de M. KONAN, pour ses seules factures.
        $this->actingAs($this->vendeur);
        $balance = Volt::test('recouvrement.balance');
        $html = $balance->html();
        $this->assertStringContainsString('SOTRA TRANSPORTS', $html);
        $this->assertStringContainsString('ASCOMA', $html);
        $this->assertStringNotContainsString('GARAGE TOTAL', $html);

        $perimetre = PerimetreCommercialDuRecouvrement::courant();
        $this->assertTrue($perimetre->retient('ASCOMA'));
        $this->assertFalse($perimetre->retient('GARAGE TOTAL'));

        // Le tableau de bord : 450 000 F, et non le 2 050 000 F de l'entreprise.
        $tableau = Volt::test('recouvrement.tableau-de-bord');
        $this->assertSame(450_000, montant_de($tableau->instance()->reperes['encours']));
        $ascoma = $tableau->instance()->lignes->firstWhere('tiers', 'ASCOMA');
        $this->assertSame(150_000, montant_de($ascoma['reste']), 'Le courtier commun ne montre que la facture de son client.');

        // Les courtiers : ASCOMA, pour la seule facture de son client.
        $this->assertSame(['ASCOMA'], Volt::test('recouvrement.courtiers')->instance()->courtiers);

        // L'extrait et le dossier d'un tiers hors de sa vue ne s'ouvrent pas.
        Volt::test('recouvrement.extrait', ['tiers' => 'GARAGE TOTAL'])->assertSet('tiers', '');
        $this->get(route('recouvrement.dossier', ['tiers' => 'GARAGE TOTAL']))->assertNotFound();
        $this->get(route('recouvrement.dossier', ['tiers' => 'SOTRA TRANSPORTS']))->assertOk();

        // Les exports : ce que ses écrans montrent, pas l'annuaire ni les journaux.
        $this->get(route('recouvrement.telecharger', ['document' => 'clients']))->assertForbidden();
        $this->get(route('recouvrement.telecharger', ['document' => 'relances']))->assertForbidden();
        $this->get(route('recouvrement.telecharger', ['document' => 'extrait', 'tiers' => 'GARAGE TOTAL']))->assertNotFound();
    }

    public function test_la_saisie_ne_montre_que_la_relance_et_s_ouvre_sur_le_tiers_demande(): void
    {
        $this->actingAs($this->vendeur);

        $this->get(route('recouvrement.saisie'))->assertOk()
            ->assertSee('Tracer une relance')
            ->assertDontSee('Enregistrer un encaissement')
            ->assertDontSee('Créer un client / tiers');

        // « Relancer » arrive sur la relance, le tiers choisi et ses factures listées.
        $this->get(route('recouvrement.saisie', ['relance' => 'ASCOMA']))->assertOk()->assertSee('F-2');
        $ecran = Volt::test('recouvrement.saisie', ['relance' => 'ASCOMA']);
        $ecran->assertSet('relTiers', 'ASCOMA');
        $this->assertSame(['F-2'], $ecran->instance()->facturesDuTiersRelance->pluck('n_facture')->all());

        // Un tiers hors de sa vue n'est pas retenu à l'arrivée…
        Volt::test('recouvrement.saisie', ['relance' => 'GARAGE TOTAL'])->assertSet('relTiers', '');

        // … ni à l'enregistrement, même renvoyé à la main.
        Volt::test('recouvrement.saisie')
            ->set('relTiers', 'GARAGE TOTAL')->set('relCanal', RelanceRecouvrement::CANAUX[0])
            ->call('enregistrerRelance')->assertHasErrors('relTiers');
        $this->assertSame(0, RelanceRecouvrement::withoutGlobalScopes()->count());

        $ecran->set('relCanal', RelanceRecouvrement::CANAUX[0])->call('enregistrerRelance')->assertHasNoErrors();
        $this->assertSame('ASCOMA', RelanceRecouvrement::withoutGlobalScopes()->sole()->tiers);

        // L'encaissement lui est refusé, même appelé directement.
        Volt::test('recouvrement.saisie')->set('encTiers', 'SOTRA TRANSPORTS')->call('enregistrerEncaissement')->assertForbidden();
        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    public function test_mes_clients_montre_leurs_impayes_et_mene_au_recouvrement(): void
    {
        $this->actingAs($this->vendeur);

        $ecran = Volt::test('commercial.mes-clients');
        $impayes = $ecran->instance()->impayes;
        $this->assertSame(300_000, montant_de($impayes['SOTRA TRANSPORTS']['reste']));
        $this->assertSame('SOTRA TRANSPORTS', $impayes['SOTRA TRANSPORTS']['tiers'], 'Le client paie lui-même.');
        $this->assertSame('ASCOMA', $impayes['M. KONAN']['tiers'], 'Le courtier paie pour M. KONAN.');

        $ecran->assertSee(route('recouvrement.saisie', ['relance' => 'ASCOMA']), false)
            ->assertSee(route('recouvrement.dossier', ['tiers' => 'SOTRA TRANSPORTS']), false)
            ->assertSee('Relance commerciale');

        $libelles = collect(MenuNavigation::pour($this->vendeur))
            ->flatMap(fn ($o) => isset($o['groupe']) ? array_column($o['groupe'], 'label') : [$o['label']])->all();
        $this->assertContains('Recouvrement', $libelles);
    }

    public function test_le_gerant_voit_tout_et_a_le_bouton_au_portefeuille(): void
    {
        $gerant = $this->compte('gerant', 'gerant');
        $this->actingAs($gerant);

        $this->assertNull(PerimetreCommercialDuRecouvrement::courant());
        $this->assertSame(2_050_000, montant_de(Volt::test('recouvrement.tableau-de-bord')->instance()->reperes['encours']));

        Volt::test('pilotage.portefeuilles-clients')
            ->assertSee(route('recouvrement.dossier', ['tiers' => 'ASCOMA']), false)
            ->assertSee(route('recouvrement.saisie', ['relance' => 'SOTRA TRANSPORTS']), false);
    }

    public function test_un_commercial_sans_client_ne_voit_rien(): void
    {
        $autre = $this->compte('commercial', 'koffi');
        Commercial::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'user_id' => $autre->id, 'numero' => 'C-2', 'nom' => 'Koffi']);
        $this->actingAs($autre);

        $this->get(route('recouvrement.tableau-de-bord'))->assertOk()->assertSee('Aucun client ne vous est encore affecté');
        $this->assertSame(0, montant_de(Volt::test('recouvrement.tableau-de-bord')->instance()->reperes['encours']));
    }

    // ------------------------------------------------------------------------------ outils

    private function suivre(string $client): void
    {
        ClientCommercial::withoutGlobalScopes()->create(['entreprise_id' => $this->e->id, 'commercial_id' => $this->fiche->id, 'client' => $client]);
    }

    /** @param  array<string, string>  $tiers */
    private function creance(string $numero, array $tiers, int $montant): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'IMP-'.$numero, 'n_facture' => $numero,
            'date' => now()->subDays(40)->toDateString(), 'date_reception' => now()->subDays(40)->toDateString(), 'exercice_impayes' => (int) now()->year,
            'montant' => $montant, 'activite' => 'Sinistre', ...$tiers,
        ]);
    }

    private function compte(string $role, string $nom): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);

        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($nom), 'email' => $nom.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

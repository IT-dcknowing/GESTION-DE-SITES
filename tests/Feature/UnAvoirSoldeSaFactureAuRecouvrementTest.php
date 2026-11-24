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
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Une facture qui a reçu son avoir sort du recouvrement — pour son client comme pour son tiers.
 *
 * **Le constat du 24/11** (le propriétaire) : « pourquoi les factures dont on a fait un avoir ne
 * disparaissent-elles pas du recouvrement pour un client précis, même pour un tiers précis ? »
 *
 * La cause : l'avoir du bouton « Avoir » (27/10) est une ligne à part, au montant négatif, qui dit
 * sa facture par `avoir_de_id`. Mais le reste dû se calculait **ligne par ligne, à plancher zéro**
 * — montant moins encaissements, rien d'autre. La ligne d'avoir tombait à zéro sur elle-même, et
 * la facture qu'elle corrige gardait tout son reste : elle restait ouverte au recouvrement, au
 * tiers, dans la balance âgée, et à l'état des impayés (filtre « ouvertes », report d'une année
 * sur l'autre). L'écran de l'avoir annonçait pourtant « la créance du client diminue d'autant ».
 *
 * Désormais le reste dû d'une facture = montant + ses avoirs rattachés − ses encaissements, une
 * seule définition, en SQL (`Facture::scopeAvecResteAEncaisser`) comme en PHP
 * (`Recouvrement::resteDe`).
 */
class UnAvoirSoldeSaFactureAuRecouvrementTest extends TestCase
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

    /** Le cas du propriétaire, par le vrai bouton « Avoir » : la facture sort du recouvrement. */
    public function test_l_avoir_du_bouton_fait_sortir_la_facture_du_recouvrement_du_client_et_du_tiers(): void
    {
        $facture = $this->facture(1_000_000);
        $this->autreFacture();

        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes-avoir')
            ->set('client', 'SODECI')
            ->set('cochees', [(string) $facture->id])
            ->set('montant', '1000000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $avoir = Facture::withoutGlobalScopes()->where('avoir_de_id', $facture->id)->first();
        $this->assertNotNull($avoir);
        $this->assertSame(-1_000_000, $avoir->montant);

        // Le tiers payant (l'assureur) n'a plus cette facture ; l'autre reste.
        $this->assertSame([], Recouvrement::facturesOuvertesDuTiers('AXA')->pluck('id')->all());
        $this->assertArrayNotHasKey('AXA', Recouvrement::tiersDebiteurs());
        $this->assertSame(['ORANGE'], array_values(Recouvrement::tiersDebiteurs()));

        $ouvertes = Recouvrement::lignesOuvertes();
        $this->assertNotContains($facture->id, $ouvertes->pluck('id')->all());
        $this->assertSame(400_000, Recouvrement::kpis($ouvertes, now())['reste']);

        // L'annuaire et la balance : le tiers est à zéro, pas à un million.
        $annuaire = Recouvrement::annuaireDesTiers(now())->keyBy('tiers');
        $this->assertSame(0, $annuaire['AXA']['reste']);
        $this->assertSame(0, $annuaire['AXA']['ouvertes']);

        // L'extrait du client (toutes les factures) : celle-ci se lit soldée.
        $this->assertSame(0, Recouvrement::reste(Recouvrement::factures()->firstWhere('id', $facture->id)));
    }

    /** L'état des impayés suit la même règle : filtre « ouvertes », totaux, report. */
    public function test_l_etat_des_impayes_ne_compte_plus_la_facture_avoiree(): void
    {
        $facture = $this->facture(1_000_000, exercice: 2025);
        $this->avoir($facture, 1_000_000, exercice: 2026);

        // Le report de 2025 sur 2026 ne la ramène plus : elle est soldée par son avoir.
        $this->assertFalse(EtatDesImpayes::requete(2026)->whereKey($facture->id)->exists());

        $ouvertes = EtatDesImpayes::filtrerLeSolde(EtatDesImpayes::requete(2025), 'ouvertes');
        $this->assertSame(0, $ouvertes->count());

        $totaux = EtatDesImpayes::totauxEnBase(EtatDesImpayes::requete(2025), 2025);
        $this->assertSame(0, $totaux['reste']);
        $this->assertSame(0, $totaux['ouvertes']);

        // La version en mémoire dit la même chose que la version en base.
        $lignes = EtatDesImpayes::requete(2025)->avecEncaisseEtAvoirs()->get();
        $this->assertSame(0, EtatDesImpayes::totaux($lignes, 2025)['reste']);
    }

    /** Un avoir partiel, avec un règlement : le reste est montant − avoir − réglé, partout pareil. */
    public function test_un_avoir_partiel_et_un_reglement_laissent_le_meme_reste_partout(): void
    {
        $facture = $this->facture(1_000_000);
        $this->avoir($facture, 253_311.5);
        $this->encaisser($facture, 100_000);

        $attendu = 646_688.5;

        $this->assertSame($attendu, Recouvrement::reste(Recouvrement::factures()->firstWhere('id', $facture->id)));
        $this->assertSame($attendu, $facture->fresh()->resteAEncaisser());
        $this->assertSame($attendu, Recouvrement::kpis(Recouvrement::lignesOuvertes(), now())['reste']);
        $this->assertSame($attendu, EtatDesImpayes::totauxEnBase(EtatDesImpayes::requete(2026), 2026)['reste']);
        $this->assertSame($attendu, Recouvrement::facturesOuvertesDuTiers('AXA')->sum(fn ($f) => Recouvrement::reste($f)));
    }

    /**
     * Un avoir sans facture désignée (les lignes négatives du classeur) ne s'impute sur rien.
     *
     * Le reprendre sur une facture du même client serait deviner : il reste une ligne à zéro, comme
     * avant. C'est la décision du 02/10 (`NatureDeLaCreance`) : rien n'est changé pour lui.
     */
    public function test_un_avoir_sans_facture_designee_ne_s_impute_sur_aucune(): void
    {
        $facture = $this->facture(1_000_000);

        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'ville_id' => $this->ville->id,
            'date' => '2026-03-01', 'n_facture' => 'AV-LIBRE', 'client' => 'SODECI', 'assureur' => 'AXA',
            'activite' => 'Mécanique', 'montant' => -300_000, 'est_avoir' => true, 'exercice_impayes' => 2026,
        ]);

        $this->assertSame(1_000_000, Recouvrement::reste(Recouvrement::factures()->firstWhere('id', $facture->id)));
    }

    private function facture(int $montant, int $exercice = 2026): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'ville_id' => $this->ville->id,
            'date' => $exercice.'-02-10', 'date_reception' => $exercice.'-02-12',
            'n_facture' => 'F-'.Facture::withoutGlobalScopes()->count(), 'immatriculation' => 'AA-'.$montant,
            'client' => 'SODECI', 'assureur' => 'AXA',
            'activite' => 'Mécanique', 'montant' => $montant, 'exercice_impayes' => $exercice,
        ]);
    }

    private function autreFacture(): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'ville_id' => $this->ville->id,
            'date' => '2026-02-11', 'date_reception' => '2026-02-12', 'n_facture' => 'F-ORANGE',
            'client' => 'ORANGE', 'activite' => 'Mécanique', 'montant' => 400_000, 'exercice_impayes' => 2026,
        ]);
    }

    /** Une ligne d'avoir telle que l'écrit le bouton « Avoir » (`pilotage.impayes-avoir`). */
    private function avoir(Facture $facture, int|float $montant, int $exercice = 2026): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $facture->site_id,
            'date' => $exercice.'-03-01', 'date_reception' => $exercice.'-03-01',
            'n_facture' => 'AV-'.$facture->n_facture, 'client' => $facture->client, 'assureur' => $facture->assureur,
            'activite' => 'Mécanique', 'montant' => -$montant, 'est_avoir' => true,
            'avoir_de_id' => $facture->id, 'exercice_impayes' => $exercice,
        ]);
    }

    private function encaisser(Facture $facture, int $montant): void
    {
        Encaissement::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id, 'facture_id' => $facture->id,
            'date' => '2026-03-05', 'type' => 'Client', 'moyen' => 'Virement', 'montant' => $montant, 'client' => 'AXA',
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Compte '.$role,
            'email' => $role.'@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

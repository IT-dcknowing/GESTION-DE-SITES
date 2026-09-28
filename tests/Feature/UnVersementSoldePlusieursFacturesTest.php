<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Noyau\Exploitation\Services\ReglementGlobal;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un chèque de 5 000 000 pour trois créances.
 *
 * **La demande, du 28/09** : « imaginons qu'un client donne un montant de 5 000 000 pour
 * ses créances, et que cela concerne 3 ou plusieurs factures — il faudra permettre de
 * sélectionner les factures et de remplir les informations une seule fois. Maintenant la
 * question qui reste posée, c'est comment le montant sera sur chaque facture : le système
 * devra répartir. »
 *
 * **La règle retenue, et pourquoi elle n'est pas arbitraire.** La plus ancienne d'abord,
 * jusqu'à épuisement. C'est la convention comptable ordinaire, et c'est la seule qui serve
 * le recouvrement : elle fait tomber les créances les plus vieilles, donc celles qui
 * déclenchent les niveaux de relance. Répartir au prorata laisserait toutes les factures
 * partiellement ouvertes — aucune ne sortirait de la balance âgée, et l'on continuerait de
 * relancer un client qui vient de payer.
 *
 * Ce qui est verrouillé ici : l'ordre, le reliquat, le refus du trop-perçu, et la
 * référence commune qui relie les écritures d'un même versement.
 */
class UnVersementSoldePlusieursFacturesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

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
    }

    // ------------------------------------------------------------------ la règle seule

    public function test_la_plus_ancienne_est_servie_en_premier(): void
    {
        $vieille = $this->facture('F-001', 2_000_000, now()->subDays(90));
        $moyenne = $this->facture('F-002', 2_000_000, now()->subDays(60));
        $recente = $this->facture('F-003', 2_000_000, now()->subDays(30));

        // 5 000 000 sur 6 000 000 dus : les deux premières tombent, la troisième reçoit
        // le reliquat.
        $repartition = ReglementGlobal::repartir(collect([$recente, $vieille, $moyenne]), 5_000_000);

        $parts = collect($repartition['parts'])
            ->mapWithKeys(fn (array $p) => [$p['facture']->n_facture => $p['part']])
            ->all();

        $this->assertSame(
            ['F-001' => 2_000_000, 'F-002' => 2_000_000, 'F-003' => 1_000_000],
            $parts,
            'La plus ancienne doit être servie la première, quel que soit l’ordre de sélection.',
        );

        $this->assertSame(0, $repartition['reste_du_versement']);
        $this->assertSame(6_000_000, $repartition['total_du']);
    }

    /**
     * Un versement qui ne couvre pas tout ne se refuse pas.
     *
     * Le propriétaire l'a précisé : « la somme donnée ne veut pas dire que ça couvrira
     * toutes les créances ».
     */
    public function test_un_versement_partiel_laisse_le_reste_ouvert(): void
    {
        $vieille = $this->facture('F-001', 1_000_000, now()->subDays(90));
        $recente = $this->facture('F-002', 1_000_000, now()->subDays(30));

        $repartition = ReglementGlobal::repartir(collect([$vieille, $recente]), 1_400_000);

        $this->assertSame(1_000_000, $repartition['parts'][0]['part']);
        $this->assertSame(400_000, $repartition['parts'][1]['part']);
        $this->assertSame(600_000, $repartition['parts'][1]['reste']);
        $this->assertTrue($repartition['parts'][0]['soldee']);
        $this->assertFalse($repartition['parts'][1]['soldee']);
    }

    public function test_un_versement_superieur_au_du_laisse_un_reliquat_visible(): void
    {
        $facture = $this->facture('F-001', 500_000, now()->subDays(10));

        $repartition = ReglementGlobal::repartir(collect([$facture]), 700_000);

        $this->assertSame(500_000, $repartition['parts'][0]['part']);
        $this->assertSame(200_000, $repartition['reste_du_versement'], 'Le trop-perçu doit rester visible.');
    }

    // ------------------------------------------------------------------ l'écran

    public function test_l_ecran_repartit_le_versement_et_relie_les_ecritures(): void
    {
        $this->declarerLeMode('CHÈQUE');

        $vieille = $this->facture('F-001', 2_000_000, now()->subDays(90));
        $moyenne = $this->facture('F-002', 2_000_000, now()->subDays(60));
        $recente = $this->facture('F-003', 2_000_000, now()->subDays(30));

        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('encTiers', 'SIFCA')
            ->set('encFactures.'.$vieille->id, true)
            ->set('encFactures.'.$moyenne->id, true)
            ->set('encFactures.'.$recente->id, true)
            ->set('encMode', 'CHÈQUE')
            ->set('encMontant', 5_000_000)
            ->set('encReference', 'CHQ 1234567')
            ->set('encBanque', 'SGBCI')
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $ecritures = Encaissement::withoutGlobalScopes()->get();

        $this->assertCount(3, $ecritures);
        $this->assertSame(5_000_000, (int) $ecritures->sum('montant'));

        $parFacture = $ecritures->mapWithKeys(fn (Encaissement $e) => [$e->facture_id => (int) $e->montant]);

        $this->assertSame(2_000_000, $parFacture[$vieille->id]);
        $this->assertSame(2_000_000, $parFacture[$moyenne->id]);
        $this->assertSame(1_000_000, $parFacture[$recente->id]);

        // La marque du versement : une seule référence, partagée par les trois écritures.
        $references = $ecritures->pluck('reglement_global')->unique();

        $this->assertCount(1, $references);
        $this->assertStringStartsWith('RG-', (string) $references->first());

        // La banque est une information de la créance : elle se pose sur les factures.
        $this->assertSame('SGBCI', $vieille->fresh()->banque);
    }

    /**
     * Un versement d'une seule facture n'est pas un règlement global.
     *
     * Le marquer comme tel ferait apparaître des « paiements groupés » qui n'en sont pas
     * dans la colonne de l'état — et la colonne cesserait de vouloir dire quelque chose.
     */
    public function test_un_versement_d_une_seule_facture_ne_porte_pas_de_reference_globale(): void
    {
        $this->declarerLeMode('ESPÈCES');
        $facture = $this->facture('F-001', 500_000, now()->subDays(10));

        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('encTiers', 'SIFCA')
            ->set('encFactures.'.$facture->id, true)
            ->set('encMode', 'ESPÈCES')
            ->set('encMontant', 500_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $this->assertNull(Encaissement::withoutGlobalScopes()->firstOrFail()->reglement_global);
    }

    public function test_le_trop_percu_est_refuse_et_rien_n_est_ecrit(): void
    {
        $this->declarerLeMode('CHÈQUE');
        $facture = $this->facture('F-001', 500_000, now()->subDays(10));

        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('encTiers', 'SIFCA')
            ->set('encFactures.'.$facture->id, true)
            ->set('encMode', 'CHÈQUE')
            ->set('encMontant', 700_000)
            ->call('enregistrerEncaissement')
            ->assertHasErrors('encMontant');

        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    public function test_sans_facture_cochee_le_versement_est_refuse(): void
    {
        $this->declarerLeMode('CHÈQUE');
        $this->facture('F-001', 500_000, now()->subDays(10));

        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('encTiers', 'SIFCA')
            ->set('encMode', 'CHÈQUE')
            ->set('encMontant', 100_000)
            ->call('enregistrerEncaissement')
            ->assertHasErrors('encFactures');

        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    /**
     * « Tout cocher » propose le total dû, sans l'imposer.
     *
     * **Le geste vit dans le navigateur depuis le 28/09**, et c'est une correction
     * mesurée : chaque clic recalculait toute la page — la liste du tiers (163 factures
     * pour CGRAE, 119 ms), le bandeau (1 339 lignes, 98 ms), la liste des débiteurs
     * (86 ms), l'annuaire (2 446 tiers, 96 ms). Près de 400 ms de serveur pour une case.
     *
     * Ce qui se vérifie ici est donc ce que le serveur, lui, doit savoir : le total dû des
     * factures cochées. C'est ce nombre que l'écran propose, et c'est celui contre lequel
     * la répartition est refaite sous verrou avant d'écrire.
     */
    public function test_le_total_du_des_factures_cochees_est_connu_du_serveur(): void
    {
        $vieille = $this->facture('F-001', 700_000, now()->subDays(90));
        $recente = $this->facture('F-002', 300_000, now()->subDays(30));

        $ecran = Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('encTiers', 'SIFCA')
            ->set('encFactures.'.$vieille->id, true)
            ->set('encFactures.'.$recente->id, true);

        $this->assertSame(1_000_000, $ecran->instance()->encTotalDu);
        $this->assertCount(2, $ecran->instance()->encSelection);

        // Décoché, le total suit : rien n'est retenu d'un état précédent.
        $ecran->set('encFactures', []);

        $this->assertSame(0, $ecran->instance()->encTotalDu);
    }

    /**
     * La relance cite les factures cochées, et non plus une seule.
     *
     * Demandé le 28/09 : « au niveau des impayés et des relances, on aimerait pouvoir
     * sélectionner les factures qu'on veut traiter ». Une mise en demeure cite presque
     * toujours plusieurs pièces, et rarement toutes — le champ n'offrait que tout ou une.
     */
    public function test_la_relance_cite_les_factures_cochees(): void
    {
        $vieille = $this->facture('F-001', 700_000, now()->subDays(120));
        $moyenne = $this->facture('F-002', 300_000, now()->subDays(60));
        $this->facture('F-003', 100_000, now()->subDays(10));

        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('relTiers', 'SIFCA')
            ->set('relFactures.'.$vieille->id, true)
            ->set('relFactures.'.$moyenne->id, true)
            ->set('relCanal', RelanceRecouvrement::CANAUX[0])
            ->set('relStatut', RelanceRecouvrement::STATUTS[0])
            ->call('enregistrerRelance')
            ->assertHasNoErrors();

        $citees = RelanceRecouvrement::withoutGlobalScopes()->firstOrFail()->factures_visees;

        $this->assertStringContainsString('F-001', $citees);
        $this->assertStringContainsString('F-002', $citees);
        $this->assertStringNotContainsString('F-003', $citees, 'Une pièce non cochée ne se réclame pas.');
    }

    /** Ne rien cocher reste un choix : on relance alors sur l'ensemble du compte. */
    public function test_sans_facture_cochee_la_relance_porte_sur_la_situation_globale(): void
    {
        $this->facture('F-001', 700_000, now()->subDays(120));

        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('relTiers', 'SIFCA')
            ->set('relCanal', RelanceRecouvrement::CANAUX[0])
            ->set('relStatut', RelanceRecouvrement::STATUTS[0])
            ->call('enregistrerRelance')
            ->assertHasNoErrors();

        $this->assertSame(
            'Situation globale',
            RelanceRecouvrement::withoutGlobalScopes()->firstOrFail()->factures_visees,
        );
    }

    /**
     * La référence porte l'année, et elle ouvre le versement entier.
     *
     * **Demandé le 28/09** : « RG-2809-0001 doit être RG-280926-0001, et aussi cliquable
     * pour aller directement vers les détails ; au niveau des détails, on ne doit pas
     * afficher uniquement le détail de cette ligne : on doit avoir le montant total
     * donné, les lignes touchées et combien par ligne. »
     *
     * Les deux tiennent ensemble. « RG-2809 » seul ne dit pas de quelle année il s'agit,
     * et on cite cette référence des mois après, sur un relevé bancaire. Et la question
     * qu'on pose alors est celle du **versement** — « votre chèque, qu'a-t-il soldé ? » —
     * et non celle d'une de ses parts.
     */
    public function test_le_versement_a_sa_page_et_sa_reference_porte_l_annee(): void
    {
        $this->declarerLeMode('CHÈQUE');

        $vieille = $this->facture('F-001', 2_000_000, now()->subDays(90));
        $recente = $this->facture('F-002', 2_000_000, now()->subDays(30));

        Volt::actingAs($this->compte('gerant'))->test('recouvrement.saisie')
            ->set('encTiers', 'SIFCA')
            ->set('encFactures.'.$vieille->id, true)
            ->set('encFactures.'.$recente->id, true)
            ->set('encMode', 'CHÈQUE')
            ->set('encMontant', 3_000_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $reference = Encaissement::withoutGlobalScopes()->firstOrFail()->reglement_global;

        // Six chiffres de date : jour, mois, année.
        $this->assertMatchesRegularExpression('/^RG-\d{6}-\d{4}$/', (string) $reference);

        $page = Volt::actingAs($this->compte('gerant'))
            ->test('pilotage.reglement-global', ['reference' => $reference]);

        // Le montant total donné, et les deux lignes qu'il a touchées.
        $this->assertSame(3_000_000, $page->instance()->total);
        $this->assertCount(2, $page->instance()->ecritures);

        $page->assertSee('F-001')->assertSee('F-002');
        // Combien par ligne : la plus ancienne soldée, la seconde allégée.
        $page->assertSee('Soldée')->assertSee('Allégée');
    }

    // ------------------------------------------------------------------ le décor

    private function facture(string $numero, int $montant, $date): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'date_reception' => $date,
            'n_facture' => $numero,
            'client' => 'SIFCA',
            'activite' => 'Mécanique',
            'montant' => $montant,
            'exercice_impayes' => now()->year,
        ]);
    }

    private function declarerLeMode(string $mode): void
    {
        Referentiel::withoutGlobalScopes()->firstOrCreate([
            'entreprise_id' => $this->entreprise->id,
            'type' => Referentiel::MODE_RECOUVREMENT,
            'valeur' => $mode,
        ], ['est_actif' => true]);
    }

    /** @var array<string, User> */
    private array $comptes = [];

    /** Le même rôle rend le même compte : deux appels ne doivent pas buter sur le courriel. */
    private function compte(string $role): User
    {
        if (isset($this->comptes[$role])) {
            return $this->comptes[$role];
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Aya Koné',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->site->ville_id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $this->comptes[$role] = $compte->fresh();
    }
}

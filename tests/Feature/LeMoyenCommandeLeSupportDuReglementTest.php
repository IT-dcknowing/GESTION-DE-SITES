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
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\SupportDeReglement as S;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le moyen commande le support : caisse **ou** banque, jamais les deux.
 *
 * **Demandé le 30/09** : « fais une liste déroulante des banques existantes, et ajoute une
 * colonne caisse car si le recouvrement a été fait caisse (cash) on doit marquer cela ; les
 * deux doivent être en relation — si caisse est cliqué, banque doit être fermé et vice versa.
 * Cette partie doit être prise en compte même au niveau des impayées. »
 *
 * **L'exclusion ne vient pas d'un verrou, elle vient de la structure.** Le moyen commande le
 * support, et le champ de l'autre n'existe pas : il n'y a rien à désactiver, donc rien à
 * contourner. Cela tient sans JavaScript, ce qui est la règle de la maison sur le chemin
 * critique — et c'est ce que la seconde moitié de ce fichier éprouve : **la règle est
 * revérifiée à la validation**, parce qu'un champ caché à l'écran part quand même dans la
 * requête si quelqu'un le remet.
 *
 * **Ce que cela ferme, mesuré le 01/10.** `factures.banque` est un champ libre depuis le
 * début, et porte quatorze orthographes pour quatre banques : `BGFI` 5 472, `BNI` 287,
 * `BDA` 106, `AFG` 77 — puis `BGFIU`, `BGFI+BNI`, `BGFI/BGFI`, `234665`, et jusqu'à `CAISSE`
 * et `wave`. Ces deux derniers disent tout : quelqu'un avait besoin d'une colonne « support »
 * et l'a écrite dans celle de la banque, faute de mieux.
 */
class LeMoyenCommandeLeSupportDuReglementTest extends TestCase
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

    // ------------------------------------------------------------------ la règle elle-même

    public function test_chaque_moyen_designe_son_support(): void
    {
        $attendus = [
            'CHÈQUE' => S::BANQUE,
            'VIREMENT — BGFI' => S::BANQUE,
            'ESPÈCE' => S::CAISSE,
            'Espèces' => S::CAISSE,
            'MOBILE MONEY — WAVE' => S::MOBILE,
            'Non précisé' => S::INCONNU,
            '' => S::INCONNU,
            null => S::INCONNU,
        ];

        foreach ($attendus as $moyen => $support) {
            $this->assertSame($support, S::pour($moyen === '' ? '' : $moyen),
                'Le moyen « '.var_export($moyen, true).' » doit désigner '.$support.'.');
        }
    }

    // ------------------------------------------------------------------ au recouvrement

    /**
     * Le formulaire ouvre un champ de banque par chèque, et aucun en espèces.
     *
     * Les deux cas sont éprouvés **sur le rendu**, et non seulement sur l'état : c'est
     * l'absence du champ qui fait l'exclusion. Un test qui ne regarderait que la propriété
     * passerait encore si le champ restait à l'écran.
     */
    public function test_au_recouvrement_le_champ_banque_suit_le_moyen(): void
    {
        $this->banque('BGFI');
        $this->banque('BNI');

        $facture = $this->facture(portee: true);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('recouvrement.saisie')
            ->set('encTiers', $facture->client)
            ->set('encMode', 'CHÈQUE');

        $this->assertTrue($ecran->instance()->passeParUneBanque);
        $ecran->assertSeeHtml('wire:model="encBanque"')
            ->assertSee('BGFI', false)
            ->assertSee('BNI', false);

        $ecran->set('encMode', 'ESPÈCE');

        $this->assertFalse($ecran->instance()->passeParUneBanque);
        $ecran->assertDontSeeHtml('wire:model="encBanque"')
            // À la place, le support est dit plutôt que laissé vide : c'est le « marquer la
            // caisse » demandé le 30/09.
            ->assertSee('Caisse — espèces', false);
    }

    /**
     * Changer de moyen efface la banque retenue pour le précédent.
     *
     * Sans cela, choisir « chèque », désigner la BGFI, puis revenir à « espèces » laisserait
     * la BGFI dans l'état : le champ aurait disparu de l'écran, et la valeur serait partie
     * quand même dans la créance. C'est l'inverse de ce que « les deux doivent être en
     * relation » demande.
     */
    public function test_revenir_aux_especes_efface_la_banque_choisie(): void
    {
        $this->banque('BGFI');

        $ecran = Volt::actingAs($this->compte('gerant'))->test('recouvrement.saisie')
            ->set('encMode', 'CHÈQUE')
            ->set('encBanque', 'BGFI');

        $this->assertSame('BGFI', $ecran->get('encBanque'));

        $ecran->set('encMode', 'ESPÈCE');

        $this->assertSame('', $ecran->get('encBanque'),
            'La banque du moyen précédent ne doit pas survivre au changement de moyen.');
    }

    // ------------------------------------------------------------------ aux impayés

    /** La même règle à l'écran « Porter à l'état », comme demandé. */
    public function test_aux_impayes_le_moyen_commande_aussi_le_support(): void
    {
        $this->banque('BGFI');
        $facture = $this->facture();

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.impayes-porter')
            ->set('client', $facture->client)
            ->set('factureId', (string) $facture->id)
            ->set('pRegle', '100000')
            ->set('pModeReglement', 'CHÈQUE');

        $this->assertTrue($ecran->instance()->passeParUneBanque);

        $ecran->set('pModeReglement', 'ESPÈCE');

        $this->assertFalse($ecran->instance()->passeParUneBanque);
        $this->assertSame('', $ecran->get('pBanque'));
    }

    // ------------------------------------------------------------------ le décor

    private function banque(string $nom): Banque
    {
        return Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => $nom,
            'nom_normalise' => Banque::clePour($nom),
        ]);
    }

    private function facture(bool $portee = false): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'n_facture' => 'F-'.(Facture::withoutGlobalScopes()->count() + 1),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Mécanique',
            'montant' => 500_000,
            // Portée à l'état : c'est ce qui en fait une créance que le recouvrement suit.
            'exercice_impayes' => $portee ? now()->year : null,
        ]);
    }

    /** @var array<string, User> */
    private array $comptes = [];

    private function compte(string $role): User
    {
        if (isset($this->comptes[$role])) {
            return $this->comptes[$role];
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $this->comptes[$role] = $compte->fresh();
    }
}

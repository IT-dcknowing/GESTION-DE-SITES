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
     * Le support commande ce qui s'ouvre en dessous.
     *
     * **Les trois cas sont éprouvés sur le rendu**, et non seulement sur l'état : c'est
     * l'absence du champ qui fait l'exclusion. Un test qui ne regarderait que la propriété
     * passerait encore si le champ restait à l'écran.
     */
    public function test_le_support_commande_les_champs_qui_s_ouvrent(): void
    {
        $this->banque('BGFI');
        $this->portefeuille('ORANGE');

        $facture = $this->facture(portee: true);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('recouvrement.saisie')
            ->set('encTiers', $facture->client);

        // ── Banque : le compte, le mode précis, et la référence facultative.
        $ecran->set('encSupport', 'banque');

        $ecran->assertSeeHtml('wire:model.live="encCompte"')
            ->assertSeeHtml('wire:model="encMode"')
            ->assertSeeHtml('wire:model="encReference"')
            ->assertSee('BGFI', false)
            // Les portefeuilles ne se proposent pas dans la liste des banques.
            ->assertDontSee('ORANGE', false);

        // ── Mobile money : le portefeuille, et rien d'autre. Un transfert mobile ne se
        //    règle pas « par chèque ».
        $ecran->set('encSupport', 'mobile');

        $ecran->assertSeeHtml('wire:model.live="encCompte"')
            ->assertDontSeeHtml('wire:model="encMode"')
            ->assertSee('ORANGE', false)
            ->assertDontSee('BGFI', false);

        // ── Caisse : aucun compte, aucun mode. L'argent passe de la main à la main.
        $ecran->set('encSupport', 'caisse');

        $ecran->assertDontSeeHtml('wire:model.live="encCompte"')
            ->assertDontSeeHtml('wire:model="encMode"');
    }

    /**
     * Changer de support efface ce qui appartenait au précédent.
     *
     * Sans cela, choisir « banque », désigner la BGFI, puis revenir à « caisse » laisserait
     * la BGFI dans l'état : les champs auraient disparu de l'écran, et les valeurs
     * partiraient quand même dans l'écriture. C'est l'inverse de ce que « les deux doivent
     * être en relation » demande.
     */
    public function test_changer_de_support_efface_le_compte_et_le_mode(): void
    {
        $bgfi = $this->banque('BGFI');
        $facture = $this->facture(portee: true);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('recouvrement.saisie')
            ->set('encTiers', $facture->client)
            ->set('encSupport', 'banque')
            ->set('encCompte', (string) $bgfi->id)
            ->set('encMode', 'Chèque')
            ->set('encReference', 'CHQ 4455');

        $this->assertSame((string) $bgfi->id, (string) $ecran->get('encCompte'));

        $ecran->set('encSupport', 'caisse');

        $this->assertSame('', (string) $ecran->get('encCompte'), 'Le compte du support précédent ne survit pas.');
        $this->assertSame('', (string) $ecran->get('encMode'), 'Le mode non plus.');
        $this->assertSame('', (string) $ecran->get('encReference'), 'La référence non plus.');
    }

    // ------------------------------------------------------------------ aux impayés

    /**
     * La même cascade à l'écran « Porter à l'état », avec les mêmes mots.
     *
     * **C'est le fond de la demande du 01/10** : les deux saisies ne proposaient pas la même
     * chose — l'une une liste figée où la banque était fondue dans le mode, l'autre un champ
     * de texte libre. Deux écrans qui posent la même question doivent la poser avec les mêmes
     * mots, sans quoi la même opération s'enregistre de deux façons et aucun total ne tombe
     * juste. Ils partagent désormais le composant `x-moyen-de-paiement`.
     */
    public function test_aux_impayes_la_cascade_est_la_meme(): void
    {
        $bgfi = $this->banque('BGFI');
        $facture = $this->facture();

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.impayes-porter')
            ->set('client', $facture->client)
            ->set('factureId', (string) $facture->id)
            ->set('pRegle', '100000')
            ->set('pSupport', 'banque')
            ->set('pCompte', (string) $bgfi->id)
            ->set('pMode', 'Chèque');

        $ecran->assertSeeHtml('wire:model.live="pCompte"')
            ->assertSee('BGFI', false);

        $ecran->set('pSupport', 'caisse');

        $this->assertSame('', (string) $ecran->get('pCompte'));
        $this->assertSame('', (string) $ecran->get('pMode'));
        $ecran->assertDontSeeHtml('wire:model.live="pCompte"');
    }

    // ------------------------------------------------------------------ le décor

    private function banque(string $nom): Banque
    {
        return $this->compte_(nom: $nom, type: Banque::BANQUE);
    }

    /** Un portefeuille mobile : c'est un compte, pas un moyen. Voir `Banque::MOBILE`. */
    private function portefeuille(string $nom): Banque
    {
        return $this->compte_(nom: $nom, type: Banque::MOBILE);
    }

    private function compte_(string $nom, string $type): Banque
    {
        return Banque::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => $nom,
            'nom_normalise' => Banque::clePour($nom),
            'type' => $type,
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

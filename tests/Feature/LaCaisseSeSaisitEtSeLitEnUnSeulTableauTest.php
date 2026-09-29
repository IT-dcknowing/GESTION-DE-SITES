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
 * La caisse se saisit, et se lit dans un seul tableau.
 *
 * **Deux demandes du 29/09, et elles se tiennent.**
 *
 * 1. « Mettre un bouton dans cette page caisse et permettre d'ouvrir le formulaire et
 *    saisir […] on doit avoir le bouton encaissement et décaissement. »
 * 2. « J'ai pas demandé de faire ce tableau : les deux tableaux doivent rester en un, mais
 *    à travers le filtre on pourra retirer. »
 *
 * **Le bouton est sur la caisse, l'écriture va ailleurs.** Ce n'est pas une contradiction,
 * c'est la réponse à « où doit-on le mettre ». Le geste appartient à la caisse — on compte
 * des espèces — mais l'écriture doit aller là où **tout le reste de l'application lit** :
 * `encaissements` pour une entrée, `charges` pour une sortie. C'est de là que se lisent la
 * trésorerie, la balance âgée et le résultat. Écrire dans `mouvements_caisse` aurait créé
 * un mouvement d'argent que seul cet écran connaîtrait — et aurait rompu la chaîne des
 * soldes annoncés, qui est la seule preuve qu'aucune ligne du journal n'a été perdue.
 */
class LaCaisseSeSaisitEtSeLitEnUnSeulTableauTest extends TestCase
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

    // ------------------------------------------------------------------ la saisie

    public function test_une_entree_saisie_part_dans_les_encaissements(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'entree')
            ->set('saisieDate', now()->toDateString())
            ->set('saisieMontant', 150_000)
            ->set('saisieLibelle', 'Apport de la direction')
            ->set('saisieTiers', 'MINLIN YANNICK')
            ->set('saisieVilleId', (string) $this->ville->id)
            ->call('enregistrerLaSaisie')
            ->assertHasNoErrors();

        $ecriture = Encaissement::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(150_000, (int) $ecriture->montant);
        // Le moyen est imposé : une caisse tient des espèces, un chèque n'entre pas dans
        // un tiroir. Se tromper ferait apparaître en caisse de l'argent qui n'y est jamais
        // passé.
        $this->assertSame('ESPÈCES', $ecriture->moyen);
        // Et elle porte un atelier : la trésorerie retient par `whereIn('site_id', …)`, et
        // un atelier nul n'entre dans aucun `whereIn`.
        $this->assertSame($this->site->id, $ecriture->site_id);

        // Rien n'est écrit dans le journal du logiciel : cette table porte ce que le
        // logiciel a imprimé, et sa chaîne de soldes prouve qu'aucune ligne n'a été perdue.
        $this->assertSame(0, MouvementCaisse::withoutGlobalScopes()->count());
    }

    public function test_une_sortie_saisie_part_dans_les_charges(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'sortie')
            ->set('saisieDate', now()->toDateString())
            ->set('saisieMontant', 40_000)
            ->set('saisieLibelle', 'Carburant')
            ->set('saisieVilleId', (string) $this->ville->id)
            ->call('enregistrerLaSaisie')
            ->assertHasNoErrors();

        $charge = Charge::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(40_000, (int) $charge->montant);
        $this->assertSame('Carburant', $charge->libelle);
        $this->assertSame('ESPÈCES', $charge->moyen);
        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    /** Sans montant ni objet, rien n'est écrit — et l'écran dit pourquoi. */
    public function test_une_saisie_incomplete_est_refusee(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'entree')
            ->set('saisieMontant', '')
            ->set('saisieLibelle', '')
            ->call('enregistrerLaSaisie')
            ->assertHasErrors(['saisieMontant', 'saisieLibelle']);

        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    /**
     * L'écran était en lecture seule, et c'est à ce titre qu'il avait été ouvert largement.
     *
     * Le responsable d'atelier continue donc de lire sans écrire — même règle que pour une
     * pièce fournisseur.
     */
    public function test_le_responsable_de_site_lit_mais_n_ecrit_pas(): void
    {
        $ecran = Volt::actingAs($this->compte('responsable_site'))->test('pilotage.caisse');

        $this->assertFalse($ecran->instance()->peutSaisir);
        // Le bouton lui-même, et non le mot : l'encart d'explication cite les deux gestes
        // pour dire ce que la page permet, et le citer n'est pas l'offrir.
        $ecran->assertDontSeeHtml('wire:click="ouvrirLaSaisie');

        // Et l'action reste gardée : masquer un bouton ne ferme pas une porte.
        $ecran->call('enregistrerLaSaisie');

        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ le tableau unique

    /**
     * Un seul tableau, et le filtre pour n'en voir qu'une sorte.
     *
     * Deux tableaux côte à côte obligeaient à lire deux fois et à rapprocher de tête ce
     * qui s'est passé dans la caisse ce jour-là.
     */
    public function test_le_journal_et_les_saisies_sont_dans_le_meme_tableau(): void
    {
        $this->mouvementDuJournal('APPROV CAISSE', 270_000);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'entree')
            ->set('saisieDate', now()->toDateString())
            ->set('saisieMontant', 150_000)
            ->set('saisieLibelle', 'Apport de la direction')
            ->set('saisieVilleId', (string) $this->ville->id)
            ->call('enregistrerLaSaisie');

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse');

        $this->assertCount(2, $ecran->instance()->mouvements);
        $ecran->assertSee('APPROV CAISSE')->assertSee('Apport de la direction');

        // Les totaux comptent les deux : ce sont de vraies espèces entrées dans le tiroir.
        $this->assertSame(420_000, $ecran->instance()->kpis['entrees']);

        // Le filtre retire l'une ou l'autre.
        $ecran->set('origineFiltre', 'journal');
        $this->assertCount(1, $ecran->instance()->mouvements);
        $this->assertSame(270_000, $ecran->instance()->kpis['entrees']);

        $ecran->set('origineFiltre', 'saisie');
        $this->assertCount(1, $ecran->instance()->mouvements);
        $this->assertSame(150_000, $ecran->instance()->kpis['entrees']);
    }

    /**
     * Le solde annoncé reste au journal, et à lui seul.
     *
     * C'est la chaîne qui prouve qu'aucune ligne n'a été perdue à l'import. Une écriture
     * saisie ici n'en a pas — le logiciel ne la connaît pas encore — et lui en calculer un
     * ferait passer un cumul pour une annonce.
     */
    public function test_une_saisie_ne_porte_pas_de_solde_annonce(): void
    {
        $this->mouvementDuJournal('APPROV CAISSE', 270_000, solde: 900_000);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'entree')
            ->set('saisieDate', now()->toDateString())
            ->set('saisieMontant', 150_000)
            ->set('saisieLibelle', 'Apport')
            ->set('saisieVilleId', (string) $this->ville->id)
            ->call('enregistrerLaSaisie');

        $lignes = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->instance()->mouvements;

        $saisie = $lignes->firstWhere('origine', 'saisie');
        $journal = $lignes->firstWhere('origine', 'journal');

        $this->assertNull($saisie->solde_annonce);
        $this->assertSame(900_000, (int) $journal->solde_annonce);
    }

    // ------------------------------------------------------------------ le décor

    private function mouvementDuJournal(string $libelle, int $montant, ?int $solde = null): MouvementCaisse
    {
        return MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'date' => now()->subDay(),
            'sens' => MouvementCaisse::ENTREE,
            'libelle' => $libelle,
            'montant' => $montant,
            'solde_annonce' => $solde,
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

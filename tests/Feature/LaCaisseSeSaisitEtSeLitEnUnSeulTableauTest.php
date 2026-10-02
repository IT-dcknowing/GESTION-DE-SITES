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
use Modules\Noyau\Imports\Services\ChaineDeSolde;
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

    // ------------------------------------------------------------------ les trois vues

    /**
     * Les trois vues de la caisse, demandées le 30/09.
     *
     * « Tu feras trois sous-boutons — caisse consolidée, caisse saisie ici, caisse importée —
     * avec chacun ses KPI, ses filtres et son tableau. »
     *
     * **Ce ne sont pas trois tableaux, et ce test le dit** : c'est un tableau et trois
     * lectures. La décision du 29/09 tient — « les deux tableaux doivent rester en un » — et
     * la raison aussi : une entrée en espèces est une entrée en espèces, qu'un fichier
     * l'apporte ou qu'on la tape. Ce qui change d'une vue à l'autre, c'est **ce qu'on peut
     * dire** de ces lignes.
     */
    public function test_les_trois_vues_decoupent_le_meme_tableau(): void
    {
        $this->mouvementDuJournal('Vente comptant', 300_000);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'entree')
            ->set('saisieDate', now()->toDateString())
            ->set('saisieMontant', '120000')
            ->set('saisieLibelle', 'Acompte client')
            ->call('enregistrerLaSaisie')
            ->assertHasNoErrors();

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse');

        // Consolidée : les deux sources.
        $this->assertSame('consolidee', $ecran->instance()->vue);
        $this->assertSame(2, $ecran->instance()->mouvements->count());
        $this->assertSame(420_000, $ecran->instance()->kpis['entrees']);

        // Saisie dans l'application : la nôtre seule.
        $ecran->set('origineFiltre', 'saisie');
        $this->assertSame('saisie', $ecran->instance()->vue);
        $this->assertSame(1, $ecran->instance()->mouvements->count());
        $this->assertSame(120_000, $ecran->instance()->kpis['entrees']);

        // Caisse importée : le journal seul.
        $ecran->set('origineFiltre', 'journal');
        $this->assertSame('importee', $ecran->instance()->vue);
        $this->assertSame(1, $ecran->instance()->mouvements->count());
        $this->assertSame(300_000, $ecran->instance()->kpis['entrees']);
    }

    /**
     * La vue « Saisie dans l'application » n'affiche aucun solde de chaîne.
     *
     * **Et c'est la moitié qui compte de cette demande.** Le solde d'avant la période, celui
     * de fin et l'écart avec le fichier se lisent tous sur la **chaîne des soldes annoncés**
     * du journal — c'est le logiciel qui les imprime ligne à ligne. Une écriture saisie ici
     * n'en a pas, et ne peut pas en avoir. Les afficher là rendrait des nombres qui ne
     * parlent pas de ce qu'on regarde, ce qui est le pire défaut d'un indicateur : on ne le
     * croit pas faux, on le croit vrai.
     */
    public function test_la_vue_de_la_saisie_ne_montre_pas_les_soldes_du_journal(): void
    {
        $this->mouvementDuJournal('Vente comptant', 300_000, solde: 800_000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse');

        $ecran->assertSee('Solde avant la période', false)
            ->assertSee('Solde à la fin de la période', false);

        $ecran->set('origineFiltre', 'saisie')
            ->assertDontSee('Solde avant la période', false)
            ->assertDontSee('Solde à la fin de la période', false)
            // À la place, le seul chiffre qui lui appartienne.
            ->assertSee('À retrouver au prochain état de caisse', false);
    }

    /** Les trois boutons annoncent chacun ce qu'ils portent, avant qu'on clique. */
    public function test_les_boutons_de_vue_disent_combien_de_lignes_ils_portent(): void
    {
        $this->mouvementDuJournal('Vente comptant', 300_000);
        $this->mouvementDuJournal('Vente comptant', 100_000);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->assertSee('Caisse consolidée', false)
            ->assertSee('Saisie dans l’application', false)
            ->assertSee('Caisse importée', false)
            // Deux au journal, zéro saisie, deux au total.
            ->assertSeeHtml('(2)')
            ->assertSeeHtml('(0)');
    }

    // ------------------------------------------------------------------ le pont entre les deux

    /**
     * La Trésorerie porte un bouton qui mène à la Caisse.
     *
     * **Demandé le 29/09** : « mets cette page Caisse dans la page trésorerie, ajoute le bouton
     * caisse, ce bouton devra maintenant ouvrir la page ». Le lien existait déjà, mais noyé au
     * milieu d'un paragraphe d'avertissement : personne ne va chercher un bouton dans un
     * paragraphe.
     *
     * **Pourquoi les deux écrans restent deux, et pourquoi le passage doit être immédiat.** Ils
     * ne lisent pas les mêmes sources : la Trésorerie porte les règlements clients et les
     * charges — ce que l'application connaît — et la Caisse porte en plus le journal du logiciel
     * et l'écart entre les deux. Les fondre effacerait cet écart, qui est justement ce qu'un
     * comptable cherche. Passer de l'un à l'autre est donc son geste le plus fréquent.
     */
    public function test_la_tresorerie_mene_a_la_caisse_par_un_bouton(): void
    {
        Volt::actingAs($this->compte('gerant'))->test('pilotage.tresorerie')
            ->assertOk()
            // Un bouton, et non une mention dans un paragraphe : la classe le dit.
            ->assertSeeHtml('class="bouton"')
            ->assertSeeHtml('href="'.route('caisse').'"');
    }

    // ------------------------------------------------------------------ la liste des villes

    /**
     * La liste déroulante Ville de la saisie propose des villes, pas du JSON.
     *
     * **Le défaut relevé le 29/09** : « Ville *, /caisse : corrige la liste déroulante ». Elle
     * affichait une ligne de JSON par option — l'objet Ville tout entier, accolades comprises.
     *
     * La cause tenait à deux lectures différentes d'une même valeur. `optionsVilles()` rend des
     * **modèles** Ville, et le filtre de période sait les lire : il parcourt la collection et
     * prend `$ville->nom`. Le composant `x-champ`, lui, parcourt ses options en
     * `valeur => libellé` : reçus tels quels, les modèles donnaient la clé numérique de la
     * collection en valeur et **le modèle lui-même en libellé**, que Blade rend en JSON. Aucune
     * erreur nulle part, et un champ requis illisible au milieu d'un formulaire d'écriture.
     *
     * Ce test regarde le rendu et non la propriété : c'est le rendu qui était faux.
     */
    public function test_la_liste_des_villes_de_la_saisie_affiche_des_noms(): void
    {
        // Le champ ne paraît qu'à partir de deux villes : avec une seule, il n'y a pas de
        // choix à faire et l'écriture est rangée d'office.
        Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'SPD', 'nom' => 'San Pédro', 'est_actif' => true,
        ]);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->call('ouvrirLaSaisie', 'entree');

        $ecran->assertSee('<option value="'.$this->ville->id.'">Abidjan</option>', false)
            ->assertDontSee('entreprise_id', false);
    }

    /** Et les options sont bien un tableau `id => nom`, jamais des modèles. */
    public function test_les_villes_de_la_saisie_sont_un_tableau_de_noms(): void
    {
        $villes = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->instance()->villesDeSaisie;

        $this->assertSame([$this->ville->id => 'Abidjan'], $villes);
    }

    /**
     * Un compte qui ne voit qu'une ville n'a pas de champ Ville, et le formulaire tient.
     *
     * `optionsVilles()` rend `null` dans ce cas, et la vue compte ces villes pour décider
     * d'afficher le champ : `count(null)` est fatal en PHP 8. Le formulaire entier serait
     * tombé pour un chef d'atelier — c'est-à-dire pour la plupart de ceux qui saisissent.
     */
    public function test_un_compte_a_une_seule_ville_garde_un_formulaire_entier(): void
    {
        $ecran = Volt::actingAs($this->compte('responsable_site'))->test('pilotage.caisse');

        $this->assertSame([], $ecran->instance()->villesDeSaisie);
        $ecran->assertOk();
    }

    /**
     * L'écran porte notre solde et son sens sur chaque ligne du journal.
     *
     * **Demandé le 02/10** : « mets le sens du solde, D si débit, C si crédit, sur chaque
     * ligne jusqu'à la dernière ». L'écran ne montrait que le solde recopié du classeur, qui
     * manque sur toutes les lignes qu'il n'annonce pas.
     */
    public function test_chaque_ligne_du_journal_porte_notre_solde_et_son_sens(): void
    {
        $this->mouvementDuJournal('Approvisionnement', 500_000, 500_000);
        $this->mouvementDuJournal('Règlement client', 120_000, 620_000);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse');

        $soldes = collect($ecran->instance()->mouvements)->pluck('solde_calcule')->all();

        // Rangés du plus récent au plus ancien, comme le tableau les affiche.
        $this->assertSame([620_000, 500_000], $soldes);

        $ecran->assertSee('D/C')->assertSee('Notre solde');
    }

    /**
     * Un solde négatif se dit « C », et ne se corrige pas.
     *
     * Il vient de l'ordre de saisie : une sortie écrite avant les entrées du même jour. Le
     * classeur du propriétaire en porte, et les signaler comme des erreurs serait faux.
     */
    public function test_un_solde_negatif_se_dit_au_credit(): void
    {
        $sortie = $this->mouvementDuJournal('Décaissement', 80_000, -80_000);
        $sortie->update(['sens' => MouvementCaisse::SORTIE]);

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse');

        $this->assertSame([-80_000], collect($ecran->instance()->mouvements)->pluck('solde_calcule')->all());
        $this->assertSame('C', ChaineDeSolde::sens(-80_000));
    }

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

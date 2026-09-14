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
use Modules\Noyau\Exploitation\Modeles\CommentaireEcartRecouvrement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Modeles\SaisieJournaliere;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les trois fonctions qui n'avaient jamais servi.
 *
 * **Pourquoi elles méritaient leur propre fichier.** Le journal des relances, les
 * commentaires d'écart de la synthèse et la saisie journalière étaient construits et
 * atteignables, mais aucune ligne n'était jamais passée dedans — zéro relance, zéro
 * commentaire, quatre journées de carnet pour trois villes et huit mois d'exercice. Du
 * code jamais exécuté n'est pas du code qui marche, c'est du code dont personne n'a
 * encore vu le défaut. Ces trois-là se ressemblent par leur rôle : elles n'entrent dans
 * aucun total, elles racontent. C'est précisément ce qui les rendait faciles à oublier.
 *
 * Les tests vérifient donc deux choses : que le geste s'enregistre et se relit, et qu'il
 * ne déplace aucun chiffre comptable.
 */
class PremierUsageTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha', 'est_active' => true]);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Le carnet de bord quotidien
    |--------------------------------------------------------------------------
    */

    /**
     * Le carnet du jour s'écrit au fil de la frappe, et se retrouve le lendemain.
     *
     * Il n'a pas de bouton « enregistrer » : chaque champ se sauve dès qu'on le quitte.
     * C'est un choix défendable pour un carnet — on y revient dix fois dans la journée —
     * mais il rend le défaut silencieux : si l'écriture ne part pas, rien ne le dit, et
     * l'on s'en aperçoit le lendemain devant une page vide.
     */
    public function test_le_carnet_du_jour_s_ecrit_champ_par_champ_et_se_relit(): void
    {
        $responsable = $this->compte('responsable_site');

        Volt::actingAs($responsable)->test('saisie.saisie-du-jour')
            ->set('commentaireCA', 'Grosse sortie de carrosserie.')
            ->set('vehiculesSansFacture', 3);

        $this->assertDatabaseHas('saisies_journalieres', [
            'site_id' => $this->site->id,
            'commentaire_ca' => 'Grosse sortie de carrosserie.',
            'vehicules_sans_facture' => 3,
        ]);

        // Une seule ligne par site et par jour : deux champs modifiés coup sur coup ne
        // doivent pas en créer deux, sans quoi le lendemain en relirait une au hasard.
        $this->assertSame(1, SaisieJournaliere::withoutGlobalScopes()->count());

        // Le lendemain, ou après un rechargement : le carnet se rouvre tel qu'il était.
        $relu = Volt::actingAs($responsable)->test('saisie.saisie-du-jour');

        $this->assertSame('Grosse sortie de carrosserie.', $relu->get('commentaireCA'));
        $this->assertSame(3, (int) $relu->get('vehiculesSansFacture'));
    }

    /** Le carnet ne fabrique aucune écriture : c'est un commentaire, pas une opération. */
    public function test_le_carnet_n_ecrit_ni_facture_ni_encaissement_ni_charge(): void
    {
        Volt::actingAs($this->compte('responsable_site'))->test('saisie.saisie-du-jour')
            ->set('commentaireTresorerie', 'Remise en banque effectuée.')
            ->set('commentaireCharges', 'Achat de pièces pour deux dossiers.');

        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('encaissements', 0);
        $this->assertDatabaseCount('charges', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Le commentaire d'écart de la synthèse
    |--------------------------------------------------------------------------
    */

    /**
     * L'encadrement explique l'écart, et vider le champ efface l'explication.
     *
     * Un commentaire vidé qui resterait en base laisserait une ligne blanche dans la
     * revue : on lirait « commenté » là où personne n'a rien à dire, ce qui est pire que
     * rien puisqu'on cesse alors de distinguer les périodes expliquées des autres.
     */
    public function test_le_superviseur_explique_l_ecart_puis_retire_son_explication(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');

        Volt::actingAs($superviseur)->test('recouvrement.synthese')
            ->set('commentaires.mois', 'Trois gros dossiers sont passés en mise en demeure.')
            ->call('enregistrerCommentaire', 'mois');

        $this->assertDatabaseHas('commentaires_ecart_recouvrement', [
            'periode' => 'mois',
            'texte' => 'Trois gros dossiers sont passés en mise en demeure.',
            'user_id' => $superviseur->id,
        ]);

        Volt::actingAs($superviseur)->test('recouvrement.synthese')
            ->set('commentaires.mois', '   ')
            ->call('enregistrerCommentaire', 'mois');

        $this->assertSame(0, CommentaireEcartRecouvrement::withoutGlobalScopes()->count());
    }

    /**
     * Commenter un écart relève de l'encadrement, et le contrôle est dans l'action.
     *
     * L'agent ne voit pas cet écran. Mais ne pas le voir n'est pas ne pas pouvoir
     * l'appeler : une méthode Livewire reste atteignable depuis le navigateur même quand
     * l'écran qui la porte est fermé. C'est là, et pas dans le menu, que la règle tient.
     */
    public function test_l_agent_ne_commente_pas_l_ecart_meme_en_appelant_l_action(): void
    {
        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.synthese')
            ->set('commentaires.mois', "Tentative depuis un compte sans mandat.")
            ->call('enregistrerCommentaire', 'mois');

        $this->assertSame(0, CommentaireEcartRecouvrement::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Le journal des relances
    |--------------------------------------------------------------------------
    */

    /**
     * Le journal montre qui a relancé, quand, à quel niveau — et se lit par les trois rôles.
     *
     * C'est la pièce sur laquelle repose l'ouverture des niveaux N4 et N5 à tous : le
     * contrôle n'est plus avant le geste, il est après, dans ce journal. S'il n'affichait
     * pas le nom de l'auteur, l'ouverture ne serait adossée à rien.
     */
    public function test_le_journal_des_relances_nomme_l_auteur_de_chaque_niveau(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $this->facture('NSIA ASSURANCES', 'F-001', 900_000, now()->subDays(120));

        foreach ([1, 3, 5] as $niveau) {
            Volt::actingAs($agent)->test('recouvrement.saisie')
                ->set('relTiers', 'NSIA ASSURANCES')
                ->set('relCanal', 'Téléphone')
                ->set('relNiveau', $niveau)
                ->set('relStatut', 'En cours')
                ->set('relInterlocuteur', 'Mme Koffi, comptabilité')
                ->call('enregistrerRelance')
                ->assertHasNoErrors();
        }

        $this->assertSame(3, RelanceRecouvrement::withoutGlobalScopes()->count());

        foreach (['agent_recouvrement', 'superviseur_recouvrement', 'gerant'] as $role) {
            $lecteur = $role === 'agent_recouvrement' ? $agent : $this->compte($role);

            $this->actingAs($lecteur)->get(route('recouvrement.relances'))
                ->assertOk()
                ->assertSee('NSIA ASSURANCES')
                ->assertSee($agent->name)
                ->assertSee('Mme Koffi, comptabilité');
        }
    }

    /** Une relance n'est pas une écriture : elle ne bouge ni la créance ni la trésorerie. */
    public function test_une_relance_ne_deplace_aucun_chiffre(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $this->facture('NSIA ASSURANCES', 'F-001', 900_000, now()->subDays(120));

        $this->actingAs($agent);
        $avant = \Modules\Noyau\Exploitation\Services\Recouvrement::facturesOuvertes()
            ->sum(fn (Facture $f) => \Modules\Noyau\Exploitation\Services\Recouvrement::reste($f));

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('relTiers', 'NSIA ASSURANCES')
            ->set('relCanal', 'LRAR')
            ->set('relNiveau', 4)
            ->set('relStatut', 'Promesse de règlement')
            ->set('relPromis', 400_000)
            ->call('enregistrerRelance')
            ->assertHasNoErrors();

        $this->actingAs($agent);
        $apres = \Modules\Noyau\Exploitation\Services\Recouvrement::facturesOuvertes()
            ->sum(fn (Facture $f) => \Modules\Noyau\Exploitation\Services\Recouvrement::reste($f));

        // Un montant promis reste une promesse. Le compter comme encaissé ferait
        // disparaître une créance que personne n'a réglée.
        $this->assertSame($avant, $apres);
        $this->assertDatabaseCount('encaissements', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Les deux fichiers importés que rien n'affichait
    |--------------------------------------------------------------------------
    */

    /**
     * La caisse et les fournisseurs ont enfin un écran.
     *
     * **Le défaut réparé.** Deux des huit formats d'import écrivaient dans des tables
     * qu'aucune page ne lisait : mille cent cinquante-cinq mouvements de caisse et mille
     * huit cent quarante-huit factures fournisseurs dormaient en base depuis leur dépôt.
     * Une donnée importée que personne ne peut voir n'a pas été importée, elle a été
     * rangée — et tout le travail de dépôt, de contrôle et de correction ne servait à rien
     * tant que la dernière marche manquait.
     */
    public function test_la_caisse_et_les_fournisseurs_s_affichent(): void
    {
        $gerant = $this->compte('gerant');

        \Modules\Noyau\Imports\Modeles\MouvementCaisse::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->site->ville_id,
            'date' => now()->subDays(3)->toDateString(),
            'sens' => 'sortie',
            'libelle' => 'Achat de pièces',
            'montant' => 150000,
            'beneficiaire' => 'Fournisseur pièces auto',
        ]);

        \Modules\Noyau\Imports\Modeles\FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->site->ville_id,
            'fournisseur' => 'PIECES AUTO CI',
            'numero_piece' => 'FF-0001',
            'date_facture' => now()->subDays(200)->toDateString(),
            'montant' => 900000,
            'montant_regle' => 400000,
            'reste_a_payer' => 500000,
        ]);

        $this->actingAs($gerant)->get(route('caisse'))
            ->assertOk()
            ->assertSee('Achat de pièces')
            ->assertSee('Fournisseur pièces auto');

        $this->actingAs($gerant)->get(route('fournisseurs'))
            ->assertOk()
            ->assertSee('PIECES AUTO CI')
            ->assertSee('FF-0001');
    }

    /**
     * La dette fournisseur ne compte que ce qu'on doit, pas ce qu'on a trop payé.
     *
     * Six pièces réelles portent un reste négatif — des trop-payés. Les additionner à la
     * dette faisait afficher un total inférieur à la somme des factures réellement
     * ouvertes : un total plus petit que l'une de ses parts se lit comme une erreur de
     * calcul, et fait douter de tout l'écran.
     */
    public function test_un_trop_paye_ne_vient_pas_en_deduction_de_la_dette(): void
    {
        $gerant = $this->compte('gerant');

        foreach ([['DU', 800000], ['TROP-PAYE', -200000]] as [$nom, $reste]) {
            \Modules\Noyau\Imports\Modeles\FactureFournisseur::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entreprise->id,
                'ville_id' => $this->site->ville_id,
                'fournisseur' => $nom,
                'numero_piece' => 'FF-'.$nom,
                'date_facture' => now()->subDays(30)->toDateString(),
                'montant' => 800000,
                'montant_regle' => 800000 - $reste,
                'reste_a_payer' => $reste,
            ]);
        }

        $this->actingAs($gerant)->get(route('fournisseurs'))
            ->assertOk()
            // La dette reste 800 000, et non 600 000.
            ->assertSee('800 000')
            ->assertSee('trop-payé à réclamer');
    }

    // ------------------------------------------------------------------ utilitaires

    private function facture(string $tiers, string $numero, int $montant, $date): Facture
    {
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'n_facture' => $numero,
            'client' => $tiers,
            'activite' => 'Sinistre',
            'montant' => $montant,
        ]);

        return Facture::withoutGlobalScopes()->withSum('encaissements', 'montant')->find($facture->id);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@premier-usage.test',
            'password' => Hash::make('motdepasse123'),
            'est_actif' => true,
        ]);
        $compte->assignRole($role);

        // Le périmètre d'un responsable se lit sur le site, et non sur son compte :
        // `Site::visiblesPour` interroge `sites.responsable_id`. Sans cette ligne, le
        // responsable ouvre un écran sans lieu et le carnet n'a nulle part où s'écrire.
        if ($role === 'responsable_site') {
            $this->site->forceFill(['responsable_id' => $compte->id])->save();
        }

        return $compte->fresh();
    }
}

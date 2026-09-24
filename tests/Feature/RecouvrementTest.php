<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Modeles\Referentiel;
use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\AccesRecouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le module Recouvrement.
 *
 * Deux choses doivent être prouvées, et la seconde compte plus que la première :
 *
 * 1. que les chiffres sont justes — un reste à payer faux fait relancer un client qui
 *    a déjà réglé, ce qui coûte une relation commerciale ;
 * 2. que le partage tient **par l'adresse**, et pas seulement par le menu. Une page
 *    cachée de la barre latérale reste atteignable en tapant son URL ; c'est le seul
 *    endroit où la séparation des fonctions se joue vraiment.
 */
class RecouvrementTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Le partage des vues
    |--------------------------------------------------------------------------
    */

    public function test_l_agent_n_atteint_ni_la_synthese_ni_la_piste_d_audit(): void
    {
        $agent = $this->compte('agent_recouvrement');

        // Cachées dans la barre latérale ; ce qui compte est que l'adresse ne s'ouvre pas.
        $this->actingAs($agent)->get(route('recouvrement.synthese'))
            ->assertRedirect(route('recouvrement.tableau-de-bord'));
        $this->actingAs($agent)->get(route('recouvrement.audit'))
            ->assertRedirect(route('recouvrement.tableau-de-bord'));

        // Les cinq autres lui sont bien ouvertes.
        foreach (['saisie', 'balance', 'extrait', 'relances', 'encaissements'] as $page) {
            $this->actingAs($agent)->get(route('recouvrement.'.$page))->assertOk();
        }
    }

    public function test_le_superviseur_lit_la_synthese_et_la_piste_d_audit(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');

        $this->actingAs($superviseur)->get(route('recouvrement.synthese'))->assertOk();

        /*
         * La piste d'audit lui était fermée au motif qu'il y figure lui-même. Le motif ne
         * tient pas : superviser, c'est vérifier le travail de son équipe, et le journal
         * horodaté est le seul instrument qui le permette. Le lui fermer revenait à lui
         * demander de contrôler à l'aveugle. Ses propres gestes y restent inscrits, et
         * c'est le gérant qui les relit — la chaîne n'a pas de trou, elle a un étage de
         * plus.
         */
        $this->actingAs($superviseur)->get(route('recouvrement.audit'))->assertOk();

        // L'agent, lui, n'y entre toujours pas : on ne confie pas à quelqu'un la lecture
        // du registre où ses propres gestes sont consignés, sans personne au-dessus.
        $this->actingAs($this->compte('agent_recouvrement'))->get(route('recouvrement.audit'))
            ->assertRedirect(route('recouvrement.tableau-de-bord'));
    }

    public function test_la_comptabilite_et_le_superviseur_de_ville_consultent_sans_ecrire(): void
    {
        /*
         * Deux rôles qui subissent l'encours sans le poursuivre. La comptabilité encaisse
         * ce que le recouvrement réclame et décrochait le téléphone sans pouvoir lire ce
         * qu'un client devait ; le superviseur de ville répond d'un chiffre d'affaires dont
         * l'encours est la moitié qu'on ne lui montrait pas.
         */
        foreach (['caissier', 'responsable_ville'] as $role) {
            $compte = $this->compte($role);

            foreach (['tableau-de-bord', 'balance', 'courtiers', 'clients', 'extrait', 'relances', 'encaissements'] as $page) {
                $this->actingAs($compte)->get(route('recouvrement.'.$page))
                    ->assertOk("La page $page doit être ouverte à $role.");
            }

            // Consulter n'est pas relancer. La saisie leur est fermée : la comptabilité a
            // son propre écran d'encaissement, et une seconde porte vers la même table
            // n'aurait apporté qu'une chance de double saisie.
            foreach (['saisie', 'synthese', 'audit'] as $page) {
                $this->actingAs($compte)->get(route('recouvrement.'.$page))
                    ->assertRedirect(route('recouvrement.tableau-de-bord'));
            }

            // Et ils n'écrivent pas le référentiel : ni facture, ni tiers.
            $this->assertFalse(AccesRecouvrement::peutCreerUneFacture($compte));
            $this->assertFalse(AccesRecouvrement::peutCreerUnTiers($compte));
            $this->assertFalse(AccesRecouvrement::peutSaisir($compte));
        }
    }

    public function test_le_tableau_de_bord_d_un_consultant_n_est_pas_vide(): void
    {
        /*
         * Le piège que la vue « mes dossiers » tendait. Elle borne l'agent à ce qu'il a
         * lui-même relancé — ce qui est juste pour lui, et absurde pour la comptabilité,
         * qui n'a jamais relancé personne et serait arrivée sur un tableau vide en
         * concluant que le module ne marche pas.
         */
        $this->facture('SIFCA', 'F-0001', 400_000, now()->subDays(40)->toDateString());

        $comptable = $this->compte('caissier');

        $this->assertTrue(AccesRecouvrement::voitToutLePortefeuille($comptable));
        $this->actingAs($comptable)->get(route('recouvrement.tableau-de-bord'))
            ->assertOk()
            ->assertSee('SIFCA');

        // L'agent, lui, garde sa vue bornée : sa charge de travail, pas celle des autres.
        $this->assertFalse(AccesRecouvrement::voitToutLePortefeuille($this->compte('agent_recouvrement')));
    }

    public function test_le_gerant_voit_les_neuf_pages(): void
    {
        $gerant = $this->compte('gerant');

        // Avec de la matière : une page qui se rend sur une base vide ne prouve pas
        // grand-chose. C'est en divisant, en ventilant et en formatant que les écrans
        // tombent — pas en affichant « aucune donnée ».
        $this->actingAs($gerant);
        $this->facture('NSIA ASSURANCES', 'F-001', 1_200_000, now()->subDays(120));
        $this->facture('GNA ASSURANCES', 'F-002', 300_000, now()->subDays(5));

        foreach (array_keys(AccesRecouvrement::PAGES) as $page) {
            $this->actingAs($gerant)->get(route('recouvrement.'.$page))->assertOk();
        }

        // L'extrait ouvert sur un tiers précis, comme depuis la balance âgée.
        $this->actingAs($gerant)
            ->get(route('recouvrement.extrait', ['tiers' => 'NSIA ASSURANCES']))
            ->assertOk()
            ->assertSee('EXTRAIT DE COMPTE');
    }

    public function test_le_module_est_ferme_aux_roles_etrangers(): void
    {
        $commercial = $this->compte('commercial');

        $this->actingAs($commercial)->get(route('recouvrement.saisie'))->assertRedirect();
    }

    /*
    |--------------------------------------------------------------------------
    | Les habilitations de niveau
    |--------------------------------------------------------------------------
    */

    /**
     * Les cinq niveaux sont ouverts à tous les rôles du module.
     *
     * **C'est la troisième version de cette règle, et il faut dire pourquoi.** Les niveaux
     * N4 et N5 ont d'abord été fermés à l'agent : l'enregistrement les refusait. Ils ont
     * ensuite été escaladés, enregistrés « en attente de validation ». La direction a
     * tranché pour l'ouverture complète : dans une équipe de deux personnes, faire
     * repasser chaque mise en demeure par un second compte n'ajoute pas de contrôle, cela
     * ajoute un délai — et sur une créance de plus de quatre-vingt-dix jours, le délai
     * coûte plus cher que le risque qu'il écarte.
     *
     * **Ce que ce test protège, c'est ce qui remplace le verrou.** Le statut choisi est
     * bien celui qui est retenu, et la relance porte le nom de celui qui l'a tracée. Sans
     * cette signature, l'ouverture serait une perte sèche : le contrôle a posteriori
     * n'existe que si l'on sait qui a fait quoi.
     */
    public function test_chaque_role_trace_une_mise_en_demeure_sous_son_nom(): void
    {
        $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(100));

        foreach (['agent_recouvrement', 'superviseur_recouvrement', 'gerant'] as $role) {
            $compte = $this->compte($role);

            Volt::actingAs($compte)->test('recouvrement.saisie')
                ->set('relTiers', 'NSIA ASSURANCES')
                ->set('relCanal', 'LRAR')
                ->set('relNiveau', 4)
                ->set('relStatut', 'En cours')
                ->call('enregistrerRelance')
                ->assertHasNoErrors();

            $relance = RelanceRecouvrement::withoutGlobalScopes()
                ->where('user_id', $compte->id)->sole();

            $this->assertSame(4, (int) $relance->niveau);

            // Le statut choisi est retenu tel quel : plus rien n'est mis en attente.
            $this->assertSame('En cours', $relance->statut,
                "Le rôle $role doit pouvoir engager une mise en demeure sans validation.");

            // La signature est ce qui rend l'ouverture tenable.
            $this->assertSame($compte->name, $relance->responsable);
        }

        $this->assertSame(3, RelanceRecouvrement::withoutGlobalScopes()->count());
    }

    /** Le contentieux, N5, suit la même règle : ouvert, daté, signé. */
    public function test_l_agent_engage_le_contentieux_et_le_journal_le_retient(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(200));

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('relTiers', 'NSIA ASSURANCES')
            ->set('relCanal', 'Huissier')
            ->set('relNiveau', 5)
            ->set('relStatut', 'Transmis au contentieux')
            ->call('enregistrerRelance')
            ->assertHasNoErrors();

        $relance = RelanceRecouvrement::withoutGlobalScopes()->sole();

        $this->assertSame(5, (int) $relance->niveau);
        $this->assertSame('Transmis au contentieux', $relance->statut);

        // Le journal d'audit est le contrôle qui remplace le verrou : s'il ne retenait
        // pas le geste, l'ouverture ne serait adossée à rien.
        $this->assertDatabaseHas('activity_log', [
            'causer_id' => $agent->id,
            'description' => 'Recouvrement — relance N5 tracée',
        ]);
    }

    /**
     * Créer la créance reste fermé à l'agent, et ce verrou-là ne bouge pas.
     *
     * L'ouverture des niveaux n'est pas l'ouverture de tout. Celui qui relance et encaisse
     * ne doit pas pouvoir créer la facture qu'il encaisse : c'est cette séparation-là qui
     * rend le journal des relances digne de foi, puisqu'elle interdit de fabriquer la
     * dette qu'on prétend recouvrer.
     */
    public function test_l_ouverture_des_niveaux_ne_touche_pas_a_la_creation_de_creance(): void
    {
        $agent = $this->compte('agent_recouvrement');

        $this->assertFalse(
            AccesRecouvrement::peutCreerUneFacture($agent),
            "L'agent ne crée pas la créance qu'il encaisse, quel que soit son niveau de relance.",
        );
    }

    /** La relance porte sa propre date, et non celle du formulaire d'encaissement. */
    public function test_la_relance_est_datee_par_son_propre_champ(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');
        $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(40));

        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            // La date de l'écriture recule pour saisir un règlement ancien : elle ne doit
            // plus emporter la date de la relance avec elle.
            ->set('dateTravail', now()->subMonths(3)->toDateString())
            ->set('relDate', now()->toDateString())
            ->set('relTiers', 'NSIA ASSURANCES')
            ->set('relCanal', 'Téléphone')
            ->set('relNiveau', 2)
            ->call('enregistrerRelance')
            ->assertHasNoErrors();

        $this->assertSame(
            now()->toDateString(),
            RelanceRecouvrement::withoutGlobalScopes()->sole()->date->toDateString(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | La séparation des fonctions
    |--------------------------------------------------------------------------
    */

    public function test_l_agent_ne_cree_ni_facture_ni_tiers(): void
    {
        $agent = $this->compte('agent_recouvrement');

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('facTiers', 'NOUVELLE ASSURANCE')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facNumero', 'F-999')
            ->set('facMontant', 500000)
            ->call('creerFacture');

        // Celui qui relance et encaisse ne crée pas la créance qu'il poursuit.
        $this->assertSame(0, Facture::withoutGlobalScopes()->count());

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('nouveauTiers', 'UN AUTRE TIERS')
            ->call('creerTiers');

        $this->assertNotContains('UN AUTRE TIERS', array_keys(Recouvrement::tiers($this->entreprise->id)));
    }

    /**
     * La facture créée porte son numéro de saisie — et sans lui, MySQL la refuse.
     *
     * **Le défaut, relevé par le propriétaire le 24/09 : « la création de la facture ne
     * fonctionne pas ».** `factures.numero` est NOT NULL sans valeur par défaut, et le
     * formulaire ne le posait pas. En production — MySQL en `STRICT_TRANS_TABLES` —
     * l'insertion échoue : le bouton ne fait rien, sans un mot à l'écran.
     *
     * **Pourquoi aucun test ne l'avait vu.** La suite tourne sur SQLite, qui accepte cette
     * même insertion sans broncher. Un test qui compte les lignes créées passait donc des
     * deux côtés. Celui-ci regarde la **valeur** plutôt que le compte : il est vrai sur
     * les deux moteurs, et c'est la seule forme qui protège.
     */
    /**
     * Un règlement descend jusqu'à un atelier, même quand sa facture n'en porte aucun.
     *
     * **Ce que cela répare, mesuré le 24/09.** 8 937 factures sur 11 332 n'ont pas
     * d'atelier : la colonne SITE des exports dit « ABIDJAN », et Abidjan en a deux, si
     * bien que l'import s'arrête à la ville. L'encaissement héritait de cet atelier nul.
     *
     * Or la Trésorerie retient les encaissements par `whereIn('site_id', …)`, et un
     * `site_id` nul n'entre dans aucun `whereIn` : le règlement aurait été enregistré, vu
     * par la balance âgée et l'extrait de compte, et **jamais affiché en trésorerie**.
     * Aucun encaissement n'avait encore été saisi ici ; le premier l'aurait rencontré.
     */
    public function test_un_encaissement_sur_une_facture_sans_atelier_en_recoit_un(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $this->declarerLeTiers('NSIA ASSURANCES');

        // Une facture reprise de l'import : sa ville est connue, son atelier ne l'est pas.
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->site->ville_id,
            'site_id' => null,
            'numero' => 'F-0001',
            'n_facture' => 'F-001',
            'date' => now()->subDays(30)->toDateString(),
            'client' => 'NSIA ASSURANCES',
            'activite' => 'Sinistre',
            'montant' => 500000,
        ]);

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('encTiers', 'NSIA ASSURANCES')
            ->set('encFactureId', (string) $facture->id)
            ->set('encMontant', '200000')
            ->set('encMode', 'CHÈQUE')
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $encaissement = Encaissement::withoutGlobalScopes()->firstOrFail();

        // Bouaké et San-Pédro n'ont qu'un atelier : la question ne s'y pose pas, et le
        // règlement y descend tout seul.
        $this->assertNotNull($encaissement->site_id, 'sans atelier, il disparaîtrait de la trésorerie');
        $this->assertSame($this->site->id, (int) $encaissement->site_id);
        $this->assertSame($facture->id, (int) $encaissement->facture_id);
    }

    public function test_la_facture_creee_porte_son_numero_de_saisie(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');
        $this->declarerLeTiers('NSIA ASSURANCES');

        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            ->set('facTiers', 'NSIA ASSURANCES')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facMontant', 750000)
            ->call('creerFacture')
            ->assertHasNoErrors();

        $facture = Facture::withoutGlobalScopes()->firstOrFail();

        $this->assertNotNull($facture->numero, 'sans numéro de saisie, MySQL refuse la ligne');
        $this->assertStringStartsWith('F-', $facture->numero);

        // Le n° de facture laissé vide se numérote tout seul : une créance saisie ici n'a
        // pas toujours de document d'atelier derrière elle, et un numéro inventé à la main
        // finit par se répéter.
        $this->assertNotNull($facture->n_facture);
        $this->assertStringStartsWith('NF-', $facture->n_facture);
    }

    public function test_la_description_d_une_facture_creee_est_conservee(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');
        $this->declarerLeTiers('NSIA ASSURANCES');

        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            ->set('facTiers', 'NSIA ASSURANCES')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facMontant', 750000)
            ->set('facObservations', 'Reprise de garantie, facture introuvable au logiciel.')
            ->call('creerFacture')
            ->assertHasNoErrors();

        // Six mois plus tard, personne ne se souvient pourquoi cette créance existe.
        $this->assertSame(
            'Reprise de garantie, facture introuvable au logiciel.',
            Facture::withoutGlobalScopes()->firstOrFail()->observations,
        );
    }

    public function test_le_superviseur_cree_une_facture_sans_doublon_de_numero(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');
        $this->declarerLeTiers('NSIA ASSURANCES');

        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            ->set('facTiers', 'NSIA ASSURANCES')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facNumero', 'F-100')
            ->set('facMontant', 750000)
            ->call('creerFacture')
            ->assertHasNoErrors();

        $this->assertSame(1, Facture::withoutGlobalScopes()->count());

        // Le même numéro pour le même tiers rend l'extrait de compte incontestable dans
        // le mauvais sens : on ne sait plus laquelle des deux est la bonne.
        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            ->set('facTiers', 'NSIA ASSURANCES')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facNumero', 'F-100')
            ->set('facMontant', 900000)
            ->call('creerFacture')
            ->assertHasErrors('facNumero');

        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
    }

    public function test_un_tiers_ne_se_cree_pas_deux_fois_a_la_casse_pres(): void
    {
        $gerant = $this->compte('gerant');
        $this->facture('NSIA ASSURANCES', 'F-001', 100000, now());

        Volt::actingAs($gerant)->test('recouvrement.saisie')
            ->set('nouveauTiers', 'Nsia Assurances')
            ->call('creerTiers')
            ->assertHasErrors('nouveauTiers');
    }

    /*
    |--------------------------------------------------------------------------
    | Les chiffres
    |--------------------------------------------------------------------------
    */

    public function test_un_encaissement_solde_la_facture_et_la_sort_de_la_balance(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 1_000_000, now()->subDays(40));

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('encTiers', 'NSIA ASSURANCES')
            ->set('encFactureId', $facture->id)
            ->set('encMode', 'CHÈQUE')
            ->set('encMontant', 1_000_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $this->actingAs($agent);

        $this->assertSame(1_000_000, (int) Encaissement::withoutGlobalScopes()->sum('montant'));
        // La facture soldée disparaît de la balance âgée : c'est ce qui empêche de
        // relancer quelqu'un qui a déjà payé.
        $this->assertCount(0, Recouvrement::facturesOuvertes(now()));
    }

    public function test_un_encaissement_ne_peut_pas_depasser_le_reste_a_payer(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 500_000, now()->subDays(10));

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('encTiers', 'NSIA ASSURANCES')
            ->set('encFactureId', $facture->id)
            ->set('encMode', 'ESPÈCE')
            ->set('encMontant', 900_000)
            ->call('enregistrerEncaissement')
            ->assertHasErrors('encMontant');

        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    public function test_le_niveau_suit_l_anciennete_de_la_facture(): void
    {
        $this->actingAs($this->compte('gerant'));

        $cas = [3 => 30, 5 => 95, 1 => 8, 0 => 2, 4 => 65, 2 => 20];

        foreach ($cas as $attendu => $jours) {
            $facture = $this->facture('TIERS '.$jours, 'F-'.$jours, 100_000, now()->subDays($jours));

            $this->assertSame(
                $attendu,
                Recouvrement::niveau($facture, now())['niveau'],
                "Une facture de $jours jours doit appeler le niveau N$attendu.",
            );
        }
    }

    public function test_une_facture_soldee_n_appelle_aucun_niveau(): void
    {
        $this->actingAs($this->compte('gerant'));

        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 100_000, now()->subDays(200));

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'facture_id' => $facture->id, 'date' => now()->toDateString(),
            'type' => 'Client', 'moyen' => 'ESPÈCE', 'montant' => 100_000, 'client' => 'NSIA ASSURANCES',
        ]);

        $facture = Facture::withSum('encaissements', 'montant')->find($facture->id);

        // Deux cents jours d'ancienneté, mais plus rien à réclamer : le contentieux
        // n'a pas lieu d'être, et l'afficher enverrait un huissier chez un client à jour.
        $this->assertSame(0, Recouvrement::niveau($facture, now())['niveau']);
        $this->assertSame('Soldée', Recouvrement::niveau($facture, now())['libelle']);
    }

    public function test_la_balance_agee_ventile_par_tranche(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->facture('NSIA ASSURANCES', 'F-001', 100_000, now()->subDays(10));   // 0-30
        $this->facture('NSIA ASSURANCES', 'F-002', 200_000, now()->subDays(45));   // 31-60
        $this->facture('NSIA ASSURANCES', 'F-003', 400_000, now()->subDays(300));  // +180

        $lignes = Recouvrement::parTiers(Recouvrement::lignesOuvertes(now()), now());

        $this->assertCount(1, $lignes);
        $this->assertSame(700_000, $lignes[0]['reste']);
        $this->assertSame([100_000, 200_000, 0, 0, 400_000], $lignes[0]['tranches']);
        // Le niveau du tiers est celui de sa facture la plus ancienne, pas une moyenne.
        $this->assertSame(5, $lignes[0]['niveau']['niveau']);
    }

    /*
    |--------------------------------------------------------------------------
    | Le courtage — le courtier est le tiers payant
    |--------------------------------------------------------------------------
    | La règle tient en une phrase : quand un courtier apporte le dossier, c'est lui
    | qui doit l'argent. Elle a l'air d'un détail d'affichage ; elle décide en réalité
    | à qui part la mise en demeure, et une mise en demeure adressée à quelqu'un qui ne
    | doit rien fait perdre le dossier et la relation.
    */

    public function test_le_courtier_devient_le_tiers_payant(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->facture('KOUAME YAO', 'F-001', 1_000_000, now()->subDays(40), 'AXA', 'WILLIS');

        $lignes = Recouvrement::parTiers(Recouvrement::lignesOuvertes(now()), now());

        // Ni l'assuré ni la compagnie : le courtier.
        $this->assertSame(['WILLIS'], $lignes->pluck('tiers')->all());
        $this->assertSame(1_000_000, $lignes[0]['reste']);
    }

    public function test_sans_courtier_la_compagnie_porte_la_creance_avant_l_assure(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->facture('KOUAME YAO', 'F-001', 400_000, now()->subDays(10), 'AXA');
        $this->facture('GARAGE DU PORT', 'F-002', 600_000, now()->subDays(10));

        $lignes = Recouvrement::parTiers(Recouvrement::lignesOuvertes(now()), now())
            ->pluck('reste', 'tiers')->all();

        // La compagnie quand elle est renseignée, le client quand elle ne l'est pas.
        $this->assertSame(['GARAGE DU PORT' => 600_000, 'AXA' => 400_000], $lignes);
    }

    public function test_la_page_courtiers_consolide_puis_ventile_par_compagnie(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->facture('ASSURE A', 'F-001', 3_000_000, now()->subDays(100), 'AXA', 'WILLIS');
        $this->facture('ASSURE B', 'F-002', 1_000_000, now()->subDays(10), 'GNA', 'WILLIS');
        $this->facture('ASSURE C', 'F-003', 2_000_000, now()->subDays(20), 'AXA', 'ASCOMA');

        $toutes = Recouvrement::lignesDeCreance(now());
        $consolide = Recouvrement::parCourtier($toutes, now());

        // Le plus gros débiteur en tête, et le niveau du courtier est celui de sa
        // facture la plus ancienne : cent jours appellent le contentieux, pas une
        // moyenne rassurante entre cent jours et dix.
        $this->assertSame(['WILLIS', 'ASCOMA'], $consolide->pluck('courtier')->all());
        $this->assertSame(4_000_000, $consolide[0]['reste']);
        $this->assertSame(2, $consolide[0]['assurances']);
        $this->assertSame(5, $consolide[0]['niveau']['niveau']);

        $detail = Recouvrement::parCourtierEtAssurance($toutes);
        $willis = $detail->where('courtier', 'WILLIS')->values();

        $this->assertSame(['AXA', 'GNA'], $willis->pluck('assurance')->all());
        $this->assertSame(0.75, $willis[0]['part']);
        $this->assertSame(0.25, $willis[1]['part']);
    }

    public function test_le_detail_par_compagnie_recoupe_toujours_le_consolide(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->facture('ASSURE A', 'F-001', 3_000_000, now()->subDays(100), 'AXA', 'WILLIS');
        $this->facture('ASSURE B', 'F-002', 1_000_000, now()->subDays(10), null, 'WILLIS');
        $this->facture('ASSURE C', 'F-003', 2_000_000, now()->subDays(20), 'AXA', 'ASCOMA');

        $toutes = Recouvrement::lignesDeCreance(now());

        // C'est le contrôle affiché en haut du second tableau. S'il tombe, une facture
        // est comptée deux fois ou pas du tout — et la relance part sur un chiffre faux.
        $this->assertSame(
            Recouvrement::parCourtier($toutes, now())->sum('reste'),
            Recouvrement::parCourtierEtAssurance($toutes)->sum('reste'),
        );

        // Un dossier apporté sans compagnie derrière lui est nommé, pas passé sous silence.
        $this->assertContains(
            Recouvrement::SANS_ASSURANCE,
            Recouvrement::parCourtierEtAssurance($toutes)->pluck('assurance')->all(),
        );
    }

    public function test_un_courtier_entierement_solde_reste_visible_a_zero(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $facture = $this->facture('ASSURE A', 'F-001', 500_000, now()->subDays(30), 'AXA', 'FILHET-ALLARD');

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'facture_id' => $facture->id, 'date' => now()->toDateString(),
            'type' => 'Client', 'moyen' => 'CHÈQUE', 'montant' => 500_000, 'client' => 'FILHET-ALLARD',
        ]);

        $consolide = Recouvrement::parCourtier(Recouvrement::lignesDeCreance(now()), now());

        // « Il ne doit rien » et « il n'existe pas » ne se disent pas de la même façon :
        // un courtier qui disparaît de la page est un apporteur d'affaires qu'on perd.
        $this->assertSame(['FILHET-ALLARD'], $consolide->pluck('courtier')->all());
        $this->assertSame(0, $consolide[0]['reste']);
        $this->assertSame(500_000, $consolide[0]['regle']);
        $this->assertSame(0, $consolide[0]['ouvertes']);
    }

    public function test_la_page_courtiers_s_ouvre_aux_trois_roles(): void
    {
        $this->facture('ASSURE A', 'F-001', 500_000, now()->subDays(30), 'AXA', 'WILLIS');

        foreach (['agent_recouvrement', 'superviseur_recouvrement', 'gerant'] as $role) {
            $this->actingAs($this->compte($role))
                ->get(route('recouvrement.courtiers'))
                ->assertOk()
                ->assertSee('WILLIS');
        }
    }

    public function test_l_encaissement_d_une_facture_de_courtier_se_fait_sous_son_nom(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $facture = $this->facture('ASSURE A', 'F-001', 800_000, now()->subDays(30), 'AXA', 'WILLIS');

        $composant = Volt::actingAs($agent)->test('recouvrement.saisie')->set('encTiers', 'WILLIS');

        // La facture doit se proposer sous le nom du courtier : c'est lui qui règle. La
        // chercher sous le nom de l'assuré revient à ne jamais la trouver.
        $this->assertSame(['F-001'], $composant->instance()->facturesDuTiersEncaissement->pluck('n_facture')->all());

        $composant->set('encFactureId', $facture->id)
            ->set('encMode', 'VIREMENT — BGFI')
            ->set('encMontant', 800_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        // L'écriture porte le nom de celui qui a payé : sans quoi l'extrait de compte du
        // courtier montrerait ses factures sans ses règlements.
        $this->assertSame('WILLIS', Encaissement::withoutGlobalScopes()->first()->client);
    }

    public function test_l_extrait_d_un_courtier_porte_les_factures_de_ses_compagnies(): void
    {
        $gerant = $this->compte('gerant');
        $this->facture('ASSURE A', 'F-001', 800_000, now()->subDays(30), 'AXA', 'WILLIS');
        $this->facture('ASSURE B', 'F-002', 200_000, now()->subDays(15), 'GNA', 'WILLIS');

        $this->actingAs($gerant)
            ->get(route('recouvrement.extrait', ['tiers' => 'WILLIS']))
            ->assertOk()
            ->assertSee('F-001')
            ->assertSee('F-002')
            // La colonne « pour le compte de » n'apparaît que sur un extrait de courtier :
            // c'est elle qui permet au courtier de rapprocher la demande de ses mandants.
            ->assertSee('Pour le compte de');
    }

    public function test_le_courtier_ne_peut_pas_etre_l_assurance_qu_il_represente(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');
        $this->declarerLeTiers('ASSURE A');
        $this->declarerLeTiers('WILLIS');

        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            ->set('facTiers', 'ASSURE A')
            ->set('facAssureur', 'WILLIS')
            ->set('facCourtier', 'WILLIS')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facNumero', 'F-500')
            ->set('facMontant', 100_000)
            ->call('creerFacture')
            ->assertHasErrors('facCourtier');

        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
    }

    public function test_une_facture_ne_se_cree_pas_sur_un_tiers_inconnu(): void
    {
        $superviseur = $this->compte('superviseur_recouvrement');
        $this->declarerLeTiers('ASSURE A');

        // Les listes déroulantes ne proposent que des tiers connus ; une valeur forgée à
        // la main créerait un débiteur fantôme, absent de toutes les listes et donc jamais
        // relancé. La créance disparaîtrait sans qu'aucun écran ne signale rien.
        Volt::actingAs($superviseur)->test('recouvrement.saisie')
            ->set('facTiers', 'ASSURE A')
            ->set('facCourtier', 'COURTIER INVENTE')
            ->set('facSiteId', $this->site->id)
            ->set('facDate', now()->toDateString())
            ->set('facNumero', 'F-501')
            ->set('facMontant', 100_000)
            ->call('creerFacture')
            ->assertHasErrors('facCourtier');

        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
    }

    public function test_un_nom_de_courtier_a_apostrophe_ne_casse_pas_la_page(): void
    {
        $gerant = $this->compte('gerant');

        // « ABIDJANAISE D'ASSURANCES » et « OLEA CI » sont de vrais noms du fichier source.
        // L'apostrophe traverse un attribut HTML, un appel Livewire et une adresse ; c'est
        // exactement le genre de caractère qui casse une page en silence — ou pire, qui
        // laisse un nom de tiers s'échapper dans le code de la page.
        $courtier = "ABIDJANAISE D'ASSURANCES";
        $this->facture('ASSURE A', 'F-001', 900_000, now()->subDays(45), 'AXA', $courtier);

        $this->actingAs($gerant)->get(route('recouvrement.courtiers'))->assertOk();

        $composant = Volt::actingAs($gerant)->test('recouvrement.courtiers')->set('courtier', $courtier);

        $this->assertSame([$courtier], $composant->instance()->detailAffiche->pluck('courtier')->all());

        // Le contrôle de recoupement tient aussi sur un seul courtier filtré.
        $this->assertSame(
            $composant->instance()->recoupe['consolide'],
            $composant->instance()->recoupe['detail'],
        );
    }

    public function test_un_filtre_de_courtier_forge_est_ignore(): void
    {
        $gerant = $this->compte('gerant');
        $this->facture('ASSURE A', 'F-001', 900_000, now()->subDays(45), 'AXA', 'WILLIS');

        // Le filtre ne compose aucune requête — mais une valeur inconnue afficherait un
        // tableau vide sous un titre annonçant un courtier, et un tableau vide se lit
        // « ce courtier ne doit rien ».
        $composant = Volt::actingAs($gerant)->test('recouvrement.courtiers')
            ->set('courtier', "COURTIER QUI N'EXISTE PAS");

        $this->assertSame('', $composant->instance()->filtre);
        $this->assertSame(['WILLIS'], $composant->instance()->detailAffiche->pluck('courtier')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Ce que la date de travail ne doit pas cacher
    |--------------------------------------------------------------------------
    */

    public function test_une_facture_posterieure_a_la_date_de_travail_reste_encaissable(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 300_000, now()->addDays(3));

        // Le bandeau de chiffres s'arrête à la date d'arrêté — c'est son rôle. Mais la
        // liste des factures à encaisser ne doit rien cacher : l'argent est arrivé, la
        // créance existe, et une liste vide ferait conclure que le compte est soldé.
        $composant = Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('encTiers', 'NSIA ASSURANCES');

        $this->assertSame(['F-001'], $composant->instance()->facturesDuTiersEncaissement->pluck('n_facture')->all());

        $composant->set('encFactureId', $facture->id)
            ->set('encMode', 'ESPÈCE')
            ->set('encMontant', 300_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();
    }

    public function test_une_date_de_travail_fantaisiste_ne_fait_pas_tomber_l_ecran(): void
    {
        $gerant = $this->compte('gerant');
        $this->facture('NSIA ASSURANCES', 'F-001', 300_000, now()->subDays(40));

        // Le champ vient du navigateur et se réécrit à la main. Une chaîne qui n'est pas
        // une date faisait tomber la page en erreur ; une chaîne relative déplaçait
        // l'arrêté sans le dire, donc les anciennetés, donc les niveaux de relance.
        foreach (['', 'pas-une-date', '+10 years', '2026-13-45'] as $saisie) {
            $this->assertSame(now()->startOfDay()->toDateString(), Recouvrement::arrete($saisie)->toDateString());
        }

        $this->assertSame('2026-03-15', Recouvrement::arrete('2026-03-15')->toDateString());

        // La date libre a disparu des écrans : on choisit une période, et l'arrêté s'en
        // déduit. Une période forgée à la main ne doit pas davantage faire tomber la page —
        // ni, surtout, produire un arrêté silencieusement faux.
        Volt::actingAs($gerant)->test('recouvrement.balance')
            ->set('moisFiltre', 'pas-un-mois')
            ->set('semaineFiltre', '99')
            ->set('jourFiltre', '-3')
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Les listes déroulantes de la saisie
    |--------------------------------------------------------------------------
    | Un champ qui ne propose rien ne se distingue pas d'un compte soldé : dans les
    | deux cas l'écran est vide. C'est la panne la plus coûteuse de tout le module,
    | parce qu'elle ne ressemble pas à une panne.
    */

    public function test_chaque_tiers_propose_bien_ses_propres_factures(): void
    {
        $agent = $this->compte('agent_recouvrement');

        // Un jeu volontairement retors : un client seul, une compagnie en direct, une
        // compagnie derrière un courtier, un courtier qui porte deux compagnies, et un
        // tiers dont la seule facture est soldée.
        $this->facture('GARAGE DU PORT', 'F-001', 500_000, now()->subDays(20));
        $this->facture('KOUAME YAO', 'F-002', 700_000, now()->subDays(30), 'AXA');
        $this->facture('ASSURE A', 'F-003', 900_000, now()->subDays(40), 'AXA', 'WILLIS');
        $this->facture('ASSURE B', 'F-004', 300_000, now()->subDays(50), 'GNA', 'WILLIS');
        $soldee = $this->facture('DIALLO', 'F-005', 100_000, now()->subDays(60));

        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'facture_id' => $soldee->id, 'date' => now()->toDateString(),
            'type' => 'Client', 'moyen' => 'ESPÈCE', 'montant' => 100_000, 'client' => 'DIALLO',
        ]);

        $attendu = [
            'GARAGE DU PORT' => ['F-001'],
            'AXA' => ['F-002'],
            'WILLIS' => ['F-003', 'F-004'],
            // Ni l'assuré ni la compagnie derrière un courtier ne portent la créance.
            'ASSURE A' => [],
            'ASSURE B' => [],
            'GNA' => [],
            'KOUAME YAO' => [],
            // Tout est réglé : la liste est vide, et c'est la bonne réponse.
            'DIALLO' => [],
        ];

        $composant = Volt::actingAs($agent)->test('recouvrement.saisie');

        // Chaque tiers proposé par la liste, sans exception : c'est le seul contrôle qui
        // vaut, puisque la panne consiste précisément à n'en servir aucun.
        $this->actingAs($agent);

        foreach (array_keys(Recouvrement::tiers($this->entreprise->id)) as $tiers) {
            $composant->set('encTiers', $tiers);
            $composant->set('relTiers', $tiers);

            $this->assertSame(
                $attendu[$tiers] ?? [],
                $composant->instance()->facturesDuTiersEncaissement->pluck('n_facture')->sort()->values()->all(),
                "Le tiers « $tiers » ne propose pas les factures attendues à l'encaissement.",
            );

            $this->assertSame(
                $attendu[$tiers] ?? [],
                $composant->instance()->facturesDuTiersRelance->pluck('n_facture')->sort()->values()->all(),
                "Le tiers « $tiers » ne propose pas les factures attendues à la relance.",
            );
        }

        // Et le tiers qui porte des factures est bien proposé : une liste de tiers
        // incomplète produirait le même écran vide, une étape plus tôt.
        $this->assertContains('WILLIS', array_keys(Recouvrement::tiers($this->entreprise->id)));
    }

    public function test_les_modes_d_encaissement_sont_une_vraie_liste(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 400_000, now()->subDays(10));

        // Les six modes livrés nomment la banque : un rapprochement bancaire se pointe
        // relevé par relevé, et « Virement » sans la banque n'aide personne.
        $modes = array_keys(Referentiel::options(
            Referentiel::MODE_RECOUVREMENT,
            $this->entreprise->id,
        ));

        $this->assertContains('VIREMENT — BGFI', $modes);
        $this->assertContains('MOBILE MONEY — WAVE', $modes);
        $this->assertCount(6, $modes);

        // La liste est ouverte : un mode ajouté depuis les Paramètres devient utilisable
        // le jour même, sans passer par une mise à jour du logiciel.
        Referentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'type' => Referentiel::MODE_RECOUVREMENT,
            'valeur' => 'VIREMENT — ECOBANK',
            'est_actif' => true,
        ]);

        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('encTiers', 'NSIA ASSURANCES')
            ->set('encFactureId', $facture->id)
            ->set('encMode', 'VIREMENT — ECOBANK')
            ->set('encMontant', 400_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $this->assertSame('VIREMENT — ECOBANK', Encaissement::withoutGlobalScopes()->first()->moyen);
    }

    public function test_un_mode_d_encaissement_inconnu_est_refuse(): void
    {
        $agent = $this->compte('agent_recouvrement');
        $facture = $this->facture('NSIA ASSURANCES', 'F-001', 400_000, now()->subDays(10));

        // La liste déroulante n'est pas la sécurité : elle se réécrit dans le navigateur.
        // Un mode inventé rendrait la synthèse par mode fausse et le rapprochement
        // bancaire impointable.
        Volt::actingAs($agent)->test('recouvrement.saisie')
            ->set('encTiers', 'NSIA ASSURANCES')
            ->set('encFactureId', $facture->id)
            ->set('encMode', 'ENVELOPPE')
            ->set('encMontant', 400_000)
            ->call('enregistrerEncaissement')
            ->assertHasErrors('encMode');

        $this->assertSame(0, Encaissement::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | L'annuaire des tiers
    |--------------------------------------------------------------------------
    */

    public function test_l_annuaire_reunit_toutes_les_provenances_de_tiers(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $this->facture('ASSURE A', 'F-001', 900_000, now()->subDays(40), 'AXA', 'WILLIS');
        $this->facture('KOUAME YAO', 'F-002', 700_000, now()->subDays(30), 'AXA');
        $this->declarerLeTiers('JAMAIS FACTURE');

        $annuaire = Recouvrement::annuaireDesTiers(now(), $this->entreprise->id)->keyBy('tiers');

        // Les quatre provenances : les trois colonnes de la facture, plus le référentiel.
        $this->assertEqualsCanonicalizing(
            ['ASSURE A', 'AXA', 'WILLIS', 'KOUAME YAO', 'JAMAIS FACTURE'],
            $annuaire->keys()->all(),
        );

        // AXA joue deux rôles à la fois : compagnie derrière WILLIS, et payeur en direct
        // sur le dossier de KOUAME YAO. C'est le cas courant, pas l'exception.
        $this->assertEqualsCanonicalizing(['Assurance'], $annuaire['AXA']['roles']);
        $this->assertSame(700_000, $annuaire['AXA']['reste']);

        // WILLIS porte la créance de son dossier ; l'assuré, lui, ne doit rien.
        $this->assertSame(900_000, $annuaire['WILLIS']['reste']);
        $this->assertSame(0, $annuaire['ASSURE A']['reste']);
        $this->assertSame(['Client'], $annuaire['ASSURE A']['roles']);

        // Déclaré mais jamais facturé : présent, à zéro. L'omettre ferait recréer le même
        // tiers sous une orthographe voisine par quelqu'un qui l'a cru absent.
        $this->assertSame([], $annuaire['JAMAIS FACTURE']['roles']);
        $this->assertSame(0, $annuaire['JAMAIS FACTURE']['factures']);
    }

    public function test_la_page_clients_liste_cherche_et_pagine(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        // Trente tiers : de quoi remplir plus d'une page de vingt-cinq.
        for ($i = 1; $i <= 30; $i++) {
            $this->facture('CLIENT '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'F-'.$i, 100_000, now()->subDays(10));
        }

        $composant = Volt::actingAs($gerant)->test('recouvrement.clients');

        $this->assertCount(25, $composant->instance()->affichees);
        $composant->set('page', 2);
        $this->assertCount(5, $composant->instance()->affichees);

        // Une recherche ramène au début : rester en page 2 afficherait un tableau vide,
        // qui se lit « aucun résultat » alors qu'il y en a un.
        $composant->set('recherche', 'CLIENT 07');
        $this->assertSame(1, $composant->instance()->page);
        $this->assertSame(['CLIENT 07'], $composant->instance()->affichees->pluck('tiers')->values()->all());

        // Un numéro de page forgé ne fait pas repartir la liste par la fin.
        $composant->set('recherche', '')->set('page', -5);
        $this->assertSame(1, $composant->instance()->pageCourante);
        $this->assertSame('CLIENT 01', $composant->instance()->affichees->first()['tiers']);
    }

    public function test_la_page_clients_filtre_par_role(): void
    {
        $gerant = $this->compte('gerant');
        $this->facture('ASSURE A', 'F-001', 900_000, now()->subDays(40), 'AXA', 'WILLIS');

        $composant = Volt::actingAs($gerant)->test('recouvrement.clients');

        $composant->set('role', 'Courtier');
        $this->assertSame(['WILLIS'], $composant->instance()->lignes->pluck('tiers')->all());

        // Un rôle forgé est ignoré plutôt que de vider le tableau sous un intitulé qui
        // annonce autre chose.
        $composant->set('role', 'ADMINISTRATEUR');
        $this->assertCount(3, $composant->instance()->lignes);
    }

    /*
    |--------------------------------------------------------------------------
    | Le cloisonnement entre entreprises
    |--------------------------------------------------------------------------
    */

    public function test_le_recouvrement_d_une_entreprise_reste_chez_elle(): void
    {
        $voisine = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        ProvisionneurEntreprise::creerRoles($voisine);

        $villeVoisine = Ville::create([
            'entreprise_id' => $voisine->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $siteVoisin = Site::create([
            'entreprise_id' => $voisine->id, 'ville_id' => $villeVoisine->id,
            'code' => 'BOU-1', 'nom' => 'Bouaké — Site 1', 'est_actif' => true,
        ]);

        Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $voisine->id, 'site_id' => $siteVoisin->id,
            'date' => now()->subDays(50), 'n_facture' => 'B-001',
            'client' => 'ASSUREUR DE BETA', 'activite' => 'Sinistre', 'montant' => 9_000_000,
        ]);

        $this->actingAs($this->compte('gerant'));

        $ouvertes = Recouvrement::facturesOuvertes(now());

        $this->assertCount(0, $ouvertes, "L'encours d'une autre entreprise ne doit jamais apparaître ici.");
        $this->assertNotContains('ASSUREUR DE BETA', array_keys(Recouvrement::tiers($this->entreprise->id)));
    }

    public function test_les_deux_lectures_du_portefeuille_disent_la_meme_chose(): void
    {
        $this->actingAs($this->compte('gerant'));

        $this->facture('NSIA ASSURANCES', 'F-001', 100_000, now()->subDays(10));
        $this->facture('NSIA ASSURANCES', 'F-002', 200_000, now()->subDays(45));
        $this->facture('SUNU', 'F-003', 400_000, now()->subDays(300));

        /*
         * Le garde-fou de la reprise du 23/09.
         *
         * Les écrans qui consolident tout le portefeuille lisent désormais les lignes
         * telles que la base les rend, au lieu d'en faire des objets — neuf mille lectures
         * d'attributs de moins par affichage. Les règles, elles, n'ont pas bougé : ce sont
         * les mêmes fonctions des deux côtés. Si les deux lectures se mettaient à diverger,
         * la balance âgée et l'export du même jour ne diraient plus le même encours, et
         * l'écart ne se verrait qu'en rapprochant deux totaux à la main.
         */
        $parObjets = Recouvrement::parTiers(Recouvrement::facturesOuvertes(now()), now());
        $parLignes = Recouvrement::parTiers(Recouvrement::lignesOuvertes(now()), now());

        $this->assertEquals($parObjets->all(), $parLignes->all());

        $this->assertEquals(
            Recouvrement::kpis(Recouvrement::facturesOuvertes(now()), now()),
            Recouvrement::kpis(Recouvrement::lignesOuvertes(now()), now()),
        );

        // Et l'âge se compte pareil, ligne à ligne.
        $jourArrete = NombreDeJours::jour(now());

        foreach (Recouvrement::lignesOuvertes(now()) as $ligne) {
            $facture = Facture::withoutGlobalScopes()->find($ligne->id);

            $this->assertSame(
                Recouvrement::anciennete($facture, now()),
                Recouvrement::ageDeLaLigne($ligne, $jourArrete),
                "âge de la facture {$facture->n_facture}",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | La liste des tiers, et la liste des factures qui en dépend
    |--------------------------------------------------------------------------
    */

    /**
     * Choisir un tiers remplit la liste de ses factures.
     *
     * C'est le geste le plus fréquent du module, et il ne se voyait pas fonctionner :
     * la page pesait 1,42 Mo, dont 1,35 Mo de listes de tiers répétées cinq fois, et
     * portait 12 285 balises `option`. Chaque choix renvoyait tout ce poids au navigateur.
     * Le temps qu'il le reconstruise, la liste des factures gardait son ancien texte —
     * « Sélectionner le tiers d'abord » — et l'on concluait qu'elle était en panne.
     */
    public function test_choisir_un_tiers_remplit_la_liste_de_ses_factures(): void
    {
        $this->facture('SIFCA', 'F-2001', 500_000, now()->subDays(20));

        $ecran = Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie');

        $this->assertStringContainsString("Sélectionner le tiers d'abord", $ecran->html());

        $rempli = $ecran->set('encTiers', 'SIFCA')->html();

        $this->assertStringNotContainsString("Sélectionner le tiers d'abord", $rempli);
        $this->assertStringContainsString('F-2001', $rempli);
    }

    /**
     * La liste ne propose que les tiers qui doivent encore.
     *
     * Sur les données réelles, 2 318 des 2 445 tiers proposés n'avaient plus rien
     * d'ouvert : sept noms sur huit menaient à « aucune facture ouverte ». On ne relance
     * pas un compte soldé et l'on n'encaisse pas sur une facture déjà payée.
     */
    public function test_un_compte_solde_quitte_la_liste_des_tiers(): void
    {
        $solde = $this->facture('PAYEUR SOLDE', 'F-2002', 200_000, now()->subDays(30));
        $this->facture('DEBITEUR', 'F-2003', 300_000, now()->subDays(30));

        // L'encaissement passe par l'écran, comme il le ferait en vrai : c'est la seule
        // façon de prouver que la liste se vide bien après un règlement.
        Volt::actingAs($this->compte('agent_recouvrement'))->test('recouvrement.saisie')
            ->set('encTiers', 'PAYEUR SOLDE')
            ->set('encFactureId', $solde->id)
            ->set('encMode', 'CHÈQUE')
            ->set('encMontant', 200_000)
            ->call('enregistrerEncaissement')
            ->assertHasNoErrors();

        $tiers = Recouvrement::tiersDebiteurs();

        $this->assertArrayHasKey('DEBITEUR', $tiers);
        $this->assertArrayNotHasKey('PAYEUR SOLDE', $tiers);
    }

    /**
     * Le tiers payant calculé en base dit la même chose que celui calculé en mémoire.
     *
     * `tiersDebiteurs()` déduit le payeur en SQL — courtier, puis assurance, puis client —
     * pour ne pas lire mille trois cents factures afin d'en tirer cent noms. Deux règles
     * écrites deux fois finissent toujours par diverger : celle-ci est vérifiée contre
     * `Facture::tiersPayant()`, qui reste celle qui fait foi.
     */
    public function test_le_tiers_payant_calcule_en_base_suit_la_regle_de_priorite(): void
    {
        $this->declarerLeTiers('AXA');
        $this->declarerLeTiers('CABINET KOFFI');

        $this->facture('Client direct', 'F-2004', 100_000, now()->subDays(10));
        $this->facture('Assuré A', 'F-2005', 100_000, now()->subDays(10), assureur: 'AXA');
        $this->facture('Assuré B', 'F-2006', 100_000, now()->subDays(10),
            assureur: 'AXA', courtier: 'CABINET KOFFI');

        $enBase = array_keys(Recouvrement::tiersDebiteurs());

        $enMemoire = Recouvrement::facturesOuvertes()
            ->map(fn (Facture $f) => $f->tiersPayant())->unique()->values()->all();

        sort($enBase);
        sort($enMemoire);

        $this->assertSame($enMemoire, $enBase);
        $this->assertContains('CABINET KOFFI', $enBase);
        $this->assertNotContains('Assuré B', $enBase);
    }

    /**
     * L'annuaire complet n'est écrit qu'une fois dans la page.
     *
     * Les trois champs de la carte « Créer une facture » ont besoin de tous les tiers, y
     * compris ceux qui ne doivent rien. Ils partagent donc un seul `datalist` : recopier
     * la liste dans trois `select` coûtait 928 Ko, renvoyés à chaque aller-retour.
     */
    public function test_l_annuaire_complet_n_est_pas_recopie_dans_chaque_champ(): void
    {
        foreach (range(1, 40) as $rang) {
            $this->declarerLeTiers('TIERS '.str_pad((string) $rang, 3, '0', STR_PAD_LEFT));
        }

        $html = Volt::actingAs($this->compte('gerant'))->test('recouvrement.saisie')->html();

        $this->assertStringContainsString('<datalist id="rec-tiers-connus">', $html);

        // Un nom quelconque de l'annuaire : une occurrence dans le datalist, et pas une
        // de plus. Trois, et les trois `select` seraient revenus.
        $this->assertSame(1, substr_count($html, '"TIERS 007"'),
            "L'annuaire des tiers est recopié plusieurs fois dans la page.");
    }

    private function facture(
        string $tiers,
        string $numero,
        int $montant,
        $date,
        ?string $assureur = null,
        ?string $courtier = null,
    ): Facture {
        $facture = Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $date,
            'n_facture' => $numero,
            'client' => $tiers,
            'assureur' => $assureur,
            'courtier' => $courtier,
            // L'activité est obligatoire en base : une facture d'atelier relève toujours
            // de la mécanique ou du sinistre, et c'est elle qui rend la ligne ventilable.
            'activite' => 'Sinistre',
            'montant' => $montant,
        ]);

        return Facture::withoutGlobalScopes()->withSum('encaissements', 'montant')->find($facture->id);
    }

    /**
     * Inscrit un tiers au référentiel de l'entreprise.
     *
     * Les trois champs de tiers d'une facture n'acceptent que des valeurs déjà connues :
     * un test qui facture un assureur jamais déclaré se fait refuser, comme l'écran
     * refuserait la même chose.
     */
    private function declarerLeTiers(string $nom): void
    {
        Referentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'type' => Referentiel::TIERS_RECOUVREMENT,
            'valeur' => $nom,
            'est_actif' => true,
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'est_actif' => true,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

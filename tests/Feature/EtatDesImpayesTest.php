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
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L'état des impayés — l'écran qui remplace le classeur.
 *
 * **Ce que ces tests tiennent, et pourquoi c'est là que ça compte.** Le classeur repris est
 * juste — 798 999 354 F de reste à payer sur 1 332 créances ouvertes, et la reprise en base y
 * retombe. Ce qu'un tableur ne peut pas faire, c'est se défendre contre la main qui le tient.
 * Mesuré sur le fichier lui-même :
 *
 * - 29 lignes réglées **au-delà** de leur propre montant facturé, pour 4 446 771 F de
 *   trop-perçu venant en déduction de la dette des autres clients ;
 * - la formule d'ancienneté rangée sous « Mode de règlement » pour 1 198 lignes et sous
 *   « banque » pour 1 492 autres — 2 765 lignes où l'on lit des jours sous un en-tête de
 *   moyen de paiement ;
 * - 45 lignes sans aucune date exploitable, 142 sans numéro.
 *
 * Chacun de ces défauts a ici un test qui l'empêche de revenir. Ce ne sont pas des tests de
 * validation de formulaire : ce sont les choses qu'aucune discipline de saisie ne pouvait
 * empêcher dans un tableur, et qui doivent échouer à la frappe ici.
 */
class EtatDesImpayesTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Qui entre, et qui n'entre pas
    |--------------------------------------------------------------------------
    */

    public function test_les_trois_pages_sont_ouvertes_a_qui_repond_du_chiffre(): void
    {
        foreach (['gerant', 'responsable_ville', 'responsable_site'] as $role) {
            $compte = $this->compte($role);

            foreach (['impayes', 'impayes.etat-initial', 'rapprochement-ca-impayes'] as $page) {
                $this->actingAs($compte)->get(route($page))
                    ->assertOk("Le rôle « $role » devrait atteindre « $page ».");
            }

            auth()->guard('web')->logout();
        }
    }

    public function test_les_trois_pages_sont_fermees_a_qui_ne_fait_que_vendre(): void
    {
        /*
         * Le responsable commercial en est écarté comme il l'est des charges et de la
         * trésorerie : animer une équipe de vente ne donne aucun titre à lire ce que les
         * clients de l'entreprise doivent. Et ce qui compte n'est pas l'absence de l'onglet
         * mais la fermeture de l'adresse — une page retirée du menu reste atteignable en la
         * tapant.
         */
        foreach (['commercial', 'responsable_commercial', 'caissier'] as $role) {
            $compte = $this->compte($role);

            foreach (['impayes', 'impayes.etat-initial', 'rapprochement-ca-impayes'] as $page) {
                $reponse = $this->actingAs($compte)->get(route($page));

                $this->assertNotEquals(
                    200,
                    $reponse->status(),
                    "Le rôle « $role » ne devrait pas atteindre « $page » en tapant son adresse.",
                );
            }

            auth()->guard('web')->logout();
        }
    }

    public function test_le_gerant_voit_les_deux_nouveaux_onglets(): void
    {
        $gerant = $this->compte('gerant');

        $etiquettes = $this->etiquettes(MenuNavigation::pour($gerant));

        $this->assertContains('État des impayés', $etiquettes);
        $this->assertContains('Rapprochement CA / impayés', $etiquettes);
    }

    /*
    |--------------------------------------------------------------------------
    | La saisie, et les quatre défauts du classeur
    |--------------------------------------------------------------------------
    */

    public function test_une_creance_saisie_porte_sa_propre_serie_et_son_reglement(): void
    {
        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes')
            ->set('fDate', '2026-03-10')
            ->set('fDateReception', '2026-03-12')
            ->set('fNumero', '4417')
            ->set('fClient', 'LOXEA CI')
            ->set('fAssureur', 'ALLIANZ')
            ->set('fSiteId', (string) $this->site->id)
            ->set('fImmatriculation', '1869 kk 1')
            ->set('fMontant', '300000')
            ->set('fRegle', '120000')
            ->set('fModeReglement', 'CHÈQUE')
            ->set('fDateReglement', '2026-04-02')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $creance = Facture::where('n_facture', '4417')->first();

        $this->assertNotNull($creance, 'La créance devrait être enregistrée.');

        // Sa propre série : elle vit dans la même table que les factures de l'atelier, et
        // c'est précisément pourquoi elle ne doit pas se lire comme l'une d'elles.
        $this->assertStringStartsWith('IMP-', $creance->numero);

        // L'année est celle de la facture, pas celle de l'écran depuis lequel on saisit.
        $this->assertSame(2026, $creance->exercice_impayes);
        $this->assertFalse($creance->est_etat_initial, "Une saisie n'est pas une reprise.");

        // La plaque est normalisée : sans cela, le rapprochement avec le chiffre d'affaires
        // déclarerait « non suivie » une créance parfaitement suivie.
        $this->assertSame('1869 KK 1', $creance->immatriculation);

        /*
         * Le règlement devient un encaissement, jamais une colonne. C'est ce qui fait que le
         * reste à payer se calcule partout de la même façon et que la créance apparaît dans la
         * balance âgée et en trésorerie sans le moindre rapprochement.
         */
        $encaissement = Encaissement::where('facture_id', $creance->id)->first();

        $this->assertNotNull($encaissement, 'Le montant réglé devrait devenir un encaissement.');
        $this->assertSame(120_000, (int) $encaissement->montant);
        $this->assertSame(180_000, $creance->fresh()->resteAEncaisser());
    }

    public function test_le_formulaire_porte_les_seize_colonnes_saisissables_du_classeur(): void
    {
        /*
         * **Pourquoi la liste des intitulés est tenue par un test.** Le superviseur de veille
         * tient ce classeur depuis quatre ans. Il ne saisit pas champ par champ : il reprend
         * une ligne de tableur et la retape, de gauche à droite. Un intitulé traduit, un champ
         * déplacé ou supprimé lui ferait chercher, à chaque créance, ce qu'il trouvait sans
         * regarder — et c'est ce genre de friction qui fait qu'on retourne au fichier.
         *
         * Les quatre colonnes non saisissables du classeur n'y sont pas, et c'est voulu : le
         * reste à payer et les deux ancienneté se calculent, la colonne A ne porte rien.
         */
        $this->actingAs($this->compte('gerant'));

        $ecran = Volt::test('pilotage.impayes')->call('basculerFormulaire');

        foreach ([
            'ASSUREUR', 'Client', 'SITE', 'Courtier',
            "Date d'édition", 'Date de réception', 'N° de la facture', 'Numéro Sinistre',
            'Vehicule', 'Immatriculation', 'montantTTC', 'Montantréglé',
            'Modederèglement', 'Datederèglement', 'banque', 'Commentaires',
        ] as $intitule) {
            $ecran->assertSee($intitule, false);
        }

        // Ce qui ne se saisit pas ne doit pas être proposé : un champ « Reste à payer » dans
        // un formulaire invite à le remplir, et l'on aurait deux vérités pour un même chiffre.
        $ecran->assertDontSee('ResteàPayer', false);
    }

    public function test_un_reglement_superieur_au_montant_facture_est_refuse(): void
    {
        /*
         * **Ce qu'aucune discipline de saisie ne pouvait empêcher dans le classeur.** 29 de ses
         * lignes portent un règlement supérieur à leur propre montant, pour 4 446 771 F. Ce
         * trop-perçu venait en déduction du total des créances : il masquait la dette d'autres
         * clients. Un client qui a trop payé ne rembourse pas la dette d'un autre.
         */
        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes')
            ->set('fDate', '2026-03-10')
            ->set('fNumero', '4418')
            ->set('fClient', 'NSIA')
            ->set('fSiteId', (string) $this->site->id)
            ->set('fMontant', '300000')
            ->set('fRegle', '475000')
            ->set('fModeReglement', 'ESPÈCE')
            ->set('fDateReglement', '2026-03-12')
            ->call('enregistrer')
            ->assertHasErrors('fRegle');

        $this->assertNull(Facture::where('n_facture', '4418')->first());
    }

    public function test_une_creance_sans_date_ni_numero_est_refusee(): void
    {
        // 45 lignes du classeur n'ont aucune date exploitable, 142 aucun numéro. On ne relance
        // pas une créance qu'on ne peut ni dater ni nommer.
        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes')
            ->set('fDate', '')
            ->set('fNumero', '')
            ->set('fClient', 'SANS RIEN')
            ->set('fSiteId', (string) $this->site->id)
            ->set('fMontant', '50000')
            ->call('enregistrer')
            ->assertHasErrors(['fDate', 'fNumero']);
    }

    public function test_le_doublon_est_refuse_sur_la_cle_du_classeur(): void
    {
        /*
         * Le classeur repérait ses doublons **après coup**, par un `CONCATENATE` de la date,
         * du numéro, de l'immatriculation et du montant. La même clé refuse ici la saisie.
         *
         * C'est aussi la clé que l'import avait retenue de son côté, en éprouvant les
         * combinaisons sur le fichier entier : deux démarches indépendantes arrivées au même
         * endroit. Le numéro seul n'identifie rien — ce sont de simples entiers remis à zéro.
         */
        $this->actingAs($this->compte('gerant'));

        $saisie = fn () => Volt::test('pilotage.impayes')
            ->set('fDate', '2026-05-04')
            ->set('fDateReception', '2026-05-06')
            ->set('fNumero', '17')
            ->set('fClient', 'ALLIANZ')
            ->set('fSiteId', (string) $this->site->id)
            ->set('fImmatriculation', '1869KK1')
            ->set('fMontant', '300000')
            ->call('enregistrer');

        $saisie()->assertHasNoErrors();
        $saisie()->assertHasErrors('fNumero');

        $this->assertSame(1, Facture::where('n_facture', '17')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La reconduction : une règle de lecture, jamais une écriture
    |--------------------------------------------------------------------------
    */

    public function test_une_creance_non_soldee_se_reporte_sans_etre_recopiee(): void
    {
        $ouverte = $this->creance(2025, 'ANCIENNE-OUVERTE', 500_000, 100_000);
        $soldee = $this->creance(2025, 'ANCIENNE-SOLDEE', 400_000, 400_000);
        $deLAnnee = $this->creance(2026, 'DE-L-ANNEE', 200_000, 0);

        $etat2026 = EtatDesImpayes::requete(2026)->withSum('encaissements', 'montant')->get();

        $numeros = $etat2026->pluck('n_facture')->all();

        $this->assertContains('ANCIENNE-OUVERTE', $numeros, "Une créance de 2025 encore due doit paraître dans l'état 2026.");
        $this->assertContains('DE-L-ANNEE', $numeros);
        $this->assertNotContains('ANCIENNE-SOLDEE', $numeros, 'Une créance soldée cesse de se reporter, sans que personne n\'ait à y penser.');

        /*
         * **Elle n'a pas été recopiée, et c'est tout l'intérêt.** Recopier la ligne compterait
         * la même créance deux fois dans un total ; la déplacer viderait l'état de l'année
         * passée, qui ne correspondrait plus à ce qu'on y avait arrêté.
         */
        $this->assertSame(1, Facture::where('n_facture', 'ANCIENNE-OUVERTE')->count());
        $this->assertSame(2025, $ouverte->fresh()->exercice_impayes, "L'année d'origine ne bouge jamais.");

        // Les deux colonnes demandées, et aucune des deux n'est stockée.
        $this->assertTrue(EtatDesImpayes::estReportee($ouverte, 2026));
        $this->assertSame('Reporté 2025', EtatDesImpayes::libelleReport($ouverte, 2026));

        $this->assertFalse(EtatDesImpayes::estReportee($deLAnnee, 2026));
        $this->assertSame('—', EtatDesImpayes::libelleReport($deLAnnee, 2026));
    }

    public function test_l_ecran_affiche_le_report_et_son_annee(): void
    {
        $this->creance(2024, 'VIEILLE-CREANCE', 900_000, 0);

        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes')
            ->set('exercice', 2026)
            ->set('statutFiltre', 'toutes')
            ->assertSee('VIEILLE-CREANCE')
            ->assertSee('Reporté 2024');
    }

    public function test_les_totaux_ne_deduisent_jamais_un_trop_percu_de_la_dette_des_autres(): void
    {
        /*
         * L'erreur du classeur, refaite exprès en base pour vérifier que le total y résiste :
         * une créance sur-encaissée à côté d'une créance due. Sommer les deux soldes ferait
         * disparaître une partie de la dette réelle. Le total se calcule donc **par ligne et à
         * plancher zéro**.
         */
        $due = $this->creance(2026, 'DUE', 1_000_000, 0);
        $trop = $this->creance(2026, 'TROP-PAYEE', 500_000, 0);

        // Le sur-règlement se pose en base : le formulaire, lui, le refuse — c'est l'objet
        // d'un autre test. Ici on éprouve le total face à une donnée déjà entrée de travers.
        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'facture_id' => $trop->id, 'date' => '2026-02-02', 'montant' => 800_000,
            'type' => 'Client', 'moyen' => 'ESPÈCE', 'client' => 'TROP',
        ]);

        $lignes = EtatDesImpayes::requete(2026)->withSum('encaissements', 'montant')->get();
        $totaux = EtatDesImpayes::totaux($lignes, 2026);

        // 1 000 000 dû, et pas 700 000 : les 300 000 de trop-perçu ne remboursent personne.
        $this->assertSame(1_000_000, $totaux['reste']);
        $this->assertSame(1, $totaux['ouvertes']);
    }

    /*
    |--------------------------------------------------------------------------
    | L'état initial, et le rapprochement
    |--------------------------------------------------------------------------
    */

    public function test_l_etat_initial_ne_montre_que_la_reprise(): void
    {
        $reprise = $this->creance(2023, 'REPRISE-DU-CLASSEUR', 700_000, 0);
        $reprise->forceFill(['est_etat_initial' => true, 'anciennete_declaree' => '<30'])->save();

        $this->creance(2026, 'SAISIE-ICI', 300_000, 0);

        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.impayes-etat-initial')
            ->assertSee('REPRISE-DU-CLASSEUR')
            ->assertDontSee('SAISIE-ICI');

        /*
         * La tranche que le classeur annonçait est confrontée à celle qu'on recalcule. Une
         * créance de 2023 notée « <30 » a franchi la borne depuis longtemps : la cellule était
         * juste le jour où on l'a tapée, puis elle a vieilli seule.
         */
        $this->assertTrue(EtatDesImpayes::trancheDivergente($reprise->fresh()));
    }

    public function test_le_rapprochement_nomme_les_factures_que_personne_ne_suit(): void
    {
        // Côté chiffre d'affaires : une facture du CATTC, sans année d'état des impayés.
        Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'n_facture' => 'FA -5713', 'numero' => 'FA -5713', 'date' => '2026-02-11',
            'client' => 'LOXEA CI', 'immatriculation' => 'AA069AM01', 'montant' => 50_000,
            'activite' => 'Mécanique', 'type' => 'FNE',
        ]);

        // Côté état des impayés : une créance sur un autre véhicule. Le pont ne la retrouvera
        // donc pas, et c'est exactement ce qu'on veut voir.
        $this->creance(2026, '2201', 300_000, 0, '1869KK1');

        $this->actingAs($this->compte('gerant'));

        Volt::test('pilotage.rapprochement-ca-impayes')
            ->set('exercice', 2026)
            ->assertSee('AA069AM01')
            ->assertSee('Facturé mais non suivi');
    }

    public function test_le_pont_ignore_la_ponctuation_des_plaques(): void
    {
        /*
         * « 1179JF01 », « 1179 JF 01 » et « 1179-jf-01 » sont le même véhicule. Une comparaison
         * sensible aux espaces déclarerait non suivie une facture parfaitement suivie — et l'on
         * irait relancer un client qui a payé. Entre les deux erreurs possibles, c'est la pire.
         */
        $premiere = new Facture(['immatriculation' => '1179 JF 01', 'montant' => 147_050]);
        $seconde = new Facture(['immatriculation' => '1179-jf-01', 'montant' => 147_050]);
        $autre = new Facture(['immatriculation' => '1179JF01', 'montant' => 147_051]);

        $this->assertSame(EtatDesImpayes::clePont($premiere), EtatDesImpayes::clePont($seconde));
        $this->assertNotSame(EtatDesImpayes::clePont($premiere), EtatDesImpayes::clePont($autre));

        // Sans plaque, la facture n'est pas rapprochable — ce qui n'est pas la même chose que
        // non suivie. L'écran la compte à part plutôt que de l'accuser.
        $this->assertNull(EtatDesImpayes::clePont(new Facture(['immatriculation' => null, 'montant' => 1])));
    }

    /*
    |--------------------------------------------------------------------------
    | Les tranches du classeur, reprises à ses propres bornes
    |--------------------------------------------------------------------------
    */

    public function test_les_tranches_reprennent_les_bornes_du_classeur(): void
    {
        $this->assertSame('—', EtatDesImpayes::trancheDuFichier(0));
        $this->assertSame('—', EtatDesImpayes::trancheDuFichier(null));
        $this->assertSame('<30', EtatDesImpayes::trancheDuFichier(29));
        $this->assertSame('30<>60', EtatDesImpayes::trancheDuFichier(30));
        $this->assertSame('30<>60', EtatDesImpayes::trancheDuFichier(59));
        $this->assertSame('60<>90', EtatDesImpayes::trancheDuFichier(60));
        $this->assertSame('>90', EtatDesImpayes::trancheDuFichier(90));
        $this->assertSame('>90', EtatDesImpayes::trancheDuFichier(4000));
    }

    /*
    |--------------------------------------------------------------------------
    | La reprise : ranger ce que les imports avaient mis dans une phrase
    |--------------------------------------------------------------------------
    */

    public function test_la_commande_date_les_creances_deja_reprises(): void
    {
        /*
         * **C'est le test qui compte le plus de cette série.** Les créances déjà importées
         * n'avaient pas d'année : sans elle, aucune n'apparaît dans l'état d'un exercice et le
         * report n'a rien sur quoi s'appuyer. L'écran serait vide sur une base qui porte
         * pourtant des milliers de créances réelles — et l'on conclurait que la page ne marche
         * pas, alors que c'est la donnée qui n'est pas datée.
         */
        $lot = $this->lotImpayes();

        $reprise = Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'lot_import_id' => $lot->id,
            'numero' => 'IM-abc123', 'n_facture' => '17', 'date' => '2024-09-30',
            'client' => 'ALLIANZ', 'montant' => 300_000, 'activite' => 'Mécanique', 'type' => 'FNE',
        ]);

        $this->assertNull($reprise->exercice_impayes);

        // Sans --appliquer, la commande dit ce qu'elle ferait et n'écrit pas une ligne : une
        // reprise sur des données réelles se regarde avant de se lancer.
        $this->artisan('impayes:ranger-les-colonnes')->assertSuccessful();
        $this->assertNull($reprise->fresh()->exercice_impayes);

        $this->artisan('impayes:ranger-les-colonnes --appliquer')->assertSuccessful();

        $reprise = $reprise->fresh();

        $this->assertSame(2024, $reprise->exercice_impayes, "L'année vient de la date de la facture.");
        $this->assertTrue($reprise->est_etat_initial);

        // Et la voilà dans l'état 2026, reportée depuis 2024 — sans avoir été recopiée.
        $this->assertContains('17', EtatDesImpayes::requete(2026)->pluck('n_facture')->all());
        $this->assertSame('Reporté 2024', EtatDesImpayes::libelleReport($reprise, 2026));
    }

    public function test_la_commande_defait_la_phrase_du_cattc_sans_toucher_a_une_note(): void
    {
        // Une facture du CATTC dont les trois données étaient concaténées en observation.
        $rangeable = Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'numero' => 'FA -1', 'n_facture' => 'FA -1', 'date' => '2026-01-10',
            'client' => 'LOXEA', 'montant' => 50_000, 'activite' => 'Mécanique', 'type' => 'FNE',
            'observations' => 'Sinistre : LOXEA011/01/24 · Sticker : 12 · Code client : N° 00190',
        ]);

        /*
         * La même phrase, augmentée d'une note écrite par quelqu'un. Les colonnes se
         * remplissent, **et la phrase reste** : perdre une note est une faute, garder une
         * phrase en trop n'en est pas une.
         */
        $avecNote = Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'numero' => 'FA -2', 'n_facture' => 'FA -2', 'date' => '2026-01-11',
            'client' => 'SIFCA', 'montant' => 70_000, 'activite' => 'Mécanique', 'type' => 'FNE',
            'observations' => 'Sinistre : X99 · Sticker : 44 · Le client conteste le devis',
        ]);

        $this->artisan('impayes:ranger-les-colonnes --appliquer')->assertSuccessful();

        $rangeable = $rangeable->fresh();
        $this->assertSame('LOXEA011/01/24', $rangeable->n_sinistre);
        $this->assertSame('12', $rangeable->n_sticker);
        $this->assertSame('N° 00190', $rangeable->code_client);
        $this->assertNull($rangeable->observations, 'La phrase entièrement reconnue disparaît.');

        /*
         * La note survit **seule** : la donnée est partie dans sa colonne, et elle n'est plus
         * affichée deux fois. Ce sont de vraies consignes de travail — « FACTURE D'AVOIR A
         * ETABLIR », « RELIQUAT REGLE LE 21/10/2022 » — et elles valent plus que le numéro de
         * sinistre qui les précédait.
         */
        $avecNote = $avecNote->fresh();
        $this->assertSame('X99', $avecNote->n_sinistre);
        $this->assertSame('44', $avecNote->n_sticker);
        $this->assertSame('Le client conteste le devis', $avecNote->observations);
    }

    public function test_la_commande_efface_la_cle_de_doublon_prise_pour_un_commentaire(): void
    {
        /*
         * Sous l'intitulé « Commentaires », la colonne T du classeur porte deux choses : 345
         * vraies consignes de travail, et une formule `CONCATENATE` de repérage des doublons
         * posée sur 4 299 lignes.
         *
         * **Pourquoi ce test existe alors que le cas ne s'est pas encore produit.** Le fichier
         * reçu n'enregistre pas le résultat de ces formules, donc aucune clé n'est arrivée en
         * observation à ce jour. Il suffirait qu'on ouvre le classeur et qu'on l'enregistre
         * pour qu'Excel les écrive toutes les 4 299, et l'import les prendrait alors pour des
         * commentaires. Ce test tient la porte fermée d'avance.
         *
         * On ne reconnaît pas la clé à sa forme : on la recompose depuis la ligne et l'on
         * compare. Une immatriculation notée à la main ressemblerait à une clé.
         */
        $lot = $this->lotImpayes();

        $avecCle = Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'lot_import_id' => $lot->id,
            'numero' => 'IM-def456', 'n_facture' => '26', 'date' => '2022-04-04',
            'client' => 'ALLIANZ', 'immatriculation' => '1179JF01', 'montant' => 147_050,
            'activite' => 'Mécanique', 'type' => 'FNE',
            // CONCATENATE(G;H;K;L) au format « Ymd ».
            'observations' => '20220404261179JF01147050',
        ]);

        $vraiCommentaire = Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'lot_import_id' => $lot->id,
            'numero' => 'IM-ghi789', 'n_facture' => '27', 'date' => '2022-04-05',
            'client' => 'NSIA', 'immatriculation' => '4455AB01', 'montant' => 90_000,
            'activite' => 'Mécanique', 'type' => 'FNE',
            'observations' => 'Relancé deux fois, promesse de règlement fin mai',
        ]);

        $this->artisan('impayes:ranger-les-colonnes --appliquer')->assertSuccessful();

        $this->assertNull($avecCle->fresh()->observations, 'La clé de doublon ne vaut pas un commentaire.');
        $this->assertSame(
            'Relancé deux fois, promesse de règlement fin mai',
            $vraiCommentaire->fresh()->observations,
            'Un vrai commentaire passe intact.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Outils
    |--------------------------------------------------------------------------
    */

    /** Un lot de dépôt au format « impayes » — c'est lui qui marque l'origine d'une reprise. */
    private function lotImpayes(): LotImport
    {
        return LotImport::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'deposant' => 'Reprise',
            'format' => 'impayes',
            'nom_fichier' => 'Etats des impayes.xlsx',
            'empreinte' => str_repeat('a', 64),
            'etat' => 'termine',
        ]);
    }

    /** Une créance d'un exercice donné, avec son encaissement s'il y en a un. */
    private function creance(
        int $exercice,
        string $numero,
        int $montant,
        int $encaisse = 0,
        ?string $immatriculation = null,
    ): Facture {
        $facture = Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => 'IMP-'.substr(md5($numero), 0, 10),
            'n_facture' => $numero,
            'date' => $exercice.'-06-15',
            'exercice_impayes' => $exercice,
            'client' => 'Client '.$numero,
            'immatriculation' => $immatriculation,
            'montant' => $montant,
            'activite' => 'Mécanique',
            'type' => 'FNE',
        ]);

        if ($encaisse > 0) {
            Encaissement::create([
                'entreprise_id' => $this->entreprise->id,
                'site_id' => $this->site->id,
                'facture_id' => $facture->id,
                'date' => $exercice.'-07-01',
                'montant' => $encaisse,
                'type' => 'Client',
                'moyen' => 'ESPÈCE',
                'client' => 'Client '.$numero,
            ]);
        }

        return $facture->fresh();
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }

    /** @return array<int, string> */
    private function etiquettes(array $onglets): array
    {
        $labels = [];

        foreach ($onglets as $onglet) {
            $labels[] = $onglet['label'];

            foreach ($onglet['groupe'] ?? [] as $sous) {
                $labels[] = $sous['label'];
            }
        }

        return $labels;
    }
}

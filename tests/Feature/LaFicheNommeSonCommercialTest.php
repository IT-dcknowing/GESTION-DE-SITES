<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Exploitation\Services\RapprochementProspectionDevis;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Services\CommercialDeLaFiche;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La fiche de réception nomme le commercial, et c'est ce qui relie le devis à la prospection.
 *
 * **Ce qui manquait, mesuré.** Sur 2 673 devis, 2 432 viennent de l'import du logiciel
 * d'atelier et ne portent aucun commercial : le chiffre de celui qui a décroché l'affaire
 * ne les compte pas. La direction a arbitré un moyen qui ne demande aucune colonne nouvelle
 * au logiciel — la fiche de réception a déjà une colonne libre, « INFORMATIONS SUR LA
 * SITUATION », et le saisisseur y met le commercial **en première position**.
 *
 * **Ce que ces tests tiennent.** Trois écritures acceptées (code de deux lettres, code de
 * l'application, nom), une ponctuation qui sépare sans couper les noms composés, et surtout
 * : **rien n'est deviné**. Un nom approchant n'est pas rapproché tout seul — il devient une
 * question, et la réponse vaut pour tous les dépôts suivants.
 *
 * **Une mesure à garder en tête.** Au 24/09, sur les 3 323 fiches en base, **3 seulement**
 * portent quelque chose dans cette colonne et aucune ne nomme un commercial. Cette lecture
 * ne rendra donc rien tant que les saisisseurs n'auront pas pris l'habitude : c'est attendu,
 * et c'est la raison d'être du travail — que l'habitude porte dès le premier fichier.
 */
class LaFicheNommeSonCommercialTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    private Commercial $koffi;

    private Commercial $marieClaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);

        $this->koffi = $this->commercial('C-0001', 'Koffi Yao', $ville);
        $this->marieClaire = $this->commercial('C-0002', 'Marie-Claire Aya', $ville);
    }

    // ------------------------------------------------------------------ découper la colonne

    public function test_le_debut_de_la_colonne_se_lit_avant_le_premier_separateur(): void
    {
        foreach (self::colonnesEtLeurDebut() as $cas => [$colonne, $attendu]) {
            $this->assertSame($attendu, CommercialDeLaFiche::premierSegment($colonne), $cas);
        }
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private static function colonnesEtLeurDebut(): array
    {
        return [
            'la virgule sépare' => ['Koffi Yao, véhicule livré', 'Koffi Yao'],
            'le point-virgule aussi' => ['KZ; en attente de pièce', 'KZ'],
            'le point suivi d’un espace' => ['C-0001. RAS', 'C-0001'],
            'le point final' => ['Koffi Yao.', 'Koffi Yao'],
            'le tiret entouré d’espaces' => ['Koffi Yao - véhicule livré', 'Koffi Yao'],
            'le blanc souligné entouré d’espaces' => ['KZ _ RAS', 'KZ'],
            'le tiret répété' => ['Koffi Yao--RAS', 'Koffi Yao'],
            // Le piège : pris au pied de la lettre, le tiret couperait le prénom composé
            // en deux et le code de l'application par le milieu.
            'un nom composé n’est pas coupé' => ['Marie-Claire Aya, RAS', 'Marie-Claire Aya'],
            'un code d’application n’est pas coupé' => ['C-0001', 'C-0001'],
            'le retour à la ligne sépare' => ["KZ\nvéhicule livré", 'KZ'],
            'une colonne vide ne dit rien' => ['', null],
            'une colonne absente non plus' => [null, null],
            'un début sans lettre ne nomme personne' => ['12/09/2026, Koffi Yao', null],
        ];
    }

    // ------------------------------------------------------------------ les trois écritures

    public function test_le_code_de_deux_lettres_du_logiciel_designe_son_commercial(): void
    {
        $compte = $this->compteDe($this->koffi);
        DB::table('codes_agents')->insert([
            'entreprise_id' => $this->entreprise->id, 'code' => 'KY', 'user_id' => $compte->id,
            'libelle' => 'Koffi Yao', 'occurrences' => 0, 'est_actif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lu = (new CommercialDeLaFiche($this->entreprise->id))->lire('KY, véhicule livré');

        $this->assertSame($this->koffi->id, $lu['commercial_id']);
        $this->assertSame('code_atelier', $lu['source']);
    }

    public function test_le_code_de_l_application_designe_son_commercial(): void
    {
        $lu = (new CommercialDeLaFiche($this->entreprise->id))->lire('C-0002 ; en attente');

        $this->assertSame($this->marieClaire->id, $lu['commercial_id']);
        $this->assertSame('code_application', $lu['source']);
    }

    public function test_un_nom_exact_designe_son_commercial_quelle_que_soit_la_casse(): void
    {
        $lu = (new CommercialDeLaFiche($this->entreprise->id))->lire('KOFFI YAO, RAS');

        $this->assertSame($this->koffi->id, $lu['commercial_id']);
        $this->assertSame('nom', $lu['source']);
    }

    // ------------------------------------------------------------------ ce qu'on ne devine pas

    public function test_un_nom_approchant_n_est_pas_rapproche_tout_seul_mais_devient_une_question(): void
    {
        $lu = (new CommercialDeLaFiche($this->entreprise->id))->lire('KOFI YAO, véhicule livré');

        // Le point qui compte : rapprocher tout seul attribuerait un jour le chiffre d'un
        // commercial à un autre, sans qu'une seule ligne ne s'affiche.
        $this->assertNull($lu['commercial_id']);
        $this->assertSame('KOFI YAO', $lu['saisi']);

        $question = CorrespondanceImport::withoutGlobalScopes()
            ->where('domaine', CommercialDeLaFiche::DOMAINE)->first();

        $this->assertNotNull($question);
        $this->assertFalse((bool) $question->est_resolue);
    }

    public function test_ce_qui_ne_ressemble_a_personne_n_est_pas_une_question(): void
    {
        // La colonne reste libre : « RAS » et « véhicule livré » y vivaient avant, et une
        // liste de questions où neuf sur dix n'en sont pas ne se lit plus du tout.
        foreach (['RAS', 'véhicule livré au client', 'en attente de pièce'] as $phrase) {
            $lu = (new CommercialDeLaFiche($this->entreprise->id))->lire($phrase);

            $this->assertNull($lu['saisi'], $phrase.' ne cherche à nommer personne');
        }

        $this->assertSame(0, CorrespondanceImport::withoutGlobalScopes()
            ->where('domaine', CommercialDeLaFiche::DOMAINE)->count());
    }

    public function test_les_noms_du_referentiel_sont_proposes_du_plus_proche_au_plus_lointain(): void
    {
        $proches = (new CommercialDeLaFiche($this->entreprise->id))->plusProches('KOFI YAO');

        // Classer, jamais décider : le bon nom en tête fait gagner un geste, le choisir à
        // la place de quelqu'un attribue un chiffre d'affaires sur une impression.
        $this->assertSame($this->koffi->id, array_key_first($proches));
        $this->assertStringContainsString('Koffi Yao', $proches[$this->koffi->id]);
    }

    // ------------------------------------------------------------------ la réponse et sa portée

    public function test_la_reponse_rattache_les_fiches_deja_lues_et_vaut_pour_les_suivantes(): void
    {
        $service = new CommercialDeLaFiche($this->entreprise->id);

        $fiche = $this->fiche('FR-KYN° 000001', 'KOFI YAO, véhicule livré');
        $service->lire($fiche->informations);
        $fiche->forceFill(['commercial_saisi' => 'KOFI YAO'])->save();

        $reprises = $service->repondre('KOFI YAO', $this->koffi->id);

        // Sans reprise, il faudrait redéposer le fichier pour qu'une réponse serve.
        $this->assertSame(1, $reprises);
        $this->assertSame($this->koffi->id, (int) $fiche->fresh()->commercial_id);
        $this->assertSame('correspondance', $fiche->fresh()->commercial_source);

        // Et la question ne se repose pas au dépôt suivant : c'est tout l'intérêt.
        $relu = (new CommercialDeLaFiche($this->entreprise->id))->lire('KOFI YAO, autre chose');
        $this->assertSame($this->koffi->id, $relu['commercial_id']);
        $this->assertSame('correspondance', $relu['source']);
    }

    public function test_une_reponse_vers_un_commercial_d_une_autre_entreprise_est_refusee(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $villeBeta = Ville::create([
            'entreprise_id' => $autre->id, 'code' => 'BET', 'nom' => 'Beta-ville', 'est_actif' => true,
        ]);
        $etranger = $this->commercial('C-9999', 'Intrus', $villeBeta, $autre);

        // Un identifiant recopié à la main ne doit pas rattacher nos fiches à quelqu'un
        // d'une autre maison — et, de proche en proche, notre chiffre d'affaires avec.
        $this->assertSame(0, (new CommercialDeLaFiche($this->entreprise->id))
            ->repondre('KOFI YAO', $etranger->id));
    }

    public function test_la_question_garde_ce_que_le_fichier_disait(): void
    {
        $service = new CommercialDeLaFiche($this->entreprise->id);
        $service->lire('KOFI YAO, RAS');

        $questions = $service->questions();

        $this->assertCount(1, $questions);
        // La forme normalisée sert de clé — « KOFI  yao » et « Kofi Yao » sont la même
        // question — et le référentiel est proposé, le plus proche d'abord.
        $this->assertSame('KOFI YAO', $questions->first()['correspondance']->valeur_source);
        $this->assertSame($this->koffi->id, array_key_first($questions->first()['candidats']));
    }

    // ------------------------------------------------------------------ ce que ça relie

    public function test_un_devis_sans_commercial_rejoint_la_prospection_par_la_fiche_qui_le_nomme(): void
    {
        // La fiche nomme Koffi Yao ; le devis cite cette fiche ; la prospection est de
        // Koffi Yao. C'est le chemin arbitré avec la direction, en deux sauts.
        $fiche = $this->fiche('FR-KYN° 000042', 'Koffi Yao, véhicule livré');
        $fiche->forceFill([
            'commercial_saisi' => 'Koffi Yao',
            'commercial_id' => $this->koffi->id,
            'commercial_source' => 'nom',
        ])->save();

        $prospection = Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->koffi->id,
            'numero' => 'P-0001',
            'date' => now()->subDays(4)->toDateString(),
            'client' => 'Garage du Centre',
            'activite' => 'Mécanique',
            'statut_validation' => 'Validée',
        ]);

        Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => null,
            'numero' => 'D-0001',
            'n_fiche_reception' => 'FR-KYN° 000042',
            'date_emission' => now()->subDays(2)->toDateString(),
            // Le nom du client diffère à dessein : sans la fiche, aucune piste ne relierait
            // ces deux-là, et c'est exactement le trou que la colonne vient combler.
            'client' => 'GARAGE CENTRE SARL',
            'activite' => 'Mécanique',
            'statut' => 'En attente',
            'montant_devis' => 450_000,
        ]);

        $propositions = RapprochementProspectionDevis::propositions([$this->site->id]);

        $this->assertCount(1, $propositions);
        $this->assertSame('commercial', $propositions->first()['motif']);
        $this->assertSame($prospection->id, $propositions->first()['prospection']->id);
    }

    public function test_la_piste_du_commercial_ne_se_confirme_pas_en_lot(): void
    {
        // Un commercial a plusieurs prospections : la fiche désigne une personne, pas une
        // affaire. C'est la date qui départage, et une date se regarde avant d'être crue.
        $this->assertNotContains('commercial', RapprochementProspectionDevis::MOTIFS_CERTAINS);
    }

    // ------------------------------------------------------------------ l'écran des traitements

    public function test_l_ecran_des_traitements_pose_la_question_et_enregistre_la_reponse(): void
    {
        (new CommercialDeLaFiche($this->entreprise->id))->lire('KOFI YAO, RAS');
        $question = CorrespondanceImport::withoutGlobalScopes()
            ->where('domaine', CommercialDeLaFiche::DOMAINE)->firstOrFail();

        $gerant = $this->gerant();

        $this->actingAs($gerant)->get(route('import.traitements'))
            ->assertOk()
            ->assertSee('KOFI YAO');

        $this->actingAs($gerant)
            ->post(route('import.traitements.commerciaux'), [
                'reponses' => [$question->id => $this->koffi->id],
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $question->fresh()->est_resolue);
        $this->assertSame((string) $this->koffi->id, $question->fresh()->valeur_cible);
    }

    public function test_repondre_releve_du_gerant_ou_du_superviseur_de_ville(): void
    {
        (new CommercialDeLaFiche($this->entreprise->id))->lire('KOFI YAO, RAS');
        $question = CorrespondanceImport::withoutGlobalScopes()
            ->where('domaine', CommercialDeLaFiche::DOMAINE)->firstOrFail();

        // Un responsable de site déciderait du commercial à qui revient le chiffre
        // d'affaires d'une affaire — y compris hors de son atelier.
        $this->actingAs($this->compte('responsable_site'))
            ->post(route('import.traitements.commerciaux'), [
                'reponses' => [$question->id => $this->koffi->id],
            ]);

        $this->assertFalse((bool) $question->fresh()->est_resolue);
    }

    public function test_une_question_laissee_vide_reste_posee(): void
    {
        (new CommercialDeLaFiche($this->entreprise->id))->lire('KOFI YAO, RAS');
        $question = CorrespondanceImport::withoutGlobalScopes()
            ->where('domaine', CommercialDeLaFiche::DOMAINE)->firstOrFail();

        // Répondre à moitié vaut mieux que d'être obligé de tout trancher d'un coup.
        $this->actingAs($this->gerant())
            ->post(route('import.traitements.commerciaux'), ['reponses' => [$question->id => null]])
            ->assertRedirect();

        $this->assertFalse((bool) $question->fresh()->est_resolue);
    }

    // ------------------------------------------------------------------ utilitaires

    private function gerant(): User
    {
        return $this->compte('gerant');
    }

    private function compte(string $role): User
    {
        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $role.'@alpha.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
            'ville_id' => $this->site->ville_id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $compte->assignRole($role);

        return $compte->fresh();
    }

    private function commercial(string $numero, string $nom, Ville $ville, ?Entreprise $entreprise = null): Commercial
    {
        $entreprise ??= $this->entreprise;

        $compte = User::create([
            'entreprise_id' => $entreprise->id,
            'name' => $nom,
            'email' => strtolower(str_replace([' ', '-', '’'], '', $nom)).'@'.$entreprise->slug.'.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ]);

        return Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id,
            'ville_id' => $ville->id,
            'user_id' => $compte->id,
            'numero' => $numero,
            'nom' => $nom,
            'statut' => 'Actif',
            'est_spontane' => false,
        ]);
    }

    private function compteDe(Commercial $commercial): User
    {
        return User::withoutGlobalScopes()->findOrFail($commercial->user_id);
    }

    private function fiche(string $numero, string $informations): DossierVehicule
    {
        return DossierVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero_fiche' => $numero,
            'informations' => $informations,
        ]);
    }
}

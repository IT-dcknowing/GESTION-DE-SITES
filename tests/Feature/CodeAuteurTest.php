<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Commun\Services\CodeAuteur;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Qui a saisi quoi.
 *
 * Cinq interfaces alimentent les mêmes tableaux. Sans marque d'auteur, une ligne
 * fausse ne se rattache à personne et la correction se fait au jugé. Le code répond
 * à la question par ville, par rôle et par personne — et se pose tout seul, sinon
 * une seule interface oubliée suffirait à trouer la piste.
 *
 * **Le dernier bloc désigne la personne, plus le rang de la ligne.** Il comptait les
 * saisies : le même agent portait un numéro différent sur chaque ligne, et deux numéros
 * différents le même jour sur deux écrans. Un identifiant qui change n'identifie plus
 * rien. Ces tests tiennent surtout cela — le code d'une personne ne bouge pas.
 */
class CodeAuteurTest extends TestCase
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
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_le_code_dit_la_ville_le_role_et_la_personne(): void
    {
        $commercial = $this->agent('Koffi Yao', 'commercial');

        $this->assertSame('A-C-KY-0001', CodeAuteur::pour($commercial));

        // Le même, appelé dix fois. C'est toute la différence avec un compteur.
        $this->assertSame('A-C-KY-0001', CodeAuteur::pour($commercial));
        $this->assertSame('A-C-KY-0001', CodeAuteur::pour($commercial->fresh()));
    }

    public function test_chaque_role_a_sa_propre_lettre(): void
    {
        // La première lettre du rôle ne suffisait pas : Commercial et Caissier donnent
        // tous deux « C », les deux Responsables tous deux « R ». Le code aurait cessé
        // de dire qui avait saisi, ce qui est précisément son objet.
        // Les rangs se suivent parce que les comptes sont ouverts l'un après l'autre :
        // c'est le rang de la personne dans l'entreprise, pas celui de sa saisie.
        $attendus = [
            'commercial' => 'A-C-AA-0001',
            'caissier' => 'A-K-AA-0002',
            'responsable_site' => 'A-R-AA-0003',
            'responsable_ville' => 'A-S-AA-0004',
            'gerant' => 'A-G-AA-0005',
        ];

        $lettres = [];

        foreach ($attendus as $role => $attendu) {
            $agent = $this->agent('Ama Ackah', $role, "$role@exemple.test");
            $code = CodeAuteur::pour($agent);

            $this->assertSame($attendu, $code, "Le rôle $role doit porter sa propre lettre.");
            $lettres[] = explode('-', $code)[1];
        }

        $this->assertCount(count($attendus), array_unique($lettres), 'Deux rôles ne peuvent pas partager une lettre.');
    }

    public function test_le_code_est_le_meme_sur_toutes_les_series_de_saisie(): void
    {
        $agent = $this->agent('Fatou Diabaté', 'caissier');
        $this->actingAs($agent);

        $encaissement = Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 50_000,
        ]);

        $charge = Charge::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'type_operation' => 'Charges',
            'libelle' => 'Achats pièces', 'moyen' => 'Espèces', 'montant' => 30_000,
        ]);

        // Un encaissement et un décaissement saisis par la même personne portent le même
        // code. Ce qui distingue les deux lignes, c'est leur numéro — ENC et DEC.
        $this->assertSame('A-K-FD-0001', $encaissement->code_auteur);
        $this->assertSame('A-K-FD-0001', $charge->code_auteur);
        $this->assertNotSame($encaissement->numero, $charge->numero);
    }

    public function test_deux_lignes_du_meme_agent_portent_le_meme_code(): void
    {
        $agent = $this->agent('Koffi Yao', 'commercial');
        $this->actingAs($agent);

        $commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'numero' => 'C-0001', 'nom' => 'Koffi Yao', 'statut' => 'Actif', 'est_spontane' => false,
        ]);

        $codes = collect(['SIFCA', 'MEDLOG', 'BOLLORÉ'])->map(fn (string $client, int $rang) => Prospection::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'commercial_id' => $commercial->id, 'numero' => 'P-000'.($rang + 1),
            'date' => now()->toDateString(), 'client' => $client,
            'moyen' => 'RDV', 'activite' => 'Mécanique', 'statut_validation' => 'Brouillon',
        ])->code_auteur);

        // C'est le défaut qu'on corrige : ces trois lignes portaient 0001, 0002, 0003.
        $this->assertSame(['A-C-KY-0001'], $codes->unique()->values()->all());
    }

    public function test_deux_agents_ne_partagent_jamais_un_rang(): void
    {
        $premier = $this->agent('Koffi Yao', 'commercial', 'un@exemple.test');
        $second = $this->agent('Sylvain Kouassi', 'commercial', 'deux@exemple.test');

        // Deux personnes, deux rangs : c'est la seule chose que le rang doit garantir.
        $this->assertSame('A-C-KY-0001', CodeAuteur::pour($premier));
        $this->assertSame('A-C-SK-0002', CodeAuteur::pour($second));
    }

    public function test_le_code_se_pose_tout_seul_a_la_saisie(): void
    {
        $agent = $this->agent('Koffi Yao', 'commercial');
        $this->actingAs($agent);

        $commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'numero' => 'C-0001', 'nom' => 'Koffi Yao', 'statut' => 'Actif', 'est_spontane' => false,
        ]);

        // Aucun appel explicite : c'est l'écouteur du modèle qui s'en charge, sans quoi
        // une seule interface distraite trouerait la piste.
        $prospection = Prospection::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'commercial_id' => $commercial->id, 'numero' => 'P-0001',
            'date' => now()->toDateString(), 'client' => 'SIFCA',
            'moyen' => 'RDV', 'activite' => 'Mécanique', 'statut_validation' => 'Brouillon',
        ]);

        $this->assertSame('A-C-KY-0001', $prospection->code_auteur);
    }

    public function test_les_mouvements_de_caisse_recoivent_un_numero_sans_qu_on_le_demande(): void
    {
        $caissier = $this->agent('Fatou Diabaté', 'caissier');
        $this->actingAs($caissier);

        // Les encaissements et décaissements n'avaient aucun numéro : on les désignait
        // par leur date et leur montant, ce qui ne suffit pas quand deux caissiers
        // saisissent le même jour.
        $encaissement = Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 50_000,
        ]);

        $charge = Charge::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'type_operation' => 'Charges',
            'libelle' => 'Achats pièces', 'moyen' => 'Espèces', 'montant' => 30_000,
        ]);

        // Le jour et le mois de l'opération entrent dans le numéro : ENC-1409-0001. On
        // les recompose ici plutôt que de les écrire en dur, sinon le test ne passerait
        // que le jour où il a été écrit.
        $jour = now()->format('dm');

        $this->assertSame("ENC-$jour-0001", $encaissement->numero);
        $this->assertSame("DEC-$jour-0001", $charge->numero);
        $this->assertSame('A-K-FD-0001', $encaissement->code_auteur);
        $this->assertSame('A-K-FD-0001', $charge->code_auteur);
    }

    public function test_un_numero_deja_pose_n_est_jamais_remplace(): void
    {
        $agent = $this->agent('Koffi Yao', 'commercial');
        $this->actingAs($agent);

        $encaissement = Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'numero' => 'ENC-9999',
            'date' => now()->toDateString(), 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 10_000,
        ]);

        // Sinon deux numéros seraient consommés pour une seule ligne, et la série
        // sauterait un cran à chaque saisie.
        $this->assertSame('ENC-9999', $encaissement->numero);
    }

    public function test_une_saisie_sans_personne_derriere_l_ecran_ne_designe_personne(): void
    {
        // Import, seeder, tâche planifiée : mieux vaut une colonne vide qu'un code
        // attribué à tort au dernier agent passé par là.
        $encaissement = Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 10_000,
        ]);

        $this->assertNull($encaissement->code_auteur);
        $this->assertSame(
            'ENC-'.now()->format('dm').'-0001',
            $encaissement->numero,
            'Le numéro, lui, reste dû : la série ne doit pas trouer.'
        );
    }

    public function test_le_code_ne_se_reecrit_pas_quand_l_agent_change_de_ville(): void
    {
        $agent = $this->agent('Koffi Yao', 'commercial');
        $this->actingAs($agent);

        $encaissement = Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => now()->toDateString(), 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 10_000,
        ]);

        $bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $agent->update(['ville_id' => $bouake->id]);

        // Une mutation ne réécrit pas l'histoire : la ligne a bien été saisie à Abidjan.
        $this->assertSame('A-C-KY-0001', $encaissement->fresh()->code_auteur);
    }

    private function agent(string $nom, string $role, string $email = 'agent@exemple.test'): User
    {
        $utilisateur = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => $nom,
            'email' => $email,
            'password' => Hash::make('password'),
            'ville_id' => $this->ville->id,
        ]);

        $utilisateur->assignRole($role);

        return $utilisateur->fresh();
    }
}

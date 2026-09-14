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
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deux défauts signalés, et ce qu'il a fallu distinguer pour les corriger.
 *
 * **Le mot de passe actuel réclamé au premier passage.** L'écran confondait deux
 * situations. Le changement *volontaire*, depuis son espace, doit exiger le mot de passe
 * en cours : c'est ce qui empêche un passant de s'emparer d'un poste laissé ouvert et d'en
 * changer la serrure. Le premier passage *imposé*, lui, suit immédiatement une connexion
 * réussie avec le mot de passe provisoire — le redemander ne prouve rien et fait retenir
 * un mot de passe qu'on est justement en train de remplacer.
 *
 * L'écran dépendait en outre de la couche interactive, sur un passage obligé : un bouton
 * muet à cet endroit ferme un compte neuf. C'est un formulaire qui poste.
 *
 * **La décision sur une prospection n'était pas signée.** La base disait « Validée » et
 * rien d'autre. Valider une prospection qui annonce un devis engage l'atelier à l'établir :
 * l'acte se signe — qui, quand, d'où, depuis quel poste.
 */
class MotDePasseEtSignatureTest extends TestCase
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
    | Le mot de passe
    |--------------------------------------------------------------------------
    */

    public function test_le_premier_passage_ne_reclame_pas_le_mot_de_passe_actuel(): void
    {
        $compte = $this->compte('caissier', impose: true);

        $this->actingAs($compte)->get(route('mot-de-passe.modifier'))
            ->assertOk()
            ->assertDontSee('Mot de passe actuel')
            ->assertSee('Choisir mon mot de passe');
    }

    public function test_le_changement_volontaire_reclame_toujours_le_mot_de_passe_actuel(): void
    {
        $compte = $this->compte('caissier', impose: false);

        $this->actingAs($compte)->get(route('mot-de-passe.modifier'))
            ->assertOk()
            ->assertSee('Mot de passe actuel');

        // Et la garde n'est pas qu'un affichage : sans lui, l'enregistrement est refusé.
        $this->actingAs($compte)->post(route('mot-de-passe.enregistrer'), [
            'nouveauMotDePasse' => 'nouveau-secret-123',
            'nouveauMotDePasse_confirmation' => 'nouveau-secret-123',
        ])->assertSessionHasErrors('motDePasseActuel');

        $this->assertTrue(Hash::check('motdepasse', $compte->fresh()->password));
    }

    public function test_le_premier_passage_enregistre_par_une_requete_ordinaire(): void
    {
        $compte = $this->compte('caissier', impose: true);

        // Un formulaire HTML qui poste : l'écran est un passage obligé, il ne peut pas
        // dépendre d'un script qui ne démarre pas toujours.
        $this->actingAs($compte)->post(route('mot-de-passe.enregistrer'), [
            'nouveauMotDePasse' => 'nouveau-secret-123',
            'nouveauMotDePasse_confirmation' => 'nouveau-secret-123',
        ])->assertRedirect(route('redirection'));

        $compte = $compte->fresh();

        $this->assertTrue(Hash::check('nouveau-secret-123', $compte->password));
        $this->assertFalse((bool) $compte->doit_changer_mot_de_passe);
    }

    public function test_le_provisoire_ne_peut_pas_etre_reconduit_a_l_identique(): void
    {
        $compte = $this->compte('caissier', impose: true);

        // Reconduire le provisoire laisserait en service un mot de passe connu d'un tiers,
        // en donnant l'apparence d'un changement.
        $this->actingAs($compte)->post(route('mot-de-passe.enregistrer'), [
            'nouveauMotDePasse' => 'motdepasse',
            'nouveauMotDePasse_confirmation' => 'motdepasse',
        ])->assertSessionHasErrors('nouveauMotDePasse');

        $this->assertTrue((bool) $compte->fresh()->doit_changer_mot_de_passe);
    }

    public function test_un_champ_cache_ne_fait_pas_sauter_la_verification(): void
    {
        $compte = $this->compte('caissier', impose: false);

        // Le drapeau est relu sur le compte, jamais sur ce que le formulaire prétend.
        $this->actingAs($compte)->post(route('mot-de-passe.enregistrer'), [
            'premiereConnexion' => '1',
            'doit_changer_mot_de_passe' => '1',
            'nouveauMotDePasse' => 'nouveau-secret-123',
            'nouveauMotDePasse_confirmation' => 'nouveau-secret-123',
        ])->assertSessionHasErrors('motDePasseActuel');
    }

    /*
    |--------------------------------------------------------------------------
    | La signature d'une décision
    |--------------------------------------------------------------------------
    */

    public function test_valider_une_prospection_inscrit_qui_quand_et_d_ou(): void
    {
        $responsable = $this->compte('responsable_site', impose: false);
        $this->site->forceFill(['responsable_id' => $responsable->id])->save();

        $prospection = $this->prospection($this->ficheCommerciale()->id);

        Volt::actingAs($responsable)->test('saisie.saisie-du-jour')
            ->call('validerProspection', $prospection->id);

        $prospection = $prospection->fresh();

        $this->assertSame('Validée', $prospection->statut_validation);
        $this->assertSame($responsable->id, (int) $prospection->valide_par);
        $this->assertSame($responsable->name, $prospection->validateur);
        $this->assertNotNull($prospection->valide_le);
        $this->assertNotNull($prospection->validation_ip);
    }

    public function test_refuser_est_signe_comme_valider(): void
    {
        $responsable = $this->compte('responsable_site', impose: false);
        $this->site->forceFill(['responsable_id' => $responsable->id])->save();

        $prospection = $this->prospection($this->ficheCommerciale()->id);

        Volt::actingAs($responsable)->test('saisie.saisie-du-jour')
            ->set('motifRefus.'.$prospection->id, 'Visite non confirmée')
            ->call('refuserProspection', $prospection->id);

        $prospection = $prospection->fresh();

        // Refuser est la décision qu'on conteste : elle se signe autant que l'autre.
        $this->assertSame('Refusée', $prospection->statut_validation);
        $this->assertSame($responsable->name, $prospection->validateur);
        $this->assertNotNull($prospection->valide_le);
    }

    public function test_la_fiche_du_commercial_ne_s_ouvre_pas_sur_la_ligne_d_un_autre(): void
    {
        $premier = $this->compte('commercial', impose: false, email: 'un@essai.test');
        $second = $this->compte('commercial', impose: false, email: 'deux@essai.test');

        $fiche = $this->ficheCommerciale($premier->id, 'Premier');

        $prospection = $this->prospection($fiche->id);

        $this->actingAs($premier)->get(route('prospection.fiche', $prospection->id))->assertOk();

        // Une adresse se tape à la main : le partage ne peut pas tenir au seul menu.
        $this->actingAs($second)->get(route('prospection.fiche', $prospection->id))->assertNotFound();
    }

    public function test_une_ligne_tranchee_avant_la_tracabilite_le_dit_sans_rien_inventer(): void
    {
        $commercial = $this->compte('commercial', impose: false);

        $fiche = $this->ficheCommerciale($commercial->id, 'Ancien');

        $prospection = $this->prospection($fiche->id);
        $prospection->forceFill(['statut_validation' => 'Validée'])->save();

        $this->actingAs($commercial)->get(route('prospection.fiche', $prospection->id))
            ->assertOk()
            ->assertSee('avant la mise en place de la traçabilité');
    }

    /*
    |--------------------------------------------------------------------------
    | Les deux codes du profil
    |--------------------------------------------------------------------------
    */

    /**
     * Les deux codes figurent sur les deux écrans de profil, et le second se modifie.
     *
     * Ils n'existaient que sur « Mon profil ». Or c'est « Mon espace » que la plupart des
     * rôles ouvrent depuis leur menu Paramètres : ceux qui saisissent dans le logiciel
     * d'atelier n'avaient aucun endroit pour corriger leur identifiant de liaison.
     */
    public function test_les_deux_codes_figurent_sur_les_deux_ecrans_de_profil(): void
    {
        $compte = $this->compte('caissier', impose: false);

        foreach ([route('mon-profil'), route('mon-espace')] as $adresse) {
            $this->actingAs($compte)->get($adresse)
                ->assertOk()
                ->assertSee('Code de saisie')
                // Le second est un champ, pas un affichage : il vient de l'autre logiciel
                // et c'est son titulaire qui le tient à jour.
                ->assertSee('name="liaison"', escape: false);
        }
    }

    public function test_l_identifiant_de_liaison_s_enregistre_depuis_mon_espace(): void
    {
        $compte = $this->compte('caissier', impose: false);

        // Une requête ordinaire : relier son propre travail ne dépend pas d'un script.
        $this->actingAs($compte)
            ->from(route('mon-espace'))
            ->post(route('mon-profil.liaison'), ['liaison' => 'kz'])
            ->assertRedirect(route('mon-espace'));

        $this->assertDatabaseHas('codes_agents', [
            'entreprise_id' => $this->entreprise->id,
            'user_id' => $compte->id,
            'code' => 'KZ',
        ]);
    }

    /**
     * L'écran de confirmation du mot de passe répond, au lieu d'une erreur serveur.
     *
     * Fortify publie `/user/confirm-password` dès que les vues sont activées, qu'on lui
     * en fournisse une ou non. Aucune n'était déclarée : l'adresse rendait une erreur 500
     * pour les huit rôles — relevé en parcourant tous les écrans de l'application. Une
     * adresse publique qui rend une trace de conteneur est une invitation à chercher
     * pourquoi.
     */
    public function test_la_confirmation_du_mot_de_passe_a_bien_un_ecran(): void
    {
        $compte = $this->compte('caissier', impose: false);

        $this->actingAs($compte)->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Confirmez votre mot de passe')
            ->assertSee('name="password"', escape: false);
    }

    // ------------------------------------------------------------------ utilitaires

    private function compte(string $role, bool $impose, string $email = 'essai@essai.test'): User
    {
        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $email,
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
            'doit_changer_mot_de_passe' => $impose,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);
        $compte->assignRole($role);

        return $compte->fresh();
    }

    /** Une fiche commerciale : la prospection ne peut pas exister sans son auteur. */
    private function ficheCommerciale(?int $userId = null, string $nom = 'Commercial'): Commercial
    {
        return Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->site->ville_id,
            'user_id' => $userId,
            'numero' => 'C-'.str_pad((string) (Commercial::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'nom' => $nom,
        ]);
    }

    private function prospection(?int $commercialId = null): Prospection
    {
        return Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $commercialId,
            'numero' => 'P-0001',
            'date' => now(),
            'client' => 'Client essai',
            'moyen' => 'RDV',
            'activite' => 'Mécanique',
            'passage' => true,
            'date_passage' => now(),
            'devis_apres_passage' => false,
            'statut_validation' => 'Transmise',
            'transmise_le' => now(),
        ]);
    }
}

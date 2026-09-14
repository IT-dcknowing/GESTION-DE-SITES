<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Actions\ModifierAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\ChoixDeVille;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le responsable commercial : il encadre les vendeurs d'une ville, et vend lui-même.
 *
 * **Le trou qu'il comble.** Entre le superviseur de ville, qui répond de tout, et le
 * commercial, qui répond de ses seules affaires, il manquait celui qui anime l'équipe. On
 * l'ouvrait donc en « commercial » — et il ne voyait rien des collègues qu'il encadre — ou
 * en « superviseur de ville », ce qui lui ouvrait la trésorerie, les charges et la gestion
 * des accès. Les deux réponses étaient fausses, l'une par défaut, l'autre par excès.
 *
 * **Ce que ces tests tiennent surtout, c'est la frontière.** Un rôle nouveau se juge moins
 * à ce qu'il ouvre qu'à ce qu'il laisse fermé : la hiérarchie n'est pas qu'une hauteur,
 * c'est aussi une branche, et encadrer des vendeurs ne donne aucun titre sur une créance
 * ni sur ce que l'entreprise dépense.
 */
class ResponsableCommercialTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        foreach ([[$this->abidjan, 'ABJ-1'], [$this->abidjan, 'ABJ-2'], [$this->bouake, 'BKE-1']] as [$ville, $code]) {
            Site::create([
                'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
                'code' => $code, 'nom' => $code, 'est_actif' => true,
            ]);
        }
    }

    public function test_le_role_est_cree_dans_chaque_entreprise(): void
    {
        $this->assertContains('responsable_commercial', ProvisionneurEntreprise::ROLES);

        $this->assertDatabaseHas('roles', [
            'name' => 'responsable_commercial',
            'entreprise_id' => $this->entreprise->id,
        ]);
    }

    public function test_il_atteint_la_chaine_commerciale_et_la_saisie(): void
    {
        $animateur = $this->animateur((string) $this->abidjan->id);

        foreach (['commerciaux', 'prospects', 'devis', 'chiffre-affaires', 'saisie-du-jour', 'mes-prospections'] as $ecran) {
            $this->actingAs($animateur)->get(route($ecran))
                ->assertStatus(200, "L'écran « $ecran » devrait lui être ouvert.");
        }
    }

    public function test_ce_qui_lui_reste_ferme(): void
    {
        $animateur = $this->animateur((string) $this->abidjan->id);

        /*
         * Charges et trésorerie : animer une équipe de vente ne donne aucun titre à lire
         * ce que l'entreprise dépense.
         *
         * Recouvrement et import : encadrer des vendeurs n'a aucun rapport avec la
         * poursuite d'une créance ni avec le versement d'un fichier dans la base.
         */
        foreach (['charges', 'tresorerie', 'recouvrement.tableau-de-bord', 'import.depot'] as $ecran) {
            $this->actingAs($animateur)->get(route($ecran))
                ->assertStatus(302, "L'écran « $ecran » ne devrait pas lui être ouvert.");
        }
    }

    public function test_son_bandeau_ne_porte_ni_recouvrement_ni_import(): void
    {
        $animateur = $this->animateur((string) $this->abidjan->id);

        $this->actingAs($animateur);
        $labels = $this->etiquettes(MenuNavigation::pour($animateur));

        $this->assertContains('Mon équipe', $labels);
        $this->assertContains('Saisie du jour', $labels);

        // Un onglet qui mène à un refus vaut moins qu'un onglet absent.
        $this->assertNotContains('Recouvrement', $labels);
        $this->assertNotContains('Import', $labels);
        $this->assertNotContains('Charges', $labels);
        $this->assertNotContains('Trésorerie', $labels);
    }

    public function test_il_vend_aussi_donc_il_recoit_sa_fiche_et_ses_objectifs(): void
    {
        $animateur = $this->animateur((string) $this->abidjan->id);

        $fiche = Commercial::withoutGlobalScopes()->where('user_id', $animateur->id)->first();

        $this->assertNotNull($fiche, "Sans fiche, ni ses prospections ni son chiffre ne seraient rattachables.");
        $this->assertSame($this->abidjan->id, $fiche->ville_id);
        $this->assertSame(600_000, (int) $fiche->objectif_mecanique);
    }

    public function test_il_ne_voit_que_sa_ville_par_defaut(): void
    {
        $animateur = $this->animateur((string) $this->abidjan->id);

        $this->assertFalse((bool) $animateur->couvre_toutes_les_villes);
        $this->assertSame(
            ['Abidjan'],
            PerimetreSites::villesVisibles($animateur->fresh())->pluck('nom')->all(),
        );
    }

    public function test_toutes_les_villes_lui_ouvre_l_entreprise_entiere(): void
    {
        $animateur = $this->animateur(ChoixDeVille::TOUTES);

        $this->assertTrue((bool) $animateur->fresh()->couvre_toutes_les_villes);

        $this->assertEqualsCanonicalizing(
            ['Abidjan', 'Bouaké'],
            PerimetreSites::villesVisibles($animateur->fresh())->pluck('nom')->all(),
        );

        // Sa fiche de vendeur vit quand même quelque part : la première ville, par ordre
        // alphabétique. C'est arbitraire, et documenté comme tel dans ChoixDeVille.
        $this->assertSame('Toutes les villes', ChoixDeVille::libelle($animateur->fresh()));
    }

    public function test_le_ramener_a_une_seule_ville_fait_retomber_le_drapeau(): void
    {
        $animateur = $this->animateur(ChoixDeVille::TOUTES);

        (new ModifierAcces)->executer($animateur->fresh(), 'responsable_commercial', [
            'nom' => 'AKA Prisca',
            'email' => 'prisca@alpha.test',
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => (string) $this->bouake->id,
        ], structureModifiable: true);

        // Sans cela, quelqu'un continuerait de voir les autres villes parce qu'il les
        // voyait hier — et rien à l'écran ne le dirait.
        $this->assertFalse((bool) $animateur->fresh()->couvre_toutes_les_villes);
        $this->assertSame(['Bouaké'], PerimetreSites::villesVisibles($animateur->fresh())->pluck('nom')->all());
    }

    public function test_un_commercial_ordinaire_ne_peut_pas_couvrir_toutes_les_villes(): void
    {
        /*
         * L'écran ne le propose pas — mais la même méthode est atteignable depuis le
         * navigateur avec la valeur que l'on veut. Le refus se tranche donc dans l'action,
         * pas dans le formulaire.
         */
        $this->assertFalse(ChoixDeVille::peutCouvrirToutesLesVilles('commercial'));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        (new CreerAcces)->executer($this->entreprise, 'commercial', [
            'nom' => 'Curieux',
            'email' => 'curieux@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'ville_id' => ChoixDeVille::TOUTES,
            'est_actif' => false,
        ]);
    }

    private function animateur(string $choixDeVille): User
    {
        $compte = (new CreerAcces)->executer($this->entreprise, 'responsable_commercial', [
            'nom' => 'AKA Prisca',
            'email' => 'prisca@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'ville_id' => $choixDeVille,
            'objectif_mecanique' => 600_000,
            'objectif_sinistre' => 400_000,
            'est_actif' => true,
        ]);

        /*
         * Un accès neuf doit changer son mot de passe, et le middleware déroute alors
         * chaque requête vers cet écran. On lève la consigne : ce n'est pas elle qu'on
         * éprouve ici, et sans cela tous les contrôles d'accès répondraient la même chose.
         */
        $compte->forceFill(['doit_changer_mot_de_passe' => false])->save();

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

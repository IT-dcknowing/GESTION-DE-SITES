<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Actions\CreerAcces;
use Modules\Noyau\Entreprises\Actions\ModifierAcces;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\ChoixDeLieu;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Une personne peut diriger deux ateliers à la fois.
 *
 * **Ce qui manquait.** Abidjan compte deux ateliers, et la même personne en dirige
 * parfois les deux. La base l'a toujours permis — la désignation est portée par
 * `sites.responsable_id`, donc rien n'empêche un compte de figurer sur deux lignes. Seul
 * l'écran ne le proposait pas : une liste de lieux, un seul choisissable, et aucune façon
 * de dire « les deux ».
 *
 * Ce que ces tests tiennent, c'est surtout la reprise. Nommer quelqu'un sur deux ateliers
 * est facile ; le ramener ensuite à un seul l'est moins, et un compte resté inscrit sur
 * son ancien atelier continuerait d'en voir le chiffre sans que rien ne l'indique.
 */
class ResponsableDePlusieursSitesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Site $siteUn;

    private Site $siteDeux;

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

        $this->siteUn = $this->site($this->abidjan, 'ABJ-1', 'Abidjan — Site 1');
        $this->siteDeux = $this->site($this->abidjan, 'ABJ-2', 'Abidjan — Site 2');
        $this->site($this->bouake, 'BKE-1', 'Bouaké');
    }

    public function test_la_liste_propose_tous_les_sites_pour_une_ville_qui_en_compte_plusieurs(): void
    {
        $options = ChoixDeLieu::options($this->entreprise->id);

        $this->assertSame('Abidjan — tous les sites', $options['ville:'.$this->abidjan->id] ?? null);

        /*
         * Mais pas pour Bouaké, qui n'a qu'un atelier : « Bouaké » et « Bouaké — tous les
         * sites » désigneraient la même chose, et le lecteur chercherait la différence.
         */
        $this->assertArrayNotHasKey('ville:'.$this->bouake->id, $options);

        // Les lieux restent proposés un par un : diriger les deux est un cas, pas la règle.
        $this->assertSame('Abidjan — Site 1', $options[(string) $this->siteUn->id] ?? null);
    }

    public function test_on_nomme_un_responsable_sur_les_deux_ateliers_d_une_ville(): void
    {
        $zita = $this->ouvrirUnAcces('ville:'.$this->abidjan->id);

        $this->assertSame($zita->id, $this->siteUn->fresh()->responsable_id);
        $this->assertSame($zita->id, $this->siteDeux->fresh()->responsable_id);

        // Le compte porte la ville, et aucun lieu : en désigner un au hasard ferait mentir
        // tous les écrans qui lisent cette colonne.
        $this->assertSame($this->abidjan->id, $zita->fresh()->ville_id);
        $this->assertNull($zita->fresh()->site_id);
    }

    public function test_ses_deux_ateliers_entrent_dans_son_perimetre(): void
    {
        $zita = $this->ouvrirUnAcces('ville:'.$this->abidjan->id);

        // C'est le seul test qui compte vraiment : la désignation ne sert à rien si le
        // périmètre de travail n'en tient pas compte.
        $this->assertEqualsCanonicalizing(
            [$this->siteUn->id, $this->siteDeux->id],
            Site::visiblesPour($zita->fresh())->pluck('id')->all(),
        );
    }

    public function test_la_reprise_d_acces_represente_bien_les_deux_ateliers(): void
    {
        $zita = $this->ouvrirUnAcces('ville:'.$this->abidjan->id);

        /*
         * `site_id` est nul : un écran qui s'y fierait rouvrirait le champ vide, comme si
         * personne n'avait jamais rien saisi. On relit donc les désignations.
         */
        $this->assertSame('ville:'.$this->abidjan->id, ChoixDeLieu::choixActuel($zita->fresh()));
        $this->assertSame('Abidjan — tous les sites', ChoixDeLieu::libelle($zita->fresh()));
    }

    public function test_le_ramener_a_un_seul_atelier_le_detache_de_l_autre(): void
    {
        $zita = $this->ouvrirUnAcces('ville:'.$this->abidjan->id);

        (new ModifierAcces)->executer($zita->fresh(), 'responsable_site', [
            'nom' => 'KOUADIO Zita',
            'email' => 'zita@alpha.test',
            'entreprise_id' => $this->entreprise->id,
            'site_id' => (string) $this->siteUn->id,
        ], structureModifiable: true);

        $this->assertSame($zita->id, $this->siteUn->fresh()->responsable_id);

        // Le point sensible : sans détachement, le Site 2 continuerait de remonter dans
        // son périmètre, et son chiffre avec.
        $this->assertNull($this->siteDeux->fresh()->responsable_id);
        $this->assertSame([$this->siteUn->id], Site::visiblesPour($zita->fresh())->pluck('id')->all());
    }

    public function test_un_seul_atelier_reste_le_cas_ordinaire(): void
    {
        $zita = $this->ouvrirUnAcces((string) $this->siteDeux->id);

        $this->assertNull($this->siteUn->fresh()->responsable_id);
        $this->assertSame($this->siteDeux->id, $zita->fresh()->site_id);
        $this->assertSame((string) $this->siteDeux->id, ChoixDeLieu::choixActuel($zita->fresh()));
        $this->assertSame('Abidjan — Site 2', ChoixDeLieu::libelle($zita->fresh()));
    }

    private function ouvrirUnAcces(string $choixDeLieu): User
    {
        return (new CreerAcces)->executer($this->entreprise, 'responsable_site', [
            'nom' => 'KOUADIO Zita',
            'email' => 'zita@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'site_id' => $choixDeLieu,
            'est_actif' => false,
        ]);
    }

    private function site(Ville $ville, string $code, string $nom): Site
    {
        return Site::create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'code' => $code,
            'nom' => $nom,
            'est_actif' => true,
        ]);
    }
}

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
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Muter quelqu'un : ce qui suit la personne, et ce qui reste où le travail a eu lieu.
 *
 * **La question posée par le propriétaire le 24/09.** « Si je modifie une personne étant à
 * Bouaké à San Pédro, ses prospections suivront ? — ce qui est logique, car c'est une
 * modification et non une réaffectation. » La réponse est oui, et ces tests la tiennent :
 * une prospection appartient à un **commercial**, pas à une ville. La fiche commerciale ne
 * change pas d'identifiant quand on la déplace, donc tout ce qui pointe dessus la suit
 * sans qu'une seule ligne soit réécrite.
 *
 * **La distinction qui tient le reste.** Ce qui suit la personne, c'est ce qui décrit *où
 * elle travaille maintenant* : son compte, sa fiche commerciale, son code de saisie. Ce
 * qui ne suit pas, c'est ce qui décrit *où un travail a eu lieu* : le lieu inscrit sur une
 * prospection déjà saisie reste celui du jour où elle a été saisie. Déplacer l'un
 * fausserait deux ateliers d'un coup, celui qu'on vide et celui qu'on gonfle.
 *
 * **Le défaut que ces tests ferment.** Le code du logiciel d'atelier ne suivait pas. On
 * déplaçait quelqu'un de Bouaké à Abidjan depuis l'écran des accès, son compte suivait, sa
 * fiche aussi — et son code restait rattaché à Bouaké. Au dépôt suivant, le contrôle
 * préalable avertissait que cent dix-sept lignes portaient un code d'une autre ville. Le
 * message était juste, la correction avait pourtant été faite : elle n'avait simplement
 * pas été faite partout.
 */
class MuterUnCommercialSuitPartoutTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $bouake;

    private Ville $sanPedro;

    private Ville $abidjan;

    private Site $siteUn;

    private Site $siteDeux;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $this->bouake = $this->ville('BKE', 'Bouaké');
        $this->sanPedro = $this->ville('SPD', 'San Pédro');
        $this->abidjan = $this->ville('ABJ', 'Abidjan');

        $this->site($this->bouake, 'BKE-1', 'Bouaké');
        $this->site($this->sanPedro, 'SPD-1', 'San Pédro');
        $this->siteUn = $this->site($this->abidjan, 'ABJ-1', 'Abidjan — Site 1');
        $this->siteDeux = $this->site($this->abidjan, 'ABJ-2', 'Abidjan — Site 2');
    }

    // ------------------------------------------------------------------ ce qui suit

    public function test_les_prospections_suivent_la_personne_qui_change_de_ville(): void
    {
        $zeinab = $this->commercialA($this->bouake);
        $fiche = Commercial::withoutGlobalScopes()->where('user_id', $zeinab->id)->firstOrFail();

        $prospection = $this->prospectionDe($fiche, 'Garage du Centre');

        $this->muter($zeinab, $this->sanPedro);

        // La fiche commerciale est la même — c'est elle qui a déménagé, pas une autre.
        $this->assertSame($fiche->id, Commercial::withoutGlobalScopes()
            ->where('user_id', $zeinab->id)->value('id'));
        $this->assertSame($this->sanPedro->id, (int) $fiche->fresh()->ville_id);

        // Et la prospection est toujours la sienne : rien n'a été réécrit pour cela.
        $this->assertSame($fiche->id, (int) $prospection->fresh()->commercial_id);
        $this->assertSame(1, Prospection::withoutGlobalScopes()
            ->where('commercial_id', $fiche->id)->count());
    }

    public function test_le_code_de_saisie_suit_la_personne_depuis_l_ecran_des_acces(): void
    {
        $zeinab = $this->commercialA($this->bouake, code: 'KZ');

        $this->assertSame($this->bouake->id, (int) $this->codeDe($zeinab)->ville_id);

        $this->muter($zeinab, $this->abidjan, $this->siteUn);

        // Le point qui manquait : sans lui, le contrôle du dépôt continuait d'annoncer
        // « code rattaché à Bouaké » pour quelqu'un qui travaille à Abidjan.
        $code = $this->codeDe($zeinab);
        $this->assertSame($this->abidjan->id, (int) $code->ville_id);
        $this->assertSame($this->siteUn->id, (int) $code->site_id);
    }

    public function test_le_changement_de_code_est_annonce_a_qui_le_fait(): void
    {
        $zeinab = $this->commercialA($this->bouake, code: 'KZ');

        $changements = $this->muter($zeinab, $this->sanPedro);

        // Un déplacement silencieux se découvre au dépôt suivant, c'est-à-dire trop tard.
        $this->assertArrayHasKey('code de saisie', $changements);
        $this->assertStringContainsString('KZ', $changements['code de saisie']);
    }

    public function test_sans_code_de_saisie_rien_n_est_annonce(): void
    {
        $paul = $this->commercialA($this->bouake);

        $this->assertArrayNotHasKey('code de saisie', $this->muter($paul, $this->sanPedro));
    }

    // ------------------------------------------------------------------ ce qui ne suit pas

    public function test_le_lieu_inscrit_sur_une_prospection_deja_saisie_ne_bouge_pas(): void
    {
        $zeinab = $this->commercialA($this->bouake);
        $fiche = Commercial::withoutGlobalScopes()->where('user_id', $zeinab->id)->firstOrFail();
        $prospection = $this->prospectionDe($fiche, 'Garage du Centre');
        $siteDOrigine = (int) $prospection->site_id;

        $this->muter($zeinab, $this->sanPedro);

        // Le travail a eu lieu à Bouaké : le déplacer viderait Bouaké et gonflerait San
        // Pédro d'un chiffre que San Pédro n'a pas fait.
        $this->assertSame($siteDOrigine, (int) $prospection->fresh()->site_id);
    }

    // ------------------------------------------------------------------ le site précis

    public function test_un_commercial_se_rattache_a_un_site_precis_de_sa_ville(): void
    {
        $koffi = $this->commercialA($this->abidjan, site: $this->siteDeux);

        // C'est la demande du chantier « rattachement facultatif au site » : Abidjan compte
        // deux ateliers, et le commercial travaille dans l'un des deux.
        $this->assertSame($this->abidjan->id, (int) $koffi->fresh()->ville_id);
        $this->assertSame($this->siteDeux->id, (int) $koffi->fresh()->site_id);
    }

    public function test_un_site_d_une_autre_ville_ne_se_pose_pas(): void
    {
        $koffi = $this->commercialA($this->bouake);

        // Un identifiant recopié à la main dans le formulaire ne doit pas rattacher
        // quelqu'un à l'atelier d'une ville où il ne travaille pas.
        $this->muter($koffi, $this->sanPedro, $this->siteUn);

        $this->assertSame($this->bouake->id, (int) $koffi->fresh()->ville_id,
            'le périmètre refusé ne se pose pas à moitié');
    }

    public function test_le_site_retombe_quand_on_ramene_le_commercial_a_la_ville_seule(): void
    {
        $koffi = $this->commercialA($this->abidjan, site: $this->siteUn);

        $this->muter($koffi, $this->abidjan);

        // Rester inscrit sur le Site 1 alors qu'on a choisi « Abidjan » ferait lire à
        // l'écran une précision que personne n'a demandée.
        $this->assertNull($koffi->fresh()->site_id);
    }

    // ------------------------------------------------------------------ utilitaires

    private function ville(string $code, string $nom): Ville
    {
        return Ville::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => $code, 'nom' => $nom, 'est_actif' => true,
        ]);
    }

    private function site(Ville $ville, string $code, string $nom): Site
    {
        return Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => $code, 'nom' => $nom, 'est_actif' => true,
        ]);
    }

    private function commercialA(Ville $ville, ?Site $site = null, ?string $code = null): User
    {
        static $rang = 0;
        $rang++;

        return (new CreerAcces)->executer($this->entreprise, 'commercial', array_filter([
            'nom' => 'Employé '.$rang,
            'email' => 'employe'.$rang.'@alpha.test',
            'mot_de_passe' => 'motdepasse',
            'ville_id' => (string) $ville->id,
            'site_id' => $site ? (string) $site->id : null,
            'code_agent' => $code,
            'est_actif' => false,
        ], fn ($valeur) => $valeur !== null));
    }

    /** @return array<string, string> */
    private function muter(User $compte, Ville $ville, ?Site $site = null): array
    {
        return (new ModifierAcces)->executer($compte->fresh(), 'commercial', [
            'nom' => $compte->name,
            'email' => $compte->email,
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => (string) $ville->id,
            'site_id' => $site ? (string) $site->id : null,
        ], structureModifiable: true);
    }

    private function codeDe(User $compte): CodeAgent
    {
        return CodeAgent::withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail();
    }

    private function prospectionDe(Commercial $fiche, string $client): Prospection
    {
        static $rang = 0;
        $rang++;

        return Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => Site::withoutGlobalScopes()->where('ville_id', $fiche->ville_id)->value('id'),
            'commercial_id' => $fiche->id,
            'numero' => 'P-'.str_pad((string) $rang, 4, '0', STR_PAD_LEFT),
            'date' => now()->subDays(10),
            'client' => $client,
            'activite' => 'Sinistre',
            'statut_validation' => 'Validée',
        ]);
    }
}

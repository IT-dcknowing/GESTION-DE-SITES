<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le « du … au … » de l'état des impayés.
 *
 * L'écran n'avait pas de période : son bandeau ne proposait que l'année de l'état. C'est
 * juste pour un registre annuel — une créance garde son année d'origine — mais insuffisant
 * dès qu'on rapproche un dépôt avec un bordereau : « les factures déposées entre le 1er et
 * le 15 mars » n'était pas une question qu'on pouvait poser.
 *
 * Les bornes comptent sur la **date de dépôt, sinon l'édition** : la même date que celle
 * d'où court l'ancienneté affichée dans le tableau. Deux dates différentes sur un même écran
 * se contrediraient sans prévenir, et c'est pourquoi la règle SQL est ici confrontée à sa
 * version PHP, comme l'est déjà celle du tiers payant.
 */
class LEtatSeFiltreAuJourTest extends TestCase
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

    public function test_les_bornes_reduisent_l_etat_aux_creances_deposees_dans_l_intervalle(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $this->creance('F-AVANT', 100_000, '2026-03-01');
        $this->creance('F-DEDANS', 200_000, '2026-03-10');
        $this->creance('F-APRES', 300_000, '2026-03-25');

        Volt::actingAs($gerant)->test('pilotage.impayes')
            ->set('exercice', 2026)
            ->set('dateDebut', '2026-03-05')
            ->set('dateFin', '2026-03-15')
            ->assertSee('F-DEDANS')
            ->assertDontSee('F-AVANT')
            ->assertDontSee('F-APRES');
    }

    public function test_les_totaux_du_bandeau_suivent_les_bornes(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $this->creance('F-AVANT', 100_000, '2026-03-01');
        $this->creance('F-DEDANS', 200_000, '2026-03-10');

        $ecran = Volt::actingAs($gerant)->test('pilotage.impayes')->set('exercice', 2026);

        // Sans borne, le registre entier : les deux créances.
        $this->assertSame(300_000, (int) $ecran->get('totaux')['facture']);

        $ecran->set('dateDebut', '2026-03-05')->set('dateFin', '2026-03-15');

        // Un filtre qui ne réduirait que la liste, en laissant le total de tête inchangé,
        // ferait mentir le bandeau : c'est la panne qu'on veut interdire ici.
        $this->assertSame(200_000, (int) $ecran->get('totaux')['facture']);
        $this->assertSame(1, (int) $ecran->get('totaux')['lignes']);
    }

    public function test_une_creance_sans_date_de_depot_se_filtre_sur_son_edition(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        // Une facture de l'atelier, jamais passée par un dépôt : l'édition est la seule
        // date qu'on ait, et c'est elle qui doit répondre au filtre.
        $sansDepot = $this->creance('F-ATELIER', 150_000, '2026-03-10');
        $sansDepot->update(['date' => '2026-03-10', 'date_reception' => null]);

        Volt::actingAs($gerant)->test('pilotage.impayes')
            ->set('exercice', 2026)
            ->set('dateDebut', '2026-03-05')
            ->set('dateFin', '2026-03-15')
            ->assertSee('F-ATELIER');
    }

    public function test_une_borne_forgee_dans_l_adresse_n_ecarte_rien(): void
    {
        $gerant = $this->compte('gerant');
        $this->actingAs($gerant);

        $this->creance('F-DEDANS', 200_000, '2026-03-10');

        // Ni une date ni rien de lisible : la page ne tombe pas, et ne filtre pas.
        Volt::actingAs($gerant)->test('pilotage.impayes')
            ->set('exercice', 2026)
            ->set('dateDebut', "2026-03-05' or 1=1 --")
            ->set('dateFin', 'demain')
            ->assertOk()
            ->assertSee('F-DEDANS');
    }

    public function test_la_date_de_depart_dit_la_meme_chose_en_php_et_en_sql(): void
    {
        $this->actingAs($this->compte('gerant'));

        $avecDepot = $this->creance('F-DEPOT', 100_000, '2026-03-10');

        $sansDepot = $this->creance('F-EDITION', 100_000, '2026-03-10');
        $sansDepot->update(['date' => '2026-02-02', 'date_reception' => null]);

        $enBase = Facture::query()
            ->selectRaw('n_facture, '.Recouvrement::EXPRESSION_DATE_DE_DEPART.' as depart')
            ->pluck('depart', 'n_facture');

        foreach ([$avecDepot, $sansDepot] as $facture) {
            $facture->refresh();

            $this->assertSame(
                Recouvrement::dateDeDepart($facture)?->toDateString(),
                // SQLite rend la date telle qu'elle est stockée ; on la ramène au jour.
                substr((string) $enBase[$facture->n_facture], 0, 10),
                'La règle SQL et la règle PHP doivent désigner le même jour.',
            );
        }

        // Et la règle SQL reste une expression valide pour la base, pas seulement une chaîne.
        $this->assertSame(2, (int) DB::table('factures')
            ->whereRaw(Recouvrement::EXPRESSION_DATE_DE_DEPART.' is not null')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Outillage
    |--------------------------------------------------------------------------
    */

    private function creance(string $numero, int $montant, string $depot): Facture
    {
        return Facture::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => 'IMP-'.substr(md5($numero), 0, 10),
            'n_facture' => $numero,
            'date' => $depot,
            'date_reception' => $depot,
            'exercice_impayes' => 2026,
            'client' => 'Client '.$numero,
            'montant' => $montant,
            'activite' => 'Sinistre',
        ]);
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
}

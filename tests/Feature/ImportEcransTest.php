<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\LotImport;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les écrans du module Import : s'ouvrent-ils, et à qui ?
 *
 * Deux questions séparées, et la seconde compte davantage. Qu'une page s'affiche se voit
 * tout de suite ; qu'elle s'affiche **à quelqu'un qui n'y a pas droit** ne se voit jamais,
 * jusqu'au jour où c'est trop tard. On vérifie donc les deux, et surtout que l'adresse
 * écrite à la main se heurte au même refus que l'onglet grisé.
 *
 * Le rendu lui-même n'est pas une formalité dans un composant Volt : une variable ordinaire
 * du bloc de préparation n'atteint pas le gabarit, un `computed` et un `state` ne peuvent
 * pas porter le même nom, et une variable de boucle masque silencieusement une propriété.
 * Aucune de ces fautes ne se voit à l'analyse syntaxique — seulement au rendu.
 */
class ImportEcransTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Site $siteUn;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['gerant', 'responsable_ville', 'responsable_site', 'caissier', 'commercial'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->siteUn = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-2', 'nom' => 'Abidjan — Site 2', 'est_actif' => true,
        ]);
    }

    public function test_le_gerant_ouvre_tous_les_ecrans(): void
    {
        $gerant = $this->compte('gerant');
        $this->garnir();

        foreach (['depot', 'lots', 'codes'] as $page) {
            $this->actingAs($gerant)
                ->get(route('import.'.$page))
                ->assertOk();
        }

        $lot = LotImport::withoutGlobalScopes()->first();

        $this->actingAs($gerant)->get(route('import.lot', $lot))->assertOk();
    }

    public function test_les_ecrans_s_ouvrent_aussi_quand_rien_n_a_encore_ete_importe(): void
    {
        // Le cas du premier jour, celui qu'on oublie de tester : aucune donnée nulle part.
        // Une page qui plante sur une base vide donne la pire des premières impressions.
        $gerant = $this->compte('gerant');

        foreach (['depot', 'lots', 'codes'] as $page) {
            $this->actingAs($gerant)->get(route('import.'.$page))->assertOk();
        }
    }

    public function test_le_responsable_de_site_depose_mais_ne_tranche_pas_le_referentiel(): void
    {
        $responsable = $this->compte('responsable_site', ['site_id' => $this->siteUn->id, 'ville_id' => $this->abidjan->id]);

        $this->actingAs($responsable)->get(route('import.depot'))->assertOk();
        $this->actingAs($responsable)->get(route('import.lots'))->assertOk();

        // Décider que « KZ » travaille au Site 1 déplace du chiffre d'affaires entre deux
        // ateliers dont l'un est le sien. L'adresse tapée à la main doit se heurter au même
        // refus que l'onglet grisé.
        $this->actingAs($responsable)
            ->get(route('import.codes'))
            ->assertRedirect(route('import.depot'));
    }

    public function test_la_comptabilite_consulte_sans_pouvoir_deposer(): void
    {
        $comptable = $this->compte('caissier', ['ville_id' => $this->abidjan->id]);

        $this->actingAs($comptable)->get(route('import.lots'))->assertOk();

        $this->actingAs($comptable)
            ->get(route('import.depot'))
            ->assertRedirect(route('import.lots'));
    }

    public function test_un_role_etranger_au_module_n_y_entre_pas(): void
    {
        $commercial = $this->compte('commercial', ['ville_id' => $this->abidjan->id]);

        // L'application ne renvoie volontairement pas un 403 nu : elle reconduit vers
        // l'espace du compte réellement connecté. Le motif est écrit dans bootstrap/app.php
        // — deux onglets partagent un cookie de session, et un « vous n'avez pas les rôles
        // requis » serait incompréhensible pour quelqu'un qui n'a rien fait de mal. Ce test
        // vérifie donc que la page ne s'ouvre pas, sans imposer une forme de refus que le
        // reste de l'application n'emploie pas.
        $this->actingAs($commercial)->get(route('import.depot'))->assertRedirect(route('redirection'));
        $this->actingAs($commercial)->get(route('import.lots'))->assertRedirect(route('redirection'));
    }

    public function test_un_lot_d_une_autre_entreprise_est_introuvable(): void
    {
        $voisine = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);

        $lotVoisin = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $voisine->id,
            'deposant' => 'Quelqu\'un d\'autre',
            'format' => 'parc',
            'nom_fichier' => 'secret.xlsx',
            'empreinte' => str_repeat('b', 64),
            'taille' => 10,
            'etat' => 'termine',
        ]);

        // La liaison de route passe par le modèle, donc par le filtre d'entreprise :
        // l'identifiant d'un lot voisin ne se résout pas. C'est le cloisonnement le moins
        // coûteux — il n'y a pas de contrôle à oublier d'écrire.
        $this->actingAs($this->compte('gerant'))
            ->get(route('import.lot', $lotVoisin->id))
            ->assertNotFound();
    }

    public function test_le_parc_a_quitte_l_import_pour_les_indicateurs(): void
    {
        $gerant = $this->compte('gerant');
        $this->garnir();

        // Il vit maintenant avec le chiffre d'affaires et la trésorerie : l'import le
        // remplit, l'exploitation le consulte.
        $this->actingAs($gerant)->get(route('parc-vehicules'))->assertOk();

        $menu = collect(\Modules\Noyau\Commun\Services\MenuNavigation::pour($gerant));
        $indicateurs = $menu->firstWhere('label', 'Indicateurs');

        $this->assertNotNull($indicateurs);
        $this->assertTrue(collect($indicateurs['groupe'])->contains('label', 'Parc véhicules'));
    }

    public function test_l_onglet_import_apparait_pour_qui_y_a_droit(): void
    {
        $menu = collect(\Modules\Noyau\Commun\Services\MenuNavigation::pour($this->compte('gerant')));
        $this->assertTrue($menu->contains('label', 'Import'));

        $menuCommercial = collect(\Modules\Noyau\Commun\Services\MenuNavigation::pour(
            $this->compte('commercial', ['ville_id' => $this->abidjan->id], 'com@alpha.test')
        ));
        $this->assertFalse($menuCommercial->contains('label', 'Import'));
    }

    /** Un jeu de données minimal mais représentatif : chaque écran doit avoir quelque chose à montrer. */
    private function garnir(): void
    {
        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'deposant' => 'K. Désirée',
            'format' => 'parc',
            'nom_fichier' => 'Abidjan_Situation du parc190826.xls',
            'empreinte' => str_repeat('a', 64),
            'taille' => 779264,
            'etat' => 'termine',
            'lignes_lues' => 2204,
            'lignes_creees' => 2203,
            'lignes_rejetees' => 1,
            'message' => '2204 lignes lues : 2203 créées, 1 rejetée.',
        ]);

        $lot->rejets()->create([
            'feuille' => 'A',
            'numero_ligne' => 1061,
            'motif' => 'Le statut « Essuie-glace à remplacer » n\'est pas un statut connu.',
            'valeurs' => ['FR-ABN° 012094', 'MAZDA', 'CX-5'],
        ]);

        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'KZ', 'occurrences' => 924, 'est_actif' => true,
        ]);

        CorrespondanceImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'domaine' => 'site',
            'valeur_source' => 'ABIIDJAN', 'est_resolue' => false, 'occurrences' => 1,
        ]);

        DossierVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'lot_import_id' => $lot->id,
            'numero_fiche' => 'FR-KZN° 010669',
            'immatriculation' => 'AA598AZ',
            'marque' => 'MG', 'modele' => 'RX-8',
            'client' => 'SGCI CI-ENERGIES',
            'motif' => 'SINISTRE',
            'statut' => 'DEVIS VALIDE / TRAVAUX EN COURS',
            'travaux' => "A REMPLACER ET PEINDRE :\nPARE-CHOC AVANT",
            'code_agent' => 'KZ',
            'source_rattachement' => 'depot',
            'rattachement_presume' => true,
            'date_fiche' => '2026-01-02',
        ]);

        // Une fiche sans code : elle alimente le troisième onglet de l'écran d'attente.
        DossierVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->abidjan->id,
            'numero_fiche' => 'PR--13699',
            'statut' => 'TRAVAUX TERMINES / VEHICULE LIVRE',
            'source_rattachement' => 'depot',
            'rattachement_presume' => true,
        ]);
    }

    private function compte(string $role, array $extra = [], string $email = null): User
    {
        $utilisateur = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Compte '.$role,
            'email' => $email ?? $role.'@alpha.test',
            'password' => 'motdepasse',
            'email_verified_at' => now(),
            'est_actif' => true,
        ] + $extra);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->entreprise->id);
        $utilisateur->assignRole($role);

        return $utilisateur->fresh();
    }
}

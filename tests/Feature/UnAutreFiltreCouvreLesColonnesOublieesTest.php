<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chercher sur les colonnes qu'aucun filtre ne couvre.
 *
 * **La demande, du 28/09** : « pour toutes ces pages, en plus des filtres disponibles, fais
 * un filtre spécial […] un bouton "autre filtre" ; dans ce bouton on doit avoir une liste
 * déroulante de toutes les colonnes manquantes de leur tableau […] si la colonne est
 * sélectionnée, un ou deux champs devront s'ouvrir selon le type de données : pour les
 * données à valeur fixe une liste déroulante, pour les données à recherche un champ, mais
 * pour les données à date deux champs — cela servira d'intervalle. »
 *
 * **Le manque que cela comble.** Chaque écran offre trois ou quatre filtres ; les tableaux
 * portent quinze à vingt-cinq colonnes. Les autres ne se filtraient pas : on exportait, on
 * ouvrait le classeur, et l'on filtrait ailleurs — pour une question que l'application
 * pouvait répondre.
 *
 * **Ce que ce test verrouille en priorité** : qu'une colonne non déclarée ne puisse pas
 * servir de filtre. Les noms viennent de l'état Livewire, donc du navigateur.
 */
class UnAutreFiltreCouvreLesColonnesOublieesTest extends TestCase
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

    // ------------------------------------------------------------------ la garde

    /**
     * Une colonne non déclarée ne filtre rien.
     *
     * C'est la garantie qui compte. Sans elle, l'état Livewire — c'est-à-dire le
     * navigateur — pourrait composer une condition sur n'importe quelle colonne de la
     * table, y compris celles qui portent le périmètre.
     */
    public function test_une_colonne_non_declaree_est_ignoree(): void
    {
        $this->creance('F-001', 'NSIA ASSURANCES', 'SGBCI');
        $this->creance('F-002', 'ALLIANZ', 'BICICI');

        $requete = Facture::query();

        FiltreLibre::appliquer(
            $requete,
            ['factures.banque' => FiltreLibre::colonne('Banque')],
            ['factures.entreprise_id' => ['valeur' => '999']],
        );

        $this->assertSame(2, $requete->count(), 'Une colonne non déclarée ne doit poser aucune condition.');
    }

    // ------------------------------------------------------------------ les trois formes

    public function test_un_texte_cherche_ce_qui_contient(): void
    {
        $this->creance('F-001', 'NSIA ASSURANCES', 'SGBCI');
        $this->creance('F-002', 'ALLIANZ', 'BICICI');

        $requete = Facture::query();

        FiltreLibre::appliquer(
            $requete,
            ['factures.banque' => FiltreLibre::colonne('Banque')],
            ['factures.banque' => ['valeur' => 'SGB']],
        );

        $this->assertSame(['F-001'], $requete->pluck('n_facture')->all());
    }

    public function test_une_liste_cherche_la_valeur_exacte(): void
    {
        $this->creance('F-001', 'NSIA ASSURANCES', 'SGBCI', activite: 'Sinistre');
        $this->creance('F-002', 'ALLIANZ', 'BICICI', activite: 'Mécanique');

        $requete = Facture::query();

        FiltreLibre::appliquer(
            $requete,
            ['factures.activite' => FiltreLibre::colonne('Activité', 'liste', ['Sinistre' => 'Sinistre'])],
            ['factures.activite' => ['valeur' => 'Sinistre']],
        );

        $this->assertSame(['F-001'], $requete->pluck('n_facture')->all());
    }

    /**
     * « Non renseigné » est un choix à part entière, et souvent celui qu'on cherche.
     *
     * Les lignes dont la colonne est vide sont celles qu'il faut compléter : sans ce
     * choix, elles seraient précisément les seules introuvables.
     */
    public function test_le_choix_non_renseigne_trouve_les_cases_vides(): void
    {
        $this->creance('F-001', 'NSIA ASSURANCES', 'SGBCI');
        $this->creance('F-002', 'ALLIANZ', null);

        $requete = Facture::query();

        FiltreLibre::appliquer(
            $requete,
            ['factures.banque' => FiltreLibre::colonne('Banque')],
            ['factures.banque' => ['valeur' => '__vide__']],
        );

        $this->assertSame(['F-002'], $requete->pluck('n_facture')->all());
    }

    /**
     * Une date ouvre deux champs, et chacun est facultatif séparément.
     *
     * « Depuis le 1er mars » et « jusqu'au 31 mars » sont deux questions réelles ; obliger
     * à remplir les deux ferait taper une date qu'on ne cherche pas, et qui écarterait des
     * lignes sans qu'on l'ait voulu.
     */
    public function test_une_date_ouvre_un_intervalle_dont_chaque_borne_est_facultative(): void
    {
        $this->creance('F-001', 'A', null, date: '2026-03-01');
        $this->creance('F-002', 'B', null, date: '2026-03-15');
        $this->creance('F-003', 'C', null, date: '2026-04-02');

        $declarees = ['factures.date' => FiltreLibre::colonne('Date', 'date')];

        $depuis = Facture::query();
        FiltreLibre::appliquer($depuis, $declarees, ['factures.date' => ['de' => '2026-03-10']]);
        $this->assertSame(['F-002', 'F-003'], $depuis->orderBy('n_facture')->pluck('n_facture')->all());

        $jusqua = Facture::query();
        FiltreLibre::appliquer($jusqua, $declarees, ['factures.date' => ['a' => '2026-03-10']]);
        $this->assertSame(['F-001'], $jusqua->pluck('n_facture')->all());

        $tranche = Facture::query();
        FiltreLibre::appliquer($tranche, $declarees, ['factures.date' => ['de' => '2026-03-01', 'a' => '2026-03-31']]);
        $this->assertSame(['F-001', 'F-002'], $tranche->orderBy('n_facture')->pluck('n_facture')->all());
    }

    public function test_un_nombre_ouvre_aussi_un_intervalle(): void
    {
        $this->creance('F-001', 'A', null, montant: 100_000);
        $this->creance('F-002', 'B', null, montant: 500_000);
        $this->creance('F-003', 'C', null, montant: 900_000);

        $requete = Facture::query();

        FiltreLibre::appliquer(
            $requete,
            ['factures.montant' => FiltreLibre::colonne('Montant', 'nombre')],
            ['factures.montant' => ['de' => '200000', 'a' => '600000']],
        );

        $this->assertSame(['F-002'], $requete->pluck('n_facture')->all());
    }

    /** Une case vide ne filtre rien : ouvrir une ligne ne doit pas vider le tableau. */
    public function test_une_case_vide_ne_filtre_rien(): void
    {
        $this->creance('F-001', 'A', 'SGBCI');
        $this->creance('F-002', 'B', 'BICICI');

        $requete = Facture::query();

        FiltreLibre::appliquer(
            $requete,
            ['factures.banque' => FiltreLibre::colonne('Banque')],
            ['factures.banque' => ['valeur' => '   ']],
        );

        $this->assertSame(2, $requete->count());
    }

    // ------------------------------------------------------------------ à l'écran

    public function test_l_etat_des_impayes_filtre_sur_une_colonne_sans_filtre(): void
    {
        $this->creance('F-001', 'NSIA ASSURANCES', 'SGBCI');
        $this->creance('F-002', 'ALLIANZ', 'BICICI');

        $ecran = Volt::actingAs($this->compte('gerant'))->test('pilotage.impayes');

        $this->assertSame(2, $ecran->instance()->totaux['lignes']);

        // La banque n'a pas de filtre en haut de l'écran — c'est exactement le cas que le
        // bouton « Autre filtre » couvre.
        // **La clé porte l'alias, et c'est tout le défaut du 28/09** : Livewire lit le
        // point comme un séparateur de chemin, si bien que « factures.banque » écrivait
        // dans une structure à trois étages que le serveur n'allait jamais lire. Aucune
        // erreur, aucun message, et un filtre muet.
        $ecran->set('filtresLibres', ['factures__banque' => ['valeur' => 'SGB']]);

        $this->assertSame(1, $ecran->instance()->totaux['lignes']);
        $ecran->assertSee('F-001')->assertDontSee('F-002');
    }

    /**
     * Le point d'un nom de colonne devient un double blanc souligné dans l'état.
     *
     * **C'est la correction du 28/09** — « le filtre ne marche pas ». Les colonnes sont
     * nommées `table.colonne` pour qu'une jointure ne rende pas la condition ambiguë, et
     * Livewire lit le point comme un chemin. Ce test verrouille la traduction, dans les
     * deux sens : le nom écrit par l'écran et celui reçu du navigateur.
     */
    public function test_le_point_du_nom_de_colonne_ne_casse_pas_le_filtre(): void
    {
        $this->creance('F-001', 'A', 'SGBCI');
        $this->creance('F-002', 'B', 'BICICI');

        $this->assertSame('factures__banque', FiltreLibre::alias('factures.banque'));

        $requete = Facture::query();

        // Ce que le navigateur renvoie : la clé sous sa forme d'alias.
        FiltreLibre::appliquer(
            $requete,
            ['factures.banque' => FiltreLibre::colonne('Banque')],
            ['factures__banque' => ['valeur' => 'SGB']],
        );

        $this->assertSame(['F-001'], $requete->pluck('n_facture')->all());
    }

    /** Le compteur du bouton dit combien de filtres sont réellement posés. */
    public function test_le_bouton_annonce_le_nombre_de_filtres_poses(): void
    {
        $declarees = [
            'factures.banque' => FiltreLibre::colonne('Banque'),
            'factures.date' => FiltreLibre::colonne('Date', 'date'),
        ];

        // Une ligne ouverte mais vide n'est pas un filtre : elle ne retire rien.
        $this->assertSame(0, FiltreLibre::compter($declarees, ['factures__banque' => ['valeur' => '']]));
        $this->assertSame(1, FiltreLibre::compter($declarees, ['factures__banque' => ['valeur' => 'SGB']]));
        $this->assertSame(2, FiltreLibre::compter($declarees, [
            'factures__banque' => ['valeur' => 'SGB'],
            'factures__date' => ['de' => '2026-03-01'],
        ]));
    }

    // ------------------------------------------------------------------ le décor

    private function creance(
        string $numero,
        string $client,
        ?string $banque,
        string $activite = 'Mécanique',
        ?string $date = null,
        int $montant = 500_000,
    ): Facture {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'date' => $date ?? now()->subDays(10)->toDateString(),
            'n_facture' => $numero,
            'client' => $client,
            'banque' => $banque,
            'activite' => $activite,
            'montant' => $montant,
            'exercice_impayes' => (int) substr($date ?? now()->toDateString(), 0, 4),
        ]);
    }

    private function compte(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Jean-Baptiste Kouassi',
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->site->ville_id,
            'site_id' => $this->site->id,
            'est_actif' => true,
        ]);

        $compte->assignRole($role);

        return $compte->fresh();
    }
}

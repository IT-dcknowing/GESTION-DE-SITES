<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Formats\FormatDesEntrees;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Formats\FormatDuParc;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\MouvementVehicule;
use Modules\Noyau\Imports\Services\Executeur;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Les colonnes d'une page listent d'abord celles du fichier d'origine.
 *
 * **La règle, et pourquoi elle ne tenait pas.** Elle était écrite dans le plan depuis le
 * 18/09 et appliquée écran par écran, de mémoire. Le relevé du 24/09 dit ce que vaut la
 * mémoire :
 *
 * - les fichiers d'entrées et de sorties portent onze colonnes ; quatre n'avaient **aucune
 *   colonne en base** et finissaient collées en une phrase dans `observations` — les
 *   travaux à effectuer, le propriétaire, le déposant et la date de livraison prévue. Sur
 *   les 147 mouvements repris, **147 portent une date de livraison prévue**, et aucune ne
 *   se comparait à aujourd'hui : elle était dans une phrase ;
 * - l'écran des mouvements affichait **six** de ces onze colonnes ;
 * - la page de détail d'une pièce fournisseur recopiait la liste à la main et en avait
 *   perdu **sept sur quarante et une**, dont « TVA 2 » — que l'import explique par écrit
 *   conserver exprès.
 *
 * **Ce que ces tests protègent n'est donc pas un affichage, c'est un mécanisme.** Une liste
 * recopiée à la main diverge de sa source : c'est sa nature. Les pages de détail lisent
 * maintenant `colonnes()` du format, et tout ce qu'aucun bloc ne réclame tombe dans un
 * dernier bloc. Le test qui compte est celui qui ajoute une colonne imaginaire et vérifie
 * qu'elle apparaît sans que personne n'ait touché à la vue.
 */
class LesColonnesDuFichierSeRetrouventALEcranTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------- les quatre colonnes qui n'existaient pas

    public function test_les_quatre_colonnes_des_entrees_ont_desormais_la_leur(): void
    {
        $this->importerUneEntree();

        $mouvement = MouvementVehicule::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('REVISION COMPLETE', $mouvement->travaux);
        $this->assertSame('LUSEO CI', $mouvement->proprietaire);
        $this->assertSame('MR BETAKO HERMANN', $mouvement->deposant);
        $this->assertSame('01/09/2026', $mouvement->date_livraison_prevue?->format('d/m/Y'));
    }

    public function test_l_import_ne_compose_plus_de_phrase(): void
    {
        $this->importerUneEntree();

        // « REVISION COMPLETE · Propriétaire : LUSEO CI · Livraison prévue : 01/09/2026 » :
        // une date dans une phrase ne se trie pas, ne se filtre pas, ne se compare pas.
        $this->assertNull(MouvementVehicule::withoutGlobalScopes()->value('observations'));
    }

    public function test_une_ligne_deja_en_base_garde_sa_phrase(): void
    {
        $ancienne = $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 015100');
        $ancienne->update(['observations' => 'REVISION · Propriétaire : LUSEO CI']);

        $this->importerUneEntree();

        // Le dépôt remplit les quatre colonnes et ne touche pas à la phrase : la découper
        // pour en répartir les morceaux supposerait qu'on sait la relire, or le déposant y
        // porte des retours à la ligne et des numéros de téléphone.
        $mouvement = $ancienne->fresh();
        $this->assertSame('REVISION · Propriétaire : LUSEO CI', $mouvement->observations);
        $this->assertSame('LUSEO CI', $mouvement->proprietaire);
    }

    // ------------------------------------------------------- ce que la date permet enfin

    public function test_une_promesse_depassee_sans_sortie_se_compte(): void
    {
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000001', Carbon::now()->subDays(10));

        $this->assertSame(1, MouvementVehicule::promesseDepassee(
            MouvementVehicule::withoutGlobalScopes()->where('entreprise_id', $this->entreprise->id),
        )->count());
    }

    public function test_une_entree_ressortie_n_est_plus_une_promesse_depassee(): void
    {
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000001', Carbon::now()->subDays(10));
        $this->mouvement(MouvementVehicule::SORTIE, 'FR-KZN° 000001', Carbon::now()->subDays(10));

        // C'est l'absence de la seconde ligne qu'on regarde, et non le statut du parc :
        // celui-là vient d'un autre fichier, donc d'un autre dépôt.
        $this->assertSame(0, MouvementVehicule::promesseDepassee(
            MouvementVehicule::withoutGlobalScopes()->where('entreprise_id', $this->entreprise->id),
        )->count());
    }

    public function test_une_promesse_a_venir_n_est_pas_depassee(): void
    {
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000002', Carbon::now()->addDays(5));

        $this->assertSame(0, MouvementVehicule::promesseDepassee(
            MouvementVehicule::withoutGlobalScopes()->where('entreprise_id', $this->entreprise->id),
        )->count());
    }

    public function test_une_sortie_n_est_jamais_une_promesse_depassee(): void
    {
        // Une sortie porte la date, mais la promesse est celle de l'entrée : la compter des
        // deux côtés doublerait le chiffre.
        $this->mouvement(MouvementVehicule::SORTIE, 'FR-KZN° 000003', Carbon::now()->subDays(10));

        $this->assertSame(0, MouvementVehicule::promesseDepassee(
            MouvementVehicule::withoutGlobalScopes()->where('entreprise_id', $this->entreprise->id),
        )->count());
    }

    // -------------------------------------------------------------------- l'écran

    public function test_l_ecran_des_mouvements_montre_les_colonnes_du_fichier(): void
    {
        $this->importerUneEntree();

        Volt::actingAs($this->gerant())->test('pilotage.mouvements-vehicules')
            ->assertSee('Motif de la venue')
            ->assertSee('Travaux à effectuer')
            ->assertSee('Propriétaire / déposant')
            ->assertSee('Date de livr. prévue')
            ->assertSee('REVISION COMPLETE')
            ->assertSee('LUSEO CI')
            ->assertSee('01/09/2026');
    }

    public function test_l_ecran_filtre_sur_la_promesse_depassee(): void
    {
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000001', Carbon::now()->subDays(10), 'AA-111-AA');
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000002', Carbon::now()->addDays(10), 'BB-222-BB');

        Volt::actingAs($this->gerant())->test('pilotage.mouvements-vehicules')
            ->set('promesseDepassee', true)
            ->assertSee('AA-111-AA')
            ->assertDontSee('BB-222-BB');
    }

    public function test_le_compteur_des_promesses_ne_depend_pas_de_la_case_cochee(): void
    {
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000001', Carbon::now()->subDays(10));
        $this->mouvement(MouvementVehicule::ENTREE, 'FR-KZN° 000002', Carbon::now()->addDays(10));

        // Un indicateur qui se filtre lui-même vaut le total dès qu'on coche la case, et
        // n'apprend plus rien.
        Volt::actingAs($this->gerant())->test('pilotage.mouvements-vehicules')
            ->assertSee('Promesse de sortie dépassée')
            ->set('promesseDepassee', true)
            ->assertSee('Promesse de sortie dépassée');

        $this->assertSame(1, MouvementVehicule::promesseDepassee(
            MouvementVehicule::withoutGlobalScopes()->where('entreprise_id', $this->entreprise->id),
        )->count());
    }

    // ------------------------------------------------- le mécanisme, et non l'affichage

    public function test_la_page_d_une_piece_montre_les_quarante_et_une_colonnes_du_fichier(): void
    {
        $piece = $this->piece();

        $rendu = $piece->champsDuFichierParBloc([
            'Identité' => ['fournisseur', 'numero_piece'],
        ]);

        $intitules = [];

        foreach ($rendu as $champs) {
            $intitules = array_merge($intitules, array_keys($champs));
        }

        // Aucune colonne du format ne manque, quels que soient les blocs déclarés : ce que
        // personne ne réclame tombe dans le dernier bloc.
        $this->assertEqualsCanonicalizing(
            array_values(FormatDesFournisseurs::colonnes()),
            $intitules,
        );
    }

    public function test_une_colonne_que_les_blocs_oublient_tombe_dans_le_dernier(): void
    {
        $piece = $this->piece();

        $rendu = $piece->champsDuFichierParBloc(['Identité' => ['fournisseur']]);

        $this->assertArrayHasKey('Autres colonnes du fichier', $rendu);
        $this->assertArrayHasKey('TVA 2', $rendu['Autres colonnes du fichier']);
    }

    public function test_la_tva_2_est_affichee_puisque_l_import_la_conserve_expres(): void
    {
        $piece = $this->piece();
        $piece->update(['tva_2' => 1800]);

        Volt::actingAs($this->gerant())->test('pilotage.fournisseur-piece', ['piece' => $piece->id])
            ->assertSee('TVA 2');
    }

    public function test_le_resultat_indicatif_n_est_pas_un_montant(): void
    {
        $piece = $this->piece();
        $piece->update(['resultat_indicatif' => 'Marge positive']);

        // Le classeur y écrit une appréciation, pas des francs : la page l'affichait
        // jusqu'ici à travers le formateur de montants, donc « 0 F ».
        $this->assertSame('Marge positive', $piece->fresh()->valeurDuFichier('resultat_indicatif'));
    }

    public function test_une_colonne_vide_s_affiche_au_lieu_de_disparaitre(): void
    {
        $piece = $this->piece();

        // « Rien » est une réponse sur une page qui dit ce qu'on sait d'une pièce, et une
        // colonne absente ne se distingue pas d'une colonne oubliée.
        $this->assertSame('—', $piece->valeurDuFichier('numero_feb'));
    }

    public function test_la_fiche_du_parc_lit_elle_aussi_son_format(): void
    {
        $dossier = DossierVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'numero_fiche' => 'FR-DMN° 000001',
            'immatriculation' => 'AB-123-CD',
            'statut' => 'En cours',
            'date_fiche' => '2026-03-04',
        ]);

        $this->assertSame(
            array_values(FormatDuParc::colonnes()),
            array_keys($dossier->champsDuFichier()),
        );
        $this->assertSame('04/03/2026', $dossier->champsDuFichier()['DATE DE LA FICHE']);
    }

    // ------------------------------------------------------------------ le décor

    private function piece(): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'fournisseur' => 'SOCIDA',
            'numero_piece' => '4138005',
            'date_facture' => '2026-03-04',
            'montant' => 150_000,
            'montant_regle' => 0,
            'reste_a_payer' => 150_000,
        ]);
    }

    private function mouvement(string $sens, string $fiche, ?Carbon $livraison = null, string $immat = 'AB-123-CD'): MouvementVehicule
    {
        return MouvementVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'sens' => $sens,
            'date' => now()->toDateString(),
            'numero_fiche' => $fiche,
            'immatriculation' => $immat,
            'marque' => 'TOYOTA',
            'modele' => 'HILUX',
            'client' => 'LOXEA',
            'date_livraison_prevue' => $livraison?->toDateString(),
        ]);
    }

    /** Dépose un fichier d'entrées portant les quatre colonnes qui n'avaient nulle part où aller. */
    private function importerUneEntree(): void
    {
        $chemin = $this->classeurXlsx(['Feuil1' => [
            ['Liste des véhicules entrés — SITE 1'],
            ['DATE', 'N° FICHE', 'N° IMMAT.', 'MARQUE', 'MODELE', 'CLIENTS',
                'NOM ET CONTACT DU PROPRIETAIRE', 'NOM ET CONTACT DEPOSANT',
                'MOTIF DE LA VENUE', 'TRAVAUX A EFFECTUER', 'DATE DE LIVR. PREVUE'],
            ['2026-08-20', 'FR-KZN° 015100', 'AB-123-CD', 'TOYOTA', 'HILUX', 'LOXEA',
                'LUSEO CI', 'MR BETAKO HERMANN', 'Entretien', 'REVISION COMPLETE', '2026-09-01'],
        ]]);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'deposant' => 'K. Désirée',
            'format' => FormatDesEntrees::cle(),
            'nom_fichier' => 'entrees.xlsx',
            'empreinte' => hash('sha256', uniqid('', true)),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        (new Executeur($this->entreprise->id))->traiter($lot, FormatDesEntrees::class);
    }

    private ?User $compte = null;

    private function gerant(): User
    {
        if ($this->compte !== null) {
            return $this->compte;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => 'Gérant',
            'email' => 'gerant@alpha.test',
            'password' => 'motdepasse123',
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole('gerant');

        return $this->compte = $compte->fresh();
    }
}

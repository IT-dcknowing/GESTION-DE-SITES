<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Services\ConditionsFournisseur;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Executeur;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * La feuille « Liste fournisseurs » entre, et l'échéance cesse d'être un tiret.
 *
 * **Le constat qui a lancé ce travail.** Sur les 1 848 pièces reprises en local, aucune ne
 * portait de date d'échéance et aucune ne portait de délai : la colonne « délais de
 * règlement » n'existe que dans le classeur de San-Pédro, et ses lignes ne la remplissent
 * pas. La colonne *Échéance* de l'écran était donc une colonne de tirets. Le classeur
 * savait pourtant répondre — il le disait dans une autre feuille, fournisseur par
 * fournisseur, et personne ne l'ouvrait.
 *
 * **Ce que ce test verrouille, et pourquoi chaque point a coûté quelque chose.**
 *
 * 1. **Un seul dépôt renseigne les deux feuilles.** Demander de redéposer le même fichier
 *    en changeant de format dans une liste déroulante, c'est se garantir qu'il ne le sera
 *    qu'une fois.
 * 2. **Les fiches ne comptent pas dans les lignes lues.** L'invariant du module — lues =
 *    créées + mises à jour + ignorées + rejetées — porte sur les pièces ; y verser 287
 *    fiches ferait mentir le seul chiffre qu'on vérifie.
 * 3. **Une échéance déduite se dit déduite, et ne s'écrit jamais.** Rangée dans
 *    `date_echeance`, elle deviendrait indiscernable de celles que le fichier annonce, et
 *    servirait à relancer un fournisseur sur un délai qu'il n'a jamais écrit.
 * 4. **Une valeur vide n'efface pas une valeur déclarée.** Les deux classeurs se
 *    contredisent huit fois sur 211 noms communs, et sept de ces huit fois c'est l'un des
 *    deux qui ne dit rien. Écraser au dernier déposé perdrait la seule feuille qui savait.
 * 5. **Aucun rapprochement approché.** « CFAO BABI » et « CFAO BABI MOTORS » se
 *    ressemblent ; se ressembler n'autorise pas à attribuer un délai de paiement. Les noms
 *    inconnus sont nommés à l'écran pour que le classeur soit corrigé là où il est tenu.
 *
 * Les intitulés et les valeurs reproduits ici sont ceux des fichiers réels : la colonne des
 * noms sans en-tête, le tiret isolé de la deuxième ligne, le plafond d'encours écrit
 * « 10 00 000 ».
 */
class LeReferentielFournisseurEntreTest extends TestCase
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
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------ la lecture

    public function test_la_feuille_annexe_est_lue_au_meme_depot_que_les_factures(): void
    {
        $resultat = $this->importer();

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
        $this->assertSame(4, FournisseurReferentiel::withoutGlobalScopes()->count());

        // Le dépôt n'est pas deux dépôts : le format lit les deux feuilles du même fichier.
        $this->assertSame(4, $resultat->fiches);
    }

    public function test_la_feuille_est_reconnue_a_son_contenu_et_non_a_son_nom(): void
    {
        // Le classeur est tenu à la main : un onglet se renomme, et on l'a déjà vu faire.
        $this->importer(nomDeLaFeuille: 'Fournisseurs (liste)');

        $this->assertSame(4, FournisseurReferentiel::withoutGlobalScopes()->count());
    }

    public function test_les_fiches_ne_comptent_pas_dans_les_lignes_lues(): void
    {
        $resultat = $this->importer();

        // L'invariant du module porte sur les pièces, et il doit rester vérifiable.
        $this->assertTrue($resultat->coherent());
        $this->assertSame(1, $resultat->lues);
        $this->assertStringContainsString('4 fiches fournisseurs à jour', $resultat->resume());
    }

    public function test_le_nom_se_trouve_a_gauche_du_terme_faute_d_en_tete(): void
    {
        // La case au-dessus de la colonne des noms est vide dans les deux classeurs réels :
        // on ne peut pas la reconnaître comme les autres. Ici une colonne vide est insérée
        // à gauche, et le nom doit rester trouvé — coder « colonne A » aurait échoué.
        $this->importer(decalage: 1);

        $this->assertNotNull($this->fiche('SOCIDA'));
    }

    public function test_le_tiret_du_menu_deroulant_n_est_pas_un_fournisseur(): void
    {
        $this->importer();

        $this->assertNull(FournisseurReferentiel::withoutGlobalScopes()->where('nom', '-')->first());
    }

    public function test_un_classeur_sans_la_feuille_n_est_pas_un_echec(): void
    {
        // Les exports du logiciel comptable n'en ont pas : leur absence n'est pas une
        // anomalie et ne doit produire ni rejet ni message.
        $resultat = $this->importer(avecLaFeuille: false);

        $this->assertSame(1, $resultat->lues);
        $this->assertSame(0, $resultat->fiches);
        $this->assertSame(0, $resultat->rejetees);
        $this->assertSame(0, FournisseurReferentiel::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ ce qui est lu

    public function test_les_quatre_termes_du_fichier_sont_lus(): void
    {
        $this->importer();

        $this->assertSame(0, $this->fiche('SOCIDA')->jours_reglement);
        $this->assertSame(30, $this->fiche('CFAO TOYOTA')->jours_reglement);
        $this->assertFalse($this->fiche('CFAO TOYOTA')->fin_de_mois);
        $this->assertSame(45, $this->fiche('TRACTAFRIC MOTORS CI')->jours_reglement);
        $this->assertSame(30, $this->fiche('BERNABE COTE D’IVOIRE')->jours_reglement);
        $this->assertTrue($this->fiche('BERNABE COTE D’IVOIRE')->fin_de_mois);
    }

    public function test_le_libelle_du_fichier_est_garde_a_la_lettre(): void
    {
        $this->importer();

        // Un fournisseur qui a négocié « 30 jours fin de mois » ne se reconnaîtrait pas
        // dans « 30 jours », et personne ne saurait que l'application a interprété.
        $this->assertSame('30 jours fin de mois', $this->fiche('BERNABE COTE D’IVOIRE')->delai_reglement);
    }

    public function test_un_terme_qu_on_ne_sait_pas_lire_n_invente_pas_de_jours(): void
    {
        $this->importer(termeDeSocida: '30 jours après réception de la facture');

        // Ce terme-là ne se compte pas depuis la date de facture : faire comme si donnerait
        // une date fausse sans le dire. Le libellé reste affiché, l'échéance non.
        $this->assertNull($this->fiche('SOCIDA')->jours_reglement);
        $this->assertSame('30 jours après réception de la facture', $this->fiche('SOCIDA')->delai_reglement);
    }

    public function test_la_tva_connait_trois_etats(): void
    {
        $this->importer();

        $this->assertTrue($this->fiche('CFAO TOYOTA')->assujetti_tva);
        $this->assertFalse($this->fiche('SOCIDA')->assujetti_tva);
        // La colonne est vide pour 33 des 282 noms d'Abidjan : dire « non » à leur place
        // serait une affirmation fiscale que le fichier ne fait pas.
        $this->assertNull($this->fiche('TRACTAFRIC MOTORS CI')->assujetti_tva);
    }

    public function test_le_plafond_d_encours_est_recopie_sans_etre_converti(): void
    {
        $this->importer();

        // Le fichier réel porte « 10 00 000 » : deviner s'il s'agit d'un million ou de dix
        // effacerait la faute au lieu de la montrer à qui peut la corriger.
        $this->assertSame('Limite compte 10 00 000 FCFA', $this->fiche('SOCIDA')->note);
    }

    // ------------------------------------------------------------------ le second dépôt

    public function test_un_second_depot_met_la_fiche_a_jour_sans_en_creer_une_seconde(): void
    {
        $this->importer();
        $this->importer(termeDeSocida: '30 jours');

        $this->assertSame(4, FournisseurReferentiel::withoutGlobalScopes()->count());
        $this->assertSame(30, $this->fiche('SOCIDA')->jours_reglement);
    }

    public function test_une_valeur_vide_n_efface_pas_une_valeur_declaree(): void
    {
        $this->importer();

        // Le second classeur ne dit rien de la TVA de SOCIDA — c'est le cas réel d'EDF et
        // de SNPC. La seule des deux feuilles qui savait ne doit pas être perdue.
        $this->importer(tvaDeSocida: null);

        $this->assertFalse($this->fiche('SOCIDA')->assujetti_tva);
    }

    public function test_le_terme_se_repose_en_entier_quand_il_change(): void
    {
        $this->importer();
        $this->assertTrue($this->fiche('BERNABE COTE D’IVOIRE')->fin_de_mois);

        $this->importer(termeDeBernabe: '30 jours');

        // Sans quoi un fournisseur passé de « 30 jours fin de mois » à « 30 jours »
        // garderait pour toujours une échéance comptée depuis la fin du mois.
        $this->assertFalse($this->fiche('BERNABE COTE D’IVOIRE')->fin_de_mois);
        $this->assertSame(30, $this->fiche('BERNABE COTE D’IVOIRE')->jours_reglement);
    }

    public function test_les_deux_orthographes_d_apostrophe_designent_la_meme_maison(): void
    {
        $this->importer();
        // Le même nom, avec l'apostrophe droite : les deux se croisent dans les fichiers.
        $this->importer(nomDeBernabe: "BERNABE COTE D'IVOIRE");

        $this->assertSame(4, FournisseurReferentiel::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ l'échéance

    public function test_l_echeance_se_compte_depuis_la_date_de_facture(): void
    {
        $this->importer();
        $piece = $this->piece('CFAO TOYOTA', '2026-03-04');

        $echeance = ConditionsFournisseur::echeance($piece, $this->fiche('CFAO TOYOTA'));

        $this->assertSame('terme', $echeance['source']);
        $this->assertSame('03/04/2026', $echeance['date']->format('d/m/Y'));
    }

    public function test_fin_de_mois_part_du_dernier_jour_du_mois(): void
    {
        $this->importer();
        $piece = $this->piece('BERNABE COTE D’IVOIRE', '2026-04-03');

        // Le commerce compte ainsi : la facture court jusqu'au dernier jour de son mois, et
        // le délai part de là. Une facture du 3 avril est due le 30 mai, pas le 3.
        $this->assertSame(
            '30/05/2026',
            ConditionsFournisseur::echeance($piece, $this->fiche('BERNABE COTE D’IVOIRE'))['date']->format('d/m/Y'),
        );
    }

    public function test_l_echeance_du_fichier_l_emporte_sur_celle_du_terme(): void
    {
        $this->importer();
        $piece = $this->piece('CFAO TOYOTA', '2026-03-04');
        $piece->update(['date_echeance' => '2026-05-20']);

        $echeance = ConditionsFournisseur::echeance($piece->fresh(), $this->fiche('CFAO TOYOTA'));

        // Ce que le fournisseur a écrit prime sur ce que nous comptons pour lui.
        $this->assertSame('fichier', $echeance['source']);
        $this->assertSame('20/05/2026', $echeance['date']->format('d/m/Y'));
    }

    public function test_sans_fiche_aucune_echeance_n_est_inventee(): void
    {
        $this->importer();
        $piece = $this->piece('BERNABE CI', '2026-03-04');

        // « BERNABE CI » ressemble à « BERNABE COTE D'IVOIRE » — et se ressembler
        // n'autorise pas à attribuer un délai de paiement.
        $echeance = ConditionsFournisseur::echeance(
            $piece,
            ConditionsFournisseur::fiche(ConditionsFournisseur::pour($this->entreprise->id, [$piece]), $piece),
        );

        $this->assertSame('aucune', $echeance['source']);
        $this->assertNull($echeance['date']);
    }

    public function test_l_echeance_deduite_ne_s_ecrit_jamais_sur_la_piece(): void
    {
        $this->importer();
        $piece = $this->piece('CFAO TOYOTA', '2026-03-04');

        ConditionsFournisseur::echeance($piece, $this->fiche('CFAO TOYOTA'));

        // La colonne du fichier reste la colonne du fichier : un calcul n'y entre pas.
        $this->assertNull($piece->fresh()->date_echeance);
    }

    public function test_une_piece_dont_l_echeance_est_seulement_deduite_n_est_pas_echue(): void
    {
        $this->importer();
        // Facturée il y a un an à trente jours : l'échéance comptée est largement passée.
        $this->piece('CFAO TOYOTA', Carbon::now()->subYear()->format('Y-m-d'));

        // Le filtre « Échues » ne regarde que les échéances déclarées : aucune ligne n'y
        // entre par un calcul. C'est le compteur du tableau qui le dit, et non l'absence
        // du nom — le bandeau « À qui nous devons le plus » le montre de toute façon.
        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->set('etatFiltre', 'echues')
            ->assertSee('Factures reçues (0)');
    }

    // ------------------------------------------------------------------ les écrans

    public function test_l_ecran_du_referentiel_liste_les_fiches(): void
    {
        $this->importer();

        Volt::actingAs($this->compte('gerant'))->test('pilotage.referentiel-fournisseurs')
            ->assertSee('CFAO TOYOTA')
            ->assertSee('30 jours fin de mois')
            ->assertSee('Limite compte 10 00 000 FCFA');
    }

    public function test_l_ecran_du_referentiel_nomme_les_fournisseurs_sans_fiche(): void
    {
        $this->importer();
        $this->piece('BERNABE CI', Carbon::now()->format('Y-m-d'));

        Volt::actingAs($this->compte('gerant'))->test('pilotage.referentiel-fournisseurs')
            ->assertSee('Facturés, mais absents de la liste')
            ->assertSee('BERNABE CI');
    }

    public function test_l_ecran_principal_annonce_une_echeance_attendue(): void
    {
        $this->importer();
        $this->piece('CFAO TOYOTA', Carbon::now()->format('Y-m-d'));

        Volt::actingAs($this->compte('gerant'))->test('pilotage.fournisseurs')
            ->assertSee('attendue');
    }

    // ------------------------------------------------- la correction à la main

    public function test_une_fiche_se_corrige_et_la_trace_reste(): void
    {
        $this->importer();
        $fiche = $this->fiche('CFAO TOYOTA');

        Volt::actingAs($this->compte('gerant'))->test('pilotage.referentiel-fournisseurs')
            ->call('corriger', $fiche->id)
            ->set('delai', '45 jours')
            ->set('tva', 'non')
            ->set('note', 'Limite compte 12 500 000 FCFA')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $fiche = $fiche->fresh();
        $this->assertSame('45 jours', $fiche->delai_reglement);
        // Les jours se relisent du libellé, ils ne se saisissent pas : deux saisies pour
        // une même chose finissent par se contredire.
        $this->assertSame(45, $fiche->jours_reglement);
        $this->assertFalse($fiche->assujetti_tva);

        $trace = Activity::query()
            ->where('subject_type', (new FournisseurReferentiel)->getMorphClass())
            ->where('subject_id', $fiche->id)
            ->latest('id')
            ->firstOrFail();

        // Qui, quoi, quand — et d'où : c'est ce dernier point qui était demandé.
        $this->assertSame('30 jours', $trace->properties['old']['delai_reglement']);
        $this->assertSame('45 jours', $trace->properties['attributes']['delai_reglement']);
        $this->assertArrayHasKey('ip', $trace->properties->toArray());
        $this->assertArrayHasKey('poste', $trace->properties->toArray());
        $this->assertNotNull($trace->created_at);
    }

    public function test_le_nom_d_une_fiche_venue_d_un_classeur_est_verrouille(): void
    {
        $this->importer();
        $fiche = $this->fiche('CFAO TOYOTA');

        Volt::actingAs($this->compte('gerant'))->test('pilotage.referentiel-fournisseurs')
            ->call('corriger', $fiche->id)
            ->set('nom', 'CFAO TOYOTA CI')
            ->call('enregistrer')
            ->assertHasNoErrors();

        // Le nom est la clé qui relie la fiche à ses factures : le changer l'orphelinerait,
        // et le prochain dépôt en recréerait une sous l'ancien nom.
        $this->assertSame('CFAO TOYOTA', $fiche->fresh()->nom);
    }

    public function test_une_fiche_saisie_a_la_main_laisse_corriger_son_nom(): void
    {
        $fiche = FournisseurReferentiel::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'BERNABE CI',
            'nom_normalise' => FournisseurReferentiel::clePour('BERNABE CI'),
        ]);

        Volt::actingAs($this->compte('gerant'))->test('pilotage.referentiel-fournisseurs')
            ->call('corriger', $fiche->id)
            ->set('nom', 'BERNABE COTE D\'IVOIRE')
            ->call('enregistrer')
            ->assertHasNoErrors();

        // Aucun fichier ne viendra la revendiquer : rien ne se désapparie.
        $fiche = $fiche->fresh();
        $this->assertSame('BERNABE COTE D\'IVOIRE', $fiche->nom);
        $this->assertSame(FournisseurReferentiel::clePour('BERNABE COTE D\'IVOIRE'), $fiche->nom_normalise);
    }

    public function test_le_responsable_d_atelier_lit_le_referentiel_sans_le_corriger(): void
    {
        $this->importer();
        $fiche = $this->fiche('CFAO TOYOTA');

        // Une route protégée ne protège que l'entrée : l'action se revérifie.
        Volt::actingAs($this->compte('responsable_site'))->test('pilotage.referentiel-fournisseurs')
            ->call('corriger', $fiche->id)
            ->assertForbidden();
    }

    public function test_un_terme_qu_on_ne_sait_pas_lire_se_conserve_sans_produire_de_date(): void
    {
        $this->importer();
        $fiche = $this->fiche('CFAO TOYOTA');

        Volt::actingAs($this->compte('gerant'))->test('pilotage.referentiel-fournisseurs')
            ->call('corriger', $fiche->id)
            ->set('delai', '30 jours après réception')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $fiche = $fiche->fresh();
        $this->assertSame('30 jours après réception', $fiche->delai_reglement);
        $this->assertNull($fiche->jours_reglement);
    }

    public function test_la_page_du_referentiel_s_ouvre(): void
    {
        $this->actingAs($this->compte('gerant'))
            ->get(route('referentiel-fournisseurs'))->assertOk();
    }

    // ------------------------------------------------------------------ le décor

    private function fiche(string $nom): ?FournisseurReferentiel
    {
        return FournisseurReferentiel::withoutGlobalScopes()
            ->where('nom_normalise', FournisseurReferentiel::clePour($nom))
            ->first();
    }

    private function piece(string $fournisseur, string $dateFacture): FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'fournisseur' => $fournisseur,
            'numero_piece' => 'P-'.substr(md5($fournisseur.$dateFacture), 0, 6),
            'date_facture' => $dateFacture,
            'montant' => 150_000,
            'montant_regle' => 0,
            'reste_a_payer' => 150_000,
        ]);
    }

    /**
     * Dépose un classeur à deux feuilles et rend ce que l'import en a fait.
     *
     * Les paramètres nommés reproduisent les situations mesurées sur les fichiers réels :
     * une feuille renommée, une colonne insérée à gauche des noms, un terme qui change
     * d'un dépôt à l'autre, une TVA que le second classeur ne déclare pas.
     */
    private function importer(
        bool $avecLaFeuille = true,
        string $nomDeLaFeuille = 'Liste fournisseurs',
        int $decalage = 0,
        string $termeDeSocida = 'Comptant',
        string $termeDeBernabe = '30 jours fin de mois',
        string $nomDeBernabe = 'BERNABE COTE D’IVOIRE',
        ?string $tvaDeSocida = 'NON',
    ) {
        $vide = array_fill(0, $decalage, null);

        $feuilles = ['DETAIL' => [
            ['MOIS', 'DATE FACTURE/BC', 'SITE', 'N° PIECE', 'MONTANT', 'FOURNISSEUR', 'MONTANT REGLE', 'RESTE A PAYER'],
            [3, '2026-03-04', 'ABIDJAN', '4138005', 150000, 'SOCIDA', 0, 150000],
        ]];

        if ($avecLaFeuille) {
            $feuilles[$nomDeLaFeuille] = [
                // La colonne des noms n'a pas d'en-tête, et la note n'en a pas non plus.
                array_merge($vide, [null, 'Type de règlement', null, 'TVA']),
                array_merge($vide, ['-']),
                array_merge($vide, ['SOCIDA', $termeDeSocida, 'Limite compte 10 00 000 FCFA', $tvaDeSocida]),
                array_merge($vide, ['CFAO TOYOTA', '30 jours', null, 'OUI']),
                array_merge($vide, ['TRACTAFRIC MOTORS CI', '45 jours', null, null]),
                array_merge($vide, [$nomDeBernabe, $termeDeBernabe, null, 'OUI']),
            ];
        }

        $chemin = $this->classeurXlsx($feuilles);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => null,
            'deposant' => 'K. Désirée',
            'format' => FormatDesFournisseurs::cle(),
            'nom_fichier' => 'suivi.xlsm',
            'empreinte' => hash('sha256', uniqid('', true)),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        return (new Executeur($this->entreprise->id))->traiter($lot, FormatDesFournisseurs::class);
    }

    /** @var array<string, User> */
    private array $comptes = [];

    private function compte(string $role): User
    {
        if (isset($this->comptes[$role])) {
            return $this->comptes[$role];
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id,
            'name' => ucfirst($role),
            'email' => $role.'@alpha.test',
            'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'est_actif' => true,
            'doit_changer_mot_de_passe' => false,
        ]);

        $compte->assignRole($role);

        return $this->comptes[$role] = $compte->fresh();
    }
}

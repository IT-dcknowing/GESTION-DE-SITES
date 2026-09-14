<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\FormatDuParc;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\LigneRejeteeImport;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AffectationDesCodes;
use Modules\Noyau\Imports\Services\Executeur;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * L'import de la situation du parc, de bout en bout.
 *
 * C'est le premier maillon de toute la chaîne : la fiche de réception est la seule pièce
 * que les devis, les factures et l'état des impayés citent tous. Si elle part dans la
 * mauvaise ville, tout ce qui s'y raccroche suit.
 *
 * Les cas couverts ici sont ceux qu'on a rencontrés dans les vrais exports, pas ceux qu'on
 * imagine : la ligne dont les colonnes ont glissé, le code agent d'une autre ville, le
 * fichier redéposé, la feuille de synthèse placée avant les données.
 */
class ImportDuParcTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Site $abidjanUn;

    private Site $abidjanDeux;

    private Ville $bouake;

    private Site $siteBouake;

    /** L'en-tête exact des trois fichiers réels, dans l'ordre exact. */
    private const EN_TETE = [
        'DATE DE LA FICHE', 'DATE FIN PREVUE', 'DATE THEORIQUE ATELIER', 'N° FICHE RECEPTION',
        'IMMAT. VEHICULE', 'MARQUE', 'MODELE', 'CLIENTS / ASSURANCES', 'PROPRIETAIRES',
        'MOTIF DE LA VENUE', 'TRAVAUX A EFFECTUER', 'STATUT', 'INFORMATIONS SUR LA SITUATION',
        'DATE TRANSMISSION DEVIS', 'DATE TRAITEMENT FEB', 'DATE EFFECTIVE TRAVAUX', 'DATE FIN TRAVAUX',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        // La configuration réelle : Abidjan a deux ateliers, Bouaké un seul.
        $this->abidjan = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->abidjanUn = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
        $this->abidjanDeux = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id,
            'code' => 'ABJ-2', 'nom' => 'Abidjan — Site 2', 'est_actif' => true,
        ]);

        $this->bouake = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
        $this->siteBouake = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->bouake->id,
            'code' => 'BOU', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);
    }

    // ---------------------------------------------------------------- lecture du fichier

    public function test_un_fichier_du_parc_devient_des_fiches_de_reception(): void
    {
        $resultat = $this->importer($this->classeurDuParc([
            $this->fiche('FR-KZN° 010669', immat: 'AA598AZ', marque: 'MG', modele: 'RX-8'),
            $this->fiche('FR-ABN° 012094', immat: '3097KE01', marque: 'MAZDA', modele: 'CX-5'),
        ]), $this->abidjan->id);

        $this->assertSame(2, $resultat->lues);
        $this->assertSame(2, $resultat->creees);
        $this->assertSame(0, $resultat->rejetees);
        $this->assertTrue($resultat->coherent());

        $fiche = DossierVehicule::withoutGlobalScopes()->where('numero_fiche', 'FR-KZN° 010669')->first();

        $this->assertSame('AA598AZ', $fiche->immatriculation);
        $this->assertSame('MG', $fiche->marque);
        $this->assertSame('KZ', $fiche->code_agent);
        $this->assertSame('2026-01-02', $fiche->date_fiche->format('Y-m-d'));
    }

    public function test_l_en_tete_est_trouve_meme_quand_le_fichier_commence_par_autre_chose(): void
    {
        // L'état des impayés place son en-tête en ligne 5, après un bloc d'adresse et une
        // ligne de totaux. Aucun numéro de ligne n'est codé en dur nulle part.
        $chemin = $this->classeurXlsx(['A' => [
            ["L'ARTISAN AUTOMOBILE"],
            ['NCC : 2197588Q'],
            [],
            ['TOTAUX', 6282488335.46],
            self::EN_TETE,
            $this->fiche('FR-KZN° 010669'),
        ]]);

        $resultat = $this->importer($chemin, $this->abidjan->id);

        $this->assertSame(5, $resultat->ligneDEnTete);
        $this->assertSame(1, $resultat->creees);
    }

    public function test_la_feuille_des_donnees_est_preferee_a_la_feuille_de_synthese(): void
    {
        // Les vrais fichiers ouvrent presque toujours sur un tableau croisé : l'état des
        // impayés a dix feuilles et les données sont sur la quatrième.
        $chemin = $this->classeurXlsx([
            'Synthèse' => [['Étiquettes de lignes', 'Somme de MONTANT'], ['ALLIANZ', 300000]],
            'Synthèse,' => [['Anciennetéfactures', '(Tous)']],
            'Détail' => [self::EN_TETE, $this->fiche('FR-KZN° 010669')],
        ]);

        $resultat = $this->importer($chemin, $this->abidjan->id);

        $this->assertSame('Détail', $resultat->feuille);
        $this->assertSame(1, $resultat->creees);
    }

    public function test_un_fichier_qui_n_est_pas_du_parc_n_ecrit_rien(): void
    {
        $chemin = $this->classeurXlsx(['A' => [
            ['DATE DE LA PROFORMA', 'N° PROFORMA', 'MONTANT PROFORMA'],
            [46024, 'PR-MT-11434', 44533316.82],
        ]]);

        $resultat = $this->importer($chemin, $this->abidjan->id);

        $this->assertSame(0, $resultat->creees);
        $this->assertSame(0, DossierVehicule::withoutGlobalScopes()->count());
        $this->assertArrayHasKey("Aucune ligne d'en-tête reconnue dans ce fichier.", $resultat->motifs);
    }

    // ------------------------------------------------------------------------- les rejets

    public function test_une_ligne_dont_les_colonnes_ont_glisse_est_rejetee_et_non_devinee(): void
    {
        // Le cas réel : un texte de travaux sur plusieurs lignes déborde sur les colonnes
        // voisines et pousse le statut hors de sa case. Il y a exactement une ligne comme
        // celle-là dans les 3 179 fiches des trois villes.
        $glissee = $this->fiche('FR-ABN° 012094');
        $glissee[11] = 'Essuie-glace à remplacer ;';

        $resultat = $this->importer($this->classeurDuParc([
            $this->fiche('FR-KZN° 010669'),
            $glissee,
        ]), $this->abidjan->id);

        $this->assertSame(1, $resultat->creees);
        $this->assertSame(1, $resultat->rejetees);
        $this->assertTrue($resultat->coherent());

        // Rien n'a été écrit pour la ligne abîmée : on ne range pas un texte de travaux
        // dans la case statut en espérant que personne ne regarde.
        $this->assertNull(DossierVehicule::withoutGlobalScopes()->where('numero_fiche', 'FR-ABN° 012094')->first());

        // Et le rejet conserve les valeurs d'origine, sans quoi il n'aide personne à
        // corriger le fichier.
        $rejet = LigneRejeteeImport::first();
        $this->assertStringContainsString('Essuie-glace', $rejet->motif);
        $this->assertContains('FR-ABN° 012094', $rejet->valeurs);
    }

    public function test_une_ligne_sans_numero_de_fiche_est_rejetee(): void
    {
        $sansNumero = $this->fiche('');

        $resultat = $this->importer($this->classeurDuParc([$sansNumero]), $this->abidjan->id);

        $this->assertSame(1, $resultat->rejetees);
        $this->assertArrayHasKey('La colonne « N° FICHE RECEPTION » est vide.', $resultat->motifs);
    }

    public function test_les_lignes_vides_ne_sont_meme_pas_comptees(): void
    {
        $resultat = $this->importer($this->classeurXlsx(['A' => [
            self::EN_TETE,
            $this->fiche('FR-KZN° 010669'),
            [],
            [],
            $this->fiche('FR-KZN° 010670'),
        ]]), $this->abidjan->id);

        $this->assertSame(2, $resultat->lues);
        $this->assertSame(2, $resultat->creees);
    }

    // ------------------------------------------------------------------ le rattachement

    public function test_le_code_agent_l_emporte_sur_la_ville_du_depot(): void
    {
        // Mesuré dans les vrais fichiers : l'export « Abidjan » contient 5 fiches rédigées
        // par YK, qui travaille à Bouaké. Le nom du fichier ment, le code ne ment pas.
        CodeAgent::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'YK',
            'ville_id' => $this->bouake->id, 'site_id' => $this->siteBouake->id, 'est_actif' => true,
        ]);

        $this->importer($this->classeurDuParc([
            $this->fiche('FR-YKN° 012218'),
        ]), $this->abidjan->id);

        $fiche = DossierVehicule::withoutGlobalScopes()->first();

        $this->assertSame($this->bouake->id, $fiche->ville_id);
        $this->assertSame($this->siteBouake->id, $fiche->site_id);
        $this->assertSame('code', $fiche->source_rattachement);
        $this->assertFalse($fiche->rattachement_presume);
    }

    public function test_sans_code_connu_la_fiche_entre_mais_se_declare_presumee(): void
    {
        $this->importer($this->classeurDuParc([
            $this->fiche('FR-TTN° 010669'),
        ]), $this->abidjan->id);

        $fiche = DossierVehicule::withoutGlobalScopes()->first();

        // Elle entre — perdre une facture réelle parce qu'on ignore qui est « TT » serait
        // le mauvais échange.
        $this->assertSame($this->abidjan->id, $fiche->ville_id);
        $this->assertSame('depot', $fiche->source_rattachement);
        $this->assertTrue($fiche->rattachement_presume);

        // Abidjan a deux ateliers et rien ne dit lequel : le site reste vide plutôt que
        // tiré au sort.
        $this->assertNull($fiche->site_id);

        // Et le code inconnu remonte dans la liste de ce qui reste à nommer.
        $this->assertSame(1, CodeAgent::withoutGlobalScopes()->where('code', 'TT')->value('occurrences'));
    }

    public function test_dans_une_ville_a_un_seul_atelier_le_site_se_deduit_tout_seul(): void
    {
        $this->importer($this->classeurDuParc([
            $this->fiche('FR-YKN° 012218'),
        ]), $this->bouake->id);

        $this->assertSame($this->siteBouake->id, DossierVehicule::withoutGlobalScopes()->first()->site_id);
    }

    public function test_un_import_repete_ne_transforme_pas_ses_propres_suppositions_en_certitudes(): void
    {
        // Le piège le plus sournois du module. Premier dépôt : on ne connaît pas le code,
        // on suppose la ville du déposant. Deuxième dépôt : si l'on se contentait de relire
        // ce qu'on a écrit, la supposition deviendrait un fait établi — sans que personne
        // n'ait rien vérifié entre-temps.
        $chemin = $this->classeurDuParc([$this->fiche('FR-YKN° 012218')]);

        $this->importer($chemin, $this->bouake->id);
        $this->assertTrue(DossierVehicule::withoutGlobalScopes()->first()->rattachement_presume);

        $this->importer($chemin, $this->bouake->id, empreinteDifferente: true);

        $fiche = DossierVehicule::withoutGlobalScopes()->first();
        $this->assertTrue($fiche->rattachement_presume, 'Une présomption relue reste une présomption.');
        $this->assertSame('depot', $fiche->source_rattachement);
    }

    public function test_un_rattachement_etabli_ne_se_laisse_pas_ecraser_par_un_depot_errone(): void
    {
        CodeAgent::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'YK',
            'ville_id' => $this->bouake->id, 'site_id' => $this->siteBouake->id, 'est_actif' => true,
        ]);

        $chemin = $this->classeurDuParc([$this->fiche('FR-YKN° 012218')]);
        $this->importer($chemin, $this->bouake->id);

        // Quelqu'un redépose le même fichier au titre d'Abidjan, par erreur.
        $this->importer($chemin, $this->abidjan->id, empreinteDifferente: true);

        $this->assertSame($this->bouake->id, DossierVehicule::withoutGlobalScopes()->first()->ville_id);
    }

    // ------------------------------------------------------------------- l'idempotence

    public function test_redeposer_le_meme_fichier_ne_change_rien(): void
    {
        $chemin = $this->classeurDuParc([
            $this->fiche('FR-KZN° 010669'),
            $this->fiche('FR-ABN° 012094'),
        ]);

        $premier = $this->importer($chemin, $this->abidjan->id);
        $second = $this->importer($chemin, $this->abidjan->id, empreinteDifferente: true);

        $this->assertSame(2, $premier->creees);
        $this->assertSame(0, $second->creees);
        $this->assertSame(0, $second->majs);
        $this->assertSame(2, $second->ignorees);
        $this->assertSame(2, DossierVehicule::withoutGlobalScopes()->count());
    }

    public function test_une_fiche_modifiee_dans_le_logiciel_est_mise_a_jour_et_non_dupliquee(): void
    {
        $this->importer($this->classeurDuParc([
            $this->fiche('FR-KZN° 010669', statut: 'VEHICULE RECEPTIONNE/ EN ATTENTE DE DEVIS'),
        ]), $this->abidjan->id);

        $resultat = $this->importer($this->classeurDuParc([
            $this->fiche('FR-KZN° 010669', statut: 'TRAVAUX TERMINES / VEHICULE LIVRE'),
        ]), $this->abidjan->id, empreinteDifferente: true);

        $this->assertSame(1, $resultat->majs);
        $this->assertSame(1, DossierVehicule::withoutGlobalScopes()->count());
        $this->assertTrue(DossierVehicule::withoutGlobalScopes()->first()->estTerminee());
    }

    // ------------------------------------------------------------------- la simulation

    public function test_le_mode_controle_n_ecrit_rien_du_tout(): void
    {
        $resultat = $this->importer($this->classeurDuParc([
            $this->fiche('FR-KZN° 010669'),
            $this->fiche('FR-ABN° 012094'),
        ]), $this->abidjan->id, ecrire: false);

        // Le compte rendu est complet : on sait ce que l'import ferait.
        $this->assertSame(2, $resultat->lues);
        $this->assertTrue($resultat->coherent());

        // Et la base n'a pas bougé d'un iota — c'est la transaction qui le garantit, pas
        // la discipline du code.
        $this->assertSame(0, DossierVehicule::withoutGlobalScopes()->count());
        $this->assertSame('controle', LotImport::withoutGlobalScopes()->first()->etat);
    }

    // -------------------------------------------------------- Abidjan, Site 1 et Site 2

    public function test_les_fiches_d_abidjan_se_repartissent_entre_les_deux_ateliers(): void
    {
        // La demande explicite : Abidjan a deux sites, et les données chargées doivent
        // pouvoir se répartir entre eux. Rien dans le fichier ne le permet — seul le code
        // de la personne qui a rédigé la fiche le peut.
        $this->importer($this->classeurDuParc([
            $this->fiche('FR-KZN° 010669'),
            $this->fiche('FR-KZN° 010670'),
            $this->fiche('FR-ABN° 012094'),
        ]), $this->abidjan->id);

        // Après un premier import, aucune fiche n'a d'atelier : c'est l'état honnête.
        $this->assertSame(3, DossierVehicule::withoutGlobalScopes()->whereNull('site_id')->count());

        $affectation = new AffectationDesCodes($this->entreprise->id);

        // Le service propose la ville, mesurée sur les données.
        $observations = $affectation->observations()->keyBy('code');
        $this->assertSame($this->abidjan->id, $observations['KZ']['ville_proposee']);
        $this->assertTrue($observations['KZ']['doit_choisir_un_site']);
        $this->assertSame(2, $observations['KZ']['sites_possibles']->count());

        // L'atelier, lui, ne se devine pas : on le désigne.
        $affectation->retenir('KZ', $this->abidjan->id, $this->abidjanUn->id, 'Atelier 1');
        $affectation->retenir('AB', $this->abidjan->id, $this->abidjanDeux->id, 'Atelier 2');

        $deplacees = $affectation->rejouerLesPresomptions();

        $this->assertSame(3, $deplacees);
        $this->assertSame(2, DossierVehicule::withoutGlobalScopes()->where('site_id', $this->abidjanUn->id)->count());
        $this->assertSame(1, DossierVehicule::withoutGlobalScopes()->where('site_id', $this->abidjanDeux->id)->count());

        // Et ces fiches ne sont plus présumées : c'est le code qui les a placées.
        $this->assertSame(0, DossierVehicule::withoutGlobalScopes()->where('rattachement_presume', true)->count());
    }

    public function test_un_atelier_qui_n_est_pas_dans_la_ville_annoncee_est_refuse(): void
    {
        CodeAgent::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'KZ', 'est_actif' => true,
        ]);

        $this->expectExceptionMessage("Ce site n'est pas dans la ville indiquée.");

        (new AffectationDesCodes($this->entreprise->id))
            ->retenir('KZ', $this->abidjan->id, $this->siteBouake->id);
    }

    public function test_la_reprise_ne_touche_pas_un_rattachement_etabli(): void
    {
        CodeAgent::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'YK',
            'ville_id' => $this->bouake->id, 'site_id' => $this->siteBouake->id, 'est_actif' => true,
        ]);

        $this->importer($this->classeurDuParc([$this->fiche('FR-YKN° 012218')]), $this->bouake->id);

        $affectation = new AffectationDesCodes($this->entreprise->id);
        $affectation->retenir('YK', $this->abidjan->id, $this->abidjanUn->id);

        // La fiche avait été placée par son code, donc établie : la reprise ne la reprend
        // pas. Seules les lignes marquées présumées bougent.
        $this->assertSame(0, $affectation->rejouerLesPresomptions());
        $this->assertSame($this->bouake->id, DossierVehicule::withoutGlobalScopes()->first()->ville_id);
    }

    // ------------------------------------------------------------------------ sécurité

    public function test_les_fiches_d_une_entreprise_restent_invisibles_a_l_autre(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        $villeAutre = Ville::create([
            'entreprise_id' => $autre->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $chemin = $this->classeurDuParc([$this->fiche('FR-KZN° 010669')]);

        $this->importer($chemin, $this->abidjan->id);
        $this->importer($chemin, $villeAutre->id, entrepriseId: $autre->id, empreinteDifferente: true);

        // Le même numéro de fiche existe des deux côtés, et chacun ne voit que le sien.
        $this->assertSame(1, DossierVehicule::withoutGlobalScopes()
            ->where('entreprise_id', $this->entreprise->id)->count());
        $this->assertSame(1, DossierVehicule::withoutGlobalScopes()
            ->where('entreprise_id', $autre->id)->count());

        $this->assertSame(1, CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $autre->id)->where('code', 'KZ')->count());
    }

    public function test_le_chemin_du_fichier_ne_peut_pas_etre_detourne(): void
    {
        $lot = new LotImport(['entreprise_id' => 1, 'empreinte' => '../../../.env']);

        // Le chemin est calculé à partir de l'empreinte, jamais d'une valeur libre. Une
        // empreinte qui n'est pas soixante-quatre caractères hexadécimaux ne mène nulle
        // part : elle s'arrête ici plutôt qu'à la lecture d'un fichier du serveur.
        $this->expectExceptionMessage("L'empreinte de ce lot n'est pas exploitable.");
        $lot->cheminRelatif();
    }

    public function test_un_fichier_absent_fait_echouer_le_lot_sans_message_technique(): void
    {
        $lot = $this->lot('inexistant.xlsx', $this->abidjan->id, str_repeat('a', 64));

        try {
            (new Executeur($this->entreprise->id))->traiter($lot, FormatDuParc::class);
            $this->fail('Un fichier manquant doit interrompre le traitement.');
        } catch (\RuntimeException) {
            // Attendu.
        }

        $lot->refresh();
        $this->assertSame('echec', $lot->etat);
        $this->assertStringNotContainsString('storage', mb_strtolower($lot->message));
    }

    // ------------------------------------------------------------------------- fabrique

    /** Une ligne du parc, dans l'ordre exact des dix-sept colonnes réelles. */
    private function fiche(
        string $numero,
        string $immat = 'AA689JQ01',
        string $marque = 'TOYOTA',
        string $modele = 'BELTA',
        string $statut = 'DEVIS VALIDE / TRAVAUX EN COURS',
    ): array {
        return [
            ['date' => '2026-01-02'], ['date' => '2026-03-11'], null, $numero,
            $immat, $marque, $modele, 'COMAR ASSURANCES', 'SAFCA-ALIOS FINANCE CI',
            'SINISTRE', "A REMPLACER ET PEINDRE :\nPARE-CHOC AVANT", $statut, null,
            null, ['date' => '2026-02-20'], null, null,
        ];
    }

    private function classeurDuParc(array $fiches): string
    {
        return $this->classeurXlsx(['A' => array_merge([self::EN_TETE], $fiches)]);
    }

    private function importer(
        string $chemin,
        ?int $villeId,
        bool $ecrire = true,
        ?int $entrepriseId = null,
        bool $empreinteDifferente = false,
    ) {
        $entrepriseId ??= $this->entreprise->id;

        $empreinte = $empreinteDifferente
            ? hash('sha256', LotImport::empreinteDe($chemin).uniqid())
            : LotImport::empreinteDe($chemin);

        $lot = $this->lot(basename($chemin), $villeId, $empreinte, $entrepriseId);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        return (new Executeur($entrepriseId))->traiter($lot, FormatDuParc::class, $ecrire);
    }

    private function lot(string $nom, ?int $villeId, string $empreinte, ?int $entrepriseId = null): LotImport
    {
        return LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $entrepriseId ?? $this->entreprise->id,
            'ville_id' => $villeId,
            'deposant' => 'K. Désirée',
            'format' => FormatDuParc::cle(),
            'nom_fichier' => $nom,
            'empreinte' => $empreinte,
            'taille' => 1024,
            'etat' => 'depose',
        ]);
    }
}

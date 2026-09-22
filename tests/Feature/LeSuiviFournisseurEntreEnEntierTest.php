<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Executeur;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Le suivi fournisseur tenu à la main, lu en entier.
 *
 * **Ce qui a changé le 22/09.** Les deux classeurs sont enfin sur le poste — jusque-là le
 * dossier ne contenait que des raccourcis. Ouverts, ils démentent ce qu'on avait conclu
 * d'un échantillon : la feuille exploitable n'est pas « contrôle chq » et ses dix-huit
 * colonnes, c'est « DETAIL » et ses trente-neuf. Son en-tête n'est simplement pas en
 * première ligne.
 *
 * **Les trois pièges que ces fichiers tendent, et qu'on vérifie ici.**
 *
 * 1. L'en-tête est en ligne 9 ou 10 selon le classeur, précédé de règles d'usage et d'une
 *    ligne de totaux.
 * 2. Les deux classeurs n'ont pas les mêmes colonnes : onze n'existent qu'à Abidjan,
 *    trois qu'à San Pedro. Un seul format lit l'union des deux.
 * 3. Ils se recouvrent — 2 670 lignes communes sur 10 147 — et la clé de rapprochement
 *    doit les fondre sans pour autant écraser deux lignes réellement distinctes. C'est le
 *    point qui coûtait le plus cher : l'ancienne clé, « fournisseur + n° de pièce »,
 *    perdait 317 lignes.
 *
 * Les intitulés reproduits ici sont ceux des fichiers réels, à la lettre — faute
 * d'orthographe de « FACTURARTION » comprise.
 */
class LeSuiviFournisseurEntreEnEntierTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_l_en_tete_est_trouve_sous_les_regles_d_usage_du_classeur(): void
    {
        $this->importer(array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$this->ligneAbidjan()],
        ));

        // Neuf lignes de préambule au-dessus de l'en-tête : la reconnaissance descend
        // jusqu'à la ligne qui ressemble au format, elle ne suppose pas la première.
        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
    }

    public function test_les_quarante_colonnes_arrivent_en_base(): void
    {
        $this->importer(array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$this->ligneAbidjan()],
        ));

        $ligne = FactureFournisseur::withoutGlobalScopes()->first();

        // Les colonnes qu'on ne lisait pas : elles font la différence entre « une dette »
        // et « une dette qu'on sait imputer, dater et discuter ».
        $this->assertSame('Carrosserie', $ligne->section);
        $this->assertSame('SUZUKI DZIRE', $ligne->vehicule);
        $this->assertSame('30 jours', $ligne->delai_reglement);
        $this->assertSame('2026-03-15', $ligne->date_echeance->toDateString());
        $this->assertSame(11, $ligne->mois);
        $this->assertSame(150_000, $ligne->montant_refacture);
        $this->assertSame(30_000, $ligne->marge);
        $this->assertSame('Vérifier le montant', $ligne->actions_a_mener);
        $this->assertSame('Pièce conforme', $ligne->commentaires);

        // Les deux TVA sont recopiées, aucune n'est choisie ni additionnée : le fichier ne
        // dit pas ce qui les distingue, et trancher à sa place donnerait un chiffre faux.
        $this->assertSame(21_600, $ligne->tva);
        $this->assertSame(0, $ligne->tva_2);
    }

    public function test_une_colonne_absente_du_classeur_reste_vide_et_ne_vaut_pas_zero(): void
    {
        // Le classeur de San Pedro n'a pas de colonne MARGE du tout.
        $this->importer(array_merge(
            [$this->enTeteSanPedro()],
            [$this->ligneSanPedro()],
        ));

        $ligne = FactureFournisseur::withoutGlobalScopes()->first();

        $this->assertNotNull($ligne);
        // Zéro dirait « cette facture n'a dégagé aucune marge ». La vérité est « le fichier
        // ne le dit pas », et les deux ne se lisent pas de la même façon.
        $this->assertNull($ligne->marge);
        // En revanche ce que San Pedro porte en propre est bien lu.
        $this->assertSame(82_097, $ligne->montant_ht);
        $this->assertSame('FEB-0012', $ligne->numero_feb);
        $this->assertSame('OUI', $ligne->arrive_a_echeance);
    }

    public function test_les_deux_classeurs_se_recouvrent_sans_compter_la_dette_deux_fois(): void
    {
        $this->importer(array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$this->ligneAbidjan()],
        ));

        // La même facture, reprise dans le suivi de San Pedro sous un en-tête différent.
        $this->importer(array_merge(
            [$this->enTeteSanPedro()],
            [$this->ligneSanPedroReprenantAbidjan()],
        ));

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
        $this->assertSame(
            240_000,
            (int) FactureFournisseur::withoutGlobalScopes()->sum('reste_a_payer'),
        );
    }

    public function test_deux_lignes_du_meme_numero_de_piece_ne_s_ecrasent_plus(): void
    {
        // La facture et son avoir portent le même numéro de pièce : c'est le cas réel de
        // SOCIDA 4138005, et l'ancienne clé en refusait un des deux.
        $facture = $this->ligneAbidjan();
        $avoir = $this->ligneAbidjan();
        $avoir[7] = 'Avoir';
        $avoir[9] = -100_000;   // MONTANT
        $avoir[17] = 0;         // MONTANT REGLE
        $avoir[18] = -100_000;  // RESTE A PAYER

        $this->importer(array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$facture, $avoir],
        ));

        $this->assertSame(2, FactureFournisseur::withoutGlobalScopes()->count());
        $this->assertSame(
            [-100_000, 260_000],
            FactureFournisseur::withoutGlobalScopes()->orderBy('montant')->pluck('montant')->all(),
        );
    }

    public function test_le_meme_fichier_redepose_ne_cree_rien_de_neuf(): void
    {
        $classeur = array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$this->ligneAbidjan()],
        );

        $this->importer($classeur);
        $this->importer($classeur);

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
    }

    public function test_les_lignes_de_formule_qui_trainent_apres_la_derniere_facture_ne_sont_pas_des_rejets(): void
    {
        // Mille lignes de ce genre traînent dans le classeur d'Abidjan après la dernière
        // facture : seules les cellules calculées y portent quelque chose. Comptées comme
        // rejets, elles noyaient les vrais dans le journal.
        $formuleSurDuVide = array_fill(0, 40, null);
        $formuleSurDuVide[11] = 0;          // TVA
        $formuleSurDuVide[12] = '#REF!';    // TVA 2
        $formuleSurDuVide[18] = 0;          // RESTE A PAYER

        $lot = $this->importer(array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$this->ligneAbidjan(), $formuleSurDuVide, $formuleSurDuVide],
        ));

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
        $this->assertSame(0, $lot->fresh()->lignes_rejetees);
    }

    public function test_une_ligne_sans_fournisseur_est_rejetee_et_le_dit(): void
    {
        $anonyme = $this->ligneAbidjan();
        $anonyme[14] = null; // FOURNISSEUR

        $lot = $this->importer(array_merge(
            $this->preambuleDuClasseur(),
            [$this->enTeteAbidjan()],
            [$anonyme],
        ));

        // Elle porte un montant et une date : elle existe, on ne sait simplement pas à qui
        // l'on doit. La taire la perdrait ; l'inventer serait pire.
        $this->assertSame(0, FactureFournisseur::withoutGlobalScopes()->count());
        $this->assertSame(1, $lot->fresh()->lignes_rejetees);
    }

    /*
    |--------------------------------------------------------------------------
    | Les classeurs, tels qu'ils sont
    |--------------------------------------------------------------------------
    */

    /** Les neuf lignes qui précèdent l'en-tête dans le classeur d'Abidjan. */
    private function preambuleDuClasseur(): array
    {
        return [
            ["Règles d'utilisation"],
            [],
            ['Les colonnes jaunes sont calculées'],
            ['Les colonnes vertes se saisissent'],
            ['Les autres colonnes sont libres'],
            ['Rensigner le numéro de pièce'],
            ['En vert dans le reste'],
            [],
            [34032798, 5183802.4, 5326188, 0, -6118932],
        ];
    }

    private function enTeteAbidjan(): array
    {
        return [
            'MOIS', 'DATE DE REGLEMENT', 'DATE FACTURE/BC', 'DATE RECEPTION FACTURE', 'SITE',
            'SECTION', 'N° BC', 'NATURE PIECE', 'N° PIECE', 'MONTANT', 'TVA', 'TVA 2',
            'MONTANT REFACTURE', 'DIFFERENCE', 'FOURNISSEUR', 'TYPE DE TRANSACTION/ SERVICES',
            'MODE DE REGLEMENT', 'MONTANT REGLE', 'RESTE A PAYER', 'IMPUTATION', 'VEHICULE',
            'IMMAT', 'délais de règlement', 'Date Echéance', 'N° FACTURE (FA)', 'N° FACTURE (FV)',
            'QTE TOTAL', 'QTE REFACT', 'CODE PIECE', 'MONTANT NET ACHAT', 'MONTANT NET VENTE',
            'MARGE', 'TAUX', 'RESULTAT INDICATIF', 'OBSERVATIONS', 'NUMERO FACTURE CLIENT',
            null, 'OBSERVATIONS SUR LA FACTURARTION CLIENT', 'COMMENTAIRES',
            'Actions à mener 06/09/2024',
        ];
    }

    private function ligneAbidjan(): array
    {
        return [
            11, '2026-01-20', '2026-02-15', '2026-02-16', 'ABIDJAN',
            'Carrosserie', 'BC-N° L2A001183', 'Facture', '24347E0100/0001180', 260000, 21600, 0,
            150000, -110000, 'SOCIDA', 'Achat de pièces',
            'CHEQUE N°486448', 20000, 240000, 'Achat de pièces', 'SUZUKI DZIRE',
            '1258KJ01', '30 jours', '2026-03-15', 'FA-0099', 'FV-0042',
            4, 2, 'CP-77', 120000, 150000,
            30000, 0.25, 'Marge positive', 'Pièce reçue complète', 'FC-2026-0011',
            null, 'Refacturé au client', 'Pièce conforme',
            'Vérifier le montant',
        ];
    }

    private function enTeteSanPedro(): array
    {
        return [
            'MOIS', 'DATE DE REGLEMENT', 'DATE FACTURE/BC', 'DATE RECEPTION FACTURE', 'SITE',
            'SECTION', 'N° BC', 'NATURE PIECE', 'N° PIECE', 'MONTANT', 'MONTANT HT', 'TVA',
            'TVA 2', 'MONTANT REFACTURE', 'DIFFERENCE', 'FOURNISSEUR', null, 'MODE DE REGLEMENT',
            'MONTANT REGLE', 'RESTE A PAYER', 'IMPUTATION', 'VEHICULE', 'IMMAT', 'FEB N°',
            'délais de règlement', 'Date Echéance', 'Arrivé à échéance OUI/NON', 'OBSERVATIONS',
            'NUMERO FACTURE CLIENT', null, 'OBSERVATIONS SUR LA FACTURARTION CLIENT',
            'COMMENTAIRES', 'Actions à mener 06/09/2024',
        ];
    }

    private function ligneSanPedro(): array
    {
        return [
            1, '2026-01-03', '2026-01-04', null, 'SAN PEDRO',
            'Carrosserie', '24VGCFC50038', 'Facture', 'SP-000300', 538189, 82096.627118644, 0,
            0, 0, 0, 'RIMCO SETACI', null, 'CHQ 486440',
            538189, 0, 'Achat de pièces', 'GREAT WALL M4', '1258KJ01', 'FEB-0012',
            'Comptant', '2026-02-03', 'OUI', 'Réglé',
            null, null, null,
            null, null,
        ];
    }

    /** La même facture qu'à Abidjan, telle que le classeur de San Pedro la reprend. */
    private function ligneSanPedroReprenantAbidjan(): array
    {
        $ligne = $this->ligneSanPedro();
        $ligne[2] = '2026-02-15';              // DATE FACTURE/BC
        $ligne[4] = 'ABIDJAN';
        $ligne[8] = '24347E0100/0001180';      // N° PIECE
        $ligne[9] = 260000;                    // MONTANT
        $ligne[15] = 'SOCIDA';                 // FOURNISSEUR
        $ligne[18] = 20000;                    // MONTANT REGLE
        $ligne[19] = 240000;                   // RESTE A PAYER

        return $ligne;
    }

    private function importer(array $lignes): LotImport
    {
        $chemin = $this->classeurXlsx(['DETAIL' => $lignes]);

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

        (new Executeur($this->entreprise->id))->traiter($lot, FormatDesFournisseurs::class);

        return $lot;
    }
}

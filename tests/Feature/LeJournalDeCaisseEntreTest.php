<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Formats\FormatDeLaCaisse;
use Modules\Noyau\Imports\Formats\FormatDuJournalDeCaisse;
use Modules\Noyau\Imports\Formats\Resultat;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Lecteurs\LecteurPdf;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Modeles\OuvertureCaisse;
use Modules\Noyau\Imports\Services\Executeur;
use Modules\Noyau\Imports\Services\Rattachement;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\ConstruitDesDocumentsImprimes;
use Tests\TestCase;

/**
 * Le journal de caisse imprimé entre dans l'application.
 *
 * **Ce que ce test protège.** Abidjan tient un classeur ; Bouaké et San-Pédro n'ont que ce
 * journal, en PDF, et leurs 1 104 mouvements n'étaient lus par rien. Lire un état imprimé
 * suppose de reconstituer un tableau à partir de morceaux de texte posés sur une page — il
 * n'y a ni lignes ni colonnes dans un PDF —, et chacune des règles qui permettent de le
 * faire a été tirée des deux fichiers réels. Les voici, une par test :
 *
 * - les deux fabricants n'écrivent pas de la même façon, et les deux doivent se lire ;
 * - l'ancre d'un mouvement est la rangée qui porte sa date et son montant, **pas** la
 *   phrase qui la décrit. Un défaut de lecture a prouvé que c'était le bon choix : le
 *   lecteur perdait les phrases contenant les lettres `ET` — « INTERNET », « REMETTANT » —
 *   et les mouvements concernés sont quand même entrés, avec leur montant juste et le
 *   solde d'accord. Accrochés à leur phrase, ils auraient disparu ;
 * - une annulation reprend le numéro de la pièce qu'elle annule et porte un montant
 *   négatif — deux lignes, un numéro, et il faut garder les deux ;
 * - deux mouvements bien distincts peuvent partager un numéro de pièce (le cas 002410) ;
 * - le solde avant la période est **annoncé** par la source, jamais recalculé ;
 * - redéposer le même document ne doit rien recréer.
 *
 * Et la vérification qui vaut toutes les autres : **la chaîne des soldes**. Le document
 * imprime, après chaque ligne, le solde de la caisse. En partant du solde annoncé et en
 * appliquant nos montants un par un, on doit retrouver chacun d'eux. Une seule colonne mal
 * lue la ferait tomber immédiatement. Mesuré sur les fichiers réels : zéro écart sur 533
 * mouvements à Bouaké, zéro sur 571 à San-Pédro.
 */
class LeJournalDeCaisseEntreTest extends TestCase
{
    use ConstruitDesClasseurs;
    use ConstruitDesDocumentsImprimes;
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
            'entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'BKE-1', 'nom' => 'Bouaké — Atelier', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------ le lecteur

    public function test_un_pdf_est_reconnu_a_sa_signature_et_non_a_son_nom(): void
    {
        $chemin = $this->documentPdf([$this->pageDUnJournal()]);

        $this->assertSame('pdf', Classeur::format($chemin));
        $this->assertInstanceOf(LecteurPdf::class, Classeur::ouvrir($chemin));

        // Un fichier qui n'est ni classeur ni PDF est refusé avant toute lecture.
        $intrus = $this->documentTemporaire("ce n'est pas un PDF");
        $this->assertNull(Classeur::format($intrus));
    }

    public function test_les_colonnes_se_lisent_sur_la_rangee_des_intitules(): void
    {
        $lecteur = Classeur::ouvrir($this->documentPdf([$this->pageDUnJournal()]));
        $rangees = iterator_to_array($lecteur->lignes('p. 1'));

        // La rangée des intitulés est la première qui aligne au moins trois cellules dont
        // aucune ne porte de chiffre : ni le titre isolé, ni la ligne de la période qui
        // porte des dates, ni celle du solde d'ouverture qui porte un montant.
        $entete = null;

        foreach ($rangees as $cellules) {
            if (in_array('DATE', $cellules, true)) {
                $entete = $cellules;

                break;
            }
        }

        $this->assertNotNull($entete, 'La rangée des intitulés doit être rendue.');
        $this->assertSame(['DATE', 'LIBELLE DE LA TRANSACTION', 'ENTREE', 'SORTIE', 'SOLDE'], array_values($entete));
    }

    public function test_un_montant_aligne_a_droite_reste_dans_sa_colonne(): void
    {
        // Les nombres sont alignés à droite : « 1 » commence trente-trois points plus loin
        // que « 270 000 » dans la même colonne ENTREE. Sans le jeu laissé au bord gauche de
        // chaque colonne, ce petit montant basculerait dans la colonne SORTIE — une entrée
        // deviendrait une sortie, en silence, et le solde ne le dirait qu'à la fin.
        $lecteur = Classeur::ouvrir($this->documentPdf([$this->pageDUnJournal()]));
        $colonnes = [];

        foreach ($lecteur->lignes('p. 1') as $cellules) {
            foreach ($cellules as $rang => $valeur) {
                $colonnes[$valeur] = $rang;
            }
        }

        // 2 = ENTREE, 3 = SORTIE, 4 = SOLDE, d'après la rangée des intitulés.
        $this->assertSame(2, $colonnes['270 000'] ?? null);
        $this->assertSame(2, $colonnes['1'] ?? null);
        $this->assertSame(3, $colonnes['1 000'] ?? null);
        $this->assertSame(4, $colonnes['369 000'] ?? null);
    }

    public function test_les_deux_ecritures_de_pdf_donnent_le_meme_journal(): void
    {
        // San-Pédro écrit en clair, Bouaké en police à index et la page renversée. Les deux
        // impriment le même état ; le lecteur doit rendre le même résultat.
        $this->importerLeJournal([$this->pageDUnJournal()], 'litterale');
        $enClair = $this->photographie();

        MouvementCaisse::withoutGlobalScopes()->delete();

        $this->importerLeJournal([$this->pageDUnJournal()], 'index');

        $this->assertSame($enClair, $this->photographie());
    }

    // ------------------------------------------------------------------ le format

    public function test_le_journal_livre_ses_colonnes_a_l_ecran(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        $mouvement = MouvementCaisse::withoutGlobalScopes()
            ->where('numero_piece', '002272')->firstOrFail();

        $this->assertSame(MouvementCaisse::ENTREE, $mouvement->sens);
        $this->assertSame(270000, $mouvement->montant);
        $this->assertSame('MC', $mouvement->type_piece);
        $this->assertSame('APPROV CAISSE', $mouvement->motif);
        $this->assertSame('remettant', $mouvement->role_tiers);
        $this->assertSame('MINLIN YANNICK', $mouvement->beneficiaire);
        $this->assertSame('CAISSE BOUAKE', $mouvement->caisse);
        // 100 000 de solde d'ouverture, plus cette entrée de 270 000.
        $this->assertSame(370000, $mouvement->solde_annonce);
        $this->assertSame(1, $mouvement->page);
        $this->assertSame('Remettant — MINLIN YANNICK', $mouvement->tiers());
    }

    public function test_le_detail_libre_devient_le_libelle_et_le_motif_reste_a_part(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        $mouvement = MouvementCaisse::withoutGlobalScopes()
            ->where('numero_piece', '02276')->firstOrFail();

        // Le motif est codifié — il sert à grouper les dépenses ; le détail est la phrase
        // que l'opérateur a écrite. Les confondre donnerait autant de postes que de lignes.
        $this->assertSame('ACHATS DIVERS', $mouvement->motif);
        $this->assertSame('ACHAT INSECTICIDE', $mouvement->libelle);
        $this->assertSame('beneficiaire', $mouvement->role_tiers);
    }

    public function test_un_mouvement_sans_ligne_de_description_entre_quand_meme(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        // Une phrase peut manquer — rognée à l'impression, avalée par un défaut de lecture
        // comme celui des lettres `ET`. Le mouvement, lui, doit entrer : il a une date, un
        // montant et un solde, et c'est de l'argent. Il lui manquera son numéro, et cela
        // se verra ; c'est très exactement ce qu'on veut qu'il arrive de pire.
        $orphelin = MouvementCaisse::withoutGlobalScopes()
            ->whereNull('numero_piece')->get();

        $this->assertCount(1, $orphelin);
        $this->assertSame(25000, $orphelin->first()->montant);
        $this->assertSame('SANE JOSE', $orphelin->first()->beneficiaire);
        $this->assertSame('ABONNEMENT FIBRE OPTIQUE', $orphelin->first()->libelle);
    }

    public function test_une_annulation_retourne_le_sens_au_lieu_d_ecraser_sa_piece(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        $deuxLignes = MouvementCaisse::withoutGlobalScopes()
            ->where('numero_piece', '002271')->orderBy('id')->get();

        // Le journal annule une pièce en réimprimant la même dans sa colonne d'origine avec
        // un montant négatif. Une entrée de −1 est une sortie de 1 : le montant reste
        // positif comme partout dans cette table, et les deux lignes se distinguent.
        $this->assertCount(2, $deuxLignes);
        $this->assertSame(MouvementCaisse::ENTREE, $deuxLignes[0]->sens);
        $this->assertSame(MouvementCaisse::SORTIE, $deuxLignes[1]->sens);
        $this->assertSame(1, $deuxLignes[0]->montant);
        $this->assertSame(1, $deuxLignes[1]->montant);
        $this->assertStringStartsWith('ANNULATION', (string) $deuxLignes[1]->motif);
    }

    public function test_deux_mouvements_distincts_peuvent_partager_un_numero_de_piece(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        // Mesuré sur le fichier de San-Pédro : la pièce 002410 porte deux sorties de
        // 8 000 F le même jour, à deux bénéficiaires différents, pour deux motifs
        // différents. La chaîne des soldes applique bien les deux. Une clé fondée sur le
        // seul numéro en aurait écrasé une.
        $lignes = MouvementCaisse::withoutGlobalScopes()
            ->where('numero_piece', '002410')->orderBy('id')->get();

        $this->assertCount(2, $lignes);
        $this->assertSame(['KACOU SIMPLICE', 'GUEDJE JAURES'], $lignes->pluck('beneficiaire')->all());
    }

    public function test_le_pied_de_page_n_est_pas_un_mouvement(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        // Le document totalise en bas de page : « Ecart Solde Caisse / Inventaire ». Ces
        // nombres ressemblent à des montants et n'en sont pas.
        $this->assertSame(0, MouvementCaisse::withoutGlobalScopes()
            ->where('libelle', 'like', '%Inventaire%')->count());
        $this->assertSame(7, MouvementCaisse::withoutGlobalScopes()->count());
    }

    public function test_la_chaine_des_soldes_se_tient(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        $ouverture = OuvertureCaisse::withoutGlobalScopes()->firstOrFail();
        $solde = (int) $ouverture->solde_avant;

        foreach (MouvementCaisse::withoutGlobalScopes()->orderBy('id')->get() as $ligne) {
            $solde += $ligne->sens === MouvementCaisse::ENTREE ? $ligne->montant : -$ligne->montant;

            $this->assertSame(
                (int) $ligne->solde_annonce,
                $solde,
                "Le solde recalculé doit retrouver celui qu'imprime le document (pièce {$ligne->numero_piece}).",
            );
        }
    }

    public function test_le_solde_avant_la_periode_est_recopie_et_non_recalcule(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        $ouverture = OuvertureCaisse::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('CAISSE BOUAKE', $ouverture->caisse);
        $this->assertSame(100000, $ouverture->solde_avant);
        $this->assertSame('2026-04-01', $ouverture->debut?->toDateString());
        $this->assertSame('2026-07-31', $ouverture->fin?->toDateString());
        $this->assertSame(OuvertureCaisse::DU_JOURNAL, $ouverture->source);
        $this->assertSame($this->ville->id, $ouverture->ville_id);
    }

    public function test_redeposer_le_meme_journal_ne_recree_rien(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);
        $avant = $this->photographie();

        $resultat = $this->importerLeJournal([$this->pageDUnJournal()]);

        $this->assertSame($avant, $this->photographie());
        $this->assertSame(0, $resultat->creees);
        $this->assertSame(1, OuvertureCaisse::withoutGlobalScopes()->count());
    }

    public function test_un_document_illisible_le_dit_au_lieu_de_ne_rien_faire(): void
    {
        // Un PDF sans rangée d'intitulés n'est pas un journal de caisse. Un import à zéro
        // ligne ne se distinguerait pas d'un fichier vide : il faut le nommer.
        $resultat = $this->importerLeJournal([[
            [40.0, 60.0, 'RAPPORT MENSUEL'],
            [60.0, 60.0, 'Rien à voir avec une caisse.'],
        ]]);

        $this->assertSame(0, $resultat->creees);
        $this->assertSame(1, $resultat->rejetees);
        $this->assertStringContainsString('journal de caisse', implode(' ', array_keys($resultat->motifs)));
    }

    public function test_le_journal_ne_se_confond_pas_avec_le_classeur_tenu_a_la_main(): void
    {
        $lecteur = Classeur::ouvrir($this->documentPdf([$this->pageDUnJournal()]));

        $journal = (new FormatDuJournalDeCaisse($this->entreprise->id, $this->rattachement()))->affinite($lecteur);
        $classeur = (new FormatDeLaCaisse($this->entreprise->id, $this->rattachement()))->affinite($lecteur);

        // Les deux formats décrivent la même caisse par deux bouts, et leurs intitulés se
        // ressemblent. C'est au dépôt de dire lequel convient, et non à la liste déroulante.
        $this->assertTrue($journal['suffisante']);
        $this->assertFalse($classeur['suffisante']);
    }

    // ------------------------------------------------------------------ l'écran

    public function test_l_ecran_de_caisse_montre_les_colonnes_du_journal(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        // La règle posée par le propriétaire : les colonnes d'une page listent d'abord
        // celles du fichier d'origine. Jusqu'ici l'écran montrait la date, le libellé, le
        // bénéficiaire et le montant — le journal porte aussi un numéro de pièce, un motif,
        // le rôle du tiers et le solde après chaque ligne.
        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->set('periode', 'periode')
            ->set('dateDebut', '2026-04-01')
            ->set('dateFin', '2026-07-31')
            ->assertSee('002272')
            ->assertSee('APPROV CAISSE')
            ->assertSee('Remettant — MINLIN YANNICK')
            ->assertSee('N° de pièce')
            ->assertSee('Motif')
            ->assertSee('Solde avant la période');
    }

    public function test_l_ecran_dit_d_ou_vient_le_solde_d_avant(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        // Le solde d'avant vaut ce que la source annonce, plus les mouvements survenus
        // entre cette annonce et le premier jour regardé. Ici la période commence le
        // 28 avril : les quatre mouvements du 27 sont déjà passés, et le solde d'avant doit
        // valoir 369 000 — celui qu'imprime la dernière ligne du 27.
        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->set('periode', 'periode')
            ->set('dateDebut', '2026-04-28')
            ->set('dateFin', '2026-07-31')
            ->assertSee('Annoncé par le fichier au 01/04/2026')
            ->assertSee('369 000');
    }

    public function test_sans_annonce_l_ecran_ne_fait_pas_passer_un_cumul_pour_un_solde(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);
        OuvertureCaisse::withoutGlobalScopes()->delete();

        // La caisse vivait avant le premier fichier déposé. Présenter la somme de ce qu'on
        // connaît comme « le solde » serait faux ; la page le dit.
        Volt::actingAs($this->compte('gerant'))->test('pilotage.caisse')
            ->set('periode', 'periode')
            ->set('dateDebut', '2026-04-01')
            ->set('dateFin', '2026-07-31')
            ->assertSee('Reconstitué à partir des seuls mouvements connus');
    }

    // ------------------------------------------------------------------ le classeur

    public function test_le_classeur_livre_enfin_son_solde_d_ouverture(): void
    {
        // Chaque onglet l'écrit en quatrième ligne, au-dessus des intitulés, et la lecture
        // commençait à la ligne des colonnes : on passait dessus depuis le 8 septembre.
        $chemin = $this->classeurXlsx(['DEC 25' => [
            1 => [null, 'CAISSE  DU MOIS DE DECEMBRE 2025'],
            4 => [null, "SOLDE D'OUVERTURE", null, null, 662700],
            5 => [
                'Date',
                'Libellé de la transaction(objet,N°Fiche de reception,N° facture)',
                'Entrées', 'sorties', 'Solde', 'Immatriculation',
                'Bénéficiaire (fournisseurs)/remettant (client)',
            ],
            6 => [['date' => '2025-12-01'], 'Transport', null, 10000, 652700, '2803KR01', 'Yapi'],
        ]]);

        $this->traiter($chemin, FormatDeLaCaisse::class, 'caisse', 'caisse.xlsx');

        $ouverture = OuvertureCaisse::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(662700, $ouverture->solde_avant);
        $this->assertSame(FormatDeLaCaisse::CAISSE_SANS_NOM, $ouverture->caisse);
        $this->assertSame('2025-12-01', $ouverture->debut?->toDateString());
        $this->assertSame('2025-12-31', $ouverture->fin?->toDateString());
        $this->assertSame(OuvertureCaisse::DU_CLASSEUR, $ouverture->source);

        // Et le mouvement du classeur, lui, n'a ni pièce ni motif : le fichier ne les dit
        // pas, et on ne les invente pas.
        $mouvement = MouvementCaisse::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($mouvement->numero_piece);
        $this->assertNull($mouvement->motif);
        $this->assertNull($mouvement->role_tiers);
        $this->assertSame('Yapi', $mouvement->tiers());
    }

    // ------------------------------------------------------------------ outillage

    /**
     * Une page de journal, reprise du fichier réel de Bouaké.
     *
     * Les hauteurs et les abscisses sont celles du document : la description au-dessus, la
     * date et les montants sur la rangée suivante, le tiers puis le détail en dessous. Les
     * montants sont alignés à droite, ce qui fait varier leur abscisse d'un montant à
     * l'autre — c'est là-dessus qu'un lecteur naïf se trompe de colonne.
     *
     * @return list<array{float, float, string}>
     */
    private function pageDUnJournal(): array
    {
        return [
            [54.4, 41.6, 'L ARTISAN AUTOMOBILE'],
            [57.1, 799.4, 'PERIODE :'],
            [57.1, 884.0, '01/04/2026'],
            [57.1, 966.9, 'Au 31/07/2026'],
            [86.2, 41.6, 'CAISSE :'],
            [87.0, 95.7, 'CAISSE BOUAKE'],
            [86.2, 799.8, 'SOLDE AVANT LA PERIODE :'],
            [86.9, 1075.5, '100 000'],
            [114.7, 69.0, 'DATE'],
            [114.7, 128.5, 'LIBELLE DE LA TRANSACTION'],
            [114.7, 866.9, 'ENTREE'],
            [114.7, 947.8, 'SORTIE'],
            [114.7, 1028.8, 'SOLDE'],

            // 1. Une entrée ordinaire, remettant nommé sous la rangée de la date.
            [135.7, 128.5, 'ENTREE DE CAISSE MC -N° 002272 -MOTIF : APPROV CAISSE'],
            [142.9, 58.7, '27/04/26'],
            [142.9, 883.0, '270 000'],
            [142.9, 1041.9, '370 000'],
            [150.2, 128.5, 'REMETTANT : MINLIN YANNICK'],

            // 2. Une entrée de 1 F, dont le montant commence plus à droite que le
            //    précédent : c'est le piège de l'alignement à droite.
            [172.8, 128.5, 'ENTREE DE CAISSE MC -N° 002271 -MOTIF : SOLDE INITIAL'],
            [187.4, 58.7, '27/04/26'],
            [187.4, 916.2, '1'],
            [187.4, 1041.9, '370 001'],
            [187.4, 128.5, 'REMETTANT :'],
            [201.9, 128.5, 'SOLDE INITIAL'],

            // 3. Son annulation : même numéro, même colonne, montant négatif.
            [224.5, 128.5, 'ANNULATION ENTREE DE CAISSE MC -N° 002271 -MOTIF : SOLDE INITIAL'],
            [239.0, 58.7, '27/04/26'],
            [239.0, 912.5, '-1'],
            [239.0, 1041.9, '370 000'],
            [253.6, 128.5, 'SOLDE INITIAL'],

            // 4. Une sortie de 1 000 F, tiers sur la rangée de la date comme le fait le
            //    second fabricant.
            [276.2, 128.5, 'SORTIE DE CAISSE MC -N° 02276 -MOTIF : ACHATS DIVERS'],
            [290.7, 58.7, '27/04/26'],
            [290.7, 128.5, 'BENEFICIAIRE : BOUTIQUE'],
            [290.7, 974.7, '1 000'],
            [290.7, 1041.9, '369 000'],
            [305.3, 128.5, 'ACHAT INSECTICIDE'],

            // 5. Un mouvement sans phrase de description : le document ne l'imprime pas.
            [327.8, 58.7, '28/04/26'],
            [327.8, 128.5, 'BENEFICIAIRE : SANE JOSE'],
            [327.8, 962.6, '25 000'],
            [327.8, 1041.9, '344 000'],
            [342.4, 128.5, 'ABONNEMENT FIBRE OPTIQUE'],

            // 6 et 7. Deux sorties distinctes sous le même numéro de pièce.
            [364.9, 128.5, 'SORTIE DE CAISSE MC -N° 002410 -MOTIF : ACHATS DIVERS'],
            [379.5, 58.7, '28/04/26'],
            [379.5, 128.5, 'BENEFICIAIRE : KACOU SIMPLICE'],
            [379.5, 968.6, '8 000'],
            [379.5, 1041.9, '336 000'],

            [402.0, 128.5, "SORTIE DE CAISSE MC -N° 002410 -MOTIF : MAIN D'OEUVRE"],
            [416.6, 58.7, '28/04/26'],
            [416.6, 128.5, 'BENEFICIAIRE : GUEDJE JAURES'],
            [416.6, 968.6, '8 000'],
            [416.6, 1041.9, '328 000'],

            // Le pied de page : des nombres qui ne sont pas des mouvements.
            [440.0, 128.5, 'Ecart Solde Caisse / Inventaire :'],
            [440.0, 868.1, '270 001'],
            [440.0, 947.7, '42 001'],
            [440.0, 1041.9, '328 000'],
        ];
    }

    /** @param  list<list<array{float, float, string}>>  $pages */
    /*
    |--------------------------------------------------------------------------
    | Le même journal, exporté en tableur
    |--------------------------------------------------------------------------
    | **La demande du propriétaire, le 24/09** : « préparer cet import aussi en Excel pour
    | que lorsque les imports seront disponibles en Excel on puisse l'importer, donc dans
    | le même type d'import du PDF ». Le logiciel ne sort aujourd'hui cet état qu'en PDF ;
    | le jour où il le sortira en tableur, il ne doit pas falloir créer un type de plus.
    |
    | Ce qui change entre les deux documents tient en une phrase : un état imprimé pose la
    | phrase du mouvement, le remettant et le détail à trois hauteurs différentes ; un
    | tableur les met dans une seule cellule, séparés par des retours à la ligne. Tout le
    | reste — le motif, le tiers, le sens lu à la colonne, la chaîne des soldes — est écrit
    | une seule fois et sert aux deux.
    */

    public function test_le_meme_journal_en_tableur_donne_exactement_les_memes_mouvements(): void
    {
        $this->importerLeJournal([$this->pageDUnJournal()]);

        $parLePdf = $this->mouvementsLisibles();

        $this->assertCount(7, $parLePdf, 'le document imprimé doit avoir produit ses sept mouvements');

        MouvementCaisse::withoutGlobalScopes()->delete();
        OuvertureCaisse::withoutGlobalScopes()->delete();

        $this->importerLeJournalEnTableur();

        $parLeTableur = $this->mouvementsLisibles();

        // Le point qui compte : ce n'est pas « le tableur passe », c'est « il donne la
        // même chose ». Deux lectures qui divergeraient sur le même journal seraient pires
        // qu'une seule qui refuse.
        $this->assertSame($parLePdf, $parLeTableur);
    }

    public function test_le_tableur_retrouve_le_tiers_et_le_detail_d_une_cellule_multiligne(): void
    {
        $this->importerLeJournalEnTableur();

        $mouvement = MouvementCaisse::withoutGlobalScopes()
            ->where('numero_piece', '002272')->firstOrFail();

        // Les trois lignes étaient dans une seule cellule : le remettant et le détail
        // doivent en être ressortis comme ils le sont d'un document imprimé.
        $this->assertSame('Remettant — MINLIN YANNICK', $mouvement->tiers());
        $this->assertSame('APPROV CAISSE', $mouvement->motif);
        $this->assertSame(270000, $mouvement->montant);
        $this->assertSame(MouvementCaisse::ENTREE, $mouvement->sens);
    }

    public function test_un_tableur_est_accepte_sous_le_meme_type_que_le_pdf(): void
    {
        // Le type ne change pas de nom, et son libellé annonce les deux extensions : c'est
        // ce qui évitera de créer un second type le jour de l'export en tableur. Excel est
        // nommé le premier — « l'import se fera en Excel la plupart du temps, donc celui de
        // l'Excel doit être prioritaire », 24/09 — et un tableur se lit par ses cellules là
        // où un imprimé se lit par la position de ses caractères.
        $this->assertStringContainsString('Excel ou PDF', FormatDuJournalDeCaisse::libelle());

        $resultat = $this->importerLeJournalEnTableur();

        $this->assertSame(0, $resultat->rejetees);
        $this->assertSame(7, MouvementCaisse::withoutGlobalScopes()->count());
    }

    /**
     * Le journal tel qu'un tableur l'exporterait : une ligne par mouvement, et les trois
     * lignes de texte réunies dans la cellule du libellé.
     */
    private function importerLeJournalEnTableur(): Resultat
    {
        $lignes = [
            1 => ['JOURNAL DE CAISSE', '', '', 'PERIODE :', '01/04/2026', 'Au', '31/07/2026'],
            2 => ['CAISSE :', 'CAISSE BOUAKE', '', 'SOLDE AVANT LA PERIODE :', '100 000'],
            3 => [],
            4 => ['DATE', 'LIBELLE DE LA TRANSACTION', 'ENTREE', 'SORTIE', 'SOLDE'],
        ];

        $rang = 5;

        foreach ($this->mouvementsDuJournal() as $mouvement) {
            $lignes[$rang++] = [
                $mouvement['date'],
                implode("\n", $mouvement['texte']),
                $mouvement['entree'],
                $mouvement['sortie'],
                $mouvement['solde'],
            ];
        }

        return $this->traiter(
            $this->classeurXlsx(['Journal' => $lignes]),
            FormatDuJournalDeCaisse::class,
            FormatDuJournalDeCaisse::cle(),
            'journal.xlsx',
        );
    }

    /**
     * Les **sept mêmes mouvements** que `pageDUnJournal()`, sous la forme d'un tableur.
     *
     * Un mot sur la correspondance : ce que le document imprimé pose à trois hauteurs, le
     * tableur le met dans une cellule avec des retours à la ligne. Le reste est identique —
     * même date, même colonne pour le montant, même solde. C'est précisément ce que ce jeu
     * d'essai doit garantir : deux écritures du même journal, et pas deux journaux.
     *
     * @return list<array{date: string, texte: list<string>, entree: string, sortie: string, solde: string}>
     */
    private function mouvementsDuJournal(): array
    {
        return [
            // 1. Une entrée ordinaire, remettant nommé.
            [
                'date' => '27/04/26',
                'texte' => [
                    'ENTREE DE CAISSE MC -N° 002272 -MOTIF : APPROV CAISSE',
                    'REMETTANT : MINLIN YANNICK',
                ],
                'entree' => '270 000', 'sortie' => '', 'solde' => '370 000',
            ],
            // 2. Une entrée de 1 F, remettant non nommé, détail sur une ligne à part.
            [
                'date' => '27/04/26',
                'texte' => [
                    'ENTREE DE CAISSE MC -N° 002271 -MOTIF : SOLDE INITIAL',
                    'REMETTANT :',
                    'SOLDE INITIAL',
                ],
                'entree' => '1', 'sortie' => '', 'solde' => '370 001',
            ],
            // 3. Son annulation : même numéro, même colonne, montant négatif.
            [
                'date' => '27/04/26',
                'texte' => [
                    'ANNULATION ENTREE DE CAISSE MC -N° 002271 -MOTIF : SOLDE INITIAL',
                    'SOLDE INITIAL',
                ],
                'entree' => '-1', 'sortie' => '', 'solde' => '370 000',
            ],
            // 4. Une sortie de 1 000 F.
            [
                'date' => '27/04/26',
                'texte' => [
                    'SORTIE DE CAISSE MC -N° 02276 -MOTIF : ACHATS DIVERS',
                    'BENEFICIAIRE : BOUTIQUE',
                    'ACHAT INSECTICIDE',
                ],
                'entree' => '', 'sortie' => '1 000', 'solde' => '369 000',
            ],
            // 5. Un mouvement sans phrase de description : le document ne l'imprime pas.
            [
                'date' => '28/04/26',
                'texte' => [
                    'BENEFICIAIRE : SANE JOSE',
                    'ABONNEMENT FIBRE OPTIQUE',
                ],
                'entree' => '', 'sortie' => '25 000', 'solde' => '344 000',
            ],
            // 6 et 7. Deux sorties distinctes sous le même numéro de pièce.
            [
                'date' => '28/04/26',
                'texte' => [
                    'SORTIE DE CAISSE MC -N° 002410 -MOTIF : ACHATS DIVERS',
                    'BENEFICIAIRE : KACOU SIMPLICE',
                ],
                'entree' => '', 'sortie' => '8 000', 'solde' => '336 000',
            ],
            [
                'date' => '28/04/26',
                'texte' => [
                    "SORTIE DE CAISSE MC -N° 002410 -MOTIF : MAIN D'OEUVRE",
                    'BENEFICIAIRE : GUEDJE JAURES',
                ],
                'entree' => '', 'sortie' => '8 000', 'solde' => '328 000',
            ],
        ];
    }

    /**
     * Les mouvements en base, réduits à ce qui se compare.
     *
     * La date est rendue en chaîne : deux objets Carbon égaux ne sont jamais identiques au
     * sens de `assertSame`, et c'est la valeur qu'on veut comparer, pas l'instance.
     *
     * @return list<array<string, mixed>>
     */
    private function mouvementsLisibles(): array
    {
        return MouvementCaisse::withoutGlobalScopes()
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(fn (MouvementCaisse $m) => [
                'date' => $m->date?->toDateString(),
                'sens' => $m->sens,
                'montant' => (int) $m->montant,
                'piece' => $m->numero_piece,
                'motif' => $m->motif,
                'libelle' => $m->libelle,
                'tiers' => $m->tiers(),
                'solde' => (int) $m->solde_annonce,
            ])
            ->all();
    }

    private function importerLeJournal(array $pages, string $ecriture = 'litterale'): Resultat
    {
        return $this->traiter(
            $this->documentPdf($pages, $ecriture),
            FormatDuJournalDeCaisse::class,
            FormatDuJournalDeCaisse::cle(),
            'journal.pdf',
        );
    }

    private function traiter(string $chemin, string $classe, string $cle, string $nom): Resultat
    {
        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'deposant' => 'K. Désirée',
            'format' => $cle,
            'nom_fichier' => $nom,
            'empreinte' => hash('sha256', uniqid('', true)),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        return (new Executeur($this->entreprise->id))->traiter($lot, $classe);
    }

    private function compte(string $role): User
    {
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

        return $compte->fresh();
    }

    private function rattachement(): Rattachement
    {
        return new Rattachement($this->entreprise->id);
    }

    /** Ce que la base contient, réduit à ce qui doit rester stable d'un dépôt à l'autre. */
    private function photographie(): array
    {
        return MouvementCaisse::withoutGlobalScopes()
            ->orderBy('date')->orderBy('montant')->orderBy('numero_piece')
            ->get(['date', 'sens', 'montant', 'numero_piece', 'motif', 'beneficiaire', 'libelle', 'solde_annonce'])
            ->map(fn ($m) => $m->only([
                'sens', 'montant', 'numero_piece', 'motif', 'beneficiaire', 'libelle', 'solde_annonce',
            ]) + ['date' => $m->date?->toDateString()])
            ->all();
    }
}

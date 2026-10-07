<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\SaisieJournaliere;
use Modules\Noyau\Exploitation\Services\SyntheseParAtelier;
use Tests\TestCase;

/**
 * Le tableau par atelier du gérant coûte cinq requêtes, et dit exactement ce qu'il disait.
 *
 * **Pourquoi ce fichier existe.** Mesuré le 02/10 : 30 requêtes par clic sur l'accueil du
 * gérant, dont six agrégats par atelier. `SyntheseParAtelier` les ramène à cinq en tout.
 * Ce sont des montants : ce test ne vérifie pas que c'est rapide, il vérifie que c'est
 * **identique**, atelier par atelier et colonne par colonne, à la boucle qu'on remplace.
 *
 * Les cas qui font tomber une réécriture de ce genre, et qui ont chacun leurs lignes :
 *
 * - un atelier sans aucune écriture doit rendre des zéros, et non disparaître ;
 * - une ligne sans atelier ne doit entrer chez aucun atelier ;
 * - une ligne hors plage, d'un jour, n'entre nulle part ; les bornes sont inclusives ;
 * - les charges ne prennent que `type_operation = 'Charges'`, les sorties prennent tout ;
 * - les filtres activité et commercial tiennent, et seulement là où ils tenaient ;
 * - un avoir (montant négatif) se soustrait au lieu de s'ajouter.
 */
class LaSyntheseParAtelierCoutCinqRequetesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    /** @var array<string, Site> */
    private array $sites = [];

    private Commercial $koffi;

    private Commercial $awa;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Abidjan', 'code' => 'ABJ', 'est_actif' => true]);
        $bouake = Ville::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Bouaké', 'code' => 'BKE', 'est_actif' => true]);

        foreach (['Abidjan 1' => $abidjan, 'Abidjan 2' => $abidjan, 'Bouaké' => $bouake, 'Vide' => $bouake] as $nom => $ville) {
            $this->sites[$nom] = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id, 'nom' => $nom]);
        }

        $this->koffi = Commercial::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $abidjan->id, 'numero' => 'C-001', 'nom' => 'Koffi Yao']);
        $this->awa = Commercial::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $abidjan->id, 'numero' => 'C-002', 'nom' => 'Awa Koné']);

        $this->ecritures();
    }

    /**
     * Des montants qui ne se confondent pas : si un atelier prenait la somme d'un autre, ou
     * une colonne celle d'une autre, l'écart se verrait au lieu de se compenser.
     */
    private function ecritures(): void
    {
        $a1 = $this->sites['Abidjan 1']->id;
        $a2 = $this->sites['Abidjan 2']->id;
        $bk = $this->sites['Bouaké']->id;

        $this->facture($a1, '2026-03-01', 1_000_000, 'Mécanique', $this->koffi->id);
        $this->facture($a1, '2026-03-20', 230_000, 'Carrosserie', $this->awa->id);
        $this->facture($a1, '2026-03-10', -70_000, 'Mécanique', $this->koffi->id); // un avoir
        $this->facture($a2, '2026-03-05', 4_400, 'Mécanique', null);
        $this->facture($bk, '2026-03-15', 9_000_000, 'Carrosserie', $this->awa->id);
        $this->facture(null, '2026-03-15', 500_000_000, 'Mécanique', $this->koffi->id); // sans atelier
        $this->facture($a1, '2026-02-28', 500_000_000, 'Mécanique', $this->koffi->id); // hors plage
        $this->facture($a1, '2026-03-21', 500_000_000, 'Mécanique', $this->koffi->id); // hors plage

        $this->charge($a1, '2026-03-02', 'Charges', 120_000, 'Mécanique');
        $this->charge($a1, '2026-03-03', 'Transfert', 33_000, 'Mécanique');
        $this->charge($a2, '2026-03-04', 'Charges', 7_700, 'Carrosserie');
        $this->charge($a2, '2026-03-04', 'Décaissement DG', 1_100_000, 'Carrosserie');
        $this->charge($bk, '2026-03-20', 'Charges', 650_000, 'Mécanique');
        $this->charge($bk, '2026-03-21', 'Charges', 800_000_000, 'Mécanique');

        $this->encaissement($a1, '2026-03-01', 900_000, 'Mécanique');
        $this->encaissement($a1, '2026-03-08', 15_000, 'Carrosserie');
        $this->encaissement($a2, '2026-03-12', 2_500, 'Mécanique');
        $this->encaissement($bk, '2026-03-19', 3_300_000, 'Carrosserie');
        $this->encaissement(null, '2026-03-12', 700_000_000, 'Mécanique');
        $this->encaissement($a2, '2026-02-28', 700_000_000, 'Mécanique');

        $this->devis($a1, '2026-03-01', 'En attente', 'Mécanique', $this->koffi->id);
        $this->devis($a1, '2026-03-02', 'En attente', 'Carrosserie', $this->awa->id);
        $this->devis($a1, '2026-03-03', 'Validé', 'Mécanique', $this->koffi->id);
        $this->devis($a2, '2026-03-20', 'En attente', 'Mécanique', null);
        $this->devis($bk, '2026-03-11', 'En attente', 'Carrosserie', $this->awa->id);
        $this->devis($bk, '2026-03-12', 'En attente', 'Carrosserie', $this->awa->id);
        $this->devis(null, '2026-03-12', 'En attente', 'Mécanique', $this->koffi->id);
        $this->devis($bk, '2026-03-22', 'En attente', 'Carrosserie', $this->awa->id);

        $this->saisie($a1, '2026-03-01', 3);
        $this->saisie($a1, '2026-03-02', 4);
        $this->saisie($bk, '2026-03-20', 11);
        $this->saisie($a2, '2026-03-21', 900);
    }

    /** La boucle d'avant, gardée ici telle quelle : c'est la référence du test. */
    private function boucleDAvant(string $activiteFiltre = '', ?array $idsCommercialFiltre = null): array
    {
        [$debut, $fin] = $this->plage();

        $activite = fn ($q) => $q->when($activiteFiltre, fn ($r) => $r->where('activite', $activiteFiltre));

        return $this->sitesRetenus()->map(function ($site) use ($debut, $fin, $activite, $idsCommercialFiltre) {
            $caFacture = (int) $activite(Facture::where('site_id', $site->id))->whereBetween('date', [$debut, $fin])
                ->when($idsCommercialFiltre !== null, fn ($q) => $q->whereIn('commercial_id', $idsCommercialFiltre))->sum('montant');
            $charges = (int) $activite(Charge::where('site_id', $site->id))->where('type_operation', 'Charges')->whereBetween('date', [$debut, $fin])->sum('montant');
            $encaisse = (int) $activite(Encaissement::where('site_id', $site->id))->whereBetween('date', [$debut, $fin])->sum('montant');
            $decaisse = (int) $activite(Charge::where('site_id', $site->id))->whereBetween('date', [$debut, $fin])->sum('montant');
            $devisAttente = $activite(Devis::where('site_id', $site->id))->where('statut', 'En attente')->whereBetween('date_emission', [$debut, $fin])
                ->when($idsCommercialFiltre !== null, fn ($q) => $q->whereIn('commercial_id', $idsCommercialFiltre))->count();
            $sansFacture = (int) SaisieJournaliere::where('site_id', $site->id)->whereBetween('date', [$debut, $fin])->sum('vehicules_sans_facture');

            return [
                'site' => $site,
                'ca' => $caFacture,
                'charges' => $charges,
                'resultat' => $caFacture - $charges,
                'encaisse' => $encaisse,
                'treso' => $encaisse - $decaisse,
                'devisAttente' => $devisAttente,
                'sansFacture' => $sansFacture,
            ];
        })->values()->all();
    }

    private function nouvelle(string $activite = '', ?array $idsCommerciaux = null): array
    {
        return SyntheseParAtelier::calculer($this->sitesRetenus(), ...$this->plage(), activite: $activite, idsCommerciaux: $idsCommerciaux)->all();
    }

    /**
     * Les bornes telles que l'écran les passe : `PeriodeCalculateur::plage()` rend deux
     * dates à minuit. Une chaîne `'2026-03-20'` exclurait le 20, stocké `2026-03-20 00:00:00`.
     */
    private function plage(): array
    {
        return PeriodeCalculateur::plage('periode', '2026-03-01', '2026-03-20', null, null, null);
    }

    private function sitesRetenus()
    {
        return Site::whereIn('id', collect($this->sites)->pluck('id'))->with('ville')->orderBy('nom')->get();
    }

    /** Les lignes sans l'objet Site, pour qu'un écart se lise dans le message d'échec. */
    private function chiffres(array $lignes): array
    {
        return array_map(fn ($l) => ['site' => $l['site']->nom] + array_diff_key($l, ['site' => true]), $lignes);
    }

    public function test_la_synthese_dit_exactement_ce_que_disait_la_boucle(): void
    {
        $attendu = $this->boucleDAvant();
        $obtenu = $this->nouvelle();

        $this->assertSame($this->chiffres($attendu), $this->chiffres($obtenu));

        // La référence elle-même doit dire quelque chose, sinon l'égalité ne prouve rien.
        $parNom = collect($this->chiffres($obtenu))->keyBy('site');
        $this->assertSame(1_160_000, $parNom['Abidjan 1']['ca'], "L'avoir se soustrait, les lignes hors plage n'entrent pas.");
        $this->assertSame(120_000, $parNom['Abidjan 1']['charges'], 'Les charges ne prennent que les « Charges ».');
        $this->assertSame(915_000 - 153_000, $parNom['Abidjan 1']['treso'], 'Les sorties prennent toute la table.');
        $this->assertSame(2, $parNom['Bouaké']['devisAttente'], 'Le devis du 22 est hors plage.');
        $this->assertSame(0, $parNom['Abidjan 2']['sansFacture'], 'La saisie du 21 est hors plage.');
    }

    public function test_un_atelier_sans_ecriture_rend_des_zeros_et_garde_sa_place(): void
    {
        $obtenu = $this->chiffres($this->nouvelle());

        $this->assertSame(['Abidjan 1', 'Abidjan 2', 'Bouaké', 'Vide'], array_column($obtenu, 'site'), "L'ordre des ateliers reçus est gardé.");
        $this->assertSame(
            ['site' => 'Vide', 'ca' => 0, 'charges' => 0, 'resultat' => 0, 'encaisse' => 0, 'treso' => 0, 'devisAttente' => 0, 'sansFacture' => 0],
            $obtenu[3],
        );
    }

    public function test_le_filtre_activite_tient_la_ou_il_tenait(): void
    {
        foreach (['Mécanique', 'Carrosserie'] as $activite) {
            $this->assertSame($this->chiffres($this->boucleDAvant($activite)), $this->chiffres($this->nouvelle($activite)), $activite);
        }
    }

    public function test_le_filtre_commercial_tient_la_ou_il_tenait(): void
    {
        foreach ([[$this->koffi->id], [$this->awa->id], [$this->koffi->id, $this->awa->id], []] as $ids) {
            $this->assertSame($this->chiffres($this->boucleDAvant('', $ids)), $this->chiffres($this->nouvelle('', $ids)), json_encode($ids));
        }

        $this->assertSame(
            $this->chiffres($this->boucleDAvant('Mécanique', [$this->koffi->id])),
            $this->chiffres($this->nouvelle('Mécanique', [$this->koffi->id])),
            'Les deux filtres ensemble.',
        );
    }

    public function test_le_nombre_de_requetes_ne_grandit_plus_avec_les_ateliers(): void
    {
        $sites = $this->sitesRetenus();

        DB::enableQueryLog();
        SyntheseParAtelier::calculer($sites, ...$this->plage());
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, $requetes, 'Cinq agrégats en tout, et non six par atelier.');
    }

    public function test_aucun_atelier_ne_coute_aucune_requete(): void
    {
        DB::enableQueryLog();
        $obtenu = SyntheseParAtelier::calculer(collect(), ...$this->plage());
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $obtenu->all());
        $this->assertSame(0, $requetes);
    }

    /**
     * Le tableau de bord à zéro du 05/10 : une ligne sans atelier tombe dans sa ville.
     *
     * Le propriétaire a importé l'état des impayés et des données de trésorerie, et l'accueil
     * du gérant est resté à 0 F partout : les lignes importées portent leur ville, pas leur
     * atelier, et chaque agrégat les cherchait par `site_id`. Avec les villes du périmètre,
     * elles reçoivent leur ligne, et le total du tableau est celui de tout ce qui est dans
     * le périmètre — ni plus, ni moins.
     */
    public function test_avec_les_villes_les_lignes_sans_atelier_ont_leur_ligne(): void
    {
        $abidjan = $this->sites['Abidjan 1']->ville_id;
        $bouake = $this->sites['Bouaké']->id;

        // Une facture d'Abidjan sans atelier, et son règlement, lui aussi sans atelier.
        $facture = $this->facture(null, '2026-03-10', 333_000, 'Mécanique', null, $abidjan);
        $this->encaissement(null, '2026-03-11', 111_000, 'Mécanique', $facture);
        // Un règlement sans atelier, mais dont la facture en a un : il va à cet atelier.
        $factureBouake = $this->facture($bouake, '2026-03-02', 50_000, 'Carrosserie', null);
        $this->encaissement(null, '2026-03-03', 40_000, 'Carrosserie', $factureBouake);
        // Un devis sans atelier, en attente.
        $this->devis(null, '2026-03-05', 'En attente', 'Mécanique', null);

        $idsVilles = collect($this->sites)->pluck('ville_id')->unique()->values()->all();

        $sites = $this->sitesRetenus();
        $plage = $this->plage();

        DB::enableQueryLog();
        $lignes = SyntheseParAtelier::calculer($sites, ...$plage, idsVilles: $idsVilles);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $parNom = collect($this->chiffres($lignes->all()))->keyBy('site');

        $this->assertSame(333_000, $parNom['Abidjan — atelier non précisé']['ca']);
        $this->assertSame(111_000, $parNom['Abidjan — atelier non précisé']['encaisse']);
        $this->assertSame(3_300_000 + 40_000, $parNom['Bouaké']['encaisse'], 'Le règlement suit l\'atelier de sa facture.');
        $this->assertSame(500_000_000, $parNom['Lieu non précisé']['ca'], 'Ni atelier ni ville : la ligne paraît, et le dit.');
        $this->assertSame(700_000_000, $parNom['Lieu non précisé']['encaisse']);
        $this->assertSame(2, $parNom['Lieu non précisé']['devisAttente']);
        $this->assertArrayNotHasKey('Bouaké — atelier non précisé', $parNom->all(), 'Une ville sans ligne orpheline n\'a pas de ligne vide.');

        // Le total est celui de toutes les factures de la plage, avoir compris, rien de plus.
        $toutes = (int) Facture::whereBetween('date', $this->plage())->sum('montant');
        $this->assertSame($toutes, $lignes->sum('ca'));

        $this->assertSame(5, $requetes, 'Toujours cinq requêtes : les villes se lisent sur les ateliers chargés.');
    }

    public function test_sans_les_villes_rien_ne_change(): void
    {
        $this->facture(null, '2026-03-10', 333_000, 'Mécanique', null, $this->sites['Abidjan 1']->ville_id);

        $this->assertSame($this->chiffres($this->boucleDAvant()), $this->chiffres($this->nouvelle()));
    }

    private function facture(?int $siteId, string $jour, int $montant, string $activite, ?int $commercialId, ?int $villeId = null): Facture
    {
        $n = ++$this->numero;

        return Facture::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $siteId, 'ville_id' => $villeId, 'commercial_id' => $commercialId,
            'numero' => "F-{$n}", 'n_facture' => "FA-{$n}", 'date' => $jour, 'client' => 'Client',
            'activite' => $activite, 'montant' => $montant, 'est_avoir' => $montant < 0,
        ]);
    }

    private function charge(?int $siteId, string $jour, string $type, int $montant, string $activite): void
    {
        Charge::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $siteId, 'date' => $jour,
            'type_operation' => $type, 'activite' => $activite, 'libelle' => 'Sortie', 'moyen' => 'Espèces', 'montant' => $montant,
        ]);
    }

    private function encaissement(?int $siteId, string $jour, int $montant, string $activite, ?Facture $facture = null): void
    {
        Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $siteId, 'facture_id' => $facture?->id, 'date' => $jour,
            'type' => 'Client', 'moyen' => 'Espèces', 'activite' => $activite, 'montant' => $montant,
        ]);
    }

    private function devis(?int $siteId, string $jour, string $statut, string $activite, ?int $commercialId): void
    {
        $n = ++$this->numero;

        Devis::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $siteId, 'commercial_id' => $commercialId,
            'numero' => "D-{$n}", 'date_emission' => $jour, 'client' => 'Client', 'activite' => $activite,
            'statut' => $statut, 'montant_devis' => 100_000,
        ]);
    }

    private function saisie(int $siteId, string $jour, int $vehicules): void
    {
        SaisieJournaliere::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $siteId, 'date' => $jour, 'vehicules_sans_facture' => $vehicules,
        ]);
    }
}

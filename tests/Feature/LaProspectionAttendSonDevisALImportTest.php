<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Modules\Noyau\Imports\Formats\FormatDesDevis;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Executeur;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * La prospection attend son devis, et le récupère toute seule au dépôt suivant.
 *
 * **Demandé le 28/09** : « pour toute prospection en devis, après avoir marqué passage, on
 * devra mettre comme statut "en attente d'import" ; dès que l'import est fait et qu'il y a
 * correspondance, automatiquement cela est pris et rangé comme si on avait fait le
 * parcours. Et aussi dans le tableau des prospections, on doit avoir une colonne qui
 * marque la correspondance avec le numéro auquel il a été lié. »
 *
 * **Pourquoi ce rattachement-ci est automatique alors que les autres ne le sont pas.** Les
 * trois autres pistes — le n° de fiche, la plaque, le nom du client — sont des
 * *rapprochements* : elles concluent à partir d'indices, et un indice se trompe ; un même
 * client revient plusieurs fois par an. Le numéro de devis, lui, n'est pas un indice : le
 * commercial l'a écrit lui-même, en cochant « devis après passage », en tenant le devis.
 * C'est une lecture, et une lecture n'a pas à être confirmée à la main.
 *
 * **Ce que cela change, mesuré au 25/09** : 2 432 devis importés sur 2 432 n'avaient aucun
 * commercial. Le chiffre de celui qui a décroché l'affaire ne les comptait pas, et la
 * prospection restait « sans suite » alors qu'elle en avait eu une.
 */
class LaProspectionAttendSonDevisALImportTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);

        $this->commercial = Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'numero' => 'C-0001', 'nom' => 'Koffi Yao', 'statut' => 'Actif', 'est_spontane' => false,
        ]);
    }

    // ------------------------------------------------------------------ les trois états

    public function test_une_visite_sans_devis_n_attend_rien(): void
    {
        $prospection = $this->prospection(devis: false);

        $this->assertSame('aucun', $prospection->etatDuDevis()['cle']);
    }

    public function test_un_devis_annonce_avec_son_numero_attend_l_import(): void
    {
        $prospection = $this->prospection(devis: true, numero: 'PR-MT-11434');

        $etat = $prospection->etatDuDevis();

        $this->assertSame('attente_import', $etat['cle']);
        $this->assertSame("En attente d'import", $etat['libelle']);
    }

    /**
     * Un devis déclaré sans numéro ne peut pas se rattacher tout seul, et le dit.
     *
     * Le taire ferait attendre un import qui ne reliera jamais rien.
     */
    public function test_un_devis_sans_numero_le_dit(): void
    {
        $prospection = $this->prospection(devis: true, numero: null);

        $this->assertSame('sans_numero', $prospection->etatDuDevis()['cle']);
    }

    // ------------------------------------------------------------------ le rattachement

    public function test_le_devis_importe_rejoint_sa_prospection_sans_qu_on_clique(): void
    {
        $prospection = $this->prospection(devis: true, numero: 'PR-MT-11434');

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $devis = Devis::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($prospection->id, $devis->prospection_id);
        // Le commercial suit l'affaire : un devis importé n'en porte aucun tant que son
        // code n'est pas relié à un compte, la prospection sait qui a décroché.
        $this->assertSame($this->commercial->id, $devis->commercial_id);

        $this->assertSame('rapproche', $prospection->fresh()->etatDuDevis()['cle']);
    }

    /** L'écriture du numéro ne compte pas : « pr mt 11434 » est le même devis. */
    public function test_la_facon_d_ecrire_le_numero_ne_compte_pas(): void
    {
        $prospection = $this->prospection(devis: true, numero: 'pr mt 11434');

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertSame($prospection->id, Devis::withoutGlobalScopes()->firstOrFail()->prospection_id);
    }

    public function test_un_devis_sans_prospection_qui_l_annonce_reste_orphelin(): void
    {
        $this->prospection(devis: true, numero: 'PR-MT-99999');

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertNull(Devis::withoutGlobalScopes()->firstOrFail()->prospection_id);
    }

    /**
     * Une prospection qui a déjà son devis n'en reçoit pas un second.
     *
     * Sans cette garde, un redépôt du même fichier la relierait chaque soir à un devis
     * différent — et le chiffre du commercial changerait sans qu'on touche à rien.
     */
    public function test_un_redepot_ne_relie_pas_une_seconde_fois(): void
    {
        $prospection = $this->prospection(devis: true, numero: 'PR-MT-11434');

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);
        $premier = Devis::withoutGlobalScopes()->firstOrFail()->id;

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertSame(1, Devis::withoutGlobalScopes()->count());
        $this->assertSame($premier, Devis::withoutGlobalScopes()->firstOrFail()->id);
        $this->assertSame($prospection->id, Devis::withoutGlobalScopes()->firstOrFail()->prospection_id);
    }

    /**
     * Le devis déposé avant que la case ne soit cochée se relie au dépôt suivant.
     *
     * C'est le cas ordinaire : le fichier arrive le soir, le commercial coche le
     * lendemain. Sans reprise, il faudrait redéposer pour que la coche serve.
     */
    public function test_un_devis_deja_entre_se_relie_quand_la_case_est_cochee_ensuite(): void
    {
        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertNull(Devis::withoutGlobalScopes()->firstOrFail()->prospection_id);

        $prospection = $this->prospection(devis: true, numero: 'PR-MT-11434');

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertSame($prospection->id, Devis::withoutGlobalScopes()->firstOrFail()->prospection_id);
    }

    // ------------------------------------------------------------------ le décor

    private function prospection(bool $devis, ?string $numero = null): Prospection
    {
        $this->actingAs($this->compte());

        return Prospection::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'commercial_id' => $this->commercial->id,
            'numero' => 'P-'.mb_substr(md5((string) $numero.$devis), 0, 5),
            'date' => now()->subDays(5),
            'client' => 'AAL GROUP',
            'moyen' => 'RDV',
            'activite' => 'Mécanique',
            'passage' => $devis,
            'date_passage' => $devis ? now()->subDays(5) : null,
            'devis_apres_passage' => $devis,
            'date_devis' => $devis ? now()->subDays(5) : null,
            'n_devis' => $numero,
            'statut_validation' => 'Validée',
        ]);
    }

    /** @return array<int, string> */
    private function enTete(): array
    {
        return [
            'DATE DE LA PROFORMA', 'N° PROFORMA', 'FICHE DE RECEPTION', 'IMMATRICULATION VEHICULE',
            'NUMERO CHASSIS', 'MARQUE', 'MODELE', 'CODE CLIENT', 'CLIENTS', 'MONTANT PROFORMA',
        ];
    }

    /** @return array<int, mixed> */
    private function ligne(string $numero): array
    {
        return [
            now()->subDays(2)->toDateString(), $numero, 'FR-LAN° 010141', '1258KJ01',
            'CH-99', 'TOYOTA', 'HILUX', 'CL-12', 'AAL GROUP', 450000,
        ];
    }

    private function importer(array $lignes): LotImport
    {
        $chemin = $this->classeurXlsx(['Feuil1' => $lignes]);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->site->ville_id,
            'deposant' => 'K. Désirée',
            'format' => FormatDesDevis::cle(),
            'nom_fichier' => 'devis.xlsx',
            'empreinte' => hash('sha256', uniqid('', true)),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        (new Executeur($this->entreprise->id))->traiter($lot, FormatDesDevis::class);

        return $lot;
    }

    private function compte(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::firstOrCreate(
            ['email' => 'commercial@alpha.test'],
            [
                'entreprise_id' => $this->entreprise->id,
                'name' => 'Koffi Yao',
                'password' => Hash::make('motdepasse123'),
                'ville_id' => $this->site->ville_id,
                'site_id' => $this->site->id,
                'est_actif' => true,
            ],
        );

        if (! $compte->hasRole('commercial')) {
            $compte->assignRole('commercial');
        }

        return $compte->fresh();
    }
}

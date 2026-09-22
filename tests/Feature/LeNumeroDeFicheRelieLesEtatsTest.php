<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\PisteDeLaFiche;
use Modules\Noyau\Imports\Modeles\DossierVehicule;
use Modules\Noyau\Imports\Modeles\MouvementVehicule;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le n° de fiche de réception relie les états du logiciel d'atelier.
 *
 * **C'est la seule clé commune**, et chaque état était lu de son côté : on pouvait voir
 * qu'un devis existait sans pouvoir dire si le véhicule était ressorti, ni si la facture
 * avait suivi.
 *
 * **Le rapprochement se fait par égalité de la chaîne, et c'est une mesure.** Relevé le
 * 24/09 sur les données réelles : le numéro s'écrit `FR-XX N° nnnnn` dans 3 318 des 3 323
 * fiches du parc, 147 mouvements sur 147, 2 422 devis sur 2 670 et 2 336 factures sur
 * 2 386 — le reste étant le jeu de démonstration local. Et rapprocher par égalité exacte
 * donne **exactement** le même résultat que rapprocher par forme normalisée : 1 169 devis,
 * 1 914 factures, 124 mouvements dans les deux cas. On ne stocke donc aucune colonne
 * normalisée, et ces tests protègent cette économie autant que le rapprochement lui-même.
 *
 * **Ce que la piste ne trouve pas est une information.** 1 191 numéros de devis et 384 de
 * facture désignent une fiche que le parc ne porte pas — la situation du parc est une
 * extraction à une date. Et 628 des 3 318 fiches n'ont encore ni devis, ni facture, ni
 * mouvement. Une page vide se confondrait avec une panne : elle dit donc ce qui manque.
 *
 * **La caisse ne porte pas ce numéro**, contrairement à ce que le plan annonçait. Vérifié
 * sur les 1 155 mouvements de caisse repris : aucun libellé et aucun motif n'en contient.
 * Le rapprochement caisse ↔ fiche n'est donc pas tenté.
 */
class LeNumeroDeFicheRelieLesEtatsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Ville $autreVille;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->autreVille = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'SPD', 'nom' => 'San-Pédro', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'A1', 'nom' => 'Site 1', 'est_actif' => true,
        ]);
    }

    // ------------------------------------------------------------------ la forme

    public function test_la_forme_du_numero_est_reconnue(): void
    {
        $this->assertTrue(PisteDeLaFiche::estUnNumero('FR-ABN° 009500'));
        $this->assertTrue(PisteDeLaFiche::estUnNumero('FR-KZN°015100'));
        // Cinq fiches du parc n'ont pas de code d'atelier : elles existent, et les refuser
        // les rendrait introuvables.
        $this->assertTrue(PisteDeLaFiche::estUnNumero('FR-N° 004754'));
    }

    public function test_ce_qui_n_est_pas_un_numero_de_fiche_est_refuse(): void
    {
        // Le jeu de démonstration local écrit « FR-ABJ-1-422 » : ce n'est pas la forme du
        // logiciel, et le confondre avec elle ferait chercher une fiche qui n'existe pas.
        $this->assertFalse(PisteDeLaFiche::estUnNumero('FR-ABJ-1-422'));
        $this->assertFalse(PisteDeLaFiche::estUnNumero('D-0239'));
        $this->assertFalse(PisteDeLaFiche::estUnNumero(''));
        $this->assertFalse(PisteDeLaFiche::estUnNumero(null));
    }

    public function test_deux_ecritures_du_meme_numero_se_rejoignent(): void
    {
        // La normalisation ne sert pas au rapprochement — l'égalité suffit, c'est mesuré —
        // mais elle doit savoir dire que ces trois-là sont le même numéro, sans quoi
        // personne ne pourrait le vérifier le jour où les écritures divergeront.
        $this->assertSame('AB-9500', PisteDeLaFiche::normaliser('FR-ABN° 009500'));
        $this->assertSame('AB-9500', PisteDeLaFiche::normaliser('fr-abn°9500'));
        $this->assertSame('AB-9500', PisteDeLaFiche::normaliser('  FR-AB N °  09500 '));
        $this->assertSame('AB', PisteDeLaFiche::atelier('FR-ABN° 009500'));
    }

    // ------------------------------------------------------------------ la piste

    public function test_la_piste_reunit_le_devis_la_facture_et_les_mouvements(): void
    {
        $this->dossier('FR-ABN° 009500');
        $this->devis('FR-ABN° 009500', 'DV-001');
        $this->facture('FR-ABN° 009500', 'FA-001');
        $this->mouvement('FR-ABN° 009500', MouvementVehicule::ENTREE);
        $this->mouvement('FR-ABN° 009500', MouvementVehicule::SORTIE);

        $piste = $this->piste('FR-ABN° 009500');

        $this->assertCount(1, $piste['devis']);
        $this->assertCount(1, $piste['factures']);
        $this->assertCount(2, $piste['mouvements']);
    }

    public function test_la_piste_ne_ramasse_pas_le_voisin(): void
    {
        $this->dossier('FR-ABN° 009500');
        $this->devis('FR-ABN° 009501', 'DV-002');

        // Un numéro voisin d'un chiffre n'est pas le même dossier : un rapprochement
        // approximatif attribuerait ici le devis d'une autre affaire.
        $this->assertCount(0, $this->piste('FR-ABN° 009500')['devis']);
    }

    public function test_ce_qui_manque_est_dit_en_toutes_lettres(): void
    {
        $this->dossier('FR-ABN° 009500');
        $this->mouvement('FR-ABN° 009500', MouvementVehicule::ENTREE);

        $manques = PisteDeLaFiche::cequiManque($this->piste('FR-ABN° 009500'));

        // Une page vide se confond avec une panne. 628 des 3 318 fiches reprises n'ont ni
        // devis ni facture ni mouvement : le silence y serait la réponse la plus fréquente.
        $this->assertContains('Aucun devis ne cite cette fiche.', $manques);
        $this->assertContains('Aucune facture ne cite cette fiche.', $manques);
        $this->assertStringContainsString('Aucune sortie', implode(' ', $manques));
        $this->assertStringNotContainsString('Aucune entrée', implode(' ', $manques));
    }

    public function test_la_piste_ne_sort_pas_du_perimetre(): void
    {
        $this->dossier('FR-YBN° 000001', $this->autreVille);
        $this->mouvement('FR-YBN° 000001', MouvementVehicule::ENTREE, $this->autreVille);

        // Le périmètre est relu de l'identité du lecteur, jamais du numéro reçu.
        $piste = PisteDeLaFiche::pour($this->entreprise->id, 'FR-YBN° 000001', [$this->ville->id]);

        $this->assertCount(0, $piste['mouvements']);
    }

    // ------------------------------------------------------------------ les écrans

    public function test_la_fiche_du_parc_montre_ce_qu_elle_a_produit(): void
    {
        $dossier = $this->dossier('FR-ABN° 009500');
        $this->devis('FR-ABN° 009500', 'DV-001');
        $this->facture('FR-ABN° 009500', 'FA-001');

        Volt::actingAs($this->compte('gerant'))->test('pilotage.parc-fiche', ['dossier' => $dossier->id])
            ->assertSee('Ce que cette fiche a produit')
            ->assertSee('DV-001')
            ->assertSee('FA-001')
            // Sans échappement : l'apostrophe est écrite telle quelle dans le gabarit, et
            // le besoin par défaut la chercherait sous sa forme HTML.
            ->assertSee("Aucun mouvement n'est enregistré pour cette fiche.", false);
    }

    public function test_le_parc_interdit_deux_fiches_sous_le_meme_numero(): void
    {
        $this->dossier('FR-ABN° 009500');

        // La page avait prévu d'annoncer « l'affaire a été rouverte » quand deux dossiers
        // portaient le même numéro. La base l'interdit : `dossiers_vehicules` a une clé
        // unique sur (entreprise, n° de fiche). Le message ne pouvait donc jamais
        // s'afficher, et la requête qui le nourrissait cherchait ce qu'on tenait déjà.
        $this->expectException(QueryException::class);

        $this->dossier('FR-ABN° 009500');
    }

    public function test_le_numero_ouvre_la_fiche_depuis_un_autre_ecran(): void
    {
        $dossier = $this->dossier('FR-ABN° 009500');

        $this->actingAs($this->compte('gerant'))
            ->get(route('parc-fiche.numero', ['numero' => 'FR-ABN° 009500']))
            ->assertRedirect(route('parc-fiche', $dossier));
    }

    public function test_un_numero_sans_fiche_au_parc_dit_pourquoi(): void
    {
        // 1 191 numéros de devis et 384 de facture sont dans ce cas : la situation du parc
        // est une extraction à une date, elle ne porte pas tout l'historique.
        $this->actingAs($this->compte('gerant'))
            ->get(route('parc-fiche.numero', ['numero' => 'FR-ABN° 009500']))
            ->assertNotFound();
    }

    public function test_une_fiche_hors_perimetre_repond_comme_une_fiche_absente(): void
    {
        $this->dossier('FR-YBN° 000001', $this->autreVille);

        $this->actingAs($this->compte('responsable_site'))
            ->get(route('parc-fiche.numero', ['numero' => 'FR-YBN° 000001']))
            ->assertNotFound();
    }

    public function test_le_responsable_commercial_lit_le_numero_sans_pouvoir_l_ouvrir(): void
    {
        // Animer une équipe de vente ne donne pas de titre à lire la situation de
        // l'atelier. Le numéro reste affiché ; il n'est simplement pas cliquable — un lien
        // qui répond « interdit » est une façon désagréable de dire non.
        $this->assertFalse(PisteDeLaFiche::peutOuvrir($this->compte('responsable_commercial')));
        $this->assertTrue(PisteDeLaFiche::peutOuvrir($this->compte('gerant')));
    }

    // ------------------------------------------------------------------ le décor

    /** @return array{devis: Collection, factures: Collection, mouvements: Collection} */
    private function piste(string $numero): array
    {
        return PisteDeLaFiche::pour(
            $this->entreprise->id,
            $numero,
            [$this->ville->id, $this->autreVille->id],
        );
    }

    private function dossier(string $numero, ?Ville $ville = null): DossierVehicule
    {
        return DossierVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => ($ville ?? $this->ville)->id,
            'site_id' => $ville === null ? $this->site->id : null,
            'numero_fiche' => $numero,
            'immatriculation' => 'AB-123-CD',
            'statut' => 'En cours',
            'date_fiche' => '2026-03-04',
        ]);
    }

    private function devis(string $numeroFiche, string $numero): Devis
    {
        return Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => $this->site->id,
            'numero' => $numero,
            'n_fiche_reception' => $numeroFiche,
            'date_reception' => '2026-03-04',
            'date_emission' => '2026-03-05',
            'client' => 'LOXEA',
            'activite' => 'Carrosserie',
            'statut' => 'En attente',
            'montant_devis' => 450_000,
        ]);
    }

    private function facture(string $numeroFiche, string $numero): Facture
    {
        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'site_id' => $this->site->id,
            'numero' => $numero,
            'n_facture' => $numero,
            'reference_devis' => $numeroFiche,
            'date' => '2026-03-20',
            'client' => 'LOXEA',
            'activite' => 'Carrosserie',
            'montant' => 450_000,
        ]);
    }

    private function mouvement(string $numeroFiche, string $sens, ?Ville $ville = null): MouvementVehicule
    {
        return MouvementVehicule::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => ($ville ?? $this->ville)->id,
            'site_id' => $ville === null ? $this->site->id : null,
            'sens' => $sens,
            'date' => '2026-03-04',
            'numero_fiche' => $numeroFiche,
            'immatriculation' => 'AB-123-CD',
        ]);
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
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => str_replace('_', '-', $role).'@alpha.test',
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

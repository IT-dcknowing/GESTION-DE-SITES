<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Charge;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le numéro d'une pièce dit ce qu'elle est, quand elle date, et son rang.
 *
 *      P-1409-0574
 *
 * **Ce que l'ancienne forme ne disait pas.** « P-0572 » tout seul n'apprend rien : pour
 * savoir de quand il date, il faut ouvrir la pièce. Or une référence se lit sur un papier,
 * se dicte au téléphone, se cherche dans une pile — et la première question est toujours
 * quand.
 *
 * **Et le piège qu'on a évité.** Un compteur qui repart à 1 chaque jour donne « 1409-001 »,
 * qui reviendra à l'identique le 14 septembre suivant : deux pièces différentes sous un
 * seul numéro, et personne pour s'en apercevoir avant un an. Le compteur continue donc de
 * courir. C'est ce que tient le deuxième test.
 */
class NumerotationDesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);

        $caissier = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Fatou Diabaté',
            'email' => 'caisse@alpha.test', 'password' => Hash::make('password'),
            'ville_id' => $ville->id, 'est_actif' => true,
        ]);

        $caissier->assignRole('caissier');
        $this->actingAs($caissier);
    }

    public function test_le_numero_porte_le_jour_et_le_mois_de_l_operation(): void
    {
        // Une opération du 12, saisie aujourd'hui. C'est le 12 qui doit figurer : la
        // référence date ce qui s'est passé, pas le moment où on l'a tapé.
        $veille = now()->subDays(2);

        $encaissement = $this->encaissement($veille->toDateString());

        $this->assertSame('ENC-'.$veille->format('dm').'-0001', $encaissement->numero);
        $this->assertNotSame(now()->format('dm'), $veille->format('dm'), 'Le test ne prouve rien si les deux dates coïncident.');
    }

    public function test_le_compteur_ne_repart_pas_a_un_chaque_jour(): void
    {
        $lundi = now()->subDays(3);
        $mardi = now()->subDays(2);

        $premier = $this->encaissement($lundi->toDateString());
        $second = $this->encaissement($mardi->toDateString());

        $this->assertSame('ENC-'.$lundi->format('dm').'-0001', $premier->numero);

        /*
         * Le rang continue : 0002, et non 0001 du jour suivant. C'est ce qui garantit
         * qu'aucun numéro ne peut revenir l'année prochaine — et c'est la seule raison
         * pour laquelle la forme « 1409-001 », plus courte, a été écartée.
         */
        $this->assertSame('ENC-'.$mardi->format('dm').'-0002', $second->numero);
    }

    public function test_deux_pieces_du_meme_jour_ne_portent_jamais_le_meme_numero(): void
    {
        $jour = now()->subDay()->toDateString();

        $numeros = collect(range(1, 5))
            ->map(fn () => $this->encaissement($jour)->numero);

        $this->assertCount(5, $numeros->unique());
    }

    public function test_chaque_serie_compte_pour_elle_meme(): void
    {
        $jour = now()->subDay();

        $this->encaissement($jour->toDateString());
        $this->encaissement($jour->toDateString());

        $charge = Charge::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => $jour->toDateString(), 'type_operation' => 'Charges',
            'libelle' => 'Achats pièces', 'moyen' => 'Espèces', 'montant' => 30_000,
        ]);

        // Les décaissements ont leur propre suite : le premier d'entre eux est le premier,
        // même si deux encaissements l'ont précédé dans la journée.
        $this->assertSame('DEC-'.$jour->format('dm').'-0001', $charge->numero);
    }

    public function test_la_fiche_commerciale_garde_sa_forme_courte(): void
    {
        // Une fiche commerciale désigne une personne, pas une opération : le jour où on
        // l'a créée n'apprend rien à personne, et « C-0001 » est un matricule.
        $this->assertSame(
            'C-0001',
            GenerateurNumero::suivant($this->entreprise->id, 'com'),
        );
    }

    public function test_une_date_illisible_ne_fait_pas_echouer_l_enregistrement(): void
    {
        /*
         * Refuser une facture parce qu'une date est mal formée coûterait infiniment plus
         * cher que quatre chiffres approximatifs. On retombe sur aujourd'hui, et la pièce
         * part avec un numéro plutôt qu'avec une erreur.
         */
        $this->assertSame(
            'F-'.now()->format('dm').'-0001',
            GenerateurNumero::suivant($this->entreprise->id, 'fac', 'pas une date'),
        );
    }

    public function test_l_apercu_annonce_le_numero_que_la_piece_portera(): void
    {
        $jour = now()->subDays(4);

        $apercus = GenerateurNumero::apercus($this->entreprise->id, 'nfa', 2, $jour->toDateString());

        $this->assertSame(
            ['NF-'.$jour->format('dm').'-0001', 'NF-'.$jour->format('dm').'-0002'],
            $apercus,
        );

        // Un aperçu ne consomme rien : un numéro réservé puis abandonné creuserait un
        // trou dans la séquence, ce qu'une facturation ne pardonne pas.
        $this->assertSame(
            'NF-'.$jour->format('dm').'-0001',
            GenerateurNumero::suivant($this->entreprise->id, 'nfa', $jour->toDateString()),
        );
    }

    private function encaissement(string $date): Encaissement
    {
        return Encaissement::create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->site->id,
            'date' => $date, 'type' => 'Client', 'moyen' => 'Espèces', 'montant' => 50_000,
        ]);
    }
}

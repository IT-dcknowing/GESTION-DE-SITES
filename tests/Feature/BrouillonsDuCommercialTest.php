<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\CompteurDocument;
use Modules\Noyau\Exploitation\Modeles\Prospection;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ce que le commercial peut faire de ses propres lignes.
 *
 * La frontière est nette : tant qu'une prospection est un brouillon, elle lui appartient
 * — il la corrige, la coche, la supprime. Une fois transmise, elle ne lui appartient
 * plus : elle ne doit pas changer sous les yeux du responsable en train de l'arbitrer.
 */
class BrouillonsDuCommercialTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Commercial $commercial;

    private User $utilisateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->utilisateur = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Koffi Yao',
            'email' => 'koffi@exemple.test', 'password' => Hash::make('password'),
            'ville_id' => $ville->id,
        ]);
        $this->utilisateur->assignRole('commercial');

        $this->commercial = Commercial::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $ville->id,
            'user_id' => $this->utilisateur->id, 'numero' => 'C-0001',
            'nom' => 'Koffi Yao', 'statut' => 'Actif', 'est_spontane' => false,
        ]);

        CompteurDocument::create([
            'entreprise_id' => $this->entreprise->id, 'type' => 'pro', 'dernier_numero' => 0,
        ]);

        $this->actingAs($this->utilisateur);
    }

    public function test_le_bouton_brouillon_met_de_cote_sans_transmettre(): void
    {
        $this->ecran()->set('client', 'SIFCA')->call('ajouterEnBrouillon');

        $prospection = Prospection::firstOrFail();
        $this->assertSame('Brouillon', $prospection->statut_validation);
        $this->assertNull($prospection->transmise_le);
    }

    public function test_le_bouton_ajouter_transmet_directement(): void
    {
        $this->ecran()->set('client', 'SIFCA')->call('ajouterEtTransmettre');

        $prospection = Prospection::firstOrFail();
        $this->assertSame('Transmise', $prospection->statut_validation);
        $this->assertNotNull($prospection->transmise_le, 'Une transmission est datée : le responsable doit voir depuis quand elle attend.');
    }

    public function test_un_brouillon_se_modifie(): void
    {
        $prospection = $this->brouillon(['client' => 'Faute de frappe']);

        $this->ecran()
            ->call('modifier', $prospection->id)
            ->set('eClient', 'SIFCA')
            ->set('eLocalisation', 'Zone 4')
            ->call('enregistrerEdition');

        $prospection->refresh();
        $this->assertSame('SIFCA', $prospection->client);
        $this->assertSame('Zone 4', $prospection->localisation);
    }

    public function test_une_prospection_transmise_ne_se_modifie_plus(): void
    {
        $prospection = $this->brouillon(['statut_validation' => 'Transmise', 'client' => 'SIFCA']);

        // Elle appartient désormais au responsable : la modifier reviendrait à la
        // changer sous ses yeux pendant qu'il l'arbitre.
        $this->expectException(ModelNotFoundException::class);

        $this->ecran()->call('modifier', $prospection->id);
    }

    /**
     * Cocher « devis » demande son numéro — au brouillon comme ailleurs.
     *
     * **Relevé le 25/09** : « lorsque la prospection est envoyée sans devis et qu'après on
     * veut ajouter le devis, le numéro de devis n'est plus demandé ». Le formulaire
     * l'exigeait, la case ne l'exigeait pas — et c'est par la case que passent presque
     * tous les devis, puisqu'un devis arrive après la visite.
     *
     * Sans ce numéro, la prospection ne peut plus être reliée au devis importé : il ne
     * reste que le rapprochement par la plaque et le nom, qui se trompe dès que le même
     * client revient dans le mois.
     */
    public function test_cocher_le_devis_demande_son_numero(): void
    {
        $prospection = $this->brouillon(['passage' => false, 'devis_apres_passage' => false]);

        $ecran = $this->ecran()->call('basculerDevisApres', $prospection->id);

        // Rien n'est écrit tant que le numéro n'est pas donné : la case pose une question.
        $prospection->refresh();
        $this->assertFalse($prospection->devis_apres_passage);
        $ecran->assertSet('devisEnAttenteId', $prospection->id);

        // Et le numéro est exigé, comme au formulaire.
        $ecran->set('nDevisEnAttente', '')->call('confirmerLeDevis')->assertHasErrors('nDevisEnAttente');
        $this->assertFalse($prospection->fresh()->devis_apres_passage);

        $ecran->set('nDevisEnAttente', 'PR-MT-11434')->call('confirmerLeDevis')->assertHasNoErrors();

        $prospection->refresh();
        $this->assertTrue($prospection->devis_apres_passage);
        $this->assertTrue($prospection->passage, 'Un devis suppose un passage.');
        $this->assertSame('PR-MT-11434', $prospection->n_devis);
    }

    public function test_decocher_le_passage_retire_le_devis_et_son_numero(): void
    {
        $prospection = $this->brouillon(['passage' => false, 'devis_apres_passage' => false]);

        $this->ecran()
            ->call('basculerDevisApres', $prospection->id)
            ->set('nDevisEnAttente', 'PR-MT-11434')
            ->call('confirmerLeDevis');

        $this->ecran()->call('basculerPassage', $prospection->id);

        $prospection->refresh();
        $this->assertFalse($prospection->passage);
        $this->assertFalse($prospection->devis_apres_passage, 'Sans passage, plus de devis annoncé.');
        $this->assertNull($prospection->n_devis, 'Un numéro de devis sans passage ne désigne plus rien.');
    }

    /**
     * La case « passage » est ouverte sur une ligne transmise.
     *
     * **Relevé le 25/09 : « c'est impossible de cliquer sur passage ».** C'était exact, et
     * incohérent : le passage est un constat, qui se constate souvent après coup, et la
     * case était déjà ouverte par la bande — cocher le devis mettait le passage à vrai.
     */
    public function test_le_passage_se_coche_sur_une_ligne_transmise(): void
    {
        $prospection = $this->brouillon(['passage' => false, 'devis_apres_passage' => false]);
        $prospection->forceFill(['statut_validation' => 'Transmise', 'transmise_le' => now()])->save();

        $this->ecran()->call('basculerPassage', $prospection->id);

        $this->assertTrue($prospection->fresh()->passage);
    }

    public function test_le_bouton_transmettre_envoie_une_seule_ligne(): void
    {
        $partante = $this->brouillon(['numero' => 'P-0001']);
        $gardee = $this->brouillon(['numero' => 'P-0002']);

        $this->ecran()->call('transmettre', $partante->id);

        $this->assertSame('Transmise', $partante->fresh()->statut_validation);
        $this->assertNotNull($partante->fresh()->transmise_le);
        $this->assertSame('Brouillon', $gardee->fresh()->statut_validation, 'Les autres brouillons ne bougent pas.');
    }

    public function test_transmettre_une_ligne_deja_partie_ne_fait_rien(): void
    {
        $prospection = $this->brouillon(['statut_validation' => 'Transmise', 'transmise_le' => now()->subDay()]);
        $envoiInitial = $prospection->transmise_le;

        // Un appel forgé ne doit ni re-dater la transmission, ni renotifier le responsable.
        $this->ecran()->call('transmettre', $prospection->id);

        $this->assertEquals($envoiInitial, $prospection->fresh()->transmise_le);
    }

    public function test_la_date_de_passage_reste_lisible_sur_un_brouillon(): void
    {
        // Une case cochée sans sa date laisse croire que la date n'a pas été retenue.
        $this->brouillon([
            'passage' => true, 'date_passage' => '2026-08-12',
            'devis_apres_passage' => true, 'date_devis' => '2026-08-12',
        ]);

        $this->ecran()->assertSee('12/08/2026');
    }

    private function ecran()
    {
        return Volt::test('commercial.mes-prospections');
    }

    private function brouillon(array $attributs = []): Prospection
    {
        return Prospection::create([
            'entreprise_id' => $this->entreprise->id,
            'site_id' => Site::firstOrFail()->id,
            'commercial_id' => $this->commercial->id,
            'numero' => 'P-0001',
            'date' => now()->toDateString(),
            'client' => 'Client Test',
            'moyen' => 'RDV',
            'activite' => 'Mécanique',
            'statut_validation' => 'Brouillon',
            ...$attributs,
        ]);
    }
}

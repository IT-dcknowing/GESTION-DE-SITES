<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Formats\FormatDesFactures;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * « Pourquoi a-t-on "Lieu non précisé" ? Normalement on a un code pour chaque facture. » — 07/10.
 *
 * Une facture importée avant que son code ait une ville le restait ; une facture de l'état des
 * impayés n'a pas de fiche. `factures:situer` reprend les premières par leur fiche, leur devis
 * ou leur code, et prend l'activité au devis de la même fiche.
 */
class LesFacturesSansLieuSeSituentTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $abidjan;

    private Ville $bouake;

    private Site $a1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->abidjan = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
        $this->a1 = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan 1', 'est_actif' => true]);
    }

    public function test_constat_puis_application(): void
    {
        // Par le devis : atelier Abidjan 1, et Sinistre.
        $parDevis = $this->facture('FR-KZN° 010669', 'Mécanique');
        Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->a1->id, 'numero' => 'D-1',
            'n_fiche_reception' => 'FR-KZN° 010669', 'date_emission' => '2026-03-01', 'client' => 'NSIA',
            'activite' => 'Sinistre', 'statut' => 'Validé', 'montant_devis' => 100_000,
        ]);
        // Par le code : SK a reçu Bouaké depuis l'import.
        $parCode = $this->facture('FR-SKN° 000123', 'Mécanique');
        DB::table('codes_agents')->insert(['entreprise_id' => $this->entreprise->id, 'code' => 'SK', 'ville_id' => $this->bouake->id, 'created_at' => now(), 'updated_at' => now()]);
        // Sans fiche : une ligne de l'état des impayés.
        $sansFiche = $this->facture(null, 'Mécanique');

        $this->artisan('factures:situer')->assertSuccessful();
        $this->assertNull($parDevis->fresh()->site_id, 'Le constat n\'écrit rien.');

        $this->artisan('factures:situer', ['--appliquer' => true])->assertSuccessful();

        $this->assertSame($this->a1->id, $parDevis->fresh()->site_id);
        $this->assertSame($this->abidjan->id, $parDevis->fresh()->ville_id);
        $this->assertSame('Sinistre', $parDevis->fresh()->activite, 'L\'activité vient du devis.');
        $this->assertSame($this->bouake->id, $parCode->fresh()->ville_id);
        $this->assertNull($sansFiche->fresh()->ville_id, 'Sans fiche ni code, on ne devine pas.');
    }

    public function test_l_import_du_ca_prend_l_activite_au_devis(): void
    {
        Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'site_id' => $this->a1->id, 'numero' => 'D-1',
            'n_fiche_reception' => 'FR-KZN° 010669', 'date_emission' => '2026-03-01', 'client' => 'NSIA',
            'activite' => 'Sinistre', 'statut' => 'Validé', 'montant_devis' => 100_000,
        ]);

        $format = new class($this->entreprise->id, new Rattachement($this->entreprise->id)) extends FormatDesFactures
        {
            public function ecrireLaLigne(array $ligne, array $rattachement, int $lot): void { $this->ecrire($ligne, $rattachement, $lot); }
        };
        $lot = LotImport::create(['entreprise_id' => $this->entreprise->id, 'deposant' => 'x', 'format' => 'factures', 'nom_fichier' => 'ca.xlsx', 'empreinte' => str_repeat('e', 64), 'etat' => 'termine']);

        $format->ecrireLaLigne(['numero' => 'FA -1', 'date' => '2026-03-02', 'client' => 'NSIA', 'montant' => '250000', 'fiche' => 'FR-KZN° 010669'],
            ['ville_id' => $this->abidjan->id, 'site_id' => $this->a1->id, 'code' => 'KZ', 'source' => 'code', 'presumee' => false], $lot->id);

        $this->assertSame('Sinistre', Facture::withoutGlobalScopes()->first()->activite);
    }

    private int $n = 0;

    private function facture(?string $fiche, string $activite): Facture
    {
        $n = ++$this->n;

        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'numero' => "F-$n", 'n_facture' => "FA-$n", 'date' => '2026-03-02',
            'client' => 'NSIA', 'activite' => $activite, 'montant' => 100_000, 'reference_devis' => $fiche,
        ]);
    }
}

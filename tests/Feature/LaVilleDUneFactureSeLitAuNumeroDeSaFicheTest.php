<?php

namespace Tests\Feature;

use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\VilleParLeNumeroDeFiche;
use Modules\Noyau\Imports\Formats\FormatDesFactures;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * « Parfois les deux chiffres sautaient : on ne peut pas déterminer si la facture vient
 * d'Abidjan, San Pedro ou Bouaké. Utiliser le numéro qui vient après les deux lettres » — 24/11.
 *
 * `FR-KZN° 010213`, `FR-N° 006165` : le numéro remonte à la fiche de réception, qui dit sa
 * ville. Un numéro porté par des fiches de plusieurs villes ne situe rien ; un numéro qui
 * contredit le code est signalé, pas tranché.
 */
class LaVilleDUneFactureSeLitAuNumeroDeSaFicheTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $abidjan;

    private Ville $sanPedro;

    private Ville $bouake;

    private Site $sp1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->abidjan = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->sanPedro = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'SPY', 'nom' => 'San Pedro', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
        $this->sp1 = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->sanPedro->id, 'code' => 'SP1', 'nom' => 'San Pedro 1', 'est_actif' => true]);
    }

    public function test_le_numero_se_lit_avec_ou_sans_lettres_et_sans_les_zeros(): void
    {
        $this->assertSame(['lettres' => 'KZ', 'numero' => '10213'], VilleParLeNumeroDeFiche::lire('FR-KZN° 010213'));
        $this->assertSame(['lettres' => 'YB', 'numero' => '9059'], VilleParLeNumeroDeFiche::lire('FR-YBN°009059'));
        $this->assertSame(['lettres' => null, 'numero' => '6165'], VilleParLeNumeroDeFiche::lire('FR-N° 006165'));
        $this->assertSame(['lettres' => 'KZ', 'numero' => '10213'], VilleParLeNumeroDeFiche::lire(' FR-KZ Nº 010213 '));
        $this->assertNull(VilleParLeNumeroDeFiche::lire('PR-SK-16091'));
        $this->assertNull(VilleParLeNumeroDeFiche::lire('FR-ABJ-1-422'));
    }

    /** `FR-KZN° 010213` : la fiche du parc écrit « FR-KZN°10213 », et dit San Pedro. KZ n'a pas de ville. */
    public function test_fr_kzn_010213_prend_la_ville_de_la_fiche_010213(): void
    {
        $this->fiche('FR-KZN°10213', ville: $this->sanPedro->id);
        $facture = $this->facture('FR-KZN° 010213');

        $this->artisan('factures:situer')->assertSuccessful();
        $this->assertNull($facture->fresh()->ville_id, 'Le constat n\'écrit rien.');

        $this->artisan('factures:situer', ['--appliquer' => true])->assertSuccessful();
        $this->assertSame($this->sanPedro->id, $facture->fresh()->ville_id);
    }

    /** `FR-N° 006165` : pas de lettres sur la facture ; la fiche a les siennes, et elles disent Bouaké. */
    public function test_fr_n_006165_sans_lettres_trouve_sa_ville_par_la_fiche(): void
    {
        $this->code('YB', $this->bouake->id);
        $this->fiche('FR-YBN° 006165');
        $parLeParc = $this->facture('FR-N° 006165');

        // Et par le devis, quand le parc n'a pas la fiche : l'atelier vient avec.
        Devis::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'site_id' => $this->sp1->id, 'numero' => 'D-1',
            'n_fiche_reception' => 'FR-TTN° 009059', 'date_emission' => '2026-01-05', 'client' => 'NSIA',
            'activite' => 'Mécanique', 'statut' => 'En attente', 'montant_devis' => 100_000,
        ]);
        $parLeDevis = $this->facture('FR-N° 009059');

        $this->artisan('factures:situer', ['--appliquer' => true])
            ->expectsOutputToContain('par le numéro de leur fiche')
            ->assertSuccessful();

        $this->assertSame($this->bouake->id, $parLeParc->fresh()->ville_id);
        $this->assertSame($this->sanPedro->id, $parLeDevis->fresh()->ville_id);
        $this->assertSame($this->sp1->id, $parLeDevis->fresh()->site_id);
    }

    /** Deux fiches de deux villes portent 004754 : on ne choisit pas — sauf si les lettres de la facture désignent l'une d'elles. */
    public function test_un_numero_ambigu_ne_donne_pas_de_ville(): void
    {
        $this->fiche('FR-KZN° 004754', ville: $this->abidjan->id);
        $this->fiche('FR-YBN° 004754', ville: $this->sanPedro->id);
        $sansLettres = $this->facture('FR-N° 004754');
        $autresLettres = $this->facture('FR-SKN° 004754');
        $memesLettres = $this->facture('FR-KZ N° 4754');

        $this->artisan('factures:situer', ['--appliquer' => true, '--details' => true])
            ->expectsOutputToContain('numéro de plusieurs villes')
            ->assertSuccessful();

        $this->assertNull($sansLettres->fresh()->ville_id);
        $this->assertNull($autresLettres->fresh()->ville_id);
        $this->assertSame($this->abidjan->id, $memesLettres->fresh()->ville_id, 'Mêmes lettres, même numéro : c\'est la même fiche.');

        $this->assertSame(VilleParLeNumeroDeFiche::AMBIGUE, (new VilleParLeNumeroDeFiche($this->e->id))->resoudre('FR-N° 004754')['statut']);
    }

    /** Le code dit Bouaké, la fiche du même numéro dit Abidjan : la facture n'est pas située, et elle est nommée. */
    public function test_un_conflit_avec_le_code_est_signale_et_rien_n_est_ecrase(): void
    {
        $this->code('SK', $this->bouake->id);
        $this->fiche('FR-ABN° 007777', ville: $this->abidjan->id);
        $enConflit = $this->facture('FR-SKN° 007777');

        // Déjà située à Bouaké, et son numéro dit Abidjan : comptée, pas déplacée.
        $this->fiche('FR-KZN° 008888', ville: $this->abidjan->id);
        $dejaSituee = $this->facture('FR-N° 008888');
        DB::table('factures')->where('id', $dejaSituee->id)->update(['ville_id' => $this->bouake->id]);

        $this->artisan('factures:situer', ['--appliquer' => true, '--details' => true])
            ->expectsOutputToContain('code et numéro en conflit')
            ->expectsOutputToContain('ville en base ≠ numéro')
            ->assertSuccessful();

        $this->assertNull($enConflit->fresh()->ville_id, 'Ni le code ni le numéro ne l\'emportent en silence.');
        $this->assertSame($this->bouake->id, $dejaSituee->fresh()->ville_id);
    }

    /** L'import du CA : sans code ni colonne SITE, la ville déclarée au dépôt n'est qu'une présomption ; le numéro situe. */
    public function test_l_import_du_ca_situe_par_le_numero_et_le_dit(): void
    {
        $this->code('YB', $this->bouake->id);
        $this->fiche('FR-YBN° 006165');
        $this->fiche('FR-KZN° 004754', ville: $this->abidjan->id);
        $this->fiche('FR-YBN° 004754', ville: $this->sanPedro->id);

        $entete = array_map(fn ($c) => FormatDesFactures::colonnes()[$c], ['date', 'numero', 'fiche', 'client', 'montant']);
        $feuille = [
            1 => $entete,
            2 => ['09/01/2026', 'FA -5768', 'FR-N° 006165', 'NSIA', '250000'],
            3 => ['09/01/2026', 'FA -5769', 'FR-N° 004754', 'NSIA', '100000'],
        ];

        $lot = LotImport::create([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->abidjan->id, 'deposant' => 'Reprise',
            'format' => 'factures', 'nom_fichier' => 'ca.xlsx', 'empreinte' => str_repeat('a', 64), 'etat' => 'termine',
        ]);

        $resultat = (new FormatDesFactures($this->e->id, new Rattachement($this->e->id)))
            ->parcourir($this->lecteur($feuille), $this->abidjan->id, $lot->id);

        $this->assertSame($this->bouake->id, Facture::withoutGlobalScopes()->where('n_facture', 'FA -5768')->value('ville_id'));
        $this->assertNull(Facture::withoutGlobalScopes()->where('n_facture', 'FA -5769')->value('ville_id'), 'Ambigu : ni Abidjan du dépôt, ni une des deux fiches.');
        $this->assertSame(1, $resultat->villesParLeNumero);
        $this->assertSame(1, $resultat->numerosAmbigus);
        $this->assertStringContainsString('située par le numéro de leur fiche', $resultat->resume());
    }

    // ------------------------------------------------------------------ outils

    private function fiche(string $numero, ?int $ville = null, ?int $site = null): void
    {
        DB::table('dossiers_vehicules')->insert([
            'entreprise_id' => $this->e->id, 'numero_fiche' => $numero, 'ville_id' => $ville, 'site_id' => $site,
            'source_rattachement' => 'code', 'rattachement_presume' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function code(string $code, int $ville): void
    {
        DB::table('codes_agents')->insert(['entreprise_id' => $this->e->id, 'code' => $code, 'ville_id' => $ville, 'created_at' => now(), 'updated_at' => now()]);
    }

    private int $n = 0;

    private function facture(string $fiche): Facture
    {
        $n = ++$this->n;

        return Facture::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'numero' => "F-$n", 'n_facture' => "FA-$n", 'date' => '2026-01-09',
            'client' => 'NSIA', 'activite' => 'Mécanique', 'montant' => 100_000, 'reference_devis' => $fiche,
        ]);
    }

    /** @param  array<int, list<string>>  $lignes */
    private function lecteur(array $lignes): Lecteur
    {
        return new class($lignes) implements Lecteur
        {
            public function __construct(private array $lignes) {}

            public function feuilles(): array
            {
                return ['CA'];
            }

            public function lignes(?string $feuille = null): Generator
            {
                yield from $this->lignes;
            }

            public function fermer(): void {}
        };
    }
}

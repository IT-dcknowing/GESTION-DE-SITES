<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Formats\FormatDesFactures;
use Modules\Noyau\Imports\Formats\FormatDesImpayes;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * Le chiffre d'affaires et l'état des impayés ne se doublent pas — question du 07/10.
 *
 * « Lorsque j'ai importé l'état des impayés, automatiquement la page chiffre d'affaires s'est
 * remplie ; il ne faudrait pas que j'aie des doublons avec les factures que je vais
 * importer. » Les deux fichiers décrivent les mêmes factures sans numéro commun : « FA -5713 »
 * d'un côté, « 17 » de l'autre. Sans pont, le second dépôt les écrivait toutes une deuxième
 * fois.
 *
 * Ce qui est tenu, et dans les deux ordres de dépôt :
 *
 * - même date, même montant, même plaque, un seul candidat : **une seule facture** ;
 * - redéposer l'un ou l'autre fichier ensuite ne la dédouble pas ;
 * - deux candidats possibles, ou une plaque absente : **on ne choisit pas**, la ligne est
 *   créée — un doublon visible plutôt qu'une fusion fausse ;
 * - la créance garde ses valeurs : le CATTC ne remplit que ce qu'elle laisse vide.
 */
class LeCaEtLEtatDesImpayesNeSeDoublentPasTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->ville = Ville::create(['entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'code' => 'ABJ-1', 'nom' => 'Abidjan 1', 'est_actif' => true]);
    }

    public function test_le_cattc_depose_apres_l_etat_retrouve_la_creance(): void
    {
        $this->etat(['numero' => '17', 'date' => '2026-03-02', 'client' => 'NSIA', 'assureur' => 'NSIA',
            'montant' => '1250000', 'regle' => '400000', 'immatriculation' => '1179 JF 01']);

        $this->cattc(['numero' => 'FA -5713', 'date' => '2026-03-02', 'client' => 'KOUASSI', 'montant' => '1250000',
            'immatriculation' => '1179JF01', 'sticker' => 'ST-9', 'code_client' => 'C042', 'marque' => 'TOYOTA']);

        $this->assertSame(1, Facture::withoutGlobalScopes()->count(), 'Une facture, pas deux.');

        $facture = Facture::withoutGlobalScopes()->first();
        $this->assertSame('17', $facture->n_facture, 'Le numéro de l\'état reste celui qu\'on suit.');
        $this->assertSame('FA -5713', $facture->n_facture_cattc);
        $this->assertSame('NSIA', $facture->client, 'La créance garde son client.');
        $this->assertSame('ST-9', $facture->n_sticker, 'Le CATTC remplit ce qui manquait.');
        $this->assertSame('TOYOTA', $facture->marque);
        $this->assertSame(1_250_000, (int) Facture::withoutGlobalScopes()->sum('montant'));

        // Redéposer le CATTC, puis l'état : toujours une seule facture, un seul règlement.
        $this->cattc(['numero' => 'FA -5713', 'date' => '2026-03-02', 'client' => 'KOUASSI', 'montant' => '1250000', 'immatriculation' => '1179JF01']);
        $this->etat(['numero' => '17', 'date' => '2026-03-02', 'client' => 'NSIA', 'assureur' => 'NSIA',
            'montant' => '1250000', 'regle' => '400000', 'immatriculation' => '1179 JF 01']);

        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
        $this->assertSame(400_000, (int) Encaissement::withoutGlobalScopes()->sum('montant'));
    }

    public function test_l_etat_depose_apres_le_cattc_retrouve_la_facture(): void
    {
        $this->cattc(['numero' => 'FA -5713', 'date' => '2026-03-02', 'client' => 'KOUASSI', 'montant' => '1250000', 'immatriculation' => '1179JF01']);

        $this->etat(['numero' => '17', 'date' => '2026-03-02', 'client' => 'NSIA', 'assureur' => 'NSIA',
            'montant' => '1250000', 'regle' => '400000', 'immatriculation' => '1179 JF 01']);

        $this->assertSame(1, Facture::withoutGlobalScopes()->count(), 'Une facture, pas deux.');

        $facture = Facture::withoutGlobalScopes()->first();
        $this->assertTrue((bool) $facture->est_etat_initial, 'Elle est désormais suivie en créance.');
        $this->assertSame('FA -5713', $facture->n_facture_cattc);
        $this->assertSame(400_000, (int) Encaissement::withoutGlobalScopes()->where('facture_id', $facture->id)->sum('montant'));

        // Les deux fichiers redéposés : rien ne se dédouble.
        $this->etat(['numero' => '17', 'date' => '2026-03-02', 'client' => 'NSIA', 'assureur' => 'NSIA',
            'montant' => '1250000', 'regle' => '400000', 'immatriculation' => '1179 JF 01']);
        $this->cattc(['numero' => 'FA -5713', 'date' => '2026-03-02', 'client' => 'KOUASSI', 'montant' => '1250000', 'immatriculation' => '1179JF01']);

        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
        $this->assertSame('NSIA', Facture::withoutGlobalScopes()->first()->client, 'Le CATTC ne réécrit pas la créance.');
    }

    public function test_deux_candidats_on_ne_choisit_pas(): void
    {
        // Un client de flotte : même véhicule, même tarif, même jour, deux factures à l'état.
        $this->etat(['numero' => '17', 'date' => '2026-03-02', 'client' => 'FLOTTE', 'montant' => '50000', 'immatriculation' => 'AA-480-EC']);
        $this->etat(['numero' => '18', 'date' => '2026-03-02', 'client' => 'FLOTTE', 'montant' => '50000', 'immatriculation' => 'AA-480-EC']);

        $this->cattc(['numero' => 'FA -1', 'date' => '2026-03-02', 'client' => 'FLOTTE', 'montant' => '50000', 'immatriculation' => 'AA480EC']);

        $this->assertSame(3, Facture::withoutGlobalScopes()->count(), 'Deux candidats : la ligne est créée, pas fusionnée au hasard.');
        $this->assertSame(0, Facture::withoutGlobalScopes()->whereNotNull('n_facture_cattc')->count());
    }

    public function test_une_cle_qui_differe_d_un_rien_ne_fusionne_pas(): void
    {
        $this->etat(['numero' => '17', 'date' => '2026-03-02', 'client' => 'NSIA', 'montant' => '1250000', 'immatriculation' => '1179 JF 01']);

        // Un jour d'écart, un franc d'écart, pas de plaque : trois factures distinctes.
        $this->cattc(['numero' => 'FA -1', 'date' => '2026-03-03', 'client' => 'NSIA', 'montant' => '1250000', 'immatriculation' => '1179JF01']);
        $this->cattc(['numero' => 'FA -2', 'date' => '2026-03-02', 'client' => 'NSIA', 'montant' => '1250001', 'immatriculation' => '1179JF01']);
        $this->cattc(['numero' => 'FA -3', 'date' => '2026-03-02', 'client' => 'NSIA', 'montant' => '1250000', 'immatriculation' => '']);

        $this->assertSame(4, Facture::withoutGlobalScopes()->count());
    }

    /**
     * Le constat compte les doublons déjà en base — écrits avant le 07/10 — sans rien écrire.
     */
    public function test_le_constat_compte_les_doublons_deja_en_base_sans_rien_ecrire(): void
    {
        foreach ([['IM-1', '17', true], ['FA-5713', 'FA -5713', false]] as [$numero, $nFacture, $etat]) {
            Facture::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entreprise->id, 'numero' => $numero, 'n_facture' => $nFacture,
                'date' => '2026-03-02', 'client' => 'NSIA', 'activite' => 'Sinistre', 'montant' => 1_250_000,
                'immatriculation' => $etat ? '1179 JF 01' : '1179JF01', 'est_etat_initial' => $etat,
            ]);
        }

        $avant = Facture::withoutGlobalScopes()->get()->toArray();

        $this->artisan('factures:doublons')
            ->expectsTable(['Constat', 'Nombre'], [
                ['Paires déjà reconnues (n_facture_cattc)', '0'],
                ['Doublons probables (clé forte, un seul candidat)', '1'],
                ['Montant compté deux fois au CA par ces doublons', '1 250 000 F'],
                ['Clés ambiguës (plusieurs candidats, on ne choisit pas)', '0'],
            ])
            ->assertSuccessful();

        $this->assertSame($avant, Facture::withoutGlobalScopes()->get()->toArray(), 'Le constat n\'écrit rien.');
    }

    private function etat(array $ligne): void
    {
        $this->format(FormatDesImpayes::class, 'impayes')->ecrireLaLigne($ligne + ['site' => 'ABIDJAN'], $this->rattachement(), $this->lot('impayes'));
    }

    private function cattc(array $ligne): void
    {
        $this->format(FormatDesFactures::class, 'factures')->ecrireLaLigne($ligne, $this->rattachement(), $this->lot('factures'));
    }

    private function rattachement(): array
    {
        return ['ville_id' => $this->ville->id, 'site_id' => null, 'code' => null, 'source' => 'colonne', 'presumee' => false];
    }

    private int $numeroDeLot = 0;

    private function lot(string $format): int
    {
        $n = ++$this->numeroDeLot;

        return LotImport::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id, 'deposant' => 'Reprise',
            'format' => $format, 'nom_fichier' => "$format-$n.xlsx", 'empreinte' => str_pad(dechex($n), 64, '0', STR_PAD_LEFT), 'etat' => 'termine',
        ])->id;
    }

    /** Chaque dépôt est une instance neuve du format, comme en vrai. */
    private function format(string $classe, string $cle)
    {
        $parent = $classe;

        return eval('return new class($this->entreprise->id, new '.Rattachement::class.'($this->entreprise->id)) extends '.$parent.' {
            public function ecrireLaLigne(array $ligne, array $rattachement, int $lot): void { $this->ecrire($ligne, $rattachement, $lot); }
        };');
    }
}

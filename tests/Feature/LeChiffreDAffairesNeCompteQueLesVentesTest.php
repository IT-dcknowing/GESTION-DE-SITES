<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Modeles\LotImport;
use Tests\TestCase;

/**
 * Le chiffre d'affaires ne compte que les ventes — décidé le 12/10 : « les KPI de CA doivent
 * se baser sur les données du CA importées ou saisies, et rien d'autre ».
 *
 * Une créance de l'état des impayés qui n'a pas été retrouvée au CATTC est une dette, pas une
 * vente de la période ; la compter faisait paraître deux fois une même vente sur l'accueil du
 * gérant — sa ligne du CATTC, et sa créance.
 */
class LeChiffreDAffairesNeCompteQueLesVentesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seules_les_ventes_entrent_dans_le_ca(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $lot = fn (string $format) => LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $entreprise->id, 'format' => $format, 'nom_fichier' => "$format.xlsx",
            'deposant' => 'X', 'empreinte' => hash('sha256', $format), 'etat' => 'termine',
        ]);
        $cattc = $lot('factures');
        $impayes = $lot('impayes');

        $facture = fn (array $v) => Facture::withoutGlobalScopes()->create($v + [
            'entreprise_id' => $entreprise->id, 'date' => '2026-09-01', 'client' => 'X',
            'activite' => 'Mécanique', 'n_facture' => uniqid(),
        ]);

        $facture(['lot_import_id' => $cattc->id, 'montant' => 100]);                                  // une ligne du CA
        $facture(['lot_import_id' => $impayes->id, 'n_facture_cattc' => 'FA -1', 'montant' => 200]);  // créance retrouvée au CA
        $facture(['montant' => 400, 'numero' => 'NFA-0001']);                                          // saisie dans l'application
        $facture(['lot_import_id' => $impayes->id, 'montant' => 1_000]);                                // créance seule
        $facture(['montant' => 2_000, 'numero' => 'IMP-0101-0001']);                                    // créance saisie à l'état
        $facture(['montant' => 4_000, 'est_etat_initial' => true]);                                     // reprise du classeur

        $this->assertSame(700, (int) Facture::withoutGlobalScopes()->duChiffreDAffaires()->sum('montant'));
    }
}

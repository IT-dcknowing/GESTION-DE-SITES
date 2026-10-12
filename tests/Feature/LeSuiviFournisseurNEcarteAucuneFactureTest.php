<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Services\Rattachement;
use Tests\TestCase;

/**
 * Le suivi fournisseurs n'écarte plus aucune facture — décidé le 12/10.
 *
 * Une ligne sans date de facture entrait au rejet (49 sur 10 147) ; elle entre, datée de sa
 * réception quand elle la porte. Un avoir fournisseur (montant négatif) entre comme tel.
 */
class LeSuiviFournisseurNEcarteAucuneFactureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ni_la_ligne_sans_date_ni_l_avoir_ne_sont_ecartes(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);

        $format = new class($entreprise->id, new Rattachement($entreprise->id)) extends FormatDesFournisseurs
        {
            public function motif(array $ligne): ?string { return $this->refuser($ligne); }
        };

        $this->assertNull($format->motif(['fournisseur' => 'CFAO', 'montant' => '150000']), 'Sans date de facture : la ligne entre.');
        $this->assertNull($format->motif(['fournisseur' => 'CFAO', 'montant' => '-25000', 'date_facture' => '2026-03-01']), 'Un avoir entre.');
    }
}

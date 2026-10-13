<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Imports\Formats\FormatDesFournisseurs;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\Executeur;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Une ligne de Bouaké dans le fichier d'Abidjan n'est pas une erreur — 13/10.
 *
 * « La ville a passé une commande, mais c'est l'autre ville qui a reçu la facture : elle ne fait
 * que l'enregistrer au nom de celle qui a commandé. Si tu vois une ligne de Bouaké dans le fichier
 * d'Abidjan, enregistre-la à Bouaké, et marque qu'elle vient d'Abidjan. »
 */
class UneFactureFournisseurRecuePourUneAutreVilleTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $abidjan;

    private Ville $bouake;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->abidjan = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->bouake = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'BKE', 'nom' => 'Bouaké', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->abidjan->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->bouake->id, 'code' => 'B1', 'nom' => 'Bouaké', 'est_actif' => true]);
    }

    public function test_la_ligne_de_bouake_va_a_bouake_et_dit_qu_abidjan_l_a_recue(): void
    {
        $lot = $this->deposer($this->abidjan->id, [
            $this->ligne('ABIDJAN', 'SOCIDA', 'A-1', 100_000),
            $this->ligne('BOUAKE', 'RIMCO', 'B-1', 250_000),
        ]);

        $abidjan = FactureFournisseur::withoutGlobalScopes()->where('numero_piece', 'A-1')->first();
        $bouake = FactureFournisseur::withoutGlobalScopes()->where('numero_piece', 'B-1')->first();

        $this->assertSame($this->abidjan->id, $abidjan->ville_id);
        $this->assertNull($abidjan->recue_par_ville_id, 'Une ligne de la ville du dépôt n\'a rien à mentionner.');

        $this->assertSame($this->bouake->id, $bouake->ville_id, 'La dette est à la ville qui a commandé.');
        $this->assertSame($this->abidjan->id, $bouake->recue_par_ville_id);
        $this->assertSame('Abidjan', $bouake->recuePar->nom);

        $this->assertStringContainsString('reçue pour le compte d\'une autre ville', $lot->fresh()->message);
        $this->assertStringNotContainsString('pas été filtrée', $lot->fresh()->message, 'Ce n\'est pas l\'alerte « fichier non filtré ».');
    }

    public function test_la_mention_survit_au_depot_du_fichier_de_la_ville_qui_a_commande(): void
    {
        $this->deposer($this->abidjan->id, [$this->ligne('BOUAKE', 'RIMCO', 'B-1', 250_000)]);
        // Le fichier de Bouaké reprend la même facture : rien à mentionner de son côté, rien à effacer non plus.
        $this->deposer($this->bouake->id, [$this->ligne('BOUAKE', 'RIMCO', 'B-1', 250_000)]);

        $this->assertSame(1, FactureFournisseur::withoutGlobalScopes()->count());
        $this->assertSame($this->abidjan->id, FactureFournisseur::withoutGlobalScopes()->first()->recue_par_ville_id);
    }

    private function deposer(int $villeId, array $lignes): LotImport
    {
        $chemin = $this->classeurXlsx(['DETAIL' => array_merge([$this->enTete()], $lignes)]);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->e->id, 'ville_id' => $villeId, 'deposant' => 'K.', 'format' => FormatDesFournisseurs::cle(),
            'nom_fichier' => 'suivi.xlsx', 'empreinte' => hash('sha256', uniqid('', true)), 'taille' => 1024, 'etat' => 'depose',
        ]);
        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));
        (new Executeur($this->e->id))->traiter($lot, FormatDesFournisseurs::class);

        return $lot;
    }

    private function enTete(): array
    {
        return ['DATE FACTURE/BC', 'SITE', 'N° PIECE', 'MONTANT', 'FOURNISSEUR', 'MONTANT REGLE', 'RESTE A PAYER'];
    }

    private function ligne(string $site, string $fournisseur, string $piece, int $montant): array
    {
        return ['2026-03-01', $site, $piece, $montant, $fournisseur, 0, $montant];
    }
}

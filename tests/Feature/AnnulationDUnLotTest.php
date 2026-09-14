<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\AnnulationDUnLot;
use Tests\TestCase;

/**
 * Défaire un import qui a réussi.
 *
 * La barre latérale promettait « aucun import n'écrit à moitié ». C'était vrai de l'import
 * qui échoue, et muet sur celui qui réussit avec le mauvais fichier — le cas le plus
 * fréquent, et le seul qu'on ne pouvait pas rattraper.
 *
 * Ces tests fixent les trois propriétés qui rendent l'annulation utilisable sans crainte :
 *
 * 1. elle dit ce qu'elle va faire avant de le faire ;
 * 2. elle ne touche pas à ce qui a été retouché depuis ;
 * 3. elle rouvre la porte au même fichier, puisque c'est pour cela qu'on annule.
 */
class AnnulationDUnLotTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    private LotImport $lot;

    private User $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);

        $this->gerant = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => 'Gérant',
            'email' => 'g@alpha.test', 'password' => 'motdepasse', 'est_actif' => true,
        ]);

        $this->lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $ville->id,
            'user_id' => $this->gerant->id,
            'deposant' => 'Gérant',
            'format' => 'parc',
            'nom_fichier' => 'parc.xls',
            'empreinte' => str_repeat('a', 64),
            'taille' => 1000,
            'etat' => 'termine',
            'lignes_lues' => 3, 'lignes_creees' => 3,
            'lignes_majs' => 0, 'lignes_ignorees' => 0, 'lignes_rejetees' => 0,
        ]);

        $this->fiche('FR-KZN° 000001');
        $this->fiche('FR-KZN° 000002');
        $this->fiche('FR-KZN° 000003');
    }

    public function test_l_apercu_annonce_ce_qui_partirait_sans_rien_toucher(): void
    {
        $apercu = (new AnnulationDUnLot($this->entreprise->id))->apercu($this->lot);

        $this->assertSame(3, $apercu['total']);
        $this->assertSame(0, $apercu['retenues']);
        $this->assertSame(3, DB::table('dossiers_vehicules')->count(), 'Un aperçu ne supprime rien.');
    }

    public function test_l_annulation_retire_les_lignes_creees_par_ce_lot(): void
    {
        $compte = (new AnnulationDUnLot($this->entreprise->id))
            ->annuler($this->lot, 'Déposé sous la mauvaise ville', $this->gerant->id);

        $this->assertSame(3, $compte['supprimees']);
        $this->assertSame(0, DB::table('dossiers_vehicules')->count());
        $this->assertSame('annule', $this->lot->fresh()->etat);
        $this->assertStringContainsString('mauvaise ville', $this->lot->fresh()->message);
    }

    public function test_une_ligne_retouchee_depuis_l_import_reste_en_place(): void
    {
        // Quelqu'un a complété la fiche à la main : ce travail n'appartient pas à l'import,
        // et le supprimer ferait disparaître ce que personne n'a demandé de perdre.
        DB::table('dossiers_vehicules')
            ->where('numero_fiche', 'FR-KZN° 000002')
            ->update(['marque' => 'TOYOTA', 'updated_at' => now()->addMinute()]);

        $compte = (new AnnulationDUnLot($this->entreprise->id))
            ->annuler($this->lot, 'Mauvais mois', $this->gerant->id);

        $this->assertSame(2, $compte['supprimees']);
        $this->assertSame(1, $compte['retenues']);
        $this->assertSame(1, DB::table('dossiers_vehicules')->count());
        $this->assertSame('TOYOTA', DB::table('dossiers_vehicules')->value('marque'));
    }

    public function test_un_lot_annule_ne_bloque_plus_le_meme_fichier(): void
    {
        (new AnnulationDUnLot($this->entreprise->id))->annuler($this->lot, 'Motif', $this->gerant->id);

        // C'est exactement pour redéposer autrement qu'on annule : le refus du doublon ne
        // doit pas se retourner contre le geste qui vient d'être fait.
        $this->assertNull(
            LotImport::withoutGlobalScopes()
                ->where('entreprise_id', $this->entreprise->id)
                ->where('empreinte', str_repeat('a', 64))
                ->where('etat', '!=', 'annule')
                ->first(),
        );
    }

    public function test_on_n_annule_ni_un_echec_ni_un_lot_deja_annule(): void
    {
        $this->lot->forceFill(['etat' => 'echec'])->save();

        $this->expectException(\RuntimeException::class);

        (new AnnulationDUnLot($this->entreprise->id))->annuler($this->lot, 'Motif', $this->gerant->id);
    }

    public function test_le_lot_d_une_autre_entreprise_est_refuse(): void
    {
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);

        $this->expectException(\RuntimeException::class);

        (new AnnulationDUnLot($autre->id))->annuler($this->lot, 'Motif', $this->gerant->id);
    }

    private function fiche(string $numero): void
    {
        DB::table('dossiers_vehicules')->insert([
            'entreprise_id' => $this->entreprise->id,
            'lot_import_id' => $this->lot->id,
            'numero_fiche' => $numero,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

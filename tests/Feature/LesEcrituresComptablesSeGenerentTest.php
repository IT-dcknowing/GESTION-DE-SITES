<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Modules\Noyau\Comptabilite\Jobs\GenererEtDeverser;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Comptabilite\Services\DeversementComptable;
use Modules\Noyau\Comptabilite\Services\GenerateurDEcritures;
use Modules\Noyau\Comptabilite\Services\Montants;
use Modules\Noyau\Comptabilite\Services\PropositionsDImputation;
use Modules\Noyau\Comptabilite\Services\TiersComptables;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les écritures comptables se génèrent — 24/11.
 *
 * Chaque épreuve fixe une règle tranchée : une pièce et son règlement sont deux opérations ; une
 * charge ne compte jamais deux fois ; la TVA d'une vente se lit sur la facture ; rien n'est
 * arrondi ; une opération déjà transmise se contre-passe.
 */
class LesEcrituresComptablesSeGenerentTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
    }

    public function test_les_tiers_sont_numerotes_a_part_du_compte_collectif(): void
    {
        $t = new TiersComptables($this->e->id);

        $a = $t->de('client', 'Allianz Côte d’Ivoire');
        $this->assertSame('410001', $a->numero);
        $this->assertSame('411000', $a->compte_general);
        $this->assertSame($a->id, $t->de('client', 'ALLIANZ COTE D’IVOIRE')->id, 'deux orthographes, un tiers');
        $this->assertSame('410002', $t->de('client', 'Garage Bon Prix')->numero);
        $this->assertSame('410000', $t->de('client', '')->numero, 'sans nom : client divers');
        $this->assertSame('400001', $t->de('fournisseur', 'CFAO', 'CI123')->numero);
        $this->assertSame('400001', $t->de('fournisseur', 'CFAO MOTORS', 'CI123')->numero, 'le NCC reconnaît le fournisseur');
    }

    public function test_une_sortie_de_caisse_administrative_est_une_charge_payee_en_caisse_de_la_ville(): void
    {
        $this->sortie('CARBURANT VEHICULE ATELIER', 15000);

        $op = $this->generer()->sole();
        $this->assertSame('prete', $op->statut);
        $this->assertSame('CA01', $op->code_journal);
        $this->assertSame([['618000', '15000', '0'], ['571101', '0', '15000']], $this->lignes($op));
    }

    public function test_une_sortie_fournisseur_est_un_reglement_et_un_versement_un_virement_de_fonds(): void
    {
        $this->sortie('ACHAT PIECES FILTRE', 40000, 'CFAO');
        $this->sortie('VERSEMENT BANQUE', 100000);

        [$reglement, $virement] = $this->generer()->values();
        $this->assertSame('ReglementAchat', $reglement->nature);
        $this->assertSame([['401000', '40000', '0'], ['571101', '0', '40000']], $this->lignes($reglement));
        $this->assertSame('400001', $reglement->lignes[0]->compte_tiers);
        $this->assertSame('VirementDeFonds', $virement->nature);
        $this->assertSame([['585000', '100000', '0'], ['571101', '0', '100000']], $this->lignes($virement));
    }

    public function test_une_sortie_payee_par_un_reglement_du_suivi_ne_s_ecrit_qu_une_fois(): void
    {
        $sortie = $this->sortie('REGLEMENT FOURNISSEUR', 59000, 'CFAO');
        $piece = DB::table('factures_fournisseurs')->insertGetId([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'fournisseur' => 'CFAO', 'numero_piece' => 'FA-1',
            'date_facture' => '2026-03-01', 'montant' => 59000, 'montant_ht' => 50000, 'tva' => 9000,
        ]);
        DB::table('reglements_fournisseur')->insert([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code_reglement' => 'R-1', 'fournisseur' => 'CFAO',
            'date_reglement' => '2026-03-05', 'montant' => 59000, 'facture_fournisseur_id' => $piece, 'mouvement_caisse_id' => $sortie,
        ]);

        $ops = $this->generer();
        $this->assertSame(['FactureAchat', 'ReglementAchat'], $ops->pluck('nature')->all());
        $this->assertSame([['601000', '50000', '0'], ['445200', '9000', '0'], ['401000', '0', '59000']], $this->lignes($ops[0]));
        $this->assertSame([['401000', '59000', '0'], ['571101', '0', '59000']], $this->lignes($ops[1]));
        $this->assertSame('reglement_fournisseur', $ops[1]->source, 'la sortie de caisse ne fait pas un second règlement');
    }

    public function test_la_fne_rattachee_donne_le_detail_certifie_et_une_piece_sans_tva_lue_attend(): void
    {
        $certifiee = DB::table('factures_fournisseurs')->insertGetId([
            'entreprise_id' => $this->e->id, 'fournisseur' => 'CFAO', 'numero_piece' => 'FA-2', 'date_facture' => '2026-04-01', 'montant' => 118100,
        ]);
        $releve = DB::table('releves_fne')->insertGetId(['entreprise_id' => $this->e->id, 'login' => 'x', 'fichier' => 'x', 'empreinte' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('factures_fne')->insert([
            'entreprise_id' => $this->e->id, 'releve_fne_id' => $releve, 'facture_fournisseur_id' => $certifiee, 'reference' => 'FNE-9',
            'date_facture' => '2026-04-01', 'emetteur_ncc' => 'CI123', 'emetteur_nom' => 'CFAO MOTORS',
            'montant_ht' => 100000, 'montant_tva' => 18000, 'timbre' => 100, 'montant_ttc' => 118100,
        ]);
        DB::table('factures_fournisseurs')->insert([
            'entreprise_id' => $this->e->id, 'fournisseur' => 'Quincaillerie X', 'numero_piece' => 'FA-3', 'date_facture' => '2026-04-02', 'montant' => 25000,
        ]);

        [$fa2, $fa3] = $this->generer()->values();
        $this->assertSame([['601000', '100000', '0'], ['445200', '18000', '0'], ['646200', '100', '0'], ['401000', '0', '118100']], $this->lignes($fa2));
        $this->assertSame('a_verifier', $fa3->statut);
        $this->assertStringContainsString('TVA de la pièce à lire', $fa3->motif);
    }

    public function test_une_vente_attend_sa_tva_lue_puis_s_ecrit_et_son_encaissement_aussi(): void
    {
        $facture = DB::table('factures')->insertGetId([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'ville_id' => $this->ville->id, 'numero' => 'F-1', 'n_facture' => 'F087',
            'date' => '2026-05-10', 'client' => 'Allianz', 'activite' => 'Sinistre', 'montant' => 1180000,
        ]);
        DB::table('encaissements')->insert([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'facture_id' => $facture, 'date' => '2026-05-20',
            'moyen' => 'Espèces', 'montant' => 500000,
        ]);

        $vente = $this->generer()->firstWhere('nature', 'FactureVente');
        $this->assertSame('a_verifier', $vente->statut);
        $this->assertStringContainsString('TVA à lire sur la facture', $vente->motif);

        DB::table('factures')->where('id', $facture)->update(['montant_ht' => 1000000, 'montant_tva' => 180000]);
        $ops = $this->generer();
        $vente = $ops->firstWhere('nature', 'FactureVente');
        $this->assertSame('prete', $vente->statut);
        $this->assertSame([['411000', '1180000', '0'], ['706200', '0', '1000000'], ['443200', '0', '180000']], $this->lignes($vente));
        $this->assertSame('410001', $vente->lignes[0]->compte_tiers);

        $reglement = $ops->firstWhere('nature', 'ReglementVente');
        $this->assertSame([['571101', '500000', '0'], ['411000', '0', '500000']], $this->lignes($reglement));
        $this->assertSame('410001', $reglement->lignes[1]->compte_tiers, 'même tiers que la facture');
        $this->assertSame(1, OperationComptable::withoutGlobalScopes()->where('nature', 'FactureVente')->count(), 'le brouillon est remplacé, pas doublé');
    }

    public function test_rien_n_est_arrondi(): void
    {
        $this->sortie('CARBURANT', '15000.25');

        $op = $this->generer()->sole();
        $this->assertSame('15000.25', Montants::texte(Montants::unites($op->lignes[0]->debit)));
        $this->assertSame('prete', $op->statut);
    }

    public function test_une_operation_deja_transmise_est_contre_passee_si_sa_source_change(): void
    {
        $sortie = $this->sortie('CARBURANT', 15000);
        $op = $this->generer()->sole();
        $op->forceFill(['statut_deversement' => 'acceptee'])->save();

        DB::table('mouvements_caisse')->where('id', $sortie)->update(['montant' => 16000]);
        $ops = OperationComptable::withoutGlobalScopes()->orderBy('numero_saisie')->get();
        (new GenerateurDEcritures($this->e->id))->generer('2026-01-01', '2026-12-31');
        $ops = OperationComptable::withoutGlobalScopes()->with('lignes')->orderBy('numero_saisie')->get();

        $this->assertSame(['contrepassee', 'prete', 'prete'], $ops->pluck('statut')->all());
        $this->assertTrue($ops[1]->est_contrepassation);
        $this->assertSame([['618000', '0', '15000'], ['571101', '15000', '0']], $this->lignes($ops[1]));
        $this->assertSame([['618000', '16000', '0'], ['571101', '0', '16000']], $this->lignes($ops[2]));
    }

    public function test_le_deversement_envoie_le_referentiel_puis_l_operation_entiere(): void
    {
        config(['services.comptaflow.url' => 'https://comptaflow.test', 'services.comptaflow.secret' => 's']);
        DB::table('liaisons_comptaflow')->insert([
            'entreprise_id' => $this->e->id, 'comptaflow_company_id' => 7, 'cle_chiffree' => Crypt::encryptString('cle'), 'statut' => 'active',
        ]);
        $this->sortie('ACHAT PIECES', 40000, 'CFAO');
        $this->generer();
        Http::fake([
            '*/referentiel/deverser' => Http::response(['success' => true]),
            '*/ecritures/deverser' => Http::response(['success' => true, 'count' => 2, 'ignorees' => 0, 'refus' => []]),
        ]);

        $bilan = (new DeversementComptable($this->e->id))->deverser();

        $this->assertSame(1, $bilan['acceptees']);
        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/referentiel/deverser')) {
                return false;
            }

            return collect($r['tiers'])->contains(fn ($t) => $t['numero_de_tiers'] === '400001' && $t['compte_general'] === '401000')
                && collect($r['codes_journaux'])->contains(fn ($j) => $j['code_journal'] === 'CA01' && $j['compte_numero'] === '571101' && $j['type'] === 'Trésorerie');
        });
        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/ecritures/deverser')) {
                return false;
            }
            $l = $r['ecritures'][0];

            return $r['atomique'] === true && $r['exercice_debut'] === '2026-01-01'
                && $l['compte_debit'] === '401000' && $l['compte_credit'] === null && $l['debit'] === 40000
                && $l['compte_tiers'] === '400001' && str_starts_with($l['cle_source'], 'L') && str_starts_with($l['operation_source'], 'OP');
        });
        $this->assertSame('acceptee', OperationComptable::withoutGlobalScopes()->value('statut_deversement'));
    }

    public function test_l_ecran_lit_la_tva_sur_la_facture_et_refuse_ce_qui_ne_fait_pas_le_ttc(): void
    {
        ProvisionneurEntreprise::creerRoles($this->e);
        $facture = DB::table('factures')->insertGetId([
            'entreprise_id' => $this->e->id, 'site_id' => $this->site->id, 'numero' => 'F-1', 'n_facture' => 'F087',
            'date' => now()->toDateString(), 'client' => 'Allianz', 'activite' => 'Mécanique', 'montant' => 118000,
        ]);
        (new GenerateurDEcritures($this->e->id))->generer(now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());
        $op = OperationComptable::withoutGlobalScopes()->where('source', 'facture')->sole();

        $this->actingAs($this->compte('caissier', 'caisse'));
        $ecran = Volt::test('comptabilite.ecritures')->assertSee('TVA à lire sur la facture')->call('ouvrir', $op->id);
        $ecran->set('ht', '100000')->set('tva', '17000')->call('lireLaTva')->assertHasErrors('ht');
        $ecran->set('ht', '100000')->set('tva', '18000')->call('lireLaTva')->assertHasNoErrors();

        $this->assertSame('100000', Montants::texte(Montants::unites(DB::table('factures')->where('id', $facture)->value('montant_ht'))));
        $vente = OperationComptable::withoutGlobalScopes()->where('source', 'facture')->where('statut', 'prete')->sole();
        $this->assertSame('706100', $vente->lignes[1]->compte);

        // Le déversement est réservé au gérant, revérifié au serveur.
        Bus::fake();
        $ecran->call('deverser')->assertForbidden();
        Bus::assertNotDispatched(GenererEtDeverser::class);
    }

    public function test_un_commercial_n_ouvre_pas_les_ecritures(): void
    {
        ProvisionneurEntreprise::creerRoles($this->e);
        // Refusé ou renvoyé vers son propre espace : jamais l'écran.
        $r = $this->actingAs($this->compte('commercial', 'awa'))->get(route('ecritures.index'));
        $this->assertContains($r->status(), [302, 403]);
        $this->assertStringNotContainsString('Déverser vers COMPTAFLOW', (string) $r->getContent());
    }

    public function test_l_ia_propose_une_nature_le_serveur_filtre_et_seule_la_validation_change_l_ecriture(): void
    {
        $sortie = $this->sortie('ZXQ 45 KOFFI', 12000);   // illisible pour la règle : « Autres »
        $this->sortie('BLABLA', 3000);
        $this->generer();
        DB::table('configurations_ia')->insert(['fournisseur' => 'anthropic', 'modele' => 'claude-sonnet-5-5', 'cle_api_chiffree' => Crypt::encryptString('k'), 'actif' => true]);
        DB::table('affectations_ia')->insert(['usage' => 'imputations', 'fournisseur' => 'anthropic', 'actif' => true]);
        [$a, $b] = OperationComptable::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            ['id' => $a, 'nature' => 'charge:Carburant & transport', 'confiance' => 70, 'raison' => 'déplacement'],
            ['id' => $b, 'nature' => 'compte:999999', 'confiance' => 99, 'raison' => 'inventé'],   // hors liste : rejeté
        ])]], 'stop_reason' => 'end_turn'])]);

        $ia = new PropositionsDImputation($this->e->id);
        $this->assertSame(1, $ia->proposer());
        $this->assertSame('658000', OperationComptable::withoutGlobalScopes()->find($a)->lignes[0]->compte, 'une proposition ne change rien');

        $ia->decider((int) DB::table('imputations_comptables')->value('id'), true, null, 1);
        $actuelle = OperationComptable::withoutGlobalScopes()->where('source', 'caisse')->where('source_id', $sortie)->where('statut', 'prete')->sole();
        $this->assertSame('618000', $actuelle->lignes[0]->compte);
        $this->assertSame('12000', Montants::texte(Montants::unites($actuelle->lignes[0]->debit)), 'le montant ne bouge pas');
    }

    private function compte(string $role, string $nom): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->e->id);
        $compte = User::create([
            'entreprise_id' => $this->e->id, 'name' => ucfirst($nom), 'email' => $nom.'@alpha.test',
            'password' => Hash::make('motdepasse123'), 'ville_id' => $this->ville->id, 'site_id' => $this->site->id,
            'est_actif' => true, 'doit_changer_mot_de_passe' => false,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }

    private function sortie(string $libelle, $montant, ?string $beneficiaire = null): int
    {
        return DB::table('mouvements_caisse')->insertGetId([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'date' => '2026-03-01', 'sens' => 'sortie',
            'libelle' => $libelle, 'montant' => $montant, 'beneficiaire' => $beneficiaire,
        ]);
    }

    private function generer()
    {
        (new GenerateurDEcritures($this->e->id))->generer('2026-01-01', '2026-12-31');

        return OperationComptable::withoutGlobalScopes()->with('lignes')->where('statut', '!=', 'contrepassee')
            ->orderBy('numero_saisie')->get();
    }

    /** @return list<array{0: string, 1: string, 2: string}> compte, débit, crédit — tels quels */
    private function lignes(OperationComptable $op): array
    {
        return $op->lignes->map(fn ($l) => [$l->compte, Montants::texte(Montants::unites($l->debit)), Montants::texte(Montants::unites($l->credit))])->all();
    }
}

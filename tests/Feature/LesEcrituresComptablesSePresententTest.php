<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Volt\Volt;
use Modules\Noyau\Commun\Services\MenuNavigation;
use Modules\Noyau\Comptabilite\Jobs\GenererEtDeverser;
use Modules\Noyau\Comptabilite\Modeles\OperationComptable;
use Modules\Noyau\Comptabilite\Services\DeversementComptable;
use Modules\Noyau\Comptabilite\Services\GenerateurDEcritures;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\HabilitationAction;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Entreprises\Support\HabilitationsDActions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les écritures comptables se présentent — 25/11.
 *
 * Le journal à neuf colonnes de COMPTAFLOW et SELFLOW ; un module donné par habilitation,
 * revérifiée au serveur ; la pièce justificative ouverte dans l'entreprise seulement, et son PDF
 * envoyé à COMPTAFLOW après l'acceptation des écritures.
 */
class LesEcrituresComptablesSePresententTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";

    private Entreprise $e;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->e = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->e);
        $this->ville = Ville::create(['entreprise_id' => $this->e->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true]);
        $this->site = Site::create(['entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'code' => 'A1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true]);
        Storage::fake('local');
    }

    public function test_le_journal_a_les_neuf_colonnes_de_comptaflow_avec_le_compte_tiers(): void
    {
        $this->pieceDuSuivi();
        $this->generer();

        $html = Volt::actingAs($this->compte('gerant', 'gerant'))->test('comptabilite.ecritures')
            ->set('annee', 2026)->set('statut', 'prete')->html();

        // Les en-têtes, exactement ceux du guide de COMPTAFLOW, et neuf seulement.
        preg_match('~<thead>(.*?)</thead>~s', $html, $tete);
        preg_match_all('~<th[^>]*>(.*?)</th>~s', $tete[1], $colonnes);
        $this->assertSame(['Date', 'N° saisie', 'Code journal', 'N° pièce / référence', 'N° compte général', 'Libellé', 'Débit', 'Crédit', 'N° compte tiers'],
            array_map(fn ($c) => html_entity_decode(trim(strip_tags($c))), $colonnes[1]));

        // Une rangée par ligne d'écriture, de neuf cellules : la charge, la TVA, le fournisseur et son tiers.
        preg_match_all('~<tr>\s*(<td style="border-left.*?)</tr>~s', $html, $rangees);
        $lignes = array_map(function ($r) {
            preg_match_all('~<td[^>]*>(.*?)</td>~s', $r, $c);

            return array_map(fn ($v) => html_entity_decode(trim(strip_tags($v))), $c[1]);
        }, $rangees[1]);
        $this->assertCount(3, $lignes);
        $this->assertSame(['01/03/2026', '1', 'ACH', 'FA-1', '601000'], array_slice($lignes[0], 0, 5));
        $this->assertSame(['401000', '400001'], [$lignes[2][4], $lignes[2][8]]);
        $this->assertSame('', $lignes[2][6], 'au crédit seulement');
        $this->assertStringContainsString('59', $lignes[2][7]);
        $this->assertCount(9, $lignes[2]);
        $this->assertStringContainsString('Prête', $html);
    }

    public function test_sans_l_habilitation_le_module_est_ferme_au_serveur(): void
    {
        $caissier = $this->compte('caissier', 'caisse');
        foreach (['ecritures.index', 'ecritures.parametrage', 'ecritures.imputations'] as $route) {
            $this->actingAs($caissier)->get(route($route))->assertForbidden();
        }

        // Le menu suit la même règle que les écrans.
        $this->actingAs($caissier);
        $this->assertNotContains('Écritures comptables', array_column(MenuNavigation::pour($caissier), 'label'));

        // Le gérant l'a au départ ; un refus posé sur son compte même l'emporte.
        $gerant = $this->compte('gerant', 'gerant');
        $this->actingAs($gerant);
        $this->assertContains('Écritures comptables', array_column(MenuNavigation::pour($gerant), 'label'));
        $this->actingAs($gerant)->get(route('ecritures.index'))->assertOk();
        HabilitationAction::create(['domaine' => 'comptabilite', 'action' => 'voir', 'user_id' => $gerant->id, 'accorde' => false]);
        HabilitationsDActions::oublier();
        $this->actingAs($gerant)->get(route('ecritures.index'))->assertForbidden();

        // Lire sans déverser : chaque action Volt revérifie son geste, le bouton caché ne suffit pas.
        HabilitationAction::create(['domaine' => 'comptabilite', 'action' => 'voir', 'role' => 'caissier', 'accorde' => true]);
        HabilitationsDActions::oublier();
        Bus::fake();
        Volt::actingAs($caissier)->test('comptabilite.ecritures')->assertDontSee('Déverser vers COMPTAFLOW')
            ->call('deverser')->assertForbidden();
        Volt::actingAs($caissier)->test('comptabilite.ecritures')->call('generer')->assertForbidden();
        Bus::assertNotDispatched(GenererEtDeverser::class);
        $this->actingAs($caissier)->get(route('ecritures.imputations'))->assertForbidden();
    }

    public function test_le_justificatif_ne_s_ouvre_que_dans_l_entreprise_et_avec_l_habilitation(): void
    {
        $this->pieceDuSuivi();
        $this->generer();
        $op = OperationComptable::withoutGlobalScopes()->where('source', 'suivi')->sole();
        $gerant = $this->compte('gerant', 'gerant');

        // Pas de PDF, pas de lien : rien à ouvrir.
        $this->actingAs($gerant)->get(route('ecritures.justificatif', $op->id))->assertNotFound();

        $this->actingAs($gerant)->post(route('ecritures.justificatif.deposer', $op->id), [
            'justificatif' => UploadedFile::fake()->createWithContent('FA-1.pdf', self::PDF),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(hash('sha256', self::PDF), DB::table('justificatifs_comptables')->value('sha256'));

        $reponse = $this->actingAs($gerant)->get(route('ecritures.justificatif', $op->id))->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertSame(self::PDF, file_get_contents($reponse->baseResponse->getFile()->getPathname()));

        // Un fichier qui n'est pas un PDF est refusé.
        $this->actingAs($gerant)->post(route('ecritures.justificatif.deposer', $op->id), [
            'justificatif' => UploadedFile::fake()->createWithContent('faux.pdf', 'pas un pdf'),
        ])->assertSessionHasErrors('justificatif');

        // Une autre entreprise : l'opération n'existe pas pour elle.
        $autre = Entreprise::create(['nom' => 'Beta', 'slug' => 'beta']);
        ProvisionneurEntreprise::creerRoles($autre);
        $voisin = $this->compte('gerant', 'voisin', $autre);
        $this->actingAs($voisin)->get(route('ecritures.justificatif', $op->id))->assertNotFound();
        $this->actingAs($voisin)->post(route('ecritures.justificatif.deposer', $op->id), [
            'justificatif' => UploadedFile::fake()->createWithContent('x.pdf', self::PDF),
        ])->assertNotFound();

        // La même entreprise, sans l'habilitation : refusé.
        $this->actingAs($this->compte('caissier', 'caisse'))->get(route('ecritures.justificatif', $op->id))->assertForbidden();

        // L'écran le propose à qui peut l'ouvrir.
        Volt::actingAs($gerant)->test('comptabilite.ecritures')->set('annee', 2026)->set('statut', 'prete')
            ->assertSee('Pièce justificative (PDF)')->assertSee(route('ecritures.justificatif', $op->id));
    }

    public function test_le_pdf_part_vers_comptaflow_apres_l_acceptation_des_ecritures(): void
    {
        Sleep::fake();
        $this->lier();
        $piece = $this->pieceDuSuivi();
        $this->generer();
        $op = OperationComptable::withoutGlobalScopes()->where('source', 'suivi')->sole();
        $this->joindre('suivi', $piece);
        $ordre = [];
        Http::fake(function ($requete) use (&$ordre) {
            $ordre[] = parse_url($requete->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($requete->url(), '/referentiel/deverser') => Http::response(['success' => true]),
                str_ends_with($requete->url(), '/ecritures/deverser') => Http::response(['success' => true, 'count' => 3, 'ignorees' => 0, 'refus' => []]),
                str_ends_with($requete->url(), '/pieces-justificatives/deverser') => Http::response(['success' => true, 'idempotent' => false], 201),
            };
        });

        $bilan = (new DeversementComptable($this->e->id))->deverser();

        $this->assertSame(1, $bilan['acceptees']);
        $this->assertSame(1, $bilan['justificatifs']);
        $this->assertSame(['/api/external/gestionsites/referentiel/deverser', '/api/external/ecritures/deverser', '/api/external/pieces-justificatives/deverser'], $ordre,
            'le PDF part après les écritures, que COMPTAFLOW doit déjà avoir pour l\'y rattacher');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pieces-justificatives/deverser')
            && $r['operation_source'] === 'OP'.$op->id
            && $r['nom_fichier'] === 'FA-1.pdf'
            && $r['sha256'] === hash('sha256', self::PDF)
            && base64_decode($r['contenu_base64']) === self::PDF
            && $r->hasHeader('X-Company-Key', 'cle'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ecritures/deverser') && $r['ecritures'][0]['operation_source'] === 'OP'.$op->id);
        $op->refresh();
        $this->assertNotNull($op->justificatif_envoye_le);
        $this->assertNull($op->justificatif_message);

        // Envoyé une fois : le passage suivant ne le renvoie pas.
        Http::fake(['*' => Http::response(['success' => true], 201)]);
        $this->assertSame('Rien à déverser.', (new DeversementComptable($this->e->id))->deverser()['message']);
        Http::assertNothingSent();
    }

    public function test_un_409_se_garde_et_un_429_ne_coute_pas_d_essai(): void
    {
        Sleep::fake();
        $this->lier();
        $piece = $this->pieceDuSuivi();
        $this->generer();
        $op = OperationComptable::withoutGlobalScopes()->where('source', 'suivi')->sole();

        // Les faux de `Http::fake` s'additionnent d'un appel à l'autre : un seul, qui lit la scène.
        $scene = 'sature';
        $pieces = 0;
        Http::fake(function ($r) use (&$scene, &$pieces) {
            return match (true) {
                str_ends_with($r->url(), '/referentiel/deverser') => Http::response(['success' => true]),
                str_ends_with($r->url(), '/ecritures/deverser') => $scene === 'sature'
                    ? Http::response(['message' => 'Too Many Attempts.'], 429)
                    : Http::response(['success' => true, 'refus' => []]),
                default => (function () use (&$pieces) {
                    $pieces++;

                    return Http::response(['success' => false, 'message' => 'Une autre pièce est déjà rattachée à cette opération.'], 409);
                })(),
            };
        });

        // 429 sur les écritures : rien n'est arrivé, l'opération reprend son état sans essai consommé.
        $bilan = (new DeversementComptable($this->e->id))->deverser();
        $this->assertStringContainsString('limite le nombre d’appels', $bilan['message']);
        $op->refresh();
        $this->assertSame(['non_envoyee', 0], [$op->statut_deversement, (int) $op->essais]);

        // Accepté, puis COMPTAFLOW a déjà une autre pièce : 409 gardé, et le même PDF ne se renvoie pas.
        $scene = 'normal';
        $this->joindre('suivi', $piece);
        (new DeversementComptable($this->e->id))->deverser();
        $op->refresh();
        $this->assertSame('acceptee', $op->statut_deversement);
        $this->assertNull($op->justificatif_envoye_le);
        $this->assertStringContainsString('409', $op->justificatif_message);
        $this->assertSame(1, $pieces);

        (new DeversementComptable($this->e->id))->deverser();
        $this->assertSame(1, $pieces, 'le même PDF refusé ne repart pas');
    }

    public function test_un_refus_atomique_dit_ses_lignes(): void
    {
        Sleep::fake();
        $this->lier();
        $this->pieceDuSuivi();
        $this->generer();
        Http::fake([
            '*/referentiel/deverser' => Http::response(['success' => true]),
            '*/ecritures/deverser' => Http::response(['success' => false, 'refus' => ['FA-1 : journal « AC » inconnu'],
                'message' => 'Opération refusée en entier : 1 ligne(s) sur 3 n\'ont pas pu être rangées.'], 422),
        ]);

        (new DeversementComptable($this->e->id))->deverser();

        $op = OperationComptable::withoutGlobalScopes()->where('source', 'suivi')->sole();
        $this->assertSame('refusee', $op->statut_deversement);
        $this->assertStringContainsString('journal « AC » inconnu', $op->message_deversement);
    }

    private function lier(): void
    {
        config(['services.comptaflow.url' => 'https://comptaflow.test', 'services.comptaflow.secret' => 's']);
        DB::table('liaisons_comptaflow')->insert([
            'entreprise_id' => $this->e->id, 'comptaflow_company_id' => 7, 'cle_chiffree' => Crypt::encryptString('cle'), 'statut' => 'active',
        ]);
    }

    private function pieceDuSuivi(): int
    {
        return DB::table('factures_fournisseurs')->insertGetId([
            'entreprise_id' => $this->e->id, 'ville_id' => $this->ville->id, 'fournisseur' => 'CFAO', 'numero_piece' => 'FA-1',
            'date_facture' => '2026-03-01', 'montant' => 59000, 'montant_ht' => 50000, 'tva' => 9000,
        ]);
    }

    private function joindre(string $source, int $id): void
    {
        Storage::disk('local')->put('justificatifs-comptables/'.$this->e->id.'/'.$source.'-'.$id.'.pdf', self::PDF);
        DB::table('justificatifs_comptables')->insert([
            'entreprise_id' => $this->e->id, 'source' => $source, 'source_id' => $id,
            'chemin' => 'justificatifs-comptables/'.$this->e->id.'/'.$source.'-'.$id.'.pdf', 'nom_original' => 'FA-1.pdf',
            'sha256' => hash('sha256', self::PDF), 'taille' => strlen(self::PDF), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function generer(): void
    {
        (new GenerateurDEcritures($this->e->id))->generer('2026-01-01', '2026-12-31');
    }

    private function compte(string $role, string $nom, ?Entreprise $entreprise = null): User
    {
        $entreprise ??= $this->e;
        app(PermissionRegistrar::class)->setPermissionsTeamId($entreprise->id);
        $compte = User::create([
            'entreprise_id' => $entreprise->id, 'name' => ucfirst($nom), 'email' => $nom.'@'.$entreprise->slug.'.test',
            'password' => Hash::make('motdepasse123'), 'est_actif' => true, 'doit_changer_mot_de_passe' => false,
            'ville_id' => $entreprise->is($this->e) ? $this->ville->id : null, 'site_id' => $entreprise->is($this->e) ? $this->site->id : null,
        ]);
        $compte->assignRole($role);

        return $compte->fresh();
    }
}

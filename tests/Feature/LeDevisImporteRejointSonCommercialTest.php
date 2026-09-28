<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Entreprises\Services\ProvisionneurEntreprise;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\Devis;
use Modules\Noyau\Imports\Formats\FormatDesDevis;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\LotImport;
use Modules\Noyau\Imports\Services\CodesDesCommerciaux;
use Modules\Noyau\Imports\Services\Executeur;
use Spatie\Permission\PermissionRegistrar;
use Tests\ConstruitDesClasseurs;
use Tests\TestCase;

/**
 * Le devis importé porte le code de qui l'a rédigé, et rejoint son commercial quand ce
 * code en désigne un.
 *
 * **Le défaut, mesuré le 25/09 après une remarque du propriétaire** — « tous les devis ne
 * sont pas liés à un commercial, j'espère que tu l'as pris en compte ». Il avait raison,
 * et c'était pire que cela : **2 432 devis importés sur 2 432** n'avaient ni code ni
 * commercial, alors que leur numéro le dit tous — « PR-MT-11434 » porte MT.
 *
 * L'import extrayait pourtant le code : il s'en sert pour ranger la ligne dans sa ville.
 * Il le jetait ensuite. L'écran des codes, lui, affirmait que « c'est par elles que les
 * devis importés rejoignent leur commercial » — une promesse que rien ne tenait, et qui
 * empêchait de chercher la cause du manque.
 *
 * **Deux choses sont vérifiées ici, et elles sont différentes.** Le code est un **fait lu
 * dans le fichier** : il s'écrit toujours. Le commercial est une **conclusion**, qui
 * suppose qu'une personne ait relié ce code à un compte ; tant que ce lien n'existe pas,
 * le devis reste sans commercial — et c'est la bonne réponse, pas un échec.
 */
class LeDevisImporteRejointSonCommercialTest extends TestCase
{
    use ConstruitDesClasseurs;
    use RefreshDatabase;

    private Entreprise $entreprise;

    private Ville $ville;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        CodesDesCommerciaux::oublier();

        $this->entreprise = Entreprise::create(['nom' => 'Alpha', 'slug' => 'alpha']);
        ProvisionneurEntreprise::creerRoles($this->entreprise);

        $this->ville = Ville::create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'ABJ', 'nom' => 'Abidjan', 'est_actif' => true,
        ]);
        $this->site = Site::create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'code' => 'ABJ-1', 'nom' => 'Abidjan — Site 1', 'est_actif' => true,
        ]);
    }

    public function test_le_code_du_numero_de_proforma_s_ecrit_sur_le_devis(): void
    {
        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $devis = Devis::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('MT', $devis->code_auteur, 'Le code du rédacteur se lit dans le numéro.');
    }

    /**
     * Le code sans personne derrière ne désigne personne — et le dit en se taisant.
     */
    public function test_sans_lien_vers_un_compte_le_devis_reste_sans_commercial(): void
    {
        CodeAgent::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'code' => 'MT', 'libelle' => 'TOURE MACHIAMY',
        ]);

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $devis = Devis::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('MT', $devis->code_auteur);
        $this->assertNull(
            $devis->commercial_id,
            'Un code que personne n’a relié ne doit pas désigner un commercial au hasard.',
        );
    }

    public function test_relie_a_un_compte_le_devis_rejoint_son_commercial(): void
    {
        $commercial = $this->commercialRelieAuCode('MT', 'TOURE MACHIAMY');

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertSame(
            $commercial->id,
            Devis::withoutGlobalScopes()->firstOrFail()->commercial_id,
        );
    }

    /**
     * Un commercial déjà posé ne se laisse pas remplacer par la déduction du code.
     *
     * C'est le sens de la hiérarchie : ce qu'une personne a décidé vaut mieux que ce qu'un
     * code déduit. Sans cette garde, un redépôt du même fichier défaisait chaque soir le
     * travail de rattachement fait à la main dans la journée.
     */
    public function test_un_commercial_deja_pose_resiste_au_redepot(): void
    {
        $autre = Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'numero' => 'C-0002', 'nom' => 'Koffi Yao', 'statut' => 'Actif',
        ]);

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $devis = Devis::withoutGlobalScopes()->firstOrFail();
        $devis->forceFill(['commercial_id' => $autre->id])->save();

        // Le code désigne quelqu'un d'autre, et le fichier revient.
        $this->commercialRelieAuCode('MT', 'TOURE MACHIAMY');
        CodesDesCommerciaux::oublier();

        $this->importer([$this->enTete(), $this->ligne('PR-MT-11434')]);

        $this->assertSame(
            $autre->id,
            Devis::withoutGlobalScopes()->firstOrFail()->commercial_id,
            'Le rattachement décidé à la main doit survivre au redépôt.',
        );
    }

    /**
     * Le numéro de Bouaké, sans initiales, ne fabrique pas un code.
     *
     * « PR--13699 » — 36 devis bien réels. Leur inventer un code les attribuerait à
     * quelqu'un ; les rejeter perdrait 36 devis. On les garde sans code.
     */
    public function test_un_numero_sans_initiales_ne_fabrique_pas_de_code(): void
    {
        $this->importer([$this->enTete(), $this->ligne('PR--13699')]);

        $this->assertNull(Devis::withoutGlobalScopes()->firstOrFail()->code_auteur);
    }

    // ------------------------------------------------------------------ le décor

    private function commercialRelieAuCode(string $code, string $nom): Commercial
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->entreprise->id);

        $compte = User::create([
            'entreprise_id' => $this->entreprise->id, 'name' => $nom,
            'email' => mb_strtolower($code).'@alpha.test', 'password' => Hash::make('motdepasse123'),
            'ville_id' => $this->ville->id, 'site_id' => $this->site->id, 'est_actif' => true,
        ]);

        CodeAgent::withoutGlobalScopes()->updateOrCreate(
            ['entreprise_id' => $this->entreprise->id, 'code' => $code],
            ['libelle' => $nom, 'user_id' => $compte->id],
        );

        CodesDesCommerciaux::oublier();

        return Commercial::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id, 'ville_id' => $this->ville->id,
            'user_id' => $compte->id, 'numero' => 'C-0001', 'nom' => $nom, 'statut' => 'Actif',
        ]);
    }

    /** @return array<int, string> les intitulés du fichier réel, à la lettre */
    private function enTete(): array
    {
        return [
            'DATE DE LA PROFORMA', 'N° PROFORMA', 'FICHE DE RECEPTION', 'IMMATRICULATION VEHICULE',
            'NUMERO CHASSIS', 'MARQUE', 'MODELE', 'CODE CLIENT', 'CLIENTS', 'MONTANT PROFORMA',
        ];
    }

    /** @return array<int, mixed> */
    private function ligne(string $numero): array
    {
        return [
            '2026-03-04', $numero, 'FR-LAN° 010141', '1258KJ01',
            'CH-99', 'TOYOTA', 'HILUX', 'CL-12', 'AAL GROUP', 450000,
        ];
    }

    private function importer(array $lignes): LotImport
    {
        $chemin = $this->classeurXlsx(['Feuil1' => $lignes]);

        $lot = LotImport::withoutGlobalScopes()->create([
            'entreprise_id' => $this->entreprise->id,
            'ville_id' => $this->ville->id,
            'deposant' => 'K. Désirée',
            'format' => FormatDesDevis::cle(),
            'nom_fichier' => 'devis.xlsx',
            'empreinte' => hash('sha256', uniqid('', true)),
            'taille' => 1024,
            'etat' => 'depose',
        ]);

        Storage::disk(LotImport::DISQUE)->put($lot->cheminRelatif(), file_get_contents($chemin));

        (new Executeur($this->entreprise->id))->traiter($lot, FormatDesDevis::class);

        return $lot;
    }
}

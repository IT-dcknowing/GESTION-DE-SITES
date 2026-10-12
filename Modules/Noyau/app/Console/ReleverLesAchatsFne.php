<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Imports\Services\AchatsFne;

/**
 * Range les relevés d'achats FNE que le scraper a déposés — 12/10.
 *
 * `SCRAPER-PORTAIL-FNE/achats.js` (repris de Selflow) écrit dans
 * `storage/app/portail-fne/achats/<login>_<AAAAMMJJ>.json`. Cette commande lit ces fichiers
 * et range leurs factures, comme le dépôt à l'écran Fournisseurs › Factures FNE.
 *
 * L'entreprise se retrouve par son **NCC**, qui est le login du portail ; à défaut,
 * `--entreprise=`. Un fichier déjà lu (même empreinte) est sauté : la relancer ne double rien.
 *
 * Elle n'écrit que dans `releves_fne` et `factures_fne`, plus le lien de rapprochement — jamais
 * une dette ni un règlement : porter une facture au suivi reste un geste fait à l'écran. Elle
 * ne s'inscrit pas dans `app:deployer` (règle 1).
 */
class ReleverLesAchatsFne extends Command
{
    protected $signature = 'fne:releve-achats
        {fichier? : un relevé précis — tous ceux du dossier du scraper sinon}
        {--entreprise= : l-entreprise visée, par son identifiant (sinon retrouvée par le NCC du login)}';

    protected $description = 'Range les relevés d’achats FNE déposés par le scraper (avoirs compris, chacun dans son exercice)';

    public function handle(): int
    {
        $fichiers = $this->argument('fichier')
            ? [$this->argument('fichier')]
            : File::glob(storage_path('app/portail-fne/achats/*.json'));

        if ($fichiers === []) {
            $this->warn('Aucun relevé dans '.storage_path('app/portail-fne/achats').'.');

            return self::SUCCESS;
        }

        $echecs = 0;

        foreach ($fichiers as $fichier) {
            if (! is_file($fichier)) {
                $this->error("Fichier introuvable : {$fichier}");
                $echecs++;

                continue;
            }

            $contenu = (string) file_get_contents($fichier);
            $entreprise = $this->entreprise($contenu);

            if ($entreprise === null) {
                $this->error(basename($fichier).' : entreprise introuvable — précisez --entreprise=.');
                $echecs++;

                continue;
            }

            try {
                $bilan = AchatsFne::importer($contenu, basename($fichier), $entreprise->id);
            } catch (\RuntimeException $e) {
                $this->error(basename($fichier).' : '.$e->getMessage());
                $echecs++;

                continue;
            }

            $this->line($bilan['deja']
                ? basename($fichier).' : déjà lu, rien à faire.'
                : sprintf('%s : %d factures (%d nouvelles, dont %d avoirs), %d rapprochées du suivi, %d ignorées.',
                    basename($fichier), $bilan['factures'], $bilan['nouvelles'], $bilan['avoirs'], $bilan['rapprochees'], $bilan['ignorees']));
        }

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function entreprise(string $contenu): ?Entreprise
    {
        if ($this->option('entreprise')) {
            return Entreprise::query()->find((int) $this->option('entreprise'));
        }

        $login = trim((string) (json_decode($contenu, true)['login'] ?? ''));
        $parNcc = $login === '' ? collect() : Entreprise::query()->where('ncc', $login)->get();

        if ($parNcc->count() === 1) {
            return $parNcc->first();
        }

        // Une seule entreprise : pas d'ambiguïté possible.
        $toutes = Entreprise::query()->limit(2)->get();

        return $toutes->count() === 1 ? $toutes->first() : null;
    }
}

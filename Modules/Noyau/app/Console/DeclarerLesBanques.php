<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Exploitation\Modeles\Banque;
use Modules\Noyau\Exploitation\Services\GenerateurNumero;

/**
 * Déclare les comptes bancaires d'une entreprise — demandé le 07/10 : « crée les deux
 * banques, qu'elles soient disponibles ».
 *
 * L'entreprise a deux banques, **BGFI** et **AFG**. Elles se déclarent d'ordinaire sur l'écran
 * Banques, par le gérant ; cette commande fait le même geste, avec les mêmes gardes, pour
 * qu'elles soient là avant le premier dépôt d'un relevé.
 *
 * **Les noms sont ceux qu'écrivent les factures** — « BGFI » 5 472 fois, « AFG » 77 fois, relevé
 * le 01/10 —, et pas « BGFI BANK » : la reconnaissance exige que tous les mots du nom
 * paraissent dans le libellé, et « BGFI BANK » ne serait reconnu dans aucune des 5 472.
 *
 * **Elle écrit dans une base réelle** : par défaut elle dit ce qu'elle ferait et n'écrit rien
 * (règle 1). Elle ne crée jamais une banque déjà déclarée sous une forme voisine. Elle ne
 * s'inscrit pas dans `app:deployer`.
 */
class DeclarerLesBanques extends Command
{
    protected $signature = 'banques:declarer
        {noms?* : les noms à déclarer — BGFI et AFG si rien n-est donné}
        {--entreprise= : l-entreprise visée, par son identifiant (obligatoire s-il y en a plusieurs)}
        {--appliquer : écrit réellement}';

    protected $description = 'Déclare des comptes bancaires — BGFI et AFG par défaut (constat par défaut)';

    public function handle(): int
    {
        $entreprises = Entreprise::query()
            ->when($this->option('entreprise'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        if ($entreprises->count() !== 1) {
            $this->error($entreprises->isEmpty()
                ? 'Aucune entreprise ne correspond.'
                : 'Plusieurs entreprises : précisez --entreprise= ('.$entreprises->map(fn ($e) => $e->id.' '.$e->nom)->implode(', ').').');

            return self::FAILURE;
        }

        $entreprise = $entreprises->first();
        $appliquer = (bool) $this->option('appliquer');
        $noms = $this->argument('noms') ?: ['BGFI', 'AFG'];

        if (! $appliquer) {
            $this->warn('Mode constat : rien ne sera écrit. Ajoutez --appliquer pour enregistrer.');
        }

        $deja = Banque::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)->pluck('nom', 'nom_normalise');
        $lignes = [];

        foreach ($noms as $nom) {
            $cle = Banque::clePour($nom);

            if ($cle === '' || preg_match('/[A-Z]/', $cle) !== 1) {
                $lignes[] = [$nom, 'refusée : ce n’est pas un nom de banque'];

                continue;
            }

            if (isset($deja[$cle])) {
                $lignes[] = [$nom, 'déjà déclarée ('.$deja[$cle].')'];

                continue;
            }

            if ($appliquer) {
                Banque::withoutGlobalScopes()->create([
                    'entreprise_id' => $entreprise->id,
                    'nom' => Banque::formePresentable($nom),
                    'nom_normalise' => $cle,
                    'type' => Banque::BANQUE,
                    // Le code est posé par le système, comme sur l'écran Banques.
                    'code' => GenerateurNumero::suivant((int) $entreprise->id, Banque::SERIE),
                    'note' => 'Déclarée par la commande banques:declarer le '.now()->format('d/m/Y').'.',
                ]);
                $deja[$cle] = $nom;
            }

            $lignes[] = [$nom, $appliquer ? 'créée' : 'serait créée'];
        }

        $this->info("— {$entreprise->nom}");
        $this->table(['Banque', 'Suite'], $lignes);

        return self::SUCCESS;
    }
}

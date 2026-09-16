<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Site;

/**
 * Pose la ville des factures déjà en base, là où elle se déduit sans rien supposer.
 *
 * La colonne `factures.ville_id` est née le 16/09/2026 ; les lignes écrites avant elle sont
 * vides. Une seule source est retenue ici : **l'atelier de la facture**. Un atelier appartient
 * à une ville, la déduction est certaine.
 *
 * **Ce qu'elle ne fait pas, délibérément.** Elle ne devine pas la ville d'une facture sans
 * atelier. Le seul indice en base serait la ville déclarée au dépôt du fichier, et elle ne
 * vaut rien ligne à ligne : le classeur des impayés a été déposé « Abidjan » et couvre toute
 * l'entreprise. Ces lignes-là reçoivent leur ville du fichier lui-même, en le redéposant —
 * l'import écrit désormais la ville que sa colonne SITE désigne.
 *
 * Elle n'écrase aucune ville déjà posée, ne crée et ne supprime rien, et n'écrit pas par le
 * modèle : la donnée ne change pas de sens, et marquer des milliers de factures « modifiées
 * aujourd'hui » effacerait la trace de leur dernière vraie modification.
 *
 * Sans `--appliquer`, elle dit ce qu'elle ferait et n'écrit pas une ligne.
 */
class PoserLaVilleDesFactures extends Command
{
    protected $signature = 'factures:poser-la-ville
        {--appliquer : écrit réellement les modifications}
        {--entreprise= : ne traiter qu-une entreprise, par son identifiant}';

    protected $description = "Pose la ville des factures d'après leur atelier (constat par défaut)";

    public function handle(): int
    {
        $appliquer = (bool) $this->option('appliquer');

        if (! $appliquer) {
            $this->warn('Mode constat : rien ne sera écrit. Ajoutez --appliquer pour enregistrer.');
        }

        $lignes = [];
        $total = 0;

        $sites = Site::withoutGlobalScopes()
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('entreprise_id', (int) $id))
            ->whereNotNull('ville_id')
            ->orderBy('id')
            ->get(['id', 'nom', 'ville_id', 'entreprise_id']);

        foreach ($sites as $site) {
            $requete = DB::table('factures')
                ->where('entreprise_id', $site->entreprise_id)
                ->where('site_id', $site->id)
                ->whereNull('ville_id');

            $combien = (clone $requete)->count();

            if ($combien === 0) {
                continue;
            }

            if ($appliquer) {
                $requete->update(['ville_id' => $site->ville_id]);
            }

            $lignes[] = [$site->nom, $combien];
            $total += $combien;
        }

        $sansRien = DB::table('factures')
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('entreprise_id', (int) $id))
            ->whereNull('site_id')
            ->whereNull('ville_id')
            ->count();

        $this->table(['Atelier', 'Factures qui reçoivent sa ville'], [...$lignes, ['TOTAL', $total]]);

        $this->newLine();
        $this->line("Factures sans atelier ni ville, laissées telles quelles : {$sansRien}.");
        $this->line('Elles reçoivent leur ville en redéposant leur fichier (colonne SITE).');

        if (! $appliquer) {
            $this->newLine();
            $this->warn("Rien n'a été écrit. Relancez avec --appliquer.");
        }

        return self::SUCCESS;
    }
}

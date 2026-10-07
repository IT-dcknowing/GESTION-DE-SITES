<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Services\PontDesFactures;

/**
 * Compte les factures écrites deux fois — une par le CATTC, une par l'état des impayés.
 *
 * Demandé le 07/10 : « il ne faudrait pas que j'aie des doublons avec les factures que je vais
 * importer ». Les imports se reconnaissent désormais par une clé forte (`PontDesFactures`).
 * Cette commande dit, **sur la base réelle**, ce que cette clé retrouve et ce qu'elle laisse :
 *
 * - les paires déjà reconnues (`n_facture_cattc` posé) ;
 * - les **doublons probables** : une facture du CATTC et une créance de l'état, même date, même
 *   montant, même plaque, un seul candidat de chaque côté — ce qu'un import fait après le
 *   07/10 aurait fusionné ;
 * - les **ambiguës** : plusieurs candidats pour une même clé — on ne choisit pas ;
 * - le **montant** que les doublons probables ajoutent à tort au chiffre d'affaires.
 *
 * **Elle n'écrit rien, jamais** : pas d'option qui fusionne. Défaire un doublon déjà en base
 * touche aux règlements et aux commerciaux de deux lignes ; c'est une décision qui se prend
 * sur ce constat, pas une commande qu'on lance.
 */
class CompterLesDoublonsDuCa extends Command
{
    protected $signature = 'factures:doublons
        {--entreprise= : ne compter qu-une entreprise, par son identifiant}';

    protected $description = "Compte les factures écrites à la fois par le CATTC et par l'état des impayés (lecture seule)";

    public function handle(): int
    {
        $entreprises = DB::table('entreprises')
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('id', (int) $id))
            ->orderBy('id')->get(['id', 'nom']);

        foreach ($entreprises as $entreprise) {
            $creances = [];
            $cattc = [];

            $lignes = DB::table('factures')
                ->where('entreprise_id', $entreprise->id)
                ->select('id', 'date', 'montant', 'immatriculation', 'est_etat_initial', 'n_facture_cattc')
                ->cursor();

            $reconnues = 0;

            foreach ($lignes as $f) {
                if ($f->n_facture_cattc !== null) {
                    $reconnues++;

                    continue;
                }

                $cle = PontDesFactures::cle($f->date, $f->montant, $f->immatriculation);

                if ($cle === null) {
                    continue;
                }

                if ($f->est_etat_initial) {
                    $creances[$cle][] = (int) $f->montant;
                } else {
                    $cattc[$cle][] = (int) $f->montant;
                }
            }

            $probables = 0;
            $ambigues = 0;
            $montant = 0;

            foreach ($cattc as $cle => $montants) {
                if (! isset($creances[$cle])) {
                    continue;
                }

                if (count($montants) === 1 && count($creances[$cle]) === 1) {
                    $probables++;
                    $montant += $montants[0];
                } else {
                    $ambigues++;
                }
            }

            $this->info("— {$entreprise->nom}");
            $this->table(['Constat', 'Nombre'], [
                ['Paires déjà reconnues (n_facture_cattc)', number_format($reconnues, 0, ',', ' ')],
                ['Doublons probables (clé forte, un seul candidat)', number_format($probables, 0, ',', ' ')],
                ['Montant compté deux fois au CA par ces doublons', number_format($montant, 0, ',', ' ').' F'],
                ['Clés ambiguës (plusieurs candidats, on ne choisit pas)', number_format($ambigues, 0, ',', ' ')],
            ]);
        }

        $this->line('Rien n\'a été écrit.');

        return self::SUCCESS;
    }
}

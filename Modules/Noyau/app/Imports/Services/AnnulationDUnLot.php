<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Modeles\LotImport;
use RuntimeException;

/**
 * Défaire un import — celui qui a réussi.
 *
 * **La nuance que la barre latérale laissait dans le flou.** « Aucun import n'écrit à
 * moitié » parle de l'import qui *échoue* : tout le parcours tient dans une transaction, et
 * une panne au milieu de neuf mille lignes ramène la base exactement à l'état d'avant. Ça,
 * c'était vrai et c'est resté vrai.
 *
 * Mais cette phrase ne disait rien du cas bien plus fréquent : **l'import qui réussit alors
 * qu'il n'aurait pas dû** — mauvais fichier, mauvaise ville, mauvais mois. Là, la
 * transaction a fait son travail : elle a tout enregistré. Et il n'existait aucun moyen de
 * revenir en arrière. C'est cette classe.
 *
 * **Ce qu'elle défait, et ce qu'elle ne défait pas.** Chaque ligne écrite par un import
 * porte le numéro de son lot. Les lignes **créées** par ce lot sont supprimées : elles
 * n'existaient pas avant, elles n'existeront plus après. Les lignes **mises à jour** par ce
 * lot ne peuvent pas être défaites, parce que leur valeur d'avant n'a jamais été conservée
 * — et prétendre le contraire serait un mensonge coûteux. Le compte des unes et des autres
 * est annoncé avant qu'on ne décide.
 *
 * **Rien n'est supprimé qui ait été touché depuis.** Une facture importée puis encaissée à
 * la main porte un règlement que personne n'a demandé de perdre. Ces lignes sont laissées
 * en place et comptées à part : c'est à un humain de trancher, ligne par ligne.
 */
class AnnulationDUnLot
{
    /**
     * Les tables qu'un import remplit, et la colonne qui dit d'où vient chaque ligne.
     *
     * Écrite ici plutôt que découverte à l'exécution : une table oubliée laisserait des
     * lignes orphelines derrière une annulation qui se dit complète, et c'est le genre de
     * silence qu'on paie six mois plus tard.
     */
    public const TABLES = [
        'dossiers_vehicules' => 'Fiches de réception',
        'devis' => 'Devis',
        'factures' => 'Factures',
        'encaissements' => 'Encaissements',
        'charges' => 'Charges',
        'factures_fournisseurs' => 'Factures fournisseurs',
        'mouvements_caisse' => 'Mouvements de caisse',
        'mouvements_vehicules' => 'Entrées et sorties',
    ];

    public function __construct(private int $entrepriseId) {}

    /**
     * Ce que l'annulation ferait, sans rien faire.
     *
     * @return array{total: int, retenues: int, par_table: array<string, array{libelle: string, supprimables: int, retenues: int}>}
     */
    public function apercu(LotImport $lot): array
    {
        $this->verifierLeLot($lot);

        $par = [];
        $total = 0;
        $retenues = 0;

        foreach (self::TABLES as $table => $libelle) {
            $base = DB::table($table)
                ->where('entreprise_id', $this->entrepriseId)
                ->where('lot_import_id', $lot->id);

            $toutes = (clone $base)->count();

            if ($toutes === 0) {
                continue;
            }

            $gardees = (clone $base)->whereColumn('updated_at', '>', 'created_at')->count();

            $par[$table] = [
                'libelle' => $libelle,
                'supprimables' => $toutes - $gardees,
                'retenues' => $gardees,
            ];

            $total += $toutes - $gardees;
            $retenues += $gardees;
        }

        return ['total' => $total, 'retenues' => $retenues, 'par_table' => $par];
    }

    /**
     * Supprime les lignes créées par ce lot et rend le compte de ce qui est parti.
     *
     * @return array{supprimees: int, retenues: int}
     */
    public function annuler(LotImport $lot, string $motif, int $parUserId): array
    {
        $this->verifierLeLot($lot);

        $apercu = $this->apercu($lot);

        DB::transaction(function () use ($lot, $motif, $parUserId, $apercu) {
            foreach (array_keys($apercu['par_table']) as $table) {
                DB::table($table)
                    ->where('entreprise_id', $this->entrepriseId)
                    ->where('lot_import_id', $lot->id)
                    // Une ligne retouchée depuis l'import porte un travail qui n'est pas
                    // celui de l'import : elle reste, et l'écran dit combien.
                    ->whereColumn('updated_at', '<=', 'created_at')
                    ->delete();
            }

            $lot->forceFill([
                'etat' => 'annule',
                'message' => sprintf(
                    'Import annulé : %d ligne(s) supprimée(s), %d retenue(s) parce que retouchées depuis. Motif : %s',
                    $apercu['total'],
                    $apercu['retenues'],
                    mb_substr(trim($motif), 0, 200) ?: 'non précisé',
                ),
                'annule_le' => now(),
                'annule_par' => $parUserId,
            ])->save();
        });

        return ['supprimees' => $apercu['total'], 'retenues' => $apercu['retenues']];
    }

    private function verifierLeLot(LotImport $lot): void
    {
        if ((int) $lot->entreprise_id !== $this->entrepriseId) {
            throw new RuntimeException("Ce dépôt n'appartient pas à votre entreprise.");
        }

        if ($lot->etat === 'annule') {
            throw new RuntimeException('Ce dépôt a déjà été annulé.');
        }

        if ($lot->etat !== 'termine') {
            throw new RuntimeException(
                "Seul un import terminé s'annule. Un import qui a échoué n'a rien écrit, "
                ."et un import en cours doit d'abord finir."
            );
        }
    }
}

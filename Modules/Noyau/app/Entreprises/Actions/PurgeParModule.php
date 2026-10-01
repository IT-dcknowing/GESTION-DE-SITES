<?php

namespace Modules\Noyau\Entreprises\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Noyau\Entreprises\Modeles\Entreprise;
use Modules\Noyau\Imports\Modeles\LotImport;
use RuntimeException;

/**
 * Vider ce qu'on désigne, module par module.
 *
 * **La demande, du 01/10** : *« Au niveau du superadmin, voici ce que je veux pour la page
 * maintenance : fais des cases à cocher des pages ayant des données, et dès que les pages
 * seront cochées et supprimées, les données seront supprimées — chiffre d'affaires, devis,
 * commerciaux, caisse, banque, trésorerie, prospection… Si possible, classer par module, et
 * un bouton tout cocher au niveau de chaque module. »*
 *
 * **Ce que cela ajoute à la purge qui existait.** `PurgerDonneesEntreprise` vide **tout** :
 * c'est le geste de la fin d'essai. On ne pouvait pas vider les seules prospections pour
 * rejouer un import, ni les seules pièces bancaires après un relevé mal déposé — il fallait
 * tout reprendre, y compris ce qui était juste. Les deux gestes restent, et ils ne se
 * confondent pas : celui-là choisit, l'autre ne choisit pas.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * **Trois garde-fous, et aucun n'est décoratif. La base en ligne porte des données réelles.**
 *
 * 1. **Rien n'est supprimé qui n'ait été compté d'abord.** `volumes()` rend le détail ligne
 *    à ligne, et l'écran le montre dans sa boîte de confirmation : on lit ce qu'on s'apprête
 *    à perdre avant de le perdre, pas après.
 * 2. **Les entraînements sont déclarés.** Supprimer les devis met à `null` le `devis_id` des
 *    factures ; supprimer les prospections fait de même sur les devis. Ce n'est pas une perte
 *    de lignes, c'est une perte de **lien**, et elle est annoncée par `entraine()` plutôt que
 *    découverte après coup.
 * 3. **Une transaction, et les fichiers du disque en dernier.** Supprimer un fichier ne
 *    s'annule pas : un `ROLLBACK` survenu entre les deux laisserait des lots sans leur
 *    fichier, ce qui est plus grave que l'inverse.
 *
 * **Ce que ce service ne touche jamais** : l'organisation — villes, lieux, accès, rôles,
 * exercices — et le journal d'activité, qui est la trace de ce qui a été fait sur la
 * plateforme, y compris de cette purge. Pour ne rien laisser du tout, c'est la suppression
 * d'entreprise qu'il faut.
 */
class PurgeParModule
{
    /**
     * Les lots qu'on peut vider, rangés par module.
     *
     * **Un « lot » est ce qu'un écran montre**, et non une table : c'est la façon dont le
     * propriétaire a posé la demande — « des cases à cocher des pages ayant des données ».
     * « Caisse » vide le journal *et* ses soldes d'ouverture, parce qu'un journal sans ses
     * ouvertures laisserait l'écran annoncer un solde d'avant période qui ne repose plus sur
     * rien.
     *
     * **L'ordre des tables d'un lot est celui des clefs étrangères**, des feuilles vers la
     * racine : ce qui pend à une ligne part avant elle.
     *
     * @var array<string, array{libelle: string, lots: array<string, array{libelle: string, ecran: string, tables: array<int, string>, entraine?: string}>}>
     */
    public const MODULES = [
        'exploitation' => [
            'libelle' => 'Exploitation',
            'lots' => [
                'prospections' => [
                    'libelle' => 'Prospections',
                    'ecran' => 'Prospects',
                    'tables' => ['rapprochements_ecartes', 'prospections'],
                    'entraine' => 'Les devis gardent leur ligne, mais perdent le lien vers leur prospection — '
                        .'donc le commercial qui l’avait apportée.',
                ],
                'devis' => [
                    'libelle' => 'Devis',
                    'ecran' => 'Devis',
                    'tables' => ['ecarts_devis_facture', 'devis'],
                    'entraine' => 'Les factures gardent leur ligne, mais perdent le lien vers leur devis.',
                ],
                'factures' => [
                    'libelle' => 'Factures — chiffre d’affaires et état des impayés',
                    'ecran' => 'Chiffre d’affaires · Impayés',
                    'tables' => ['factures'],
                    'entraine' => 'Les encaissements gardent leur ligne, mais ne soldent plus rien : '
                        .'ils deviennent de l’argent reçu sans créance en face.',
                ],
                'encaissements' => [
                    'libelle' => 'Encaissements',
                    'ecran' => 'Trésorerie · Journal des encaissements',
                    'tables' => ['encaissements'],
                ],
                'charges' => [
                    'libelle' => 'Charges et décaissements',
                    'ecran' => 'Charges · Trésorerie',
                    'tables' => ['charges'],
                ],
                'saisies' => [
                    'libelle' => 'Saisies journalières',
                    'ecran' => 'Saisie du jour',
                    'tables' => ['saisies_journalieres'],
                ],
                'commerciaux' => [
                    'libelle' => 'Fiches commerciales',
                    'ecran' => 'Commerciaux',
                    'tables' => ['commerciaux'],
                    'entraine' => 'Les devis et factures gardent leur ligne, mais ne sont plus comptés à personne — '
                        .'et aucune commission ne se calcule plus.',
                ],
            ],
        ],

        'recouvrement' => [
            'libelle' => 'Recouvrement',
            'lots' => [
                'relances' => [
                    'libelle' => 'Relances tracées',
                    'ecran' => 'Journal des relances',
                    'tables' => ['relances_recouvrement'],
                ],
                'ecarts' => [
                    'libelle' => 'Commentaires d’écart',
                    'ecran' => 'Synthèse & pilotage',
                    'tables' => ['commentaires_ecart_recouvrement'],
                ],
                'tiers' => [
                    'libelle' => 'Tiers et leurs codes',
                    'ecran' => 'Clients & tiers',
                    'tables' => ['tiers'],
                ],
            ],
        ],

        'parc' => [
            'libelle' => 'Parc et atelier',
            'lots' => [
                'fiches' => [
                    'libelle' => 'Fiches de réception',
                    'ecran' => 'Parc de véhicules',
                    'tables' => ['notes_vehicule', 'dossiers_vehicules'],
                ],
                'mouvements' => [
                    'libelle' => 'Entrées et sorties de véhicules',
                    'ecran' => 'Mouvements de véhicules',
                    'tables' => ['mouvements_vehicules'],
                ],
            ],
        ],

        'tresorerie' => [
            'libelle' => 'Caisse et banques',
            'lots' => [
                'caisse' => [
                    'libelle' => 'Journal de caisse',
                    'ecran' => 'Caisse — vue importée',
                    'tables' => ['ouvertures_caisse', 'mouvements_caisse'],
                ],
                'pieces_bancaires' => [
                    'libelle' => 'Pièces bancaires',
                    'ecran' => 'Banques',
                    'tables' => ['pieces_bancaires'],
                ],
                'banques' => [
                    'libelle' => 'Comptes déclarés — banques et portefeuilles',
                    'ecran' => 'Banques',
                    'tables' => ['banques'],
                    'entraine' => 'Les pièces bancaires de ces comptes partent avec eux. Les encaissements et '
                        .'les charges gardent leur ligne, mais ne désignent plus de compte.',
                ],
            ],
        ],

        'fournisseurs' => [
            'libelle' => 'Fournisseurs',
            'lots' => [
                'factures_fournisseurs' => [
                    'libelle' => 'Factures fournisseurs',
                    'ecran' => 'Fournisseurs',
                    'tables' => ['factures_fournisseurs'],
                ],
                'balance' => [
                    'libelle' => 'Balance fournisseurs',
                    'ecran' => 'Balance fournisseurs',
                    'tables' => ['soldes_fournisseur'],
                ],
                'reglements_fournisseurs' => [
                    'libelle' => 'Règlements fournisseurs',
                    'ecran' => 'Règlements fournisseurs',
                    'tables' => ['reglements_fournisseur'],
                ],
                'referentiel' => [
                    'libelle' => 'Conditions de règlement',
                    'ecran' => 'Conditions de règlement',
                    'tables' => ['referentiel_fournisseurs'],
                ],
            ],
        ],

        'imports' => [
            'libelle' => 'Imports',
            'lots' => [
                'lots' => [
                    'libelle' => 'Dépôts de fichiers, et les fichiers eux-mêmes',
                    'ecran' => 'Journal des imports',
                    'tables' => ['corrections_import', 'lignes_rejetees_import', 'lots_import'],
                    'entraine' => 'Les lignes déjà importées restent, mais perdent la trace du fichier qui les a '
                        .'apportées. En contrepartie, ce fichier redevient déposable : son empreinte ne '
                        .'fait plus obstacle.',
                ],
                'correspondances' => [
                    'libelle' => 'Réglages de rattachement des imports',
                    'ecran' => 'Codes d’atelier',
                    'tables' => ['correspondances_import'],
                ],
                'donnees_libres' => [
                    'libelle' => 'Informations libres des fichiers',
                    'ecran' => 'Détail d’une ligne importée',
                    'tables' => ['donnees_libres'],
                ],
            ],
        ],
    ];

    /**
     * Ce que chaque lot porte, pour cette entreprise.
     *
     * **Compté avant tout, et montré avant tout.** C'est ce qui permet à l'écran de dire ce
     * qu'il va détruire plutôt que de le dire après. Une table absente du schéma rend zéro et
     * ne fait pas tomber l'écran : un module désinstallé ne doit pas interdire d'en vider un
     * autre.
     *
     * @return array<string, int>
     */
    public static function volumes(int $entrepriseId): array
    {
        $volumes = [];

        foreach (self::MODULES as $module) {
            foreach ($module['lots'] as $cle => $lot) {
                $volumes[$cle] = 0;

                foreach ($lot['tables'] as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'entreprise_id')) {
                        continue;
                    }

                    $volumes[$cle] += DB::table($table)->where('entreprise_id', $entrepriseId)->count();
                }
            }
        }

        return $volumes;
    }

    /** Tous les lots connus, à plat : clé => déclaration. */
    public static function lots(): array
    {
        $lots = [];

        foreach (self::MODULES as $module) {
            foreach ($module['lots'] as $cle => $lot) {
                $lots[$cle] = $lot;
            }
        }

        return $lots;
    }

    /**
     * Vide les lots demandés, et rend ce qui a été supprimé.
     *
     * **Les fichiers du disque partent après la transaction, et c'est délibéré** : supprimer
     * un fichier ne s'annule pas. Un `ROLLBACK` survenu entre les deux laisserait des lots
     * sans leur fichier — bien plus grave que l'inverse, qui ne coûte qu'un peu de place.
     *
     * @param  array<int, string>  $cles
     * @return array<string, int>
     */
    public function executer(Entreprise $entreprise, array $cles): array
    {
        $connus = self::lots();
        $choisis = array_values(array_intersect($cles, array_keys($connus)));

        if ($choisis === []) {
            throw new RuntimeException('Aucun ensemble de données n’a été choisi.');
        }

        $bilan = DB::transaction(function () use ($entreprise, $choisis, $connus) {
            $compte = [];

            foreach ($choisis as $cle) {
                $supprimees = 0;

                foreach ($connus[$cle]['tables'] as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'entreprise_id')) {
                        continue;
                    }

                    $supprimees += DB::table($table)
                        ->where('entreprise_id', $entreprise->id)
                        ->delete();
                }

                $compte[$connus[$cle]['libelle']] = $supprimees;
            }

            return $compte;
        });

        // Hors transaction, et à dessein — voir le commentaire de la méthode.
        if (in_array('lots', $choisis, true)) {
            $bilan['fichiers effacés du disque'] = LotImport::effacerLesFichiersDe((int) $entreprise->id);
        }

        return $bilan;
    }
}

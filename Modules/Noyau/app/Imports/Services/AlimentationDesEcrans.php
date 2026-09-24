<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\LotImport;

/**
 * Quel fichier alimente quelle page.
 *
 * **La question à laquelle cette classe répond.** Huit fichiers sortent du logiciel
 * d'atelier, une quinzaine d'écrans en vivent, et le lien entre les deux n'était écrit
 * nulle part. Conséquence pratique : devant une balance âgée vide, personne ne pouvait
 * dire s'il manquait les factures, les impayés, ou les deux. On redéposait au hasard.
 *
 * Le tableau se lit dans les deux sens, et c'est ce qui le rend utile :
 *
 * - **d'un fichier vers les écrans** — « si je dépose l'état des impayés, qu'est-ce qui
 *   bouge ? » ;
 * - **d'un écran vers son fichier** — « cette page est vide, que dois-je déposer ? »
 *
 * **Les dépendances sont dites, parce qu'elles se paient.** Le parc fonde la fiche de
 * réception que le devis et la facture citent ; les impayés apportent les règlements, qui
 * n'ont de sens qu'en regard d'une facture. Importer dans le désordre ne casse rien — la
 * cascade de rattachement rattrape ce qu'elle peut — mais laisse des lignes rattachées à
 * la ville seulement, là où elles auraient pu l'être à l'atelier.
 *
 * Les compteurs sont **mesurés en base à chaque affichage**, jamais recopiés : un tableau
 * de correspondances qui se désaligne du réel est pire qu'une absence de tableau.
 */
class AlimentationDesEcrans
{
    /**
     * Ce que chaque format écrit, et qui s'en sert.
     *
     * `tables` sert au comptage ; `ecrans` porte des noms de route, résolus à l'affichage
     * — un lien mort dans un tableau d'aide vaut aveu que le tableau n'est plus tenu.
     */
    private const CARTE = [
        'parc' => [
            'apporte' => 'La fiche de réception de chaque véhicule : le client, le véhicule, la date, l\'atelier.',
            'tables' => ['dossiers_vehicules'],
            'ecrans' => [
                ['route' => 'parc-vehicules', 'libelle' => 'Parc véhicules'],
                ['route' => 'clients', 'libelle' => 'Clients de l\'entreprise'],
            ],
            'prealable' => null,
            'consequence' => "C'est le socle. Sans lui, les devis et les factures citent des fiches "
                ."que la base ne connaît pas, et restent rattachés à la ville sans descendre à l'atelier.",
        ],
        'devis' => [
            'apporte' => 'Les proformas établies, avec leur montant, leur date et le commercial qui les a rédigées.',
            'tables' => ['devis'],
            'ecrans' => [
                ['route' => 'devis', 'libelle' => 'Devis'],
                ['route' => 'commerciaux', 'libelle' => 'Commerciaux & objectifs'],
            ],
            'prealable' => 'parc',
            'consequence' => "Le rattachement d'un devis à son commercial passe par le code de deux "
                .'lettres du numéro de proforma : un code non renseigné laisse le devis sans auteur.',
        ],
        'factures' => [
            'apporte' => 'Le chiffre d\'affaires facturé : le client, le montant, la date, l\'activité.',
            'tables' => ['factures'],
            'ecrans' => [
                ['route' => 'chiffre-affaires', 'libelle' => 'Chiffre d\'affaires'],
                ['route' => 'recouvrement.balance', 'libelle' => 'Balance âgée'],
                ['route' => 'recouvrement.clients', 'libelle' => 'Clients & tiers'],
                ['route' => 'recouvrement.extrait', 'libelle' => 'Extrait de compte'],
                ['route' => 'recouvrement.synthese', 'libelle' => 'Synthèse du recouvrement'],
            ],
            'prealable' => 'parc',
            'consequence' => 'Une facture crée la créance. Tout le recouvrement en découle : '
                ."sans factures importées, la balance âgée est vide, et ce n'est pas une anomalie.",
        ],
        'impayes' => [
            'apporte' => "L'état des règlements reçus, et donc le reste à devoir de chaque facture.",
            'tables' => ['encaissements'],
            'ecrans' => [
                ['route' => 'recouvrement.balance', 'libelle' => 'Balance âgée'],
                ['route' => 'recouvrement.courtiers', 'libelle' => 'Courtiers'],
                ['route' => 'recouvrement.encaissements', 'libelle' => 'Journal des encaissements'],
                ['route' => 'recouvrement.synthese', 'libelle' => 'Synthèse du recouvrement'],
                ['route' => 'tresorerie', 'libelle' => 'Trésorerie'],
            ],
            'prealable' => 'factures',
            'consequence' => 'À déposer après les factures, jamais avant : un règlement sans facture '
                ."en face n'a rien à diminuer, et l'encours reste faux jusqu'au dépôt suivant.",
        ],
        'fournisseurs' => [
            'apporte' => 'Les factures des fournisseurs, leur nature et leur règlement.',
            'tables' => ['factures_fournisseurs'],
            'ecrans' => [
                ['route' => 'charges', 'libelle' => 'Charges'],
                ['route' => 'tresorerie', 'libelle' => 'Trésorerie'],
                ['route' => 'caissier.decaissements', 'libelle' => 'Décaissements'],
            ],
            'prealable' => null,
            'consequence' => "Indépendant du parc : une charge n'a pas besoin d'une fiche de réception.",
        ],
        /*
         * **La caisse ne remplit pas la Trésorerie, et cette ligne le disait de travers.**
         *
         * Corrigé le 24/09, après une question du propriétaire : il dépose le journal de
         * Bouaké, la page *Caisse* se remplit, la page *Trésorerie* reste vide, et il
         * demande si c'est normal. Ça l'est — mais ce tableau annonçait le contraire, et
         * c'est lui qui l'avait induit en erreur.
         *
         * Les deux écrans ne lisent pas la même table, parce qu'ils ne répondent pas à la
         * même question. *Caisse* lit `mouvements_caisse` : **ce que la caisse du logiciel
         * comptable a enregistré**. *Trésorerie* lit `encaissements` et `charges` : **ce
         * que l'application a encaissé et décaissé elle-même**. Les fondre ferait compter
         * deux fois l'argent d'Abidjan, qui a les deux.
         */
        'caisse' => [
            'apporte' => 'Les mouvements d\'espèces du classeur tenu à la main, dans les deux sens, '
                .'avec le solde annoncé.',
            'tables' => ['mouvements_caisse'],
            'ecrans' => [
                ['route' => 'caisse', 'libelle' => 'Caisse'],
                ['route' => 'caisse.vehicule', 'libelle' => 'Caisse par véhicule'],
            ],
            'prealable' => null,
            'consequence' => "Une caisse n'a pas de numéro de pièce : le rapprochement se fait sur la "
                .'ligne entière — date, sens, montant, libellé — ce qui protège du redépôt du même mois. '
                ."Il ne remplit pas la Trésorerie : celle-ci compte ce que l'application encaisse et "
                .'décaisse elle-même, la caisse compte ce que le logiciel comptable a enregistré.',
        ],
        /*
         * Le même contenu, par l'autre document. Bouaké et San-Pédro ne tiennent pas de
         * classeur : elles n'ont que l'état imprimé, et c'est le seul format de la maison
         * lu dans un PDF.
         */
        'journal-caisse' => [
            'apporte' => "Les mêmes mouvements d'espèces, lus dans l'état imprimé par le logiciel "
                ."comptable — plus le n° de pièce, le motif codifié, le remettant et le solde d'ouverture.",
            'tables' => ['mouvements_caisse'],
            'ecrans' => [
                ['route' => 'caisse', 'libelle' => 'Caisse'],
                ['route' => 'caisse.vehicule', 'libelle' => 'Caisse par véhicule'],
            ],
            'prealable' => null,
            'consequence' => "Même destination que le classeur : c'est la même caisse, par un autre "
                ."document. Abidjan tient un classeur, Bouaké et San-Pédro n'ont que ce journal.",
        ],
        'balance-fournisseurs' => [
            'apporte' => 'Le débit, le crédit et le solde de chaque fournisseur, tels que le logiciel '
                .'comptable les arrête.',
            'tables' => ['soldes_fournisseur'],
            'ecrans' => [
                ['route' => 'balance-fournisseurs', 'libelle' => 'Balance fournisseurs'],
            ],
            'prealable' => null,
            'consequence' => "Se lit en regard du suivi fournisseur : c'est l'écart entre les deux — "
                ."ce que l'atelier croit devoir, ce que la comptabilité a enregistré — qu'on cherche "
                .'quand un fournisseur réclame. Il porte plusieurs exercices : un solde se traîne.',
        ],
        'reglements-fournisseurs' => [
            'apporte' => 'Les paiements enregistrés par la comptabilité, avec leur mode et leur date.',
            'tables' => ['reglements_fournisseur'],
            'ecrans' => [
                ['route' => 'reglements-fournisseurs', 'libelle' => 'Règlements fournisseurs'],
                ['route' => 'fournisseurs', 'libelle' => 'Factures fournisseurs'],
            ],
            'prealable' => 'balance-fournisseurs',
            'consequence' => "Sans la balance, un règlement ne s'oppose à aucun solde : on voit ce qui "
                ."est sorti, jamais ce qu'il restait à sortir.",
        ],
        'entrees' => [
            'apporte' => "Les entrées de véhicules à l'atelier, avec le déposant et le propriétaire.",
            'tables' => ['mouvements_vehicules'],
            'ecrans' => [
                ['route' => 'mouvements-vehicules', 'libelle' => 'Entrées / Sorties'],
                ['route' => 'parc-vehicules', 'libelle' => 'Parc véhicules'],
            ],
            'prealable' => 'parc',
            'consequence' => 'Enrichit des fiches que le parc a déjà posées ; il ne les fonde pas.',
        ],
        'sorties' => [
            'apporte' => 'Les sorties de véhicules, avec la date de restitution.',
            'tables' => ['mouvements_vehicules'],
            'ecrans' => [
                ['route' => 'mouvements-vehicules', 'libelle' => 'Entrées / Sorties'],
                ['route' => 'parc-vehicules', 'libelle' => 'Parc véhicules'],
            ],
            'prealable' => 'parc',
            'consequence' => "Se lit avec les entrées : c'est l'écart entre les deux qui donne "
                ."l'immobilisation d'un véhicule.",
        ],
    ];

    public function __construct(private int $entrepriseId) {}

    /**
     * Le tableau complet, mesuré.
     *
     * @return list<array{
     *     cle: string, libelle: string, apporte: string, consequence: string,
     *     prealable: ?string, ecrans: list<array{route: string, libelle: string}>,
     *     lignes_en_base: ?int, dernier: ?LotImport, lots: int,
     * }>
     */
    public function lignes(): array
    {
        $lots = LotImport::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('etat', '!=', 'annule')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('format');

        $tableau = [];

        foreach (self::CARTE as $cle => $fiche) {
            $desLots = $lots->get($cle, collect());

            $tableau[] = [
                'cle' => $cle,
                'libelle' => Registre::libelle($cle),
                'apporte' => $fiche['apporte'],
                'consequence' => $fiche['consequence'],
                'prealable' => $fiche['prealable'] === null ? null : Registre::libelle($fiche['prealable']),
                'ecrans' => $fiche['ecrans'],
                'lignes_en_base' => $this->compter($fiche['tables']),
                'dernier' => $desLots->first(),
                'lots' => $desLots->count(),
            ];
        }

        return $tableau;
    }

    /**
     * Les écrans, et ce qu'il faut déposer pour qu'ils se remplissent — la lecture inverse.
     *
     * @return list<array{route: string, libelle: string, fichiers: list<string>}>
     */
    public function parEcran(): array
    {
        $par = [];

        foreach (self::CARTE as $cle => $fiche) {
            foreach ($fiche['ecrans'] as $ecran) {
                $par[$ecran['route']] ??= [
                    'route' => $ecran['route'],
                    'libelle' => $ecran['libelle'],
                    'fichiers' => [],
                ];

                $par[$ecran['route']]['fichiers'][] = Registre::libelle($cle);
            }
        }

        ksort($par);

        return array_values($par);
    }

    /**
     * Combien de lignes portent les tables d'un format.
     *
     * Rendu `null` quand la table n'existe pas encore : c'est un renseignement, pas une
     * panne — et un zéro à sa place laisserait croire qu'un import n'a rien donné.
     */
    private function compter(array $tables): ?int
    {
        $total = 0;

        foreach ($tables as $table) {
            try {
                $total += (int) DB::table($table)->where('entreprise_id', $this->entrepriseId)->count();
            } catch (\Throwable) {
                return null;
            }
        }

        return $total;
    }
}

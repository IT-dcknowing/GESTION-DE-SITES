<?php

namespace Modules\Noyau\Console;

use Illuminate\Console\Command;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Imports\Formats\FormatDesImpayes;
use Modules\Noyau\Imports\Modeles\LotImport;

/**
 * Range dans leurs colonnes les données que les imports avaient mises dans une phrase.
 *
 * **Pourquoi cette commande existe.** Trois choses étaient conservées sans être exploitables :
 *
 * 1. Le numéro de sinistre, le sticker et le code client du CATTC étaient concaténés en
 *    observation — « Sinistre : X · Sticker : Y · Code client : Z ». Une donnée rangée dans
 *    une phrase ne se trie pas, ne se filtre pas, ne s'affiche pas en colonne.
 * 2. Les créances reprises de l'état des impayés n'avaient pas d'année : sans elle, aucune ne
 *    peut apparaître dans l'état d'un exercice, et le report d'une année sur l'autre n'a rien
 *    sur quoi s'appuyer. C'est le point qui compte le plus — sans lui, l'écran est vide.
 * 3. La colonne « Commentaires » du classeur ne porte pas de commentaires mais une clé de
 *    doublon `CONCATENATE`, recopiée telle quelle en observation sur des milliers de lignes.
 *
 * **Ce qu'elle ne fait jamais**, et c'est ce qui la rend sûre sur une base qui porte de
 * vraies créances :
 *
 * - elle **n'écrase aucune valeur déjà présente** : elle ne remplit que ce qui est nul ;
 * - elle **ne jette aucun morceau qu'elle ne comprend pas**. Une observation est découpée en
 *   morceaux, chacun est jugé séparément, et ce qui n'est ni une donnée mal rangée ni la clé de
 *   doublon reste écrit. Le classeur porte de vraies consignes de travail accolées au numéro de
 *   sinistre — « FACTURE D'AVOIR A ETABLIR », « EN ATTENTE DE JUSTIF DE REGLEMENT PAR SAAR » —
 *   et elles valent plus que la donnée qui les précède ;
 * - elle **ne touche pas** à une observation dont elle n'a reconnu aucun morceau ;
 * - elle **ne découpe pas le véhicule** en marque et modèle. « MERCEDES GLE400 » se couperait
 *   au premier espace, et « LAND ROVER DEFENDER » deviendrait la marque « LAND » ;
 * - elle **ne crée et ne supprime rien**.
 *
 * Sans `--appliquer`, elle dit ce qu'elle ferait et n'écrit pas une ligne. C'est le mode par
 * défaut : une reprise sur des données réelles se regarde avant de se lancer.
 */
class RangerLesColonnesDesFactures extends Command
{
    protected $signature = 'impayes:ranger-les-colonnes
        {--appliquer : écrit réellement les modifications}
        {--entreprise= : ne traiter qu-une entreprise, par son identifiant}';

    protected $description = "Range en colonnes les données que les imports gardaient dans une phrase, et date les créances reprises";

    /** Par paquets : la table porte plusieurs milliers de lignes et la mémoire n'est pas extensible. */
    private const PAQUET = 500;

    public function handle(): int
    {
        $appliquer = (bool) $this->option('appliquer');

        if (! $appliquer) {
            $this->warn('Mode constat : rien ne sera écrit. Ajoutez --appliquer pour enregistrer.');
        }

        $this->newLine();

        $bilan = [
            'annee' => 0, 'initial' => 0, 'sinistre' => 0, 'sticker' => 0,
            'code_client' => 0, 'phrase_defaite' => 0, 'phrase_gardee' => 0, 'cle_effacee' => 0,
        ];

        $lotsImpayes = LotImport::withoutGlobalScopes()
            ->where('format', FormatDesImpayes::cle())
            ->pluck('id')
            ->all();

        Facture::withoutGlobalScopes()
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('entreprise_id', (int) $id))
            ->orderBy('id')
            ->chunkById(self::PAQUET, function ($factures) use ($appliquer, $lotsImpayes, &$bilan) {
                foreach ($factures as $facture) {
                    $changements = $this->changementsPour($facture, $lotsImpayes, $bilan);

                    if ($changements === [] || ! $appliquer) {
                        continue;
                    }

                    /*
                     * Écriture sans passer par le modèle : ni horodatage, ni journal
                     * d'activité, ni observateur. Ce n'est pas une modification métier — la
                     * donnée ne change pas, elle change de place. Marquer neuf mille factures
                     * comme « modifiées aujourd'hui » effacerait la seule trace qui dise quand
                     * chacune a réellement été touchée.
                     */
                    Facture::withoutGlobalScopes()->where('id', $facture->id)->update($changements);
                }
            });

        $this->table(
            ['Ce qui a été rangé', 'Lignes'],
            [
                ["Année de la créance posée (c'est elle qui fait apparaître la ligne dans un état)", $bilan['annee']],
                ['Marquées comme venant de la reprise du classeur', $bilan['initial']],
                ['N° de sinistre sorti de la phrase', $bilan['sinistre']],
                ['N° de sticker sorti de la phrase', $bilan['sticker']],
                ['Code client sorti de la phrase', $bilan['code_client']],
                ['Phrase entièrement reconnue, donc effacée', $bilan['phrase_defaite']],
                ['Phrase gardée — elle contenait autre chose', $bilan['phrase_gardee']],
                ['Clé de doublon effacée des observations', $bilan['cle_effacee']],
            ],
        );

        if (! $appliquer) {
            $this->newLine();
            $this->warn('Rien n\'a été écrit. Relancez avec --appliquer.');
        }

        return self::SUCCESS;
    }

    /**
     * Ce qu'il faudrait changer sur cette facture — et rien d'autre.
     *
     * @param  array<int, int>  $lotsImpayes
     * @return array<string, mixed>
     */
    private function changementsPour(Facture $facture, array $lotsImpayes, array &$bilan): array
    {
        $changements = [];
        $vientDesImpayes = $facture->lot_import_id !== null
            && in_array((int) $facture->lot_import_id, $lotsImpayes, true);

        /*
         * L'année de la créance, pour les seules lignes qui relèvent de l'état des impayés.
         *
         * Le critère est l'origine, pas la présence d'un règlement : une créance de l'état
         * qui se trouve soldée en relève tout autant, c'est elle qui prouve qu'elle l'est. Les
         * factures du chiffre d'affaires, elles, gardent une année nulle — elles n'apportent
         * aucun règlement, et les compter en créance les déclarerait intégralement dues.
         */
        if ($vientDesImpayes && $facture->exercice_impayes === null && $facture->date !== null) {
            $changements['exercice_impayes'] = (int) $facture->date->format('Y');
            $bilan['annee']++;
        }

        if ($vientDesImpayes && ! $facture->est_etat_initial) {
            $changements['est_etat_initial'] = true;
            $bilan['initial']++;
        }

        $observation = trim((string) $facture->observations);

        if ($observation === '') {
            return $changements;
        }

        return $changements + $this->demelerLObservation($facture, $observation, $bilan);
    }

    /**
     * Démêle une observation : range ce qui est une donnée, **garde ce qui est une note**.
     *
     * L'observation est découpée en morceaux séparés par « · », et chacun est jugé
     * séparément. Trois cas, et le troisième est celui qui commande la prudence :
     *
     * 1. un morceau qui commence par « Sinistre : », « Sticker : » ou « Code client : » est
     *    une donnée que l'import y avait rangée faute de colonne. Elle rejoint sa colonne ;
     * 2. un morceau qui reproduit la clé `CONCATENATE` du classeur est un rebut technique. Il
     *    disparaît. On ne le reconnaît pas à sa forme — on le **recompose depuis la ligne** et
     *    l'on compare, car une immatriculation notée à la main ressemblerait à une clé ;
     * 3. tout le reste est une note écrite par quelqu'un, et elle est conservée telle quelle.
     *
     * **Le troisième cas n'est pas théorique, et c'est lui qui a fait réécrire cette méthode.**
     * Le classeur porte de vraies notes de travail, accolées au numéro de sinistre :
     * « FACTURE D'AVOIR A ETABLIR », « RELIQUAT REGLE LE 21/10/2022 : 4460484 F », « EN
     * ATTENTE DE JUSTIF DE REGLEMENT PAR SAAR ». Ce sont des consignes de recouvrement, et
     * elles valent plus que la donnée qui les précède. La première version gardait la phrase
     * entière dès qu'elle ne la reconnaissait pas en totalité : la note survivait, mais le
     * numéro de sinistre restait affiché deux fois. Ici la note survit **seule**.
     *
     * Rien n'est réécrit tant qu'aucun morceau n'a été reconnu : une observation entièrement
     * libre n'est pas touchée.
     *
     * @return array<string, mixed>
     */
    private function demelerLObservation(Facture $facture, string $observation, array &$bilan): array
    {
        $prefixes = [
            'n_sinistre' => 'Sinistre : ',
            'n_sticker' => 'Sticker : ',
            'code_client' => 'Code client : ',
        ];
        $tailles = ['n_sinistre' => 60, 'n_sticker' => 60, 'code_client' => 40];
        $compteurs = ['n_sinistre' => 'sinistre', 'n_sticker' => 'sticker', 'code_client' => 'code_client'];

        $lus = [];
        $notes = [];
        $cleTrouvee = false;

        foreach (array_map('trim', explode('·', $observation)) as $morceau) {
            if ($morceau === '') {
                continue;
            }

            $reconnu = false;

            foreach ($prefixes as $colonne => $prefixe) {
                if (str_starts_with($morceau, $prefixe)) {
                    $lus[$colonne] = trim(mb_substr($morceau, mb_strlen($prefixe)));
                    $reconnu = true;

                    break;
                }
            }

            if ($reconnu) {
                continue;
            }

            if ($this->estLaCleDuClasseur($facture, $morceau)) {
                $cleTrouvee = true;

                continue;
            }

            $notes[] = $morceau;
        }

        // Aucun morceau compris : on ne touche pas à une observation entièrement libre.
        if ($lus === [] && ! $cleTrouvee) {
            return [];
        }

        $changements = [];

        foreach ($lus as $colonne => $valeur) {
            if ($valeur === '' || $facture->{$colonne} !== null) {
                continue;
            }

            $changements[$colonne] = mb_substr($valeur, 0, $tailles[$colonne]);
            $bilan[$compteurs[$colonne]]++;
        }

        if ($cleTrouvee) {
            $bilan['cle_effacee']++;
        }

        $restant = $notes === [] ? null : implode(' · ', $notes);

        if ($restant !== $observation) {
            $changements['observations'] = $restant;
            $bilan[$restant === null ? 'phrase_defaite' : 'phrase_gardee']++;
        }

        return $changements;
    }

    /**
     * Ce morceau reproduit-il la clé `CONCATENATE` du classeur ?
     *
     * Trois formes, relevées dans le fichier : la date en `Ymd`, la même date sous son numéro
     * de série Excel — c'est ainsi que le tableur la concatène — et la forme ancienne à deux
     * champs. Voir {@see \Modules\Noyau\Imports\Formats\FormatDesImpayes::observations()}.
     */
    private function estLaCleDuClasseur(Facture $facture, string $morceau): bool
    {
        $numero = trim((string) $facture->n_facture);
        $immatriculation = trim((string) $facture->immatriculation);
        $montant = (string) (int) $facture->montant;
        $serie = $facture->date !== null
            ? (string) (new \DateTimeImmutable('1899-12-30'))->diff($facture->date)->days
            : '';

        $candidates = [
            ($facture->date?->format('Ymd') ?? '').$numero.$immatriculation.$montant,
            $serie.$numero.$immatriculation.$montant,
            $immatriculation.$montant,
        ];

        $compare = fn (string $valeur) => preg_replace('/\s+/', '', $valeur) ?? $valeur;

        foreach ($candidates as $cle) {
            // Une clé vide viendrait d'une ligne sans plaque ni montant : elle coïnciderait
            // avec n'importe quel morceau vide, donc on ne la retient pas.
            if ($cle !== '' && $compare($morceau) === $compare($cle)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Modules\Noyau\Imports\Formats;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Lecteurs\LecteurXlsx;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\DoublonImport;
use Modules\Noyau\Imports\Services\Rattachement;

/**
 * Ce que tous les imports ont en commun.
 *
 * Chaque type de fichier — parc, devis, factures, impayés — hérite d'ici et ne décrit que
 * ce qui lui est propre : ses colonnes et ce qu'il en fait. Le reste est identique et vaut
 * la peine d'être écrit une seule fois.
 *
 * **Trouver l'en-tête plutôt que la supposer.** Les exports ne commencent pas tous à la
 * même ligne : la situation du parc en ligne 1, l'état des impayés en ligne 5 après un
 * bloc d'adresse et une ligne de totaux, le CATTC en ligne 2 après un montant isolé. On ne
 * code donc aucun numéro de ligne : on cherche, dans les premières lignes, celle qui
 * ressemble le plus à l'en-tête attendu. Le jour où quelqu'un ajoute un logo, l'import
 * continue de fonctionner.
 *
 * **Choisir la feuille de la même façon.** Un fichier peut en avoir douze — le suivi
 * fournisseurs en a cinq, l'état des impayés dix — et la bonne n'est presque jamais la
 * première : ce sont souvent des tableaux croisés de synthèse. On ouvre celle dont
 * l'en-tête correspond, pas celle qui se présente en premier.
 *
 * **Ne jamais deviner.** Une ligne qu'on ne sait pas lire n'est ni corrigée ni écrasée :
 * elle est mise de côté avec son motif et ses valeurs d'origine, pour qu'on répare le
 * fichier plutôt que la base.
 */
abstract class Format
{
    /** Combien de lignes on accepte de parcourir pour trouver l'en-tête. */
    protected const LIGNES_SONDEES = 12;

    /** Le nom court du format, tel qu'il est stocké sur le lot. */
    abstract public static function cle(): string;

    /** Ce qu'on en dit à l'écran. */
    abstract public static function libelle(): string;

    /**
     * Les colonnes attendues : clé interne => intitulé dans le fichier.
     *
     * @return array<string, string>
     */
    abstract public static function colonnes(): array;

    /**
     * Celles sans lesquelles le fichier n'est pas le bon.
     *
     * @return list<string>
     */
    abstract public static function colonnesObligatoires(): array;

    /**
     * Écrit une ligne, et dit ce qu'il en est advenu : `cree`, `maj` ou `ignore`.
     *
     * @param  array<string, string|float|DateTimeImmutable|null>  $ligne
     * @param  array{ville_id: int|null, site_id: int|null, code: string|null, source: string, presumee: bool}  $rattachement
     */
    abstract protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string;

    /**
     * La référence dont on tire le code agent — le numéro de fiche, de proforma ou de
     * facture selon le format. Null quand le fichier n'en porte pas.
     */
    abstract protected function reference(array $ligne): ?string;

    /**
     * Ce fichier se range-t-il par le code de deux lettres de l'employé qui l'a rédigé ?
     *
     * **Pourquoi la question se pose, et ce qu'elle répare.** L'écran de dépôt annonçait,
     * pour tout fichier déposé sans ville, « ce fichier sera ventilé par les codes du
     * personnel » — y compris pour la balance fournisseurs, les règlements fournisseurs et
     * le journal de caisse, qui ne portent aucun numéro de fiche et que rien ne ventile
     * par les codes. Le propriétaire l'a relevé le 24/09, et il a raison : l'import de ces
     * fichiers se passe très bien, mais l'avertissement décrit un mécanisme qui ne les
     * concerne pas. Un avertissement qui ne s'applique pas à ce qu'on fait apprend à ne
     * plus lire les avertissements.
     *
     * La réponse suit exactement `reference()` : un format qui ne rend aucune référence ne
     * peut produire aucun code, donc rien à ventiler. Elle est déclarée plutôt que
     * devinée, parce qu'elle sert à **écrire une phrase à l'écran** — et une phrase qu'on
     * déduit d'un détour finit par mentir le jour où le détour change.
     */
    public static function ventileParLesCodes(): bool
    {
        return true;
    }

    /**
     * Ce fichier se dépose-t-il sur un compte bancaire choisi au dépôt ?
     *
     * Les deux relevés bancaires ne nomment pas le compte dans leurs lignes : il se déclare
     * au dépôt, comme la ville. Déclaré ici plutôt que comparé à un nom de format à trois
     * endroits — le jour où un troisième relevé arrive, il n'y a qu'une ligne à écrire.
     */
    public static function demandeUnCompte(): bool
    {
        return false;
    }

    /** La valeur de la colonne SITE, quand le fichier en a une. */
    protected function colonneSite(array $ligne): ?string
    {
        return isset($ligne['site']) ? (string) $ligne['site'] : null;
    }

    public function __construct(
        protected int $entrepriseId,
        protected Rattachement $rattachement,
    ) {}

    /**
     * Parcourt le fichier et applique le format.
     *
     * `$ecrire` à false, c'est la **simulation** : tout est lu, analysé, compté, et rien
     * n'est écrit. C'est l'état dans lequel on répond à « qu'est-ce que ça va faire ? »
     * avant de le faire, et c'est ce que l'écran propose par défaut.
     *
     * `$surAvancee` reçoit le nombre de lignes déjà lues. C'est ce qui permet à l'écran de
     * montrer une progression réelle plutôt qu'un sablier immobile.
     */
    public function parcourir(
        Lecteur $lecteur,
        ?int $villeDuDepot,
        ?int $lotId,
        bool $ecrire = true,
        ?\Closure $surAvancee = null,
        ?int $siteDuDepot = null,
    ): Resultat {
        $resultat = new Resultat;

        $feuille = $this->feuilleDe($lecteur, $resultat);
        $entete = null;

        foreach ($lecteur->lignes($feuille) as $numero => $cellules) {
            if ($entete === null) {
                $candidat = $this->correspondance($cellules);

                if ($this->suffisante($candidat)) {
                    $entete = $candidat;
                    $resultat->ligneDEnTete = $numero;
                }

                continue;
            }

            $ligne = $this->extraire($cellules, $entete);

            // Une ligne sans aucune valeur utile n'est pas une erreur : les exports en
            // sèment entre les blocs. On ne la compte même pas comme lue.
            if ($this->vide($ligne)) {
                continue;
            }

            $resultat->lues++;

            $motif = $this->refuser($ligne);

            if ($motif !== null) {
                $resultat->rejeter($numero, $motif, $this->pourLeJournal($cellules));

                continue;
            }

            $connu = $this->dossierExistant($ligne);

            $ou = $this->rattachement->resoudre(
                $this->colonneSite($ligne),
                $this->reference($ligne),
                $villeDuDepot,
                $connu['site_id'],
                $connu['presume'],
                $siteDuDepot,
            );

            $resultat->desaccords = $this->rattachement->desaccords();
            $this->villeDuDepotCourante = $villeDuDepot;
            $this->apresRattachement($ou, $villeDuDepot, $resultat);

            $issue = $ecrire ? $this->ecrire($ligne, $ou, $lotId) : 'ignore';

            if ($issue === self::DOUBLON) {
                $issue = $this->traiterLeDoublon($numero, $ligne, $ou, $lotId, $resultat->feuille, $this->decrireLeDoublon($ligne), $resultat);
            }

            $resultat->compter($issue);

            if ($surAvancee !== null) {
                $surAvancee($resultat->lues);
            }
        }

        if ($entete === null) {
            $resultat->rejeter(0, 'Aucune ligne d\'en-tête reconnue dans ce fichier.', []);
        }

        return $resultat;
    }

    /**
     * `colonne` égale à `$montant` — ou à ce montant arrondi au franc, pour une ligne d'avant le 13/10.
     *
     * Les lignes écrites avant le 13/10 ont leur montant arrondi au franc ; celles d'après le gardent
     * tel que donné. On comparait à ±0,5 F, ce qui retrouvait les deux mais **fusionnait aussi deux
     * montants distincts** : 1 000,20 et 1 000,60 le même jour, sous le même libellé, devenaient une
     * seule ligne — un doublon écarté par arrondi, revu le 14/10. On ne retrouve plus que le montant
     * exact (aux quatre décimales de la colonne) ou son arrondi au franc, qui est la forme exacte de
     * la ligne d'avant. L'arrondi n'est ici qu'une clé de recherche, jamais un montant écrit.
     */
    protected static function aPeuPres($requete, string $colonne, int|float $montant): void
    {
        $requete->where(fn ($q) => $q
            ->whereBetween($colonne, [$montant - 0.00005, $montant + 0.00005])
            ->orWhere($colonne, round($montant)));
    }

    // ------------------------------------------------------------------ doublons — 14/10

    /**
     * L'issue d'une ligne qui répète, dans le même fichier, une ligne déjà écrite.
     *
     * **Ce qui se passait avant.** Chaque format retrouvait la ligne en base par sa clé — numéro de
     * facture, ligne entière d'un mouvement de caisse — et la mettait à jour. Une ligne répétée
     * dans le même fichier retrouvait donc **celle que le même dépôt venait d'écrire**, et la
     * réécrivait : elle disparaissait, son montant avec. Mesuré sur le CA du 12/10 : 10 000 F, le
     * doublon FA -8730. Pour la caisse, le commentaire de l'import le disait lui-même — « deux
     * dépenses de 5 000 F le même jour pour le même motif sont possibles et légitimes » — et la
     * clé les fusionnait quand même.
     *
     * **La règle d'aujourd'hui** : une ligne de la base ne sert qu'une fois par dépôt (comme dans
     * `PontDesFactures`). La retrouver une seconde fois, c'est être une répétition. `ecrire()` rend
     * alors `DOUBLON` sans rien écrire, et `traiterLeDoublon()` décide :
     *
     * - **au premier import, rien n'est écarté** (propriétaire, 15/10) : la ligne est écrite à part
     *   entière — elle compte —, et consignée comme **doublon supposé** dans `doublons_import`, avec
     *   la raison (« même n°, même date, même montant… ») ; l'écran demande de choisir laquelle
     *   garder, ou les deux ;
     * - **au redépôt**, chaque ligne retrouve la sienne : rien ne se double ; une ligne qu'on a
     *   retirée par décision ne revient pas (`doublons_import.statut = retire`).
     */
    public const DOUBLON = 'doublon';

    /** @var array<string, true> les lignes de la base déjà retrouvées par ce parcours : « table#id » */
    private array $tenues = [];

    /** La ligne déjà retenue que la ligne en cours répète, quand c'en est une. */
    private ?Model $repetee = null;

    /** Vrai pendant « Intégrer » : la ligne s'écrit à part entière, quoi qu'elle répète. */
    protected bool $enIntegration = false;

    /** Ce que la dernière écriture a créé ou retrouvé — `integrer()` en rend l'identifiant. */
    protected ?Model $derniereTenue = null;

    /** @var array<string, int> combien de fois la même ligne a déjà été mise de côté dans ce parcours */
    private array $empreintesVues = [];

    private ?bool $doublonsGardes = null;

    /**
     * La première ligne de la base, parmi celles qui répondent à la clé, que ce dépôt n'a pas
     * encore retrouvée — ou null.
     *
     * Quand toutes sont déjà prises, la ligne en cours en répète une : `repetition()` la nomme.
     * `$memeLigne` dit, au besoin, si une ligne prise est bien la même (la caisse compare le
     * montant exact : sa clé retrouve aussi l'arrondi d'une ligne d'avant le 13/10, qui n'est pas
     * une répétition d'une ligne de ce fichier).
     *
     * Tant que la table des doublons n'est pas migrée, l'ancienne règle s'applique : la première
     * ligne trouvée, prise ou non — mieux vaut un doublon réécrit comme avant qu'un doublon perdu.
     *
     * @param  iterable<Model|null>  $candidats  dans l'ordre où les préférer
     */
    protected function premiereLibre(iterable $candidats, ?\Closure $memeLigne = null): ?Model
    {
        $this->repetee = null;

        if ($this->enIntegration) {
            return null;
        }

        foreach ($candidats as $candidat) {
            if ($candidat === null) {
                continue;
            }

            if (! isset($this->tenues[self::marque($candidat)]) || ! $this->doublonsGardes()) {
                return $this->tenir($candidat);
            }

            if ($memeLigne === null || $memeLigne($candidat)) {
                $this->repetee ??= $candidat;
            }
        }

        return null;
    }

    /** Marque une ligne de la base comme retrouvée (ou créée) par ce dépôt. */
    protected function tenir(Model $ligne): Model
    {
        $this->tenues[self::marque($ligne)] = true;
        $this->derniereTenue = $ligne;

        return $ligne;
    }

    /** La ligne déjà retenue que la ligne en cours répète — null si elle n'en répète aucune. */
    protected function repetition(): ?Model
    {
        return $this->enIntegration || ! $this->doublonsGardes() ? null : $this->repetee;
    }

    /**
     * La clé d'une ligne intégrée, quand sa table veut une clé unique : « FA -8730#2 ».
     *
     * Hors intégration, la clé reste celle du fichier. Le suffixe se voit à l'écran, et c'est voulu :
     * la ligne dit qu'elle est la seconde de ce numéro. `avecSesVariantes()` la retrouve au dépôt suivant.
     *
     * @param  \Closure(string): bool  $existe
     */
    protected function variante(string $valeur, int $longueur, \Closure $existe): string
    {
        if (! $this->enIntegration) {
            return $valeur;
        }

        for ($rang = 2; ; $rang++) {
            $suffixe = '#'.$rang;
            $candidate = mb_substr($valeur, 0, $longueur - strlen($suffixe)).$suffixe;

            if (! $existe($candidate)) {
                return $candidate;
            }
        }
    }

    /** La clé, et celles des lignes intégrées sous elle (« clé#2 », « clé#3 »…). */
    protected static function avecSesVariantes($requete, string $colonne, string $valeur): void
    {
        $requete->where(fn ($q) => $q->where($colonne, $valeur)->orWhere($colonne, 'like', $valeur.'#%'));
    }

    /** Vrai quand `$trouvee` est la clé ou l'une de ses variantes — le LIKE ci-dessus, relu sans joker. */
    protected static function estUneVariante(?string $trouvee, string $valeur): bool
    {
        return $trouvee === $valeur || (bool) preg_match('/^'.preg_quote($valeur, '/').'#\d+$/u', (string) $trouvee);
    }

    /**
     * Écrit à part entière une ligne mise de côté comme doublon, et rend ce qui a été créé.
     *
     * C'est le même `ecrire()` que celui du dépôt : la ligne entre exactement comme elle serait
     * entrée si elle n'avait répété personne — mêmes colonnes, même rattachement.
     */
    public function integrer(array $ligne, array $rattachement, ?int $lotId, ?string $feuille = null, ?int $villeDuDepot = null): ?Model
    {
        $this->enIntegration = true;
        $this->derniereTenue = null;
        $this->villeDuDepotCourante = $villeDuDepot;

        try {
            $issue = $this->ecrireUneLigne($ligne, $rattachement, $lotId, $feuille);
        } finally {
            $this->enIntegration = false;
        }

        return $issue === 'cree' ? $this->derniereTenue : null;
    }

    /** Le point d'entrée d'`integrer()` ; les formats qui écrivent autrement que par `ecrire()` le redéfinissent. */
    protected function ecrireUneLigne(array $ligne, array $rattachement, ?int $lotId, ?string $feuille): string
    {
        return $this->ecrire($ligne, $rattachement, $lotId);
    }

    /**
     * Ce que l'écran montre d'un doublon : sa référence, sa date, son libellé, son montant.
     *
     * Lu sur les colonnes que les formats partagent ; un format dont la ligne ne ressemble à
     * aucune autre (le journal de caisse) le redéfinit.
     *
     * @return array{reference: ?string, date: ?\DateTimeInterface, libelle: ?string, montant: int|float|null}
     */
    protected function decrireLeDoublon(array $ligne): array
    {
        $premier = function (array $cles, \Closure $lire) use ($ligne) {
            foreach ($cles as $cle) {
                if (array_key_exists($cle, $ligne) && ($valeur = $lire($ligne[$cle])) !== null) {
                    return $valeur;
                }
            }

            return null;
        };

        $montant = $premier(['montant', 'credit', 'debit', 'solde'], fn ($v) => self::montant($v));

        return [
            'reference' => $premier(['numero', 'numero_piece', 'code_reglement', 'code_piece', 'fiche'], fn ($v) => self::texte($v, 120)),
            'date' => $premier(['date', 'date_facture', 'date_piece', 'date_reglement', 'date_operation'], fn ($v) => self::date($v)),
            'libelle' => $premier(['client', 'libelle', 'fournisseur', 'beneficiaire', 'assureur'], fn ($v) => self::texte($v, 255)),
            'montant' => $montant === null ? null : montant_de($montant),
        ];
    }

    /**
     * Une ligne qui en répète une autre du même fichier : écrite quand même, et consignée comme
     * doublon supposé — sauf si une décision passée l'a retirée.
     *
     * @param  array{reference: ?string, date: ?\DateTimeInterface, libelle: ?string, montant: int|float|null}  $description
     */
    protected function traiterLeDoublon(?int $numeroLigne, array $ligne, array $rattachement, ?int $lotId, ?string $feuille, array $description, Resultat $resultat, ?array $pourLEmpreinte = null): string
    {
        if (! $this->doublonsGardes()) {
            return 'ignore';
        }

        $retenue = $this->repetee;
        $empreinte = $this->empreinteDuDoublon($ligne, $pourLEmpreinte);

        $deja = DoublonImport::query()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('format', static::cle())
            ->where('empreinte', $empreinte)
            ->first();

        // Tranché : l'une des deux a été retirée. La ligne du fichier ne la fait pas revenir.
        if ($deja?->statut === DoublonImport::RETIRE) {
            return 'ignore';
        }

        $this->enIntegration = true;
        $this->derniereTenue = null;

        try {
            $issue = $this->ecrireUneLigne($ligne, $rattachement, $lotId, $feuille);
        } finally {
            $this->enIntegration = false;
        }

        $this->repetee = $retenue;
        $this->consignerLeDoublon($numeroLigne, $ligne, $rattachement, $lotId, $feuille, $description, $pourLEmpreinte,
            $empreinte, $issue === 'cree' ? $this->derniereTenue : null);
        $resultat->doublons++;

        return $issue;
    }

    /** L'empreinte d'une ligne répétée : ses valeurs, et son rang parmi les lignes identiques du fichier. */
    private function empreinteDuDoublon(array $ligne, ?array $pourLEmpreinte): string
    {
        $base = hash('sha256', static::cle().'|'.json_encode(self::serialiser($pourLEmpreinte ?? $ligne), JSON_UNESCAPED_UNICODE));
        $rang = $this->empreintesVues[$base] = ($this->empreintesVues[$base] ?? 0) + 1;

        return hash('sha256', $base.'#'.$rang);
    }

    /**
     * Pourquoi deux lignes sont supposées être la même : ce qu'elles ont en commun, et ce qui les
     * distingue. C'est la colonne « Pourquoi » du tableau — la preuve du contraire se cherche là.
     *
     * @param  array{reference: ?string, date: ?\DateTimeInterface, libelle: ?string, montant: int|float|null}  $description
     */
    private function raisonDuDoublon(array $description, ?Model $retenue): string
    {
        $colonnes = \Modules\Noyau\Imports\Services\DoublonsDImport::COLONNES_RETENUES[$retenue?->getTable()] ?? null;

        if ($retenue === null || $colonnes === null) {
            return 'Même clé de pièce qu\'une ligne déjà lue dans ce fichier.';
        }

        [$cReference, $cDate, $cLibelle, $cMontant] = $colonnes;
        $memes = [];
        $differents = [];

        $comparer = function (string $quoi, $ici, $la, \Closure $dire) use (&$memes, &$differents) {
            if ($ici === null && $la === null) {
                return;
            }

            if ($ici !== null && $la !== null && $ici === $la) {
                $memes[] = 'même '.$quoi.' '.$dire($ici);
            } else {
                $differents[] = $quoi.' différent : '.($ici === null ? '—' : $dire($ici)).' / '.($la === null ? '—' : $dire($la));
            }
        };

        $texte = fn ($v) => $v === null || trim((string) $v) === '' ? null : mb_strtoupper(trim((string) $v));
        $jour = fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString();
        $argent = fn ($v) => $v === null ? null : (string) montant_de(abs((float) $v));

        $comparer('n°', $texte($description['reference']), $texte($retenue->getAttribute($cReference)), fn ($v) => '« '.$v.' »');
        $comparer('date', $jour($description['date']), $jour($retenue->getAttribute($cDate)), fn ($v) => Carbon::parse($v)->format('d/m/Y'));
        $comparer('montant', $argent($description['montant']), $argent($retenue->getAttribute($cMontant)), fn ($v) => ae($v));
        $comparer('libellé', $texte($description['libelle']), $texte($retenue->getAttribute($cLibelle)), fn ($v) => '« '.mb_strimwidth($v, 0, 60, '…').' »');

        return 'Supposé doublon à preuve du contraire — '
            .($memes === [] ? 'même clé de pièce' : implode(', ', $memes))
            .($differents === [] ? '.' : ' ; mais '.implode(', ', $differents).'.');
    }

    /**
     * Garde la ligne mise de côté, avec de quoi la montrer et la réintégrer.
     *
     * L'empreinte est celle des valeurs de la ligne : redéposer le même fichier retrouve la même
     * ligne et la met à jour au lieu de la recopier. Trois lignes identiques donnent deux doublons,
     * distingués par leur rang. Une décision déjà prise (« intégrée ») n'est jamais touchée.
     *
     * @param  array{reference: ?string, date: ?\DateTimeInterface, libelle: ?string, montant: int|float|null}  $description
     */
    protected function consignerLeDoublon(?int $numeroLigne, array $ligne, array $rattachement, ?int $lotId, ?string $feuille, array $description, ?array $pourLEmpreinte, string $empreinte, ?Model $ecrite): void
    {
        $serialisee = self::serialiser($ligne);

        $existant = DoublonImport::query()->where('entreprise_id', $this->entrepriseId)
            ->where('format', static::cle())->where('empreinte', $empreinte)->first();

        $decision = [];

        // Neuf, ou resté « écarté » d'avant le 15/10 alors qu'il vient d'être écrit : à trancher.
        if ($existant === null || ($existant->statut === DoublonImport::ECARTE && $ecrite !== null)) {
            $decision = ['statut' => DoublonImport::A_TRANCHER];
        }

        if ($ecrite !== null) {
            $decision['integre_id'] = $ecrite->getKey();
        }

        $raison = \App\Support\SchemaDisponible::colonne('doublons_import', 'raison')
            ? ['raison' => $this->raisonDuDoublon($description, $this->repetee)] : [];

        DoublonImport::query()->updateOrCreate(
            [
                'entreprise_id' => $this->entrepriseId,
                'format' => static::cle(),
                'empreinte' => $empreinte,
            ],
            $decision + $raison + [
                'lot_import_id' => $lotId,
                'numero_ligne' => $numeroLigne,
                'feuille' => $feuille === null ? null : mb_substr($feuille, 0, 60),
                'reference' => $description['reference'] === null ? null : mb_substr($description['reference'], 0, 120),
                'date' => $description['date'],
                'libelle' => $description['libelle'] === null ? null : mb_substr($description['libelle'], 0, 255),
                'montant' => $description['montant'],
                'ville_id' => $rattachement['ville_id'] ?? null,
                'site_id' => $rattachement['site_id'] ?? null,
                'retenue_table' => $this->repetee?->getTable(),
                'retenue_id' => $this->repetee?->getKey(),
                'ligne' => $serialisee,
                'rattachement' => $rattachement,
            ],
        );
    }

    /** Les valeurs d'une ligne, dates comprises, sous une forme qu'on peut écrire en JSON et relire. */
    public static function serialiser(array $ligne): array
    {
        $rendu = [];

        foreach ($ligne as $cle => $valeur) {
            $rendu[$cle] = match (true) {
                $valeur instanceof \DateTimeImmutable => ['@date' => $valeur->format('Y-m-d H:i:s'), '@classe' => 'immuable'],
                $valeur instanceof \DateTimeInterface => ['@date' => $valeur->format('Y-m-d H:i:s'), '@classe' => 'carbon'],
                is_array($valeur) => self::serialiser($valeur),
                default => $valeur,
            };
        }

        return $rendu;
    }

    /** L'inverse de `serialiser()` : la ligne telle que le lecteur l'avait rendue. */
    public static function deserialiser(array $ligne): array
    {
        $rendu = [];

        foreach ($ligne as $cle => $valeur) {
            if (is_array($valeur) && isset($valeur['@date'])) {
                $rendu[$cle] = ($valeur['@classe'] ?? '') === 'immuable'
                    ? new \DateTimeImmutable($valeur['@date'])
                    : Carbon::parse($valeur['@date']);
            } else {
                $rendu[$cle] = is_array($valeur) ? self::deserialiser($valeur) : $valeur;
            }
        }

        return $rendu;
    }

    private function doublonsGardes(): bool
    {
        return $this->doublonsGardes ??= \App\Support\SchemaDisponible::table('doublons_import');
    }

    private static function marque(Model $ligne): string
    {
        return $ligne->getTable().'#'.$ligne->getKey();
    }

    /** La ville déclarée au dépôt, pour la ligne en cours — les formats qui en ont besoin dans `ecrire()`. */
    protected ?int $villeDuDepotCourante = null;

    /**
     * Un point d'accroche, appelé après chaque rattachement et avant l'écriture — même en
     * simulation. Sans effet par défaut.
     *
     * @param  array{ville_id: int|null, site_id: int|null, code: string|null, source: string, presumee: bool}  $ou
     */
    protected function apresRattachement(array $ou, ?int $villeDuDepot, Resultat $resultat): void {}

    /**
     * Pourquoi refuser cette ligne, ou null pour l'accepter.
     *
     * Les formats précisent ; par défaut on ne refuse que ce qui n'a pas de clé, puisque
     * sans clé on ne saurait ni la créer ni la retrouver.
     */
    protected function refuser(array $ligne): ?string
    {
        foreach (static::colonnesObligatoires() as $cle) {
            if (trim((string) ($ligne[$cle] ?? '')) === '') {
                $intitule = static::colonnes()[$cle] ?? $cle;

                return "La colonne « {$intitule} » est vide.";
            }
        }

        return null;
    }

    /**
     * Ce qu'on sait déjà du rattachement de cette affaire, quand le format sait la retrouver.
     *
     * `presume` compte autant que `site_id` : il dit si ce qui est en base a été établi par
     * la donnée ou seulement supposé. Sans lui, un import répété transformerait ses propres
     * suppositions en certitudes.
     *
     * @return array{site_id: int|null, presume: bool}
     */
    protected function dossierExistant(array $ligne): array
    {
        return ['site_id' => null, 'presume' => false];
    }

    /** La feuille dont l'en-tête ressemble le plus à celui qu'on attend. */
    protected function feuilleDe(Lecteur $lecteur, Resultat $resultat): ?string
    {
        $feuilles = $lecteur->feuilles();
        $meilleure = null;
        $meilleurScore = 0;

        foreach ($feuilles as $feuille) {
            $score = 0;
            $sondees = 0;

            foreach ($lecteur->lignes($feuille) as $cellules) {
                $score = max($score, count($this->correspondance($cellules)));

                if (++$sondees >= static::LIGNES_SONDEES) {
                    break;
                }
            }

            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleure = $feuille;
            }
        }

        // Aucune feuille ne ressemble à rien : on ouvre la première et le parcours
        // conclura proprement par « aucun en-tête reconnu ».
        $resultat->feuille = $meilleure ?? ($feuilles[0] ?? null);

        return $resultat->feuille;
    }

    /**
     * Les colonnes reconnues dans une ligne candidate : clé interne => position.
     *
     * La comparaison passe par la normalisation du module — casse, accents et ponctuation
     * effacés — parce que « N° FICHE RECEPTION » et « N FICHE RECEPTION » désignent la
     * même chose et qu'on ne va pas se disputer sur un signe degré.
     *
     * @return array<string, int>
     */
    protected function correspondance(array $cellules): array
    {
        $attendues = [];

        foreach (static::colonnes() as $cle => $intitule) {
            $attendues[CorrespondanceImport::normaliser($intitule)] = $cle;
        }

        $trouvees = [];

        foreach ($cellules as $position => $valeur) {
            if (! is_string($valeur)) {
                continue;
            }

            $normalise = CorrespondanceImport::normaliser($valeur);
            $cle = $attendues[$normalise] ?? null;

            // La première occurrence gagne : un export qui répète un intitulé plus bas
            // (« Ancienneté factures » apparaît deux fois dans l'état des impayés) ne doit
            // pas faire glisser la colonne déjà trouvée.
            if ($cle !== null && ! isset($trouvees[$cle])) {
                $trouvees[$cle] = $position;
            }
        }

        return $trouvees;
    }

    /**
     * À quel point ce fichier ressemble à ce format — sans rien écrire, sans rien décider.
     *
     * C'est la question qu'il faut poser **avant** l'import, et qu'on ne posait pas : on
     * faisait confiance à la liste déroulante. Or déposer l'état des impayés en annonçant
     * « devis » ne produisait pas un refus clair mais un import à zéro ligne, indiscernable
     * d'un fichier vide. La personne recommençait, changeait de ville, doutait du logiciel.
     *
     * On rend donc trois choses, dans l'ordre où elles servent à quelqu'un :
     * combien de colonnes attendues sont là, si les indispensables y sont, et **lesquelles
     * manquent** — parce qu'un refus qui ne nomme pas ce qui manque ne se corrige pas.
     *
     * @return array{score: int, suffisante: bool, manquantes: list<string>}
     */
    public function affinite(Lecteur $lecteur): array
    {
        $meilleure = [];
        $score = 0;

        foreach ($lecteur->feuilles() as $feuille) {
            $sondees = 0;

            foreach ($lecteur->lignes($feuille) as $cellules) {
                $candidat = $this->correspondance($cellules);

                if (count($candidat) > $score) {
                    $score = count($candidat);
                    $meilleure = $candidat;
                }

                // Une feuille dont l'en-tête est reconnu en entier n'a plus rien à
                // apprendre : on s'arrête là plutôt que de lire tout le classeur.
                if ($this->suffisante($candidat) && $score === count(static::colonnes())) {
                    break 2;
                }

                if (++$sondees >= static::LIGNES_SONDEES) {
                    break;
                }
            }
        }

        $manquantes = [];

        foreach (static::colonnesObligatoires() as $cle) {
            if (! isset($meilleure[$cle])) {
                $manquantes[] = static::colonnes()[$cle] ?? $cle;
            }
        }

        return [
            'score' => $score,
            'suffisante' => $manquantes === [] && $meilleure !== [],
            'manquantes' => $manquantes,
        ];
    }

    /**
     * Où se trouvent, dans ce fichier précis, les colonnes que le format connaît.
     *
     * **On relit l'en-tête plutôt que de le supposer**, pour la même raison qui a fait
     * écrire {@see correspondance()} : les exports ne rangent pas leurs colonnes dans le
     * même ordre d'un mois sur l'autre, et une position devinée écrirait un montant dans
     * une date. C'est ce qui permet à l'écran de correction de présenter « MONTANT » et
     * « DATE FACTURE » plutôt que « colonne 7 » et « colonne 3 ».
     *
     * @return list<array{cle: string, intitule: string, position: int, obligatoire: bool}>
     */
    public function planDesColonnes(Lecteur $lecteur): array
    {
        $meilleure = [];
        $resultat = new Resultat;
        $feuille = $this->feuilleDe($lecteur, $resultat);
        $sondees = 0;

        foreach ($lecteur->lignes($feuille) as $cellules) {
            $candidat = $this->correspondance($cellules);

            if (count($candidat) > count($meilleure)) {
                $meilleure = $candidat;
            }

            if ($this->suffisante($candidat) || ++$sondees >= static::LIGNES_SONDEES) {
                break;
            }
        }

        $obligatoires = static::colonnesObligatoires();
        $plan = [];

        foreach (static::colonnes() as $cle => $intitule) {
            if (! isset($meilleure[$cle])) {
                continue;
            }

            $plan[] = [
                'cle' => $cle,
                'intitule' => $intitule,
                'position' => (int) $meilleure[$cle],
                'obligatoire' => in_array($cle, $obligatoires, true),
            ];
        }

        usort($plan, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $plan;
    }

    /**
     * Les codes employés que ce fichier contient, sans rien écrire.
     *
     * Sert à une seule question, et elle est décisive au moment du dépôt : **ce fichier
     * vient-il bien de la ville qu'on annonce ?** Les codes le disent mieux que n'importe
     * quel nom de fichier, puisqu'ils sont dans la donnée et que personne ne les retape.
     *
     * @return array<string, int> le code, et le nombre de fois qu'il apparaît
     */
    public function codesRencontres(Lecteur $lecteur, int $lignesMaximum = 400): array
    {
        $entete = null;
        $codes = [];
        $lues = 0;

        foreach ($lecteur->lignes($this->feuilleProbable($lecteur)) as $cellules) {
            if ($entete === null) {
                $candidat = $this->correspondance($cellules);

                if ($this->suffisante($candidat)) {
                    $entete = $candidat;
                }

                continue;
            }

            // La référence porte le numéro complet — « FR-KZN° 010669 ». Ce sont les deux
            // lettres qu'on veut, pas le numéro : c'est le même extracteur que celui du
            // rattachement, et il doit le rester, sinon les deux ne parleraient plus du
            // même code.
            $code = CodeAgent::extraire($this->reference($this->extraire($cellules, $entete)));

            if ($code !== null && $code !== '') {
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }

            if (++$lues >= $lignesMaximum) {
                break;
            }
        }

        arsort($codes);

        return $codes;
    }

    /**
     * Ce fichier décrit-il **un exercice**, ou plusieurs ?
     *
     * **La question vient du propriétaire, le 24/09** : « tout ce qui s'importe doit être
     * dans l'exercice en cours ; le jour où je fais un import dans un exercice qui ne
     * correspond pas à l'année, le système doit le dire ».
     *
     * Il a lui-même posé les exceptions, et elles sont justes : **l'état des impayés et le
     * suivi fournisseur portent un tableau initial de plusieurs années**. Leur reprocher de
     * contenir 2024 serait leur reprocher d'être ce qu'ils sont. La balance fournisseurs
     * est dans le même cas — un solde se traîne d'un exercice à l'autre.
     *
     * Les autres décrivent une période : une situation du parc, des devis, un chiffre
     * d'affaires, une caisse. Déposer un fichier de 2025 sur l'exercice 2026 y range des
     * lignes sous une année qui n'est pas la leur, et rien ne le dirait.
     */
    public static function porteUnSeulExercice(): bool
    {
        return true;
    }

    /**
     * Les années que porte la colonne de date de ce fichier, et leur poids.
     *
     * Lu sur un échantillon, sans rien écrire : c'est un contrôle de dépôt, pas un import.
     * On ne conclut pas sur une ligne — un fichier de 2026 peut très bien contenir une
     * reprise de décembre 2025, et ce n'est pas une erreur de dépôt.
     *
     * @return array<int, int> l'année, et le nombre de lignes qui la portent
     */
    public function anneesRencontrees(Lecteur $lecteur, int $lignesMaximum = 400): array
    {
        $colonne = static::colonneDeDate();

        if ($colonne === null) {
            return [];
        }

        $entete = null;
        $annees = [];
        $lues = 0;

        foreach ($lecteur->lignes($this->feuilleProbable($lecteur)) as $cellules) {
            if ($entete === null) {
                $candidat = $this->correspondance($cellules);

                if ($this->suffisante($candidat)) {
                    $entete = $candidat;
                }

                continue;
            }

            $date = self::date($this->extraire($cellules, $entete)[$colonne] ?? null);

            if ($date !== null) {
                $annee = (int) $date->format('Y');
                $annees[$annee] = ($annees[$annee] ?? 0) + 1;
            }

            if (++$lues >= $lignesMaximum) {
                break;
            }
        }

        arsort($annees);

        return $annees;
    }

    /**
     * La colonne qui date une ligne de ce fichier.
     *
     * Devinée plutôt que déclarée, et c'est assumé : toutes les colonnes de date de tous
     * les formats commencent par `date`, la première déclarée est celle qui date la ligne
     * — la date de la fiche, celle du devis, celle de la facture. Un format qui n'en aurait
     * aucune rend null, et le contrôle se tait plutôt que de conclure sur rien.
     */
    public static function colonneDeDate(): ?string
    {
        foreach (array_keys(static::colonnes()) as $cle) {
            if (str_starts_with((string) $cle, 'date')) {
                return (string) $cle;
            }
        }

        return null;
    }

    /** La feuille qui ressemble le plus à ce format — sans journal ni effet de bord. */
    private function feuilleProbable(Lecteur $lecteur): ?string
    {
        $meilleure = null;
        $meilleurScore = 0;

        foreach ($lecteur->feuilles() as $feuille) {
            $sondees = 0;

            foreach ($lecteur->lignes($feuille) as $cellules) {
                $score = count($this->correspondance($cellules));

                if ($score > $meilleurScore) {
                    $meilleurScore = $score;
                    $meilleure = $feuille;
                }

                if (++$sondees >= static::LIGNES_SONDEES) {
                    break;
                }
            }
        }

        return $meilleure ?? ($lecteur->feuilles()[0] ?? null);
    }

    /** Vrai quand la ligne candidate porte toutes les colonnes indispensables. */
    protected function suffisante(array $correspondance): bool
    {
        foreach (static::colonnesObligatoires() as $cle) {
            if (! isset($correspondance[$cle])) {
                return false;
            }
        }

        return $correspondance !== [];
    }

    /** @return array<string, string|float|DateTimeImmutable|null> */
    protected function extraire(array $cellules, array $entete): array
    {
        $ligne = [];

        foreach ($entete as $cle => $position) {
            $ligne[$cle] = $cellules[$position] ?? null;
        }

        return $ligne;
    }

    protected function vide(array $ligne): bool
    {
        foreach ($ligne as $valeur) {
            if ($valeur !== null && trim((string) ($valeur instanceof DateTimeImmutable ? 'x' : $valeur)) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Ce qu'on garde d'une ligne rejetée : ses valeurs, bornées pour ne pas gonfler. */
    protected function pourLeJournal(array $cellules): array
    {
        $valeurs = [];

        foreach (array_slice($cellules, 0, 40, true) as $position => $valeur) {
            $valeurs[$position] = $valeur instanceof DateTimeImmutable
                ? $valeur->format('Y-m-d')
                : mb_substr((string) $valeur, 0, 120);
        }

        return $valeurs;
    }

    // ------------------------------------------------------------------ conversions

    /**
     * La date que porte une cellule, quelle que soit la façon dont elle est écrite.
     *
     * Trois cas se présentent dans les vrais fichiers, et il faut les traiter tous les
     * trois : le lecteur a reconnu une date grâce au format d'affichage ; la cellule est
     * restée un nombre parce que la colonne n'était pas mise en forme — c'est le cas des
     * dates de proforma, qui arrivent en « 46024 » ; ou c'est du texte saisi à la main.
     *
     * Ce qui n'est aucun des trois rend null. Deviner une date fausse est pire que
     * d'admettre qu'on ne sait pas : elle se retrouverait dans un chiffre d'affaires.
     */
    public static function date(string|float|DateTimeImmutable|null $valeur): ?Carbon
    {
        if ($valeur instanceof DateTimeImmutable) {
            return Carbon::instance(\DateTime::createFromImmutable($valeur))->startOfDay();
        }

        if (is_float($valeur) || (is_string($valeur) && is_numeric(trim($valeur)))) {
            $date = LecteurXlsx::dateDeSerie((float) $valeur);

            // Un numéro de série plausible, mais pas n'importe lequel : au-delà de ces
            // bornes on a affaire à un montant, pas à une date d'atelier.
            if ($date === null || $date->format('Y') < '1990' || $date->format('Y') > '2100') {
                return null;
            }

            return Carbon::instance(\DateTime::createFromImmutable($date))->startOfDay();
        }

        $texte = trim((string) $valeur);

        if ($texte === '') {
            return null;
        }

        // « 29 mai 2026 », « 1er juin 2026 », « 3 févr. 2026 » : l'extrait AFG passé par un PDF (27/10)
        // écrit ses dates en toutes lettres.
        if (preg_match('/^(\d{1,2})(?:er)?\s+([[:alpha:]éû\.]+)\s+(\d{4})$/iu', $texte, $m)) {
            $mois = self::moisEnLettres($m[2]);

            if ($mois !== null && checkdate($mois, (int) $m[1], (int) $m[3])) {
                return Carbon::create((int) $m[3], $mois, (int) $m[1])->startOfDay();
            }
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y'] as $motif) {
            try {
                $date = Carbon::createFromFormat($motif, $texte);
            } catch (\Throwable) {
                continue;
            }

            // Le tour complet écarte les dates qui débordent — « 45/13/2026 » se replierait
            // silencieusement sur un jour valide si on ne le vérifiait pas.
            if ($date && $date->format($motif) === $texte) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    /** Le numéro d'un mois écrit en français, en entier ou abrégé — null s'il n'en est pas un. */
    private static function moisEnLettres(string $mot): ?int
    {
        $mot = rtrim(mb_strtolower($mot), '.');
        $mot = strtr($mot, ['é' => 'e', 'û' => 'u', 'è' => 'e']);

        foreach (['janv' => 1, 'fev' => 2, 'mars' => 3, 'avr' => 4, 'mai' => 5, 'juin' => 6, 'juil' => 7,
            'aout' => 8, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12] as $debut => $numero) {
            if (str_starts_with($mot, $debut)) {
                return $numero;
            }
        }

        return null;
    }

    /**
     * Le montant que porte une cellule.
     *
     * Les exports mêlent les nombres propres et le texte mis en forme à la main : « 34 205
     * 673 », « 1,384,588,525.53 », « 250 000 F CFA ». On retire tout ce qui n'est ni
     * chiffre ni séparateur, puis on tranche entre virgule décimale et séparateur de
     * milliers d'après la position : une virgule suivie d'exactement trois chiffres jusqu'à
     * la fin est un séparateur de milliers, partout ailleurs c'est une décimale.
     */
    public static function montant(string|float|DateTimeImmutable|null $valeur): ?float
    {
        if ($valeur instanceof DateTimeImmutable) {
            return null;
        }

        if (is_float($valeur)) {
            return $valeur;
        }

        $texte = trim((string) $valeur);

        if ($texte === '') {
            return null;
        }

        $negatif = str_starts_with($texte, '-') || (str_starts_with($texte, '(') && str_ends_with($texte, ')'));
        $texte = preg_replace('/[^0-9,.]/', '', $texte) ?? '';

        if ($texte === '' || ! preg_match('/\d/', $texte)) {
            return null;
        }

        $virgule = strrpos($texte, ',');
        $point = strrpos($texte, '.');
        $dernier = max($virgule === false ? -1 : $virgule, $point === false ? -1 : $point);

        if ($dernier >= 0) {
            $apres = strlen($texte) - $dernier - 1;

            if ($apres === 3) {
                // Trois chiffres après le dernier séparateur : c'est un millier, sauf s'il
                // n'y en a qu'un seul et qu'il est un point — « 1.234 » reste ambigu, et on
                // suit alors la convention du fichier, qui écrit les milliers par paquets.
                $texte = str_replace([',', '.'], '', $texte);
            } else {
                $texte = str_replace([',', '.'], ['', '.'], substr($texte, 0, $dernier))
                    .'.'.substr($texte, $dernier + 1);
            }
        }

        $texte = preg_replace('/[^0-9.]/', '', $texte) ?? '';

        if ($texte === '' || ! is_numeric($texte)) {
            return null;
        }

        return $negatif ? -(float) $texte : (float) $texte;
    }

    /** Un texte propre, borné, ou null. */
    public static function texte(string|float|DateTimeImmutable|null $valeur, int $longueur = 255): ?string
    {
        if ($valeur === null) {
            return null;
        }

        if ($valeur instanceof DateTimeImmutable) {
            return $valeur->format('d/m/Y');
        }

        if (is_float($valeur)) {
            // Un nombre entier lu dans une colonne de texte ne doit pas devenir « 190.0 ».
            $valeur = $valeur == (int) $valeur ? (string) (int) $valeur : (string) $valeur;
        }

        // Les retours à la ligne des champs de travaux sont conservés, mais les caractères
        // de contrôle qui traînent dans les exports sont retirés : ils cassent l'affichage
        // et n'apportent rien.
        $texte = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $valeur) ?? '';
        $texte = trim($texte);

        return $texte === '' ? null : mb_substr($texte, 0, $longueur);
    }
}

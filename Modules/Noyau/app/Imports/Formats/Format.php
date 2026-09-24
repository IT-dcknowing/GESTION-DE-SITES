<?php

namespace Modules\Noyau\Imports\Formats;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Lecteurs\LecteurXlsx;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
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

            $resultat->compter($ecrire ? $this->ecrire($ligne, $ou, $lotId) : 'ignore');

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

                if (++$sondees >= self::LIGNES_SONDEES) {
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

                if (++$sondees >= self::LIGNES_SONDEES) {
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

            if ($this->suffisante($candidat) || ++$sondees >= self::LIGNES_SONDEES) {
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

                if (++$sondees >= self::LIGNES_SONDEES) {
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

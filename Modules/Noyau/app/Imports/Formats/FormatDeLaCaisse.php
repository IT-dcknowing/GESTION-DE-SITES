<?php

namespace Modules\Noyau\Imports\Formats;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Modeles\OuvertureCaisse;

/**
 * Les états de caisse — un onglet par mois.
 *
 * **Ce format est le seul à parcourir plusieurs feuilles.** Les autres fichiers du logiciel
 * sont une longue liste ; celui-ci est tenu à la main, un onglet par mois : « DEC 25 »,
 * « JANV 26 », « FEV 26 », « MARS 26 ». Ne lire que la première perdrait tout le reste, et
 * les lire toutes suppose de retrouver l'en-tête dans chacune — elle est en ligne 5, sous un
 * titre et un solde d'ouverture.
 *
 * **Le doublon de janvier, et pourquoi on ne tranche pas à la place du comptable.** Deux
 * onglets décrivent le même mois : « JANV 26 » (19 lignes) et « JANV 26-CONGES » (342
 * lignes), même solde d'ouverture, même première ligne. Importer les deux doublerait le
 * mois. On garde donc **la feuille la plus fournie pour un mois donné** et on écarte
 * l'autre en le disant, plutôt que de les additionner en silence. Le nom de la feuille est
 * recopié sur chaque ligne : si l'arbitrage est mauvais, on sait exactement quoi retirer.
 *
 * **Les entrées et les sorties sont deux colonnes, pas un montant signé.** On enregistre le
 * montant toujours positif et le sens à côté : un total de sorties se lit alors sans avoir
 * à se demander si les signes ont été respectés à la saisie.
 *
 * Le solde annoncé par le fichier est recopié sans être vérifié. Ce n'est pas de la
 * paresse : c'est le solde tel que la caisse le déclarait ce jour-là, et le recalculer
 * effacerait justement l'écart qu'on voudrait pouvoir constater.
 *
 * **Le solde d'ouverture, lu depuis le 23/09.** Chaque onglet l'écrit en quatrième ligne,
 * au-dessus des intitulés — « SOLDE D'OUVERTURE | 662 700 » —, et on passait dessus sans
 * le voir : la lecture commençait à la ligne des colonnes. C'est pourtant ce que la caisse
 * contenait avant le premier mouvement du mois, et aucun cumul de nos lignes ne peut le
 * retrouver. Mesuré sur le classeur réel : décembre ouvre à 662 700, janvier à 293 700,
 * février à **0** et mars à 263 100 — février n'est donc pas la suite de janvier, et c'est
 * une information, pas une erreur de lecture.
 */
class FormatDeLaCaisse extends Format
{
    /** Là où l'en-tête se trouve dans chaque onglet, sous le titre et le solde d'ouverture. */
    private const LIGNE_D_ENTETE = 5;

    /** Le nom d'une caisse que son fichier ne nomme pas. */
    public const CAISSE_SANS_NOM = 'Caisse';

    public static function cle(): string
    {
        return 'caisse';
    }

    public static function libelle(): string
    {
        // Les deux formats de caisse décrivent **la même chose** — les entrées et les
        // sorties d'une caisse — et écrivent dans les mêmes tables. Ce qui les distingue
        // est le document qu'on apporte : un classeur tenu à la main pour Abidjan, un
        // état imprimé par le logiciel comptable pour Bouaké et San-Pédro. Les libellés
        // le disent maintenant dans les mêmes mots, sur le même modèle, pour qu'on choisisse
        // par ce qu'on a en main plutôt qu'en devinant lequel est lequel (24/09).
        return 'Caisse — le classeur tenu à la main (Excel)';
    }

    public static function colonnes(): array
    {
        return [
            'date' => 'Date',
            // Les intitulés sont recopiés **entiers**, tels que le classeur les écrit :
            // la reconnaissance se fait sur l'intitulé complet, et « Libellé de la
            // transaction » seul ne rencontrait rien. C'est volontairement strict — un
            // rapprochement approximatif finirait par ranger une colonne sous une autre.
            'libelle' => 'Libellé de la transaction(objet,N°Fiche de reception,N° facture)',
            'entree' => 'Entrées',
            'sortie' => 'sorties',
            'solde' => 'Solde',
            'immatriculation' => 'Immatriculation',
            'beneficiaire' => 'Bénéficiaire (fournisseurs)/remettant (client)',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['libelle'];
    }

    /**
     * Parcourt tous les onglets mensuels, et non le seul premier.
     *
     * La méthode générale cherche la meilleure feuille et s'y tient — ce qui convient à tous
     * les autres formats. Ici, chaque feuille est un mois : les ignorer reviendrait à
     * n'importer qu'un douzième du fichier.
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
        $feuilles = $this->feuillesRetenues($lecteur);
        $resultat->feuille = implode(' + ', $feuilles);
        $resultat->ligneDEnTete = self::LIGNE_D_ENTETE;

        foreach ($feuilles as $feuille) {
            $this->parcourirUnMois($lecteur, $feuille, $resultat, $villeDuDepot, $siteDuDepot, $lotId, $ecrire, $surAvancee);
        }

        // `terminer()` n'est pas appelé ici : c'est l'exécuteur qui s'en charge, une fois,
        // après le parcours — y compris quand celui-ci s'est mal passé.
        $resultat->desaccords = $this->rattachement->desaccords();

        return $resultat;
    }

    /**
     * Les onglets à lire : un seul par mois, le plus fourni.
     *
     * Le mois se lit dans le nom de la feuille, réduit à ses lettres et chiffres —
     * « JANV 26 » et « JANV 26-CONGES » donnent tous deux « JANV26 » une fois le suffixe
     * retiré. C'est ce rapprochement qui permet de voir qu'ils décrivent le même mois.
     *
     * @return list<string>
     */
    private function feuillesRetenues(Lecteur $lecteur): array
    {
        $parMois = [];

        foreach ($lecteur->feuilles() as $feuille) {
            $mois = $this->moisDe($feuille);
            $lignes = $this->compterLesLignes($lecteur, $feuille);

            // À mois égal, la feuille la plus fournie l'emporte : celle de janvier qui
            // porte 342 lignes contre 19 est manifestement la tenue complète, l'autre un
            // début abandonné.
            if (! isset($parMois[$mois]) || $lignes > $parMois[$mois]['lignes']) {
                $parMois[$mois] = ['feuille' => $feuille, 'lignes' => $lignes];
            }
        }

        return array_values(array_map(fn ($e) => $e['feuille'], $parMois));
    }

    /** Le mois désigné par un nom d'onglet, dépouillé de ses suffixes. */
    private function moisDe(string $feuille): string
    {
        $reduit = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($feuille)) ?? $feuille;

        // Tout ce qui suit le millésime à deux chiffres est un commentaire de l'auteur du
        // classeur — « CONGES », « BIS », « SUITE » — et non un autre mois.
        return preg_replace('/^([A-Z]+\d{2}).*$/', '$1', $reduit) ?? $reduit;
    }

    private function compterLesLignes(Lecteur $lecteur, string $feuille): int
    {
        $n = 0;

        foreach ($lecteur->lignes($feuille) as $numero => $cellules) {
            if ($numero > self::LIGNE_D_ENTETE) {
                $n++;
            }
        }

        return $n;
    }

    private function parcourirUnMois(
        Lecteur $lecteur,
        string $feuille,
        Resultat $resultat,
        ?int $villeDuDepot,
        ?int $siteDuDepot,
        ?int $lotId,
        bool $ecrire,
        ?\Closure $surAvancee,
    ): void {
        $entete = null;
        $ouverture = null;
        $premierJour = null;

        // Le rattachement ne dépend d'aucune ligne : le fichier ne porte pas d'atelier, et
        // c'est le dépôt qui le déclare. Le résoudre une fois par onglet plutôt qu'une fois
        // par ligne, c'est mille cent cinquante résolutions de moins pour le même résultat.
        $ou = $this->rattachement->resoudre(null, null, $villeDuDepot, null, false, $siteDuDepot);

        foreach ($lecteur->lignes($feuille) as $numero => $cellules) {
            if ($entete === null) {
                // Au-dessus des intitulés, l'onglet annonce ce que la caisse contenait
                // avant son premier mouvement. On le lit au passage, plutôt que de sauter
                // directement à la ligne des colonnes comme on le faisait.
                $ouverture ??= $this->soldeDOuverture($cellules);

                if ($numero >= self::LIGNE_D_ENTETE) {
                    $entete = $this->correspondance($cellules);
                }

                continue;
            }

            $ligne = $this->extraire($cellules, $entete);

            if ($this->vide($ligne)) {
                continue;
            }

            $resultat->lues++;

            if ($motif = $this->refuser($ligne)) {
                $resultat->rejeter($numero, $feuille.' — '.$motif, $this->pourLeJournal($cellules));

                continue;
            }

            $premierJour ??= self::date($ligne['date'] ?? null);

            $resultat->compter($ecrire ? $this->ecrireLeMouvement($ligne, $ou, $lotId, $feuille) : 'ignore');

            if ($surAvancee !== null) {
                $surAvancee($resultat->lues);
            }
        }

        if ($ecrire && $ouverture !== null && $premierJour !== null) {
            $this->noterLOuverture($ouverture, $premierJour, $feuille, $ou, $lotId);
        }
    }

    /**
     * Le solde d'ouverture annoncé par une rangée, ou null si ce n'en est pas une.
     *
     * Le montant est posé dans la colonne des soldes, à droite de la mention : on prend la
     * dernière cellule chiffrée de la rangée, et non la première, qui serait la mention
     * elle-même.
     */
    private function soldeDOuverture(array $cellules): ?int
    {
        $entier = implode(' ', array_map(
            fn ($valeur) => $valeur instanceof DateTimeImmutable ? '' : (string) $valeur,
            $cellules,
        ));

        if (preg_match('/SOLDE\s*D.?\s*OUVERTURE/ui', $entier) !== 1) {
            return null;
        }

        foreach (array_reverse($cellules, true) as $valeur) {
            $montant = self::montant($valeur);

            if ($montant !== null && preg_match('/[0-9]/', (string) $valeur) === 1) {
                return (int) round($montant);
            }
        }

        return null;
    }

    /**
     * Consigne l'ouverture du mois : une caisse, un mois, un solde de départ.
     *
     * Le classeur ne nomme pas sa caisse — son titre dit seulement « CAISSE DU MOIS DE
     * DECEMBRE 2025 ». Elle s'appelle donc « Caisse », ce qu'elle est, et c'est la ville du
     * dépôt qui la distingue de celle d'une autre ville. Lui inventer un nom que le fichier
     * ne porte pas serait ajouter une donnée là où il n'y en a pas.
     */
    private function noterLOuverture(int $solde, Carbon $premierJour, string $feuille, array $ou, ?int $lotId): void
    {
        OuvertureCaisse::consigner(
            [
                'entreprise_id' => $this->entrepriseId,
                'ville_id' => $ou['ville_id'],
                'caisse' => self::CAISSE_SANS_NOM,
                'debut' => $premierJour->copy()->startOfMonth()->toDateString(),
                'fin' => $premierJour->copy()->endOfMonth()->toDateString(),
            ],
            [
                'site_id' => $ou['site_id'],
                'solde_avant' => $solde,
                'source' => OuvertureCaisse::DU_CLASSEUR,
                'feuille' => mb_substr($feuille, 0, 60),
                'lot_import_id' => $lotId,
            ],
        );
    }

    /** Une ligne de caisse ne porte aucun numéro de fiche : il n'y a pas de code à en tirer. */
    /** Le classeur de caisse est tenu par ville, à la main : sa ville vient du dépôt, jamais d'un code de deux lettres qu'il ne porte pas. */
    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    protected function reference(array $ligne): ?string
    {
        return null;
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if (self::date($ligne['date'] ?? null) === null) {
            return 'La date du mouvement est absente ou illisible.';
        }

        if ($this->montantDuMouvement($ligne) === null) {
            // Les lignes de sous-total n'ont ni entrée ni sortie : ce ne sont pas des
            // mouvements, et les compter fausserait le total du mois.
            return "Ni entrée ni sortie : cette ligne n'est pas un mouvement de caisse.";
        }

        return null;
    }

    /** @return array{sens: string, montant: int}|null */
    private function montantDuMouvement(array $ligne): ?array
    {
        $entree = self::montant($ligne['entree'] ?? null);
        $sortie = self::montant($ligne['sortie'] ?? null);

        if ($entree !== null && abs($entree) > 0.009) {
            return ['sens' => MouvementCaisse::ENTREE, 'montant' => (int) round(abs($entree))];
        }

        if ($sortie !== null && abs($sortie) > 0.009) {
            return ['sens' => MouvementCaisse::SORTIE, 'montant' => (int) round(abs($sortie))];
        }

        return null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        // Le parcours de ce format passe par ecrireLeMouvement(), qui connaît la feuille.
        return $this->ecrireLeMouvement($ligne, $rattachement, $lotId, null);
    }

    private function ecrireLeMouvement(array $ligne, array $rattachement, ?int $lotId, ?string $feuille): string
    {
        $mouvement = $this->montantDuMouvement($ligne);
        $date = self::date($ligne['date'] ?? null);
        $libelle = self::texte($ligne['libelle'] ?? null, 255);

        $valeurs = [
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,
            'date' => $date,
            'sens' => $mouvement['sens'],
            'libelle' => $libelle,
            'montant' => $mouvement['montant'],
            'solde_annonce' => ($s = self::montant($ligne['solde'] ?? null)) === null ? null : (int) round($s),
            'beneficiaire' => self::texte($ligne['beneficiaire'] ?? null, 160),
            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 40),
            'mois' => $date?->format('Y-m'),
            'feuille' => $feuille === null ? null : mb_substr($feuille, 0, 60),
        ];

        // Une caisse n'a pas de numéro de pièce : deux dépenses de 5 000 F le même jour pour
        // le même motif sont possibles et légitimes. La clé de rapprochement est donc la
        // ligne entière — date, sens, montant, libellé — ce qui protège du redépôt du même
        // fichier sans interdire deux mouvements réellement identiques dans deux mois
        // différents.
        $existant = MouvementCaisse::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('date', $date)
            ->where('sens', $mouvement['sens'])
            ->where('montant', $mouvement['montant'])
            ->where('libelle', $libelle)
            ->first();

        if ($existant === null) {
            MouvementCaisse::withoutGlobalScopes()->create($valeurs);

            return 'cree';
        }

        $existant->fill($valeurs);

        if (! $existant->isDirty()) {
            return 'ignore';
        }

        $existant->save();

        return 'maj';
    }
}

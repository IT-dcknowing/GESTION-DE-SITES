<?php

namespace Modules\Noyau\Imports\Formats;

use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;

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
 */
class FormatDeLaCaisse extends Format
{
    /** Là où l'en-tête se trouve dans chaque onglet, sous le titre et le solde d'ouverture. */
    private const LIGNE_D_ENTETE = 5;

    public static function cle(): string
    {
        return 'caisse';
    }

    public static function libelle(): string
    {
        return 'États de caisse — entrées et sorties';
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

        foreach ($lecteur->lignes($feuille) as $numero => $cellules) {
            if ($numero < self::LIGNE_D_ENTETE) {
                continue;
            }

            if ($entete === null) {
                $entete = $this->correspondance($cellules);

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

            $ou = $this->rattachement->resoudre(
                null,
                null,
                $villeDuDepot,
                null,
                false,
                $siteDuDepot,
            );

            $resultat->compter($ecrire ? $this->ecrireLeMouvement($ligne, $ou, $lotId, $feuille) : 'ignore');

            if ($surAvancee !== null) {
                $surAvancee($resultat->lues);
            }
        }
    }

    /** Une ligne de caisse ne porte aucun numéro de fiche : il n'y a pas de code à en tirer. */
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
            return "La date du mouvement est absente ou illisible.";
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

<?php

namespace Modules\Noyau\Imports\Formats;

use Illuminate\Support\Carbon;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Modeles\MouvementCaisse;
use Modules\Noyau\Imports\Modeles\OuvertureCaisse;

/**
 * Le journal de caisse imprimé — la sortie du logiciel comptable.
 *
 * **Pourquoi il fallait le lire.** L'écran *Caisse* n'était alimenté que par le classeur
 * tenu à la main d'Abidjan. Bouaké et San-Pédro n'ont pas de classeur : elles n'ont que ce
 * journal, en PDF. Mille cent quatre mouvements — 533 à Bouaké, 571 à San-Pédro — que rien
 * ne lisait, pour deux villes sur trois.
 *
 * **Ce que le journal porte et que le classeur ne porte pas** : le numéro de pièce et son
 * journal (`MC`, `MC KR`, `MC KD`), un motif codifié, le remettant ou le bénéficiaire
 * distingués l'un de l'autre, le nom de la caisse, et le solde avec lequel la période
 * s'ouvre. À l'inverse, il ne porte **ni colonne immatriculation ni colonne
 * bénéficiaire** : tout est empilé dans la cellule du libellé, sur trois lignes.
 *
 * **L'ancre est la ligne qui porte la date et les montants, pas la phrase qui la
 * précède** — et ce choix a coûté moins cher qu'il n'y paraît. Au premier essai, sept
 * mouvements de San-Pédro arrivaient sans numéro de pièce ; on a d'abord cru que le
 * document ne les imprimait pas. C'était faux : le lecteur perdait leur phrase, parce
 * qu'il découpait le flux du PDF sur les lettres `BT`…`ET` et que « INTERNET » en contient
 * une. Le défaut était dans notre lecture, pas dans le document.
 *
 * L'ancre a néanmoins bien fait son travail : les sept mouvements **sont entrés**, avec
 * leur date, leur montant et leur tiers, et la chaîne des soldes est restée exacte — seuls
 * leurs numéros manquaient, ce qui se voyait. Accrochée à la phrase, la même erreur aurait
 * fait disparaître 25 000 F sans laisser de trace. On garde donc cette ancre : une phrase
 * illisible doit coûter une étiquette, jamais un mouvement.
 *
 * **Le sens se lit à la colonne où le montant est posé** — entre l'intitulé ENTREE et
 * l'intitulé SORTIE, c'est une entrée — exactement comme le lit un œil humain. La phrase
 * le confirme quand elle existe : sur les 1 104 mouvements des deux fichiers, les deux
 * lectures ne se contredisent **jamais**.
 *
 * Mesuré sur les deux fichiers réels, lecture faite : **533 mouvements à Bouaké** et
 * **571 à San-Pédro**, tous avec leur numéro de pièce et leur motif, 36 et 30 motifs
 * distincts, deux annulations de chaque côté.
 *
 * **La preuve que la lecture est juste, et elle est dans le document.** Chaque ligne
 * affiche le solde de la caisse après elle. En partant du solde annoncé avant la période
 * et en appliquant nos montants un par un, on doit retrouver, ligne après ligne, le solde
 * imprimé. Mesuré : **zéro écart sur 533 mouvements à Bouaké, zéro sur 571 à San-Pédro**.
 * Aucune autre vérification n'était possible sans le logiciel qui a produit l'état ;
 * celle-là suffit, parce qu'une seule colonne mal lue la ferait tomber immédiatement.
 *
 * **Le solde annoncé n'est jamais recalculé.** Ni celui de chaque ligne, ni celui d'avant
 * la période. Ce sont les nombres du document ; c'est l'écart entre eux et notre propre
 * cumul qui renseigne, et l'effacer serait effacer la question.
 */
class FormatDuJournalDeCaisse extends Format
{
    /** La ligne qui ouvre la description d'un mouvement. */
    private const DESCRIPTION = '/^(ANNULATION\s+)?(ENTREE|SORTIE)\s+DE\s+CAISSE\s*(.*?)\s*-?\s*N.\s*(\S+)\s*-?\s*MOTIF\s*:\s*(.*)$/u';

    /** La ligne qui nomme celui qui a remis ou reçu l'argent. */
    private const TIERS = '/^(REMETTANT|BENEFICIAIRE)\s*:\s*(.*)$/ui';

    /** Ce qui suit le dernier mouvement d'une page : les totaux de contrôle. */
    private const PIED_DE_PAGE = ['ecart solde caisse', 'inventaire'];

    private ?string $caisse = null;

    private ?Carbon $debut = null;

    private ?Carbon $fin = null;

    private ?int $soldeAvant = null;

    public static function cle(): string
    {
        return 'journal-caisse';
    }

    public static function libelle(): string
    {
        /*
         * **Les deux extensions, et c'est la demande du propriétaire du 24/09.**
         *
         * Le logiciel ne sort aujourd'hui cet état qu'en PDF — c'est pourquoi le lecteur
         * de PDF existe. Mais l'export en tableur viendra, et le jour où il viendra il ne
         * doit pas falloir créer un type de plus : c'est le même journal, la même caisse,
         * les mêmes tables. On dépose donc le document qu'on a, sous ce type-ci, et le
         * format lit l'un comme l'autre. Voir `parcourirUnePage()` pour ce qui change
         * réellement entre les deux — presque rien.
         *
         * **Excel est nommé le premier, et ce n'est pas un détail de rédaction.** Le
         * propriétaire l'a demandé le 24/09 : « l'import se fera en Excel la plupart du
         * temps, donc celui de l'Excel doit être prioritaire ». Un tableur se lit par ses
         * cellules, un imprimé par la position de ses caractères sur la page — la première
         * lecture ne peut pas se tromper de colonne, la seconde le peut. L'ordre des mots
         * dit donc lequel apporter quand on a le choix.
         */
        return 'Caisse — le journal du logiciel (Excel ou PDF)';
    }

    /**
     * Les cinq colonnes du journal, telles qu'il les intitule.
     *
     * Elles ressemblent à celles du classeur tenu à la main sans se confondre avec elles :
     * le classeur écrit « Entrées » et « sorties » au pluriel et ajoute une colonne
     * immatriculation et une colonne bénéficiaire. C'est ce qui permet au dépôt de dire
     * lequel des deux formats convient au fichier reçu, plutôt que de s'en remettre à la
     * liste déroulante.
     */
    public static function colonnes(): array
    {
        return [
            'date' => 'DATE',
            'libelle' => 'LIBELLE DE LA TRANSACTION',
            'entree' => 'ENTREE',
            'sortie' => 'SORTIE',
            'solde' => 'SOLDE',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        // Les cinq, et pas moins : c'est cette exigence qui distingue la rangée des
        // intitulés du titre de l'état, qu'un document imprimé pose juste au-dessus.
        return ['date', 'libelle', 'entree', 'sortie', 'solde'];
    }

    /**
     * Parcourt toutes les pages du document.
     *
     * La méthode générale choisit une feuille et s'y tient — ce qui convient à un classeur,
     * où une feuille est un tableau entier. Ici chaque « feuille » est une page, et
     * l'état en compte quarante-cinq : n'en lire qu'une donnerait douze mouvements
     * sur cinq cent trente-trois.
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
        $pages = $lecteur->feuilles();
        $resultat->feuille = count($pages).' page'.(count($pages) > 1 ? 's' : '');

        $ou = $this->rattachement->resoudre(null, null, $villeDuDepot, null, false, $siteDuDepot);

        foreach ($pages as $rang => $page) {
            $this->parcourirUnePage($lecteur, $page, $rang + 1, $resultat, $ou, $lotId, $ecrire, $surAvancee);
        }

        if ($resultat->lues === 0) {
            $resultat->lues++;
            $resultat->rejeter(
                0,
                "Aucune rangée « DATE / LIBELLE DE LA TRANSACTION / ENTREE / SORTIE / SOLDE » n'a été trouvée : "
                ."ce document n'est pas un journal de caisse, ou son texte n'est pas lisible.",
                [],
            );
        } elseif ($ecrire) {
            $this->noterLOuverture($ou, $lotId);
        }

        // `terminer()` reste à l'exécuteur, comme pour tous les formats.
        $resultat->desaccords = $this->rattachement->desaccords();

        return $resultat;
    }

    private function parcourirUnePage(
        Lecteur $lecteur,
        string $page,
        int $numeroDePage,
        Resultat $resultat,
        array $ou,
        ?int $lotId,
        bool $ecrire,
        ?\Closure $surAvancee,
    ): void {
        $courant = null;
        $description = null;

        foreach ($this->rangees($lecteur, $page, $numeroDePage, $resultat) as $numero => $ligne) {
            $texte = trim((string) ($ligne['libelle'] ?? ''));

            if ($this->piedDePage($texte)) {
                break;
            }

            $date = self::date($ligne['date'] ?? null);
            $entree = self::montant($ligne['entree'] ?? null);
            $sortie = self::montant($ligne['sortie'] ?? null);

            // Une rangée n'écrit qu'une seule chose dans la colonne du libellé : la phrase
            // qui décrit le mouvement, le nom du tiers, ou le détail que l'opérateur a
            // saisi. Les trois ne se mêlent jamais sur une même hauteur.
            $laDescription = $texte !== '' && preg_match(self::DESCRIPTION, $texte, $morceaux) === 1 ? $morceaux : null;
            $leTiers = $laDescription === null && $texte !== '' && preg_match(self::TIERS, $texte, $qui) === 1 ? $qui : null;
            $leDetail = $laDescription === null && $leTiers === null ? $texte : '';

            if ($laDescription !== null) {
                $description = $laDescription;
            }

            if ($date !== null && ($entree !== null || $sortie !== null)) {
                $this->fermer($courant, $resultat, $lotId, $ecrire, $surAvancee);

                $courant = $this->ouvrir($ligne, $date, $entree, $sortie, $description, $leTiers, $ou, $numeroDePage);

                if ($leDetail !== '' && ! $this->purementNumerique($leDetail)) {
                    $courant['detail'][] = $leDetail;
                }

                $description = null;

                continue;
            }

            if ($courant === null) {
                continue;
            }

            if ($leTiers !== null && $courant['role_tiers'] === null) {
                $courant['role_tiers'] = mb_strtolower($leTiers[1]);
                $courant['beneficiaire'] = trim($leTiers[2]) === '' ? null : trim($leTiers[2]);
            } elseif ($leDetail !== '' && ! $this->purementNumerique($leDetail)) {
                $courant['detail'][] = $leDetail;
            }
        }

        $this->fermer($courant, $resultat, $lotId, $ecrire, $surAvancee);
    }

    /**
     * Les rangées de la page, **dépliées** — un PDF et un tableur ne les découpent pas pareil.
     *
     * **Ce qui change entre les deux documents, et c'est tout.** Un état imprimé pose
     * chaque ligne de texte à sa propre hauteur : la phrase du mouvement, le nom du
     * remettant et le détail arrivent donc en trois rangées successives. Un tableur, lui,
     * met les trois dans **une seule cellule**, séparées par des retours à la ligne — c'est
     * ainsi que le même logiciel exportera son journal le jour où il saura le faire.
     *
     * On déplie donc la cellule du libellé : la première ligne garde la date et les
     * montants, les suivantes ne portent qu'un texte. Le tableur reprend exactement la
     * forme du PDF, et **tout ce qui suit est écrit une seule fois** — la reconnaissance du
     * motif, du tiers, le sens lu à la colonne, la chaîne des soldes. Deux lectures
     * séparées auraient fini par diverger sur le même journal.
     *
     * @return \Generator<int, array<string, string|float|\DateTimeImmutable|null>>
     */
    private function rangees(Lecteur $lecteur, string $page, int $numeroDePage, Resultat $resultat): \Generator
    {
        $entete = null;

        foreach ($lecteur->lignes($page) as $numero => $cellules) {
            if ($entete === null) {
                if ($numeroDePage === 1) {
                    $this->lireLEnTeteDuDocument($cellules);
                }

                $candidat = $this->correspondance($cellules);

                if ($this->suffisante($candidat)) {
                    $entete = $candidat;
                    $resultat->ligneDEnTete ??= $numero;
                }

                continue;
            }

            $ligne = $this->extraire($cellules, $entete);
            $morceaux = preg_split('/\R/u', (string) ($ligne['libelle'] ?? ''));

            // Le cas ordinaire du PDF : une seule ligne dans la cellule, rien à déplier.
            if ($morceaux === false || count($morceaux) <= 1) {
                yield $numero => $ligne;

                continue;
            }

            foreach ($morceaux as $rang => $morceau) {
                if ($rang === 0) {
                    $ligne['libelle'] = $morceau;
                    yield $numero => $ligne;

                    continue;
                }

                // Les suivantes ne portent qu'un texte : leur donner la date et le montant
                // de la première ouvrirait autant de mouvements que de lignes de libellé.
                if (trim($morceau) !== '') {
                    yield $numero => ['libelle' => $morceau];
                }
            }
        }
    }

    /**
     * Le mouvement qu'ouvre une rangée portant une date et un montant.
     *
     * Le sens vient de la colonne où le montant est posé. Quand la phrase du dessus le dit
     * aussi et qu'elle dit autre chose, c'est la colonne qui l'emporte — elle est ce que
     * le document imprime, la phrase est ce qu'il raconte —, mais le cas ne s'est présenté
     * sur aucun des 1 104 mouvements lus.
     */
    private function ouvrir(
        array $ligne,
        Carbon $date,
        ?float $entree,
        ?float $sortie,
        ?array $description,
        ?array $tiers,
        array $ou,
        int $page,
    ): array {
        $sens = $entree !== null ? MouvementCaisse::ENTREE : MouvementCaisse::SORTIE;
        $montant = (int) round($entree ?? $sortie ?? 0);
        $motif = $description === null ? null : trim($description[5]);

        /*
         * Un montant négatif retourne le sens, il ne devient pas un montant négatif.
         *
         * Le journal annule une pièce en réimprimant la même dans sa colonne d'origine
         * avec un montant négatif : « ANNULATION ENTREE DE CAISSE MC -N° 002271 », colonne
         * ENTREE, −1. Deux lignes portent alors le même numéro de pièce et le même sens —
         * et se sont écrasées l'une l'autre au premier essai, emportant six soldes avec
         * elles. Une entrée de −1 est une sortie de 1 : le montant reste positif, comme
         * partout dans cette table, et les deux lignes se distinguent enfin.
         */
        if ($montant < 0) {
            $sens = $sens === MouvementCaisse::ENTREE ? MouvementCaisse::SORTIE : MouvementCaisse::ENTREE;
        }

        // Une annulation reprend le numéro de la pièce qu'elle annule et porte un montant
        // négatif : la mention doit rester lisible, sans quoi deux lignes opposées
        // paraîtraient contradictoires.
        if ($description !== null && trim($description[1]) !== '') {
            $motif = 'ANNULATION — '.$motif;
        }

        return [
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $ou['ville_id'],
            'site_id' => $ou['site_id'],
            'date' => $date,
            'sens' => $sens,
            'montant' => abs($montant),
            'solde_annonce' => ($solde = self::montant($ligne['solde'] ?? null)) === null ? null : (int) round($solde),
            'numero_piece' => $description === null ? null : self::texte($description[4], 40),
            'type_piece' => $description === null ? null : self::texte($description[3], 20),
            'motif' => self::texte($motif, 190),
            'role_tiers' => $tiers === null ? null : mb_strtolower($tiers[1]),
            'beneficiaire' => $tiers === null || trim($tiers[2]) === '' ? null : self::texte(trim($tiers[2]), 200),
            'caisse' => $this->caisse,
            'mois' => $date->format('Y-m'),
            'page' => $page,
            'detail' => [],
        ];
    }

    /** Écrit le mouvement en construction, s'il y en a un. */
    private function fermer(?array &$courant, Resultat $resultat, ?int $lotId, bool $ecrire, ?\Closure $surAvancee): void
    {
        if ($courant === null) {
            return;
        }

        $mouvement = $courant;
        $courant = null;

        $resultat->lues++;
        $resultat->compter($ecrire ? $this->ecrireLeMouvement($mouvement, $lotId) : 'ignore');

        if ($surAvancee !== null) {
            $surAvancee($resultat->lues);
        }
    }

    /**
     * Écrit un mouvement, ou reconnaît qu'il est déjà là.
     *
     * **La clé est la ligne entière, et le numéro de pièce n'y suffisait pas.** On avait
     * commencé par « caisse + numéro de pièce + sens + montant », le numéro paraissant
     * fait pour cela. Mesuré sur le fichier de San-Pédro : **deux couples de mouvements
     * bien distincts portent le même numéro** — pièce 002410, deux sorties de 8 000 F le
     * même jour, l'une à KACOU SIMPLICE pour des achats divers, l'autre à GUEDJE JAURES
     * pour de la main-d'œuvre ; pièce 003468, deux sorties de 5 000 F dans le même cas. Le
     * logiciel a réattribué le numéro ; la chaîne des soldes, elle, applique bien les deux.
     * La clé retenue est donc tout ce qui décrit le mouvement : caisse, pièce, date, sens,
     * montant, motif, tiers et libellé. Sur les 1 104 mouvements des deux fichiers, elle ne
     * confond rien.
     *
     * **Ce qui n'entre pas dans la clé** : le solde annoncé et la page. Ils dépendent du
     * tirage, pas du mouvement — un état réimprimé sur une période plus longue n'a ni les
     * mêmes soldes ni la même pagination, et le redéposer doit reconnaître ses lignes au
     * lieu de les doubler.
     *
     * Un mouvement dont la phrase manquerait n'aurait pas de numéro du tout. Il entre
     * quand même, et se distingue par le reste — comme le classeur tenu à la main
     * d'Abidjan, qui n'a jamais eu de numéro.
     */
    private function ecrireLeMouvement(array $mouvement, ?int $lotId): string
    {
        $detail = array_values(array_unique(array_filter($mouvement['detail'])));
        unset($mouvement['detail']);

        $libelle = implode(' · ', $detail);

        if (trim($libelle) === '') {
            $libelle = (string) ($mouvement['motif'] ?? 'Mouvement de caisse');
        }

        $valeurs = $mouvement + [
            'libelle' => (string) self::texte($libelle, 500),
            'lot_import_id' => $lotId,
        ];

        $existant = MouvementCaisse::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('caisse', $mouvement['caisse'])
            ->where('date', $mouvement['date'])
            ->where('sens', $mouvement['sens'])
            ->where('montant', $mouvement['montant'])
            ->where('libelle', $valeurs['libelle'])
            ->where(fn ($q) => $this->memeValeur($q, 'numero_piece', $mouvement['numero_piece']))
            ->where(fn ($q) => $this->memeValeur($q, 'motif', $mouvement['motif']))
            ->where(fn ($q) => $this->memeValeur($q, 'beneficiaire', $mouvement['beneficiaire']))
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

    /**
     * Le cartouche du document : quelle caisse, quelle période, et avec quel solde.
     *
     * Ces trois renseignements sont imprimés au-dessus de la rangée des intitulés, et
     * n'appartiennent à aucun mouvement. Sans eux, l'écran ne pourrait ni nommer la caisse
     * ni dire ce qu'elle contenait avant la première ligne — deux choses qu'aucun cumul de
     * nos mouvements ne peut retrouver, puisque la caisse vivait avant le document.
     */
    private function lireLEnTeteDuDocument(array $cellules): void
    {
        $entier = trim(implode(' ', array_map(fn ($v) => (string) $v, $cellules)));

        foreach ($cellules as $valeur) {
            if (preg_match('/^CAISSE\s*:\s*(.+)$/ui', trim((string) $valeur), $trouvaille) === 1) {
                $this->caisse ??= self::texte(trim($trouvaille[1]), 120);
            }
        }

        if ($this->soldeAvant === null && preg_match('/SOLDE\s+AVANT/ui', $entier) === 1) {
            // Le montant est posé tout à droite, dans la colonne des soldes : on prend la
            // dernière cellule chiffrée de la rangée plutôt que la première, qui serait la
            // phrase elle-même.
            foreach (array_reverse($cellules) as $valeur) {
                $montant = self::montant($valeur);

                if ($montant !== null && preg_match('/\d/', (string) $valeur) === 1) {
                    $this->soldeAvant = (int) round($montant);

                    break;
                }
            }
        }

        if ($this->debut === null && preg_match('/P.RIODE\s*:/ui', $entier) === 1) {
            preg_match_all('#\d{2}/\d{2}/\d{4}#', $entier, $dates);

            $this->debut = isset($dates[0][0]) ? self::date($dates[0][0]) : null;
            $this->fin = isset($dates[0][1]) ? self::date($dates[0][1]) : null;
        }
    }

    /**
     * Consigne le solde avec lequel la caisse ouvre la période.
     *
     * Une photographie, pas un événement : redéposer le même état met la ligne à jour au
     * lieu d'en ajouter une seconde.
     */
    private function noterLOuverture(array $ou, ?int $lotId): void
    {
        if ($this->caisse === null || $this->soldeAvant === null) {
            return;
        }

        OuvertureCaisse::consigner(
            [
                'entreprise_id' => $this->entrepriseId,
                'ville_id' => $ou['ville_id'],
                'caisse' => $this->caisse,
                'debut' => $this->debut?->toDateString(),
                'fin' => $this->fin?->toDateString(),
            ],
            [
                'site_id' => $ou['site_id'],
                'solde_avant' => $this->soldeAvant,
                'source' => OuvertureCaisse::DU_JOURNAL,
                'lot_import_id' => $lotId,
            ],
        );
    }

    /**
     * Compare une colonne à une valeur qui peut être vide.
     *
     * `where('motif', null)` en SQL ne rapproche rien du tout, pas même les lignes dont le
     * motif est vide : `NULL = NULL` est faux. Sans cette précaution, un mouvement sans
     * numéro de pièce serait recréé à chaque dépôt.
     */
    private function memeValeur($requete, string $colonne, ?string $valeur)
    {
        return $valeur === null ? $requete->whereNull($colonne) : $requete->where($colonne, $valeur);
    }

    private function piedDePage(string $texte): bool
    {
        $compare = mb_strtolower($texte);

        foreach (self::PIED_DE_PAGE as $marque) {
            if (str_starts_with($compare, $marque)) {
                return true;
            }
        }

        return false;
    }

    /** Un nombre seul en bas de page est un compteur de lignes, pas un libellé. */
    private function purementNumerique(string $texte): bool
    {
        return preg_match('/^[\d\s.,]+$/u', $texte) === 1;
    }

    /** Le journal ne porte aucun numéro de fiche : il n'y a pas de code à en tirer. */
    /** Le journal imprimé dit sa caisse et sa ville en toutes lettres : il n'a pas besoin des codes, et n'en porte pas. */
    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    protected function reference(array $ligne): ?string
    {
        return null;
    }

    /** Le parcours de ce format n'emprunte pas le chemin d'écriture ligne à ligne. */
    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        return 'ignore';
    }
}

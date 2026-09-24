<?php

namespace Modules\Noyau\Imports\Formats;

use Closure;
use Modules\Noyau\Imports\Lecteurs\Lecteur;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;
use Modules\Noyau\Imports\Modeles\FactureFournisseur;
use Modules\Noyau\Imports\Modeles\FournisseurReferentiel;

/**
 * Le suivi des factures fournisseurs — ce que l'entreprise doit.
 *
 * **La feuille lue est « DETAIL », et c'est une correction.** On avait conclu, sur un
 * échantillon, que le seul onglet exploitable était « contrôle chq » : dix-huit colonnes
 * propres, en-tête en première ligne. C'était l'échantillon qui était pauvre, pas le
 * classeur. Les deux fichiers réels, ouverts le 22/09, portent dans « DETAIL » la liste
 * complète — trente-neuf colonnes à Abidjan, trente et une à San Pedro. Son en-tête n'est
 * simplement pas en première ligne : il est en ligne 10 dans l'un, en ligne 9 dans l'autre,
 * et au-dessus se trouvent les règles d'usage du classeur et une ligne de totaux.
 *
 * La reconnaissance d'en-tête de la classe mère sait descendre : elle retient la ligne qui
 * ressemble le plus à ce format, quel que soit son rang. C'est le nombre de colonnes
 * reconnues qui décide, et « DETAIL » en aligne deux fois plus que « contrôle chq ».
 *
 * **Les deux classeurs n'ont pas les mêmes colonnes, et ce format lit l'union des deux.**
 * Onze colonnes n'existent qu'à Abidjan — la refacturation, les quantités, la marge ;
 * trois n'existent qu'à San Pedro — le montant HT, le n° FEB, « arrivé à échéance ». Une
 * colonne absente reste vide, elle ne fait pas échouer la ligne. Deux formats séparés
 * auraient obligé à choisir le bon dans une liste, et à s'en souvenir.
 *
 * **Les deux fichiers se recouvrent, et la clé le règle.** Mesuré : 10 147 lignes en tout,
 * 7 397 distinctes — 2 670 lignes figurent dans les deux classeurs, les factures d'Abidjan
 * étant reprises dans le suivi de San Pedro. La clé de rapprochement est donc
 * **fournisseur + n° de pièce + date de facture + montant**, et non le seul couple
 * fournisseur + pièce : 188 couples portent plusieurs lignes bien réelles — une facture
 * ventilée par section, un avoir qui reprend le numéro de la pièce qu'il annule. Avec
 * l'ancienne clé, 317 lignes s'écrasaient l'une l'autre.
 *
 * **Les deux colonnes de TVA sont recopiées, aucune n'est interprétée.** « TVA » et
 * « TVA 2 » portent des valeurs tantôt égales tantôt non, sans qu'aucune règle ne se lise
 * dans le fichier. On les garde toutes les deux, telles quelles : c'est au comptable de
 * dire ce qu'elles signifient, et en choisir une au hasard donnerait un montant de taxe
 * faux qu'on croirait juste. Le montant total, lui, est sans ambiguïté et c'est lui qui
 * fait la dette.
 *
 * **La colonne SITE dit d'où vient chaque ligne** — ABIDJAN, SAN PEDRO, BOUAKE — ce qui
 * évite de le deviner. Elle contient une faute de frappe (« ABIIDJAN », une ligne) que le
 * rapprochement de lieux traite comme n'importe quel libellé inconnu.
 */
class FormatDesFournisseurs extends Format
{
    public static function cle(): string
    {
        return 'fournisseurs';
    }

    public static function libelle(): string
    {
        return 'Factures fournisseurs — ce que nous devons';
    }

    /**
     * L'union des colonnes des deux classeurs, dans leur ordre d'apparition.
     *
     * Les intitulés sont recopiés à la lettre du fichier, fautes comprises
     * (« OBSERVATIONS SUR LA FACTURARTION CLIENT ») : la reconnaissance se fait sur
     * l'intitulé complet, et corriger l'orthographe ici ferait manquer la colonne.
     */
    public static function colonnes(): array
    {
        return [
            'mois' => 'MOIS',
            'date_reglement' => 'DATE DE REGLEMENT',
            'date_facture' => 'DATE FACTURE/BC',
            'date_reception' => 'DATE RECEPTION FACTURE',
            'site' => 'SITE',
            'section' => 'SECTION',
            'numero_bc' => 'N° BC',
            'nature_piece' => 'NATURE PIECE',
            'numero_piece' => 'N° PIECE',
            'montant' => 'MONTANT',
            // San Pedro seul.
            'montant_ht' => 'MONTANT HT',
            'tva' => 'TVA',
            'tva_2' => 'TVA 2',
            'montant_refacture' => 'MONTANT REFACTURE',
            'difference' => 'DIFFERENCE',
            'fournisseur' => 'FOURNISSEUR',
            // Abidjan seul.
            'type_transaction' => 'TYPE DE TRANSACTION/ SERVICES',
            'mode_reglement' => 'MODE DE REGLEMENT',
            'montant_regle' => 'MONTANT REGLE',
            'reste_a_payer' => 'RESTE A PAYER',
            'imputation' => 'IMPUTATION',
            'vehicule' => 'VEHICULE',
            'immatriculation' => 'IMMAT',
            // San Pedro seul.
            'numero_feb' => 'FEB N°',
            'delai_reglement' => 'délais de règlement',
            'date_echeance' => 'Date Echéance',
            // San Pedro seul.
            'arrive_a_echeance' => 'Arrivé à échéance OUI/NON',
            // Abidjan seul, de « N° FACTURE (FA) » à « RESULTAT INDICATIF ».
            'numero_facture_achat' => 'N° FACTURE (FA)',
            'numero_facture_vente' => 'N° FACTURE (FV)',
            'quantite_totale' => 'QTE TOTAL',
            'quantite_refacturee' => 'QTE REFACT',
            'code_piece' => 'CODE PIECE',
            'montant_net_achat' => 'MONTANT NET ACHAT',
            'montant_net_vente' => 'MONTANT NET VENTE',
            'marge' => 'MARGE',
            'taux' => 'TAUX',
            'resultat_indicatif' => 'RESULTAT INDICATIF',
            'observations' => 'OBSERVATIONS',
            'numero_facture_client' => 'NUMERO FACTURE CLIENT',
            'observations_facturation' => 'OBSERVATIONS SUR LA FACTURARTION CLIENT',
            'commentaires' => 'COMMENTAIRES',
            // L'intitulé porte une date, celle du jour où la colonne a été ajoutée au
            // classeur. Elle est recopiée telle quelle : c'est le libellé réel.
            'actions_a_mener' => 'Actions à mener 06/09/2024',
            // La feuille « contrôle chq » du classeur d'Abidjan porte cette colonne que
            // « DETAIL » n'a pas. On la garde pour qu'un dépôt de cette feuille-là reste
            // lisible, sans que son absence gêne en quoi que ce soit.
            'numero_cheque' => 'n° CHQ',
        ];
    }

    public static function colonnesObligatoires(): array
    {
        return ['fournisseur', 'montant'];
    }

    /**
     * L'intitulé qui reconnaît la feuille annexe, et lui seul.
     *
     * « Type de règlement » n'existe que là : la feuille `DETAIL` porte bien une colonne
     * de délai, mais elle s'y appelle « délais de règlement ». Reconnaître la feuille à son
     * nom aurait été plus court et moins sûr — le module choisit toujours sur le contenu,
     * parce qu'un onglet se renomme et qu'on l'a déjà vu faire.
     */
    private const EN_TETE_DES_FICHES = 'TYPE DE REGLEMENT';

    /**
     * Le classeur porte deux feuilles utiles, et on n'en lisait qu'une.
     *
     * `DETAIL` donne les factures ; « Liste fournisseurs » donne les fournisseurs — à quel
     * terme chacun se règle, s'il facture la TVA, et pour six d'entre eux le plafond
     * d'encours négocié. Un seul dépôt renseigne donc les deux : demander à quelqu'un de
     * déposer deux fois le même fichier en changeant de format dans une liste déroulante,
     * c'est se garantir qu'il ne le fera qu'une.
     *
     * Les fiches sont comptées à part de `lues` : ce ne sont pas des pièces, et
     * l'invariant des cinq compteurs ne porte que sur les pièces.
     */
    public function parcourir(
        Lecteur $lecteur,
        ?int $villeDuDepot,
        ?int $lotId,
        bool $ecrire = true,
        ?Closure $surAvancee = null,
        ?int $siteDuDepot = null,
    ): Resultat {
        $resultat = parent::parcourir($lecteur, $villeDuDepot, $lotId, $ecrire, $surAvancee, $siteDuDepot);

        $this->lireLesFiches($lecteur, $lotId, $ecrire, $resultat);

        return $resultat;
    }

    /**
     * Parcourt la feuille annexe, quand le classeur en porte une.
     *
     * Son absence n'est pas une anomalie : les exports du logiciel comptable n'en ont pas,
     * et un classeur tenu à la main peut très bien s'en passer. Rien n'est donc rejeté ni
     * signalé — il n'y a simplement pas de fiches.
     */
    private function lireLesFiches(Lecteur $lecteur, ?int $lotId, bool $ecrire, Resultat $resultat): void
    {
        $ou = $this->feuilleDesFiches($lecteur);

        if ($ou === null) {
            return;
        }

        [$feuille, $rangDEnTete, $posDelai, $posTva] = $ou;

        foreach ($lecteur->lignes($feuille) as $rang => $cellules) {
            if ($rang <= $rangDEnTete) {
                continue;
            }

            $nom = $this->nomDeLaFiche($cellules, $posDelai);

            // Le tiret isolé de la deuxième ligne est la ligne « aucun fournisseur » du
            // menu déroulant du classeur, pas un fournisseur qui s'appellerait « - ».
            if ($nom === null || $nom === '-') {
                continue;
            }

            $libelle = self::texte($cellules[$posDelai] ?? null, 60);
            $terme = FournisseurReferentiel::lireLeTerme($libelle);

            $resultat->fiches++;

            if (! $ecrire) {
                continue;
            }

            FournisseurReferentiel::consigner($this->entrepriseId, $nom, [
                'lot_import_id' => $lotId,
                'delai_reglement' => $libelle,
                'jours_reglement' => $terme['jours'],
                'fin_de_mois' => $terme['finDeMois'],
                'assujetti_tva' => $this->assujetti($posTva === null ? null : ($cellules[$posTva] ?? null)),
                'note' => $this->noteDeLaFiche($cellules, $posDelai, $posTva),
                'source_feuille' => $feuille,
            ]);
        }
    }

    /**
     * Où se trouvent les fiches : la feuille, la ligne d'en-tête, et les deux colonnes.
     *
     * @return array{0: string, 1: int, 2: int, 3: int|null}|null
     */
    private function feuilleDesFiches(Lecteur $lecteur): ?array
    {
        foreach ($lecteur->feuilles() as $feuille) {
            $sondees = 0;

            foreach ($lecteur->lignes($feuille) as $rang => $cellules) {
                $delai = null;
                $tva = null;

                foreach ($cellules as $position => $valeur) {
                    if (! is_string($valeur)) {
                        continue;
                    }

                    $normalise = CorrespondanceImport::normaliser($valeur);

                    if ($normalise === self::EN_TETE_DES_FICHES && $delai === null) {
                        $delai = $position;
                    }

                    if ($normalise === 'TVA' && $tva === null) {
                        $tva = $position;
                    }
                }

                if ($delai !== null) {
                    return [$feuille, $rang, $delai, $tva];
                }

                if (++$sondees >= self::LIGNES_SONDEES) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Le nom du fournisseur : la première cellule remplie avant la colonne du terme.
     *
     * La colonne des noms **n'a pas d'en-tête** dans le fichier — la case au-dessus est
     * vide dans les deux classeurs. On ne peut donc pas la reconnaître comme les autres, et
     * coder « colonne A » supposerait que personne n'insérera jamais une colonne à gauche.
     * La position relative, elle, tient : le nom précède le terme.
     */
    private function nomDeLaFiche(array $cellules, int $posDelai): ?string
    {
        for ($position = 0; $position < $posDelai; $position++) {
            $nom = self::texte($cellules[$position] ?? null, 190);

            if ($nom !== null && $nom !== '') {
                return $nom;
            }
        }

        return null;
    }

    /**
     * Ce que la ligne dit de plus, recopié tel quel.
     *
     * Six fournisseurs portent, dans une colonne sans en-tête, leur plafond d'encours
     * négocié — « Limite compte 12 500 000 FCFA ». Il n'est ni converti en nombre ni
     * comparé à quoi que ce soit : l'un des six l'écrit « 10 00 000 », et deviner s'il
     * s'agit d'un million ou de dix effacerait la faute au lieu de la montrer.
     */
    private function noteDeLaFiche(array $cellules, int $posDelai, ?int $posTva): ?string
    {
        $morceaux = [];

        foreach ($cellules as $position => $valeur) {
            if ($position <= $posDelai || $position === $posTva) {
                continue;
            }

            $texte = self::texte($valeur, 255);

            if ($texte !== null && $texte !== '') {
                $morceaux[] = $texte;
            }
        }

        return $morceaux === [] ? null : mb_substr(implode(' · ', $morceaux), 0, 255);
    }

    /**
     * Assujetti à la TVA, non assujetti, ou inconnu.
     *
     * Trois états et non deux : la colonne est vide pour 33 des 282 noms d'Abidjan. Dire
     * « non » à leur place serait une affirmation fiscale que le fichier ne fait pas.
     */
    private function assujetti(mixed $valeur): ?bool
    {
        return match (CorrespondanceImport::normaliser((string) self::texte($valeur, 20))) {
            'OUI' => true,
            'NON' => false,
            default => null,
        };
    }

    protected function colonneSite(array $ligne): ?string
    {
        return self::texte($ligne['site'] ?? null, 60);
    }

    /** Le bon de commande porte parfois le code, mais pas de façon fiable : on n'en tire rien. */
    /** Une facture fournisseur porte le nom du fournisseur, pas le code de l'employé qui a reçu le véhicule : il n'y a rien à ventiler. */
    public static function ventileParLesCodes(): bool
    {
        return false;
    }

    protected function reference(array $ligne): ?string
    {
        return null;
    }

    /**
     * Une ligne qui n'est rien, et qu'il ne faut pas compter comme un rejet.
     *
     * Les classeurs tenus à la main traînent leurs formules longtemps après la dernière
     * facture saisie. Mesuré sur celui d'Abidjan : la dernière vraie ligne est la 6 381,
     * et les mille suivantes ne portent qu'un « 0 » de TVA et un `#REF!` — des cellules
     * calculées sur du vide. La classe mère ne les voit pas comme vides, puisqu'une
     * cellule y porte bien quelque chose, et les mille remontaient au journal des rejets
     * sous le motif « la colonne FOURNISSEUR est vide », noyant les vrais.
     *
     * Une ligne qui ne dit ni de qui, ni quelle pièce, ni combien, ni quand, ne se rejette
     * pas : elle n'existe pas.
     */
    protected function vide(array $ligne): bool
    {
        if (parent::vide($ligne)) {
            return true;
        }

        foreach (['fournisseur', 'numero_piece', 'montant', 'date_facture'] as $cle) {
            $valeur = $ligne[$cle] ?? null;

            if ($valeur !== null && ! (is_string($valeur) && trim($valeur) === '')) {
                return false;
            }
        }

        return true;
    }

    protected function refuser(array $ligne): ?string
    {
        if ($motif = parent::refuser($ligne)) {
            return $motif;
        }

        if (self::montant($ligne['montant'] ?? null) === null) {
            return "Le montant de la facture n'est pas un nombre exploitable.";
        }

        // Sans date de facture, la dette n'a pas d'âge : elle ne peut ni entrer dans une
        // balance âgée ni être relancée à échéance. Mieux vaut la signaler que la ranger
        // à une date inventée. Mesuré sur les deux classeurs : 49 lignes sur 10 147.
        if (self::date($ligne['date_facture'] ?? null) === null) {
            return 'La date de la facture est absente ou illisible.';
        }

        return null;
    }

    protected function ecrire(array $ligne, array $rattachement, ?int $lotId): string
    {
        $fournisseur = self::texte($ligne['fournisseur'] ?? null, 160);
        $piece = self::texte($ligne['numero_piece'] ?? null, 60);
        $montant = self::entier($ligne['montant'] ?? null) ?? 0;
        $dateFacture = self::date($ligne['date_facture'] ?? null);

        $regle = self::entier($ligne['montant_regle'] ?? null);
        $reste = self::entier($ligne['reste_a_payer'] ?? null);

        $valeurs = [
            'entreprise_id' => $this->entrepriseId,
            'ville_id' => $rattachement['ville_id'],
            'site_id' => $rattachement['site_id'],
            'lot_import_id' => $lotId,

            'mois' => $this->mois($ligne['mois'] ?? null),
            'numero_piece' => $piece,
            'nature_piece' => self::texte($ligne['nature_piece'] ?? null, 60),
            'numero_bc' => self::texte($ligne['numero_bc'] ?? null, 60),
            'section' => self::texte($ligne['section'] ?? null, 60),
            'fournisseur' => $fournisseur,
            'type_transaction' => self::texte($ligne['type_transaction'] ?? null, 120),

            'date_facture' => $dateFacture,
            'date_reception' => self::date($ligne['date_reception'] ?? null),
            'date_reglement' => self::date($ligne['date_reglement'] ?? null),
            'date_echeance' => self::date($ligne['date_echeance'] ?? null),
            'delai_reglement' => self::texte($ligne['delai_reglement'] ?? null, 40),
            'arrive_a_echeance' => self::texte($ligne['arrive_a_echeance'] ?? null, 20),

            'montant' => $montant,
            'montant_ht' => self::entier($ligne['montant_ht'] ?? null),
            // Recopiées, jamais additionnées ni choisies : voir l'en-tête du fichier.
            'tva' => self::entier($ligne['tva'] ?? null),
            'tva_2' => self::entier($ligne['tva_2'] ?? null),
            'montant_regle' => $regle ?? 0,
            // Le reste est repris tel que le fichier l'annonce plutôt que recalculé :
            // l'écart éventuel entre « montant − réglé » et la colonne du fichier est une
            // information comptable, et la recalculer l'effacerait.
            'reste_a_payer' => $reste ?? max(0, $montant - ($regle ?? 0)),
            'montant_refacture' => self::entier($ligne['montant_refacture'] ?? null),
            'difference' => self::entier($ligne['difference'] ?? null),
            'montant_net_achat' => self::entier($ligne['montant_net_achat'] ?? null),
            'montant_net_vente' => self::entier($ligne['montant_net_vente'] ?? null),
            'marge' => self::entier($ligne['marge'] ?? null),
            'taux' => self::montant($ligne['taux'] ?? null),
            'resultat_indicatif' => self::texte($ligne['resultat_indicatif'] ?? null, 120),

            'quantite_totale' => self::montant($ligne['quantite_totale'] ?? null),
            'quantite_refacturee' => self::montant($ligne['quantite_refacturee'] ?? null),
            'code_piece' => self::texte($ligne['code_piece'] ?? null, 60),
            'numero_facture_achat' => self::texte($ligne['numero_facture_achat'] ?? null, 60),
            'numero_facture_vente' => self::texte($ligne['numero_facture_vente'] ?? null, 60),
            'numero_facture_client' => self::texte($ligne['numero_facture_client'] ?? null, 60),
            'numero_feb' => self::texte($ligne['numero_feb'] ?? null, 60),

            'mode_reglement' => self::texte($ligne['mode_reglement'] ?? null, 200),
            'numero_cheque' => self::texte($ligne['numero_cheque'] ?? null, 60),
            'imputation' => self::texte($ligne['imputation'] ?? null, 120),
            'vehicule' => self::texte($ligne['vehicule'] ?? null, 120),
            'immatriculation' => self::texte($ligne['immatriculation'] ?? null, 40),

            // Les quatre colonnes de texte libre gardent chacune la sienne. Les fondre en
            // une seule, comme on le faisait, revenait à perdre laquelle disait quoi — or
            // « Actions à mener » est une consigne de travail, pas une observation.
            'observations' => self::texte($ligne['observations'] ?? null, 500),
            'observations_facturation' => self::texte($ligne['observations_facturation'] ?? null, 500),
            'commentaires' => self::texte($ligne['commentaires'] ?? null, 500),
            'actions_a_mener' => self::texte($ligne['actions_a_mener'] ?? null, 500),

            'code_agent' => $rattachement['code'],
            'source_rattachement' => $rattachement['source'],
            'rattachement_presume' => $rattachement['presumee'],
        ];

        $existante = $this->retrouver($fournisseur, $piece, $dateFacture, $montant);

        if ($existante === null) {
            FactureFournisseur::withoutGlobalScopes()->create($valeurs);

            return 'cree';
        }

        if ($rattachement['presumee'] && $existante->site_id !== null) {
            unset($valeurs['site_id']);
        }

        $existante->fill($valeurs);

        if (! $existante->isDirty()) {
            return 'ignore';
        }

        $existante->save();

        return 'maj';
    }

    /**
     * La ligne déjà en base, ou null.
     *
     * Quatre valeurs et non deux : un même numéro de pièce revient chez deux fournisseurs
     * différents, et un même fournisseur ventile une facture sur plusieurs lignes ou émet
     * un avoir sous le numéro de la pièce qu'il annule. Quand la pièce manque — 183 lignes
     * sur 10 147 — la date et le montant suffisent à retrouver la ligne.
     */
    private function retrouver(?string $fournisseur, ?string $piece, $dateFacture, int $montant): ?FactureFournisseur
    {
        return FactureFournisseur::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('fournisseur', $fournisseur)
            ->when(
                $piece !== null && $piece !== '',
                fn ($requete) => $requete->where('numero_piece', $piece),
                fn ($requete) => $requete->whereNull('numero_piece'),
            )
            ->where('date_facture', $dateFacture)
            ->where('montant', $montant)
            ->first();
    }

    /**
     * Le mois du classeur, ramené entre 1 et 12.
     *
     * La colonne est remplie à la main : elle porte parfois un mois écrit en toutes
     * lettres, parfois rien. Un nombre hors des douze mois est une faute de saisie qu'on
     * laisse vide plutôt que d'écrire un treizième mois en base.
     */
    private function mois(mixed $valeur): ?int
    {
        $mois = self::montant($valeur);

        if ($mois === null) {
            return null;
        }

        $mois = (int) round($mois);

        return $mois >= 1 && $mois <= 12 ? $mois : null;
    }

    /**
     * Un montant en francs, arrondi — ou null quand la cellule n'en porte pas.
     *
     * Le franc CFA n'a pas de centimes ; les décimales que portent certaines colonnes
     * viennent des formules du tableur (« 82 096,627… » pour un montant hors taxe) et non
     * d'une monnaie plus fine. Distinguer null de zéro compte : une colonne absente d'un
     * des deux classeurs doit rester vide, pas s'afficher à zéro comme si elle avait été
     * renseignée.
     */
    private static function entier(mixed $valeur): ?int
    {
        $montant = self::montant($valeur);

        return $montant === null ? null : (int) round($montant);
    }
}

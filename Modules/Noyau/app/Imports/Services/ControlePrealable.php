<?php

namespace Modules\Noyau\Imports\Services;

use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Formats\Format;
use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Lecteurs\Classeur;
use Modules\Noyau\Imports\Modeles\CodeAgent;

/**
 * Ce que le fichier dit de lui-même, avant qu'on ne l'importe.
 *
 * **Le défaut auquel cette classe répond.** Jusqu'ici, le type de fichier et la ville
 * étaient déclarés dans deux listes déroulantes, et crus sur parole. Se tromper de type ne
 * produisait pas un refus mais un import à zéro ligne — impossible à distinguer d'un
 * fichier vide. Se tromper de ville produisait pire : un import qui réussit, et huit cents
 * fiches de San Pédro rangées à Abidjan. Le premier fait perdre une heure ; le second
 * fausse le chiffre d'affaires d'un atelier sans que personne ne s'en aperçoive.
 *
 * **Deux questions, deux réponses, et aucune décision prise à la place de quelqu'un.**
 *
 * 1. *Ce fichier ressemble-t-il au type annoncé ?* On compare ses en-têtes à ceux de tous
 *    les formats connus. Celui qui reconnaît le plus de colonnes gagne. Si ce n'est pas
 *    celui qu'on a déclaré, on le dit, en nommant les colonnes qui manquent.
 *
 * 2. *Vient-il de la ville annoncée ?* Les codes employés sont dans la donnée, personne ne
 *    les retape : ils sont le témoin le plus fiable qu'on ait. Si les codes du fichier sont
 *    connus et rattachés ailleurs, on le dit — avec les chiffres, pas avec une impression.
 *
 * Le contrôle **avertit, il ne bloque pas**. Un employé muté travaille un mois dans une
 * ville avec le code d'une autre ; un format peut recevoir une colonne de plus. Refuser sur
 * une ressemblance apprendrait surtout aux gens à contourner le refus. Le seul cas où l'on
 * parle de blocage est celui où aucun format ne reconnaît le fichier : là, il n'y a rien à
 * importer, et le dire tout de suite fait gagner l'heure.
 */
class ControlePrealable
{
    /**
     * Sous ce seuil de désaccord, on se tait.
     *
     * Un ou deux codes étrangers dans un fichier de mille lignes, c'est un employé qui a
     * dépanné une autre ville — pas une erreur de dépôt. C'est le même raisonnement, et le
     * même ordre de grandeur, que l'apprentissage des ateliers.
     */
    public const PART_MINIMALE_D_ALERTE = 0.20;

    /** En deçà, la ville n'est pas mesurée mais devinée : on n'en parle pas. */
    public const LIGNES_MINIMALES_POUR_CONCLURE = 20;

    /** Et il faut qu'un quart au moins des lignes portent un code dont on connaît la ville. */
    public const COUVERTURE_MINIMALE = 0.25;

    /**
     * Part des lignes d'une autre année au-delà de laquelle on avertit.
     *
     * Un fichier de 2026 contient légitimement quelques lignes de décembre 2025 — une
     * reprise, une facture tardive. Ce n'est pas une erreur de dépôt. Un fichier dont
     * **la moitié** des lignes sont d'une autre année, si : c'est l'exercice qu'on s'est
     * trompé de sélectionner, et les lignes iront se ranger sous une année qui n'est pas
     * la leur sans que rien ne le dise.
     */
    public const PART_D_UNE_AUTRE_ANNEE = 0.50;

    public function __construct(private int $entrepriseId) {}

    /**
     * @return array{
     *     lisible: bool,
     *     format_declare: string,
     *     format_probable: ?string,
     *     format_conforme: bool,
     *     colonnes_reconnues: int,
     *     colonnes_manquantes: list<string>,
     *     ville_declaree: ?string,
     *     ville_probable: ?string,
     *     ville_conforme: bool,
     *     codes_etrangers: array<string, string>,
     *     villes_etrangeres: list<string>,
     *     exercice_declare: ?int,
     *     annee_du_fichier: ?int,
     *     couverture_insuffisante: bool,
     *     avertissements: list<string>,
     * }
     */
    public function examiner(string $chemin, string $formatDeclare, ?int $villeId, ?int $exercice = null): array
    {
        $rapport = [
            'lisible' => false,
            'format_declare' => $formatDeclare,
            'format_probable' => null,
            'format_conforme' => false,
            'colonnes_reconnues' => 0,
            'colonnes_manquantes' => [],
            'ville_declaree' => $this->nomDeVille($villeId),
            'ville_probable' => null,
            'ville_conforme' => true,
            'codes_etrangers' => [],
            'villes_etrangeres' => [],
            'exercice_declare' => $exercice,
            'annee_du_fichier' => null,
            'couverture_insuffisante' => false,
            'avertissements' => [],
        ];

        if (Classeur::format($chemin) === null) {
            $rapport['avertissements'][] = "Ce fichier n'est pas un classeur Excel lisible.";

            return $rapport;
        }

        $affinites = $this->affinites($chemin);
        $rapport['lisible'] = true;

        if ($affinites === []) {
            $rapport['avertissements'][] = 'Aucun format connu ne reconnaît les colonnes de ce fichier. '
                ."Vérifiez qu'il s'agit bien d'un export du logiciel d'atelier, et non d'un fichier retravaillé à la main.";

            return $rapport;
        }

        $probable = array_key_first($affinites);
        $declare = $affinites[$formatDeclare] ?? ['score' => 0, 'suffisante' => false, 'manquantes' => []];

        $rapport['format_probable'] = $probable;
        $rapport['format_conforme'] = (bool) $declare['suffisante'];
        $rapport['colonnes_reconnues'] = (int) $declare['score'];
        $rapport['colonnes_manquantes'] = $declare['manquantes'];

        if (! $declare['suffisante']) {
            $rapport['avertissements'][] = $probable === $formatDeclare
                ? sprintf(
                    'Ce fichier ressemble bien à « %s », mais il lui manque : %s.',
                    Registre::libelle($formatDeclare),
                    implode(', ', $declare['manquantes']),
                )
                : sprintf(
                    'Vous avez annoncé « %s », or les colonnes de ce fichier sont celles de « %s ». '
                    .'Vérifiez le type avant de lancer : un mauvais type ne remplit rien, il fait perdre du temps.',
                    Registre::libelle($formatDeclare),
                    Registre::libelle($probable),
                );
        }

        $rapport = $this->examinerLaVille($rapport, $chemin, $formatDeclare, $villeId);

        return $this->examinerLExercice($rapport, $chemin, $formatDeclare, $exercice);
    }

    /**
     * Le score de chaque format connu, du plus ressemblant au moins ressemblant.
     *
     * @return array<string, array{score: int, suffisante: bool, manquantes: list<string>}>
     */
    private function affinites(string $chemin): array
    {
        $affinites = [];

        foreach (array_keys(Registre::DISPONIBLES) as $cle) {
            $lecteur = Classeur::ouvrir($chemin);

            try {
                $mesure = $this->instancier($cle)->affinite($lecteur);

                if ($mesure['score'] > 0) {
                    $affinites[$cle] = $mesure;
                }
            } catch (\Throwable) {
                // Un format qui trébuche sur un fichier qui ne le concerne pas n'a rien à
                // dire : il ne doit surtout pas empêcher les autres de répondre.
                continue;
            } finally {
                $lecteur->fermer();
            }
        }

        // Un format qui reconnaît toutes ses colonnes indispensables passe devant un format
        // qui en reconnaît davantage sans les bonnes : c'est « suffisante » qui tranche.
        uasort(
            $affinites,
            fn (array $a, array $b) => [$b['suffisante'], $b['score']] <=> [$a['suffisante'], $a['score']],
        );

        return $affinites;
    }

    private function examinerLaVille(array $rapport, string $chemin, string $formatDeclare, ?int $villeId): array
    {
        if ($villeId === null || ! Registre::connait($formatDeclare)) {
            return $rapport;
        }

        $lecteur = Classeur::ouvrir($chemin);

        try {
            $codes = $this->instancier($formatDeclare)->codesRencontres($lecteur);
        } catch (\Throwable) {
            // Un format sans code employé — la caisse, les fournisseurs — n'a rien à dire ici.
            return $rapport;
        } finally {
            $lecteur->fermer();
        }

        if ($codes === []) {
            return $rapport;
        }

        $connus = CodeAgent::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNotNull('ville_id')
            ->whereIn('code', array_keys($codes))
            ->get(['code', 'ville_id', 'libelle']);

        if ($connus->isEmpty()) {
            return $rapport;
        }

        $parVille = [];
        $etrangers = [];
        $rattaches = 0;

        // Les villes d'où viennent les codes qui ne sont pas d'ici, chacune une fois.
        $villesEtrangeres = [];

        foreach ($connus as $agent) {
            $vues = $codes[$agent->code] ?? 0;
            $rattaches += $vues;
            $parVille[(int) $agent->ville_id] = ($parVille[(int) $agent->ville_id] ?? 0) + $vues;

            if ((int) $agent->ville_id !== $villeId) {
                $ailleurs = $this->nomDeVille((int) $agent->ville_id) ?? 'ville inconnue';
                $etrangers[$agent->code] = trim(($agent->libelle ?: 'employé non nommé').' — '.$ailleurs);
                $villesEtrangeres[$ailleurs] = true;
            }
        }

        // **On ne conclut que si l'on sait.** Sur les trente-huit codes du logiciel, huit
        // seulement sont rattachés à une ville aujourd'hui. Un fichier de Bouaké dont trois
        // lignes sur quatre cents portent un code connu — d'Abidjan — désignerait Abidjan
        // avec l'aplomb d'une mesure. Trois lignes ne sont pas une mesure. Tant que la
        // couverture est faible, cet écran se tait sur la ville : mieux vaut ne rien dire
        // que dire quelque chose de faux avec assurance.
        $echantillon = array_sum($codes);

        if ($rattaches < self::LIGNES_MINIMALES_POUR_CONCLURE
            || $rattaches / max(1, $echantillon) < self::COUVERTURE_MINIMALE) {
            $rapport['couverture_insuffisante'] = true;

            return $rapport;
        }

        arsort($parVille);
        $dominante = (int) array_key_first($parVille);
        $etrangeres = $rattaches - ($parVille[$villeId] ?? 0);

        $rapport['ville_probable'] = $this->nomDeVille($dominante);
        $rapport['ville_conforme'] = $dominante === $villeId;

        if ($etrangeres / $rattaches >= self::PART_MINIMALE_D_ALERTE) {
            $rapport['codes_etrangers'] = $etrangers;
            $rapport['villes_etrangeres'] = array_keys($villesEtrangeres);

            /*
             * **La phrase nommait la mauvaise ville, et se contredisait.** Elle annonçait
             * `ville_probable`, c'est-à-dire la ville *dominante* du fichier. Or quand la
             * dominante est celle du dépôt — 280 lignes d'Abidjan contre 117 de Bouaké —
             * on lisait « vous déposez au titre d'Abidjan, mais 117 lignes portent des
             * codes rattachés à Abidjan ». Le lecteur y voyait, à juste titre, que sa
             * correction n'avait servi à rien : la phrase disait le contraire d'elle-même.
             *
             * Ce qu'il faut nommer, ce sont les villes **des codes en cause**. C'est la
             * seule information qui aide : elle dit où l'on devrait déposer, ou qui a été
             * muté sans que son code suive.
             */
            $rapport['avertissements'][] = sprintf(
                'Vous déposez au titre de %s, mais %d ligne(s) sur %d portent des codes rattachés à %s (%s). '
                ."S'il ne s'agit pas d'une mutation, c'est la ville du dépôt qu'il faut corriger.",
                $rapport['ville_declaree'] ?? 'cette ville',
                $etrangeres,
                $rattaches,
                $villesEtrangeres === [] ? 'une autre ville' : implode(' et ', array_keys($villesEtrangeres)),
                implode(', ', array_slice(array_keys($etrangers), 0, 6)),
            );
        }

        return $rapport;
    }

    /**
     * Le fichier parle-t-il bien de l'exercice sous lequel on le dépose ?
     *
     * **Demandé par le propriétaire le 24/09** : « toute activité doit être dans
     * l'exercice en cours ; le jour où je fais un import dans un exercice qui ne
     * correspond pas à l'année, le système doit émettre un message et demander de
     * sélectionner la vraie année ».
     *
     * **Il avertit, il ne bloque pas** — comme le contrôle de ville, et pour la même
     * raison : une reprise de fin d'exercice se dépose légitimement en janvier suivant, et
     * refuser sur une ressemblance apprendrait surtout à contourner le refus. Le fichier
     * est mis de côté, la question posée, et l'on confirme ou l'on corrige.
     *
     * **Trois formats en sont exemptés** parce qu'ils portent un tableau initial de
     * plusieurs années : l'état des impayés, le suivi fournisseur et la balance
     * fournisseurs. Voir Format::porteUnSeulExercice().
     */
    private function examinerLExercice(array $rapport, string $chemin, string $formatDeclare, ?int $exercice): array
    {
        if ($exercice === null || ! Registre::connait($formatDeclare)) {
            return $rapport;
        }

        $classe = Registre::classe($formatDeclare);

        if (! $classe::porteUnSeulExercice()) {
            return $rapport;
        }

        $lecteur = Classeur::ouvrir($chemin);

        try {
            $annees = $this->instancier($formatDeclare)->anneesRencontrees($lecteur);
        } catch (\Throwable) {
            return $rapport;
        } finally {
            $lecteur->fermer();
        }

        $total = array_sum($annees);

        // Sous vingt lignes datées, on ne mesure rien : on devine, et une devinette n'a
        // pas à interrompre un dépôt. C'est le même seuil que pour les villes.
        if ($annees === [] || $total < self::LIGNES_MINIMALES_POUR_CONCLURE) {
            return $rapport;
        }

        $dominante = (int) array_key_first($annees);
        $rapport['annee_du_fichier'] = $dominante;

        if ($dominante === $exercice) {
            return $rapport;
        }

        $ailleurs = $total - ($annees[$exercice] ?? 0);

        if ($ailleurs / $total < self::PART_D_UNE_AUTRE_ANNEE) {
            return $rapport;
        }

        $rapport['avertissements'][] = sprintf(
            "Vous déposez sous l'exercice %d, mais %d ligne(s) sur %d datent de %d. "
            ."Si ce n'est pas voulu, changez d'exercice en haut de l'écran avant d'importer : "
            ."les lignes se rangeraient sous une année qui n'est pas la leur.",
            $exercice,
            $ailleurs,
            $total,
            $dominante,
        );

        return $rapport;
    }

    private function instancier(string $cle): Format
    {
        $classe = Registre::classe($cle);

        return new $classe($this->entrepriseId, new Rattachement($this->entrepriseId));
    }

    private function nomDeVille(?int $villeId): ?string
    {
        if ($villeId === null) {
            return null;
        }

        return Ville::withoutGlobalScopes()->whereKey($villeId)->value('nom');
    }
}

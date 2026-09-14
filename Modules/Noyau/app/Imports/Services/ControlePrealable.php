<?php

namespace Modules\Noyau\Imports\Services;

use Modules\Noyau\Entreprises\Modeles\Ville;
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
     *     couverture_insuffisante: bool,
     *     avertissements: list<string>,
     * }
     */
    public function examiner(string $chemin, string $formatDeclare, ?int $villeId): array
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

        return $this->examinerLaVille($rapport, $chemin, $formatDeclare, $villeId);
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

        foreach ($connus as $agent) {
            $vues = $codes[$agent->code] ?? 0;
            $rattaches += $vues;
            $parVille[(int) $agent->ville_id] = ($parVille[(int) $agent->ville_id] ?? 0) + $vues;

            if ((int) $agent->ville_id !== $villeId) {
                $etrangers[$agent->code] = trim(($agent->libelle ?: 'employé non nommé')
                    .' — '.($this->nomDeVille((int) $agent->ville_id) ?? 'ville inconnue'));
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
            $rapport['avertissements'][] = sprintf(
                'Vous déposez au titre de %s, mais %d ligne(s) sur %d portent des codes rattachés à %s (%s). '
                ."S'il ne s'agit pas d'une mutation, c'est la ville du dépôt qu'il faut corriger.",
                $rapport['ville_declaree'] ?? 'cette ville',
                $etrangeres,
                $rattaches,
                $rapport['ville_probable'] ?? 'une autre ville',
                implode(', ', array_slice(array_keys($etrangers), 0, 6)),
            );
        }

        return $rapport;
    }

    private function instancier(string $cle): \Modules\Noyau\Imports\Formats\Format
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

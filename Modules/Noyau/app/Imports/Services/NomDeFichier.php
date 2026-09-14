<?php

namespace Modules\Noyau\Imports\Services;

use Modules\Noyau\Imports\Formats\Registre;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;

/**
 * Ce que le nom d'un fichier laisse deviner — et rien de plus.
 *
 * Une convention de nommage est une bonne idée : elle évite à celui qui dépose de choisir
 * dans une liste, et elle supprime la classe entière des erreurs où l'on importe un état
 * de caisse en croyant importer des factures. On en propose donc une, et l'écran la
 * rappelle à chaque dépôt :
 *
 *     VILLE_TYPE_JJMMAA.xlsx        →  ABIDJAN_PARC_190826.xlsx
 *
 * **Mais le nom ne fait jamais foi, et c'est un point de conception, pas une prudence de
 * façade.** On sait de source directe comment ces fichiers naissent : quelqu'un extrait du
 * logiciel, filtre sur une ville, puis renomme à la main. Le renommage vient après le
 * filtre, il ne le décrit pas — et on l'a mesuré, le fichier « San Pédro » des devis
 * partage 216 de ses 217 proformas avec celui d'« Abidjan ».
 *
 * D'où la règle appliquée partout dans le module : **le nom propose, le contenu dispose.**
 * Ce que cette classe rend est une pré-sélection de formulaire. Le rattachement de chaque
 * ligne, lui, passe par la colonne SITE et le code agent, qui sont dans les données.
 *
 * Le désaccord entre les deux est d'ailleurs une information : un fichier nommé « BOUAKE »
 * dont toutes les lignes portent des codes d'Abidjan mérite qu'on le signale avant de
 * l'écrire.
 */
class NomDeFichier
{
    /** La convention proposée, montrée à l'écran au moment du dépôt. */
    public const CONVENTION = 'VILLE_TYPE_JJMMAA.xlsx';

    public const EXEMPLE = 'ABIDJAN_PARC_190826.xlsx';

    /**
     * Les mots qui trahissent la nature d'un fichier, par format.
     *
     * Chaque liste mêle le mot de la convention et ceux des noms historiques, parce que
     * les fichiers déjà produits ne vont pas se renommer tout seuls : « Situation du parc »
     * doit être reconnu aussi bien que « PARC ».
     *
     * @var array<string, list<string>>
     */
    private const INDICES = [
        'parc' => ['PARC', 'SITUATION DU PARC', 'FICHES', 'RECEPTION'],
        'devis' => ['DEVIS', 'PROFORMA', 'PROFORMAS'],
        'factures' => ['FACTURES', 'CATTC', 'CA TTC', 'CHIFFRE D AFFAIRES'],
        'impayes' => ['IMPAYES', 'IMPAYE', 'ETATS DES IMPAYES', 'CREANCES', 'RECOUVREMENT'],
        'fournisseurs' => ['FOURNISSEURS', 'FSF', 'SUIVI FOURNISSEURS', 'FRS'],
        'caisse' => ['CAISSE', 'ETAT CAISSE', 'TRESORERIE'],
        'entrees' => ['ENTREES', 'ENTREES DE VEHICULES'],
        'sorties' => ['SORTIES', 'SORTIES DE VEHICULES'],
    ];

    public function __construct(private int $entrepriseId) {}

    /**
     * Ce que le nom suggère : un format, une ville, une période.
     *
     * Chaque valeur peut être nulle, et l'écran le montre tel quel — un champ vide qui
     * attend une réponse vaut mieux qu'un champ pré-rempli au hasard.
     *
     * @return array{
     *     format: string|null, format_disponible: bool,
     *     ville_id: int|null, ville_libelle: string|null,
     *     periode: string|null, conforme: bool, indice_ville: string|null
     * }
     */
    public function analyser(string $nomFichier): array
    {
        $base = pathinfo($nomFichier, PATHINFO_FILENAME);
        $normalise = CorrespondanceImport::normaliser($base);

        $format = $this->formatDe($normalise);
        [$villeId, $villeLibelle, $indice] = $this->villeDe($normalise);

        return [
            'format' => $format,
            'format_disponible' => $format !== null && Registre::connait($format),
            'ville_id' => $villeId,
            'ville_libelle' => $villeLibelle,
            'periode' => $this->periodeDe($base),
            'conforme' => $this->conforme($base),
            'indice_ville' => $indice,
        ];
    }

    /** Vrai quand le nom suit la convention proposée. */
    public function conforme(string $base): bool
    {
        return (bool) preg_match('/^[A-Za-zÀ-ÿ\- ]+_[A-Za-z]+_\d{6}$/u', pathinfo($base, PATHINFO_FILENAME));
    }

    /** Le nom que ce fichier porterait s'il suivait la convention. */
    public function proposerUnNom(?string $ville, ?string $format, ?string $extension = 'xlsx'): string
    {
        return implode('_', [
            CorrespondanceImport::normaliser($ville ?? 'VILLE') ?: 'VILLE',
            mb_strtoupper($format ?? 'TYPE'),
            now()->format('dmy'),
        ]).'.'.ltrim((string) $extension, '.');
    }

    /**
     * Le format que le nom laisse deviner.
     *
     * Les indices les plus longs sont essayés d'abord : « SITUATION DU PARC » doit
     * l'emporter sur « PARC », et « ETATS DES IMPAYES » sur « IMPAYES ». Sans cela un
     * fichier de synthèse des impayés par parc partirait dans la mauvaise page.
     */
    private function formatDe(string $normalise): ?string
    {
        $trouve = null;
        $longueur = 0;

        foreach (self::INDICES as $format => $mots) {
            foreach ($mots as $mot) {
                if (mb_strlen($mot) > $longueur && str_contains($normalise, $mot)) {
                    $trouve = $format;
                    $longueur = mb_strlen($mot);
                }
            }
        }

        return $trouve;
    }

    /**
     * La ville que le nom laisse deviner.
     *
     * Les exports arrivent avec des noms mutilés par les accents perdus — « Bouak »,
     * « SanPdro », « San_Pdro ». On compare donc sur les premières lettres plutôt que sur
     * l'égalité : quatre lettres communes suffisent à reconnaître une ville, et sont trop
     * peu pour en confondre deux dans une entreprise qui en compte trois.
     *
     * @return array{0: int|null, 1: string|null, 2: string|null}
     */
    private function villeDe(string $normalise): array
    {
        $villes = \Modules\Noyau\Entreprises\Modeles\Ville::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->get(['id', 'nom', 'code']);

        foreach ($villes as $ville) {
            foreach ([$ville->nom, (string) $ville->code] as $libelle) {
                $cle = CorrespondanceImport::normaliser((string) $libelle);

                if ($cle === '' || mb_strlen($cle) < 3) {
                    continue;
                }

                // « SAN PEDRO » sans espace pour retrouver « SanPdro », puis le début du
                // mot pour absorber la lettre accentuée disparue.
                $compact = str_replace(' ', '', $cle);
                $debut = mb_substr($compact, 0, 4);

                if (str_contains(str_replace(' ', '', $normalise), $debut)) {
                    return [$ville->id, $ville->nom, $libelle];
                }
            }
        }

        return [null, null, null];
    }

    /** La période annoncée par le nom, quand il en porte une. */
    private function periodeDe(string $base): ?string
    {
        // « DU 01012026 AU 16032026 » — les états de caisse annoncent leur intervalle.
        if (preg_match('/(\d{2})(\d{2})(\d{4}).{1,6}?(\d{2})(\d{2})(\d{4})/u', $base, $t)) {
            return "{$t[1]}/{$t[2]}/{$t[3]} → {$t[4]}/{$t[5]}/{$t[6]}";
        }

        // « au190826 », « 190826 » — une date d'arrêté sur six chiffres.
        if (preg_match('/(?<!\d)(\d{2})(\d{2})(\d{2})(?!\d)/u', $base, $t)) {
            return "au {$t[1]}/{$t[2]}/20{$t[3]}";
        }

        return null;
    }
}

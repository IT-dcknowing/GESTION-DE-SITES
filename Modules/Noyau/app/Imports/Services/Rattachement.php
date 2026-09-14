<?php

namespace Modules\Noyau\Imports\Services;

use Modules\Noyau\Entreprises\Modeles\Site;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Imports\Modeles\CodeAgent;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;

/**
 * Où va une ligne importée : quelle ville, et quel site.
 *
 * C'est la question centrale de tout le module, et elle est difficile pour une raison
 * simple : **dans le logiciel, les trois villes vivent dans la même base.** Un export n'est
 * pas une ville, c'est une extraction que quelqu'un a filtrée puis renommée — on l'a
 * mesuré, le fichier « San Pédro » des devis contient 216 de ses 217 proformas en commun
 * avec celui d'Abidjan, et leurs fiches renvoient au parc d'Abidjan.
 *
 * Le nom du fichier ne prouve donc rien. Quatre sources sont interrogées, dans cet ordre
 * d'autorité, et la première qui répond gagne :
 *
 *   1. **Le dossier de la fiche, déjà en base** — la même affaire ne change pas de ville
 *      entre deux dépôts.
 *   2. **La colonne SITE du fichier** — factuelle quand elle existe (CATTC, impayés).
 *   3. **Le code agent du numéro de fiche** — « FR-KZN° 010669 » → KZ → sa ville.
 *   4. **La ville déclarée au dépôt** — en dernier recours, et la ligne est marquée.
 *
 * Le **site** suit une règle à part, et il faut la comprendre : Bouaké et San Pédro n'en
 * ont qu'un, donc la ville suffit. **Abidjan en a deux, et aucun fichier ne les distingue**
 * — la colonne SITE dit « ABIDJAN » sans plus de précision. Le seul discriminant possible
 * est le code de la personne qui a rédigé la fiche.
 *
 * Quand ce code n'est pas rattaché à un site, on s'arrête à la ville et on le dit. Une
 * ligne dont on ignore l'atelier se déclare inconnue ; elle ne se range pas au hasard entre
 * deux ateliers, parce qu'un chiffre d'affaires attribué au mauvais site est pire qu'un
 * chiffre d'affaires en attente d'affectation.
 *
 * **Sur la mémoire de ce service.** Il est écrit pour être construit une fois et réutilisé
 * sur des milliers de lignes. Les référentiels — villes, sites, codes — sont chargés une
 * seule fois, et les compteurs de rencontres sont accumulés puis écrits d'un bloc par
 * {@see terminer()}. La version naïve, qui interrogeait la base à chaque ligne, mettait
 * 72 secondes sur les 2 204 fiches d'Abidjan. Ce n'est pas une optimisation prématurée :
 * c'est la différence entre un import qu'on lance et un import qu'on n'ose pas lancer.
 */
class Rattachement
{
    /** D'où vient la décision — recopié sur la ligne, pour qu'on puisse la contester. */
    public const SOURCES = [
        'atelier' => 'Atelier déclaré au dépôt',
        'colonne' => 'Colonne SITE du fichier',
        'dossier' => 'Fiche déjà rattachée en base',
        'code' => 'Code agent du numéro de fiche',
        'depot' => 'Ville déclarée au dépôt',
        'inconnue' => 'Indéterminée',
    ];

    /**
     * Combien de fois un code doit paraître dans un dépôt filtré pour qu'on en tire une
     * conclusion sur son atelier.
     *
     * **Ce seuil vient d'une mesure, et il évite un dégât considérable.** Sans lui, un code
     * croisé une seule fois dans un fichier déclaré « Abidjan — Site 1 » y était rattaché
     * pour de bon. Éprouvé sur les vrais fichiers : le code YB, qui est celui de San Pédro,
     * apparaît **3 fois sur 2 204** dans le fichier d'Abidjan — un collègue de passage, ou
     * un numéro mal saisi. Ces trois lignes suffisaient à inscrire « YB = Abidjan » dans le
     * référentiel ; au dépôt suivant, les **801 fiches de San Pédro** partaient à Abidjan.
     *
     * Trois lignes de bruit en déplaçaient huit cents. D'où la règle : on ne conclut que sur
     * un code réellement installé dans le fichier, jamais sur un passage.
     *
     * Le seuil est le plus grand des deux — vingt occurrences, ou un centième du fichier —
     * pour valoir aussi bien sur les 168 fiches de Bouaké que sur les 2 204 d'Abidjan.
     */
    public const MINIMUM_POUR_APPRENDRE = 20;

    public const PART_MINIMALE_POUR_APPRENDRE = 0.01;

    /** @var array<string, int> code rencontré dans un dépôt filtré => site déclaré */
    private array $codesAApprendre = [];

    /** @var array<string, int> code => nombre de lignes de ce dépôt qui le portent */
    private array $vuesDansLeDepot = [];

    /**
     * Combien de lignes contredisent l'atelier déclaré au dépôt.
     *
     * Zéro sur un fichier correctement filtré. Un nombre élevé veut dire une chose et une
     * seule : l'extraction n'a pas été filtrée avant d'être exportée.
     */
    private int $desaccords = 0;

    /** @var array<int, int>|null site_id => ville_id */
    private ?array $villeDuSite = null;

    /** @var array<int, list<int>> ville_id => sites actifs */
    private array $sitesDeLaVille = [];

    /** @var array<int, string> ville_id => nom */
    private array $nomsDesVilles = [];

    /** @var array<string, int> libellé normalisé => ville_id */
    private array $annuaire = [];

    /** @var array<string, CodeAgent> */
    private array $agents = [];

    /** @var array<string, int> code => rencontres pas encore écrites */
    private array $rencontres = [];

    /** @var array<string, string|null> "domaine|source normalisée" => cible résolue */
    private array $correspondances = [];

    /** @var array<string, array{domaine: string, source: string, cible: string|null, n: int}> */
    private array $aEnregistrer = [];

    public function __construct(private int $entrepriseId) {}

    /**
     * Résout la ville et le site d'une ligne.
     *
     * @param  string|null  $colonneSite  la valeur brute de la colonne SITE, si le fichier en a une
     * @param  string|null  $reference  le numéro de fiche ou de proforma, d'où sort le code agent
     * @param  int|null  $villeDuDepot  la ville déclarée par le déposant
     * @param  int|null  $siteDuDossier  le site déjà connu pour cette fiche, s'il l'est
     * @param  bool  $dossierPresume  ce site était-il lui-même une présomption
     * @return array{ville_id: int|null, site_id: int|null, code: string|null, source: string, presumee: bool}
     */
    public function resoudre(
        ?string $colonneSite = null,
        ?string $reference = null,
        ?int $villeDuDepot = null,
        ?int $siteDuDossier = null,
        bool $dossierPresume = false,
        ?int $siteDuDepot = null,
    ): array {
        $this->charger();

        $code = CodeAgent::extraire($reference);
        $agent = $code !== null ? $this->agent($code) : null;

        $cascade = $this->cascade($colonneSite, $code, $agent, $villeDuDepot, $siteDuDossier, $dossierPresume);

        return $this->affiner($cascade, $siteDuDepot, $code);
    }

    /**
     * Applique l'atelier déclaré au dépôt, **sans jamais changer une ligne de ville**.
     *
     * C'est la règle la plus importante du module, et elle a été écrite après une erreur
     * qu'il vaut mieux raconter que taire.
     *
     * La première version faisait de l'atelier déclaré la source d'autorité absolue : dès
     * qu'un dépôt annonçait « Abidjan — Site 1 », toutes ses lignes y allaient. Éprouvée sur
     * les vrais fichiers, elle a rangé dans l'atelier 1 d'Abidjan les 804 fiches du code YB,
     * qui est celui de San Pédro — puis, pire, elle a **appris** que YB appartenait à
     * Abidjan. Une déclaration erronée sur un fichier ne s'était pas contentée de fausser ce
     * fichier : elle s'était inscrite dans le référentiel, d'où elle aurait faussé tous les
     * imports suivants.
     *
     * La cause n'était pas le code mais l'usage : le fichier n'avait pas été filtré avant
     * d'être extrait. Or c'est précisément l'erreur que l'on doit attendre — personne ne se
     * souvient toujours d'avoir filtré, et rien dans le fichier ne le dit.
     *
     * D'où la règle retenue : **la déclaration tranche l'atelier, elle ne déplace pas la
     * ville.** Elle répond exactement à la question pour laquelle elle a été introduite —
     * Site 1 ou Site 2 dans Abidjan, que rien d'autre ne sait distinguer — et elle se tait
     * sur celle à laquelle le code agent répond déjà mieux qu'elle.
     *
     * Quand une ligne appartient manifestement à une autre ville, le désaccord est compté et
     * remonté au déposant plutôt que résolu en silence : c'est ainsi qu'on apprend qu'on a
     * oublié de filtrer.
     */
    private function affiner(array $cascade, ?int $siteDuDepot, ?string $code): array
    {
        if ($siteDuDepot === null || ! isset($this->villeDuSite[$siteDuDepot])) {
            return $cascade;
        }

        $villeDeclaree = $this->villeDuSite[$siteDuDepot];

        // La ligne relève d'une autre ville : la déclaration ne s'applique pas, et le
        // désaccord se compte.
        if ($cascade['ville_id'] !== null && $cascade['ville_id'] !== $villeDeclaree) {
            $this->desaccords++;

            return $cascade;
        }

        // Même ville, ou ville inconnue : la déclaration tranche l'atelier.
        //
        // Le code n'est appris que s'il ne contredit rien — jamais quand il est déjà établi
        // ailleurs. Un code encore vierge peut malgré tout venir d'un fichier mal filtré :
        // c'est pourquoi l'écran des codes reste modifiable, et pourquoi le rattachement
        // appris ici n'écrase jamais un rattachement posé à la main.
        if ($code !== null) {
            $this->codesAApprendre[$code] = $siteDuDepot;
            $this->vuesDansLeDepot[$code] = ($this->vuesDansLeDepot[$code] ?? 0) + 1;
        }

        return $this->reponse($villeDeclaree, $siteDuDepot, $code, 'atelier');
    }

    /** La cascade historique, inchangée : dossier, colonne, code, dépôt. */
    private function cascade(
        ?string $colonneSite,
        ?string $code,
        ?CodeAgent $agent,
        ?int $villeDuDepot,
        ?int $siteDuDossier,
        bool $dossierPresume,
    ): array {
        // 1. Le dossier déjà rattaché : c'est la même fiche, elle ne peut pas avoir changé
        // de ville entre deux imports.
        //
        // Mais seulement si ce rattachement-là avait été **établi**, pas présumé. Un site
        // deviné au dépôt puis relu au dépôt suivant se blanchirait tout seul : on aurait
        // écrit « probablement Bouaké », puis on lirait « Bouaké, c'est écrit en base », et
        // la présomption serait devenue un fait sans que personne n'ait rien vérifié. Une
        // ligne présumée retraverse donc toute la cascade — ce qui la répare d'elle-même le
        // jour où le code agent est enfin renseigné.
        if ($siteDuDossier !== null && ! $dossierPresume && isset($this->villeDuSite[$siteDuDossier])) {
            return $this->reponse($this->villeDuSite[$siteDuDossier], $siteDuDossier, $code, 'dossier');
        }

        // 2. La colonne SITE du fichier, passée par la table des correspondances — c'est
        // elle qui rattrape « ABIIDJAN », « SAN-PEDRO » et « ÄBIDJAN ».
        $villeDeLaColonne = $this->villeDepuisLaColonne($colonneSite);

        if ($villeDeLaColonne !== null) {
            return $this->reponse(
                $villeDeLaColonne,
                $this->siteDansLaVille($villeDeLaColonne, $agent),
                $code,
                'colonne',
            );
        }

        // 3. Le code agent. C'est la seule source qui sait descendre au site.
        if ($agent?->ville_id !== null) {
            return $this->reponse(
                $agent->ville_id,
                $this->siteDansLaVille($agent->ville_id, $agent),
                $code,
                'code',
            );
        }

        // 4. Le dépôt, faute de mieux. La ligne entre, mais elle porte la mention.
        if ($villeDuDepot !== null) {
            return $this->reponse(
                $villeDuDepot,
                $this->siteDansLaVille($villeDuDepot, $agent),
                $code,
                'depot',
            );
        }

        return $this->reponse(null, null, $code, 'inconnue');
    }

    /**
     * Écrit ce que le parcours a rencontré : compteurs de codes et correspondances.
     *
     * À appeler une fois l'import fini, y compris quand il s'est mal passé — savoir quels
     * codes et quelles orthographes ont été croisés reste utile même sur un import annulé.
     */
    public function terminer(): void
    {
        $this->apprendreLesAteliers();

        foreach ($this->rencontres as $code => $combien) {
            if ($combien > 0) {
                CodeAgent::withoutGlobalScopes()
                    ->where('entreprise_id', $this->entrepriseId)
                    ->where('code', $code)
                    ->increment('occurrences', $combien);
            }
        }

        $this->rencontres = [];

        foreach ($this->aEnregistrer as $entree) {
            $correspondance = CorrespondanceImport::withoutGlobalScopes()->firstOrCreate(
                [
                    'entreprise_id' => $this->entrepriseId,
                    'domaine' => $entree['domaine'],
                    'valeur_source' => CorrespondanceImport::normaliser($entree['source']),
                ],
                [
                    'valeur_cible' => $entree['cible'],
                    'est_resolue' => $entree['cible'] !== null,
                    'occurrences' => 0,
                ],
            );

            $correspondance->increment('occurrences', $entree['n']);
        }

        $this->aEnregistrer = [];
    }

    /**
     * Le site à retenir dans une ville donnée.
     *
     * Deux cas seulement, et c'est ce qui rend la règle tenable :
     *
     * - la ville n'a **qu'un** site : il n'y a pas de choix à faire, on le prend ;
     * - la ville en a plusieurs — Abidjan — : seul le site du code agent peut trancher.
     *   S'il n'est pas renseigné, on rend null et la ligne reste au niveau de la ville.
     */
    private function siteDansLaVille(int $villeId, ?CodeAgent $agent): ?int
    {
        $siteDuCode = $agent?->site_id;

        // Un code dont le site appartient à une autre ville que celle retenue : c'est un
        // dépannage entre sites, ou une erreur de saisie. On ne l'impose pas.
        if ($siteDuCode !== null && ($this->villeDuSite[$siteDuCode] ?? null) === $villeId) {
            return $siteDuCode;
        }

        $sites = $this->sitesDeLaVille[$villeId] ?? [];

        return count($sites) === 1 ? $sites[0] : null;
    }

    /** La ville que désigne la colonne SITE, via la table des correspondances. */
    private function villeDepuisLaColonne(?string $colonneSite): ?int
    {
        $brut = trim((string) $colonneSite);

        if ($brut === '') {
            return null;
        }

        $normalise = CorrespondanceImport::normaliser($brut);

        // Le nom d'une ville, son code, ou le nom d'un site — « SITE 1 » désigne un lieu,
        // donc une ville.
        if (isset($this->annuaire[$normalise])) {
            $this->noter('site', $brut, $this->nomDeLaVille($this->annuaire[$normalise]));

            return $this->annuaire[$normalise];
        }

        // La correspondance posée à la main, pour les orthographes fantaisistes.
        $cible = $this->correspondance('site', $normalise);

        if ($cible !== null && isset($this->annuaire[CorrespondanceImport::normaliser($cible)])) {
            return $this->annuaire[CorrespondanceImport::normaliser($cible)];
        }

        // Inconnue : la question est posée, l'import continue.
        $this->noter('site', $brut, null);

        return null;
    }

    /** La correspondance déjà résolue pour cette valeur, ou null. */
    private function correspondance(string $domaine, string $normalise): ?string
    {
        $cle = $domaine.'|'.$normalise;

        if (! array_key_exists($cle, $this->correspondances)) {
            $ligne = CorrespondanceImport::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('domaine', $domaine)
                ->where('valeur_source', $normalise)
                ->first();

            $this->correspondances[$cle] = $ligne?->est_resolue ? $ligne->valeur_cible : null;
        }

        return $this->correspondances[$cle];
    }

    /** Retient qu'une valeur a été croisée, sans écrire tout de suite. */
    private function noter(string $domaine, string $source, ?string $cible): void
    {
        $cle = $domaine.'|'.CorrespondanceImport::normaliser($source);

        if (! isset($this->aEnregistrer[$cle])) {
            $this->aEnregistrer[$cle] = ['domaine' => $domaine, 'source' => $source, 'cible' => $cible, 'n' => 0];
        }

        $this->aEnregistrer[$cle]['n']++;
    }

    /**
     * Rattache à leur atelier les codes croisés dans un dépôt filtré.
     *
     * **On ne remplit que ce qui est vide, jamais ce qui est déjà renseigné.** Un code déjà
     * rattaché — à la main par le gérant, ou par un dépôt précédent — ne se laisse pas
     * réécrire par un fichier suivant. Si le même code apparaît dans deux extractions
     * filtrées sur deux ateliers différents, c'est une contradiction réelle : quelqu'un
     * travaille sur les deux sites, ou une extraction a été mal filtrée. Dans les deux cas,
     * le trancher tout seul reviendrait à faire basculer silencieusement l'historique d'un
     * atelier à l'autre. Le premier rattachement tient, et l'écran des codes reste là pour
     * le corriger en connaissance de cause.
     */
    private function apprendreLesAteliers(): void
    {
        $total = array_sum($this->vuesDansLeDepot);
        $seuil = max(self::MINIMUM_POUR_APPRENDRE, (int) ceil($total * self::PART_MINIMALE_POUR_APPRENDRE));

        foreach ($this->codesAApprendre as $code => $siteId) {
            // Un code de passage n'apprend rien : voir le commentaire de MINIMUM_POUR_APPRENDRE.
            if (($this->vuesDansLeDepot[$code] ?? 0) < $seuil) {
                continue;
            }

            $ville = $this->villeDuSite[$siteId] ?? null;

            CodeAgent::withoutGlobalScopes()
                ->where('entreprise_id', $this->entrepriseId)
                ->where('code', $code)
                ->whereNull('site_id')
                // Et jamais un code déjà connu dans une autre ville : la déclaration
                // précise un atelier, elle ne réécrit pas une ville établie.
                ->where(fn ($q) => $q->whereNull('ville_id')->orWhere('ville_id', $ville))
                ->update([
                    'site_id' => $siteId,
                    'ville_id' => $ville,
                    'updated_at' => now(),
                ]);
        }

        $this->codesAApprendre = [];
        $this->vuesDansLeDepot = [];
    }

    /** Combien de lignes ont contredit l'atelier déclaré — zéro sur un fichier bien filtré. */
    public function desaccords(): int
    {
        return $this->desaccords;
    }

    /** Le code agent, créé s'il est neuf, et compté pour l'écriture finale. */
    private function agent(string $code): CodeAgent
    {
        $code = mb_strtoupper($code);

        if (! isset($this->agents[$code])) {
            $this->agents[$code] = CodeAgent::withoutGlobalScopes()->firstOrCreate(
                ['entreprise_id' => $this->entrepriseId, 'code' => $code],
                ['est_actif' => true, 'occurrences' => 0],
            );
        }

        $this->rencontres[$code] = ($this->rencontres[$code] ?? 0) + 1;

        return $this->agents[$code];
    }

    private function nomDeLaVille(int $villeId): ?string
    {
        return $this->nomsDesVilles[$villeId] ?? null;
    }

    /** Charge une fois pour toutes les référentiels que le rattachement interroge. */
    private function charger(): void
    {
        if ($this->villeDuSite !== null) {
            return;
        }

        $this->villeDuSite = [];

        $villes = Ville::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->get(['id', 'nom', 'code']);

        foreach ($villes as $ville) {
            $this->nomsDesVilles[$ville->id] = $ville->nom;

            foreach ([$ville->nom, (string) $ville->code] as $libelle) {
                $normalise = CorrespondanceImport::normaliser((string) $libelle);

                if ($normalise !== '') {
                    $this->annuaire[$normalise] ??= $ville->id;
                }
            }
        }

        $sites = Site::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->get(['id', 'nom', 'code', 'ville_id', 'est_actif']);

        foreach ($sites as $site) {
            if ($site->ville_id === null) {
                continue;
            }

            $this->villeDuSite[$site->id] = $site->ville_id;

            if ($site->est_actif) {
                $this->sitesDeLaVille[$site->ville_id][] = $site->id;
            }

            foreach ([$site->nom, (string) $site->code] as $libelle) {
                $normalise = CorrespondanceImport::normaliser((string) $libelle);

                // Un nom de site ne prend jamais la place d'un nom de ville dans
                // l'annuaire : « Bouaké » le site ne doit pas masquer « Bouaké » la ville.
                if ($normalise !== '') {
                    $this->annuaire[$normalise] ??= $site->ville_id;
                }
            }
        }
    }

    /** @return array{ville_id: int|null, site_id: int|null, code: string|null, source: string, presumee: bool} */
    private function reponse(?int $villeId, ?int $siteId, ?string $code, string $source): array
    {
        return [
            'ville_id' => $villeId,
            'site_id' => $siteId,
            'code' => $code,
            'source' => $source,
            // « Présumée » désigne ce qui n'a pas été établi par la donnée elle-même :
            // c'est ce que l'écran met en évidence pour qu'on puisse le corriger.
            'presumee' => in_array($source, ['depot', 'inconnue'], true),
        ];
    }
}

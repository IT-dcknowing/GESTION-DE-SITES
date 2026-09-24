<?php

namespace Modules\Noyau\Imports\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Imports\Modeles\CorrespondanceImport;

/**
 * Lire, dans la colonne libre de la fiche de réception, le commercial qui a décroché l'affaire.
 *
 * **Le manque auquel cela répond.** Sur 2 673 devis mesurés le 21/09, 2 432 viennent de
 * l'import du logiciel d'atelier et ne portent aucun commercial : le chiffre de celui qui a
 * décroché l'affaire ne les compte pas, et la prospection reste « sans suite » alors qu'elle
 * en a eu une. Le rapprochement par la plaque et le nom comble une partie du trou, jamais
 * tout : deux visites du même client dans le mois se ressemblent trop.
 *
 * La direction a tranché sur un moyen qui ne demande **aucune colonne nouvelle au logiciel
 * d'atelier** : la fiche de réception porte déjà « INFORMATIONS SUR LA SITUATION », une
 * colonne libre qui remonte telle quelle dans l'export du parc. Quand la venue fait suite à
 * une prospection, le saisisseur y met le commercial **en première position**. Le reste de
 * la colonne continue de servir à ce qu'il servait : on ne lit que son début.
 *
 * **Trois écritures acceptées, parce que trois habitudes coexistent.**
 *
 * 1. Le **code de deux lettres** du logiciel d'atelier — `KZ` — celui qui est déjà dans les
 *    numéros de fiche. C'est le plus court à taper, et le seul qui ne se trompe pas.
 * 2. Le **code de l'application** — `C-0001`, `SP-ABJ` — celui qu'affichent nos écrans.
 * 3. Le **nom**, qui est ce que les gens écrivent spontanément, et donc ce qui se déforme.
 *
 * **Sur la ponctuation qui sépare.** Trois caractères, arrêtés par le propriétaire le
 * 24/09 : la **virgule**, le **point-virgule** et le **point** — plus le retour à la ligne,
 * que la colonne contient. Il a retiré lui-même le tiret et le blanc souligné de sa
 * première liste, et c'est la bonne décision : le tiret vit à l'intérieur des noms
 * composés — « Marie-Claire Aya » — et à l'intérieur des codes de l'application —
 * « C-0001 ». Le garder obligeait à une règle d'exception que personne n'aurait devinée
 * en saisissant, et une règle qu'on ne peut pas expliquer en une phrase au saisisseur est
 * une règle qui sera mal appliquée.
 *
 * **Les espaces autour ne gênent pas.** « Koffi Yao , RAS », « Koffi Yao;RAS » et
 * « Koffi Yao.  RAS » donnent tous « Koffi Yao » : on coupe sur le caractère, puis on
 * débarrasse ce qui reste de ses espaces et de sa ponctuation de bord.
 *
 * **Sur les noms mal saisis.** « KOFI YAO » pour « Koffi Yao », « M. AYA » pour
 * « Marie-Claire Aya » : un rapprochement automatique se tromperait un jour, et ce jour-là
 * il attribuerait le chiffre d'un commercial à un autre sans qu'une ligne ne s'affiche.
 * **On ne devine donc pas.** Ce qui ne correspond pas exactement est posé comme une
 * question, avec les noms du référentiel classés du plus proche au plus lointain, et la
 * réponse est retenue une fois pour toutes dans {@see CorrespondanceImport} : une même
 * faute de frappe ne se demande jamais deux fois.
 */
class CommercialDeLaFiche
{
    /** Le domaine sous lequel les questions de ce service vivent. */
    public const DOMAINE = 'commercial';

    /**
     * En deçà de cette ressemblance, le début de la colonne ne cherche pas à nommer quelqu'un.
     *
     * **Pourquoi un plancher, et pourquoi celui-là.** La colonne reste libre : elle
     * contient aussi « RAS », « véhicule livré », « en attente pièce ». Sans plancher,
     * chacune de ces phrases deviendrait une question à l'écran — et une liste de
     * questions où neuf sur dix n'en sont pas ne se lit plus du tout. On ne pose donc la
     * question que lorsque ce qui est écrit **ressemble** à quelqu'un du référentiel.
     *
     * Cinquante-cinq pour cent laisse passer les abréviations et les fautes de frappe
     * — « KOFI YAO » contre « Koffi Yao » dépasse les quatre-vingt-dix — et arrête les
     * phrases ordinaires. Le seuil ne décide jamais à la place de personne : il décide
     * seulement s'il y a lieu de demander.
     */
    public const RESSEMBLANCE_MINIMALE = 55.0;

    /**
     * Par quel chemin on a conclu. Un rattachement dont on ne sait plus s'il a été lu ou
     * décidé ne se défend pas devant celui à qui on retire le chiffre.
     */
    public const SOURCES = [
        'code_atelier' => 'Code du logiciel d’atelier',
        'code_application' => 'Code de l’application',
        'nom' => 'Nom exact',
        'correspondance' => 'Correspondance décidée à l’écran',
    ];

    /** @var Collection<int, object>|null le référentiel, lu une fois par import */
    private ?Collection $commerciaux = null;

    /** @var array<string, int>|null code de deux lettres => identifiant du commercial */
    private ?array $parCodeAtelier = null;

    public function __construct(private int $entrepriseId) {}

    /**
     * Ce que la colonne dit du commercial, et ce qu'on en a conclu.
     *
     * @return array{saisi: ?string, commercial_id: ?int, source: ?string}
     */
    public function lire(?string $informations): array
    {
        $saisi = self::premierSegment($informations);

        if ($saisi === null) {
            return ['saisi' => null, 'commercial_id' => null, 'source' => null];
        }

        foreach (['parCode', 'parCodeDApplication', 'parNom', 'parCorrespondance'] as $piste) {
            $trouve = $this->{$piste}($saisi);

            if ($trouve !== null) {
                return ['saisi' => $saisi, 'commercial_id' => $trouve[0], 'source' => $trouve[1]];
            }
        }

        /*
         * Rien ne répond à ce début de colonne. Deux cas, et ils n'appellent pas la même
         * réponse : ou bien cela ressemble à quelqu'un — un nom abrégé, une faute de
         * frappe — et c'est une question à poser ; ou bien cela ne ressemble à personne,
         * et c'est simplement l'autre usage de la colonne, celui qu'elle avait avant.
         * Consigner « RAS » comme une question à trancher noierait les vraies.
         */
        if (! $this->ressembleAQuelquun($saisi)) {
            return ['saisi' => null, 'commercial_id' => null, 'source' => null];
        }

        // On n'invente pas : la rencontre est consignée sans réponse, et l'écran des
        // traitements la posera comme une question. L'import, lui, continue — un nom
        // qu'on ne reconnaît pas n'est pas une raison de refuser une fiche de réception.
        CorrespondanceImport::rencontrer($this->entrepriseId, self::DOMAINE, $saisi);

        return ['saisi' => $saisi, 'commercial_id' => null, 'source' => null];
    }

    /**
     * Le début de la colonne, avant le premier séparateur qui en est vraiment un.
     *
     * Rend null quand la colonne est vide, ou quand son début ne peut porter aucun nom :
     * une date, un montant, un numéro posés en tête. Ce qui contient des lettres est rendu
     * tel quel — c'est {@see lire()} qui décide ensuite si cela cherche à nommer quelqu'un.
     */
    public static function premierSegment(?string $informations): ?string
    {
        $texte = trim((string) $informations);

        if ($texte === '') {
            return null;
        }

        /*
         * **Trois séparateurs, et trois seulement** : la virgule, le point-virgule et le
         * point. Arrêté par le propriétaire le 24/09, qui a retiré de sa liste le tiret et
         * le blanc souligné. C'est la bonne décision, et elle simplifie tout : le tiret vit
         * à l'intérieur des noms composés — « Marie-Claire Aya » — et à l'intérieur des
         * codes de l'application — « C-0001 ». Le garder comme séparateur obligeait à une
         * règle d'exception (« il ne sépare qu'entouré d'espaces ») que personne n'aurait
         * devinée en saisissant. Une règle qu'on ne peut pas expliquer en une phrase au
         * saisisseur est une règle qui sera mal appliquée.
         *
         * Le retour à la ligne sépare aussi : la colonne en contient, et une phrase qui
         * commence à la ligne suivante n'est plus le début de la colonne.
         *
         * **Les espaces ne gênent pas.** « Koffi Yao , RAS », « Koffi Yao;RAS »,
         * « Koffi Yao.  RAS » donnent tous « Koffi Yao » : le découpage se fait sur le
         * caractère, et ce qui reste est débarrassé de ses espaces et de sa ponctuation de
         * bord. Un point à l'intérieur d'un mot — une initiale, « M.AYA » — coupe, lui
         * aussi ; c'est assumé, le point est le séparateur que le propriétaire a retenu.
         */
        $morceaux = preg_split('/[;,.\r\n]/u', $texte, 2);

        $segment = trim((string) ($morceaux[0] ?? ''), " \t\n\r\0\x0B.,;:/");

        if ($segment === '' || mb_strlen($segment) > 120) {
            return null;
        }

        // Un segment sans la moindre lettre ne désigne personne : une date, un montant,
        // un numéro de téléphone posé en tête de colonne.
        if (preg_match('/\p{L}/u', $segment) !== 1) {
            return null;
        }

        return $segment;
    }

    /**
     * Les questions en attente, chacune avec les noms du référentiel les plus proches.
     *
     * @return Collection<int, array{correspondance: CorrespondanceImport, candidats: array<int, string>}>
     */
    public function questions(int $limite = 30): Collection
    {
        $attentes = CorrespondanceImport::withoutGlobalScopes()
            ->where('entreprise_id', $this->entrepriseId)
            ->where('domaine', self::DOMAINE)
            ->where('est_resolue', false)
            ->orderByDesc('occurrences')
            ->limit($limite)
            ->get();

        return $attentes->map(fn (CorrespondanceImport $attente) => [
            'correspondance' => $attente,
            'candidats' => $this->plusProches($attente->valeur_source),
        ]);
    }

    /**
     * Les commerciaux du référentiel, du plus ressemblant au plus lointain.
     *
     * La ressemblance sert **à classer**, jamais à décider. C'est toute la différence :
     * mettre le bon nom en tête de liste fait gagner un geste, le choisir à la place de
     * quelqu'un attribue un chiffre d'affaires sur une impression.
     *
     * @return array<int, string> identifiant => nom, le plus proche d'abord
     */
    public function plusProches(string $saisi, int $combien = 8): array
    {
        $cle = CorrespondanceImport::normaliser($saisi);

        $classes = $this->referentiel()
            ->map(function (object $commercial) use ($cle) {
                $nom = CorrespondanceImport::normaliser((string) $commercial->nom);
                similar_text($cle, $nom, $pourcentage);

                return [
                    'id' => (int) $commercial->id,
                    'nom' => (string) $commercial->nom,
                    'numero' => (string) $commercial->numero,
                    'proximite' => $pourcentage,
                ];
            })
            ->sortByDesc('proximite')
            ->take($combien);

        $rendu = [];

        foreach ($classes as $candidat) {
            $rendu[$candidat['id']] = $candidat['nom'].' ('.$candidat['numero'].')';
        }

        return $rendu;
    }

    /**
     * Retient une réponse, et l'applique aux fiches déjà lues sous ce libellé.
     *
     * **Pourquoi reprendre les fiches passées.** Un fichier se dépose, la question se pose,
     * on y répond une heure plus tard : sans reprise, il faudrait redéposer le fichier pour
     * que la réponse serve. C'est la même reprise que celle des codes employés, et elle ne
     * touche que les fiches dont le libellé saisi est exactement celui qu'on vient de
     * reconnaître.
     *
     * @return int le nombre de fiches rattachées
     */
    public function repondre(string $saisi, int $commercialId): int
    {
        $existe = DB::table('commerciaux')
            ->where('entreprise_id', $this->entrepriseId)
            ->where('id', $commercialId)
            ->exists();

        if (! $existe) {
            return 0;
        }

        $cle = CorrespondanceImport::normaliser($saisi);

        CorrespondanceImport::withoutGlobalScopes()->updateOrCreate(
            ['entreprise_id' => $this->entrepriseId, 'domaine' => self::DOMAINE, 'valeur_source' => $cle],
            ['valeur_cible' => (string) $commercialId, 'est_resolue' => true],
        );

        // Les fiches déjà lues sous ce libellé, et elles seules. Comparer sur la forme
        // normalisée retrouve « KOFI  yao » comme « Kofi Yao » : c'est la même question,
        // elle n'a pas à être posée deux fois.
        $reprises = 0;

        DB::table('dossiers_vehicules')
            ->where('entreprise_id', $this->entrepriseId)
            ->whereNull('commercial_id')
            ->whereNotNull('commercial_saisi')
            ->select(['id', 'commercial_saisi'])
            ->orderBy('id')
            ->chunk(500, function ($fiches) use ($cle, $commercialId, &$reprises) {
                $ids = [];

                foreach ($fiches as $fiche) {
                    if (CorrespondanceImport::normaliser((string) $fiche->commercial_saisi) === $cle) {
                        $ids[] = $fiche->id;
                    }
                }

                if ($ids !== []) {
                    $reprises += DB::table('dossiers_vehicules')->whereIn('id', $ids)->update([
                        'commercial_id' => $commercialId,
                        'commercial_source' => 'correspondance',
                        'updated_at' => now(),
                    ]);
                }
            });

        return $reprises;
    }

    /** Ce début de colonne cherche-t-il à nommer quelqu'un, ou parle-t-il d'autre chose ? */
    public function ressembleAQuelquun(string $saisi): bool
    {
        $cle = CorrespondanceImport::normaliser($saisi);

        foreach ($this->referentiel() as $commercial) {
            similar_text($cle, CorrespondanceImport::normaliser((string) $commercial->nom), $pourcentage);

            if ($pourcentage >= self::RESSEMBLANCE_MINIMALE) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ les quatre pistes

    /** @return array{0: int, 1: string}|null */
    private function parCode(string $saisi): ?array
    {
        if (preg_match('/^[A-Za-z]{2}$/', $saisi) !== 1) {
            return null;
        }

        $this->parCodeAtelier ??= DB::table('codes_agents')
            ->join('commerciaux', 'commerciaux.user_id', '=', 'codes_agents.user_id')
            ->where('codes_agents.entreprise_id', $this->entrepriseId)
            ->where('commerciaux.entreprise_id', $this->entrepriseId)
            ->whereNotNull('codes_agents.user_id')
            ->pluck('commerciaux.id', 'codes_agents.code')
            ->map(fn ($id) => (int) $id)
            ->all();

        $id = $this->parCodeAtelier[mb_strtoupper($saisi)] ?? null;

        return $id === null ? null : [$id, 'code_atelier'];
    }

    /** @return array{0: int, 1: string}|null */
    private function parCodeDApplication(string $saisi): ?array
    {
        $cle = mb_strtoupper(trim($saisi));

        $commercial = $this->referentiel()
            ->first(fn (object $c) => mb_strtoupper((string) $c->numero) === $cle);

        return $commercial === null ? null : [(int) $commercial->id, 'code_application'];
    }

    /** @return array{0: int, 1: string}|null */
    private function parNom(string $saisi): ?array
    {
        $cle = CorrespondanceImport::normaliser($saisi);

        $commercial = $this->referentiel()
            ->first(fn (object $c) => CorrespondanceImport::normaliser((string) $c->nom) === $cle);

        return $commercial === null ? null : [(int) $commercial->id, 'nom'];
    }

    /** @return array{0: int, 1: string}|null */
    private function parCorrespondance(string $saisi): ?array
    {
        $cible = CorrespondanceImport::resoudre($this->entrepriseId, self::DOMAINE, $saisi);

        if ($cible === null || ! ctype_digit((string) $cible)) {
            return null;
        }

        $id = (int) $cible;

        // La correspondance a pu désigner quelqu'un qui n'est plus au référentiel : mieux
        // vaut reposer la question que rattacher à une fiche effacée.
        return $this->referentiel()->contains(fn (object $c) => (int) $c->id === $id)
            ? [$id, 'correspondance']
            : null;
    }

    /** @return Collection<int, object> */
    private function referentiel(): Collection
    {
        return $this->commerciaux ??= collect(DB::table('commerciaux')
            ->where('entreprise_id', $this->entrepriseId)
            ->where('est_spontane', false)
            ->orderBy('nom')
            ->get(['id', 'nom', 'numero', 'user_id']));
    }
}

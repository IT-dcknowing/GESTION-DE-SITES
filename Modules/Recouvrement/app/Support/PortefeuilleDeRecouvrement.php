<?php

namespace Modules\Recouvrement\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Noyau\Commun\Services\NombreDeJours;
use Modules\Noyau\Exploitation\Modeles\Encaissement;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;

/**
 * Le portefeuille de créances, vu par celui qui doit le recouvrer.
 *
 * **Ce que les écrans existants ne disaient pas.** La balance âgée répond à « combien nous
 * doit-on, et depuis quand ». C'est la question du comptable. Celle de l'agent de
 * recouvrement est autre : *sur quel dossier dois-je passer ma matinée, qui s'en occupe
 * déjà, et qu'a-t-on obtenu la dernière fois qu'on a appelé ?* Aucun écran ne la portait.
 * On avait un état de la dette, et aucun état du travail.
 *
 * Cette classe réunit les deux en une seule ligne par tiers : ce qui est dû, son âge, le
 * dernier geste fait dessus, par qui, ce qui a été promis, et ce qui est effectivement
 * rentré. C'est la ligne qu'on emporte en réunion.
 *
 * **Sur « qui s'en charge », et pourquoi ce n'est pas une affectation.** Il n'existe pas
 * de table qui distribuerait les tiers entre les agents, et je n'en ai pas inventé une :
 * une affectation qui ne serait jamais tenue à jour mentirait au bout d'un mois. Le
 * responsable affiché est **celui qui a fait la dernière relance** — un fait, daté et
 * signé, et non une intention. Un tiers que personne n'a jamais relancé est annoncé pour
 * ce qu'il est : à confier. C'est précisément la liste qu'un superviseur cherche.
 *
 * **Sur les rapprochements.** Un encaissement se rattache à son tiers par sa facture, et
 * non par la colonne `client` qu'il porte : c'est la facture qui sait qui paie — courtier,
 * puis compagnie, puis client — et c'est le même chemin que la balance âgée. Deux chemins
 * différents donneraient deux totaux différents pour la même créance, et le tableau de
 * bord contredirait la balance.
 */
final class PortefeuilleDeRecouvrement
{
    /** Au-delà, un tiers sans le moindre geste depuis si longtemps mérite d'être signalé. */
    public const SILENCE_ALERTE = 30;

    /**
     * Lignes par page du portefeuille.
     *
     * Vingt-cinq tiennent dans un écran sans faire défiler la page entière, et couvrent
     * largement les tiers qui pèsent : sur la base actuelle, les vingt-cinq premiers
     * portent l'essentiel de l'encours.
     */
    public const PAR_PAGE = 25;

    private Collection $ouvertes;

    /**
     * La plus vieille créance ouverte de chaque tiers, retenue dès la première passe.
     *
     * @var array<string, array{jour: int, depuis: int, date: Carbon, numero: ?string}>
     */
    private array $plusAncienneParTiers = [];

    /** Les lignes du tableau, calculées une fois par instance. */
    private ?Collection $lignes = null;

    /** Les encaissements de la période, lus une seule fois. */
    private ?Collection $encaissements = null;

    /**
     * Le tiers payant de chaque facture, toutes factures confondues.
     *
     * Elle était construite deux fois — une fois pour rapprocher les règlements, une fois
     * pour ouvrir un dossier — soit deux lectures complètes de la table des factures pour
     * une même correspondance. Sur la base actuelle, cela faisait près de deux secondes
     * perdues à l'ouverture d'une fiche.
     *
     * @var ?Collection<int, string>
     */
    private ?Collection $tiersParFacture = null;

    /** @var Collection<string, Collection<int, RelanceRecouvrement>> */
    private Collection $relancesParTiers;

    /** @var ?array<string, array{montant: int, date: ?Carbon, auteur: ?string}> */
    private ?array $encaissementsParTiers = null;

    public function __construct(
        private readonly Carbon $arrete,
        private readonly ?Carbon $debut = null,
    ) {
        // Lues telles que la base les rend, sans en faire des objets : ce tableau
        // consolide mille trois cent quarante créances sans en afficher une seule ligne à
        // ligne, et chaque lecture d'une colonne date coûtait quarante-sept microsecondes.
        // Voir Recouvrement::lignesOuvertes().
        $this->ouvertes = Recouvrement::lignesOuvertes($this->arrete);

        // La plus vieille créance de chaque tiers se retient en une seule passe : c'est
        // elle que le tableau nomme, et la chercher ensuite tiers par tiers ferait balayer
        // les mille trois cents lignes pour chacun des deux mille quatre cents tiers, soit
        // trois millions de comparaisons.
        $jourArrete = NombreDeJours::jour($this->arrete);

        foreach ($this->ouvertes as $ligne) {
            $payeur = Facture::tiersPayantParmi(
                $ligne->depose_chez, $ligne->courtier, $ligne->assureur, $ligne->client,
            );

            $depart = NombreDeJours::jourDeLIso($ligne->date_reception ?: $ligne->date);

            if ($depart === null) {
                continue;
            }

            $connue = $this->plusAncienneParTiers[$payeur] ?? null;

            if ($connue === null || $depart < $connue['jour']) {
                $this->plusAncienneParTiers[$payeur] = [
                    'jour' => $depart,
                    'depuis' => max(0, $jourArrete - $depart),
                    'date' => Carbon::parse(substr(trim((string) ($ligne->date_reception ?: $ligne->date)), 0, 10)),
                    'numero' => $ligne->n_facture,
                ];
            }
        }
        $this->relancesParTiers = RelanceRecouvrement::query()
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->groupBy('tiers');
    }

    /**
     * Une ligne par tiers débiteur, la plus lourde en tête.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lignes(): Collection
    {
        return $this->lignes ??= Recouvrement::parTiers($this->ouvertes, $this->arrete)
            ->map(function (array $ligne) {
                $tiers = $ligne['tiers'];
                $relances = $this->relancesParTiers->get($tiers, collect());
                $derniere = $relances->first();
                $this->encaissementsParTiers ??= $this->rapprocherLesEncaissements();
                $encaisse = $this->encaissementsParTiers[$tiers] ?? ['montant' => 0, 'date' => null, 'auteur' => null];

                // La plus ancienne au sens du recouvrement : déposée la première, et non
                // éditée la première — voir Recouvrement::dateDeDepart(). Retenue au
                // constructeur, dans la passe qui groupe déjà les créances.
                $plusAncienne = $this->plusAncienneParTiers[$tiers] ?? null;

                return $ligne + [
                    // Depuis quand ce tiers doit-il : l'âge de sa plus vieille facture non
                    // soldée. C'est ce chiffre qui classe un dossier, pas le montant seul.
                    'depuis' => $plusAncienne['depuis'] ?? null,
                    'plus_ancienne' => $plusAncienne['date'] ?? null,
                    'plus_ancienne_numero' => $plusAncienne['numero'] ?? null,

                    // Le dernier geste, et qui l'a fait.
                    'derniere_relance' => $derniere?->date,
                    'dernier_niveau' => $derniere ? (int) $derniere->niveau : null,
                    'relances' => $relances->count(),
                    'responsable' => $derniere?->responsable,
                    'responsable_id' => $derniere ? (int) $derniere->user_id : null,
                    'statut' => $derniere?->statut,

                    // Ce qui a été promis et qui n'est pas encore rentré : l'engagement
                    // qu'on rappelle au téléphone, et le premier motif de rappel.
                    'promis' => (int) $relances
                        ->where('statut', 'Promesse de règlement')
                        ->sum('montant_promis'),

                    // Ce qui est réellement rentré sur la période regardée.
                    'encaisse' => $encaisse['montant'],
                    'dernier_encaissement' => $encaisse['date'],
                    'encaisse_par' => $encaisse['auteur'],

                    // Le silence : jours écoulés depuis le dernier geste, relance ou
                    // encaissement confondus. Sans relance ni règlement, c'est l'âge de la
                    // créance elle-même — personne ne s'en est jamais occupé.
                    'silence' => $this->silence(
                        $derniere?->date,
                        $encaisse['date'],
                        $plusAncienne['date'] ?? null,
                    ),
                ];
            })
            ->values();
    }

    /**
     * L'activité de chaque agent sur la période : ce qu'il a fait, pas ce qu'il devrait faire.
     *
     * Les comptes sans le moindre geste y figurent aussi, à zéro. Un tableau qui n'affiche
     * que ceux qui ont travaillé cache exactement ce qu'un superviseur cherche.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function agents(?Closure $retient = null): Collection
    {
        $comptes = $this->comptesDuRecouvrement();

        // Les relances sont déjà en mémoire depuis le constructeur : les relire ici en
        // ferait une seconde requête pour le même contenu. Et elles suivent le filtre —
        // chercher un tiers doit montrer qui a travaillé *sur ce tiers*, pas l'activité de
        // tout le monde sur tout le portefeuille.
        $relances = $this->relancesRetenues($retient);
        $encaissements = $this->reglementsRetenus($retient);

        return $comptes->map(function (User $compte) use ($relances, $encaissements) {
            $siennes = $relances->where('user_id', $compte->id);
            $siens = $encaissements->where('cree_par', $compte->id);

            return [
                'id' => $compte->id,
                'nom' => $compte->name,
                'role' => $compte->hasRole('superviseur_recouvrement')
                    ? 'Superviseur'
                    : ($compte->hasRole('gerant') ? 'Gérant' : 'Agent'),
                'relances' => $siennes->count(),
                'niveau_max' => (int) ($siennes->max('niveau') ?? 0),
                'promesses' => (int) $siennes->where('statut', 'Promesse de règlement')->sum('montant_promis'),
                'encaissements' => $siens->count(),
                'encaisse' => (int) $siens->sum('montant'),
                'tiers_suivis' => $siennes->pluck('tiers')->unique()->count(),
                'dernier_geste' => collect([
                    $siennes->max('date'),
                    $siens->max('date'),
                ])->filter()->max(),
            ];
        })->sortByDesc('encaisse')->values();
    }

    /**
     * Les comptes qui relèvent du recouvrement, pour le filtre « par agent ».
     *
     * Le superviseur en fait partie : il relance et encaisse lui aussi, et l'en exclure
     * ferait disparaître son propre travail du tableau qu'il consulte.
     *
     * @return Collection<int, User>
     */
    public function comptesDuRecouvrement(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                'agent_recouvrement', 'superviseur_recouvrement',
            ]))
            ->orderBy('name')
            ->get();
    }

    /**
     * La forme de la créance : ce qui est dû par tranche d'âge.
     *
     * @return array<int, array{libelle: string, couleur: string, montant: int, part: float}>
     */
    public function parTranche(Collection $lignes): array
    {
        $montants = array_fill(0, count(Recouvrement::TRANCHES), 0);

        /*
         * **Calculé sur les lignes affichées, et non sur tout le portefeuille.** Corrigé le
         * 24/09 : la forme de la créance ignorait les filtres, si bien qu'on cherchait un
         * tiers et que le graphique continuait d'afficher les 795 millions de l'entreprise.
         *
         * Chaque ligne porte déjà sa propre ventilation par tranche d'âge, posée par
         * `Recouvrement::parTiers()` dans la passe qui groupe les créances : il n'y a qu'à
         * les additionner. Repartir des factures ouvertes obligerait à leur réappliquer les
         * filtres, qui portent sur le tiers et non sur la facture.
         */
        foreach ($lignes as $ligne) {
            foreach (($ligne['tranches'] ?? []) as $index => $montant) {
                $montants[$index] = ($montants[$index] ?? 0) + (int) $montant;
            }
        }

        $total = array_sum($montants) ?: 1;

        return collect(Recouvrement::TRANCHES)->map(fn (array $tranche, int $index) => [
            'libelle' => $tranche['libelle'],
            'couleur' => $tranche['couleur'],
            'montant' => $montants[$index],
            'part' => $montants[$index] / $total,
        ])->all();
    }

    /**
     * Ce qui est dû par niveau de relance appelé — la charge de travail, par gravité.
     *
     * @return array<int, array{niveau: int, libelle: string, montant: int, tiers: int}>
     */
    public function parNiveau(Collection $lignes): array
    {
        $parNiveau = [];

        // Les lignes affichées, et non toutes : la charge par niveau annonçait 100 tiers en
        // contentieux alors que le filtre n'en retenait qu'un. Corrigé le 24/09.
        foreach ($lignes as $ligne) {
            $niveau = (int) ($ligne['niveau']['niveau'] ?? 0);
            $parNiveau[$niveau] ??= ['niveau' => $niveau, 'montant' => 0, 'tiers' => 0];
            $parNiveau[$niveau]['montant'] += $ligne['reste'];
            $parNiveau[$niveau]['tiers']++;
        }

        krsort($parNiveau);

        return collect($parNiveau)->map(fn (array $entree) => $entree + [
            'libelle' => RelanceRecouvrement::NIVEAUX[$entree['niveau']] ?? 'Courante',
        ])->values()->all();
    }

    /**
     * Ce qui est rentré mois par mois sur l'exercice, et le nombre de relances en regard.
     *
     * Les deux courbes sur le même graphique répondent à une question qu'on pose souvent
     * et qu'on ne peut jamais étayer : est-ce que relancer fait rentrer l'argent ?
     *
     * @return array<int, array{mois: string, encaisse: int, relances: int}>
     */
    public function parMois(?Closure $retient = null): array
    {
        $debut = ($this->debut ?? $this->arrete->copy()->startOfYear())->copy()->startOfMonth();
        $suite = [];

        for ($curseur = $debut->copy(); $curseur <= $this->arrete; $curseur->addMonth()) {
            $suite[$curseur->format('Y-m')] = [
                'mois' => $curseur->isoFormat('MMM'),
                'encaisse' => 0,
                'relances' => 0,
            ];
        }

        // La date arrive en chaîne ISO : ses sept premiers caractères sont le mois. Un
        // Carbon par règlement pour n'en lire que l'année et le mois coûtait 305 ms.
        foreach ($this->reglementsRetenus($retient) as $encaissement) {
            $clef = substr((string) $encaissement->date, 0, 7);

            if (isset($suite[$clef])) {
                $suite[$clef]['encaisse'] += (int) $encaissement->montant;
            }
        }

        // Les relances sont déjà lues et groupées au constructeur : les relire ici en
        // ferait une seconde requête pour le même contenu. Elles suivent le filtre, comme
        // la courbe des règlements — sans quoi les deux courbes du même graphique
        // parleraient de deux périmètres différents.
        foreach ($this->relancesRetenues($retient) as $relance) {
            $clef = $relance->date->format('Y-m');

            if (isset($suite[$clef])) {
                $suite[$clef]['relances']++;
            }
        }

        return array_values($suite);
    }

    /**
     * Les chiffres de tête, calculés sur les lignes qu'on affiche et pas sur d'autres.
     *
     * `$retient` borne ce qui ne se lit pas sur les lignes — les règlements et les relances,
     * qui vivent du côté des factures. Null veut dire « aucun filtre », et les chiffres
     * restent ceux de l'entreprise.
     */
    public function reperes(Collection $lignes, ?Closure $retient = null): array
    {
        $encaisse = (int) $this->reglementsRetenus($retient)->sum('montant');

        return [
            'encours' => (int) $lignes->sum('reste'),
            'tiers' => $lignes->count(),
            'factures' => (int) $lignes->sum('nombre'),
            'encaisse' => $encaisse,
            'promis' => (int) $lignes->sum('promis'),
            'a_confier' => $lignes->whereNull('responsable_id')->count(),
            'sans_geste' => $lignes->filter(fn (array $l) => ($l['silence'] ?? 0) > self::SILENCE_ALERTE)->count(),
            'contentieux' => (int) $lignes->filter(fn (array $l) => ($l['niveau']['niveau'] ?? 0) >= 5)->sum('reste'),
            // Les relances sont en mémoire depuis le constructeur : une requête de plus
            // pour les compter lirait deux fois la même chose.
            'relances' => $this->relancesRetenues($retient)->count(),
        ];
    }

    /**
     * Tout ce qui concerne un tiers — c'est la page de détail.
     *
     * Les factures se relisent ici pour ce seul tiers, et non dans la collection entière
     * du portefeuille : c'est la seule page qui les affiche ligne à ligne, et il en faut
     * une poignée. La lecture ciblée applique la même règle du payeur que partout ailleurs.
     */
    public function dossier(string $tiers): array
    {
        $factures = Recouvrement::facturesOuvertesDuTiers($tiers)
            ->sortBy(fn (Facture $f) => $f->date)
            ->values();

        $ligne = $this->lignes()->firstWhere('tiers', $tiers);

        return [
            'tiers' => $tiers,
            'ligne' => $ligne,
            'factures' => $factures,
            'relances' => $this->relancesParTiers->get($tiers, collect()),
            'encaissements' => $this->encaissementsDuTiers($tiers),
        ];
    }

    /** Les règlements d'un tiers, du plus récent au plus ancien. */
    public function encaissementsDuTiers(string $tiers): Collection
    {
        $ids = $this->facturesPayeesPar($tiers);

        if ($ids->isEmpty()) {
            return collect();
        }

        return Encaissement::whereIn('facture_id', $ids)
            ->with('facture:id,n_facture')
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * Les règlements de la période, restreints aux tiers que le filtre retient.
     *
     * **Ce que cela répare, relevé par le propriétaire le 24/09.** Chercher « SAAR » sur le
     * tableau de bord ne filtrait que le tableau des tiers : « Reste à recouvrer » passait
     * bien à 860 466 F, mais « Encaissé sur la période » restait à 1 141 472 574 F — le
     * total de l'entreprise, affiché à côté d'un encours filtré. Deux chiffres côte à côte
     * qui ne parlent pas du même périmètre ne se comparent pas, et personne ne peut deviner
     * lequel des deux a bougé.
     *
     * Le rattachement passe par la facture, comme partout ailleurs ici : c'est elle qui sait
     * qui paie. Un règlement dont la facture est inconnue ne se rattache à personne, et
     * sort donc dès qu'un filtre est posé — il ne peut pas être retenu par un nom qu'il n'a
     * pas.
     *
     * `null` veut dire « aucun filtre » : on rend tout, et le chiffre reste celui de
     * l'entreprise.
     *
     * @return Collection<int, object>
     */
    private function reglementsRetenus(?Closure $retient): Collection
    {
        if ($retient === null) {
            return $this->encaissementsDeLaPeriode();
        }

        $tiersParFacture = $this->tiersParFacture();

        return $this->encaissementsDeLaPeriode()->filter(function (object $encaissement) use ($tiersParFacture, $retient) {
            $tiers = $tiersParFacture[$encaissement->facture_id] ?? null;

            return $tiers !== null && $retient($tiers);
        });
    }

    /**
     * Les relances de la période, restreintes aux tiers que le filtre retient.
     *
     * @return Collection<int, RelanceRecouvrement>
     */
    private function relancesRetenues(?Closure $retient): Collection
    {
        $jourArrete = $this->arrete->toDateString();
        $depuis = $this->debut?->toDateString();

        return $this->relancesParTiers->flatten(1)
            ->filter(function (RelanceRecouvrement $relance) use ($retient, $jourArrete, $depuis) {
                if ($relance->date === null) {
                    return false;
                }

                $jour = $relance->date->toDateString();

                if ($jour > $jourArrete || ($depuis !== null && $jour < $depuis)) {
                    return false;
                }

                return $retient === null || $retient((string) $relance->tiers);
            });
    }

    /**
     * Les encaissements de la période regardée, une fois pour toutes.
     *
     * **Lus tels que la base les rend, et non en objets.** Mesuré le 24/09 sur la base de
     * travail : l'exercice entier compte 7 711 règlements, qu'aucun écran n'affiche ligne
     * à ligne — ils ne servent qu'à additionner et à ranger par mois. Les construire en
     * objets Eloquent coûtait **438 ms**, et relire ensuite leur colonne `date` en Carbon
     * **305 ms de plus** dans le seul graphique mensuel. Quatre colonnes suffisent ; on ne
     * rapporte pas la table entière pour faire des sommes. C'est le même raisonnement,
     * et la même mesure, que Recouvrement::lignesDeCreance().
     *
     * La date reste donc une chaîne `Y-m-d H:i:s`. Elle se compare et se tronque telle
     * quelle — l'ordre lexicographique de l'ISO est l'ordre chronologique — et ne devient
     * un Carbon qu'une fois par tiers retenu, dans rapprocherLesEncaissements().
     *
     * Mémorisé sur l'instance, et non en statique : une statique survivrait au changement
     * de période et servirait les chiffres du filtre précédent.
     *
     * Restreints à la ville regardée, comme les factures : sans cela « Encaissé sur la
     * période » gardait le total de l'entreprise à côté d'un encours filtré.
     *
     * @return Collection<int, object>
     */
    private function encaissementsDeLaPeriode(): Collection
    {
        return $this->encaissements ??= Recouvrement::encaissementsDeLaVilleRegardee(Encaissement::query())
            ->when($this->debut, fn ($q) => $q->whereDate('date', '>=', $this->debut))
            ->whereDate('date', '<=', $this->arrete)
            // `cree_par` sert le tableau des agents — c'est lui qui dit qui a encaissé.
            // Oublié de cette liste, il faisait afficher zéro encaissement à tout le monde.
            ->select(['encaissements.id', 'encaissements.facture_id', 'encaissements.montant',
                'encaissements.date', 'encaissements.code_auteur', 'encaissements.cree_par'])
            ->toBase()
            ->get();
    }

    /**
     * Rattacher chaque encaissement à son tiers payant, par sa facture.
     *
     * @return array<string, array{montant: int, date: ?Carbon, auteur: ?string}>
     */
    private function rapprocherLesEncaissements(): array
    {
        $tiersParFacture = $this->tiersParFacture();
        $agrege = [];

        foreach ($this->encaissementsDeLaPeriode() as $encaissement) {
            $tiers = $tiersParFacture[$encaissement->facture_id] ?? null;

            if ($tiers === null) {
                continue;
            }

            $agrege[$tiers] ??= ['montant' => 0, 'date' => null, 'auteur' => null];
            $agrege[$tiers]['montant'] += (int) $encaissement->montant;

            // Les dates sont des chaînes ISO : leur ordre alphabétique *est* leur ordre
            // chronologique, et les comparer ainsi évite sept mille sept cents Carbon pour
            // n'en garder que cent trente.
            if ($encaissement->date !== null && $encaissement->date !== ''
                && ($agrege[$tiers]['date'] === null || $encaissement->date > $agrege[$tiers]['date'])) {
                $agrege[$tiers]['date'] = $encaissement->date;
                $agrege[$tiers]['auteur'] = $encaissement->code_auteur;
            }
        }

        // Un Carbon par tiers retenu, et pas un de plus : la ligne du tableau affiche cette
        // date, et le calcul du silence la compare à l'arrêté.
        foreach ($agrege as $tiers => $entree) {
            if ($entree['date'] !== null) {
                $agrege[$tiers]['date'] = Carbon::parse($entree['date']);
            }
        }

        return $agrege;
    }

    /**
     * Le tiers payant des factures que les règlements de la période concernent.
     *
     * Sans cette correspondance, on rechargerait une facture par encaissement — sept mille
     * requêtes pour un écran de lecture. Mais la construire sur **toutes** les factures
     * était l'excès inverse : onze mille lignes relues pour rapprocher les quelques
     * centaines qu'un mois de règlements touche réellement. On ne charge donc que les
     * factures effectivement citées par les encaissements de la période.
     *
     * @return Collection<int, string>
     */
    private function tiersParFacture(): Collection
    {
        if ($this->tiersParFacture !== null) {
            return $this->tiersParFacture;
        }

        $citees = $this->encaissementsDeLaPeriode()
            ->pluck('facture_id')->filter()->unique()->values();

        return $this->tiersParFacture = $citees->isEmpty()
            ? collect()
            : Facture::query()
                ->whereIn('id', $citees)
                // `depose_chez` en fait partie, et son absence était une erreur : c'est
                // le premier candidat de `tiersPayant()`. Sans la colonne, la règle lisait
                // null et créditait le règlement au courtier — ou au client — d'une facture
                // déposée chez un tiers. Le tableau de bord attribuait donc l'encaissement
                // à quelqu'un d'autre que celui à qui la créance est réclamée, sans qu'une
                // seule erreur ne s'affiche.
                //
                // Lues brutes : sur l'exercice entier, les règlements citent sept mille
                // factures, et en faire des objets coûtait 586 ms auxquelles s'ajoutaient
                // 315 ms de lecture d'attributs. La règle du payeur ne change pas d'un
                // iota — c'est la même Facture::tiersPayantParmi() que partout ailleurs,
                // celle que `tiersPayant()` appelle elle-même.
                ->toBase()
                ->get(['id', 'client', 'assureur', 'courtier', 'depose_chez'])
                ->mapWithKeys(fn (object $f) => [$f->id => Facture::tiersPayantParmi(
                    $f->depose_chez, $f->courtier, $f->assureur, $f->client,
                )]);
    }

    /**
     * Les identifiants des factures dont ce tiers est le payeur.
     *
     * **Deux étapes, et la seconde n'est pas une précaution superflue.** La base dégrossit :
     * elle ne rend que les factures où le nom apparaît dans l'une des trois colonnes, ce qui
     * évite de relire onze mille lignes pour en garder deux cents. Puis `tiersPayant()`
     * tranche en mémoire, sur ce petit lot.
     *
     * Pourquoi ne pas s'arrêter à la requête. D'abord parce que la comparaison de MySQL est
     * insensible à la casse : mesuré sur la base, « NSIA Assurances » et « NSIA ASSURANCES »
     * y sont le même tiers, alors que la balance âgée les compte séparément — le dossier
     * aurait affiché deux cent quarante et une factures là où l'encours en annonce deux cent
     * trente-quatre. Ensuite parce que la priorité déposant → courtier → compagnie → client
     * est écrite à un seul endroit, et qu'une seconde écriture en SQL aurait fini par en
     * diverger.
     *
     * @return Collection<int, int>
     */
    private function facturesPayeesPar(string $tiers): Collection
    {
        return Facture::query()
            ->where(fn ($requete) => $requete
                ->where('depose_chez', $tiers)
                ->orWhere('courtier', $tiers)
                ->orWhere('assureur', $tiers)
                ->orWhere('client', $tiers))
            ->get(['id', 'client', 'assureur', 'courtier', 'depose_chez'])
            ->filter(fn (Facture $f) => $f->tiersPayant() === $tiers)
            ->pluck('id');
    }

    /** Jours écoulés depuis le dernier geste connu sur ce tiers. */
    private function silence(?Carbon $relance, ?Carbon $encaissement, ?Carbon $facture): ?int
    {
        $dernier = collect([$relance, $encaissement, $facture])->filter()->max();

        if ($dernier === null) {
            return null;
        }

        return max(0, NombreDeJours::entre($dernier, $this->arrete));
    }
}

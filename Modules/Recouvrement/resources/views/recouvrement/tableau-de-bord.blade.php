<?php

use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\AccesRecouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use Modules\Recouvrement\Support\PortefeuilleDeRecouvrement;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Tableau de bord du recouvrement — la page d'arrivée du module
|--------------------------------------------------------------------------
| **Ce qui manquait.** Le module savait dire combien on nous doit, et depuis quand.
| Il ne savait pas dire ce qu'on en fait. Un agent qui s'assoit le matin n'a pas
| besoin d'un total, il a besoin de savoir par quel dossier commencer : celui qui
| pèse lourd, qui vieillit, que personne n'a appelé depuis trois semaines. Un
| superviseur, lui, a besoin de voir le travail — qui a relancé qui, ce qui a été
| promis, ce qui est rentré, et ce qui n'est confié à personne.
|
| Tout ce qui s'affiche ici est **mesuré**, jamais supposé. Le responsable d'un
| dossier est celui qui l'a relancé en dernier, parce que c'est un fait daté et
| signé ; il n'existe pas de table d'affectation, et je n'en ai pas inventé une qui
| aurait menti au bout d'un mois. Ce qui n'a jamais été relancé est annoncé pour ce
| qu'il est : à confier.
|
| **Les chiffres sont ceux de la balance âgée**, au même arrêté et par le même
| service. Un tableau de bord qui contredirait la balance ne serait pas un tableau
| de bord, ce serait un doute de plus.
|
| **Le partage de la vue.** Le superviseur et le gérant voient tout le portefeuille
| et peuvent l'examiner agent par agent. L'agent ne voit que le sien, et la réserve
| des dossiers que personne n'a pris — la restriction est appliquée ici, au calcul,
| et non par un menu qu'on contourne en tapant une adresse.
*/

state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');
state(['recherche' => ''])->url(except: '');
state(['niveauFiltre' => ''])->url(except: '');

/*
 * La vue du portefeuille : vide pour tout voir, « libres » pour ce qui n'est confié à
 * personne, ou l'identifiant d'un agent. Elle est relue et corrigée plus bas — un agent
 * qui écrirait l'identifiant d'un collègue dans l'adresse retombe sur le sien.
 */
state(['vue' => ''])->url(except: '');

state(['page' => '1'])->url(except: '1');

/*
 * Une recherche qui se réduit repart de la première page.
 *
 * Sans cela, taper trois lettres depuis la page sept laissait un tableau vide : le
 * résultat n'a plus sept pages, et la septième n'existe plus. Un écran vide après une
 * recherche se lit comme « rien trouvé », alors qu'il y avait des lignes.
 */
$updatedRecherche = function () {
    $this->page = '1';
};

$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

/*
 * Qui lit tout le portefeuille.
 *
 * La règle vit dans AccesRecouvrement, avec les autres : elle sert aussi à la comptabilité
 * et au superviseur de ville, qui consultent sans relancer. Écrite ici, elle leur aurait
 * rendu un tableau de bord vide — ils n'ont jamais relancé personne, et la vue « mes
 * dossiers » n'aurait rien eu à montrer.
 */
$estEncadrant = computed(fn () => AccesRecouvrement::voitToutLePortefeuille(auth()->user()));

$portefeuille = computed(fn () => new PortefeuilleDeRecouvrement($this->arrete, $this->periode->debut));

/**
 * La vue réellement appliquée.
 *
 * Un agent ne consulte pas le portefeuille d'un autre : s'il demande autre chose que la
 * réserve des dossiers libres, on lui rend le sien. La règle est ici et non dans le menu,
 * parce qu'une liste déroulante n'a jamais fermé une adresse.
 */
$vueAppliquee = computed(function () {
    if ($this->estEncadrant) {
        return (string) $this->vue;
    }

    return $this->vue === 'libres' ? 'libres' : (string) auth()->id();
});

$lignes = computed(function () {
    $lignes = $this->portefeuille->lignes();
    $vue = $this->vueAppliquee;

    if ($vue === 'libres') {
        $lignes = $lignes->whereNull('responsable_id');
    } elseif ($vue !== '') {
        $lignes = $lignes->where('responsable_id', (int) $vue);
    }

    if ($this->niveauFiltre !== '') {
        $lignes = $lignes->filter(
            fn (array $l) => (int) ($l['niveau']['niveau'] ?? 0) === (int) $this->niveauFiltre
        );
    }

    $recherche = trim(mb_strtolower($this->recherche));

    if ($recherche !== '') {
        $lignes = $lignes->filter(fn (array $l) => str_contains(mb_strtolower($l['tiers']), $recherche));
    }

    return $lignes->values();
});

$reperes = computed(fn () => $this->portefeuille->reperes($this->lignes));

/** L'activité par agent — l'agent simple n'y lit que la sienne. */
$agents = computed(function () {
    $agents = $this->portefeuille->agents();

    return $this->estEncadrant
        ? $agents
        : $agents->where('id', auth()->id())->values();
});

$tranches = computed(fn () => $this->portefeuille->parTranche());
$niveaux = computed(fn () => $this->portefeuille->parNiveau());
$mois = computed(fn () => $this->portefeuille->parMois());

/*
 * Pagination à la main : les lignes sont une collection calculée, pas une requête. Le
 * numéro de page voyage dans l'adresse, et les liens sont des liens — la page se tourne
 * avec ou sans script, et une page de résultats se transmet telle quelle.
 */
$pages = computed(fn () => max(1, (int) ceil(
    $this->lignes->count() / PortefeuilleDeRecouvrement::PAR_PAGE
)));

$pageCourante = computed(fn () => min(max(1, (int) $this->page), $this->pages));

$lignesDeLaPage = computed(fn () => $this->lignes
    ->slice(($this->pageCourante - 1) * PortefeuilleDeRecouvrement::PAR_PAGE,
        PortefeuilleDeRecouvrement::PAR_PAGE)
    ->values());

/**
 * Les numéros de page à montrer autour de celui qu'on lit.
 *
 * Deux flèches « précédente / suivante » obligent à cliquer onze fois pour aller à la
 * page douze, et surtout elles ne disent pas où l'on est. On montre donc une fenêtre de
 * numéros, avec le premier et le dernier toujours accessibles.
 */
$numeros = computed(function () {
    $total = $this->pages;
    $courante = $this->pageCourante;

    if ($total <= 7) {
        return range(1, $total);
    }

    $debut = max(1, min($courante - 2, $total - 4));
    $fin = min($total, max($courante + 2, 5));

    $suite = range($debut, $fin);

    if ($debut > 1) {
        array_unshift($suite, 1);
    }

    if ($fin < $total) {
        $suite[] = $total;
    }

    return $suite;
});

/** L'adresse d'une page, tous les filtres conservés. */
$lien = function (array $changements = []): string {
    $parametres = array_filter([
        'moisFiltre' => $this->moisFiltre,
        'semaineFiltre' => $this->semaineFiltre,
        'jourFiltre' => $this->jourFiltre,
        'recherche' => $this->recherche,
        'niveauFiltre' => $this->niveauFiltre,
        'vue' => $this->vue,
    ], fn ($valeur) => $valeur !== '' && $valeur !== null);

    return route('recouvrement.tableau-de-bord', array_merge($parametres, $changements));
};

?>

<x-recouvrement::pleine-page
    titre="Tableau de bord du recouvrement"
    :sous-titre="\Modules\Recouvrement\Support\AccesRecouvrement::SOUS_TITRE_TABLEAU"
    :retour="route('recouvrement.saisie')"
    retour-libelle="Ouvrir le module →">

    <x-slot:actions>
        <x-telecharger route="recouvrement.telecharger"
            :parametres="['document' => 'portefeuille', 'arrete' => $this->periode->arreteIso()]" />
    </x-slot:actions>

    {{-- Les filtres tiennent une bande pleine largeur, sur une ligne ou deux. Repliés dans
         le coin de la coquille, ils s'empilaient sur quatre rangs. --}}
    <x-slot:filtres>
        <x-recouvrement::periode route="recouvrement.tableau-de-bord" :periode="$this->periode"
            :recherche="$recherche" placeholder="Filtrer un tiers…">

            {{-- Le portefeuille regardé. Pour l'encadrement, c'est le filtre « par agent »
                 demandé : chaque compte du recouvrement y figure, superviseur compris — il
                 relance lui aussi, et l'en exclure ferait disparaître son propre travail. --}}
            <div>
                <label for="p-vue" style="display:block; font-size:10.5px; text-transform:uppercase;
                       letter-spacing:.7px; color:#5A6472; font-weight:700; margin-bottom:3px;">
                    Portefeuille
                </label>
                <select id="p-vue" name="vue"
                        style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px;
                               background:var(--th-champ,#FFFBEA); font-family:inherit;">
                    @if ($this->estEncadrant)
                        <option value="" @selected($vue === '')>Tout le portefeuille</option>
                    @else
                        <option value="" @selected($vue !== 'libres')>Mes dossiers</option>
                    @endif

                    <option value="libres" @selected($vue === 'libres')>À confier (personne dessus)</option>

                    @if ($this->estEncadrant)
                        @foreach ($this->portefeuille->comptesDuRecouvrement() as $compte)
                            <option value="{{ $compte->id }}" @selected((string) $vue === (string) $compte->id)>
                                {{ $compte->name }}
                            </option>
                        @endforeach
                    @endif
                </select>
            </div>

            <div>
                <label for="p-niveau" style="display:block; font-size:10.5px; text-transform:uppercase;
                       letter-spacing:.7px; color:#5A6472; font-weight:700; margin-bottom:3px;">
                    Niveau
                </label>
                <select id="p-niveau" name="niveauFiltre"
                        style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px;
                               background:var(--th-champ,#FFFBEA); font-family:inherit;">
                    <option value="" @selected($niveauFiltre === '')>Tous les niveaux</option>
                    @foreach (RelanceRecouvrement::NIVEAUX as $numero => $libelle)
                        <option value="{{ $numero }}" @selected((string) $niveauFiltre === (string) $numero)>
                            {{ $libelle }}
                        </option>
                    @endforeach
                </select>
            </div>
        </x-recouvrement::periode>
    </x-slot:filtres>

    @php $r = $this->reperes; @endphp

    {{-- ───────────────────────────── les chiffres de tête ───────────────────────────── --}}
    <div class="rec-kpis">
        <div class="rec-kpi rouge">
            <div class="lab">Reste à recouvrer</div>
            <div class="val">{{ Recouvrement::fr($r['encours']) }}</div>
            <div class="sub">{{ $r['factures'] }} facture(s) · {{ $r['tiers'] }} tiers</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Encaissé sur la période</div>
            <div class="val">{{ Recouvrement::fr($r['encaisse']) }}</div>
            <div class="sub">{{ $this->periode->enClair() }}</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">Promesses en cours</div>
            <div class="val">{{ Recouvrement::fr($r['promis']) }}</div>
            <div class="sub">engagements pris, pas encore rentrés</div>
        </div>
        <div class="rec-kpi rouge">
            <div class="lab">Contentieux (N5)</div>
            <div class="val">{{ Recouvrement::fr($r['contentieux']) }}</div>
            <div class="sub">créances de plus de 90 jours</div>
        </div>
        <div class="rec-kpi">
            <div class="lab">À confier</div>
            <div class="val">{{ $r['a_confier'] }}</div>
            <div class="sub">tiers que personne n'a relancés</div>
        </div>
        <div class="rec-kpi {{ $r['sans_geste'] > 0 ? 'rouge' : '' }}">
            <div class="lab">Sans geste depuis {{ PortefeuilleDeRecouvrement::SILENCE_ALERTE }} j</div>
            <div class="val">{{ $r['sans_geste'] }}</div>
            <div class="sub">ni relance ni règlement</div>
        </div>
    </div>

    {{-- ───────────────────────────── la forme de la créance ───────────────────────────── --}}
    <div class="rec-g2" style="margin-bottom:15px;">
        <div class="rec-carte">
            <h2>Forme de la créance <span class="chip">par ancienneté</span></h2>

            {{-- Une barre unique plutôt que cinq : ce qui se lit ici, c'est la part que
                 prend chaque tranche dans le tout. Cinq barres séparées obligeraient à
                 comparer des hauteurs ; une seule se lit d'un regard. --}}
            <div class="rec-bar">
                @foreach ($this->tranches as $tranche)
                    @if ($tranche['part'] > 0)
                        <div style="width:{{ round($tranche['part'] * 100, 2) }}%; background:{{ $tranche['couleur'] }};"
                             title="{{ $tranche['libelle'] }} — {{ Recouvrement::fr($tranche['montant']) }}"></div>
                    @endif
                @endforeach
            </div>

            <table class="rec-tbl" style="margin-top:12px;">
                <thead>
                    <tr><th>Tranche</th><th class="num">Montant</th><th class="num">Part</th></tr>
                </thead>
                <tbody>
                    @foreach ($this->tranches as $tranche)
                        <tr>
                            <td>
                                <span class="rec-sw" style="background:{{ $tranche['couleur'] }};"></span>
                                {{ $tranche['libelle'] }}
                            </td>
                            <td class="num">{{ number_format($tranche['montant'], 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format($tranche['part'] * 100, 1, ',', ' ') }} %</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="rec-hint">
                Deux cents millions à trente jours se recouvrent par téléphone&nbsp;; la même
                somme à deux cents jours se prépare avec un huissier. C'est cette répartition
                qui commande l'action, pas le total.
            </div>
        </div>

        <div class="rec-carte">
            <h2>Charge par niveau <span class="chip">{{ $this->lignes->count() }} tiers</span></h2>

            @php $plafond = max(1, collect($this->niveaux)->max('montant')); @endphp

            @foreach ($this->niveaux as $entree)
                <div style="margin-bottom:10px;">
                    <div style="display:flex; justify-content:space-between; font-size:12.5px; margin-bottom:3px;">
                        <span><span class="pill pN{{ $entree['niveau'] }}">{{ $entree['libelle'] }}</span></span>
                        <span class="rec-chiffre">{{ number_format($entree['montant'], 0, ',', ' ') }}
                            <span style="color:#6B6E76; font-weight:400;">· {{ $entree['tiers'] }} tiers</span></span>
                    </div>
                    <div style="height:9px; background:#EFEDE6; border-radius:5px; overflow:hidden;">
                        <div style="height:100%; width:{{ round($entree['montant'] / $plafond * 100, 2) }}%;
                                    background:{{ $entree['niveau'] >= 4 ? '#C8102E' : ($entree['niveau'] >= 2 ? '#D9541E' : '#5A6472') }};"></div>
                    </div>
                </div>
            @endforeach

            <div class="rec-hint">
                Le niveau retenu pour un tiers est celui de sa facture <strong>la plus
                ancienne</strong>, et non une moyenne&nbsp;: on ne relance pas un compte au
                niveau moyen de ses pièces.
            </div>
        </div>
    </div>

    {{-- ───────────────────── ce qui rentre, mois par mois ───────────────────── --}}
    <div class="rec-carte" style="margin-bottom:15px;">
        <h2>Encaissements et relances, mois par mois <span class="chip">{{ $this->periode->annee }}</span></h2>

        @php
            $serie = $this->mois;
            $hauteur = 150;
            $plafond = max(1, collect($serie)->max('encaisse'));
            $largeur = max(1, count($serie));
        @endphp

        {{-- Un graphique dessiné à la main, en SVG : pas de bibliothèque à charger, pas de
             script à exécuter, et il s'imprime. Les barres disent ce qui est rentré ; les
             points, le nombre de relances tracées le même mois. Les deux ensemble
             répondent à une question qu'on pose souvent sans pouvoir l'étayer : est-ce que
             relancer fait rentrer l'argent ? --}}
        <div style="overflow-x:auto;">
            <svg viewBox="0 0 {{ $largeur * 60 }} {{ $hauteur + 34 }}" role="img"
                 style="width:100%; min-width:{{ $largeur * 46 }}px; height:{{ $hauteur + 40 }}px;"
                 aria-label="Encaissements mensuels de l'exercice">
                @foreach ($serie as $index => $point)
                    @php
                        $h = (int) round($point['encaisse'] / $plafond * $hauteur);
                        $x = $index * 60 + 8;
                    @endphp
                    <rect x="{{ $x }}" y="{{ $hauteur - $h }}" width="44" height="{{ max(1, $h) }}"
                          rx="3" fill="{{ $point['encaisse'] > 0 ? '#191B20' : '#E3E0D8' }}"></rect>

                    @if ($point['relances'] > 0)
                        <circle cx="{{ $x + 22 }}" cy="{{ $hauteur - $h - 8 }}" r="5" fill="#C8102E"></circle>
                        <text x="{{ $x + 22 }}" y="{{ $hauteur - $h - 5 }}" text-anchor="middle"
                              font-size="8" fill="#fff" font-weight="700">{{ $point['relances'] }}</text>
                    @endif

                    <text x="{{ $x + 22 }}" y="{{ $hauteur + 14 }}" text-anchor="middle"
                          font-size="11" fill="#5A6472">{{ $point['mois'] }}</text>
                    <text x="{{ $x + 22 }}" y="{{ $hauteur + 28 }}" text-anchor="middle"
                          font-size="10" fill="#191B20" font-weight="700">
                        {{ $point['encaisse'] > 0 ? number_format($point['encaisse'] / 1000000, 1, ',', ' ').' M' : '·' }}
                    </text>
                @endforeach
            </svg>
        </div>

        <div class="rec-leg">
            <span><span class="rec-sw" style="background:#191B20;"></span>Encaissé dans le mois (millions de F)</span>
            <span><span class="rec-sw" style="background:#C8102E;"></span>Relances tracées dans le mois</span>
        </div>

        @if ($r['relances'] === 0)
            <div class="rec-hint warn">
                Aucune relance n'est tracée à ce jour. Les points rouges resteront absents
                tant que le journal des relances ne sera pas alimenté depuis l'écran de saisie.
            </div>
        @endif
    </div>

    {{-- ───────────────────────── qui fait quoi ───────────────────────── --}}
    <div class="rec-carte" style="margin-bottom:15px;">
        <h2>
            {{ $this->estEncadrant ? 'Activité par agent' : 'Mon activité' }}
            <span class="chip">{{ $this->periode->enClair() }}</span>
        </h2>

        <div class="rec-tbl-wrap" style="max-height:none;">
            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Fonction</th>
                        <th class="num">Relances</th>
                        <th class="num">Tiers suivis</th>
                        <th class="num">Niveau le plus haut</th>
                        <th class="num">Promesses obtenues</th>
                        <th class="num" title="Encaissements enregistrés à la main dans le module. Ceux repris d'un import n'ont pas d'auteur.">Règlements saisis</th>
                        <th class="num">Montant encaissé</th>
                        <th>Dernier geste</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->agents as $agent)
                        <tr>
                            <td><b>{{ $agent['nom'] }}</b></td>
                            <td>{{ $agent['role'] }}</td>
                            <td class="num">{{ $agent['relances'] }}</td>
                            <td class="num">{{ $agent['tiers_suivis'] }}</td>
                            <td class="num">
                                {{ $agent['niveau_max'] > 0 ? 'N'.$agent['niveau_max'] : '·' }}
                            </td>
                            <td class="num">{{ $agent['promesses'] ? number_format($agent['promesses'], 0, ',', ' ') : '·' }}</td>
                            <td class="num">{{ $agent['encaissements'] }}</td>
                            <td class="num">{{ $agent['encaisse'] ? number_format($agent['encaisse'], 0, ',', ' ') : '·' }}</td>
                            <td>{{ $agent['dernier_geste']?->format('d/m/Y') ?? 'aucun' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" style="text-align:center; color:#6B6E76;">Aucun compte de recouvrement.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ───────────────────────── le portefeuille, ligne à ligne ───────────────────────── --}}
    <div class="rec-carte">
        <h2>
            Portefeuille de créances
            <span class="chip">
                {{ $this->lignes->count() }} tiers · page {{ $this->pageCourante }} / {{ $this->pages }}
            </span>
        </h2>

        <div class="rec-tbl-wrap" style="max-height:none;">
            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Tiers</th>
                        <th class="num">Reste à payer</th>
                        <th class="num">Factures</th>
                        <th class="num">Doit depuis</th>
                        <th>Niveau</th>
                        <th>Qui s'en charge</th>
                        <th>Dernière relance</th>
                        <th class="num">Promis</th>
                        <th class="num">Encaissé</th>
                        <th class="num">Silence</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignesDeLaPage as $ligne)
                        <tr>
                            <td><b>{{ $ligne['tiers'] }}</b></td>
                            <td class="num">{{ number_format($ligne['reste'], 0, ',', ' ') }}</td>
                            <td class="num">{{ $ligne['nombre'] }}</td>
                            <td class="num">
                                @if ($ligne['depuis'] !== null)
                                    {{ $ligne['depuis'] }} j
                                    <div style="font-size:10.5px; color:#6B6E76; font-weight:400;">
                                        {{ $ligne['plus_ancienne']?->format('d/m/Y') }}
                                    </div>
                                @else
                                    ·
                                @endif
                            </td>
                            <td><span class="pill {{ $ligne['niveau']['classe'] }}">{{ $ligne['niveau']['libelle'] }}</span></td>
                            <td>
                                @if ($ligne['responsable'])
                                    {{ $ligne['responsable'] }}
                                    @if ($ligne['statut'])
                                        <div style="font-size:10.5px; color:#6B6E76;">{{ $ligne['statut'] }}</div>
                                    @endif
                                @else
                                    <span style="color:#C8102E; font-weight:700;">À confier</span>
                                @endif
                            </td>
                            <td>
                                @if ($ligne['derniere_relance'])
                                    {{ $ligne['derniere_relance']->format('d/m/Y') }}
                                    <div style="font-size:10.5px; color:#6B6E76;">
                                        N{{ $ligne['dernier_niveau'] }} · {{ $ligne['relances'] }} au total
                                    </div>
                                @else
                                    <span style="color:#6B6E76;">jamais</span>
                                @endif
                            </td>
                            <td class="num">{{ $ligne['promis'] ? number_format($ligne['promis'], 0, ',', ' ') : '·' }}</td>
                            <td class="num">
                                {{ $ligne['encaisse'] ? number_format($ligne['encaisse'], 0, ',', ' ') : '·' }}
                                @if ($ligne['dernier_encaissement'])
                                    <div style="font-size:10.5px; color:#6B6E76; font-weight:400;">
                                        {{ $ligne['dernier_encaissement']->format('d/m/Y') }}
                                    </div>
                                @endif
                            </td>
                            <td class="num" @if (($ligne['silence'] ?? 0) > \Modules\Recouvrement\Support\PortefeuilleDeRecouvrement::SILENCE_ALERTE) style="color:#C8102E;" @endif>
                                {{ $ligne['silence'] !== null ? $ligne['silence'].' j' : '·' }}
                            </td>
                            <td class="no-print">
                                <a href="{{ route('recouvrement.dossier', ['tiers' => $ligne['tiers']]) }}"
                                   class="rec-btn o" style="text-decoration:none; padding:4px 10px; font-size:11px; white-space:nowrap;">
                                    Détail
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" style="text-align:center; color:#6B6E76; padding:20px;">
                                Aucun tiers ne correspond à ce filtre.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($this->lignes->isNotEmpty())
                    <tfoot>
                        <tr class="tot">
                            <td>TOTAL — {{ $this->lignes->count() }} tiers</td>
                            <td class="num">{{ number_format($this->lignes->sum('reste'), 0, ',', ' ') }}</td>
                            <td class="num">{{ $this->lignes->sum('nombre') }}</td>
                            <td colspan="4"></td>
                            <td class="num">{{ number_format($this->lignes->sum('promis'), 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format($this->lignes->sum('encaisse'), 0, ',', ' ') }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        {{-- Les pages se tournent par des liens, et `wire:navigate` les fait se tourner
             **sans recharger la page** : le contenu est échangé sur place, la position
             dans la page est conservée, et le bandeau ne clignote plus. Ce sont malgré
             tout de vrais liens — ils s'ouvrent dans un autre onglet, se mettent en
             favori, et fonctionnent quand le script ne démarre pas.

             Ils étaient réduits à deux flèches : pour atteindre la page douze il fallait
             cliquer onze fois, sans jamais savoir où l'on en était. --}}
        @if ($this->pages > 1)
            @php
                $bouton = 'text-decoration:none; display:inline-flex; align-items:center; justify-content:center;'
                    .' min-width:34px; height:32px; padding:0 9px; border-radius:7px; font-size:13px;'
                    .' font-weight:700; border:1.5px solid #191B20; color:#191B20; background:#fff;';
                $actif = $bouton.' background:#191B20; color:#fff;';
                $mort = 'min-width:34px; height:32px; display:inline-flex; align-items:center;'
                    .' justify-content:center; color:#9A9DA5; font-size:13px;';
            @endphp

            <div class="no-print" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;
                                         margin-top:13px; padding-top:12px; border-top:1px solid #EFEDE6;">

                @if ($this->pageCourante > 1)
                    <a href="{{ $this->lien(['page' => $this->pageCourante - 1]) }}" wire:navigate
                       style="{{ $bouton }}" rel="prev" aria-label="Page précédente">←</a>
                @else
                    <span style="{{ $mort }}">←</span>
                @endif

                @php $precedent = 0; @endphp
                @foreach ($this->numeros as $numero)
                    {{-- Le saut est marqué : sans lui, « 1 2 12 13 » laisse croire à une
                         suite continue et l'on cherche les pages manquantes. --}}
                    @if ($numero > $precedent + 1)
                        <span style="{{ $mort }}">…</span>
                    @endif

                    <a href="{{ $this->lien(['page' => $numero]) }}" wire:navigate
                       style="{{ $numero === $this->pageCourante ? $actif : $bouton }}"
                       @if ($numero === $this->pageCourante) aria-current="page" @endif>
                        {{ $numero }}
                    </a>

                    @php $precedent = $numero; @endphp
                @endforeach

                @if ($this->pageCourante < $this->pages)
                    <a href="{{ $this->lien(['page' => $this->pageCourante + 1]) }}" wire:navigate
                       style="{{ $bouton }}" rel="next" aria-label="Page suivante">→</a>
                @else
                    <span style="{{ $mort }}">→</span>
                @endif

                {{-- Le rang des lignes affichées, et non le seul numéro de page : c'est ce
                     qu'on cherche quand on veut savoir s'il reste beaucoup à parcourir. --}}
                <span style="font-size:12.5px; color:#6B6E76; margin-left:8px;">
                    Tiers
                    {{ number_format(($this->pageCourante - 1) * \Modules\Recouvrement\Support\PortefeuilleDeRecouvrement::PAR_PAGE + 1, 0, ',', ' ') }}
                    à
                    {{ number_format(min($this->pageCourante * \Modules\Recouvrement\Support\PortefeuilleDeRecouvrement::PAR_PAGE, $this->lignes->count()), 0, ',', ' ') }}
                    sur {{ number_format($this->lignes->count(), 0, ',', ' ') }}
                </span>
            </div>
        @endif

        <div class="rec-hint">
            <strong>« Qui s'en charge » n'est pas une affectation, c'est un constat</strong>&nbsp;:
            c'est la personne qui a tracé la dernière relance sur ce tiers. Un dossier marqué
            <strong>À confier</strong> n'a jamais été relancé par personne — c'est la liste par
            laquelle commencer. La colonne <strong>Silence</strong> compte les jours écoulés
            depuis le dernier geste connu, relance ou règlement.
        </div>
    </div>
</x-recouvrement::pleine-page>

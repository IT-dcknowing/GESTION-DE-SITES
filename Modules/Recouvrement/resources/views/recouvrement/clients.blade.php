<?php

use Modules\Noyau\Exploitation\Modeles\Tiers;
use Modules\Noyau\Exploitation\Services\CodeDuTiers;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
use Modules\Recouvrement\Support\AccesRecouvrement;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Clients & tiers — l'annuaire complet
|--------------------------------------------------------------------------
| Tous les tiers que la base connaît, d'où qu'ils viennent : les clients facturés,
| les compagnies d'assurance, les courtiers, et ceux qui ont été déclarés au
| référentiel sans avoir encore reçu la moindre facture.
|
| Les taire aurait une conséquence très concrète : quelqu'un qui ne trouve pas un
| tiers dans une liste le recrée sous une orthographe voisine, et l'encours de ce
| tiers se coupe en deux — moitié sous « NSIA ASSURANCES », moitié sous « Nsia
| Assurance ». On relance alors deux fois la moitié de la dette.
|
| Une colonne demande une attention particulière : le **rôle**. Un tiers peut en tenir
| plusieurs — une compagnie travaille en direct sur certains dossiers et par courtier
| sur d'autres. Et l'encours affiché est celui qu'il doit **en tant que payeur** : une
| compagnie dont tous les dossiers passent par un courtier figure bien ici, à zéro,
| parce que ce n'est pas elle qui règle.
|
| Rien ne s'écrit sur cet écran. La création d'un tiers reste sur la page Saisie, et
| relève du superviseur ou du gérant.
*/

/*
 * La période remplace la date libre. Trois appels séparés à `state()` et non un seul :
 * `->url(except: ...)` attend une chaîne, pas un tableau — un seul appel pour trois
 * propriétés ne compile pas.
 */
state(['moisFiltre' => ''])->url(except: '');
state(['semaineFiltre' => ''])->url(except: '');
state(['jourFiltre' => ''])->url(except: '');

// Liés à l'adresse : les filtres agissent avec ou sans script, et la vue se transmet.
state(['recherche' => ''])->url(except: '');
state(['role' => ''])->url(except: '');
state(['page' => 1])->url(except: '1');

/** Les rôles proposés au filtre, dans l'ordre où la créance leur revient. */
$rolesPossibles = computed(fn () => ['Courtier', 'Assurance', 'Client']);

/** Vingt-cinq lignes par page : de quoi balayer un rôle entier sans tourner dix fois. */
$parPage = computed(fn () => 25);

/** La période regardée, et l'arrêté qu'elle commande — voir PeriodeDeTravail. */
$periode = computed(fn () => PeriodeDeTravail::depuis($this->moisFiltre, $this->semaineFiltre, $this->jourFiltre));

$arrete = computed(fn () => Recouvrement::arrete($this->periode->arreteIso()));

$annuaire = computed(fn () => Recouvrement::annuaireDesTiers($this->arrete));

/**
 * Les codes déjà attribués, par nom normalisé — une requête pour toute la page.
 *
 * **Pourquoi un code, demandé le 28/09.** Un nom ne peut pas à la fois empêcher le doublon
 * d'orthographe et permettre l'homonyme volontaire : « NSIA ASSURANCES » et « Nsia
 * Assurance » doivent se confondre, deux sociétés réellement homonymes doivent se
 * distinguer. Le code fait la seconde moitié.
 *
 * Il n'est pas posé d'office sur les 2 445 tiers connus : ce serait écrire des données que
 * personne n'a demandées. Il s'attribue par un geste, ligne par ligne ou en une fois, et
 * ce geste se trace.
 */
$codes = computed(fn () => CodeDuTiers::parNom((int) auth()->user()->entreprise_id));

$sansCode = computed(fn () => collect($this->annuaire)
    ->reject(fn (array $ligne) => isset($this->codes[Tiers::normaliser($ligne['tiers'])]))
    ->count());

/** Peut-on attribuer un code ? Même règle que la création d'un tiers. */
$peutCoder = computed(fn () => AccesRecouvrement::peutCreerUnTiers(auth()->user()));

/**
 * Attribuer son code à un tiers, un à la fois.
 *
 * Le nom vient de l'annuaire et non du navigateur : un nom reçu tel quel permettrait de
 * coder n'importe quelle chaîne, et la liste se remplirait de tiers qui n'existent pas.
 */
$coder = function (string $tiers) {
    if (! $this->peutCoder) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: "L'attribution d'un code relève du superviseur ou du gérant.");

        return;
    }

    $connu = collect($this->annuaire)->firstWhere('tiers', $tiers);

    if ($connu === null) {
        return;
    }

    $pose = CodeDuTiers::attribuer(
        (int) auth()->user()->entreprise_id,
        $tiers,
        $connu['roles'][0] ?? null,
        auth()->id(),
    );

    unset($this->codes, $this->sansCode);

    $this->dispatch('annonce', ton: 'succes',
        texte: '« '.$tiers.' » porte désormais le code '.$pose->code.'.');
};

/**
 * Attribuer les codes manquants, en une fois.
 *
 * **Un geste, pas un effet de bord.** On aurait pu poser les codes à la migration, ou au
 * premier affichage de la page : les deux auraient écrit des milliers de lignes sans que
 * personne ne l'ait décidé, et sur une base qui porte de vraies données. Ici, c'est un
 * bouton, il dit combien il va écrire, et l'écriture se trace au journal.
 */
$coderTout = function () {
    if (! $this->peutCoder) {
        $this->dispatch('annonce', ton: 'alerte',
            texte: "L'attribution des codes relève du superviseur ou du gérant.");

        return;
    }

    $entrepriseId = (int) auth()->user()->entreprise_id;
    $poses = 0;

    foreach ($this->annuaire as $ligne) {
        if (isset($this->codes[Tiers::normaliser($ligne['tiers'])])) {
            continue;
        }

        CodeDuTiers::attribuer($entrepriseId, $ligne['tiers'], $ligne['roles'][0] ?? null, auth()->id());
        $poses++;
    }

    activity()->causedBy(auth()->user())
        ->withProperties(['codes_poses' => $poses])
        ->log('Recouvrement — codes de tiers attribués');

    unset($this->codes, $this->sansCode);

    $this->dispatch('annonce', ton: 'succes',
        texte: $poses.' code(s) attribué(s). Un tiers garde son code pour toujours.');
};

$lignes = computed(function () {
    $recherche = trim(mb_strtolower($this->recherche));

    // Le filtre est ramené aux rôles connus : il ne compose aucune requête, mais une
    // valeur forgée viderait le tableau sous un intitulé qui annonce autre chose.
    $role = in_array($this->role, $this->rolesPossibles, true) ? $this->role : '';

    return $this->annuaire
        ->when($recherche !== '', fn ($lignes) => $lignes
            ->filter(fn (array $l) => str_contains(mb_strtolower($l['tiers']), $recherche)))
        ->when($role !== '', fn ($lignes) => $lignes
            ->filter(fn (array $l) => in_array($role, $l['roles'], true)))
        ->values();
});

/**
 * Le numéro de page, ramené dans les bornes.
 *
 * `page` vient de l'écran et se réécrit à la main. Un nombre négatif donnerait un
 * décalage négatif à `slice()`, qui repart alors de la fin de la liste — le tableau
 * afficherait les derniers tiers sous le titre « page 1 ».
 */
$pageCourante = computed(fn () => min(
    max(1, (int) $this->page),
    max(1, (int) ceil($this->lignes->count() / $this->parPage)),
));

$affichees = computed(fn () => $this->lignes->slice(($this->pageCourante - 1) * $this->parPage, $this->parPage));

$totaux = computed(fn () => [
    'tiers' => $this->lignes->count(),
    'reste' => $this->lignes->sum('reste'),
    'ouvertes' => $this->lignes->sum('ouvertes'),
]);

// Une recherche qui laisse la pagination sur la page 7 affiche un tableau vide : on
// revient au début dès que le filtre change.
$updatedRecherche = function () { $this->page = 1; };
$updatedRole = function () { $this->page = 1; };

?>

<x-recouvrement::coquille page="clients">
    <x-slot:actions>
        <x-recouvrement::periode route="recouvrement.clients" :periode="$this->periode" />

        {{-- Le fichier emporté contient exactement ce que l'écran montre :
             mêmes filtres, même arrêté, même ligne de totaux. --}}
        <x-telecharger route="recouvrement.telecharger"
            :parametres="['document' => 'clients', 'arrete' => $this->periode->arreteIso()]" />
    </x-slot:actions>

    <div class="rec-kpis">
        <div class="rec-kpi">
            <div class="lab">Tiers connus</div>
            <div class="val">{{ $this->totaux['tiers'] }}</div>
            <div class="sub">clients, assurances et courtiers réunis</div>
        </div>
        <div class="rec-kpi rouge">
            <div class="lab">Reste à payer</div>
            <div class="val">{{ number_format($this->totaux['reste'], 0, ',', ' ') }}</div>
            <div class="sub">F CFA · {{ $this->totaux['ouvertes'] }} factures ouvertes</div>
        </div>
    </div>

    <div class="rec-carte">
        <h2>
            Clients & tiers
            <span class="chip">Annuaire — aucune écriture</span>
        </h2>

        <div class="rec-frm" style="grid-template-columns:2fr 1fr; margin-bottom:13px;">
            <div class="rec-fld">
                <label>Rechercher un tiers</label>
                <input type="text" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}" placeholder="Nom du client, de l'assurance ou du courtier">
            </div>
            <div class="rec-fld">
                <label>Rôle</label>
                <select wire:model.live="role">
                    <option value="" @selected($role === '')>— Tous les rôles —</option>
                    @foreach ($this->rolesPossibles as $nom)
                        <option value="{{ $nom }}" @selected((string) $role === (string) $nom)>{{ $nom }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($this->sansCode > 0 && $this->peutCoder)
            {{-- **Le geste est proposé, jamais fait d'office.** Poser 2 445 codes à la
                 migration ou au premier affichage aurait écrit des milliers de lignes sans
                 que personne ne l'ait décidé, sur une base qui porte de vraies données.
                 Ici c'est un bouton, il dit combien il va écrire, et l'écriture se trace. --}}
            <div class="rec-hint" style="margin-bottom:10px;">
                <strong>{{ number_format($this->sansCode, 0, ',', ' ') }} tiers n'ont pas encore de code.</strong>
                Le code distingue deux tiers réellement homonymes, là où le nom ne le peut pas.
                <button type="button" wire:click="coderTout" class="rec-btn n"
                    style="margin-left:8px; padding:3px 10px; font-size:12px;">
                    Attribuer les codes manquants
                </button>
            </div>
        @endif

        <div class="rec-tbl-wrap" style="max-height:none;">
            <table class="rec-tbl">
                <thead>
                    <tr>
                        {{-- Le code, demandé le 28/09 : c'est lui qui distingue deux tiers
                             réellement homonymes, là où le nom ne le peut pas. --}}
                        <th>Code</th>
                        <th>Tiers</th>
                        <th>Rôle</th>
                        <th class="num">Factures</th>
                        <th class="num">Facturé</th>
                        <th class="num">Réglé</th>
                        <th class="num">Reste à payer</th>
                        <th class="num">Ouvertes</th>
                        <th>Niveau</th>
                        <th>Dernière facture</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->affichees as $ligne)
                        @php $code = $this->codes[Tiers::normaliser($ligne['tiers'])] ?? null; @endphp
                        <tr>
                            <td style="white-space:nowrap; font-family:ui-monospace,Consolas,monospace; font-size:12.5px;">
                                @if ($code)
                                    {{ $code }}
                                @elseif ($this->peutCoder)
                                    {{-- Le geste est là où manque le code, et il ne demande
                                         pas de quitter la page. --}}
                                    <button type="button" wire:click="coder('{{ addslashes($ligne['tiers']) }}')"
                                        class="rec-btn" style="padding:2px 8px; font-size:11px;"
                                        title="Attribuer un code à ce tiers">coder</button>
                                @else
                                    <span style="color:#9A9DA5;">—</span>
                                @endif
                            </td>
                            <td><b>{{ $ligne['tiers'] }}</b></td>
                            <td>
                                @forelse ($ligne['roles'] as $chapeau)
                                    <span class="pill {{ $chapeau === 'Courtier' ? 'pN2' : ($chapeau === 'Assurance' ? 'pN1' : 'pN0') }}">{{ $chapeau }}</span>
                                @empty
                                    {{-- Déclaré au référentiel, jamais facturé : il attend son premier dossier. --}}
                                    <span class="pill pN0">Déclaré</span>
                                @endforelse
                            </td>
                            <td class="num">{{ $ligne['factures'] ?: '·' }}</td>
                            <td class="num">{{ $ligne['facture'] ? number_format($ligne['facture'], 0, ',', ' ') : '·' }}</td>
                            <td class="num">{{ $ligne['regle'] ? number_format($ligne['regle'], 0, ',', ' ') : '·' }}</td>
                            <td class="num"><b>{{ $ligne['reste'] ? number_format($ligne['reste'], 0, ',', ' ') : '·' }}</b></td>
                            <td class="num">{{ $ligne['ouvertes'] ?: '·' }}</td>
                            <td>
                                @if ($ligne['ouvertes'] > 0)
                                    <span class="pill {{ $ligne['niveau']['classe'] }}">{{ $ligne['niveau']['libelle'] }}</span>
                                @else
                                    <span style="color:#6B6E76;">—</span>
                                @endif
                            </td>
                            <td>{{ $ligne['derniere']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="no-print">
                                <a href="{{ route('recouvrement.extrait', ['tiers' => $ligne['tiers']]) }}"
                                    wire:navigate class="rec-btn o"
                                    style="padding:3px 9px; font-size:12px; text-decoration:none; display:inline-block;">
                                    Extrait
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" style="text-align:center; color:#6B6E76; padding:26px;">
                                Aucun tiers ne correspond à cette recherche.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$this->pageCourante" :total="$this->lignes->count()" prop="page" :par-page="$this->parPage" />

        <div class="rec-hint">
            L'encours affiché est celui que le tiers doit <b>en tant que payeur</b>. Une compagnie
            dont tous les dossiers passent par un courtier figure ici à zéro : la créance est portée
            par le courtier, et c'est à lui qu'elle est réclamée. Un tiers marqué « Déclaré » existe
            au référentiel mais n'a encore été facturé nulle part.
        </div>
    </div>
</x-recouvrement::coquille>

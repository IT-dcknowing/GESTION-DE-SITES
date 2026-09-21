<?php

use Modules\Noyau\Exploitation\Services\Recouvrement;
use Modules\Recouvrement\Support\PeriodeDeTravail;
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

        <div class="rec-tbl-wrap" style="max-height:none;">
            <table class="rec-tbl">
                <thead>
                    <tr>
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
                        <tr>
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
                            <td colspan="10" style="text-align:center; color:#6B6E76; padding:26px;">
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

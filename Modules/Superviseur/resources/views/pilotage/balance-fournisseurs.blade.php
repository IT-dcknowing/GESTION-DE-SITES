<?php

use Modules\Noyau\Commun\Services\FiltreLibre;
use Modules\Noyau\Imports\Modeles\SoldeFournisseur;

use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Balance fournisseurs — ce que la comptabilité tient
|--------------------------------------------------------------------------
| **Deux sources, et l'écart entre elles.** L'écran *Fournisseurs* montre le suivi tenu à
| la main : ce que l'atelier croit devoir, pièce par pièce. Celui-ci montre la balance
| exportée du logiciel d'atelier : ce qui est enregistré. C'est leur écart qu'on cherche
| quand un fournisseur réclame.
|
| **Le solde est gardé tel que le logiciel l'annonce, et recalculé à côté.** Demandé par le
| propriétaire le 22/09 : « on garde le total estimé du logiciel et on compare après calcul,
| comme le fait la caisse avec son KPI ». Débit moins crédit ne redonne pas toujours la
| colonne SOLDE — lettrage, reports, écritures d'à-nouveau. Écraser l'un par l'autre
| effacerait l'écart ; les afficher côte à côte le montre.
|
| Cette page est en **lecture seule** : rien ne s'y saisit, tout vient du fichier déposé.
*/

state(['recherche' => ''])->url(except: '');
/*
 * **Deux balances — 20/10.** « On doit avoir deux balances : celle qu'on importe, et celle que le
 * système produit avec les données dont il dispose, comme la balance âgée. » La seconde se lit
 * sur le suivi (`BalanceDesFournisseurs`), et porte en regard le solde de la première.
 */
state(['vue' => 'importee'])->url(except: 'importee');
state(['pageSysteme' => 1]);
state(['ecartsSeulement' => false])->url(except: false);
state(['page' => 1]);

/*
 * Les filtres posés sur les colonnes sans filtre propre — voir `FiltreLibre` et le
 * composant `x-autre-filtre`. Hors de l'adresse : un tableau de tableaux ne se sérialise
 * pas lisiblement dans une URL, pour un gain nul.
 */
state(['filtresLibres' => []]);

$updatedRecherche = function () { $this->page = 1; $this->pageSysteme = 1; };
$updatedVue = function () { $this->page = 1; $this->pageSysteme = 1; };
$updatedEcartsSeulement = function () { $this->page = 1; };
$updatedFiltresLibres = function () { $this->page = 1; };

/**
 * Les colonnes de cette balance qu'aucun filtre du haut ne couvre.
 *
 * Trois, et ce sont des montants : la recherche prend le nom, la case à cocher prend
 * l'écart, et il ne reste que le débit, le crédit et le solde. On les cherche par tranche —
 * « les comptes qui dépassent deux millions » est la question qu'un comptable pose à une
 * balance ; « exactement 2 134 500 » n'est la question de personne.
 *
 * Le solde **annoncé** est celui qu'on filtre, et non le recalculé : le recalcul et l'écart
 * se font à la lecture, ligne par ligne, et n'existent dans aucune colonne — la base ne
 * peut donc pas les comparer. C'est déjà pour cela que la case « comptes en écart » filtre
 * la collection et non la requête.
 */
$colonnesFiltrables = computed(fn () => [
    'soldes_fournisseur.debit' => FiltreLibre::colonne('Débit', 'nombre'),
    'soldes_fournisseur.credit' => FiltreLibre::colonne('Crédit', 'nombre'),
    'soldes_fournisseur.solde' => FiltreLibre::colonne('Solde annoncé', 'nombre'),
]);

/**
 * Les soldes, chacun avec son recalcul et son écart.
 *
 * L'écart se calcule à la lecture et n'est jamais une colonne stockée — même règle que le
 * reste à payer d'une créance : une colonne peut se tromper, une soustraction faite à la
 * lecture ne peut pas.
 */
$lignes = computed(function () {
    $requete = SoldeFournisseur::query()->where('entreprise_id', auth()->user()->entreprise_id);

    if (trim($this->recherche) !== '') {
        $requete->where('fournisseur', 'like', '%'.trim($this->recherche).'%');
    }

    // Posés sur la requête, donc avant le `get()` : filtrer la collection ensuite
    // ramènerait toute la balance pour n'en garder que trois lignes.
    FiltreLibre::appliquer($requete, $this->colonnesFiltrables, (array) $this->filtresLibres);

    $lignes = $requete->orderByDesc('solde')->get()->map(function ($ligne) {
        $recalcule = montant_de($ligne->credit) - montant_de($ligne->debit);

        return [
            'ligne' => $ligne,
            'recalcule' => $recalcule,
            'ecart' => montant_de($ligne->solde) - $recalcule,
        ];
    });

    if ($this->ecartsSeulement) {
        $lignes = $lignes->filter(fn ($l) => $l['ecart'] !== 0)->values();
    }

    return $lignes;
});

$totaux = computed(fn () => [
    'fournisseurs' => $this->lignes->count(),
    'debit' => montant_de($this->lignes->sum(fn ($l) => $l['ligne']->debit)),
    'credit' => montant_de($this->lignes->sum(fn ($l) => $l['ligne']->credit)),
    'solde' => montant_de($this->lignes->sum(fn ($l) => $l['ligne']->solde)),
    'recalcule' => montant_de($this->lignes->sum(fn ($l) => $l['recalcule'])),
    'enEcart' => $this->lignes->filter(fn ($l) => $l['ecart'] !== 0)->count(),
]);

$balanceSysteme = computed(fn () => $this->vue !== 'systeme' ? collect()
    : \Modules\Noyau\Exploitation\Services\BalanceDesFournisseurs::calculer((int) auth()->user()->entreprise_id, now(), $this->recherche));

$totauxSysteme = computed(fn () => \Modules\Noyau\Exploitation\Services\BalanceDesFournisseurs::totaux($this->balanceSysteme));

?>

<div>
    <x-titre-ecran titre="Balance fournisseurs"
        sous-titre="Deux balances : celle du logiciel d'atelier, importée, et celle que le système produit à partir du suivi fournisseurs, par ancienneté.">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <a href="{{ route('fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">← Suivi fournisseur</a>
            <a href="{{ route('reglements-fournisseurs') }}" wire:navigate class="bouton bouton-secondaire">Règlements</a>
        </div>
    </x-titre-ecran>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
        <button type="button" wire:click="$set('vue', 'importee')" class="onglet {{ $vue === 'importee' ? 'est-actif' : '' }}">Balance importée (logiciel d'atelier)</button>
        <button type="button" wire:click="$set('vue', 'systeme')" class="onglet {{ $vue === 'systeme' ? 'est-actif' : '' }}">Balance produite par le système (âgée)</button>
    </div>

    @if ($vue === 'systeme')
        @php $t = $this->totauxSysteme; $tranches = \Modules\Noyau\Exploitation\Services\Recouvrement::TRANCHES; @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:12px; margin-bottom:18px;">
            <x-kpi-card label="Fournisseurs" :value="$t['fournisseurs']" />
            <x-kpi-card label="Facturé (suivi)" :value="ae($t['facture'])" />
            <x-kpi-card label="Réglé" :value="ae($t['regle'])" couleur="#0E9F6E" />
            <x-kpi-card label="Reste à payer" :value="ae($t['reste'])" couleur="#C8102E" sub="Selon le suivi fournisseurs" />
            <x-kpi-card label="Non échu" :value="ae($t['nonEchu'])" sub="Échéance à venir" />
            <x-kpi-card label="Solde de la balance importée" :value="ae($t['importe'])" sub="Écart avec le suivi : {{ ae(montant_de($t['reste'] - $t['importe'])) }}" />
        </div>

        <div class="carte">
            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:10px;">
                <x-champ label="Rechercher un fournisseur" model="recherche" :live="true" placeholder="Nom du fournisseur…" />
            </div>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
                Produite à partir du suivi fournisseurs, toutes années : le reste de chaque pièce, rangé par
                ancienneté depuis son échéance (sinon sa date de facture), aux tranches de la balance âgée des
                clients. En regard, le solde que donne la balance importée, rapproché par le nom.
            </p>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th><th style="text-align:right;">Pièces</th>
                            <th style="text-align:right;">Facturé</th><th style="text-align:right;">Réglé</th>
                            <th style="text-align:right;">Reste</th><th style="text-align:right;">Non échu</th>
                            @foreach ($tranches as $tr)
                                <th style="text-align:right; color:{{ $tr['couleur'] }};">{{ $tr['libelle'] }}</th>
                            @endforeach
                            <th style="text-align:right;">Balance importée</th><th class="colonne-collee" style="text-align:right;">Écart</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->balanceSysteme->forPage($pageSysteme, 25) as $b)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $b['fournisseur'] }}
                                    <div style="font-size:11px; font-weight:400;"><a href="{{ route('fournisseurs.grand-livre', ['fournisseur' => $b['fournisseur']]) }}" wire:navigate>Grand livre ›</a></div></td>
                                <td style="text-align:right;">{{ $b['nombre'] }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ ae($b['facture']) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ ae($b['regle']) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:700;">{{ ae($b['reste']) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; color:#6B6E76;">{{ $b['nonEchu'] != 0 ? ae($b['nonEchu']) : '—' }}</td>
                                @foreach ($b['tranches'] as $m)
                                    <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">{{ $m != 0 ? ae($m) : '—' }}</td>
                                @endforeach
                                <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; color:#6B6E76;">{{ $b['importe'] === null ? 'absent' : ae($b['importe']) }}</td>
                                <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:700; color:{{ $b['ecart'] === null ? '#6B6E76' : ($b['ecart'] == 0 ? '#0E9F6E' : '#C8102E') }};">
                                    {{ $b['ecart'] === null ? '—' : ($b['ecart'] == 0 ? '✓' : ae($b['ecart'])) }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="8 + count($tranches)" texte="Aucune pièce au suivi fournisseurs." />
                        @endforelse
                    </tbody>
                    @if ($this->balanceSysteme->isNotEmpty())
                        <tfoot>
                            <tr style="font-weight:700; border-top:2px solid var(--th-steel,#2A2E35);">
                                <td>Total</td><td></td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae($t['facture']) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae($t['regle']) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae($t['reste']) }}</td>
                                <td style="text-align:right; white-space:nowrap;">{{ ae($t['nonEchu']) }}</td>
                                @foreach ($t['tranches'] as $m)
                                    <td style="text-align:right; white-space:nowrap;">{{ ae($m) }}</td>
                                @endforeach
                                <td style="text-align:right; white-space:nowrap;">{{ ae($t['importe']) }}</td>
                                <td class="colonne-collee" style="text-align:right; white-space:nowrap;">{{ ae(montant_de($t['reste'] - $t['importe'])) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <x-pagination :page="$pageSysteme" :total="$this->balanceSysteme->count()" prop="pageSysteme" :par-page="25" />
        </div>
    @else
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:12px; margin-bottom:18px;">
        <x-kpi-card label="Fournisseurs" :value="$this->totaux['fournisseurs']" />
        <x-kpi-card label="Total débit" :value="ae($this->totaux['debit'])" />
        <x-kpi-card label="Total crédit" :value="ae($this->totaux['credit'])" />
        <x-kpi-card label="Solde annoncé" :value="ae($this->totaux['solde'])" sub="Tel que le logiciel le donne" />
        <x-kpi-card label="Solde recalculé" :value="ae($this->totaux['recalcule'])" sub="Crédit − débit"
            :couleur="$this->totaux['recalcule'] === $this->totaux['solde'] ? '#0E9F6E' : '#D97706'" />
        <x-kpi-card label="Comptes en écart" :value="$this->totaux['enEcart']"
            sub="Annoncé ≠ recalculé" :couleur="$this->totaux['enEcart'] > 0 ? '#C8102E' : '#0E9F6E'" />
    </div>

    <div class="carte">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
            <x-champ label="Rechercher un fournisseur" model="recherche" :live="true" placeholder="Nom du fournisseur…" />
            <x-champ label="Comptes en écart seulement" model="ecartsSeulement" type="checkbox" live="true" />

            {{-- Les montants, par tranche. Demandé le 29/09 pour cette page nommément. --}}
            <x-autre-filtre :colonnes="$this->colonnesFiltrables" :actifs="$filtresLibres" />
        </div>

        @if ($this->lignes->isEmpty() && $recherche === '' && ! $ecartsSeulement)
            <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                Aucune balance déposée. Le fichier se dépose depuis le module <b>Import</b>,
                sous le format « Balance fournisseurs ».
            </p>
        @else
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Fournisseur</th>
                            <th style="text-align:right;">Débit</th>
                            <th style="text-align:right;">Crédit</th>
                            <th style="text-align:right;">Solde annoncé</th>
                            <th style="text-align:right;">Recalculé</th>
                            <th class="colonne-collee" style="text-align:right;">Écart</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes->forPage($page, 25) as $l)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="font-weight:700;">{{ $l['ligne']->fournisseur }}
                                    <div style="font-size:11px; font-weight:400;"><a href="{{ route('fournisseurs.grand-livre', ['fournisseur' => $l['ligne']->fournisseur]) }}" wire:navigate>Grand livre ›</a></div></td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($l['ligne']->debit) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($l['ligne']->credit) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($l['ligne']->solde) }}</td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums; color:#6B6E76;">{{ ae($l['recalcule']) }}</td>
                                <td class="colonne-collee" style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                           color:{{ $l['ecart'] === 0 ? '#0E9F6E' : '#C8102E' }};">
                                    {{ $l['ecart'] === 0 ? '—' : ae($l['ecart']) }}
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucun compte ne correspond à ce filtre." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="25" />

            <p style="margin:14px 0 0; font-size:12.5px; color:#6B6E76;">
                Un écart n'est pas une erreur en soi : un lettrage, un report ou une écriture
                d'à-nouveau suffit à l'expliquer. C'est pour qu'il se voie plutôt qu'il ne
                disparaisse que le solde annoncé n'est jamais écrasé par le recalcul.
            </p>
        @endif
    </div>
    @endif
</div>

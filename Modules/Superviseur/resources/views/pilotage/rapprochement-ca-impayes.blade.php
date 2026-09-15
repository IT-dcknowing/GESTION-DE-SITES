<?php

use Illuminate\Support\Collection;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use function Livewire\Volt\{computed, mount, state};

/*
|--------------------------------------------------------------------------
| Rapprochement — chiffre d'affaires facturé / état des impayés
|--------------------------------------------------------------------------
| **La question posée était : le système compare-t-il les deux ? Il ne le faisait pas.**
| Les deux fichiers vivaient côte à côte en base sans que rien ne les confronte, et
| c'est précisément dans cet écart-là qu'une créance se perd — non pas mal suivie, mais
| jamais entrée dans le suivi.
|
| **La prémisse était juste, à une nuance près.** L'état des impayés retrace bien les
| mêmes affaires que le CATTC, mais les deux fichiers ne partagent pas leur numéro de
| facture : « FA -5713 » d'un côté, « 17 » ou « 23 » de l'autre. Et ils ne couvrent pas la
| même période — le CATTC repris ne porte que 2026, l'état des impayés va de 2022 à 2026.
| Aucun des deux n'est donc le sous-ensemble de l'autre.
|
| **Le pont existe quand même, et il a été mesuré sur les deux fichiers réels** : le couple
| immatriculation + montant. 1 593 des 2 232 couples du CATTC se retrouvent dans l'état des
| impayés, soit 71 % ; sur la seule immatriculation, 1 184 sur 1 509, soit 78 %. C'est assez
| pour répondre à « cette facture est-elle suivie ? », et ce n'est pas assez pour désigner
| une facture précise : un client de flotte ramène le même véhicule au même tarif. Le
| rapprochement dit donc « suivie » ou « non suivie », jamais « c'est cette ligne-là ».
|
| **Ce que l'écart mesure, année 2026 sur les fichiers repris** : 1 384 588 526 F facturés
| au CATTC contre 1 289 412 357 F relevés dans l'état des impayés — 95 176 169 F et 424
| factures dont personne ne suit le règlement. C'est le chiffre que cet écran existe pour
| montrer.
*/

state(['exercice' => null])->url(except: '');
state(['villeFiltre' => ''])->url(except: '');
state(['pageAbsentes' => 1]);
state(['pageOrphelines' => 1]);

mount(function () {
    $this->exercice ??= EtatDesImpayes::exerciceOuvert(auth()->user()->entreprise_id);
});

$updatedExercice = function () { $this->pageAbsentes = 1; $this->pageOrphelines = 1; };
$updatedVilleFiltre = function () { $this->pageAbsentes = 1; $this->pageOrphelines = 1; };

$annee = computed(fn () => (int) ($this->exercice ?: now()->year));

$exercices = computed(function () {
    $annees = EtatDesImpayes::exercices(auth()->user()->entreprise_id);

    /*
     * Les années du chiffre d'affaires comptent aussi : une année facturée dont aucune
     * créance n'a été relevée est exactement le cas qu'on cherche à voir.
     *
     * On lit les deux bornes et l'on déplie l'intervalle, plutôt que de grouper par année :
     * extraire l'année d'une date ne s'écrit pas de la même façon sur MySQL et sur SQLite, et
     * une requête qui marche en production sans marcher en test ne se teste pas.
     */
    $bornes = Facture::query()
        ->whereNull('exercice_impayes')
        ->whereNotNull('date')
        ->selectRaw('min(date) as premiere, max(date) as derniere')
        ->first();

    $duCa = [];

    if ($bornes?->premiere) {
        $duCa = range(
            (int) \Illuminate\Support\Carbon::parse($bornes->premiere)->format('Y'),
            (int) \Illuminate\Support\Carbon::parse($bornes->derniere)->format('Y'),
        );
    }

    $toutes = collect($annees)->merge($duCa)->filter()->unique()->sortDesc()->values()->all();

    return array_combine($toutes, $toutes) ?: [now()->year => now()->year];
});

// Null quand le choix ne se pose pas — une seule ville. Converti pour la liste déroulante.
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user())?->pluck('nom', 'id')->all() ?? []);
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, ''));

/**
 * Les deux populations de l'année, chargées une fois et partagées par tout l'écran.
 *
 * Elles vivent dans la même table et se distinguent par une seule chose : l'année de l'état
 * des impayés, nulle pour une facture venue du chiffre d'affaires, renseignée pour une
 * créance relevée. C'est la distinction qui compte, et non l'origine du dépôt : une créance
 * saisie à la main sur l'écran de l'état en relève tout autant que celle qui a été importée.
 *
 * @return array{ca: Collection<int, Facture>, impayes: Collection<int, Facture>}
 */
$populations = computed(function () {
    $colonnes = [
        'id', 'site_id', 'numero', 'n_facture', 'date', 'client', 'assureur', 'courtier',
        'immatriculation', 'montant', 'exercice_impayes', 'est_etat_initial', 'lot_import_id',
    ];

    $base = fn () => Facture::query()
        ->select($colonnes)
        ->whereNotNull('date')
        ->whereYear('date', $this->annee)
        ->where(fn ($q) => $q->whereIn('site_id', $this->idsSites)->orWhereNull('site_id'));

    return [
        'ca' => $base()->whereNull('exercice_impayes')->with('site')->get(),
        'impayes' => $base()->whereNotNull('exercice_impayes')
            ->withSum('encaissements', 'montant')->with('site')->get(),
    ];
});

$indicateurs = computed(function () {
    $ca = $this->populations['ca'];
    $impayes = $this->populations['impayes'];

    $totalCa = (int) $ca->sum('montant');
    $totalImpayes = (int) $impayes->sum('montant');
    $reste = $impayes->sum(fn (Facture $f) => Recouvrement::reste($f));

    return [
        'ca' => $totalCa,
        'lignesCa' => $ca->count(),
        'impayes' => $totalImpayes,
        'lignesImpayes' => $impayes->count(),
        'ecart' => $totalCa - $totalImpayes,
        // Le taux de suivi : quelle part du chiffre d'affaires facturé est entrée dans le
        // suivi des créances. Cent pour cent ne veut pas dire « tout est encaissé », cela
        // veut dire « tout est suivi » — ce sont deux questions différentes.
        'taux' => $totalCa > 0 ? round($totalImpayes / $totalCa * 100, 1) : null,
        'reste' => $reste,
        'tauxEncaisse' => $totalImpayes > 0 ? round(($totalImpayes - $reste) / $totalImpayes * 100, 1) : null,
    ];
});

/** Les clés du pont présentes de chaque côté, pour ne les calculer qu'une fois. */
$clesImpayes = computed(fn () => $this->populations['impayes']
    ->map(fn (Facture $f) => EtatDesImpayes::clePont($f))
    ->filter()
    ->unique()
    ->flip());

$clesCa = computed(fn () => $this->populations['ca']
    ->map(fn (Facture $f) => EtatDesImpayes::clePont($f))
    ->filter()
    ->unique()
    ->flip());

/**
 * Les factures du chiffre d'affaires dont aucune créance ne porte la trace.
 *
 * C'est la liste qui fait tout l'intérêt de l'écran : ces factures ont été émises, elles
 * comptent dans le chiffre d'affaires, et personne ne suit leur règlement. Ce ne sont pas
 * des impayés — on ne sait pas si elles sont payées, et c'est bien le problème.
 *
 * Une facture sans immatriculation ne peut pas être rapprochée : elle est comptée à part
 * plutôt que déclarée non suivie, parce qu'on ne sait pas.
 */
$absentes = computed(fn () => $this->populations['ca']
    ->filter(function (Facture $f) {
        $cle = EtatDesImpayes::clePont($f);

        return $cle !== null && ! $this->clesImpayes->has($cle);
    })
    ->sortByDesc('montant')
    ->values());

$sansPlaque = computed(fn () => $this->populations['ca']
    ->filter(fn (Facture $f) => EtatDesImpayes::clePont($f) === null)
    ->values());

/**
 * Les créances que le chiffre d'affaires importé ne connaît pas.
 *
 * Deux explications possibles, et l'écran ne choisit pas à la place du lecteur : soit la
 * facture a été émise hors du logiciel d'atelier, soit le CATTC de cette année n'a pas été
 * repris. Sur les fichiers d'origine, c'est la seconde qui domine — le CATTC repris ne porte
 * que 2026, l'état des impayés remonte à 2022.
 */
$orphelines = computed(fn () => $this->populations['impayes']
    ->filter(function (Facture $f) {
        $cle = EtatDesImpayes::clePont($f);

        return $cle !== null && ! $this->clesCa->has($cle);
    })
    ->sortByDesc(fn (Facture $f) => Recouvrement::reste($f))
    ->values());

/** Le rapprochement lieu par lieu : c'est là qu'un atelier qui ne suit pas se voit. */
$parSite = computed(function () {
    $lignes = [];

    foreach (['ca', 'impayes'] as $cote) {
        foreach ($this->populations[$cote] as $facture) {
            $nom = $facture->site?->nom ?? '— sans atelier —';

            $lignes[$nom] ??= ['site' => $nom, 'ca' => 0, 'lignesCa' => 0, 'impayes' => 0, 'lignesImpayes' => 0, 'reste' => 0];

            if ($cote === 'ca') {
                $lignes[$nom]['ca'] += (int) $facture->montant;
                $lignes[$nom]['lignesCa']++;
            } else {
                $lignes[$nom]['impayes'] += (int) $facture->montant;
                $lignes[$nom]['lignesImpayes']++;
                $lignes[$nom]['reste'] += Recouvrement::reste($facture);
            }
        }
    }

    return collect($lignes)
        ->map(function (array $ligne) {
            $ligne['ecart'] = $ligne['ca'] - $ligne['impayes'];
            $ligne['taux'] = $ligne['ca'] > 0 ? round($ligne['impayes'] / $ligne['ca'] * 100, 1) : null;

            return $ligne;
        })
        ->sortByDesc('ca')
        ->values();
});

?>

<div>
    <x-titre-ecran titre="Rapprochement CA / impayés"
        sous-titre="Ce qui a été facturé, face à ce qui est réellement suivi en créance — année par année et atelier par atelier." />

    <div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
        <p style="margin:0 0 8px; font-size:13px; line-height:1.6;">
            <strong>Ce que cet écran rapproche, et sur quoi.</strong> Les deux fichiers ne partagent pas
            leur numéro de facture — « FA -5713 » d'un côté, « 17 » de l'autre. Le pont retenu est le
            couple <strong>immatriculation + montant</strong> : mesuré sur les fichiers d'origine, il
            retrouve 71 % des factures du CATTC dans l'état des impayés, et 78 % sur la seule plaque.
        </p>
        <p style="margin:0; font-size:13px; line-height:1.6; color:#6B6E76;">
            Il répond donc à « cette facture est-elle suivie ? », jamais à « c'est cette ligne-là » : un
            client de flotte ramène le même véhicule au même tarif. Une facture sans immatriculation
            n'est pas rapprochable et n'est pas comptée comme non suivie — on ne sait pas.
        </p>
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="Année" model="exercice" type="select" :options="$this->exercices" :live="true" width="130" />

            @if (! $this->villeUnique && $this->mesVilles !== [])
                <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles" vide="Toutes" :live="true" width="180" />
            @endif
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Chiffre d'affaires facturé — {{ $this->annee }}" :value="ae($this->indicateurs['ca'])"
            :sub="$this->indicateurs['lignesCa'].' facture(s)'" />
        <x-kpi-card label="Créances relevées à l'état" :value="ae($this->indicateurs['impayes'])"
            :sub="$this->indicateurs['lignesImpayes'].' créance(s)'" />
        <x-kpi-card label="Facturé sans être suivi"
            :value="ae(max(0, $this->indicateurs['ecart']))"
            :accent="$this->indicateurs['ecart'] > 0"
            :sub="$this->absentes->count().' facture(s) non retrouvée(s)'" />
        <x-kpi-card label="Taux de suivi"
            :value="$this->indicateurs['taux'] !== null ? $this->indicateurs['taux'].' %' : '—'"
            :bon="$this->indicateurs['taux'] !== null && $this->indicateurs['taux'] >= 95"
            :accent="$this->indicateurs['taux'] !== null && $this->indicateurs['taux'] < 80"
            sub="Part du facturé entrée dans le suivi des créances" />
    </div>

    {{-- Suivi n'est pas encaissé : les deux taux sont côte à côte pour qu'on ne les
         confonde pas. Une année peut être suivie à 100 % et encaissée à 60 %. --}}
    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:20px;">
        <x-kpi-card label="Reste à payer sur les créances suivies" :value="ae($this->indicateurs['reste'])"
            :accent="$this->indicateurs['reste'] > 0" />
        <x-kpi-card label="Taux d'encaissement des créances suivies"
            :value="$this->indicateurs['tauxEncaisse'] !== null ? $this->indicateurs['tauxEncaisse'].' %' : '—'"
            :bon="$this->indicateurs['tauxEncaisse'] !== null && $this->indicateurs['tauxEncaisse'] >= 90"
            sub="À ne pas confondre avec le taux de suivi ci-dessus" />
    </div>

    <div class="carte" style="margin-bottom:20px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Atelier par atelier</h3>
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Atelier</th>
                        <th style="text-align:right;">CA facturé</th>
                        <th style="text-align:right;">Factures</th>
                        <th style="text-align:right;">Créances relevées</th>
                        <th style="text-align:right;">Créances</th>
                        <th style="text-align:right;">Facturé non suivi</th>
                        <th style="text-align:right;">Taux de suivi</th>
                        <th style="text-align:right;">Reste à payer</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->parSite as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="font-weight:600;">{{ $ligne['site'] }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($ligne['ca']) }}</td>
                            <td style="text-align:right;">{{ $ligne['lignesCa'] }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($ligne['impayes']) }}</td>
                            <td style="text-align:right;">{{ $ligne['lignesImpayes'] }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;
                                       color:{{ $ligne['ecart'] > 0 ? '#C8102E' : '#6B6E76' }}; font-weight:700;">
                                {{ ae(max(0, $ligne['ecart'])) }}
                            </td>
                            <td style="text-align:right; font-weight:700;
                                       color:{{ $ligne['taux'] === null ? '#6B6E76' : ($ligne['taux'] >= 95 ? '#0E9F6E' : ($ligne['taux'] < 80 ? '#C8102E' : '#B87A00')) }};">
                                {{ $ligne['taux'] !== null ? $ligne['taux'].' %' : '—' }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($ligne['reste']) }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="8" texte="Aucune facture ni créance sur l'année {{ $this->annee }}." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="carte" style="margin-bottom:20px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">
            Facturé mais non suivi — {{ $this->absentes->count() }} facture(s)
        </h3>
        <p style="font-size:12.5px; color:#6B6E76; margin:0 0 14px;">
            Ces factures comptent dans le chiffre d'affaires et aucune créance ne porte leur trace. Ce ne
            sont pas des impayés : on ne sait pas si elles ont été réglées, et c'est précisément le
            problème. Les porter à l'état des impayés est le geste qui manque.
            @if ($this->sansPlaque->isNotEmpty())
                <br>{{ $this->sansPlaque->count() }} autre(s) facture(s) sont sans immatriculation :
                elles ne sont pas rapprochables et ne sont donc pas comptées ici.
            @endif
        </p>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Date</th>
                        <th>N° facture</th>
                        <th>Client</th>
                        <th>Immatriculation</th>
                        <th>Atelier</th>
                        <th style="text-align:right;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->absentes->forPage($pageAbsentes, 10) as $ligne)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_facture ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td>{{ $ligne->immatriculation }}</td>
                            <td>{{ $ligne->site?->nom ?? '— à rattacher —' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;">{{ ae($ligne->montant) }}</td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="7"
                            texte="Tout le chiffre d'affaires de l'année se retrouve dans l'état des impayés." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageAbsentes" :total="$this->absentes->count()" prop="pageAbsentes" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">
            Créances sans facture au chiffre d'affaires — {{ $this->orphelines->count() }}
        </h3>
        <p style="font-size:12.5px; color:#6B6E76; margin:0 0 14px;">
            Deux explications, et l'écran ne choisit pas : soit la facture a été émise hors du logiciel
            d'atelier, soit le fichier CATTC de cette année n'a pas encore été repris. Le CATTC déjà
            importé ne porte que 2026, alors que l'état des impayés remonte à 2022 — pour les années
            antérieures, c'est la seconde explication qui vaut.
        </p>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Date</th>
                        <th>N° facture</th>
                        <th>Tiers payant</th>
                        <th>Immatriculation</th>
                        <th>Atelier</th>
                        <th style="text-align:right;">Montant</th>
                        <th style="text-align:right;">Reste à payer</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->orphelines->forPage($pageOrphelines, 10) as $ligne)
                        @php $reste = Recouvrement::reste($ligne); @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_facture ?? '—' }}</td>
                            <td>{{ $ligne->tiersPayant() }}</td>
                            <td>{{ $ligne->immatriculation }}</td>
                            <td>{{ $ligne->site?->nom ?? '— à rattacher —' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($ligne->montant) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $reste >= Recouvrement::SEUIL_SOLDE ? '#C8102E' : '#6B6E76' }};">
                                {{ ae($reste) }}
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="8"
                            texte="Chaque créance de l'année se retrouve dans le chiffre d'affaires facturé." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :page="$pageOrphelines" :total="$this->orphelines->count()" prop="pageOrphelines" />
    </div>
</div>

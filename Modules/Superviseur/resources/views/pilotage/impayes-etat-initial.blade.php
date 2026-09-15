<?php

use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Facture;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Tableau état initial — la reprise du classeur, telle quelle
|--------------------------------------------------------------------------
| Cet écran ne sert qu'à une chose : garder sous les yeux ce qui a été repris, sans y
| toucher. Le classeur mêlait cinq années sur un seul onglet et ne se rangeait pas ;
| le remettre en ordre d'office aurait été réécrire l'histoire d'un fichier tenu à la
| main pendant quatre ans, et personne n'aurait plus pu rapprocher quoi que ce soit.
|
| Il est donc en lecture seule, et **volontairement** : la correction d'une créance se
| fait dans l'état de son année, où elle est datée, tracée et attribuée à quelqu'un.
|
| Ce que cet écran montre et qu'aucun autre ne montre : la tranche d'ancienneté que le
| classeur annonçait, à côté de celle que l'application recalcule. Une tranche écrite
| dans une cellule était juste le jour où on l'a tapée ; elle a vieilli seule depuis.
*/

state(['anneeFiltre' => ''])->url(except: '');
state(['villeFiltre' => ''])->url(except: '');
state(['statutFiltre' => 'toutes'])->url(except: 'toutes');
state(['ecartFiltre' => ''])->url(except: '');
state(['recherche' => ''])->url(except: '');
state(['page' => 1]);

$updatedAnneeFiltre = function () { $this->page = 1; };
$updatedVilleFiltre = function () { $this->page = 1; };
$updatedStatutFiltre = function () { $this->page = 1; };
$updatedEcartFiltre = function () { $this->page = 1; };
$updatedRecherche = function () { $this->page = 1; };

// Null quand le choix ne se pose pas — une seule ville. Converti pour la liste déroulante.
$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user())?->pluck('nom', 'id')->all() ?? []);
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));
$idsSites = computed(fn () => PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, ''));

/** Les années présentes dans la reprise — le classeur en mêlait cinq. */
$annees = computed(function () {
    $annees = EtatDesImpayes::requeteEtatInitial()
        ->whereNotNull('exercice_impayes')
        ->distinct()
        ->pluck('exercice_impayes')
        ->map(fn ($a) => (int) $a)
        ->sortDesc()
        ->values()
        ->all();

    return array_combine($annees, $annees) ?: [];
});

$lignes = computed(function () {
    $requete = EtatDesImpayes::requeteEtatInitial()
        ->withSum('encaissements', 'montant')
        ->with('site.ville')
        ->where(fn ($q) => $q->whereIn('site_id', $this->idsSites)->orWhereNull('site_id'))
        ->when($this->anneeFiltre !== '', fn ($q) => $q->where('exercice_impayes', (int) $this->anneeFiltre));

    if ($this->recherche !== '') {
        $terme = '%'.trim($this->recherche).'%';

        $requete->where(fn ($sous) => $sous
            ->where('client', 'like', $terme)
            ->orWhere('assureur', 'like', $terme)
            ->orWhere('courtier', 'like', $terme)
            ->orWhere('n_facture', 'like', $terme)
            ->orWhere('immatriculation', 'like', $terme)
            ->orWhere('n_sinistre', 'like', $terme));
    }

    $lignes = $requete->orderByDesc('date')->orderByDesc('id')->get();

    if ($this->statutFiltre === 'ouvertes') {
        $lignes = $lignes->filter(fn (Facture $f) => Recouvrement::reste($f) >= Recouvrement::SEUIL_SOLDE);
    } elseif ($this->statutFiltre === 'soldees') {
        $lignes = $lignes->filter(fn (Facture $f) => Recouvrement::reste($f) < Recouvrement::SEUIL_SOLDE);
    }

    /*
     * Le filtre qui donne son intérêt à cet écran : ne montrer que les lignes dont la
     * tranche écrite dans le classeur ne correspond plus à celle qu'on recalcule. C'est la
     * mesure de ce qu'un tableur tenu à la main coûte en justesse.
     */
    if ($this->ecartFiltre === 'ecart') {
        $lignes = $lignes->filter(fn (Facture $f) => EtatDesImpayes::trancheDivergente($f));
    }

    return $lignes->values();
});

$totaux = computed(function () {
    $facture = 0;
    $encaisse = 0;
    $reste = 0;
    $ouvertes = 0;
    $ecarts = 0;

    foreach ($this->lignes as $ligne) {
        $duReste = Recouvrement::reste($ligne);
        $facture += (int) $ligne->montant;
        $encaisse += (int) ($ligne->encaissements_sum_montant ?? 0);
        $reste += $duReste;

        if ($duReste >= Recouvrement::SEUIL_SOLDE) {
            $ouvertes++;
        }

        if (EtatDesImpayes::trancheDivergente($ligne)) {
            $ecarts++;
        }
    }

    return compact('facture', 'encaisse', 'reste', 'ouvertes', 'ecarts');
});

?>

<div>
    <x-titre-ecran titre="Tableau état initial"
        sous-titre="La reprise du classeur « Etats des impayés », toutes années mêlées, en lecture seule." />

    <div class="carte" style="margin-bottom:16px; border-left:3px solid #B87A00;">
        <p style="margin:0; font-size:13px; line-height:1.6;">
            <strong>Pourquoi ce tableau est à part.</strong> Le classeur repris porte cinq années sur un
            seul onglet et des colonnes qui glissent d'une ligne à l'autre — la formule d'ancienneté s'y
            trouve sous « Mode de règlement » ou sous « banque » sur 2 765 lignes. Ses chiffres, eux, sont
            justes : <strong>798 999 354 F</strong> de reste à payer sur 1 332 créances ouvertes, et la
            reprise en base y retombe. Rien n'a donc été redressé d'office — ce serait réécrire quatre ans
            de travail fait à la main. Les créances encore ouvertes apparaissent, elles, dans
            l'<a href="{{ route('impayes') }}">état de leur année</a>, où elles se corrigent et se relancent.
        </p>
    </div>

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="Année de la facture" model="anneeFiltre" type="select" :options="$this->annees" vide="Toutes" :live="true" width="170" />

            @if (! $this->villeUnique && $this->mesVilles !== [])
                <x-champ label="Ville" model="villeFiltre" type="select" :options="$this->mesVilles" vide="Toutes" :live="true" width="170" />
            @endif

            <x-champ label="Solde" model="statutFiltre" type="select" :live="true" width="150"
                :options="['toutes' => 'Toutes', 'ouvertes' => 'Non soldées', 'soldees' => 'Soldées']" />

            <x-champ label="Tranche du classeur" model="ecartFiltre" type="select" :live="true" width="220"
                :options="['ecart' => 'Ne correspond plus au calcul']" vide="Peu importe" />

            <x-champ label="Recherche" model="recherche" :live="true"
                placeholder="Client, assureur, n° facture, immatriculation…" />
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
        <x-kpi-card label="Facturé — reprise" :value="ae($this->totaux['facture'])"
            :sub="$this->lignes->count().' ligne(s) reprise(s)'" />
        <x-kpi-card label="Déjà encaissé" :value="ae($this->totaux['encaisse'])" :bon="true" />
        <x-kpi-card label="Reste à payer, recalculé" :value="ae($this->totaux['reste'])"
            :accent="$this->totaux['reste'] > 0" :sub="$this->totaux['ouvertes'].' non soldée(s)'" />
        <x-kpi-card label="Tranches devenues fausses" :value="(string) $this->totaux['ecarts']"
            :accent="$this->totaux['ecarts'] > 0"
            sub="Écrites dans le classeur, contredites par le calcul" />
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
            Reprise du classeur — {{ $this->lignes->count() }} ligne(s)
        </h3>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Année</th>
                        <th>ASSUREUR</th>
                        <th>Client</th>
                        <th>SITE</th>
                        <th>Courtier</th>
                        <th>Réception</th>
                        <th>Édition</th>
                        <th>N° facture</th>
                        <th>N° sinistre</th>
                        <th>Vehicule</th>
                        <th>Immatriculation</th>
                        <th style="text-align:right;">Montant TTC</th>
                        <th style="text-align:right;">Réglé</th>
                        <th style="text-align:right;">Reste</th>
                        <th>banque</th>
                        <th>Ancienneté — classeur</th>
                        <th>Ancienneté — calculée</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes->forPage($page, 15) as $ligne)
                        @php
                            $reste = Recouvrement::reste($ligne);
                            $age = Recouvrement::anciennete($ligne, now());
                            $calculee = EtatDesImpayes::trancheDuFichier($age);
                            $ecart = EtatDesImpayes::trancheDivergente($ligne);
                        @endphp
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td><x-numero-ligne :ligne="$ligne" /></td>
                            <td>{{ $ligne->exercice_impayes ?? '—' }}</td>
                            <td>{{ $ligne->assureur ?? '—' }}</td>
                            <td>{{ $ligne->client }}</td>
                            <td>{{ $ligne->site?->nom ?? '— à rattacher —' }}</td>
                            <td>{{ $ligne->courtier ?? '—' }}</td>
                            <td>{{ $ligne->date_reception?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->date?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $ligne->n_facture ?? '—' }}</td>
                            <td>{{ $ligne->n_sinistre ?? '—' }}</td>
                            <td>{{ $ligne->vehicule ?? '—' }}</td>
                            <td>{{ $ligne->immatriculation ?? '—' }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ ae($ligne->montant) }}</td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:#0E9F6E;">
                                {{ ae((int) ($ligne->encaissements_sum_montant ?? 0)) }}
                            </td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:700;
                                       color:{{ $reste >= Recouvrement::SEUIL_SOLDE ? '#C8102E' : '#6B6E76' }};">
                                {{ ae($reste) }}
                            </td>
                            <td>{{ $ligne->banque ?? '—' }}</td>
                            <td style="{{ $ecart ? 'color:#C8102E; font-weight:700;' : 'color:#6B6E76;' }}">
                                {{ $ligne->anciennete_declaree ?: '—' }}
                            </td>
                            <td style="font-weight:600;">
                                {{ $calculee }}
                                @if ($age !== null && $reste >= Recouvrement::SEUIL_SOLDE)
                                    <div style="font-size:11px; color:#6B6E76; font-weight:400;">{{ $age }} j</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="18"
                            texte="Aucune ligne reprise. L'état initial se remplit au premier dépôt du fichier, depuis Import." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->lignes->count()" prop="page" :par-page="15" />
    </div>
</div>

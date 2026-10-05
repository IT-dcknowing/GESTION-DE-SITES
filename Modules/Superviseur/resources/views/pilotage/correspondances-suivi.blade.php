<?php

use Modules\Noyau\Commun\Services\PeriodeCalculateur;
use Modules\Noyau\Entreprises\Support\PerimetreSites;
use Modules\Noyau\Exploitation\Modeles\Commercial;
use Modules\Noyau\Exploitation\Modeles\CorrespondanceFacture;
use Modules\Noyau\Exploitation\Services\CorrespondancesDeFactures;
use Modules\Noyau\Exploitation\Services\EtatDesImpayes;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Les correspondances — ce que les commerciaux ont coché
|--------------------------------------------------------------------------
| La page des responsables : toutes les affectations faites depuis l'écran
| « Correspondances », par commercial, avec les filtres du tableau de bord — période, ville,
| atelier, commercial. C'est d'ici qu'on relit avant d'arrêter les commissions, et d'ici
| qu'on annule.
|
| **La période porte sur la date de la facture**, et non sur celle de la coche : c'est la
| date de la facture qui range un chiffre d'affaires dans un mois, donc dans une commission.
| Une facture de mars cochée en octobre compte en mars.
*/

state([
    'periode' => 'calendrier',
    'dateDebut' => null,
    'dateFin' => null,
    'moisFiltre' => '',
    'semaineFiltre' => '',
    'jourFiltre' => '',
    'villeFiltre' => '',
    'siteFiltre' => '',
    'activiteFiltre' => '',
    'commercialFiltre' => '',
]);
state(['etatFiltre' => 'en_cours'])->url(except: 'en_cours');
state(['page' => 1]);
state(['message' => '']);
state(['erreur' => '']);

mount(function () {
    $this->dateDebut ??= now()->startOfYear()->format('Y-m-d');
    $this->dateFin ??= now()->format('Y-m-d');
});

$updatedMoisFiltre = function () { $this->semaineFiltre = ''; $this->jourFiltre = ''; $this->page = 1; };
$updatedSemaineFiltre = function () { $this->jourFiltre = ''; $this->page = 1; };
/** Changer de ville rend caducs le lieu et le commercial choisis dans la précédente. */
$updatedVilleFiltre = function () { $this->siteFiltre = ''; $this->commercialFiltre = ''; $this->page = 1; };
$updatedSiteFiltre = function () { $this->page = 1; };
$updatedCommercialFiltre = function () { $this->page = 1; };
$updatedEtatFiltre = function () { $this->page = 1; };
$updatedJourFiltre = function () { $this->page = 1; };
$updatedPeriode = function () { $this->page = 1; };
$updatedDateDebut = function () { $this->page = 1; };
$updatedDateFin = function () { $this->page = 1; };
$updatedActiviteFiltre = function () { $this->page = 1; };

$plage = computed(fn () => PeriodeCalculateur::plage(
    $this->periode, $this->dateDebut, $this->dateFin, $this->moisFiltre ?: null, $this->semaineFiltre ?: null, $this->jourFiltre ?: null
));

$mesVilles = computed(fn () => PerimetreSites::optionsVilles(auth()->user()));
$mesSitesFiltre = computed(fn () => PerimetreSites::optionsSites(auth()->user(), $this->villeFiltre));
$villeUnique = computed(fn () => PerimetreSites::villeUnique(auth()->user()));

/** Les commerciaux des villes retenues — la même liste que sur le tableau de bord. */
$commerciaux = computed(fn () => Commercial::whereIn('ville_id', PerimetreSites::idsVillesRetenus(auth()->user(), $this->villeFiltre))
    ->orderBy('est_spontane')->orderBy('nom')->get());

$idsCommercialFiltre = computed(function () {
    if ($this->commercialFiltre === 'spontane') {
        return $this->commerciaux->where('est_spontane', true)->pluck('id')->all();
    }

    return $this->commercialFiltre ? [(int) $this->commercialFiltre] : null;
});

/**
 * Les correspondances du périmètre, filtres posés.
 *
 * Le périmètre du compte d'abord (`dansLePerimetre`, qui garde les factures sans atelier),
 * le filtre de ville et d'atelier ensuite : un filtre règle l'affichage, il ne donne pas de
 * droits.
 */
$filtrer = protect(function () {
    [$debut, $fin] = $this->plage;
    [$sites, $villes] = CorrespondancesDeFactures::perimetre(auth()->user());

    $requete = EtatDesImpayes::dansLePerimetre(
        CorrespondanceFacture::query()->join('factures', 'factures.id', '=', 'correspondances_factures.facture_id'),
        $sites, $villes,
    )->whereBetween('factures.date', [$debut->toDateString(), $fin->toDateString()]);

    if ($this->villeFiltre !== '' || $this->siteFiltre !== '') {
        $retenus = PerimetreSites::idsRetenus(auth()->user(), $this->villeFiltre, $this->siteFiltre);
        $ville = $this->villeFiltre !== '' ? (int) $this->villeFiltre : null;

        $requete->where(fn ($q) => $q
            ->whereIn('factures.site_id', $retenus)
            // Sans atelier, une facture ne se place que par sa ville — et seulement quand on
            // n'a pas demandé un atelier précis, qu'elle ne peut pas prouver.
            ->when($ville !== null && $this->siteFiltre === '', fn ($ou) => $ou
                ->orWhere(fn ($sansAtelier) => $sansAtelier->whereNull('factures.site_id')->where('factures.ville_id', $ville))));
    }

    if ($this->activiteFiltre !== '') {
        $requete->where('factures.activite', $this->activiteFiltre);
    }

    if ($this->idsCommercialFiltre !== null) {
        $requete->whereIn('correspondances_factures.commercial_id', $this->idsCommercialFiltre);
    }

    if ($this->etatFiltre === 'en_cours') {
        $requete->whereNull('correspondances_factures.annulee_le');
    } elseif ($this->etatFiltre === 'annulees') {
        $requete->whereNotNull('correspondances_factures.annulee_le');
    }

    return $requete;
});

/** Par commercial : combien de factures, pour combien. Les annulées n'y comptent jamais. */
$parCommercial = computed(fn () => $this->filtrer()
    ->whereNull('correspondances_factures.annulee_le')
    ->join('commerciaux', 'commerciaux.id', '=', 'correspondances_factures.commercial_id')
    ->groupBy('correspondances_factures.commercial_id', 'commerciaux.nom')
    ->selectRaw('correspondances_factures.commercial_id, commerciaux.nom, count(*) as nombre, sum(factures.montant) as montant')
    ->orderByDesc('montant')
    ->get());

$total = computed(fn () => $this->filtrer()->count());
$pageAffichee = computed(fn () => min(max(1, (int) $this->page), max(1, (int) ceil($this->total / 25))));

$lignes = computed(fn () => $this->filtrer()
    ->select('correspondances_factures.*')
    ->with(['facture.site', 'facture.ville', 'commercial.utilisateur'])
    ->orderByDesc('correspondances_factures.created_at')
    ->orderByDesc('correspondances_factures.id')
    ->forPage($this->pageAffichee, 25)
    ->get());

$saisisseurs = computed(fn () => CorrespondancesDeFactures::saisisseurs($this->lignes->pluck('facture')->filter()));

$annuler = function (int $correspondance) {
    $this->message = '';
    $refus = CorrespondancesDeFactures::annuler(auth()->user(), $correspondance);
    unset($this->parCommercial, $this->total, $this->lignes, $this->saisisseurs, $this->pageAffichee);

    if ($refus !== null) {
        $this->erreur = $refus;

        return;
    }

    $this->erreur = '';
    $this->message = 'Affectation annulée : la facture est de nouveau proposée à tous les commerciaux.';
    $this->dispatch('annonce', texte: 'Affectation annulée.', ton: 'succes');
};

?>

<div>
    <x-titre-ecran titre="Les correspondances"
        sous-titre="Les factures que les commerciaux ont reconnues comme nées de leurs prospections. La période porte sur la date de la facture." />

    <div style="margin-bottom:14px;">
        <a href="{{ route('correspondances') }}" wire:navigate class="bouton bouton-secondaire"
            style="padding:9px 16px; text-decoration:none;">← Retour aux correspondances</a>
    </div>

    <x-filtre-periode :periode="$periode" :date-debut="$dateDebut" :date-fin="$dateFin" :villes="$this->mesVilles" :ville-unique="$this->villeUnique"
        :ville-filtre="$villeFiltre" :sites="$this->mesSitesFiltre" :site-filtre="$siteFiltre" :activite-filtre="$activiteFiltre"
        :mois-filtre="$moisFiltre" :semaine-filtre="$semaineFiltre" :jour-filtre="$jourFiltre"
        :commerciaux="$this->commerciaux" :commercial-filtre="$commercialFiltre" />

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <x-champ label="État" model="etatFiltre" type="select" :live="true" width="200"
                :options="['en_cours' => 'En cours', 'annulees' => 'Annulées', 'toutes' => 'Toutes']" />
        </div>

        @if ($message !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
        @endif

        @if ($erreur !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:18px;">
        <x-kpi-card label="Factures affectées" :value="number_format((int) $this->parCommercial->sum('nombre'), 0, ',', ' ')" />
        <x-kpi-card label="Montant affecté" :value="ae((int) $this->parCommercial->sum('montant'))" />
        <x-kpi-card label="Commerciaux concernés" :value="$this->parCommercial->count()" />
    </div>

    @if ($this->parCommercial->isNotEmpty())
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Par commercial</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Commercial</th><th>Factures</th><th>Montant</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($this->parCommercial as $ligne)
                            <tr wire:key="par-commercial-{{ $ligne->commercial_id }}">
                                <td style="font-weight:600;">{{ $ligne->nom }}</td>
                                <td style="font-variant-numeric:tabular-nums;">{{ (int) $ligne->nombre }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ ae((int) $ligne->montant) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="carte">
        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Coché par</th>
                        <th>Coché le</th>
                        <th>Date</th>
                        <th>N° facture</th>
                        <th>Fiche de réception</th>
                        <th>Client</th>
                        <th>Montant</th>
                        <th>Atelier</th>
                        <th>Saisi par</th>
                        <th>Lieu du saisisseur</th>
                        <th>État</th>
                        <th class="colonne-collee"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lignes as $correspondance)
                        @php
                            $facture = $correspondance->facture;
                            $saisi = $this->saisisseurs[$facture?->id] ?? ['code' => null, 'nom' => '', 'lieu' => ''];
                        @endphp
                        <tr wire:key="suivi-{{ $correspondance->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="white-space:nowrap;">
                                <div style="font-weight:600;">{{ $correspondance->commercial?->nom ?? $correspondance->auteur }}</div>
                                <div style="font-size:11.5px; color:#6B6E76;">{{ $correspondance->commercial?->codeDeSaisie() }}</div>
                            </td>
                            <td style="white-space:nowrap;">{{ $correspondance->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $facture?->date?->format('d/m/Y') ?? '—' }}</td>
                            <td style="font-weight:700;">{{ $facture?->n_facture ?: $facture?->numero }}</td>
                            <td style="color:#6B6E76;">{{ $facture?->reference_devis ?: '—' }}</td>
                            <td>{{ $facture?->client }}</td>
                            <td style="font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae($facture?->montant ?? 0) }}</td>
                            <td>{{ $facture?->site?->nom ?? $facture?->ville?->nom ?? '—' }}</td>
                            <td style="white-space:nowrap;">
                                {{ $saisi['code'] ?? '—' }}
                                @if ($saisi['nom'] !== '')
                                    <span style="color:#6B6E76;">· {{ $saisi['nom'] }}</span>
                                @endif
                            </td>
                            <td>{{ $saisi['lieu'] !== '' ? $saisi['lieu'] : '—' }}</td>
                            <td>
                                @if ($correspondance->estAnnulee())
                                    <span style="color:#C8102E; font-weight:600; font-size:12px;">Annulée</span>
                                    <div style="font-size:11.5px; color:#6B6E76;">
                                        par {{ $correspondance->annulee_par_nom ?? '—' }}, le {{ $correspondance->annulee_le->format('d/m/Y') }}
                                    </div>
                                @else
                                    <span style="color:#1E7B34; font-weight:600; font-size:12px;">En cours</span>
                                @endif
                            </td>
                            <td class="colonne-collee" style="white-space:nowrap;">
                                @unless ($correspondance->estAnnulee())
                                    <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                        wire:click="annuler({{ $correspondance->id }})"
                                        data-confirmer="Annuler l'affectation de la facture {{ $facture?->n_facture ?: $facture?->numero }} ?"
                                        data-confirmer-detail="Elle ne sera plus comptée à {{ $correspondance->commercial?->nom }} et redeviendra visible de tous les commerciaux."
                                        data-confirmer-ton="alerte">Annuler</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="12"
                            texte="Aucune correspondance avec ces filtres. Élargissez la période : elle porte sur la date de la facture, pas sur celle de la coche." />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$this->pageAffichee" :total="$this->total" prop="page" :par-page="25" />
    </div>
</div>

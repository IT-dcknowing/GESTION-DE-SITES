<?php

use Modules\Noyau\Exploitation\Modeles\CorrespondanceFacture;
use Modules\Noyau\Exploitation\Services\CorrespondancesDeFactures;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Mes correspondances — les factures que j'ai cochées
|--------------------------------------------------------------------------
| Uniquement celles du compte connecté : la fiche est lue sur l'identité, jamais sur un
| paramètre. Les affectations qu'un responsable a annulées restent lisibles, marquées
| comme telles — sans quoi une facture disparaîtrait de cet écran sans que le commercial
| sache pourquoi, et il la cocherait de nouveau.
*/

state(['etatFiltre' => 'en_cours'])->url(except: 'en_cours');
state(['dateDebut' => ''])->url(except: '');
state(['dateFin' => ''])->url(except: '');
state(['recherche' => ''])->url(except: '');
state(['page' => 1]);

$updated = function (string $propriete) {
    if ($propriete !== 'page') {
        $this->page = 1;
    }
};

$fiche = computed(fn () => CorrespondancesDeFactures::ficheDe(auth()->user()));

$filtrer = protect(function () {
    $requete = CorrespondanceFacture::query()
        ->join('factures', 'factures.id', '=', 'correspondances_factures.facture_id')
        ->where('correspondances_factures.commercial_id', $this->fiche?->id ?? 0);

    if ($this->etatFiltre === 'en_cours') {
        $requete->whereNull('correspondances_factures.annulee_le');
    } elseif ($this->etatFiltre === 'annulees') {
        $requete->whereNotNull('correspondances_factures.annulee_le');
    }

    if ($this->dateDebut !== '') {
        $requete->whereDate('factures.date', '>=', $this->dateDebut);
    }

    if ($this->dateFin !== '') {
        $requete->whereDate('factures.date', '<=', $this->dateFin);
    }

    $mot = trim($this->recherche);

    if ($mot !== '') {
        $requete->where(fn ($q) => $q
            ->where('factures.n_facture', 'like', '%'.$mot.'%')
            ->orWhere('factures.reference_devis', 'like', '%'.$mot.'%')
            ->orWhere('factures.client', 'like', '%'.$mot.'%')
            ->orWhere('factures.immatriculation', 'like', '%'.$mot.'%'));
    }

    return $requete;
});

/** Ce qui m'est compté aujourd'hui — les annulées n'y entrent jamais, quel que soit le filtre. */
$totaux = computed(function () {
    $ligne = $this->filtrer()
        ->whereNull('correspondances_factures.annulee_le')
        ->selectRaw('count(*) as nombre, coalesce(sum(factures.montant), 0) as montant')
        ->first();

    return ['nombre' => (int) ($ligne->nombre ?? 0), 'montant' => (int) ($ligne->montant ?? 0)];
});

$total = computed(fn () => $this->filtrer()->count());
$pageAffichee = computed(fn () => min(max(1, (int) $this->page), max(1, (int) ceil($this->total / 25))));

$lignes = computed(fn () => $this->filtrer()
    ->select('correspondances_factures.*')
    ->with(['facture.site', 'facture.ville'])
    ->orderByDesc('correspondances_factures.created_at')
    ->orderByDesc('correspondances_factures.id')
    ->forPage($this->pageAffichee, 25)
    ->get());

$saisisseurs = computed(fn () => CorrespondancesDeFactures::saisisseurs($this->lignes->pluck('facture')->filter()));

?>

<div>
    <x-titre-ecran titre="Mes correspondances"
        sous-titre="Les factures que vous avez reconnues comme nées de vos prospections, et qui vous sont comptées." />

    <div style="margin-bottom:14px;">
        <a href="{{ route('correspondances') }}" wire:navigate class="bouton bouton-secondaire"
            style="padding:9px 16px; text-decoration:none;">← Retour aux correspondances</a>
    </div>

    @if (! $this->fiche)
        <div class="carte">
            <p style="margin:0; font-size:13.5px; color:#B45309;">
                Votre compte ne porte aucune fiche commerciale : aucune facture ne peut vous être comptée.
            </p>
        </div>
    @else
        <div class="carte" style="margin-bottom:16px;">
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <x-champ label="Rechercher" model="recherche" :live="true" width="220"
                    placeholder="N° facture, fiche, client, plaque" />
                <x-champ label="Du" model="dateDebut" type="date" :live="true" width="150" />
                <x-champ label="Au" model="dateFin" type="date" :live="true" width="150" />
                <x-champ label="État" model="etatFiltre" type="select" :live="true" width="190"
                    :options="['en_cours' => 'Qui me sont comptées', 'annulees' => 'Annulées par un responsable', 'toutes' => 'Toutes']" />
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:18px;">
            <x-kpi-card label="Factures qui me sont comptées" :value="number_format($this->totaux['nombre'], 0, ',', ' ')" />
            <x-kpi-card label="Montant" :value="ae($this->totaux['montant'])" />
        </div>

        <div class="carte">
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Coché le</th>
                            <th>Date</th>
                            <th>N° facture</th>
                            <th>Fiche de réception</th>
                            <th>Client</th>
                            <th>Véhicule</th>
                            <th>Montant</th>
                            <th>Atelier</th>
                            <th>Saisi par</th>
                            <th>État</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lignes as $correspondance)
                            @php
                                $facture = $correspondance->facture;
                                $saisi = $this->saisisseurs[$facture?->id] ?? ['code' => null, 'nom' => '', 'lieu' => ''];
                            @endphp
                            <tr wire:key="correspondance-{{ $correspondance->id }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:nowrap;">{{ $correspondance->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $facture?->date?->format('d/m/Y') ?? '—' }}</td>
                                <td style="font-weight:700;">{{ $facture?->n_facture ?: $facture?->numero }}</td>
                                <td style="color:#6B6E76;">{{ $facture?->reference_devis ?: '—' }}</td>
                                <td>{{ $facture?->client }}</td>
                                <td>
                                    {{ $facture?->vehicule ?: '—' }}
                                    @if ($facture?->immatriculation)
                                        <span style="color:#6B6E76; font-size:11.5px;">· {{ $facture->immatriculation }}</span>
                                    @endif
                                </td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap;">{{ ae($facture?->montant ?? 0) }}</td>
                                <td>{{ $facture?->site?->nom ?? $facture?->ville?->nom ?? '—' }}</td>
                                <td style="white-space:nowrap;">
                                    {{ $saisi['code'] ?? '—' }}
                                    @if ($saisi['nom'] !== '')
                                        <span style="color:#6B6E76;">· {{ $saisi['nom'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($correspondance->estAnnulee())
                                        <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600; background:#FDE8EA; color:#C8102E;">
                                            Annulée
                                        </span>
                                        <div style="font-size:11.5px; color:#6B6E76; margin-top:2px;">
                                            par {{ $correspondance->annulee_par_nom ?? 'un responsable' }}, le {{ $correspondance->annulee_le->format('d/m/Y') }}
                                            @if ($correspondance->motif_annulation)
                                                — {{ $correspondance->motif_annulation }}
                                            @endif
                                        </div>
                                    @else
                                        <span style="display:inline-block; padding:2px 8px; border-radius:20px; font-size:11.5px; font-weight:600; background:#E5F2E8; color:#1E7B34;">
                                            Comptée
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="10"
                                texte="Aucune facture cochée avec ces filtres. Les factures se cochent depuis l'écran Correspondances." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :page="$this->pageAffichee" :total="$this->total" prop="page" :par-page="25" />
        </div>
    @endif
</div>

<?php

use Illuminate\Support\Carbon;
use Modules\Noyau\Exploitation\Modeles\BaremeCommission;
use Modules\Noyau\Exploitation\Modeles\TrancheBareme;
use Modules\Noyau\Exploitation\Services\CommissionCommerciale;

use function Livewire\Volt\{computed, protect, state};

/*
|--------------------------------------------------------------------------
| Barème de commission — la grille, et le jour où elle prend effet
|--------------------------------------------------------------------------
| **Réservé au gérant.** Un barème décide de ce que quelqu'un touche à la fin du mois ;
| il n'appartient ni au commercial qu'il rémunère, ni au responsable qui l'anime.
|
| **Un barème ne se modifie pas, il se remplace.** Corriger les taux d'une grille en
| vigueur réécrirait des commissions déjà annoncées. On pose une nouvelle grille avec une
| nouvelle date d'effet ; l'ancienne continue de répondre pour les mois qu'elle a couverts.
| Les tranches d'une grille restent modifiables tant qu'on la construit — c'est la date
| d'effet qui protège le passé, pas l'interdiction de corriger une faute de frappe.
|
| **La grille du document est proposée, jamais posée d'office.** Elle s'installe d'un clic,
| par quelqu'un qui l'a lue. Le PDF laisse trois questions ouvertes (seuil d'entrée, trous
| entre tranches, bornes qui se chevauchent) : la proposition les tranche d'une façon dite à
| l'écran, et le gérant corrige ce qu'il veut, ligne par ligne.
*/

state(['cible' => 'commercial'])->url(except: 'commercial');
state(['baremeOuvert' => null]);
state(['message' => '']);
state(['erreur' => '']);

// La grille qu'on pose ou qu'on crée.
state(['dateEffet' => null]);
state(['libelle' => '']);
state(['assiette' => 'global']);

// La tranche qu'on ajoute, et celle qu'on modifie.
state(['plancher' => '']);
state(['plafond' => '']);
state(['taux' => '']);
state(['trancheEnModification' => null]);

// Le simulateur : un chiffre d'affaires, et ce qu'il produit.
state(['simulation' => '']);

$baremes = computed(fn () => BaremeCommission::query()
    ->where('entreprise_id', auth()->user()->entreprise_id)
    ->where('cible', $this->cible)
    ->with('tranches')
    ->orderByDesc('date_effet')
    ->orderByDesc('id')
    ->get());

/** La grille en vigueur aujourd'hui — celle qui répond quand on calcule une commission. */
$enVigueur = computed(fn () => CommissionCommerciale::grilleEnVigueur(
    auth()->user()->entreprise_id, $this->cible,
));

/**
 * La grille dépliée, relue dans l'entreprise du lecteur.
 *
 * L'identifiant vient du navigateur : il n'est jamais cru sur parole. Une grille d'une
 * autre entreprise ne s'ouvre pas, même en forgeant le numéro.
 */
$grille = computed(function () {
    if ($this->baremeOuvert === null) {
        return null;
    }

    return BaremeCommission::where('entreprise_id', auth()->user()->entreprise_id)
        ->with('tranches')
        ->find($this->baremeOuvert);
});

$anomalies = computed(fn () => $this->grille ? CommissionCommerciale::anomalies($this->grille) : []);

/** Ce que la grille dépliée ferait d'un chiffre d'affaires donné. */
$essai = computed(function () {
    $ca = (int) preg_replace('/\D/', '', (string) $this->simulation);

    if ($ca <= 0 || ! $this->grille) {
        return null;
    }

    return [
        'ca' => $ca,
        'taux' => CommissionCommerciale::taux($this->grille, $ca),
        'commission' => CommissionCommerciale::commission($this->grille, $ca),
    ];
});

$oublier = protect(function () {
    unset($this->baremes, $this->enVigueur, $this->grille, $this->anomalies, $this->essai);
});

$updatedCible = function () {
    $this->baremeOuvert = null;
    $this->message = '';
    $this->erreur = '';
    $this->oublier();
};

$ouvrir = function (int $id) {
    $this->baremeOuvert = $this->baremeOuvert === $id ? null : $id;
    $this->trancheEnModification = null;
    $this->reset(['plancher', 'plafond', 'taux', 'message', 'erreur']);
    $this->oublier();
};

/** Poser la grille du document, telle qu'elle est proposée à l'écran. */
$poserLaGrilleDuDocument = function () {
    $jour = $this->dateEffet
        ? Carbon::parse($this->dateEffet)
        : Carbon::today()->startOfMonth();

    $bareme = CommissionCommerciale::poserLaGrilleDuDocument(auth()->user(), $this->cible, $jour);

    $this->oublier();

    if ($bareme === null) {
        $this->erreur = 'Une grille de cette catégorie prend déjà effet le '.$jour->format('d/m/Y').
            ' — choisissez un autre jour, ou modifiez celle qui existe.';

        return;
    }

    $this->baremeOuvert = $bareme->id;
    $this->message = 'Grille du document posée, effet au '.$jour->format('d/m/Y').
        '. Relisez ses tranches : le document en laisse trois à votre arbitrage.';
};

/** Une grille vide, à remplir tranche par tranche. */
$creerUneGrille = function () {
    $donnees = $this->validate([
        'libelle' => ['required', 'string', 'max:160'],
        'dateEffet' => ['required', 'date'],
        'assiette' => ['required', 'in:global,tranche'],
    ], [], ['libelle' => 'nom de la grille', 'dateEffet' => "date d'effet"]);

    $jour = Carbon::parse($donnees['dateEffet']);

    $dejaLa = BaremeCommission::where('entreprise_id', auth()->user()->entreprise_id)
        ->where('cible', $this->cible)
        ->whereDate('date_effet', $jour->toDateString())
        ->exists();

    if ($dejaLa) {
        $this->erreur = 'Une grille de cette catégorie prend déjà effet le '.$jour->format('d/m/Y').'.';

        return;
    }

    $bareme = BaremeCommission::create([
        'entreprise_id' => auth()->user()->entreprise_id,
        'cible' => $this->cible,
        'libelle' => $donnees['libelle'],
        'date_effet' => $jour->toDateString(),
        'assiette' => $donnees['assiette'],
        'cree_par' => auth()->id(),
        'auteur' => auth()->user()->name,
    ]);

    $this->reset(['libelle', 'dateEffet']);
    $this->baremeOuvert = $bareme->id;
    $this->oublier();
    $this->message = 'Grille créée. Ajoutez ses tranches ci-dessous.';
};

/** Ajouter une tranche à la grille dépliée, ou enregistrer celle qu'on modifiait. */
$enregistrerLaTranche = function () {
    $grille = $this->grille;

    if (! $grille) {
        $this->erreur = 'Ouvrez une grille avant d\'y ajouter une tranche.';

        return;
    }

    $donnees = $this->validate([
        'plancher' => ['required', 'numeric', 'min:0'],
        'plafond' => ['nullable', 'numeric', 'gt:plancher'],
        'taux' => ['required', 'numeric', 'min:0', 'max:100'],
    ], [], ['plancher' => 'plancher', 'plafond' => 'plafond', 'taux' => 'taux']);

    $valeurs = [
        'plancher' => (int) $donnees['plancher'],
        'plafond' => $donnees['plafond'] === '' || $donnees['plafond'] === null ? null : (int) $donnees['plafond'],
        'taux' => (float) $donnees['taux'],
    ];

    if ($this->trancheEnModification !== null) {
        // Relue dans la grille dépliée, et non par son seul identifiant.
        $tranche = $grille->tranches()->find($this->trancheEnModification);

        if (! $tranche) {
            $this->erreur = "Cette tranche n'appartient pas à la grille ouverte.";

            return;
        }

        $tranche->update($valeurs);
        $this->message = 'Tranche modifiée.';
    } else {
        $grille->tranches()->create($valeurs + ['ordre' => $grille->tranches()->count()]);
        $this->message = 'Tranche ajoutée.';
    }

    $this->trancheEnModification = null;
    $this->reset(['plancher', 'plafond', 'taux']);
    $this->oublier();
};

$modifierLaTranche = function (int $id) {
    $tranche = $this->grille?->tranches()->find($id);

    if (! $tranche) {
        return;
    }

    $this->trancheEnModification = $tranche->id;
    $this->plancher = (string) $tranche->plancher;
    $this->plafond = $tranche->plafond === null ? '' : (string) $tranche->plafond;
    $this->taux = (string) $tranche->taux;
};

$annulerLaTranche = function () {
    $this->trancheEnModification = null;
    $this->reset(['plancher', 'plafond', 'taux']);
    $this->resetValidation();
};

$supprimerLaTranche = function (int $id) {
    $tranche = $this->grille?->tranches()->find($id);

    if (! $tranche) {
        return;
    }

    $tranche->delete();
    $this->trancheEnModification = null;
    $this->oublier();
    $this->message = 'Tranche retirée.';
};

?>

<div>
    <x-titre-ecran titre="Barème de commission"
        sous-titre="La grille qui décide de la commission d'un mois, et le jour à partir duquel elle s'applique. Une grille posée ne réécrit jamais les mois qu'une autre a couverts." />

    <div class="carte" style="margin-bottom:16px;">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            @foreach (\Modules\Noyau\Exploitation\Modeles\BaremeCommission::CIBLES as $cle => $libelleCible)
                <button type="button" wire:click="$set('cible', '{{ $cle }}')"
                    class="onglet {{ $cible === $cle ? 'est-actif' : '' }}">{{ $libelleCible }}</button>
            @endforeach
        </div>

        @if ($message !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
        @endif

        @if ($erreur !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
        @endif
    </div>

    {{-- Ce qui répond aujourd'hui quand on calcule une commission. Affiché en tête parce
         que c'est la seule question qu'on se pose en arrivant ici. --}}
    <div class="carte" style="margin-bottom:16px;">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">En vigueur aujourd'hui</h3>

        @if ($this->enVigueur)
            <p style="margin:0 0 10px; font-size:13.5px;">
                <b>{{ $this->enVigueur->libelle }}</b> — effet au
                {{ $this->enVigueur->date_effet->format('d/m/Y') }},
                {{ \Modules\Noyau\Exploitation\Modeles\BaremeCommission::ASSIETTES[$this->enVigueur->assiette] ?? $this->enVigueur->assiette }}.
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead><tr><th>Tranche de chiffre d'affaires mensuel</th><th>Taux</th><th>Commission à la borne basse</th></tr></thead>
                    <tbody>
                        @foreach ($this->enVigueur->tranches as $tranche)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ \Modules\Noyau\Exploitation\Services\CommissionCommerciale::libelleTranche($tranche->plancher, $tranche->plafond) }}</td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700;">{{ rtrim(rtrim(number_format($tranche->taux, 2, ',', ' '), '0'), ',') }} %</td>
                                <td style="font-variant-numeric:tabular-nums;">
                                    {{ ae(\Modules\Noyau\Exploitation\Services\CommissionCommerciale::commission($this->enVigueur, (int) $tranche->plancher) ?? 0) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p style="margin:0; font-size:13.5px; color:#6B6E76;">
                Aucune grille n'est en vigueur pour cette catégorie : la colonne « Commission »
                de l'écran Commerciaux restera vide tant qu'il n'y en aura pas. Posez la grille
                du document ci-dessous, ou construisez la vôtre.
            </p>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:16px; margin-bottom:16px;">
        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">Poser la grille du document</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                Reprise de <i>Commission_Commerciaux_Artisan (1)_vf6.pdf</i>. Le document laisse
                trois points à votre arbitrage, tranchés ici d'une façon que vous pouvez
                corriger tranche par tranche :
                <b>l'entrée à 20 M</b> (le texte annonce 25 M, la grille et sa commission
                indicative de 200 000 F disent 20 M) ; <b>les tranches rendues jointives</b>
                (le document écrit 25 M puis 26 M, laissant 25,5 M sans taux) ; et
                <b>le plancher atteint, le plafond exclu</b>, qui départage les bornes que le
                document fait appartenir à deux tranches.
            </p>

            <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <x-champ label="Effet à partir du" model="dateEffet" type="date" width="170"
                    aide="Par défaut, le 1er du mois en cours" />
                <button type="button" wire:click="poserLaGrilleDuDocument" class="bouton bouton-sombre">
                    Poser cette grille
                </button>
            </div>
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 6px;">Construire une grille</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76;">
                Une grille vide, à remplir tranche par tranche. C'est la voie à prendre pour
                corriger un barème : on n'en modifie pas un qui a déjà servi, on en pose un
                nouveau avec sa date d'effet.
            </p>

            <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <x-champ label="Nom de la grille" model="libelle" placeholder="Grille 2027" />
                <x-champ label="Effet à partir du" model="dateEffet" type="date" width="160" />
                <x-champ label="Assiette" model="assiette" type="select" width="260"
                    :options="\Modules\Noyau\Exploitation\Modeles\BaremeCommission::ASSIETTES" />
                <button type="button" wire:click="creerUneGrille" class="bouton">Créer</button>
            </div>
        </div>
    </div>

    <div class="carte">
        <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
            Grilles de « {{ \Modules\Noyau\Exploitation\Modeles\BaremeCommission::CIBLES[$cible] ?? $cible }} »
            ({{ $this->baremes->count() }})
        </h3>

        <div class="tableau-conteneur">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Grille</th>
                        <th>Effet à partir du</th>
                        <th>Assiette</th>
                        <th>Tranches</th>
                        <th>Posée par</th>
                        <th class="colonne-collee"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->baremes as $bareme)
                        <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                            <td style="font-weight:700;">{{ $bareme->libelle }}</td>
                            <td>{{ $bareme->date_effet->format('d/m/Y') }}</td>
                            <td style="color:#6B6E76;">{{ \Modules\Noyau\Exploitation\Modeles\BaremeCommission::ASSIETTES[$bareme->assiette] ?? $bareme->assiette }}</td>
                            <td style="font-variant-numeric:tabular-nums;">{{ $bareme->tranches->count() }}</td>
                            <td style="color:#6B6E76;">{{ $bareme->auteur ?? '—' }}</td>
                            <td class="colonne-collee" style="white-space:nowrap;">
                                <button type="button" wire:click="ouvrir({{ $bareme->id }})"
                                    class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">
                                    {{ $baremeOuvert === $bareme->id ? 'Fermer' : 'Ouvrir' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <x-table-vide :colspan="6"
                            texte="Aucune grille pour cette catégorie. Posez celle du document, ou construisez la vôtre." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($this->grille)
        <div class="carte" style="margin-top:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">{{ $this->grille->libelle }}</h3>
            <p style="margin:0 0 14px; font-size:12.5px; color:#6B6E76;">
                Effet au {{ $this->grille->date_effet->format('d/m/Y') }} —
                {{ \Modules\Noyau\Exploitation\Modeles\BaremeCommission::ASSIETTES[$this->grille->assiette] ?? $this->grille->assiette }}.
            </p>

            {{-- Une grille trouée ne fait pas tomber le calcul : elle le rend faux sans le
                 dire. On préfère le dire. --}}
            @if ($this->anomalies !== [])
                <div style="background:#FDF3E3; border:1px solid #F0D9A8; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                    <b style="font-size:13px; color:#B45309;">À revoir dans cette grille</b>
                    <ul style="margin:6px 0 0; padding-left:18px; font-size:12.5px; color:#7C4A08;">
                        @foreach ($this->anomalies as $anomalie)
                            <li>{{ $anomalie }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th>Tranche</th>
                            <th>Plancher (atteint)</th>
                            <th>Plafond (exclu)</th>
                            <th>Taux</th>
                            <th class="colonne-collee"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->grille->tranches as $tranche)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td>{{ \Modules\Noyau\Exploitation\Services\CommissionCommerciale::libelleTranche($tranche->plancher, $tranche->plafond) }}</td>
                                <td style="font-variant-numeric:tabular-nums;">{{ ae($tranche->plancher) }}</td>
                                <td style="font-variant-numeric:tabular-nums;">
                                    {{ $tranche->plafond === null ? 'et au-delà' : ae($tranche->plafond) }}
                                </td>
                                <td style="font-variant-numeric:tabular-nums; font-weight:700;">
                                    {{ rtrim(rtrim(number_format($tranche->taux, 2, ',', ' '), '0'), ',') }} %
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" wire:click="modifierLaTranche({{ $tranche->id }})"
                                        class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">Modifier</button>
                                    <button type="button" wire:click="supprimerLaTranche({{ $tranche->id }})"
                                        class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;">Retirer</button>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="5" texte="Cette grille n'a encore aucune tranche." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:14px;">
                <x-champ label="Plancher (F, atteint)" model="plancher" type="number" width="180" />
                <x-champ label="Plafond (F, exclu)" model="plafond" type="number" width="180"
                    aide="Vide = et au-delà" />
                <x-champ label="Taux (%)" model="taux" type="number" width="120" />
                <button type="button" wire:click="enregistrerLaTranche" class="bouton bouton-sombre">
                    {{ $trancheEnModification ? 'Enregistrer la tranche' : 'Ajouter la tranche' }}
                </button>
                @if ($trancheEnModification)
                    <button type="button" wire:click="annulerLaTranche" class="bouton bouton-secondaire">Annuler</button>
                @endif
            </div>

            {{-- Un barème ne se relit pas, il s'essaie : on tape un chiffre d'affaires et
                 l'on voit ce qu'il produit, avant de l'appliquer à quelqu'un. --}}
            <div style="margin-top:18px; border-top:1px solid var(--th-ligne,#E2E0D8); padding-top:14px;">
                <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                    <x-champ label="Essayer avec un chiffre d'affaires de" model="simulation"
                        type="number" width="220" :live="true" placeholder="32000000" />
                </div>

                @if ($this->essai)
                    <p style="margin:10px 0 0; font-size:13.5px;">
                        @if ($this->essai['taux'] === null)
                            <span style="color:#C8102E; font-weight:600;">
                                {{ ae($this->essai['ca']) }} ne tombe dans aucune tranche de cette grille.
                            </span>
                            Ce n'est pas zéro : c'est qu'aucune règle ne dit quoi en faire.
                        @else
                            {{ ae($this->essai['ca']) }} →
                            <b>{{ rtrim(rtrim(number_format($this->essai['taux'], 2, ',', ' '), '0'), ',') }} %</b>
                            → <b style="color:#1E7B34;">{{ ae($this->essai['commission']) }}</b> de commission.
                        @endif
                    </p>
                @endif
            </div>
        </div>
    @endif
</div>

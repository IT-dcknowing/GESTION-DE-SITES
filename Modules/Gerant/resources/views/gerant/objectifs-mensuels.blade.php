<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Noyau\Entreprises\Modeles\Ville;
use Modules\Noyau\Exploitation\Modeles\ObjectifEntreprise;
use Modules\Noyau\Exploitation\Modeles\TrancheObjectif;
use Modules\Noyau\Exploitation\Services\ObjectifsDeLEntreprise;
use function Livewire\Volt\{state, computed, mount};

/*
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * Les objectifs mensuels de l'entreprise — 14/10.
 *
 * « L'entreprise a plusieurs tranches d'objectif : configurer les objectifs mensuels qui ont
 * été établis ; le système les applique en fonction de leur période (date de début et de fin). »
 *
 * Ici on les pose, on les corrige, on les retire. Le tableau de bord les lit par
 * `ObjectifsDeLEntreprise`, qui dit lequel s'applique à quel jour. Le gérant seul, comme le
 * barème de commission : fixer ce que l'entreprise doit atteindre est une décision de direction.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
state([
    'editionId' => null,
    'libelle' => '',
    'dateDebut' => '',
    'dateFin' => '',
    'villeId' => '',
    'activite' => '',
    'tranches' => [],
    'aSupprimer' => null,
    'erreur' => '',
]);

mount(function () {
    $this->vider();
});

$disponible = computed(fn () => ObjectifsDeLEntreprise::disponible());

$objectifs = computed(fn () => ! $this->disponible ? collect() : ObjectifEntreprise::query()
    ->with(['tranches', 'ville'])
    ->orderByDesc('date_debut')->orderByDesc('id')
    ->get());

$villes = computed(fn () => Ville::query()->orderBy('nom')->pluck('nom', 'id')->all());

$vider = function () {
    $this->editionId = null;
    $this->libelle = '';
    $this->dateDebut = now()->startOfMonth()->toDateString();
    $this->dateFin = now()->endOfYear()->toDateString();
    $this->villeId = '';
    $this->activite = '';
    // Trois tranches proposées, à renommer ou retirer : c'est le cas le plus courant.
    $this->tranches = [
        ['libelle' => 'Seuil', 'montant' => ''],
        ['libelle' => 'Objectif', 'montant' => ''],
        ['libelle' => 'Excellence', 'montant' => ''],
    ];
    $this->erreur = '';
    $this->resetErrorBag();
};

$ajouterUneTranche = function () {
    $this->tranches[] = ['libelle' => 'Tranche '.(count($this->tranches) + 1), 'montant' => ''];
};

$retirerUneTranche = function (int $rang) {
    unset($this->tranches[$rang]);
    $this->tranches = array_values($this->tranches);
};

$modifier = function (int $id) {
    $objectif = ObjectifEntreprise::query()->with('tranches')->findOrFail($id);

    $this->editionId = $objectif->id;
    $this->libelle = $objectif->libelle;
    $this->dateDebut = $objectif->date_debut->toDateString();
    $this->dateFin = $objectif->date_fin->toDateString();
    $this->villeId = $objectif->ville_id ? (string) $objectif->ville_id : '';
    $this->activite = (string) $objectif->activite;
    $this->tranches = $objectif->tranches->map(fn (TrancheObjectif $t) => ['libelle' => $t->libelle, 'montant' => montant_nombre($t->montant)])->all();
    $this->erreur = '';
};

/**
 * Enregistre l'objectif et ses tranches. Tout est relu ici : la ville doit être de l'entreprise,
 * l'objectif modifié aussi (portée globale du modèle), et le montant se lit avec le même lecteur
 * que les imports — « 150 000 000 » et « 150000000,5 » sont deux écritures valables.
 */
$enregistrer = function () {
    abort_unless(auth()->user()->hasRole('gerant'), 403);

    $this->erreur = '';
    $this->validate([
        'libelle' => ['required', 'string', 'max:120'],
        'dateDebut' => ['required', 'date'],
        'dateFin' => ['required', 'date'],
        'villeId' => ['nullable', \Illuminate\Validation\Rule::in(array_map('strval', array_keys($this->villes)))],
        'activite' => ['nullable', \Illuminate\Validation\Rule::in(ObjectifsDeLEntreprise::ACTIVITES)],
        'tranches' => ['required', 'array', 'min:1'],
        'tranches.*.libelle' => ['required', 'string', 'max:80'],
        'tranches.*.montant' => ['required'],
    ], [], ['libelle' => 'libellé', 'dateDebut' => 'date de début', 'dateFin' => 'date de fin', 'tranches.*.libelle' => 'nom de la tranche', 'tranches.*.montant' => 'montant']);

    $tranches = [];

    foreach ($this->tranches as $tranche) {
        $montant = \Modules\Noyau\Imports\Formats\Format::montant((string) $tranche['montant']);

        if ($montant === null || $montant <= 0) {
            $this->erreur = '« '.$tranche['libelle'].' » : le montant n\'est pas un nombre positif.';

            return;
        }

        $tranches[] = ['libelle' => trim($tranche['libelle']), 'montant' => montant_de($montant)];
    }

    $villeId = $this->villeId === '' ? null : (int) $this->villeId;
    $activite = $this->activite === '' ? null : $this->activite;
    $debut = Carbon::parse($this->dateDebut)->startOfDay();
    $fin = Carbon::parse($this->dateFin)->startOfDay();
    $entreprise = (int) auth()->user()->entreprise_id;

    if ($this->editionId !== null) {
        // Relu sous l'entreprise du lecteur : un identifiant venu du navigateur ne suffit pas.
        ObjectifEntreprise::query()->findOrFail($this->editionId);
    }

    try {
        (new ObjectifsDeLEntreprise($entreprise))->verifier($debut, $fin, $villeId, $activite, $tranches, $this->editionId);
    } catch (\RuntimeException $refus) {
        $this->erreur = $refus->getMessage();

        return;
    }

    DB::transaction(function () use ($debut, $fin, $villeId, $activite, $tranches) {
        $objectif = $this->editionId !== null
            ? ObjectifEntreprise::query()->findOrFail($this->editionId)
            : new ObjectifEntreprise(['cree_par' => auth()->id()]);

        $objectif->fill([
            'libelle' => trim($this->libelle), 'date_debut' => $debut->toDateString(), 'date_fin' => $fin->toDateString(),
            'ville_id' => $villeId, 'activite' => $activite,
        ])->save();

        $objectif->tranches()->delete();

        foreach ($tranches as $rang => $tranche) {
            $objectif->tranches()->create(['rang' => $rang + 1, 'libelle' => $tranche['libelle'], 'montant' => $tranche['montant']]);
        }
    });

    $texte = $this->editionId !== null ? 'Objectif mis à jour.' : 'Objectif enregistré.';
    $this->vider();
    unset($this->objectifs);
    $this->dispatch('annonce', ton: 'succes', texte: $texte);
};

/** Pas de dialogue du navigateur (règle 6) : un premier clic demande, le second supprime. */
$demanderLaSuppression = function (int $id) {
    $this->aSupprimer = $id;
};

$supprimer = function (int $id) {
    abort_unless(auth()->user()->hasRole('gerant'), 403);

    if ($this->aSupprimer !== $id) {
        return;
    }

    ObjectifEntreprise::query()->findOrFail($id)->delete();
    $this->aSupprimer = null;
    unset($this->objectifs);
    $this->dispatch('annonce', ton: 'succes', texte: 'Objectif retiré.');
};

?>

<div>
    <x-titre-ecran titre="Objectifs mensuels de l'entreprise"
        sous-titre="Les tranches à atteindre chaque mois, et la période pendant laquelle elles valent." />

    @if (! $this->disponible)
        <div class="carte" style="border-left:4px solid #C8102E;">
            La table des objectifs n'existe pas encore sur ce serveur : lancez <code>php artisan app:deployer</code>.
        </div>
    @else
        <div class="carte" style="margin-bottom:16px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;">{{ $editionId ? 'Modifier l\'objectif' : 'Nouvel objectif' }}</h3>
            <p style="margin:0 0 12px; font-size:12.5px; color:#6B6E76; line-height:1.5;">
                Les montants sont <b>mensuels</b>. Sur une période plus courte qu'un mois, le tableau de bord compte
                chaque jour pour sa part du mois. Deux objectifs de même portée ne peuvent pas se recouvrir :
                pour changer de tranches au 1<sup>er</sup> juillet, terminez le premier au 30 juin.
            </p>

            <div class="bloc-saisie" style="background:#fff; border-style:solid;">
                <x-champ label="Libellé" model="libelle" :requis="true" width="260" placeholder="Objectifs 2026 — second semestre" />
                <x-champ label="Du" model="dateDebut" type="date" :requis="true" width="160" />
                <x-champ label="Au" model="dateFin" type="date" :requis="true" width="160" />
                <x-champ label="Ville" model="villeId" type="select" width="200" :options="$this->villes" vide="Toute l'entreprise" />
                <x-champ label="Activité" model="activite" type="select" width="170" :options="array_combine(\Modules\Noyau\Exploitation\Services\ObjectifsDeLEntreprise::ACTIVITES, \Modules\Noyau\Exploitation\Services\ObjectifsDeLEntreprise::ACTIVITES)" vide="Toutes" />
            </div>

            <h4 style="font-size:13.5px; font-weight:700; margin:14px 0 6px;">Tranches (par mois, en croissant)</h4>
            @foreach ($tranches as $rang => $tranche)
                <div style="display:flex; gap:8px; align-items:flex-end; margin-bottom:6px;" wire:key="tranche-{{ $rang }}">
                    <span style="width:22px; color:#6B6E76; padding-bottom:9px;">{{ $rang + 1 }}.</span>
                    <x-champ label="Nom" model="tranches.{{ $rang }}.libelle" width="200" />
                    <x-champ label="Montant mensuel (F)" model="tranches.{{ $rang }}.montant" width="220" placeholder="150 000 000" />
                    @if (count($tranches) > 1)
                        <button type="button" wire:click="retirerUneTranche({{ $rang }})" class="bouton bouton-secondaire" style="padding:8px 12px;">Retirer</button>
                    @endif
                </div>
            @endforeach
            <button type="button" wire:click="ajouterUneTranche" class="bouton bouton-secondaire" style="padding:7px 12px; margin-top:4px;">+ Ajouter une tranche</button>

            @if ($erreur)
                <div style="margin-top:10px; color:#C8102E; font-weight:600;">{{ $erreur }}</div>
            @endif
            @if ($errors->any())
                <div style="margin-top:10px; color:#C8102E;">@foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach</div>
            @endif

            <div style="display:flex; gap:8px; margin-top:14px;">
                <button type="button" wire:click="enregistrer" class="bouton">{{ $editionId ? 'Enregistrer les modifications' : 'Enregistrer l\'objectif' }}</button>
                @if ($editionId)
                    <button type="button" wire:click="vider" class="bouton bouton-secondaire">Annuler</button>
                @endif
            </div>
        </div>

        <div class="carte">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 10px;">Objectifs établis ({{ $this->objectifs->count() }})</h3>
            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr><th>Libellé</th><th>Période</th><th>Portée</th><th>Tranches mensuelles</th><th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->objectifs as $o)
                            <tr style="border-bottom:1px solid var(--th-ligne,#E2E0D8); vertical-align:top;">
                                <td style="font-weight:700;">{{ $o->libelle }}</td>
                                <td style="white-space:nowrap;">{{ $o->date_debut->format('d/m/Y') }} → {{ $o->date_fin->format('d/m/Y') }}</td>
                                <td>{{ $o->portee() }}</td>
                                <td style="font-size:12.5px;">
                                    @foreach ($o->tranches as $t)<div>{{ $t->libelle }} : <b>{{ ae($t->montant) }}</b></div>@endforeach
                                </td>
                                <td style="font-size:12px; font-weight:700; color:{{ $o->etat() === 'En cours' ? '#0E9F6E' : '#6B6E76' }};">{{ $o->etat() }}</td>
                                <td style="white-space:nowrap;">
                                    <button type="button" wire:click="modifier({{ $o->id }})" class="bouton bouton-secondaire" style="padding:6px 10px;">Modifier</button>
                                    @if ($aSupprimer === $o->id)
                                        <button type="button" wire:click="supprimer({{ $o->id }})" class="bouton" style="padding:6px 10px; background:#C8102E;">Confirmer le retrait</button>
                                    @else
                                        <button type="button" wire:click="demanderLaSuppression({{ $o->id }})" class="bouton bouton-secondaire" style="padding:6px 10px;">Retirer</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="6" texte="Aucun objectif établi pour l'instant." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

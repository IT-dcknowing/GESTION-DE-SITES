<?php

use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;
use Modules\Noyau\Exploitation\Modeles\BaremeCommission;
use Modules\Noyau\Exploitation\Services\CommissionCommerciale;

use function Livewire\Volt\{computed, mount, protect, state};

/*
|--------------------------------------------------------------------------
| Barème de commission — les deux grilles, telles qu'elles sont au document
|--------------------------------------------------------------------------
| **Réservé au gérant.** Un barème décide de ce que quelqu'un touche à la fin du mois ; il
| n'appartient ni au commercial qu'il rémunère, ni au responsable qui l'anime.
|
| **Deux sections, deux grilles**, comme dans le document : celle des commerciaux, celle du
| responsable commercial et de son adjoint. Elles sont toujours visibles — tant que rien
| n'est enregistré pour l'exercice, on affiche la grille de référence, prête à être
| corrigée. L'écran dit laquelle des deux il montre.
|
| **Cloisonné par exercice.** Décidé par le propriétaire le 22/09/2026 : la commission
| s'applique par exercice ; une grille corrigée vaut aussitôt pour tout son exercice — les
| mois déjà passés compris — et ne touche à aucun autre.
|
| **Tout se modifie ici, rien n'est écrit dans le code** : les tranches, les taux, la façon
| de compter. Un changement enregistré agit dès l'affichage suivant de l'écran des
| commerciaux, sans cache et sans déploiement.
*/

state(['exercice' => null]);

// Les tranches affichées, section par section. Elles vivent dans l'état du composant le
// temps de la saisie : c'est ce qui permet d'ajouter une ligne, d'en corriger trois, et de
// n'enregistrer qu'une fois. Rien n'est écrit avant le clic sur « Enregistrer ».
state(['grilles' => []]);

// Le formulaire d'ajout, ouvert sous le tableau d'une section et d'une seule.
state(['ajoutOuvert' => null]);
state(['nouveauPlancher' => '']);
state(['nouveauPlafond' => '']);
state(['nouveauTaux' => '']);

// Les notes, repliées ; elles s'ouvrent section par section.
state(['notesOuvertes' => null]);

state(['message' => '']);
state(['erreur' => '']);

mount(function () {
    $this->exercice = ExerciceDeTravail::annee(auth()->user()) ?? now()->year;
    $this->chargerLesGrilles();
});

/** Recharge les deux grilles depuis la base — ou, à défaut, depuis le document. */
$chargerLesGrilles = protect(function () {
    $this->grilles = [];

    foreach (array_keys(BaremeCommission::CIBLES) as $cible) {
        $this->grilles[$cible] = CommissionCommerciale::grilleAMontrer(
            auth()->user()->entreprise_id, $cible, (int) $this->exercice,
        );
    }
});

/** Ce qui est réellement enregistré, par catégorie — pour dire si l'on montre le document. */
$enregistrees = computed(function () {
    $trouvees = [];

    foreach (array_keys(BaremeCommission::CIBLES) as $cible) {
        $trouvees[$cible] = CommissionCommerciale::grilleDeLaCible(
            auth()->user()->entreprise_id, $cible, (int) $this->exercice,
        );
    }

    return $trouvees;
});

$exercices = computed(function () {
    $annees = ExerciceDeTravail::disponibles(auth()->user()->entreprise_id)
        ->pluck('annee')->map(fn ($a) => (int) $a)->sortDesc()->values()->all();

    return $annees === [] ? [now()->year => now()->year] : array_combine($annees, $annees);
});

$updatedExercice = function () {
    $this->ajoutOuvert = null;
    $this->message = '';
    $this->erreur = '';
    unset($this->enregistrees);
    $this->chargerLesGrilles();
};

$ouvrirLesNotes = function (string $cible) {
    $this->notesOuvertes = $this->notesOuvertes === $cible ? null : $cible;
};

$ouvrirLAjout = function (string $cible) {
    $this->ajoutOuvert = $this->ajoutOuvert === $cible ? null : $cible;
    $this->reset(['nouveauPlancher', 'nouveauPlafond', 'nouveauTaux']);
    $this->resetValidation();
};

$annulerLAjout = function () {
    $this->ajoutOuvert = null;
    $this->reset(['nouveauPlancher', 'nouveauPlafond', 'nouveauTaux']);
    $this->resetValidation();
};

/** Valide la tranche saisie sous le tableau et l'y ajoute — sans rien enregistrer encore. */
$validerLAjout = function () {
    $cible = $this->ajoutOuvert;

    if (! isset($this->grilles[$cible])) {
        return;
    }

    $donnees = $this->validate([
        'nouveauPlancher' => ['required', 'numeric', 'min:0'],
        'nouveauPlafond' => ['nullable', 'numeric', 'gt:nouveauPlancher'],
        'nouveauTaux' => ['required', 'numeric', 'min:0', 'max:100'],
    ], [], [
        'nouveauPlancher' => 'plancher', 'nouveauPlafond' => 'plafond', 'nouveauTaux' => 'taux',
    ]);

    $lignes = $this->grilles[$cible];
    $lignes[] = [
        'plancher' => (int) $donnees['nouveauPlancher'],
        'plafond' => ($donnees['nouveauPlafond'] === '' || $donnees['nouveauPlafond'] === null)
            ? null
            : (int) $donnees['nouveauPlafond'],
        'taux' => (float) $donnees['nouveauTaux'],
    ];

    // Les tranches se lisent de la plus basse à la plus haute : on les y remet tout de
    // suite, plutôt que de laisser le tableau dans un ordre que personne n'a voulu.
    usort($lignes, fn ($a, $b) => $a['plancher'] <=> $b['plancher']);

    $this->grilles[$cible] = $lignes;
    $this->ajoutOuvert = null;
    $this->reset(['nouveauPlancher', 'nouveauPlafond', 'nouveauTaux']);
    $this->message = 'Tranche ajoutée au tableau. Cliquez « Enregistrer » pour la conserver.';
};

$retirerLaTranche = function (string $cible, int $rang) {
    if (! isset($this->grilles[$cible][$rang])) {
        return;
    }

    $lignes = $this->grilles[$cible];
    unset($lignes[$rang]);

    $this->grilles[$cible] = array_values($lignes);
    $this->message = 'Tranche retirée du tableau. Cliquez « Enregistrer » pour la conserver.';
};

/**
 * Enregistre la grille d'une section, en bloc.
 *
 * Les contrôles sont faits ici et non à la saisie de chaque case : on corrige un tableau,
 * pas une cellule, et refuser une valeur à mi-chemin empêcherait d'en réécrire deux.
 */
$enregistrerLaGrille = function (string $cible) {
    if (! isset($this->grilles[$cible])) {
        return;
    }

    $this->erreur = '';
    $lignes = [];

    foreach ($this->grilles[$cible] as $ligne) {
        $plancher = (int) ($ligne['plancher'] ?? 0);
        $plafond = ($ligne['plafond'] === null || $ligne['plafond'] === '') ? null : (int) $ligne['plafond'];
        $taux = (float) ($ligne['taux'] ?? 0);

        if ($plafond !== null && $plafond <= $plancher) {
            $this->erreur = "Une tranche dont le plafond n'est pas au-dessus du plancher ne veut rien dire.";

            return;
        }

        if ($taux < 0 || $taux > 100) {
            $this->erreur = 'Un taux se situe entre 0 et 100.';

            return;
        }

        $lignes[] = ['plancher' => $plancher, 'plafond' => $plafond, 'taux' => $taux];
    }

    if ($lignes === []) {
        $this->erreur = 'Une grille sans tranche ne calcule rien.';

        return;
    }

    usort($lignes, fn ($a, $b) => $a['plancher'] <=> $b['plancher']);

    CommissionCommerciale::enregistrer(auth()->user(), $cible, (int) $this->exercice, $lignes);

    unset($this->enregistrees);
    $this->chargerLesGrilles();

    $this->message = 'Grille « '.(BaremeCommission::CIBLES[$cible] ?? $cible)
        ."\u{a0}» enregistrée pour l'exercice ".$this->exercice
        .'. Elle s\'applique immédiatement à tout cet exercice.';
};

/**
 * Les notes du document, rendues à la section qu'elles concernent.
 *
 * Le propriétaire a relevé que les cinq phrases du document étaient mêlées et prêtaient à
 * confusion : deux d'entre elles ne valent que pour une seule grille, et le seuil qu'elles
 * annoncent n'est pas le même — 25 millions pour le responsable, 20 pour le commercial.
 * Elles sont donc rangées chacune sous sa section, et les deux phrases communes figurent
 * dans les deux.
 *
 * @return array<string, list<string>>
 */
$notes = computed(fn () => [
    'commercial' => [
        'À partir de 20 millions FCFA, une commission est versée avec un taux évolutif selon les tranches de CA.',
        'Le taux augmente progressivement de 1 % à 5 %, ce qui permet de récompenser davantage les performances les plus élevées.',
        "La commission estimée est calculée directement sur la tranche correspondante du chiffre d'affaires réalisé.",
    ],
    'responsable' => [
        "Aucune commission n'est appliquée pour un chiffre d'affaires inférieur à 25 millions FCFA.",
        'Le taux augmente progressivement de 1 % à 5 %, ce qui permet de récompenser davantage les performances les plus élevées.',
        "La commission estimée est calculée directement sur la tranche correspondante du chiffre d'affaires réalisé.",
    ],
]);

?>

<div>
    <x-titre-ecran titre="Barème de commission"
        sous-titre="Les deux grilles du document, pour l'exercice regardé. Une grille enregistrée s'applique aussitôt à tout son exercice, les mois déjà passés compris." />

    <div class="carte" style="margin-bottom:18px;">
        <div style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
            <x-champ label="Exercice" model="exercice" type="select" :options="$this->exercices" :live="true" width="140" />
            <p style="margin:0 0 9px; font-size:12.5px; color:#6B6E76; flex:1; min-width:260px;">
                Chaque exercice a ses grilles. Corriger celle de {{ $exercice }} ne touche à aucune autre année.
            </p>
        </div>

        @if ($message !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#1E7B34; font-weight:600;">{{ $message }}</p>
        @endif

        @if ($erreur !== '')
            <p style="margin:12px 0 0; font-size:13px; color:#C8102E; font-weight:600;">{{ $erreur }}</p>
        @endif
    </div>

    @foreach (\Modules\Noyau\Exploitation\Modeles\BaremeCommission::CIBLES as $cible => $libelleCible)
        @php
            $lignes = $this->grilles[$cible] ?? [];
            $estEnregistree = ($this->enregistrees[$cible] ?? null) !== null;
        @endphp

        <div class="carte" style="margin-bottom:22px;">
            {{-- Le titre rouge centré de la maquette, et la mention de la grille montrée. --}}
            <h2 style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:800;
                       color:#C8102E; text-align:center; margin:0 0 4px; letter-spacing:.01em;">
                Grille de commission* (CA mensuel)
            </h2>
            <p style="text-align:center; margin:0 0 16px; font-size:12.5px; color:#6B6E76;">
                @if ($estEnregistree)
                    Grille enregistrée pour l'exercice {{ $exercice }}.
                @else
                    {{-- On n'affiche jamais un écran vide : la grille de référence est là,
                         prête à être corrigée puis enregistrée. --}}
                    <b style="color:#B45309;">Grille de référence du document</b> — rien n'est encore
                    enregistré pour {{ $exercice }}. Corrigez-la si besoin, puis enregistrez.
                @endif
            </p>

            <div class="tableau-conteneur">
                <table class="tableau">
                    <thead>
                        <tr>
                            <th style="width:32%;">Tranche CA (FCFA)</th>
                            <th style="width:16%;">Taux</th>
                            <th>Commission estimée</th>
                            <th class="colonne-collee" style="width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lignes as $rang => $ligne)
                            <tr wire:key="{{ $cible }}-{{ $rang }}" style="border-bottom:1px solid var(--th-ligne,#E2E0D8);">
                                <td style="white-space:normal;">
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <input type="number" class="champ" style="width:112px;"
                                            wire:model.live.debounce.500ms="grilles.{{ $cible }}.{{ $rang }}.plancher">
                                        <span style="color:#6B6E76;">→</span>
                                        {{-- Laissé vide : « et au-delà », comme le « ≥ 61 M » du document. --}}
                                        <input type="number" class="champ" style="width:112px;" placeholder="et au-delà"
                                            wire:model.live.debounce.500ms="grilles.{{ $cible }}.{{ $rang }}.plafond">
                                    </div>
                                    <span style="font-size:11.5px; color:#6B6E76;">
                                        {{ \Modules\Noyau\Exploitation\Services\CommissionCommerciale::libelleTranche(
                                            (int) $ligne['plancher'],
                                            $ligne['plafond'] === null || $ligne['plafond'] === '' ? null : (int) $ligne['plafond'],
                                        ) }}
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <input type="number" step="0.1" class="champ" style="width:82px;"
                                            wire:model.live.debounce.500ms="grilles.{{ $cible }}.{{ $rang }}.taux">
                                        <span style="font-weight:700;">%</span>
                                    </div>
                                </td>
                                <td style="font-weight:700; font-variant-numeric:tabular-nums;">
                                    {{-- Recalculée, jamais saisie : la recopier à la main
                                         permettrait qu'elle contredise le taux d'à côté. --}}
                                    {{ \Modules\Noyau\Exploitation\Services\CommissionCommerciale::commissionEstimee(
                                        (int) $ligne['plancher'],
                                        $ligne['plafond'] === null || $ligne['plafond'] === '' ? null : (int) $ligne['plafond'],
                                        (float) $ligne['taux'],
                                    ) }}
                                </td>
                                <td class="colonne-collee" style="white-space:nowrap;">
                                    <button type="button" class="bouton bouton-secondaire" style="padding:3px 9px; font-size:11.5px;"
                                        wire:click="retirerLaTranche('{{ $cible }}', {{ $rang }})">Retirer</button>
                                </td>
                            </tr>
                        @empty
                            <x-table-vide :colspan="4" texte="Aucune tranche. Utilisez « + Ajouter » pour en poser une." />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p style="margin:10px 0 0; font-size:12px; color:#C8102E; font-weight:700;">*{{ $libelleCible }}</p>

            {{-- Les trois gestes de la section, sur une seule ligne. --}}
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
                <button type="button" class="bouton bouton-sombre" wire:click="enregistrerLaGrille('{{ $cible }}')">
                    Enregistrer
                </button>
                <button type="button" class="bouton bouton-secondaire" wire:click="ouvrirLAjout('{{ $cible }}')">
                    {{ $ajoutOuvert === $cible ? 'Fermer' : '+ Ajouter' }}
                </button>
                <button type="button" class="bouton bouton-secondaire" wire:click="ouvrirLesNotes('{{ $cible }}')">
                    {{ $notesOuvertes === $cible ? 'Masquer les notes' : 'Notes' }}
                </button>
            </div>

            @if ($ajoutOuvert === $cible)
                {{-- Les champs apparaissent sous le tableau ; on valide, ou l'on annule. --}}
                <div style="margin-top:14px; background:#F7F5EF; border-radius:8px; padding:12px 14px;">
                    <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <x-champ label="Plancher (FCFA, atteint)" model="nouveauPlancher" type="number" width="190" />
                        <x-champ label="Plafond (FCFA, exclu)" model="nouveauPlafond" type="number" width="190"
                            aide="Vide = et au-delà" />
                        <x-champ label="Taux (%)" model="nouveauTaux" type="number" width="120" />
                        <button type="button" class="bouton bouton-sombre" wire:click="validerLAjout">Valider</button>
                        <button type="button" class="bouton bouton-secondaire" wire:click="annulerLAjout">Annuler</button>
                    </div>
                </div>
            @endif

            @if ($notesOuvertes === $cible)
                <div style="margin-top:14px; background:#FDF3E3; border:1px solid #F0D9A8; border-radius:8px; padding:12px 16px;">
                    <b style="font-size:13px; color:#B45309;">Notes — {{ $libelleCible }}</b>
                    <ul style="margin:8px 0 0; padding-left:18px; font-size:13px; color:#7C4A08; line-height:1.55;">
                        @foreach ($this->notes[$cible] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endforeach

    {{-- La dernière phrase du document ne vise ni l'une ni l'autre grille : c'est l'intention
         d'ensemble, et elle reste donc hors des deux sections. --}}
    <p style="font-size:12.5px; color:#6B6E76; font-style:italic; margin:0;">
        Le système favorise ainsi une montée en performance tout en maintenant une logique de
        rémunération claire et motivante.
    </p>
</div>

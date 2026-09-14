<?php

use Modules\Noyau\Entreprises\Modeles\Exercice;
use Modules\Noyau\Entreprises\Services\ExerciceDeTravail;

use function Livewire\Volt\{computed, state};

/**
 * Le badge d'exercice, devenu un sélecteur d'année.
 *
 * Il affichait l'exercice courant et menait aux paramètres. Il fait désormais le geste
 * qu'on attendait de lui : **changer l'année qu'on regarde**, partout à la fois.
 *
 * Trois choses qu'il ne fait pas, et c'est délibéré :
 *
 * - **il ne clôture rien.** Passer sur 2025 ne ferme pas 2026 ; les deux restent ouverts.
 *   Le statut affiché reste celui de l'exercice, il n'est pas modifié par la consultation ;
 * - **il n'écrit rien en base.** Le choix vit dans la session de celui qui regarde ;
 * - **il ne change pas ce qu'on a le droit de saisir.** La saisie reste gouvernée par
 *   l'état réel de l'exercice, ville par ville.
 *
 * Quand l'année regardée n'est pas l'année courante, le badge change de couleur et le dit.
 * Un écran qui montre 2025 en ayant l'air de montrer 2026 est pire qu'un écran vide.
 */
state(['annee' => null]);

$exercices = computed(fn () => ExerciceDeTravail::disponibles((int) auth()->user()->entreprise_id));

$courant = computed(fn () => Exercice::actuel((int) auth()->user()->entreprise_id));

$retourEnArriere = computed(fn () => ExerciceDeTravail::estUnRetourEnArriere());

/** L'exercice affiché — celui qu'on regarde, pas forcément celui de l'année civile. */
$regarde = computed(function () {
    $annee = ExerciceDeTravail::annee();

    return $this->exercices->firstWhere('annee', $annee) ?? $this->courant;
});

$basculer = function (?string $annee) {
    ExerciceDeTravail::choisir($annee === '' || $annee === null ? null : (int) $annee);

    // Rechargement complet : les écrans lisent l'année au moment où ils calculent, et
    // rafraîchir le seul badge laisserait le reste de la page sur l'année précédente —
    // c'est-à-dire un écran qui se contredit lui-même.
    $this->redirect(request()->header('Referer') ?? route('redirection'), navigate: false);
};

?>

<div style="display:flex; align-items:center; gap:7px;">
    @php
        $regarde = $this->regarde;
        $enArriere = $this->retourEnArriere;
        $couleur = $enArriere ? '#B9791C' : ($regarde?->statut === 'Clos' ? '#C8102E' : '#0E9F6E');
    @endphp

    <label for="exercice-regarde" class="sr-only" style="position:absolute; left:-9999px;">Exercice consulté</label>

    <div style="display:flex; align-items:center; gap:6px; border:1px solid {{ $couleur }};
                background:{{ $couleur }}22; border-radius:99px; padding:3px 6px 3px 11px;">
        <span style="width:7px; height:7px; border-radius:99px; background:{{ $couleur }}; flex:0 0 auto;"></span>

        <form method="POST" action="{{ route('loupe.exercice') }}" style="display:flex; align-items:center; gap:5px; margin:0;">
            @csrf
            <select id="exercice-regarde" name="annee"
                    onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"
                    title="Année consultée — la saisie n'est pas affectée"
                    style="background:transparent; border:0; color:#fff; font-size:12.5px; font-weight:700;
                           cursor:pointer; padding:2px 4px; font-family:inherit;">
                @forelse ($this->exercices as $exercice)
                    <option value="{{ $exercice->annee }}" @selected($regarde?->annee === $exercice->annee)
                            style="color:#191B20;">
                        Exercice {{ $exercice->annee }}@if ($exercice->annee === $this->courant?->annee) — en cours @endif
                    </option>
                @empty
                    <option value="" style="color:#191B20;">Aucun exercice</option>
                @endforelse
            </select>
            <noscript>
                <button type="submit" style="background:#fff; border:0; border-radius:5px; padding:2px 7px;
                                             font-size:11px; font-weight:700; cursor:pointer;">Voir</button>
            </noscript>
        </form>
    </div>

    @if ($enArriere)
        {{-- Le retour à l'année courante doit tenir en un clic : on ne veut pas qu'un
             écran reste sur une année passée parce que personne n'a pensé à revenir. --}}
        <form method="POST" action="{{ route('loupe.exercice') }}" style="margin:0;">
            @csrf
            <input type="hidden" name="annee" value="{{ $this->courant?->annee }}">
            <button type="submit" title="Revenir à l'exercice en cours"
                    style="background:transparent; border:1px solid #B9791C; color:#F4C878; border-radius:99px;
                           padding:3px 9px; font-size:11.5px; font-weight:700; cursor:pointer; font-family:inherit;">
                ↩ {{ $this->courant?->annee }}
            </button>
        </form>
    @endif
</div>

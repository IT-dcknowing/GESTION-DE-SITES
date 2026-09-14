<?php

use Modules\Noyau\Entreprises\Services\VilleDeTravail;

use function Livewire\Volt\{computed};

/**
 * Le choix de la ville regardée, pour les modules qui portent sur toute l'entreprise.
 *
 * Il ne s'affiche que là où il a un sens : quand l'entreprise compte plusieurs villes et
 * que celui qui regarde les voit toutes. Ailleurs, il n'y aurait qu'une réponse possible,
 * et proposer un choix unique donne à croire qu'il en existait d'autres.
 */
$villes = computed(fn () => VilleDeTravail::villes());

$choisie = computed(fn () => VilleDeTravail::villeId());

$basculer = function (?string $ville) {
    VilleDeTravail::choisir($ville === '' || $ville === null ? null : (int) $ville);

    // Rechargement complet, pour la même raison que le sélecteur d'exercice : les écrans
    // lisent la ville au moment où ils calculent leurs totaux.
    $this->redirect(request()->header('Referer') ?? route('redirection'), navigate: false);
};

?>

<div>
    @if ($this->villes->count() > 1)
        <form method="POST" action="{{ route('loupe.ville') }}"
              style="display:flex; align-items:center; gap:6px;">
            @csrf
            <select name="ville" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"
                    title="Ville regardée — cela ne modifie aucun droit"
                    style="padding:7px 11px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:7px;
                           font-size:13.5px; font-weight:600; background:var(--th-champ,#FFFBEA);
                           color:#2A2E35; cursor:pointer; font-family:inherit;">
                <option value="" @selected($this->choisie === null)>Toutes les villes (consolidé)</option>
                @foreach ($this->villes as $id => $nom)
                    <option value="{{ $id }}" @selected($this->choisie === (int) $id)>{{ $nom }}</option>
                @endforeach
            </select>

            {{-- Visible seulement quand aucun script ne se charge de soumettre : sinon il
                 double le geste. `noscript` est le seul mécanisme qui sait le dire sans
                 dépendre lui-même d'un script. --}}
            <noscript>
                <button type="submit" class="rec-btn n" style="padding:6px 12px; font-size:12.5px;">Voir</button>
            </noscript>
        </form>
    @endif
</div>

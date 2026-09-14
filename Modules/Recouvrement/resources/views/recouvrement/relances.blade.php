<?php

use Modules\Noyau\Exploitation\Modeles\RelanceRecouvrement;
use Modules\Noyau\Exploitation\Services\Recouvrement;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Journal des relances — la preuve que la créance a été poursuivie
|--------------------------------------------------------------------------
| Ce n'est pas un historique de confort. Une injonction de payer au titre de
| l'AUPSRVE se demande sur pièces : sans trace des mises en demeure, la procédure
| n'a pas de point de départ, et la créance vieillit sans recours.
|
| Rien ne se supprime ici, et le responsable est inscrit en clair à côté de son
| compte : un accès fermé plus tard ne doit pas rendre anonyme ce qui a été fait
| sous son nom.
*/

state([
    'recherche' => '',
    'niveau' => '',
    'statut' => '',
    'page' => 1,
]);

$relances = computed(function () {
    $recherche = trim($this->recherche);

    return RelanceRecouvrement::query()
        ->when($recherche !== '', fn ($q) => $q->where(fn ($r) => $r
            ->where('tiers', 'like', "%$recherche%")
            ->orWhere('interlocuteur', 'like', "%$recherche%")
            ->orWhere('responsable', 'like', "%$recherche%")))
        ->when($this->niveau !== '', fn ($q) => $q->where('niveau', (int) $this->niveau))
        ->when($this->statut !== '', fn ($q) => $q->where('statut', $this->statut))
        ->orderByDesc('date')->orderByDesc('id')
        ->get();
});

$promesses = computed(fn () => $this->relances->sum('montant_promis'));

$updatedRecherche = fn () => $this->page = 1;
$updatedNiveau = fn () => $this->page = 1;
$updatedStatut = fn () => $this->page = 1;

?>

<x-recouvrement::coquille page="relances">
    <x-slot:actions>
        <div style="background:#fff; border:1px solid #E3E0D8; border-radius:10px; padding:8px 12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="search" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}" placeholder="Tiers, interlocuteur, responsable…"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px; min-width:210px;">
            <select wire:model.live="niveau"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 8px; font-size:13px;">
                <option value="" @selected($niveau === '')>Tous niveaux</option>
                @foreach (RelanceRecouvrement::NIVEAUX as $n => $libelle)
                    <option value="{{ $n }}" @selected((string) $niveau === (string) $n)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select wire:model.live="statut"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 8px; font-size:13px;">
                <option value="" @selected($statut === '')>Tous statuts</option>
                @foreach (RelanceRecouvrement::STATUTS as $s)
                    <option value="{{ $s }}" @selected((string) $statut === (string) $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
    
        {{-- Le fichier emporté contient exactement ce que l'écran montre :
             mêmes filtres, même arrêté, même ligne de totaux. --}}
        <x-telecharger route="recouvrement.telecharger"
            :parametres="['document' => 'relances']" />
    </x-slot:actions>

    <div class="rec-carte">
        <h2>
            Journal des relances
            <span class="chip">
                {{ $this->relances->count() }} action(s) ·
                {{ Recouvrement::fr($this->promesses) }} promis
            </span>
        </h2>

        <div class="rec-tbl-wrap" style="max-height:560px;">
            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Tiers</th>
                        <th>Factures</th>
                        <th>Niveau</th>
                        <th>Canal</th>
                        <th>Interlocuteur</th>
                        <th>Résultat / engagement</th>
                        <th class="num">Promis</th>
                        <th>Responsable</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->relances->forPage($page, 20) as $relance)
                        <tr wire:key="rel-{{ $relance->id }}">
                            <td>{{ $relance->date->format('d/m/Y') }}</td>
                            <td><b>{{ $relance->tiers }}</b></td>
                            <td>{{ $relance->factures_visees }}</td>
                            <td><span class="pill pN{{ $relance->niveau }}">N{{ $relance->niveau }}</span></td>
                            <td>{{ $relance->canal }}</td>
                            <td>{{ $relance->interlocuteur }}</td>
                            <td>{{ $relance->resultat }}</td>
                            <td class="num">
                                {{ $relance->montant_promis ? number_format($relance->montant_promis, 0, ',', ' ') : '·' }}
                            </td>
                            <td>{{ $relance->responsable }}</td>
                            <td>{{ $relance->statut }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="text-align:center; color:#5A6472; padding:26px;">
                                Aucune relance ne correspond — le formulaire de la vue Saisie en enregistre une.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->relances->count()" prop="page" :par-page="20" />

        <div class="rec-hint">
            Protocole : N1 e-mail à J+7 → N2 téléphone à J+15 → N3 lettre de relance à J+30 →
            N4 mise en demeure à J+60 (direction) → N5 contentieux à J+90 (injonction de payer, AUPSRVE).
        </div>
    </div>
</x-recouvrement::coquille>

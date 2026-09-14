<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use function Livewire\Volt\{computed, state};

/*
|--------------------------------------------------------------------------
| Piste d'audit — le registre du module, réservé au gérant
|--------------------------------------------------------------------------
| L'agent et le superviseur n'y ont pas accès, et ce n'est pas une marque de
| défiance : c'est le registre où leurs propres gestes sont consignés. On ne confie
| pas à quelqu'un la lecture — donc, tôt ou tard, la contestation — du journal qui
| le concerne.
|
| Le journal est en lecture seule et rien ne l'efface depuis l'application. C'est la
| réponse directe au défaut relevé par l'audit : des dossiers dévalidés sans qu'aucune
| trace ne dise par qui ni quand.
*/

state([
    'recherche' => '',
    'page' => 1,
]);

/**
 * Les actions du module, et elles seules.
 *
 * Le filtre porte sur le préfixe posé à l'écriture (« Recouvrement — … ») et sur
 * l'entreprise de l'auteur : le journal d'activité est commun à toute la plateforme, et
 * rien ne l'y cloisonne de lui-même. Sans la jointure, un gérant lirait les gestes
 * d'une autre entreprise.
 */
$actions = computed(function () {
    $recherche = trim($this->recherche);
    $entrepriseId = auth()->user()->entreprise_id;

    return Activity::query()
        ->where('description', 'like', 'Recouvrement — %')
        ->whereIn('causer_id', User::where('entreprise_id', $entrepriseId)->select('id'))
        ->where('causer_type', (new User)->getMorphClass())
        ->when($recherche !== '', fn ($q) => $q->where('description', 'like', "%$recherche%"))
        ->with('causer:id,name')
        ->latest('id')
        ->limit(1000)
        ->get();
});

$updatedRecherche = fn () => $this->page = 1;

?>

<x-recouvrement::coquille page="audit">
    <x-slot:actions>
        <div style="background:#fff; border:1px solid #E3E0D8; border-radius:10px; padding:8px 12px;">
            <input type="search" wire:model.live.debounce.300ms="recherche" value="{{ $recherche }}" placeholder="Filtrer les actions…"
                style="border:1px solid #E3E0D8; border-radius:6px; padding:6px 9px; font-size:13px; min-width:220px;">
        </div>
    </x-slot:actions>

    <div class="rec-carte">
        <h2>
            Piste d'audit du recouvrement
            <span class="chip">Gérant uniquement · {{ $this->actions->count() }} action(s)</span>
        </h2>

        <div class="rec-tbl-wrap" style="max-height:560px;">
            <table class="rec-tbl">
                <thead>
                    <tr>
                        <th>Horodatage</th>
                        <th>Auteur</th>
                        <th>Action</th>
                        <th>Détail</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->actions->forPage($page, 25) as $action)
                        <tr wire:key="audit-{{ $action->id }}">
                            <td>{{ $action->created_at->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $action->causer?->name ?? 'Système' }}</td>
                            <td><b>{{ str_replace('Recouvrement — ', '', $action->description) }}</b></td>
                            <td>
                                @php $details = collect($action->properties ?? [])->filter(fn ($v) => $v !== null && $v !== ''); @endphp
                                @if ($details->isEmpty())
                                    <span style="color:#5A6472;">—</span>
                                @else
                                    {{ $details->map(fn ($valeur, $clef) => $clef.' : '
                                        .(is_scalar($valeur) ? $valeur : json_encode($valeur, JSON_UNESCAPED_UNICODE)))->implode(' · ') }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align:center; color:#5A6472; padding:26px;">
                                Aucune action enregistrée pour le moment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :page="$page" :total="$this->actions->count()" prop="page" :par-page="25" />

        <div class="rec-hint">
            Chaque création de facture ou de tiers, chaque encaissement, chaque relance et chaque
            changement d'objectif est horodaté et attribué. Le registre est en lecture seule : rien,
            dans l'application, ne permet d'en retirer une ligne.
        </div>
    </div>
</x-recouvrement::coquille>

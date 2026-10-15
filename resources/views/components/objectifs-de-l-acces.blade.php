{{-- La case « concerné par les objectifs de l'entreprise » d'un formulaire d'accès — 15/10.

     « À la création d'un accès, on coche s'il est concerné par les objectifs ; si oui, on coche
     ceux dont il est concerné (un ou tous). » Les trois propriétés Livewire du formulaire :
     `concerneObjectifs` (bool), `objectifsTous` (bool), `objectifsChoisis` (liste d'ids). La
     vérification se fait au serveur (`AccesAuxObjectifs::enregistrer`). --}}
@props(['options' => [], 'concerne' => false, 'tous' => true])

<div style="margin:14px 0 6px; padding:10px 12px; border:1px solid var(--th-ligne,#E2E0D8); border-radius:8px; background:#FAFAF8;">
    <label style="display:flex; gap:8px; align-items:center; font-size:14px; font-weight:600; color:#4B4E55; cursor:pointer;">
        <input type="checkbox" wire:model.live="concerneObjectifs">
        Concerné par les objectifs de l'entreprise
    </label>

    @if ($concerne)
        @if ($options === [])
            <p style="margin:8px 0 0; font-size:13px; color:#B45309;">
                Aucun objectif n'est encore établi : la case vaudra pour <b>tous les objectifs</b>, présents et à venir.
            </p>
        @else
            <div style="margin-top:8px; display:flex; flex-direction:column; gap:6px; font-size:13.5px;">
                <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                    <input type="radio" wire:model.live="objectifsTous" value="1" @checked($tous)> Tous les objectifs (y compris ceux à venir)
                </label>
                <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                    <input type="radio" wire:model.live="objectifsTous" value="0" @checked(! $tous)> Seulement ceux-ci :
                </label>
                @if (! $tous)
                    <div style="padding-left:24px; display:flex; flex-direction:column; gap:4px;">
                        @foreach ($options as $id => $libelle)
                            <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                                <input type="checkbox" wire:model="objectifsChoisis" value="{{ $id }}"> {{ $libelle }}
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
    @error('objectifsChoisis') <div style="color:#C8102E; font-size:13.5px; margin-top:6px;">{{ $message }}</div> @enderror
</div>

@props([
    'label',
    'model',
    'type' => 'text',
    'options' => null,
    'width' => null,
    'placeholder' => null,
    'live' => false,
    'requis' => false,
    'aide' => null,
    'liste' => null,
    'disabled' => false,
    'vide' => null,
])

@php
    $wire = $live ? 'wire:model.live' : 'wire:model';
    $largeur = $width ? 'width:'.$width.'px;' : 'flex:1; min-width:150px;';
@endphp

@if ($type === 'checkbox')
    <label style="font-size:13.5px; display:flex; gap:6px; align-items:center; padding-bottom:9px; white-space:nowrap;">
        <input type="checkbox" {{ $wire }}="{{ $model }}">
        {{ $label }}
    </label>
@else
    <div style="display:flex; flex-direction:column; {{ $largeur }}">
        <label class="champ-libelle">
            {{ $label }}@if ($requis)<span style="color:var(--th-accent);"> *</span>@endif
        </label>
        @if ($type === 'select')
            <select {{ $wire }}="{{ $model }}" class="champ" @if ($disabled) disabled @endif>
                {{-- Sans cette ligne vide, un champ encore non renseigné affiche la
                     première option alors que le serveur ne détient rien : le navigateur
                     ne prévient pas d'un choix que personne n'a fait. On voyait donc
                     « ce champ est obligatoire » sur une valeur qui semblait choisie. --}}
                @if ($vide !== null)
                    <option value="">{{ $vide }}</option>
                @endif
                @foreach ($options as $valeur => $libelle)
                    <option value="{{ $valeur }}">{{ $libelle }}</option>
                @endforeach
            </select>
        @elseif ($type === 'textarea')
            <textarea {{ $wire }}="{{ $model }}" rows="2" placeholder="{{ $placeholder }}" class="champ" style="resize:vertical;" @if ($disabled) disabled @endif></textarea>
        @elseif ($type === 'password')
            {{-- Bouton œil : bascule entre texte masqué et texte lisible. --}}
            <div class="champ-mot-de-passe">
                <input type="password" {{ $wire }}="{{ $model }}" placeholder="{{ $placeholder }}" class="champ">
                <x-oeil-mot-de-passe />
            </div>
        @else
            <input type="{{ $type }}" {{ $wire }}="{{ $model }}" placeholder="{{ $placeholder }}" class="champ"
                @if ($liste) list="{{ $liste }}" @endif @if ($disabled) disabled @endif>
        @endif
        @if ($aide)
            <span style="font-size:11.5px; color:#9A9DA5; margin-top:3px;">{{ $aide }}</span>
        @endif
        @error($model) <span class="champ-erreur">{{ $message }}</span> @enderror
    </div>
@endif

@props([
    'label',
    'valeur' => null,
    'width' => null,
    'aide' => null,
])

{{-- Une case qui se lit et ne se saisit pas.

     Elle porte exactement l'habit de `x-champ` — même libellé, même boîte, même largeur — parce
     qu'un formulaire où les cases figées auraient une autre allure se lirait comme deux
     formulaires. Ce qu'elle dit, c'est : cette valeur vient d'ailleurs et ne se corrige pas ici.

     Elle ne poste rien et ne porte aucun `wire:model` : ce qui n'est pas modifiable n'a pas à
     revenir du navigateur, et un champ désactivé n'a jamais empêché personne de le renvoyer
     quand même. --}}

<div style="display:flex; flex-direction:column; {{ $width ? 'width:'.$width.'px;' : 'flex:1; min-width:150px;' }}">
    <label class="champ-libelle">{{ $label }}</label>
    <input type="text" class="champ" value="{{ $valeur ?? '—' }}" disabled
        style="background:#F2F0E9; color:#4B4E55; font-weight:600;">
    @if ($aide)
        <span style="font-size:11px; color:#6B6E76; margin-top:2px;">{{ $aide }}</span>
    @endif
</div>

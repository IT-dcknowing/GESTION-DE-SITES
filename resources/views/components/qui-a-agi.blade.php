@props(['personnes' => []])

{{-- Qui s'est chargé de cette ligne — dans un tableau, donc sans les dates.

     **La consigne du 25/09 est précise sur ce point** : le nom dans le tableau, les dates
     dans le détail seulement. Un tableau répond à « qui suit ce dossier ? » ; une colonne
     de dates y serait illisible et n'aiderait personne à décider.

     On montre le **code de saisie** sous le nom — `A-C-KY-0007` — parce que c'est lui qui
     désigne sans ambiguïté : deux homonymes existent, et c'est justement quand on demande
     des comptes qu'il ne faut pas se tromper de personne.

     Au-delà de deux personnes, on compte le reste plutôt que d'allonger la colonne : le
     détail les nomme toutes. --}}

@php
    $personnes = collect($personnes);
    $montrees = $personnes->take(2);
    $reste = $personnes->count() - $montrees->count();
@endphp

@if ($personnes->isEmpty())
    {{-- Ni trace ni auteur : la ligne vient d'un import, et personne ne l'a touchée
         depuis. Le dire vaut mieux qu'un blanc, qu'on lirait comme un défaut d'affichage. --}}
    <span style="color:#9A9DA5; font-size:12px;" title="Aucun geste enregistré sur cette ligne.">
        personne encore
    </span>
@else
    @foreach ($montrees as $personne)
        <div style="line-height:1.35; {{ ! $loop->last ? 'margin-bottom:4px;' : '' }}">
            <span style="font-size:12.5px;">{{ $personne['nom'] }}</span>
            @if ($personne['code'])
                <div style="font-size:10.5px; color:#6B6E76; font-family:ui-monospace,Consolas,monospace;"
                     title="{{ $personne['fonction'] ?? '' }}">{{ $personne['code'] }}</div>
            @endif
        </div>
    @endforeach

    @if ($reste > 0)
        <div style="font-size:10.5px; color:#6B6E76;">+ {{ $reste }} autre{{ $reste > 1 ? 's' : '' }}</div>
    @endif
@endif

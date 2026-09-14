{{-- Afficher ou masquer un mot de passe.

     **Ce qui a changé et pourquoi.** Le bouton portait deux émojis, 👁 et 🙈 — l'œil et le
     singe qui se cache les yeux. Deux défauts : un émoji n'est pas dessiné par nous, il
     change de forme d'un système à l'autre et arrive parfois en couleur au milieu d'une
     interface qui n'en a pas ; et le singe ne dit pas « masqué », il fait une plaisanterie
     là où l'on attend un état. Deux traits au crayon valent mieux : l'œil, et l'œil barré.

     Le dessin est posé ici en SVG, à la ligne, donc sans police à charger ni requête à
     faire. Il suit la couleur du texte, il grandit avec lui, et il est identique partout.

     Le bouton agit sur le champ qui le précède. Sans script il ne fait rien — et c'est
     acceptable : le mot de passe reste saisissable, seule l'aide à la relecture manque. --}}

@php
    $oeil = 'width:17px; height:17px; display:block; stroke:currentColor; stroke-width:1.7;'
        .' fill:none; stroke-linecap:round; stroke-linejoin:round;';
@endphp

<button type="button" tabindex="-1"
        aria-label="Afficher ou masquer le mot de passe"
        data-oeil
        onclick="(function(b){
            var i = b.previousElementSibling;
            if (! i || i.tagName !== 'INPUT') { return; }
            var masque = i.type === 'password';
            i.type = masque ? 'text' : 'password';
            b.querySelector('[data-oeil-ouvert]').hidden = masque;
            b.querySelector('[data-oeil-barre]').hidden = ! masque;
            b.setAttribute('aria-pressed', masque ? 'true' : 'false');
        })(this)">

    {{-- Champ masqué : l'œil ouvert propose de regarder. --}}
    <svg data-oeil-ouvert viewBox="0 0 24 24" style="{{ $oeil }}" aria-hidden="true">
        <path d="M1.8 12S5.4 5.4 12 5.4 22.2 12 22.2 12 18.6 18.6 12 18.6 1.8 12 1.8 12Z"/>
        <circle cx="12" cy="12" r="3.1"/>
    </svg>

    {{-- Champ lisible : l'œil barré propose de le refermer. --}}
    <svg data-oeil-barre hidden viewBox="0 0 24 24" style="{{ $oeil }}" aria-hidden="true">
        <path d="M9.9 5.6A9.6 9.6 0 0 1 12 5.4c6.6 0 10.2 6.6 10.2 6.6a18 18 0 0 1-2.9 3.8"/>
        <path d="M6.4 6.5A17.6 17.6 0 0 0 1.8 12S5.4 18.6 12 18.6a9.9 9.9 0 0 0 4.1-.9"/>
        <path d="M9.8 9.9a3.1 3.1 0 0 0 4.3 4.3"/>
        <path d="M3.2 3.2 20.8 20.8"/>
    </svg>
</button>

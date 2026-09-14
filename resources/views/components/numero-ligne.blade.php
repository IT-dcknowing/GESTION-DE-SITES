@props(['ligne' => null, 'numero' => null])

@php
    use Modules\Noyau\Commun\Services\SignatureDeSaisie;

    // L'auteur se lit sur la ligne quand elle en porte un. Une ligne importée n'en a pas :
    // elle n'a pas été saisie ici, et lui attribuer quelqu'un serait une invention.
    $auteurId = $ligne?->cree_par;
    $nomAuteur = SignatureDeSaisie::nom($auteurId);
    $liaison = SignatureDeSaisie::liaison($auteurId);

    // Le code de liaison de la ligne elle-même prime sur celui de l'auteur : sur une ligne
    // importée, c'est le seul qui existe, et il désigne qui a rédigé la fiche dans le
    // logiciel d'atelier — ce qui n'est pas forcément la même personne.
    $liaison = $ligne?->code_agent ?: $liaison;
@endphp

{{-- Trois marques dans la même cellule, de la plus précise à la plus lisible :

     - le numéro du document, qui situe la ligne dans toute l'entreprise ;
     - le code de saisie, qui dit qui l'a écrite et à quel rang de son propre travail ;
     - le nom en clair, parce qu'aucun code ne se retient — et, s'il existe, l'identifiant
       de liaison du logiciel d'atelier, entre crochets pour qu'on ne le confonde pas avec
       le code de saisie.

     Une seule colonne : les tableaux sont déjà larges, et ces trois marques se lisent
     toujours en regard du même document. --}}
<div style="line-height:1.35;">
    <span style="font-weight:700;">{{ $numero ?? $ligne?->numero ?? '—' }}</span>

    @if ($ligne?->code_auteur || $nomAuteur || $liaison)
        <div style="font-size:11px; color:var(--th-gris,#6B6E76); font-weight:600; letter-spacing:.2px;"
            title="{{ $nomAuteur ? 'Saisi par '.$nomAuteur : 'Auteur inconnu — ligne importée' }}{{ $liaison ? ' · identifiant de liaison '.$liaison : '' }}">
            @if ($ligne?->code_auteur)
                {{ $ligne->code_auteur }}
            @endif
            @if ($liaison)
                <span style="color:#B9791C;">[{{ $liaison }}]</span>
            @endif
        </div>
    @endif

    @if ($nomAuteur)
        <div style="font-size:11px; color:var(--th-gris,#6B6E76); font-weight:400;">{{ $nomAuteur }}</div>
    @endif
</div>

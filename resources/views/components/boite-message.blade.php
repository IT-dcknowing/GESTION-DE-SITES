@props([
    'titre',
    'ton' => 'info',
    'lien' => null,
    'libelleLien' => null,
])

{{-- Un message qu'on ne peut pas manquer.

     **Le défaut auquel cette boîte répond.** Un import a été relancé sur un fichier déjà
     passé. Le système a bien réagi — il a refusé, expliqué, montré le dépôt d'origine — et
     la personne n'a rien vu : le message s'était glissé dans le flux de la page, entre deux
     cadres, à l'endroit exact où l'œil ne s'arrête pas. Elle a conclu qu'il ne s'était rien
     passé.

     Un refus doit interrompre. Pas pour punir : pour être lu. On barre donc l'écran, et il
     faut un geste pour continuer — c'est ce geste qui prouve que le message a été vu.

     **Construction volontairement pauvre.** Un `<dialog open>` s'affiche par le navigateur
     lui-même, sans une ligne de JavaScript : le message reste donc visible même si aucun
     script ne tourne. Le bouton de fermeture, lui, tient en une instruction en ligne. Rien
     ici ne dépend de la couche interactive — ce serait absurde pour l'écran qui sert
     justement à dire que quelque chose n'a pas marché.

     Réservé à ce qui mérite d'arrêter quelqu'un : un refus, un doublon, une conséquence
     qu'il faut avoir lue. Le reste — « enregistré », « à jour » — se dit dans la page,
     sans barrer le passage. --}}

@php
    $couleurs = [
        'info' => ['bord' => '#2563EB', 'fond' => '#EEF3FD', 'texte' => '#1B3F86'],
        'succes' => ['bord' => '#1E7B34', 'fond' => '#F0F7F1', 'texte' => '#155724'],
        'alerte' => ['bord' => '#C8102E', 'fond' => '#FCF0F2', 'texte' => '#8C1023'],
    ];

    $c = $couleurs[$ton] ?? $couleurs['info'];
    $id = 'boite-'.substr(md5($titre.$ton), 0, 8);
@endphp

<dialog open id="{{ $id }}"
    style="border:0; border-top:5px solid {{ $c['bord'] }}; border-radius:12px; padding:0;
           max-width:540px; width:calc(100% - 32px); box-shadow:0 18px 50px rgba(0,0,0,.3);
           font-family:var(--font-sans), system-ui, sans-serif; color:var(--th-ink,#191B20);">

    <div style="padding:20px 22px 6px;">
        <h2 style="font-family:'Barlow Condensed',sans-serif; font-size:20px; font-weight:700;
                   text-transform:uppercase; letter-spacing:1px; margin:0 0 10px; color:{{ $c['texte'] }};">
            {{ $titre }}
        </h2>

        <div style="font-size:14px; line-height:1.65; color:#3C4048;">
            {{ $slot }}
        </div>
    </div>

    <div style="display:flex; gap:10px; align-items:center; justify-content:flex-end;
                padding:16px 22px 18px; background:{{ $c['fond'] }}; margin-top:16px; flex-wrap:wrap;">
        @if ($lien)
            <a href="{{ $lien }}"
               style="text-decoration:none; border:1.5px solid var(--th-ink,#191B20); color:var(--th-ink,#191B20);
                      border-radius:7px; padding:8px 15px; font-weight:600; font-size:14px;">
                {{ $libelleLien ?? 'Voir' }}
            </a>
        @endif

        <button type="button" autofocus
                onclick="this.closest('dialog').close(); this.closest('dialog').remove();"
                style="border:0; border-radius:7px; padding:9px 18px; font-family:inherit; font-size:14px;
                       font-weight:700; cursor:pointer; background:{{ $c['bord'] }}; color:#fff;">
            Fermer
        </button>
    </div>
</dialog>

@once
    <style>
        /* Le voile derrière la boîte : c'est lui qui dit « la page attend ». Sans lui, une
           boîte posée au milieu de l'écran se confond avec un cadre de plus. */
        dialog[open]::backdrop { background: rgba(25, 27, 32, .55); }
        dialog[open] { position: fixed; inset: 0; margin: auto; }
    </style>
@endonce

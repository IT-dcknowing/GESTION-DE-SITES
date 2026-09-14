@props([
    'titre',
    'sousTitre' => null,
])

{{-- Le titre d'un écran, dans la forme qu'ils partagent tous.

     **Pourquoi certains écrans n'en avaient pas.** Ils s'ouvraient directement sur leurs
     filtres, en comptant sur l'onglet du bandeau pour dire où l'on est. Cela tient tant
     qu'on y arrive par le menu ; cela ne tient plus dès qu'on revient par l'historique,
     qu'on imprime la page, ou qu'on la partage — et surtout, un lecteur d'écran n'annonce
     alors rien du tout. Treize écrans étaient dans ce cas.

     Le sous-titre dit ce que l'écran contient, pas ce qu'il est : « Chiffre d'affaires »
     puis « ce qui a été facturé » vaut mieux que « Chiffre d'affaires » puis « page du
     chiffre d'affaires ». --}}

<div {{ $attributes->merge(['style' => 'margin-bottom:18px;']) }}>
    <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:26px; font-weight:700;
               text-transform:uppercase; letter-spacing:1px; margin:0; color:var(--th-steel,#2A2E35);">
        {{ $titre }}
    </h1>

    @if ($sousTitre)
        <div style="color:var(--th-gris,#6B6E76); font-size:13.5px; margin-top:3px;">{{ $sousTitre }}</div>
    @endif

    {{ $slot }}
</div>

@props(['titre', 'icone' => null, 'couleur' => null])

@php
    // Pictogrammes légers, dessinés en SVG : aucun fichier ni police à charger.
    $traces = [
        'commercial' => '<circle cx="9" cy="7" r="3.2"/><path d="M2.5 19c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5"/><path d="M17 8.5h4M19 6.5v4"/>',
        'facture' => '<path d="M5 3h11l3 3v15l-2.5-1.5L14 21l-2.5-1.5L9 21l-2.5-1.5L4 21V4z"/><path d="M8 8h7M8 12h7M8 16h4"/>',
        'encaissement' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 10v4M18 10v4"/>',
        'charge' => '<path d="M3 20h18"/><path d="M6 20V9M11 20V5M16 20v-7M21 20v-4"/>',
        'atelier' => '<path d="M14.7 6.3a4 4 0 1 0-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.1-2.1z"/>',
        'liste' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1.3"/><circle cx="3.5" cy="12" r="1.3"/><circle cx="3.5" cy="18" r="1.3"/>',
    ];
    $trace = $traces[$icone] ?? null;
    $teinte = $couleur ?? 'var(--th-steel,#2A2E35)';
@endphp

<div class="carte" style="margin-bottom:20px;">
    <h2 class="titre-section" style="display:flex; align-items:center; gap:8px; color:{{ $teinte }}; border-bottom-color:{{ $teinte }};">
        @if ($trace)
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="{{ $teinte }}"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                 style="flex:0 0 auto;">{!! $trace !!}</svg>
        @endif
        {{ texte_d_attribut($titre) }}
    </h2>
    {{ $slot }}
</div>

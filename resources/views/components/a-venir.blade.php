@props(['titre', 'description' => null])

<div style="background:#fff; border:1px solid var(--th-ligne,#E2E0D8); border-radius:10px; padding:26px; margin-bottom:16px;">
    <h1 style="font-size:22px; font-weight:800; margin:0 0 6px;">{{ texte_d_attribut($titre) }}</h1>
    @if ($description)
        <p style="color:#6B6E76; font-size:15px; margin:0 0 16px; max-width:70ch;">{{ texte_d_attribut($description) }}</p>
    @endif
    {{ $slot }}
</div>

@props(['entreprise' => null, 'titre', 'sousTitre' => null])

<!DOCTYPE html>
<html lang="fr" style="{{ collect($entreprise?->theme() ?? [])->map(fn ($v, $k) => "$k:$v")->implode(';') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre }} — {{ $entreprise?->nom ?? config('app.name') }}</title>
    @if ($entreprise?->logoUrl())
        <link rel="icon" type="image/png" href="{{ $entreprise->logoUrl() }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" style="margin:0; font-family:var(--font-sans); background:var(--th-paper,#F4F3EF); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 20px;">
    <div class="carte" style="width:100%; max-width:430px; padding:30px; box-shadow:0 18px 44px rgba(25,27,32,.10);">
        <div style="text-align:center; margin-bottom:22px;">
            @if ($entreprise?->logoUrl())
                <img src="{{ $entreprise->logoUrl() }}" alt="{{ $entreprise->nom }}" style="height:52px; margin:0 auto 14px; display:block;">
            @endif
            <h1 style="font-family:'Barlow Condensed',sans-serif; font-size:28px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin:0; color:var(--th-ink,#191B20);">
                {{ $titre }}
            </h1>
            @if ($sousTitre)
                <p style="color:var(--th-gris,#6B6E76); font-size:14px; margin:6px 0 0;">{{ $sousTitre }}</p>
            @endif
        </div>
        {{ $slot }}
    </div>
    <x-nettoyer-courriels />
</body>
</html>

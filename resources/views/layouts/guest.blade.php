<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="antialiased" style="margin:0; font-family:var(--font-sans);">
    {{ $slot }}
    @livewireScripts
    <x-nettoyer-courriels />
</body>
</html>
